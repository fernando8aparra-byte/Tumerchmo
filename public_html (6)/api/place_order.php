<?php
/**
 * place_order.php (v3)
 * -------------------------------------------------------------
 * Coloca y consulta pedidos de los servicios que TÚ agregaste desde
 * admin.html ("Agregar servicio"). Todo lo sensible pasa aquí, en el
 * servidor:
 *   - El precio: se lee del catálogo en Firestore (el que tú pusiste
 *     al agregar el servicio), nunca de lo que mande el navegador.
 *   - El saldo: se lee y se descuenta aquí, con una cuenta de admin de
 *     Firestore que el navegador no puede tocar.
 *   - Las claves de DHRU (usuario + API key): se leen de Firestore
 *     (colección "config", doc "dhru"), YA NO están escritas en este
 *     archivo. Configúralas una vez desde admin.html.
 * -------------------------------------------------------------
 * INSTALACIÓN: sube este archivo junto con firestore-admin.php a la
 * misma carpeta que antes (api). Necesita que ya hayas configurado:
 *   1. La cuenta de servicio de Google (ver firestore-admin.php)
 *   2. Usuario + API key de DHRU desde admin.html → "Config. DHRU"
 *   3. Al menos un servicio agregado desde admin.html → "Agregar servicio"
 * -------------------------------------------------------------
 */

require __DIR__ . '/firestore-admin.php';
require __DIR__ . '/notify-admin.php';

define('FIREBASE_WEB_API_KEY', 'AIzaSyAv2zk1jEQJcyTfEb7RsmsNtFAp3J5sSnI');
define('DHRU_API_URL', 'https://territorioremoto.com/api/index.php');

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Método no permitido, usa POST']);
    exit;
}

/* ---------- 1) Verificar sesión de Firebase (identidad del usuario) ---------- */
$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
if (!preg_match('/Bearer\s+(.+)/i', $authHeader, $m)) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Falta el token de sesión (Authorization: Bearer ...)']);
    exit;
}
$firebaseUser = verifyFirebaseIdToken(trim($m[1]));
if (!$firebaseUser) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Token inválido o expirado, vuelve a iniciar sesión']);
    exit;
}
$uid = $firebaseUser['uid'];

/* ---------- 2) Token de ADMIN para Firestore ---------- */
$fsToken = getFirestoreAdminToken();
if (!$fsToken) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'No se pudo autenticar con Firestore. Revisa que subiste el archivo de cuenta de servicio (ver firestore-admin.php).']);
    exit;
}

/* ---------- 3) Credenciales de DHRU (desde Firestore, no de este archivo) ---------- */
$dhruConfig = fsGetDoc($fsToken, 'config/dhru');
if (!$dhruConfig || empty($dhruConfig['username']) || empty($dhruConfig['apikey'])) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Faltan configurar el usuario/API key de DHRU. Ve a admin.html → "Config. DHRU" y guárdalos.']);
    exit;
}
define('DHRU_USERNAME', $dhruConfig['username']);
define('DHRU_API_KEY', $dhruConfig['apikey']);

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Cuerpo JSON inválido']);
    exit;
}
$step = $input['step'] ?? '';

