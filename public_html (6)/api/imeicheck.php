<?php
/**
 * imeicheck.php
 * -------------------------------------------------------------
 * Endpoint para el servicio "Check IMEI" (service ID 11642) de
 * territorioremoto.com (formato DHRU Fusion).
 *
 * Lo llama index.html en DOS pasos:
 *   1) { "step": "place",  "imei": "356327109216543" }
 *      -> pide la consulta y regresa { ok:true, referenceId: ... }
 *   2) { "step": "status", "orderId": "<referenceId>" }
 *      -> consulta el resultado y regresa { ok:true, status:0|1|3|4, result: ... }
 *
 * Cada llamada debe incluir el header:
 *   Authorization: Bearer <idToken de Firebase>
 * Este archivo verifica ese token contra la API de Firebase antes de
 * hacer nada, para que nadie pueda usar tu saldo de API sin loguearse
 * en tu app.
 * -------------------------------------------------------------
 * INSTALACIÓN:
 * 1. Sube este archivo a tu hosting (junto a dhru_test.php).
 * 2. Revisa los valores de la sección "CONFIGURACIÓN" abajo.
 * 3. En index.html, cambia:
 *      const IMEI_CHECK_ENDPOINT = "https://TU-DOMINIO-HOSTINGER.com/api/imeicheck.php";
 *    por la URL real donde subiste este archivo.
 * -------------------------------------------------------------
 * FIX (8ª vuelta) -- CORRECCIÓN DEFINITIVA de las vueltas 4-7: el
 * proveedor nos pasó el enlace al cliente PHP OFICIAL de Dhru.com
 * (help.dhru.com/en/articles/10892898-api-integration, sección "PHP
 * Example", archivo dhrufusionapi.class.php). Ese código es la fuente
 * de verdad, no los repos de terceros que usamos antes. Dos cosas que
 * teníamos mal:
 *   1) "parameters" es XML armado con DOMDocument (claves en
 *      MAYÚSCULAS) y saveHTML() -- SIN base64, SIN JSON.
 *   2) El POST completo se manda como ARRAY a CURLOPT_POSTFIELDS, lo
 *      que hace que PHP/cURL lo mande como multipart/form-data, no
 *      como application/x-www-form-urlencoded (que era lo que
 *      forzábamos a mano con http_build_query() + header manual).
 * Las claves para placeimeiorder son 'IMEI' e 'ID', igual que en el
 * ejemplo oficial place_imei_order.php.
 * -------------------------------------------------------------
 */

/* ============ CONFIGURACIÓN ============ */
define('DHRU_API_URL', 'https://territorioremoto.com/api/index.php');
define('DHRU_USERNAME', 'fernando8aparra');
define('DHRU_API_KEY', 'R5M-ZX-G3Z-BZB-LCK-CIJ-1FY-NMM');

// Misma apiKey web que ya usas en el firebaseConfig de index.html.
define('FIREBASE_WEB_API_KEY', 'AIzaSyAv2zk1jEQJcyTfEb7RsmsNtFAp3J5sSnI');

// ID del servicio "Check IMEI" en el panel DHRU de territorioremoto.com.
define('IMEI_SERVICE_ID', 11642);
/* ========================================= */

header('Content-Type: application/json; charset=utf-8');

/* ---------- CORS ----------
 * Necesario porque index.html vive en un dominio distinto (Firebase
 * Hosting) al de este archivo (Hostinger). Sin esto, el navegador
 * bloquea la respuesta con "Failed to fetch" aunque el servidor sí
 * haya procesado la petición.
 * El navegador manda primero una petición OPTIONS ("preflight") cuando
 * usas el header Authorization; hay que responderla con 204 y salir,
 * antes de intentar leer el body o verificar el token. */
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Método no permitido, usa POST']);
    exit;
}

/* ---------- 1) Verificar sesión de Firebase ---------- */
$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
if (!preg_match('/Bearer\s+(.+)/i', $authHeader, $m)) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Falta el token de sesión (Authorization: Bearer ...)']);
    exit;
}
$idToken = trim($m[1]);
$firebaseUser = verifyFirebaseIdToken($idToken);
if (!$firebaseUser) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Token inválido o expirado, vuelve a iniciar sesión']);
    exit;
}

/* ---------- 2) Leer el cuerpo JSON ---------- */
$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Cuerpo JSON inválido']);
    exit;
}
$step = $input['step'] ?? '';

/* ---------- 3) PASO: pedir la consulta ---------- */
if ($step === 'place') {
    $imei = preg_replace('/\D/', '', (string)($input['imei'] ?? ''));
    if (strlen($imei) < 14 || strlen($imei) > 16) {
        echo json_encode(['ok' => false, 'error' => 'IMEI inválido (debe tener 14-16 dígitos)']);
        exit;
    }

    // FIX (8ª vuelta): mismas claves EXACTAS que el ejemplo oficial
    // place_imei_order.php de Dhru.com: $para['IMEI'] y $para['ID'].
    $dhru = callDhruApi([
        'action'     => 'placeimeiorder',
        'parameters' => buildDhruParametersXml([
            'IMEI' => $imei,
            'ID'   => (string)IMEI_SERVICE_ID,
        ]),
    ]);

    if (!$dhru['ok']) {
        echo json_encode(['ok' => false, 'error' => $dhru['error'] ?? 'Error al conectar con el proveedor', 'raw' => $dhru]);
        exit;
    }
    $body = $dhru['response'];
    if (isset($body['ERROR'])) {
        $msg = $body['ERROR'][0]['MESSAGE'] ?? 'El proveedor rechazó la solicitud';
        echo json_encode(['ok' => false, 'error' => $msg, 'raw' => $body]);
        exit;
    }
    $referenceId = $body['SUCCESS'][0]['REFERENCEID'] ?? null;
    if (!$referenceId) {
        echo json_encode(['ok' => false, 'error' => 'El proveedor no regresó un número de orden', 'raw' => $body]);
        exit;
    }
    echo json_encode(['ok' => true, 'referenceId' => $referenceId, 'raw' => $body]);
    exit;
}

