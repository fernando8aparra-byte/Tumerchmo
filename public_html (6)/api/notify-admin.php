<?php
/**
 * notify-admin.php
 * -------------------------------------------------------------
 * Manda una notificación al admin por CORREO y por WHATSAPP (API de
 * WhatsApp Business de Meta) cada vez que alguien hace una compra o
 * una recarga. Se usa desde place_order.php y los 3 webhooks de pago.
 *
 * Las credenciales viven en Firestore -- config/email y
 * config/whatsapp -- las llenas desde admin.html, nunca están
 * escritas en este archivo.
 *
 * Si falta configurar algo (o si el envío falla), esto NUNCA debe
 * tronar el flujo principal (el pedido/recarga ya se procesó, avisar
 * es un extra) -- por eso todo está envuelto en try/catch silenciosos
 * con error_log para que tú puedas revisar en los logs de PHP si algo
 * no está llegando.
 * -------------------------------------------------------------
 */

/**
 * @param string $fsToken   Token de acceso a Firestore (de getFirestoreAdminToken()).
 * @param string $subject   Asunto del correo (ej. "Nueva recarga -- $50.00 USD").
 * @param string $message   Cuerpo del mensaje, texto plano, se usa para correo Y WhatsApp.
 */
function notifyAdmin(string $fsToken, string $subject, string $message): void
{
    notifyAdminByEmail($fsToken, $subject, $message);
    notifyAdminByWhatsApp($fsToken, $message);
}

function notifyAdminByEmail(string $fsToken, string $subject, string $message): void
{
    try {
        $config = fsGetDoc($fsToken, 'config/email');
        if (!$config || empty($config['adminEmail'])) return; // no configurado, no hacemos nada

        $to = $config['adminEmail'];
        $from = $config['fromEmail'] ?? 'no-reply@tumerchmo.com';
        $headers = "From: F3RN4N <{$from}>\r\n";
        $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";

        $ok = @mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $message, $headers);
        if (!$ok) {
            error_log('notifyAdminByEmail: mail() regresó false -- revisa que tu hosting tenga correo saliente configurado.');
        }
    } catch (\Throwable $e) {
        error_log('notifyAdminByEmail error: ' . $e->getMessage());
    }
}

function notifyAdminByWhatsApp(string $fsToken, string $message): void
{
    try {
        $config = fsGetDoc($fsToken, 'config/whatsapp');
        if (!$config || empty($config['accessToken']) || empty($config['phoneNumberId']) || empty($config['adminPhoneNumber'])) {
            return; // no configurado, no hacemos nada
        }
        if (!function_exists('curl_init')) return;

        $templateName = $config['templateName'] ?: 'admin_notificacion';
        $lang = $config['templateLanguage'] ?: 'es_MX';

        $body = [
            'messaging_product' => 'whatsapp',
            'to' => $config['adminPhoneNumber'],
            'type' => 'template',
            'template' => [
                'name' => $templateName,
                'language' => ['code' => $lang],
                'components' => [[
                    'type' => 'body',
                    'parameters' => [['type' => 'text', 'text' => $message]],
                ]],
            ],
        ];

        $ch = curl_init("https://graph.facebook.com/v20.0/{$config['phoneNumberId']}/messages");
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($body),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $config['accessToken'],
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
        ]);
        $raw = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode < 200 || $httpCode >= 300) {
            error_log('notifyAdminByWhatsApp: Meta regresó ' . $httpCode . ' -- ' . $raw);
        }
    } catch (\Throwable $e) {
        error_log('notifyAdminByWhatsApp error: ' . $e->getMessage());
    }
}
