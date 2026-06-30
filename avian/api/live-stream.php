<?php
// AvianVisitors - no-popup live audio proxy.
//
// The raw /stream route is protected by Caddy basic_auth, which can trigger
// the browser's native login dialog. This endpoint validates the app's stored
// Authorization header itself and proxies Icecast without ever emitting a
// WWW-Authenticate challenge.

declare(strict_types=1);

set_time_limit(0);
ignore_user_abort(true);

$BIRDNETPI_DIR = dirname(__DIR__, 2);
$LOCAL_CONF_PATH = "$BIRDNETPI_DIR/birdnet.conf";
$SYSTEM_CONF_PATH = '/etc/birdnet/birdnet.conf';
$CONF_PATH = is_readable($SYSTEM_CONF_PATH) ? $SYSTEM_CONF_PATH : $LOCAL_CONF_PATH;

function live_conf(string $path): array {
    if (!is_readable($path)) return [];
    $source = preg_replace("~^#+.*$~m", "", (string)file_get_contents($path));
    $parsed = parse_ini_string((string)$source);
    return is_array($parsed) ? $parsed : [];
}

function live_auth_header(): string {
    return (string)($_SERVER['HTTP_X_AVIAN_AUTH'] ?? $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
}

function live_authorized(array $conf): bool {
    if (isset($_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW'])) {
        return $_SERVER['PHP_AUTH_USER'] === 'pensionado'
            && isset($conf['LIVE_PWD'])
            && hash_equals((string)$conf['LIVE_PWD'], (string)$_SERVER['PHP_AUTH_PW']);
    }
    $hdr = live_auth_header();
    if (!preg_match('/^Basic\s+(.+)$/i', $hdr, $m)) return false;
    $raw = base64_decode($m[1], true);
    if ($raw === false || strpos($raw, ':') === false) return false;
    [$user, $pass] = explode(':', $raw, 2);
    return $user === 'pensionado'
        && isset($conf['LIVE_PWD'])
        && hash_equals((string)$conf['LIVE_PWD'], $pass);
}

$conf = live_conf($CONF_PATH);
if (!live_authorized($conf)) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode(['error' => 'live audio requires pensionado access']);
    exit;
}

$stream = @fopen('http://127.0.0.1:8000/stream', 'rb');
if ($stream === false) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    echo 'live stream unavailable';
    exit;
}

header('Content-Type: audio/mpeg');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('X-Accel-Buffering: no');

while (!feof($stream) && connection_status() === CONNECTION_NORMAL) {
    $chunk = fread($stream, 16384);
    if ($chunk === false) break;
    echo $chunk;
    flush();
}
fclose($stream);