/* ---------- PASO: colocar el pedido ---------- */
if ($step === 'place') {
    // Teléfono verificado, obligatorio para COMPRAR (no para consultar
    // el estado de pedidos ya existentes). Se checa contra Firebase Auth,
    // no contra un campo de Firestore que el navegador podría manipular.
    if (empty($firebaseUser['phoneVerified'])) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Debes verificar tu número de teléfono antes de comprar. Ve al menú → "Verificar teléfono".']);
        exit;
    }

    $serviceId = trim((string)($input['serviceId'] ?? ''));
    $values    = is_array($input['values'] ?? null) ? $input['values'] : [];
    $imei      = trim((string)($values['imei'] ?? ''));
    $sn        = trim((string)($values['sn'] ?? ''));
    $username  = trim((string)($values['username'] ?? ''));
    $customerEmail = trim((string)($values['email'] ?? ''));
    $ecid      = trim((string)($values['ecid'] ?? ''));
    // Nota privada que el técnico escribe antes de pagar (para acordarse
    // de qué cliente suyo era, etc). Solo la ve él en "Mis pedidos".
    $myNote    = trim((string)($input['myNote'] ?? ''));
    if (mb_strlen($myNote) > 500) $myNote = mb_substr($myNote, 0, 500);

    if ($serviceId === '') {
        echo json_encode(['ok' => false, 'error' => 'Falta serviceId']);
        exit;
    }

    // 3.1) El servicio y su precio, tal como TÚ los guardaste al
    // agregarlo -- nunca confiamos en un precio que mande el navegador.
    $catalogDoc = fsGetDoc($fsToken, 'catalog/' . $serviceId);
    if (!$catalogDoc || ($catalogDoc['enabled'] ?? true) === false) {
        echo json_encode(['ok' => false, 'error' => 'Ese servicio no existe o está desactivado.']);
        exit;
    }
    $price = (float)($catalogDoc['price'] ?? 0);
    if ($price <= 0) {
        echo json_encode(['ok' => false, 'error' => 'Este servicio no tiene un precio configurado.']);
        exit;
    }
    $identifierType = strtoupper((string)($catalogDoc['identifierType'] ?? 'IMEI')); // IMEI | SN | BOTH | USER_EMAIL | ECID

    // 3.2) Validar que mandaron el/los dato(s) que este servicio pide.
    if (($identifierType === 'IMEI' || $identifierType === 'BOTH') && $imei === '') {
        echo json_encode(['ok' => false, 'error' => 'Falta el IMEI']);
        exit;
    }
    if (($identifierType === 'SN' || $identifierType === 'BOTH') && $sn === '') {
        echo json_encode(['ok' => false, 'error' => 'Falta el número de serie (SN)']);
        exit;
    }
    if ($identifierType === 'ECID' && $ecid === '') {
        echo json_encode(['ok' => false, 'error' => 'Falta el ECID']);
        exit;
    }
    if ($identifierType === 'USER_EMAIL') {
        if ($username === '') {
            echo json_encode(['ok' => false, 'error' => 'Falta el usuario']);
            exit;
        }
        if ($customerEmail === '' || !filter_var($customerEmail, FILTER_VALIDATE_EMAIL)) {
            echo json_encode(['ok' => false, 'error' => 'Falta un correo válido']);
            exit;
        }
    }

    // 3.3) Saldo actual.
    $tech = fsGetDoc($fsToken, 'technicians/' . $uid);
    $balance = (float)($tech['balance'] ?? 0);
    if ($balance < $price) {
        echo json_encode(['ok' => false, 'error' => "Saldo insuficiente. Necesitas $" . number_format($price, 2) . " USD y tienes $" . number_format($balance, 2) . " USD."]);
        exit;
    }

    // 3.4) Descontamos ANTES de llamar a DHRU (si falla, reembolsamos).
    $newBalance = round($balance - $price, 2);
    fsPatchDoc($fsToken, 'technicians/' . $uid, ['balance' => $newBalance]);

    // 3.4.1) Sumamos 1 al contador de compras de este servicio -- se usa
    // para la insignia "Hot" y para ordenar por más/menos vendido en el
    // apartado de Productos del admin. No es perfectamente atómico
    // (lee y luego escribe), pero para un contador de popularidad el
    // riesgo de una carrera muy rara no importa.
    $currentCount = (int)($catalogDoc['purchaseCount'] ?? 0);
    fsPatchDoc($fsToken, 'catalog/' . $serviceId, ['purchaseCount' => $currentCount + 1]);

    // 3.5) Creamos la orden.
    $orderId = fsCreateDoc($fsToken, 'orders', null, [
        'ownerUid'     => $uid,
        'email'        => $firebaseUser['email'] ?? null,
        'service'      => 'catalogo',
        'serviceId'    => (string)$serviceId,
        'serviceLabel' => $catalogDoc['name'] ?? $serviceId,
        'imei'         => $imei !== '' ? $imei : null,
        'sn'           => $sn !== '' ? $sn : null,
        'ecid'         => $ecid !== '' ? $ecid : null,
        'username'     => $username !== '' ? $username : null,
        'customerEmail'=> $customerEmail !== '' ? $customerEmail : null,
        'myNote'       => $myNote !== '' ? $myNote : null,
        'amount'       => $price,
        'status'       => 'procesando',
        'createdAt'    => gmdate('Y-m-d\TH:i:s\Z'),
        'updatedAt'    => gmdate('Y-m-d\TH:i:s\Z'),
    ]);
    if (!$orderId) {
        fsPatchDoc($fsToken, 'technicians/' . $uid, ['balance' => $balance]); // reembolso
        echo json_encode(['ok' => false, 'error' => 'No se pudo registrar el pedido, inténtalo de nuevo.']);
        exit;
    }

    // Notificación al admin -- no bloquea el flujo si falla.
    notifyAdmin(
        $fsToken,
        'Nueva compra -- $' . number_format($price, 2) . ' USD',
        "🛒 Nueva compra en F3RN4N\n" .
        'Servicio: ' . ($catalogDoc['name'] ?? $serviceId) . "\n" .
        'Monto: $' . number_format($price, 2) . " USD\n" .
        'Técnico: ' . ($firebaseUser['email'] ?? $uid) . "\n" .
        'Saldo restante: $' . number_format($newBalance, 2) . ' USD'
    );

    // 3.6) Llamamos a DHRU. Mandamos los campos que pida este servicio.
    // NOTA: "ECID" es nuestra mejor suposición del nombre de campo que
    // espera DHRU para este dato (igual que hicimos con USERNAME/EMAIL).
    // Si el proveedor regresa un error de "parámetro requerido" para un
    // servicio ECID, revisa el 'raw' de la respuesta -- lo más probable
    // es que solo haya que cambiar esta clave por la que ellos usen.
    $params = ['ID' => $serviceId];
    if ($imei !== '') $params['IMEI'] = $imei;
    if ($sn !== '') $params['SN'] = $sn;
    if ($ecid !== '') $params['ECID'] = $ecid;
    if ($username !== '') $params['USERNAME'] = $username;
    if ($customerEmail !== '') $params['EMAIL'] = $customerEmail;
    $dhru = callDhruApi(['action' => 'placeimeiorder', 'parameters' => buildDhruParametersXml($params)]);

    if (!$dhru['ok']) {
        fsPatchDoc($fsToken, 'technicians/' . $uid, ['balance' => $balance]);
        fsPatchDoc($fsToken, 'orders/' . $orderId, ['status' => 'error', 'response' => $dhru['error'] ?? 'Error al conectar con el proveedor', 'updatedAt' => gmdate('Y-m-d\TH:i:s\Z')]);
        echo json_encode(['ok' => false, 'error' => $dhru['error'] ?? 'Error al conectar con el proveedor', 'raw' => $dhru]);
        exit;
    }
    $body = $dhru['response'];
    if (isset($body['ERROR'])) {
        $msg = $body['ERROR'][0]['MESSAGE'] ?? 'El proveedor rechazó la solicitud';
        fsPatchDoc($fsToken, 'technicians/' . $uid, ['balance' => $balance]);
        fsPatchDoc($fsToken, 'orders/' . $orderId, ['status' => 'error', 'response' => $msg, 'updatedAt' => gmdate('Y-m-d\TH:i:s\Z')]);
        echo json_encode(['ok' => false, 'error' => $msg, 'raw' => $body]);
        exit;
    }
    $referenceId = $body['SUCCESS'][0]['REFERENCEID'] ?? null;
    if (!$referenceId) {
        fsPatchDoc($fsToken, 'technicians/' . $uid, ['balance' => $balance]);
        fsPatchDoc($fsToken, 'orders/' . $orderId, ['status' => 'error', 'response' => 'El proveedor no regresó un número de orden', 'updatedAt' => gmdate('Y-m-d\TH:i:s\Z')]);
        echo json_encode(['ok' => false, 'error' => 'El proveedor no regresó un número de orden', 'raw' => $body]);
        exit;
    }

    fsPatchDoc($fsToken, 'orders/' . $orderId, ['dhruOrderId' => (string)$referenceId, 'updatedAt' => gmdate('Y-m-d\TH:i:s\Z')]);
    echo json_encode(['ok' => true, 'orderId' => $orderId, 'referenceId' => $referenceId, 'amountCharged' => $price, 'newBalance' => $newBalance]);
    exit;
}