/* ---------- 4) PASO: consultar el resultado ---------- */
if ($step === 'status') {
    $orderId = trim((string)($input['orderId'] ?? ''));
    if ($orderId === '') {
        echo json_encode(['ok' => false, 'error' => 'Falta orderId']);
        exit;
    }

    // Mismo formato oficial: solo "ID" con el REFERENCEID que dio placeimeiorder.
    $dhru = callDhruApi([
        'action'     => 'getimeiorder',
        'parameters' => buildDhruParametersXml([
            'ID' => (string)$orderId,
        ]),
    ]);

    if (!$dhru['ok']) {
        echo json_encode(['ok' => false, 'error' => $dhru['error'] ?? 'Error al conectar con el proveedor', 'raw' => $dhru]);
        exit;
    }
    $body = $dhru['response'];
    if (isset($body['ERROR'])) {
        $msg = $body['ERROR'][0]['MESSAGE'] ?? 'El proveedor rechazó la consulta';
        echo json_encode(['ok' => false, 'error' => $msg, 'raw' => $body]);
        exit;
    }
    $data = $body['SUCCESS'][0] ?? [];
    // STATUS: 0 Nuevo, 1 En proceso, 3 Rechazado (reembolso), 4 Disponible (éxito)
    echo json_encode([
        'ok'     => true,
        'status' => isset($data['STATUS']) ? (int)$data['STATUS'] : 0,
        'result' => $data['CODE'] ?? null,
        'raw'    => $data,
    ]);
    exit;
}

http_response_code(400);
echo json_encode(['ok' => false, 'error' => 'step inválido, usa "place" o "status"']);
exit;


/* =================================================================
 * Verifica un ID token de Firebase contra la API de Google, sin
 * necesitar ninguna librería extra (solo cURL).
 * ================================================================= */
function verifyFirebaseIdToken(string $idToken): ?array
{
    if (!function_exists('curl_init')) return null;
    $url = 'https://identitytoolkit.googleapis.com/v1/accounts:lookup?key=' . FIREBASE_WEB_API_KEY;

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode(['idToken' => $idToken]),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
    ]);
    $raw = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($raw === false || $code !== 200) return null;
    $data = json_decode($raw, true);
    if (!isset($data['users'][0]['localId'])) return null;

    return [
        'uid'   => $data['users'][0]['localId'],
        'email' => $data['users'][0]['email'] ?? null,
    ];
}

/* =================================================================
 * Arma el XML <PARAMETERS><CLAVE>valor</CLAVE>...</PARAMETERS> igual
 * que el método action() de la clase OFICIAL dhrufusionapi.class.php
 * (la que da Dhru.com en su Centro de Ayuda): DOMDocument, claves en
 * MAYÚSCULAS, y saveHTML() -- sin base64, sin JSON.
 * ================================================================= */
function buildDhruParametersXml(array $arr): string
{
    if (!count($arr)) return '';
    $dom = new DOMDocument();
    $request = $dom->createElement('PARAMETERS');
    $dom->appendChild($request);
    foreach ($arr as $key => $val) {
        $key = strtoupper($key);
        $request->appendChild($dom->createElement($key, (string)$val));
    }
    return $dom->saveHTML();
}

/* =================================================================
 * Llama a la API DHRU de territorioremoto.com.
 * FIX (8ª vuelta) -- CORRECCIÓN de las vueltas 4-7: conseguimos el
 * cliente PHP OFICIAL de Dhru.com (help.dhru.com, sección "PHP
 * Example") y hace dos cosas distintas a lo que teníamos:
 *   1) "parameters" es XML armado con DOMDocument->saveHTML(), SIN
 *      base64 (la 5ª/6ª/7ª vuelta asumieron base64+JSON por el repo
 *      "dhru-fusion-api-standards", que resultó ser solo un ejemplo
 *      de referencia para armar tu PROPIO servidor, no el formato
 *      real que espera un servidor DHRU Fusion de verdad).
 *   2) El POST se manda como ARRAY a CURLOPT_POSTFIELDS -- eso hace
 *      que PHP/cURL lo mande como multipart/form-data. Nosotros
 *      forzábamos application/x-www-form-urlencoded con
 *      http_build_query(), que es un formato distinto.
 * ================================================================= */
function callDhruApi(array $extraParams): array
{
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'error' => 'La extensión cURL de PHP no está activada en este hosting.'];
    }

    $payload = array_merge([
        'username'      => DHRU_USERNAME,
        'apiaccesskey'  => DHRU_API_KEY,
        'requestformat' => 'JSON',
    ], $extraParams);

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => DHRU_API_URL,
        CURLOPT_POST           => true,
        // Array (no string) => multipart/form-data, igual que el cliente oficial.
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $rawResponse = curl_exec($ch);
    $httpCode    = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError   = curl_error($ch);
    curl_close($ch);

    if ($rawResponse === false) {
        return ['ok' => false, 'http_code' => $httpCode, 'error' => 'Error de conexión: ' . $curlError];
    }

    $decoded = json_decode($rawResponse, true);
    if (json_last_error() === JSON_ERROR_NONE) {
        return ['ok' => ($httpCode >= 200 && $httpCode < 300), 'http_code' => $httpCode, 'response' => $decoded];
    }
    return [
        'ok' => false, 'http_code' => $httpCode,
        'error' => 'La respuesta del proveedor no es JSON válido',
        'raw_response' => $rawResponse,
    ];
}