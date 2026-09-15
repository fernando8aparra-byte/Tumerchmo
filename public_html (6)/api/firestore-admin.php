<?php
/**
 * firestore-admin.php
 * -------------------------------------------------------------
 * Le da a tu PHP acceso de ADMINISTRADOR a Firestore, usando una
 * "cuenta de servicio" de Google -- esto es lo que hace que el
 * descuento de saldo sea seguro de verdad: pasa a vivir aquí, en tu
 * servidor, en vez de en el navegador (que cualquiera puede editar).
 *
 * Las reglas de seguridad de Firestore (firestore.rules) NO aplican a
 * este acceso -- por eso es "admin": puede leer y escribir lo que sea.
 * Por eso es CRÍTICO que el archivo de credenciales nunca sea
 * accesible desde el navegador (ver INSTALACIÓN abajo).
 * -------------------------------------------------------------
 * INSTALACIÓN:
 * 1. Ve a Firebase Console → engranaje (⚙) → Configuración del
 *    proyecto → Cuentas de servicio → "Generar nueva clave privada".
 *    Se descarga un archivo .json.
 * 2. Sube ese .json a tu hosting, en una carpeta que NO sea pública
 *    (por ejemplo un nivel arriba de donde vive index.html/api), o si
 *    no tienes esa opción, en la misma carpeta /api pero bloqueado.
 * 3. Ajusta SERVICE_ACCOUNT_KEY_PATH abajo con la ruta real.
 * 4. Si lo dejaste en /api, crea (o agrega a) el .htaccess de esa
 *    carpeta esto para que nadie pueda descargarlo desde el navegador:
 *      <FilesMatch "\.json$">
 *          Require all denied
 *      </FilesMatch>
 * 5. NUNCA subas ese .json a un repositorio público ni lo compartas.
 *    Quien lo tenga puede leer/editar TODA tu base de datos.
 * -------------------------------------------------------------
 */

define('SERVICE_ACCOUNT_KEY_PATH', '/home/u511071039/domains/skyblue-caterpillar-268674.hostingersite.com/firebase-service-account.json');
define('FS_PROJECT_ID', 'f3rn4n');
define('FS_BASE', 'https://firestore.googleapis.com/v1/projects/' . FS_PROJECT_ID . '/databases/(default)/documents');

/* =================================================================
 * Consigue un token de acceso de Google (OAuth2) usando la cuenta de
 * servicio. Firma un JWT con la llave privada del .json y lo cambia
 * por un access_token con Google.
 * ================================================================= */
function getFirestoreAdminToken(?string $scope = null): ?string
{
    // El scope por defecto solo sirve para Firestore. Para administrar
    // cuentas de usuario (custom claims) hace falta también el scope de
    // Identity Toolkit -- por eso se puede pasar uno distinto.
    $scope = $scope ?: 'https://www.googleapis.com/auth/datastore';

    static $cache = [];
    if (isset($cache[$scope]) && time() < $cache[$scope]['expiry'] - 30) {
        return $cache[$scope]['token'];
    }

    if (!file_exists(SERVICE_ACCOUNT_KEY_PATH)) {
        error_log('firestore-admin: no se encontró el archivo de cuenta de servicio en ' . SERVICE_ACCOUNT_KEY_PATH);
        return null;
    }
    $sa = json_decode(file_get_contents(SERVICE_ACCOUNT_KEY_PATH), true);
    if (!$sa || !isset($sa['private_key'], $sa['client_email'])) {
        error_log('firestore-admin: el archivo de cuenta de servicio no es válido');
        return null;
    }

    $now = time();
    $header = ['alg' => 'RS256', 'typ' => 'JWT'];
    $claims = [
        'iss'   => $sa['client_email'],
        'scope' => $scope,
        'aud'   => 'https://oauth2.googleapis.com/token',
        'iat'   => $now,
        'exp'   => $now + 3600,
    ];
    $segments = [fsBase64Url(json_encode($header)), fsBase64Url(json_encode($claims))];
    $signingInput = implode('.', $segments);
    $signature = '';
    $ok = openssl_sign($signingInput, $signature, $sa['private_key'], 'sha256WithRSAEncryption');
    if (!$ok) {
        error_log('firestore-admin: no se pudo firmar el JWT (revisa que openssl esté disponible)');
        return null;
    }
    $segments[] = fsBase64Url($signature);
    $jwt = implode('.', $segments);

    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion'  => $jwt,
        ]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
    ]);
    $raw = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $data = json_decode($raw, true);
    if ($code !== 200 || !isset($data['access_token'])) {
        error_log('firestore-admin: Google rechazó el token -- ' . $raw);
        return null;
    }
    $cache[$scope] = [
        'token'  => $data['access_token'],
        'expiry' => $now + ($data['expires_in'] ?? 3600),
    ];
    return $data['access_token'];
}

