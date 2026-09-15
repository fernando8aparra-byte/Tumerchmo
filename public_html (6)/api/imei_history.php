<?php
/**
 * imei_history.php
 * -------------------------------------------------------------
 * Busca en TODOS los pedidos (de cualquier técnico) por un
 * IMEI/SN/ECID y regresa qué servicios se le han hecho a ese equipo.
 *
 * IMPORTANTE -- privacidad: a propósito NO regresa quién fue el
 * técnico que hizo cada pedido, ni datos del cliente. Solo se admin
 * puede ver esa parte (en admin.html, dentro del detalle de cada
 * técnico). Aquí solo se comparte: fecha, nombre del servicio, estado,
 * y el link de descarga del software si el servicio lo tiene
 * configurado -- así cualquier técnico logueado puede consultar el
 * historial de un equipo sin ver información de negocio ajena.
 * -------------------------------------------------------------
 * INSTALACIÓN: sube este archivo a /api.
 * -------------------------------------------------------------
 */

require __DIR__ . '/firestore-admin.php';

define('FIREBASE_WEB_API_KEY', 'AIzaSyAv2zk1jEQJcyTfEb7RsmsNtFAp3J5sSnI');

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Método no permitido']);
    exit;
}

/* ---------- Solo usuarios con sesión iniciada pueden buscar ---------- */
$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
if (!preg_match('/Bearer\s+(.+)/i', $authHeader, $m)) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Falta el token de sesión']);
    exit;
}
if (!verifyFirebaseIdToken(trim($m[1]))) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Token inválido o expirado, vuelve a iniciar sesión']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$term = trim((string)($input['term'] ?? ''));
if ($term === '' || mb_strlen($term) < 4) {
    echo json_encode(['ok' => false, 'error' => 'Escribe al menos 4 caracteres del IMEI, SN o ECID']);
    exit;
}

$fsToken = getFirestoreAdminToken();
if (!$fsToken) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'No se pudo autenticar con Firestore']);
    exit;
}

/* ---------- Buscar en las 3 formas en que se guarda un identificador ---------- */
$byImei = fsListByField($fsToken, 'orders', 'imei', $term);
$bySn   = fsListByField($fsToken, 'orders', 'sn', $term);
// Pedidos viejos (flujo de registro de equipo) guardan el ECID/serie
// dentro de un objeto deviceId.value -- Firestore sí permite filtrar
// directo por un campo anidado usando notación de punto.
$byDeviceId = fsListByField($fsToken, 'orders', 'deviceId.value', $term);

// Unimos y quitamos duplicados por _id.
$all = array_merge($byImei, $bySn, $byDeviceId);
$seen = [];
$unique = [];
foreach ($all as $o) {
    $id = $o['_id'] ?? null;
    if (!$id || isset($seen[$id])) continue;
    $seen[$id] = true;
    $unique[] = $o;
}

if (empty($unique)) {
    echo json_encode(['ok' => true, 'results' => []]);
    exit;
}

/* ---------- Para cada pedido, buscar el link de descarga del servicio (si aplica) ---------- */
$catalogCache = [];
$results = [];
foreach ($unique as $o) {
    $serviceId = $o['serviceId'] ?? null;
    // Los registros de equipo (Paso 3 de index.html) ya traen su propio
    // link directo (programLink) porque el software se elige ahí mismo,
    // sin pasar por el catálogo de servicios comprables.
    $softwareLink = $o['programLink'] ?? null;
    if (!$softwareLink && $serviceId) {
        if (!array_key_exists($serviceId, $catalogCache)) {
            $catalogCache[$serviceId] = fsGetDoc($fsToken, 'catalog/' . $serviceId);
        }
        $softwareLink = $catalogCache[$serviceId]['softwareLink'] ?? null;
    }

    $results[] = [
        'date'         => $o['createdAt'] ?? null,
        'service'      => $o['serviceLabel'] ?? ($o['program'] ?? 'Servicio'),
        'status'       => $o['status'] ?? null,
        'lockLabel'    => $o['lockLabel'] ?? null,
        'softwareLink' => $softwareLink,
        // No se regresa: ownerUid, email del técnico, cliente, ni ningún dato de negocio.
    ];
}

// Más reciente primero.
usort($results, function($a, $b) {
    return strcmp((string)($b['date'] ?? ''), (string)($a['date'] ?? ''));
});

echo json_encode(['ok' => true, 'results' => $results], JSON_UNESCAPED_UNICODE);
exit;


function verifyFirebaseIdToken(string $idToken): bool
{
    if (!function_exists('curl_init')) return false;
    $url = 'https://identitytoolkit.googleapis.com/v1/accounts:lookup?key=' . FIREBASE_WEB_API_KEY;
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode(['idToken' => $idToken]),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
    ]);
    $raw = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($raw === false || $code !== 200) return false;
    $data = json_decode($raw, true);
    return isset($data['users'][0]['localId']);
}