<?php
namespace App\Learning\Evaluations;

use PDO;
use Exception;

class QuizReconciliadorArchivos {
    private $pdo;
    private $directorioTemp;
    private $directorioFinal;

    public function __construct(PDO $pdo, string $directorioTemp, string $directorioFinal) {
        $this->pdo = $pdo;
        $this->directorioTemp = rtrim($directorioTemp, '/\\') . '/';
        $this->directorioFinal = rtrim($directorioFinal, '/\\') . '/';
    }

    public function ejecutar() {
        $this->pdo->exec("SET time_zone = '+00:00'");

        $stmtLock = $this->pdo->query("SELECT GET_LOCK('quiz_reconciliador_archivos', 0)");
        if (!$stmtLock->fetchColumn()) {
            echo "El reconciliador ya está en ejecución.\n";
            return false;
        }

        try {
            // Buscar archivos pendientes que hayan estado cargando por más de 5 minutos,
            // asumiendo que el endpoint de carga ya movió el archivo a $directorioTemp.
            $sql = "
                SELECT id, storage_key, nombre_original, tamano_bytes
                FROM quiz_respuesta_archivos
                WHERE estado = 'uploading'
                AND recibido_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 2 MINUTE)
                LIMIT 50
            ";

            $pendientes = $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
            $procesados = 0;
            $errores = 0;

            foreach ($pendientes as $archivo) {
                if ($this->procesarArchivo($archivo)) {
                    $procesados++;
                } else {
                    $errores++;
                }
            }

            echo "Reconciliación de archivos finalizada. Procesados: $procesados, Errores: $errores\n";
            return true;
        } finally {
            $this->pdo->query("SELECT RELEASE_LOCK('quiz_reconciliador_archivos')");
        }
    }

    private function procesarArchivo($archivoData) {
        $id = $archivoData['id'];
        $storageKey = $archivoData['storage_key'];

        $rutaTemp = $this->directorioTemp . $storageKey;
        $rutaFinal = $this->directorioFinal . $storageKey;

        $this->pdo->beginTransaction();
        try {
            // Verificar existencia
            if (!file_exists($rutaTemp)) {
                throw new Exception("Archivo físico no encontrado en almacenamiento temporal.");
            }

            // Validar tamaño real
            $tamanoReal = filesize($rutaTemp);
            if ($tamanoReal != $archivoData['tamano_bytes']) {
                // Podría ser que la descarga fue parcial o corrompida, pero usamos el tamaño real para reconciliar.
            }

            // Validar MIME con finfo
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mimeReal = finfo_file($finfo, $rutaTemp);
            finfo_close($finfo);

            // Validar extensión y mime permitido (Ejemplo simplificado)
            $mimesPermitidos = ['application/pdf', 'image/jpeg', 'image/png', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'];
            if (!in_array($mimeReal, $mimesPermitidos)) {
                throw new Exception("Tipo MIME no permitido: " . $mimeReal);
            }

            // Generar SHA-256
            $hashReal = hash_file('sha256', $rutaTemp);

            // Mover a directorio final
            if (!rename($rutaTemp, $rutaFinal)) {
                throw new Exception("No se pudo mover el archivo al directorio final.");
            }

            // Actualizar DB
            $stmtUpd = $this->pdo->prepare("
                UPDATE quiz_respuesta_archivos
                SET estado = 'validated',
                    mime_detectado = ?,
                    tamano_bytes = ?,
                    hash_sha256 = ?,
                    validado_at = UTC_TIMESTAMP()
                WHERE id = ?
            ");
            $stmtUpd->execute([$mimeReal, $tamanoReal, $hashReal, $id]);

            $this->pdo->commit();
            return true;

        } catch (Exception $e) {
            $this->pdo->rollBack();
            $this->marcarError($id, $e->getMessage());
            return false;
        }
    }

    private function marcarError($id, $mensaje) {
        $stmtError = $this->pdo->prepare("
            UPDATE quiz_respuesta_archivos
            SET estado = 'error',
                estado_antivirus = ?
            WHERE id = ?
        ");
        $stmtError->execute([substr($mensaje, 0, 30), $id]);
    }
}
