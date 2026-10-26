<?php
// El acceso de todo el personal se hace por el login principal, que redirige
// al panel según el rol. Este archivo se conserva solo por enlaces antiguos.
header('Location: ../login', true, 301);
exit;
