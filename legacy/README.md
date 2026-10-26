# Código Legacy

Esta carpeta (y la noción de "Legacy") indica que los archivos que se encuentran aquí o los archivos en la raíz del proyecto actual (como `pago.php`, `verificar.php`, etc.) siguen en funcionamiento pero serán reemplazados.

**Reglas sobre el código Legacy:**
1. NO ELIMINAR ningún archivo legacy hasta que su funcionalidad esté completamente migrada y probada en la nueva arquitectura `app/`.
2. Las correcciones urgentes se pueden hacer en los archivos legacy, pero las nuevas grandes características deben ir al sistema modular.
3. El código legacy utiliza consultas directas (PDO) combinadas con HTML. Esto está **estrictamente prohibido** en los módulos nuevos.
