<?php
/**
 * Zoneary contact endpoint.
 *
 *   POST /api/contact.php   ->   authenticated SMTP   ->   info@zoneary.com
 *
 * The browser posts here; this file authenticates to Hostinger's SMTP server
 * and sends the message. The SMTP credential is read from a file above the web
 * root and never reaches the client in any form - not in a response body, not
 * in an error message, not in a header. See docs/contact-form.md.
 */

declare(strict_types=1);

define('ZONEARY_CONTACT', true);

// Errors are for the server's log, never for the visitor's screen: a warning
// rendered into the response could disclose a path or a configuration value.
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

require __DIR__ . '/lib/config.php';
require __DIR__ . '/lib/validate.php';
require __DIR__ . '/lib/message.php';
require __DIR__ . '/lib/smtp.php';
require __DIR__ . '/lib/rate_limit.php';
require __DIR__ . '/lib/handler.php';

/** Largest request body we will read, in bytes. */
const MAX_BODY_BYTES = 16384;

/** Does the caller want JSON back, or is this a plain form post without JS? */
$wantsJson = strpos((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json') !== false
    || strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'fetch';

/**
 * @param array<string,mixed> $payload
 */
function contact_respond(int $status, array $payload, bool $wantsJson): void
{
    http_response_code($status);
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');

    if (isset($payload['retry_after'])) {
        header('Retry-After: ' . (int) $payload['retry_after']);
    }

    if ($wantsJson) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    // No-JavaScript fallback: a plain page rather than raw JSON on screen.
    $ok      = !empty($payload['ok']);
    $heading = $ok ? 'Message received' : 'Not sent';
    $text    = (string) ($payload['message'] ?? $payload['error'] ?? '');

    header('Content-Type: text/html; charset=utf-8');
    echo "<!DOCTYPE html>\n<html lang=\"en\"><head><meta charset=\"utf-8\">"
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<meta name="robots" content="noindex">'
        . '<title>' . htmlspecialchars($heading, ENT_QUOTES) . ' | Zoneary</title>'
        . '<style>body{background:#080a11;color:#e8ecf4;font:16px/1.6 system-ui,sans-serif;'
        . 'display:flex;min-height:100vh;margin:0;align-items:center;justify-content:center;padding:24px}'
        . 'div{max-width:520px;text-align:center}h1{font-size:22px;margin:0 0 12px}'
        . 'a{color:#00d4ff}</style></head><body><div><h1>'
        . htmlspecialchars($heading, ENT_QUOTES) . '</h1><p>'
        . htmlspecialchars($text, ENT_QUOTES) . '</p>'
        . '<p><a href="../contact/">Back to contact</a></p></div></body></html>';
    exit;
}

// A fatal error must not leak a trace into the response body.
set_exception_handler(static function (Throwable $e) use ($wantsJson): void {
    error_log('[contact] unhandled: ' . $e->getMessage());
    contact_respond(500, ['ok' => false, 'error' => ContactHandler::GENERIC_FAILURE], $wantsJson);
});

// ---------------------------------------------------------------- method ----
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($method === 'OPTIONS') {
    header('Allow: POST');
    contact_respond(204, ['ok' => true], $wantsJson);
}
if ($method !== 'POST') {
    header('Allow: POST');
    contact_respond(405, ['ok' => false, 'error' => 'This endpoint only accepts form submissions.'], $wantsJson);
}

// ---------------------------------------------------------------- origin ----
// Only checked when the browser sends it. A same-origin form post from our own
// pages always matches; a cross-site page posting into this endpoint does not.
$origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
if ($origin !== '') {
    $originHost = strtolower((string) parse_url($origin, PHP_URL_HOST));
    $selfHost   = strtolower((string) preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? '')));
    $allowed    = array_filter([$selfHost, 'zoneary.com', 'www.zoneary.com']);
    if ($originHost !== '' && !in_array($originHost, $allowed, true)) {
        contact_respond(403, ['ok' => false, 'error' => 'That submission could not be read.'], $wantsJson);
    }
}

// ------------------------------------------------------------------ size ----
$declared = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
if ($declared > MAX_BODY_BYTES) {
    contact_respond(413, ['ok' => false, 'error' => 'That message is longer than we can accept.'], $wantsJson);
}

// ------------------------------------------------------------------ body ----
$contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
$body = [];

if (strpos($contentType, 'application/json') !== false) {
    $raw = (string) file_get_contents('php://input', false, null, 0, MAX_BODY_BYTES + 1);
    if (strlen($raw) > MAX_BODY_BYTES) {
        contact_respond(413, ['ok' => false, 'error' => 'That message is longer than we can accept.'], $wantsJson);
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        contact_respond(400, ['ok' => false, 'error' => 'That submission could not be read.'], $wantsJson);
    }
    $body = $decoded;
} elseif (
    strpos($contentType, 'application/x-www-form-urlencoded') !== false
    || strpos($contentType, 'multipart/form-data') !== false
) {
    $body = $_POST;
} else {
    contact_respond(415, ['ok' => false, 'error' => 'That submission could not be read.'], $wantsJson);
}

// json_decode can produce nested structures; the validator only accepts strings,
// but flatten obvious scalars first so a numeric field is not a hard rejection.
foreach ($body as $key => $value) {
    if (is_int($value) || is_float($value) || is_bool($value)) {
        $body[$key] = (string) $value;
    }
}

// ----------------------------------------------------------------- serve ----
try {
    $config = ContactConfig::load(__DIR__);
} catch (Throwable $e) {
    // Misconfiguration on our side, not the visitor's problem to interpret.
    error_log('[contact] configuration unavailable: ' . $e->getMessage());
    contact_respond(500, ['ok' => false, 'error' => ContactHandler::GENERIC_FAILURE], $wantsJson);
}

$handler = new ContactHandler(
    $config,
    new SmtpTransport($config),
    new ContactRateLimit($config->stateDir())
);

$result = $handler->handle($body, $_SERVER);

if (isset($result['log'])) {
    error_log('[contact] ' . (string) $result['log']);
}

contact_respond((int) $result['status'], $result['payload'], $wantsJson);
