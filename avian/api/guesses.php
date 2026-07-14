<?php
// AvianVisitors - live "what is the algo guessing" feed for the admin
// overlay (#admin=live). Reads the JSONL files that scripts/utils/guesses.py
// writes for every analyzed time slot (including guesses that never pass
// CONFIDENCE), so the frontend gets structured rows instead of journalctl
// text that jumps around.
//
// Endpoints (?op=...):
//   live    - &n=120 &since=<ISO ts>: newest slot records, newest FIRST.
//             `since` returns only records strictly newer than that cursor,
//             which is what lets the frontend append instead of re-render.
//   species - &hours=24: per-species aggregate over the window (count, best
//             confidence, last heard, confident count), best first.
//
// Default LAN deploy: returns data immediately, no auth.
// Forwarded deploy:  AV_REQUIRE_AUTH=1 + Caddy basic_auth on /avian/api/.

declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (getenv('AV_REQUIRE_AUTH') === '1' && empty($_SERVER['HTTP_AUTHORIZATION'])) {
    http_response_code(401);
    echo json_encode(['error' => 'unauthorized']);
    exit;
}

// Path layout mirrors birdnet-status.php: this file lives at
// /home/{USER}/BirdNET-Pi/avian/api/, BirdSongs sits next to BirdNET-Pi.
$GUESS_DIR = dirname(__DIR__, 3) . '/BirdSongs/guesses';
$LOCAL_CONF_PATH  = dirname(__DIR__, 2) . '/birdnet.conf';
$SYSTEM_CONF_PATH = '/etc/birdnet/birdnet.conf';
$CONF_PATH = is_readable($SYSTEM_CONF_PATH) ? $SYSTEM_CONF_PATH : $LOCAL_CONF_PATH;

function conf_value(string $path, string $key, string $fallback): string {
    $raw = @file_get_contents($path);
    if ($raw !== false && preg_match('/^' . preg_quote($key, '/') . '=(.*)$/m', $raw, $m)) {
        return trim($m[1], " \t\"'");
    }
    return $fallback;
}

function day_file(string $dir, int $days_ago): string {
    return $dir . '/guesses-' . date('Y-m-d', time() - $days_ago * 86400) . '.jsonl';
}

// Read the last $n lines of a file without loading the whole day into
// memory (a full day is a few MB; the Pi serves this every few seconds).
function tail_lines(string $path, int $n): array {
    $f = @fopen($path, 'r');
    if (!$f) return [];
    $buf = '';
    $chunk = 8192;
    fseek($f, 0, SEEK_END);
    $pos = ftell($f);
    while ($pos > 0 && substr_count($buf, "\n") <= $n) {
        $read = min($chunk, $pos);
        $pos -= $read;
        fseek($f, $pos);
        $buf = fread($f, $read) . $buf;
    }
    fclose($f);
    $lines = explode("\n", trim($buf));
    return array_slice($lines, -$n);
}

function decode_lines(array $lines): array {
    $out = [];
    foreach ($lines as $line) {
        $rec = json_decode($line, true);
        if (is_array($rec) && isset($rec['t'], $rec['top'])) $out[] = $rec;
    }
    return $out;
}

$op = $_GET['op'] ?? 'live';

if ($op === 'live') {
    $n = max(10, min(500, (int)($_GET['n'] ?? 120)));
    $since = (string)($_GET['since'] ?? '');
    // Today's tail; top up from yesterday just after midnight so the
    // feed is never near-empty at 00:05.
    $recs = decode_lines(tail_lines(day_file($GUESS_DIR, 0), $n));
    if (count($recs) < $n && $since === '') {
        $extra = decode_lines(tail_lines(day_file($GUESS_DIR, 1), $n - count($recs)));
        $recs = array_merge($extra, $recs);
    }
    if ($since !== '') {
        $recs = array_values(array_filter($recs, function ($r) use ($since) {
            return strcmp($r['t'], $since) > 0;
        }));
    }
    // newest first for the feed
    usort($recs, function ($a, $b) { return strcmp($b['t'], $a['t']); });
    echo json_encode([
        'slots' => array_slice($recs, 0, $n),
        'confidence' => (float)conf_value($CONF_PATH, 'CONFIDENCE', '0.7'),
        'now' => date('c'),
    ]);
    exit;
}

if ($op === 'species') {
    $hours = max(1, min(72, (int)($_GET['hours'] ?? 24)));
    $cutoff = date('Y-m-d\TH:i:s', time() - $hours * 3600);
    $agg = [];
    // The window spans at most today + enough previous days.
    for ($d = 0; $d <= (int)ceil($hours / 24); $d++) {
        $f = @fopen(day_file($GUESS_DIR, $d), 'r');
        if (!$f) continue;
        while (($line = fgets($f)) !== false) {
            $rec = json_decode($line, true);
            if (!is_array($rec) || !isset($rec['t'], $rec['top'][0])) continue;
            if (strcmp($rec['t'], $cutoff) < 0) continue;
            // quiet slots are near-silence noise-matches; they'd flood the
            // leaderboard with 2% junk species (mockserver skips them too)
            if (($rec['status'] ?? '') === 'quiet') continue;
            $top = $rec['top'][0];
            $sci = $top['sci'] ?? '';
            if ($sci === '' || $sci === 'Human_Human') continue;
            if (!isset($agg[$sci])) {
                $agg[$sci] = ['sci' => $sci, 'com' => $top['com'] ?? $sci,
                              'n' => 0, 'best' => 0.0, 'last' => '', 'n_confident' => 0];
            }
            $agg[$sci]['n']++;
            $agg[$sci]['best'] = max($agg[$sci]['best'], (float)($top['conf'] ?? 0));
            if (strcmp($rec['t'], $agg[$sci]['last']) > 0) $agg[$sci]['last'] = $rec['t'];
            if (($rec['status'] ?? '') === 'confident') $agg[$sci]['n_confident']++;
        }
        fclose($f);
    }
    $out = array_values($agg);
    usort($out, function ($a, $b) { return $b['best'] <=> $a['best']; });
    echo json_encode(['species' => $out, 'hours' => $hours, 'now' => date('c')]);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'unknown op']);
