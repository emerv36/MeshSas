---
name: pruebas-aislamiento
description: Cómo probar MeshSas con dos o más instituciones en local (subdominios *.meshsas.test, sesiones de prueba, peticiones HTTP) y verificar que una institución no puede ver ni modificar datos de otra. Usar al cerrar cada módulo o fase de PLAN_SAAS.md y en la fase 6.
---

# Pruebas de aislamiento en MeshSas

## Entorno local (fase 0)
- **VirtualHost** en `C:\xampp\apache\conf\extra\httpd-vhosts.conf`:
  ```apache
  <VirtualHost *:80>
      ServerName meshsas.test
      ServerAlias *.meshsas.test
      DocumentRoot "C:/xampp/htdocs/MeshSas"
      <Directory "C:/xampp/htdocs/MeshSas">
          AllowOverride All
          Require all granted
      </Directory>
  </VirtualHost>
  ```
  Conserva antes un VirtualHost para `localhost`, para que los demás proyectos sigan funcionando.
- **Archivo `hosts`** (`C:\Windows\System32\drivers\etc\hosts`, requiere administrador): `127.0.0.1 meshsas.test demo1.meshsas.test demo2.meshsas.test admin.meshsas.test`. Dale al usuario los pasos y reinicia Apache.
- **Sin acceso al `hosts`:** `curl` permite fijar el host sin tocar nada: `curl -H "Host: demo1.meshsas.test" http://127.0.0.1/...`, o `--resolve demo1.meshsas.test:80:127.0.0.1`.

## Sesiones de prueba sin contraseña
PHP guarda las sesiones en `C:\xampp\tmp\sess_<id>`. Una sesión de prueba se crea así:
```bash
printf 'user_id|i:ID;user_rol|s:5:"admin";user_name|s:6:"Prueba";institucion_id|i:1;csrf_token|s:64:"<64 hex>";' > /c/xampp/tmp/sess_pruebaA
curl -s -b PHPSESSID=pruebaA -H "Host: demo1.meshsas.test" http://127.0.0.1/admin/index
```
Bórralas al terminar (`rm /c/xampp/tmp/sess_prueba*`).

## Matriz mínima por módulo
Con datos en las instituciones A y B, y una sesión de A:

| Prueba | Resultado esperado |
|---|---|
| Ver un recurso propio de A en el host de A | 200 |
| Ver el mismo tipo de recurso de B, con su `id`, en el host de A | 404 (o 403) |
| Hacer POST para modificar o borrar un recurso de B desde A | 403/404, y **la fila de B no cambia** (compruébalo en la base) |
| Usar la sesión de A en el host de B | Te manda al login o da 403; nunca muestra datos |
| Descargar un comprobante, tarea o archivo H5P de B desde A | 404 |
| Listados y contadores de A | No incluyen nada de B |
| `/verificar/{codigo}` de un certificado de B en el host de A | "No válido" |

Recursos a cubrir: cursos, módulos, lecciones, quizzes e intentos, pagos y comprobantes, inscripciones y accesos, tareas, Excel, H5P, certificados, usuarios, categorías, rutas, firmantes, prospectos, ajustes y portada.

## Script de la fase 6
`tests/aislamiento.php`, ejecutado por CLI contra `meshsas_db_prueba`:
- Crea dos instituciones con datos mínimos.
- Genera sesiones.
- Recorre la matriz con `curl` y peticiones con `Host`.
- Informa cada caso con OK o FALLA.
- Al final borra lo que creó.

Debe negarse a correr si la base no es la de prueba.

## Después de probar
- Restaura los datos de prueba y borra las sesiones.
- Revisa el log (`LOG_DIR` del `.env`): sin errores nuevos.
- Informa los resultados reales, también los que fallaron.
