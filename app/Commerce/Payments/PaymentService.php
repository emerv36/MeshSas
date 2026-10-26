<?php
require_once __DIR__ . '/../../Learning/Access/AccessService.php';

/** Sin sesión, el correo ya pertenece a una cuenta: hay que iniciar sesión para inscribirse. */
final class CuentaExistenteException extends InvalidArgumentException {}

final class PaymentService
{
    public function __construct(private PDO $pdo) {}

    public function getProduct(string $type, int $id): array
    {
        if (!in_array($type, ['curso', 'ruta'], true) || $id <= 0) {
            throw new InvalidArgumentException('Producto inválido.');
        }
        $table = $type === 'ruta' ? 'rutas_aprendizaje' : 'cursos';
        $stmt = $this->pdo->prepare("SELECT * FROM {$table} WHERE id = ?");
        $stmt->execute([$id]);
        $product = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$product) {
            throw new RuntimeException('El producto no existe.');
        }
        return $product;
    }

    public function finalPrice(array $product): float
    {
        $price = (float)($product['precio'] ?? 0);
        $offer = isset($product['precio_oferta']) ? (float)$product['precio_oferta'] : 0;
        return $offer > 0 && $offer < $price ? $offer : $price;
    }

    public function resolveUser(array $data, ?int $sessionUserId): array
    {
        if ($sessionUserId) {
            $stmt = $this->pdo->prepare('SELECT * FROM usuarios WHERE id = ?');
            $stmt->execute([$sessionUserId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$user) {
                throw new RuntimeException('La sesión no corresponde a un usuario válido.');
            }
            return ['user' => $user, 'created' => false, 'temporary_password' => null];
        }

        $email = strtolower(trim((string)($data['email'] ?? '')));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('El correo electrónico no es válido.');
        }
        // Sin sesión no se inscribe a una cuenta existente: cualquiera podría hacerlo a nombre
        // de otra persona escribiendo su correo
        $stmt = $this->pdo->prepare('SELECT 1 FROM usuarios WHERE email = ?');
        $stmt->execute([$email]);
        if ($stmt->fetchColumn()) {
            throw new CuentaExistenteException('Ya existe una cuenta con este correo. Inicia sesión para inscribirte.');
        }

        $name = trim((string)($data['nombre'] ?? ''));
        if ($name === '') {
            throw new InvalidArgumentException('El nombre es obligatorio.');
        }
        $password = bin2hex(random_bytes(6));
        $stmt = $this->pdo->prepare(
            "INSERT INTO usuarios
                (nombre, email, password, telefono, tipo_documento, numero_documento, rol)
             VALUES (?, ?, ?, ?, ?, ?, 'estudiante')"
        );
        $stmt->execute([
            $name, $email, password_hash($password, PASSWORD_DEFAULT),
            trim((string)($data['telefono'] ?? '')),
            (string)($data['tipo_documento'] ?? 'CC'),
            trim((string)($data['numero_documento'] ?? '')),
        ]);
        $stmt = $this->pdo->prepare('SELECT * FROM usuarios WHERE id = ?');
        $stmt->execute([(int)$this->pdo->lastInsertId()]);
        return ['user' => $stmt->fetch(PDO::FETCH_ASSOC), 'created' => true, 'temporary_password' => $password];
    }

    /**
     * Motivo por el que el usuario no puede inscribirse otra vez al producto, o null: un pago
     * pendiente de revisión o un acceso vigente al propio producto. Vencido el acceso, puede
     * volver a inscribirse. Bloquea la fila del usuario para serializar envíos simultáneos.
     */
    public function duplicateReason(int $userId, string $type, int $productId): ?string
    {
        $this->pdo->prepare('SELECT id FROM usuarios WHERE id = ? FOR UPDATE')->execute([$userId]);

        $stmt = $this->pdo->prepare(
            "SELECT 1 FROM pagos WHERE usuario_id = ? AND producto_tipo = ? AND producto_id = ? AND estado = 'pendiente' LIMIT 1"
        );
        $stmt->execute([$userId, $type, $productId]);
        if ($stmt->fetchColumn()) {
            return 'Ya tienes un pago en revisión para este producto. Te avisaremos por correo cuando sea verificado.';
        }

        $stmt = $this->pdo->prepare(
            'SELECT MAX(acceso_fin) FROM accesos_productos
             WHERE usuario_id = ? AND producto_tipo = ? AND producto_id = ? AND revocado_at IS NULL
               AND acceso_inicio <= UTC_TIMESTAMP() AND acceso_fin > UTC_TIMESTAMP()'
        );
        $stmt->execute([$userId, $type, $productId]);
        $endsAt = $stmt->fetchColumn();
        if ($endsAt) {
            $local = (new DateTimeImmutable($endsAt, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('America/Bogota'));
            return 'Ya tienes acceso a este producto hasta el ' . $local->format('d/m/Y') . '.';
        }
        return null;
    }

    public function productCourseIds(string $type, int $productId): array
    {
        if ($type === 'curso') {
            $stmt = $this->pdo->prepare('SELECT id FROM cursos WHERE id = ?');
            $stmt->execute([$productId]);
            $id = $stmt->fetchColumn();
            if (!$id) {
                throw new RuntimeException('El curso asociado no existe.');
            }
            return [(int)$id];
        }
        if ($type !== 'ruta') {
            throw new RuntimeException('Tipo de producto no soportado.');
        }
        $stmt = $this->pdo->prepare(
            'SELECT c.id FROM ruta_cursos rc
             JOIN cursos c ON c.id = rc.curso_id
             WHERE rc.ruta_id = ? ORDER BY rc.orden, c.id'
        );
        $stmt->execute([$productId]);
        $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        if (!$ids) {
            throw new RuntimeException('La ruta no contiene cursos válidos.');
        }
        return $ids;
    }

    public function ensureEnrollments(int $userId, array $courseIds): void
    {
        $insert = $this->pdo->prepare(
            'INSERT INTO inscripciones (usuario_id, curso_id)
             SELECT ?, ? WHERE NOT EXISTS (
                 SELECT 1 FROM inscripciones WHERE usuario_id = ? AND curso_id = ?
             )'
        );
        $check = $this->pdo->prepare('SELECT 1 FROM inscripciones WHERE usuario_id = ? AND curso_id = ?');
        foreach (array_unique(array_map('intval', $courseIds)) as $courseId) {
            $insert->execute([$userId, $courseId, $userId, $courseId]);
            $check->execute([$userId, $courseId]);
            if (!$check->fetchColumn()) {
                throw new RuntimeException("No fue posible crear la inscripción al curso {$courseId}.");
            }
        }
    }

    public function verifyPayment(int $paymentId, int $approverId, bool $manageTransaction = true): array
    {
        if ($manageTransaction) {
            $this->pdo->beginTransaction();
        } elseif (!$this->pdo->inTransaction()) {
            throw new LogicException('La verificación de prueba requiere una transacción activa.');
        }
        try {
            $stmt = $this->pdo->prepare('SELECT * FROM pagos WHERE id = ? FOR UPDATE');
            $stmt->execute([$paymentId]);
            $payment = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$payment) {
                throw new RuntimeException('El pago no existe.');
            }
            if ((int)$payment['usuario_id'] === $approverId) {
                throw new RuntimeException('No puedes verificar tu propio pago.');
            }
            if ($payment['estado'] !== 'pendiente') {
                throw new RuntimeException('El pago ya fue procesado.');
            }
            $user = $this->pdo->prepare('SELECT id FROM usuarios WHERE id = ?');
            $user->execute([(int)$payment['usuario_id']]);
            if (!$user->fetchColumn()) {
                throw new RuntimeException('El pago no tiene un usuario válido.');
            }
            $type = (string)$payment['producto_tipo'];
            $productId = (int)$payment['producto_id'];
            $product = $this->getProduct($type, $productId);
            $courseIds = $this->productCourseIds($type, $productId);
            $this->ensureEnrollments((int)$payment['usuario_id'], $courseIds);
            $verifiedAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            $accessService = new AccessService($this->pdo);
            $access = $accessService->grantProductAccess(
                (int)$payment['usuario_id'],
                $type,
                $productId,
                $paymentId,
                (float)$payment['monto'] <= 0 ? 'gratis' : 'pago',
                $verifiedAt
            );

            $update = $this->pdo->prepare(
                "UPDATE pagos SET estado = 'verificado', verificado_at = ?
                 WHERE id = ? AND estado = 'pendiente'"
            );
            $update->execute([$verifiedAt->format('Y-m-d H:i:s'), $paymentId]);
            if ($update->rowCount() !== 1) {
                throw new RuntimeException('El pago cambió de estado durante la verificación.');
            }
            if ($manageTransaction) {
                $this->pdo->commit();
            }
            $payment['estado'] = 'verificado';
            $payment['producto_titulo'] = $product['titulo'];
            $payment['course_ids'] = $courseIds;
            $payment['access'] = $access;
            return $payment;
        } catch (Throwable $e) {
            if ($manageTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }
}