/* ---------- PASO: consultar el resultado ---------- */
if ($step === 'status') {
    $orderId = trim((string)($input['orderId'] ?? ''));
    if ($orderId === '') {
        echo json_encode(['ok' => false, 'error' => 'Falta orderId']);
        exit;
    }
    $orderDoc = fsGetDoc($fsToken, 'orders/' . $orderId);
    if (!$orderDoc || (string)($orderDoc['ownerUid'] ?? '') !== $uid) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'No tienes acceso a este pedido']);
        exit;
    }
    $refId = (string)($orderDoc['dhruOrderId'] ?? '');
    if ($refId === '') {
        echo json_encode(['ok' => true, 'status' => 0, 'result' => null]);
        exit;
    }

    $dhru = callDhruApi(['action' => 'getimeiorder', 'parameters' => buildDhruParametersXml(['ID' => $refId])]);
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
    $status = isset($data['STATUS']) ? (int)$data['STATUS'] : 0;

    if ($status === 4) {
        $rawCode = $data['CODE'] ?? null;
        // El proveedor a veces regresa el resultado como texto JSON
        // (ej. {"username":"...","password":"..."}) -- si es el caso,
        // lo dejamos bien formateado y legible en vez de una sola línea.
        $formattedResult = $rawCode;
        if (is_string($rawCode)) {
            $decoded = json_decode($rawCode, true);
            if (json_last_error() === JSON_ERROR_NONE && (is_array($decoded))) {
                $formattedResult = json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
        }
        fsPatchDoc($fsToken, 'orders/' . $orderId, ['status' => 'completado', 'result' => $formattedResult, 'updatedAt' => gmdate('Y-m-d\TH:i:s\Z')]);
    } elseif ($status === 3) {
        $tech = fsGetDoc($fsToken, 'technicians/' . $uid);
        $balance = (float)($tech['balance'] ?? 0);
        $amount = (float)($orderDoc['amount'] ?? 0);
        fsPatchDoc($fsToken, 'technicians/' . $uid, ['balance' => round($balance + $amount, 2)]);
        fsPatchDoc($fsToken, 'orders/' . $orderId, ['status' => 'error', 'response' => 'El proveedor rechazó la orden -- se reembolsó tu saldo.', 'updatedAt' => gmdate('Y-m-d\TH:i:s\Z')]);
    }

    echo json_encode(['ok' => true, 'status' => $status, 'result' => $data['CODE'] ?? null, 'raw' => $data]);
    exit;
}

