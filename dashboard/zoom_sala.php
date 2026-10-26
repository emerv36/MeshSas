<?php
require_once '../config.php';
require_once __DIR__ . '/../helpers/csrf_helper.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login');
    exit;
}

$meetingNumberRaw = $_GET['meeting'] ?? '';
$meetingNumber = preg_replace('/[^0-9]/', '', $meetingNumberRaw); // Eliminar cualquier cosa que no sea número
$passWord = $_GET['pass'] ?? '';
$role = 0; // FORZAMOS A 0 PARA TESTEAR
$userName = (string)($_SESSION['user_name'] ?? 'Usuario');
if (intval($_GET['role'] ?? 0) === 1) $userName .= ' (Host)';

if (!$meetingNumber) {
    echo '<h2>Error: No se proporcionó un Meeting ID</h2>';
    exit;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Zoom - <?= htmlspecialchars(ACADEMY_NAME) ?></title>
    <link type="text/css" rel="stylesheet" href="https://source.zoom.us/2.17.0/css/bootstrap.css" />
    <link type="text/css" rel="stylesheet" href="https://source.zoom.us/2.17.0/css/react-select.css" />
    <style>
        body { margin: 0; padding: 0; background: #000; }
        #zmmtg-root { width: 100vw; height: 100vh; }
        .loading-zoom {
            display: flex; flex-direction: column; align-items: center; justify-content: center;
            height: 100vh; color: white; font-family: 'Segoe UI', sans-serif;
        }
        .loading-zoom .spinner {
            width: 48px; height: 48px; border: 4px solid rgba(255,255,255,0.2);
            border-top-color: #2d8cff; border-radius: 50%;
            animation: spin 0.8s linear infinite; margin-bottom: 1.5rem;
        }
        @keyframes spin { to { transform: rotate(360deg); } }
    </style>
</head>
<body>
    <div class="loading-zoom" id="zoom-loading">
        <div class="spinner"></div>
        <h3>Conectando a Zoom...</h3>
        <p style="color:#9ca3af;">Preparando la sala de reunión</p>
    </div>

    <script src="https://source.zoom.us/2.17.0/lib/vendor/react.min.js"></script>
    <script src="https://source.zoom.us/2.17.0/lib/vendor/react-dom.min.js"></script>
    <script src="https://source.zoom.us/2.17.0/lib/vendor/redux.min.js"></script>
    <script src="https://source.zoom.us/2.17.0/lib/vendor/redux-thunk.min.js"></script>
    <script src="https://source.zoom.us/2.17.0/lib/vendor/lodash.min.js"></script>
    <script src="https://source.zoom.us/zoom-meeting-2.17.0.min.js"></script>
    <script>
        const meetingNumber = <?= json_encode($meetingNumber, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
        const passWord = <?= json_encode((string)$passWord, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
        const userName = <?= json_encode($userName, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
        const role = <?= $role ?>;

        ZoomMtg.setZoomJSLib('https://source.zoom.us/2.17.0/lib', '/av');
        ZoomMtg.preLoadWasm();
        ZoomMtg.prepareWebSDK();
        ZoomMtg.i18n.load('en-US');
        ZoomMtg.i18n.reload('en-US');

        // Obtener la firma del servidor
        const fd = new FormData();
        fd.append('meetingNumber', meetingNumber);
        fd.append('role', role);
        fd.append('csrf_token', <?= json_encode(get_csrf_token()) ?>);

        fetch('../api/zoom_signature.php', { method: 'POST', body: fd })
        .then(res => res.json())
        .then(data => {
            if (data.error) {
                document.getElementById('zoom-loading').innerHTML = `
                    <i style="font-size:3rem; color:#ef4444;">⚠️</i>
                    <h3 style="color:#ef4444;">Error de Firma</h3>
                    <p style="color:#9ca3af;">${data.error}</p>
                `;
                return;
            }

            ZoomMtg.init({
                leaveUrl: document.referrer || window.location.origin,
                isSupportAV: true,
                success: function() {
                    document.getElementById('zoom-loading').style.display = 'none';
                    ZoomMtg.join({
                        sdkKey: data.sdkKey,
                        signature: data.signature,
                        meetingNumber: meetingNumber,
                        passWord: passWord,
                        userName: userName,
                        userEmail: '', // Requerido en algunas configuraciones
                        success: function(res) { console.log('Zoom conectado exitosamente'); },
                        error: function(res) { 
                            console.error('Error al unirse:', res);
                            // Mostrar el error exacto en pantalla en lugar de dejarlo en negro
                            document.body.innerHTML = `
                                <div class="loading-zoom" style="background:#000;">
                                    <i style="font-size:3rem; color:#ef4444;">⚠️</i>
                                    <h3 style="color:#ef4444;">Error al Unirse (${res.errorCode})</h3>
                                    <p style="color:#9ca3af;">${res.errorMessage || res.result || 'Revisa la consola'}</p>
                                    <p style="color:#6b7280; font-size:0.8rem; margin-top:1rem; max-width:600px; text-align:center;">
                                    Tip: Asegúrate de que tu Zoom App Type sea "Meeting SDK" (no OAuth ni JWT clásico), y que la Key y Secret en el archivo .env sean exactas.
                                    </p>
                                </div>
                            `;
                        }
                    });
                },
                error: function(res) {
                    console.error('Error al iniciar Zoom:', res);
                    document.getElementById('zoom-loading').innerHTML = `
                        <i style="font-size:3rem; color:#ef4444;">⚠️</i>
                        <h3 style="color:#ef4444;">Error al iniciar Zoom</h3>
                        <p style="color:#9ca3af;">${res.errorMessage || 'Error en init'}</p>
                    `;
                }
            });
        }).catch(e => {
            console.error(e);
            document.getElementById('zoom-loading').innerHTML = `
                <i style="font-size:3rem; color:#ef4444;">⚠️</i>
                <h3 style="color:#ef4444;">Error de conexión</h3>
                <p style="color:#9ca3af;">No se pudo conectar con el servidor</p>
            `;
        });
    </script>
</body>
</html>
