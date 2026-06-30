<?php
// AvianVisitors - place-name search for the reisschema, proxying OpenStreetMap
// Nominatim server-side (keeps a proper User-Agent + avoids browser CORS).
//   GET ?q=<place> -> {"results":[{"label","lat","lon"}...]}
// Gated behind Caddy basic_auth (both tiers) - it's only used by the logged-in
// schedule editor. Nominatim usage policy: <=1 req/s, identify via User-Agent.

declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$q = trim((string)($_GET['q'] ?? ''));
if ($q === '' || mb_strlen($q) > 120) {
    echo json_encode(['results' => []]);
    exit;
}

$ua = getenv('AV_USER_AGENT') ?: 'AvianVisitors/1.0 (+https://github.com/siemhoukes/AvianVisitors)';
$url = 'https://nominatim.openstreetmap.org/search?format=jsonv2&limit=5&accept-language=nl&q='
     . rawurlencode($q);
$ctx = stream_context_create(['http' => [
    'header'  => "User-Agent: $ua\r\n",
    'timeout' => 10,
]]);
$raw = @file_get_contents($url, false, $ctx);
if ($raw === false) {
    http_response_code(502);
    echo json_encode(['results' => [], 'error' => 'geocoder unreachable']);
    exit;
}
$list = json_decode($raw, true);
$out = [];
if (is_array($list)) {
    foreach ($list as $r) {
        if (!is_array($r) || !isset($r['lat'], $r['lon'])) continue;
        $lat = (float)$r['lat'];
        $lon = (float)$r['lon'];
        if ($lat < -90 || $lat > 90 || $lon < -180 || $lon > 180) continue;
        // Shorten the long Nominatim display_name to the first 3 comma parts
        // (e.g. "Porto, Portugal" instead of the full administrative chain).
        $name = (string)($r['display_name'] ?? $r['name'] ?? '');
        $parts = array_map('trim', explode(',', $name));
        $short = implode(', ', array_slice(array_filter($parts), 0, 3));
        $out[] = ['label' => $short !== '' ? $short : $name, 'lat' => $lat, 'lon' => $lon];
    }
}
echo json_encode(['results' => $out]);
