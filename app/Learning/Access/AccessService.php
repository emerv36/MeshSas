<?php

final class AccessService
{
    public function __construct(private PDO $pdo) {}

    public function grantProductAccess(
        int $userId,
        string $productType,
        int $productId,
        ?int $paymentId,
        string $origin,
        ?DateTimeImmutable $startsAt = null
    ): array {
        if (!in_array($productType, ['curso', 'ruta'], true) || $productId <= 0 || $userId <= 0) {
            throw new InvalidArgumentException('Datos de acceso inválidos.');
        }
        if (!in_array($origin, ['pago', 'gratis', 'administrativo', 'migracion'], true)) {
            throw new InvalidArgumentException('Origen de acceso inválido.');
        }
        if ($paymentId) {
            $existing = $this->pdo->prepare('SELECT * FROM accesos_productos WHERE pago_id = ? LIMIT 1');
            $existing->execute([$paymentId]);
            $access = $existing->fetch(PDO::FETCH_ASSOC);
            if ($access) return $access;
        }

        $startsAt ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $startsAt = $startsAt->setTimezone(new DateTimeZone('UTC'));
        $days = defined('ACCESS_DURATION_DAYS') ? max(1, (int)ACCESS_DURATION_DAYS) : 30;
        $endsAt = $startsAt->add(new DateInterval("P{$days}D"));
        $stmt = $this->pdo->prepare(
            'INSERT INTO accesos_productos
                (usuario_id, pago_id, producto_tipo, producto_id, acceso_inicio, acceso_fin, origen)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $userId, $paymentId, $productType, $productId,
            $startsAt->format('Y-m-d H:i:s'), $endsAt->format('Y-m-d H:i:s'), $origin,
        ]);
        return [
            'id' => (int)$this->pdo->lastInsertId(), 'usuario_id' => $userId, 'pago_id' => $paymentId,
            'producto_tipo' => $productType, 'producto_id' => $productId,
            'acceso_inicio' => $startsAt->format('Y-m-d H:i:s'),
            'acceso_fin' => $endsAt->format('Y-m-d H:i:s'), 'origen' => $origin,
        ];
    }

    public function courseState(int $userId, int $courseId): array
    {
        if ($this->courseIsExempt($userId, $courseId)) {
            return ['status' => 'exento', 'active' => true, 'starts_at' => null, 'ends_at' => null];
        }
        $stmt = $this->pdo->prepare(
            "SELECT a.*, 'curso' AS fuente FROM accesos_productos a
             WHERE a.usuario_id = ? AND a.producto_tipo = 'curso' AND a.producto_id = ?
             UNION ALL
             SELECT a.*, 'ruta' AS fuente FROM accesos_productos a
             JOIN ruta_cursos rc ON rc.ruta_id = a.producto_id
             WHERE a.usuario_id = ? AND a.producto_tipo = 'ruta' AND rc.curso_id = ?
             ORDER BY acceso_fin DESC"
        );
        $stmt->execute([$userId, $courseId, $userId, $courseId]);
        return $this->resolveState($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function routeState(int $userId, int $routeId): array
    {
        if ($this->userIsExempt($userId)) {
            return ['status' => 'exento', 'active' => true, 'starts_at' => null, 'ends_at' => null];
        }
        $stmt = $this->pdo->prepare(
            "SELECT a.*, 'ruta' AS fuente FROM accesos_productos a
             WHERE a.usuario_id = ? AND a.producto_tipo = 'ruta' AND a.producto_id = ?
             ORDER BY a.acceso_fin DESC"
        );
        $stmt->execute([$userId, $routeId]);
        return $this->resolveState($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** Admin y docente entran a todo sin acceso registrado. */
    public function userIsExempt(int $userId): bool
    {
        return in_array($this->role($userId), ['admin', 'docente'], true);
    }

    /**
     * Exento en este curso: admin y docente en cualquiera; el personal administrativo solo
     * en los cursos internos (privados), sin vencimiento. Los públicos los cursa como un
     * estudiante más (y pago.php no le deja inscribirse en ellos).
     */
    public function courseIsExempt(int $userId, int $courseId): bool
    {
        $role = $this->role($userId);
        if (in_array($role, ['admin', 'docente'], true)) return true;
        if ($role !== 'administrativo') return false;
        $stmt = $this->pdo->prepare('SELECT estado FROM cursos WHERE id = ?');
        $stmt->execute([$courseId]);
        return $stmt->fetchColumn() === 'privado';
    }

    private function role(int $userId): string
    {
        $stmt = $this->pdo->prepare('SELECT rol FROM usuarios WHERE id = ?');
        $stmt->execute([$userId]);
        return (string)$stmt->fetchColumn();
    }

    private function resolveState(array $accesses): array
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        // Con ACCESS_EXPIRATION_ENABLED=false los accesos no vencen: basta con que haya empezado
        $expira = !defined('ACCESS_EXPIRATION_ENABLED') || ACCESS_EXPIRATION_ENABLED;
        $expired = null;
        $future = null;
        foreach ($accesses as $access) {
            if (!empty($access['revocado_at'])) continue;
            $start = new DateTimeImmutable($access['acceso_inicio'], new DateTimeZone('UTC'));
            $end = new DateTimeImmutable($access['acceso_fin'], new DateTimeZone('UTC'));
            if ($start <= $now && (!$expira || $now < $end)) return $this->payload('activo', true, $access);
            if ($start > $now && (!$future || $start < $future['date'])) $future = ['date' => $start, 'access' => $access];
            if ($end <= $now && (!$expired || $end > $expired['date'])) $expired = ['date' => $end, 'access' => $access];
        }
        if ($future) return $this->payload('pendiente', false, $future['access']);
        if ($expired) return $this->payload('vencido', false, $expired['access']);
        return ['status' => 'sin_acceso', 'active' => false, 'starts_at' => null, 'ends_at' => null];
    }

    private function payload(string $status, bool $active, array $access): array
    {
        return [
            'status' => $status, 'active' => $active,
            'starts_at' => $access['acceso_inicio'], 'ends_at' => $access['acceso_fin'],
            'source' => $access['fuente'] ?? $access['producto_tipo'], 'access_id' => (int)$access['id'],
        ];
    }
}