http_response_code(400);
echo json_encode(['ok' => false, 'error' => 'step inválido, usa "place" o "status"']);
exit;


/* =================================================================
 * Verifica un ID token de Firebase contra la API de Google.
 * ================================================================= */
function verifyFirebaseIdToken(string $idToken): ?array
{
    if (!function_exists('curl_init')) return null;
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
    if ($raw === false || $code !== 200) return null;
    $data = json_decode($raw, true);
    if (!isset($data['users'][0]['localId'])) return null;
    // phoneNumber solo viene poblado si de verdad se vinculó por SMS con
    // linkWithPhoneNumber -- no hay forma de falsificarlo desde el
    // navegador, a diferencia de un campo cualquiera en Firestore.
    return [
        'uid'   => $data['users'][0]['localId'],
        'email' => $data['users'][0]['email'] ?? null,
        'phoneVerified' => !empty($data['users'][0]['phoneNumber']),
    ];
}

/* =================================================================
 * XML <PARAMETERS>...</PARAMETERS> igual que la clase oficial de Dhru.com.
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
 * Llama a la API DHRU de territorioremoto.com (multipart, como el
 * cliente oficial).
 * ================================================================= */
function callDhruApi(array $extraParams): array
{
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'error' => 'La extensión cURL de PHP no está activada en este hosting.'];
    }
    $payload = array_merge([
        'username' => DHRU_USERNAME,
        'apiaccesskey' => DHRU_API_KEY,
        'requestformat' => 'JSON',
    ], $extraParams);

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => DHRU_API_URL,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $rawResponse = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($rawResponse === false) {
        return ['ok' => false, 'http_code' => $httpCode, 'error' => 'Error de conexión: ' . $curlError];
    }
    $decoded = json_decode($rawResponse, true);
    if (json_last_error() === JSON_ERROR_NONE) {
        return ['ok' => ($httpCode >= 200 && $httpCode < 300), 'http_code' => $httpCode, 'response' => $decoded];
    }
    return ['ok' => false, 'http_code' => $httpCode, 'error' => 'La respuesta del proveedor no es JSON válido', 'raw_response' => $rawResponse];
}