function fsBase64Url(string $data): string
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

/* =================================================================
 * Conversión entre valores PHP normales y el formato "tipado" que usa
 * la API REST de Firestore (ej. {"integerValue": "5"}).
 * ================================================================= */
function fsEncodeValue($v)
{
    if ($v === null) return ['nullValue' => null];
    if (is_bool($v)) return ['booleanValue' => $v];
    if (is_int($v)) return ['integerValue' => (string)$v];
    if (is_float($v)) return ['doubleValue' => $v];
    if (is_array($v)) {
        $isList = array_keys($v) === range(0, count($v) - 1);
        if ($isList) {
            return ['arrayValue' => ['values' => array_map('fsEncodeValue', $v)]];
        }
        $fields = [];
        foreach ($v as $k => $vv) $fields[$k] = fsEncodeValue($vv);
        return ['mapValue' => ['fields' => $fields]];
    }
    return ['stringValue' => (string)$v];
}

function fsDecodeValue($v)
{
    if (!is_array($v)) return null;
    if (array_key_exists('nullValue', $v)) return null;
    if (array_key_exists('booleanValue', $v)) return $v['booleanValue'];
    if (array_key_exists('integerValue', $v)) return (int)$v['integerValue'];
    if (array_key_exists('doubleValue', $v)) return (float)$v['doubleValue'];
    if (array_key_exists('stringValue', $v)) return $v['stringValue'];
    if (array_key_exists('timestampValue', $v)) return $v['timestampValue'];
    if (array_key_exists('mapValue', $v)) {
        $out = [];
        foreach (($v['mapValue']['fields'] ?? []) as $k => $vv) $out[$k] = fsDecodeValue($vv);
        return $out;
    }
    if (array_key_exists('arrayValue', $v)) {
        return array_map('fsDecodeValue', $v['arrayValue']['values'] ?? []);
    }
    return null;
}

/* =================================================================
 * Operaciones básicas de Firestore vía REST, con el token de admin.
 * ================================================================= */
function fsGetDoc(string $accessToken, string $path): ?array
{
    $ch = curl_init(FS_BASE . '/' . $path);
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $accessToken],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
    ]);
    $raw = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200) return null;
    $data = json_decode($raw, true);
    if (!isset($data['fields'])) return null;
    $out = [];
    foreach ($data['fields'] as $k => $v) $out[$k] = fsDecodeValue($v);
    return $out;
}

function fsPatchDoc(string $accessToken, string $path, array $fields): bool
{
    $body = ['fields' => []];
    foreach ($fields as $k => $v) $body['fields'][$k] = fsEncodeValue($v);
    $mask = implode('&', array_map(fn($k) => 'updateMask.fieldPaths=' . urlencode($k), array_keys($fields)));
    $ch = curl_init(FS_BASE . '/' . $path . '?' . $mask);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => 'PATCH',
        CURLOPT_POSTFIELDS => json_encode($body),
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $accessToken, 'Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
    ]);
    curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $code >= 200 && $code < 300;
}

function fsCreateDoc(string $accessToken, string $collectionPath, ?string $docId, array $fields): ?string
{
    $body = ['fields' => []];
    foreach ($fields as $k => $v) $body['fields'][$k] = fsEncodeValue($v);
    $url = FS_BASE . '/' . $collectionPath . ($docId ? '?documentId=' . urlencode($docId) : '');
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($body),
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $accessToken, 'Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
    ]);
    $raw = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code < 200 || $code >= 300) return null;
    $data = json_decode($raw, true);
    if (!isset($data['name'])) return $docId;
    $parts = explode('/', $data['name']);
    return end($parts);
}

/* Consulta simple: UNA sola igualdad (no necesita índice compuesto). */
function fsListByField(string $accessToken, string $collection, string $field, $value): array
{
    $structuredQuery = [
        'from'  => [['collectionId' => $collection]],
        'where' => [
            'fieldFilter' => [
                'field' => ['fieldPath' => $field],
                'op'    => 'EQUAL',
                'value' => fsEncodeValue($value),
            ],
        ],
    ];
    $ch = curl_init(FS_BASE . ':runQuery');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode(['structuredQuery' => $structuredQuery]),
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $accessToken, 'Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
    ]);
    $raw = curl_exec($ch);
    curl_close($ch);
    $rows = json_decode($raw, true);
    $out = [];
    if (is_array($rows)) {
        foreach ($rows as $row) {
            if (!isset($row['document'])) continue;
            $fields = [];
            foreach (($row['document']['fields'] ?? []) as $k => $v) $fields[$k] = fsDecodeValue($v);
            $parts = explode('/', $row['document']['name']);
            $fields['_id'] = end($parts);
            $out[] = $fields;
        }
    }
    return $out;
}