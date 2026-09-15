<?php
/**
 * binance-sign.php
 * -------------------------------------------------------------
 * Ayudante compartido para llamar a la API de Binance Pay con la
 * firma que exigen (HMAC-SHA512), usado tanto por
 * create_binance_order.php como por binance_webhook.php.
 *
 * Basado en la documentación oficial:
 *   https://merchant.binance.com/en/docs/getting-started
 * -------------------------------------------------------------
 */

define('BINANCE_API_BASE', 'https://bpay.binanceapi.com');

/* =================================================================
 * Genera un nonce aleatorio de 32 caracteres, como pide Binance.
 * ================================================================= */
function binanceNonce(): string
{
    return substr(bin2hex(random_bytes(20)), 0, 32);
}

/* =================================================================
 * Firma y llama a un endpoint de Binance Pay.
 * payload = timestamp + "\n" + nonce + "\n" + body + "\n"
 * signature = HMAC-SHA512(payload, secretKey) en hex, mayúsculas.
 * ================================================================= */
function callBinanceApi(string $apiKey, string $secretKey, string $path, array $bodyArr): array
{
    // Un arreglo vacío en PHP se codifica como "[]" (arreglo JSON), pero
    // las APIs casi siempre esperan "{}" (objeto JSON vacío) para "sin
    // parámetros" -- forzamos eso para no mandar algo que Binance
    // rechace silenciosamente.
    $bodyJson = count($bodyArr) ? json_encode($bodyArr, JSON_UNESCAPED_SLASHES) : '{}';
    $timestamp = (string) round(microtime(true) * 1000);
    $nonce = binanceNonce();
    $payload = $timestamp . "\n" . $nonce . "\n" . $bodyJson . "\n";
    $signature = strtoupper(hash_hmac('sha512', $payload, $secretKey));

    $ch = curl_init(BINANCE_API_BASE . $path);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $bodyJson,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'BinancePay-Timestamp: ' . $timestamp,
            'BinancePay-Nonce: ' . $nonce,
            'BinancePay-Certificate-SN: ' . $apiKey,
            'BinancePay-Signature: ' . $signature,
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
    ]);
    $raw = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($raw === false) {
        return ['ok' => false, 'error' => 'Error de conexión con Binance: ' . $curlError];
    }
    $data = json_decode($raw, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return ['ok' => false, 'error' => 'Binance no devolvió JSON válido', 'raw_response' => mb_substr($raw, 0, 500)];
    }
    return ['ok' => true, 'http_code' => $httpCode, 'data' => $data];
}
