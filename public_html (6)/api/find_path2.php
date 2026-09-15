<?php
/**
 * find_path2.php -- TEMPORAL, bórralo del hosting en cuanto termines.
 * Confirma la ruta real de tu servidor después de cambiar de dominio a
 * tumercho.com, para saber si firebase-service-account.json sigue en
 * la misma ruta o si Hostinger la renombró.
 */
header('Content-Type: text/plain; charset=utf-8');

$here = __DIR__;              // carpeta actual (api)
$oneUp = dirname($here);      // normalmente public_html
$twoUp = dirname($oneUp);     // normalmente el home de tu cuenta

function listDir($path) {
    if (!is_dir($path)) { echo "  (no se pudo leer esta carpeta)\n"; return; }
    foreach (scandir($path) as $f) {
        if ($f === '.' || $f === '..') continue;
        $full = $path . '/' . $f;
        $marker = (stripos($f, 'firebase') !== false || stripos($f, '.json') !== false) ? '  <-- ESTE' : '';
        echo '  - ' . $full . $marker . "\n";
    }
}

echo "Carpeta de este archivo (api):\n  $here\n\n";
echo "Un nivel arriba ($oneUp):\n";
listDir($oneUp);
echo "\n";
echo "Dos niveles arriba ($twoUp):\n";
listDir($twoUp);
echo "\nBusca la línea marcada con '<-- ESTE' -- esa es la ruta completa que necesito.\n";
