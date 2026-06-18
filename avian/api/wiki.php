<?php
// AvianVisitors - Wikipedia summary proxy.
//
// The detail modal calls /avian/api/wiki.php?sci=<name> for the species
// description. We hit Wikipedia's REST summary endpoint and return the
// extract + thumbnail. Cached at the browser + Caddy edge for 24 h
// because species descriptions don't really change.
//
// Description language is DUTCH: we try nl.wikipedia.org first (natively
// written Dutch prose, not a machine translation) and fall back to the
// English article for the handful of species without a Dutch page. eBird
// itself has no public species-description API - its pages render the
// paywalled Birds of the World - so Dutch Wikipedia is the native-Dutch
// source. (Bird *names* come from the eBird taxonomy; see NAMES_NL.)
//
// Pinned to wikimedia.org / wikipedia.org for the photo URL to block
// SSRF if Wikipedia ever returns a poisoned redirect target.

declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=86400');

$sci = trim((string)($_GET['sci'] ?? ''));
if ($sci === '') {
    http_response_code(400);
    echo json_encode(['error' => 'sci required']);
    exit;
}
if (!preg_match('/^[A-Za-z]{2,40}(?:[ ][a-z]{2,40}){1,3}$/', $sci)) {
    http_response_code(400);
    echo json_encode(['error' => 'invalid sci']);
    exit;
}

$ua = getenv('AV_USER_AGENT') ?: 'AvianVisitors/1.0 (+https://github.com/Twarner491/AvianVisitors)';
$ctx = stream_context_create([
    'http' => ['header' => "User-Agent: $ua\r\n", 'timeout' => 8],
]);

// Fetch a Wikipedia REST summary for $sci from a given language host.
// Returns the decoded array on success, or null (network failure, non-JSON,
// or a "not found" stub with no extract) so the caller can fall back.
function wiki_summary(string $host, string $sci, $ctx): ?array {
    $url = 'https://' . $host . '/api/rest_v1/page/summary/' . rawurlencode($sci);
    $raw = @file_get_contents($url, false, $ctx);
    if ($raw === false) return null;
    $j = json_decode($raw, true);
    if (!is_array($j)) return null;
    // REST returns type=https://...#/definitions/not_found (and no extract)
    // for missing pages; treat an empty extract as a miss so en/ can answer.
    if (empty($j['extract'])) return null;
    return $j;
}

// Dutch first, English as the fallback for species without a Dutch article.
$lang = 'nl';
$j = wiki_summary('nl.wikipedia.org', $sci, $ctx);
if ($j === null) {
    $lang = 'en';
    $j = wiki_summary('en.wikipedia.org', $sci, $ctx);
}
if ($j === null) {
    echo json_encode(['extract' => null, 'thumbnail' => null, 'lang' => null]);
    exit;
}

$thumb = $j['thumbnail']['source'] ?? null;
if ($thumb) {
    $host = parse_url((string)$thumb, PHP_URL_HOST) ?: '';
    if (!preg_match('/(?:^|\.)(?:wikimedia\.org|wikipedia\.org)$/i', $host)) {
        $thumb = null;
    }
}

echo json_encode([
    'extract'   => $j['extract'] ?? null,
    'thumbnail' => $thumb ? ['source' => $thumb] : null,
    'title'     => $j['title'] ?? null,
    'lang'      => $lang,
]);
