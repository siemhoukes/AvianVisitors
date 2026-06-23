<?php
// AvianVisitors - read/set what the GeekMagic SmallTV collage shows.
//   GET  -> {"window":"24h"}                  (open; just reflects the setting)
//   POST {"window":"8h|24h|7d|location"}      (gate behind Caddy basic_auth)
// The value is stored in birdnet.conf as AV_SMALLTV_WINDOW; smalltv_push.py
// re-reads it each loop, so a change takes effect within one push cycle.
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

$CONF = '/etc/birdnet/birdnet.conf';
$ALLOWED = ['8h', '24h', '7d', 'location'];

function current_window(string $conf): string {
    $s = @file_get_contents($conf);
    if ($s !== false && preg_match('/^AV_SMALLTV_WINDOW=(.*)$/m', $s, $m)) {
        return trim($m[1], " \"'");
    }
    return '24h';
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    echo json_encode(['window' => current_window($CONF)]);
    exit;
}

$body = json_decode((string)file_get_contents('php://input'), true);
$w = is_array($body) ? ($body['window'] ?? '') : '';
if (!in_array($w, $ALLOWED, true)) {
    http_response_code(400);
    echo json_encode(['error' => 'bad window']);
    exit;
}

$s = @file_get_contents($CONF);
if ($s === false) { http_response_code(500); echo json_encode(['error' => 'conf read']); exit; }
if (preg_match('/^AV_SMALLTV_WINDOW=/m', $s)) {
    $s = preg_replace('/^AV_SMALLTV_WINDOW=.*$/m', 'AV_SMALLTV_WINDOW=' . $w, $s, 1);
} else {
    $s = rtrim($s, "\n") . "\nAV_SMALLTV_WINDOW=" . $w . "\n";
}
// conf is read-only to the web user; stage + copy with its passwordless sudo
// (preserves the symlink + owner), like the location editor does.
$tmp = '/tmp/avian_tvwin_' . getmypid();
if (@file_put_contents($tmp, $s) === false) { http_response_code(500); echo json_encode(['error' => 'tmp']); exit; }
@exec('sudo /bin/cp ' . escapeshellarg($tmp) . ' ' . escapeshellarg($CONF) . ' 2>/dev/null');
@unlink($tmp);
echo json_encode(['ok' => true, 'window' => $w]);
