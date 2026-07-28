<?php
// AvianVisitors - Vogelkans: which species are likely at a place and week.
//
//   GET ?lat=52.09&lon=5.12&week=31&threshold=0.01
//     -> {"species":[{sci,nl,group,family,p,art,heard,code}...],
//         "counts":{total,art,heard}, "groups":[...], lat, lon, week, ...}
//
// The scores come from BirdNET's species range model, run via
// avian/scripts/vogelkans.py. That is the same model that decides which
// species the analyzer will even attempt to detect (SF_THRESH), so this panel
// doubles as a view of what the box is currently listening for.
//
// Deliberately NOT an action on birdnet-api.php: that file materialises the
// `moments` temp table (a window function over the whole detections table) on
// every request, which this endpoint has no use for, and its cache signature
// has no lat/lon/week so entries would collide.
//
// Gated behind Caddy basic_auth (both tiers) - lat/lon default to the unit's
// own configured position, so an ungated version would leak where it is.

declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (getenv('AV_REQUIRE_AUTH') === '1' && empty($_SERVER['HTTP_AUTHORIZATION'])) {
    http_response_code(401);
    echo json_encode(['error' => 'unauthorized']);
    exit;
}

// __DIR__ resolves through the ${EXTRACTED}/avian symlink to the realpath, so
// dirname(..., 2) is the BirdNET-Pi install root under any username. See the
// same note in birdnet-api.php - getenv('HOME') is /var/lib/caddy here.
$ROOT       = dirname(__DIR__, 2);
$DB_PATH    = $ROOT . '/scripts/birds.db';
$SCRIPT     = $ROOT . '/avian/scripts/vogelkans.py';
$TAXONOMY   = $ROOT . '/avian/data/taxonomy.json';
$NAMES_NL   = $ROOT . '/model/l18n/labels_nl.json';
$ILLUS_DIR  = $ROOT . '/avian/assets/illustrations';

const KANS_WEEKS      = 48;     // BirdNET's year is 48 weeks, not 52. See vogelkans.py.
const KANS_GRID       = 0.25;   // deg; rounding to this loses ~4% of the species set
const KANS_MODEL_TTL  = 2592000; // 30 d - the model is deterministic, only its file changes
const KANS_DEFAULT_TH = 0.01;

// ---- birdnet.conf reader (mirrors birdnet-api.php's) ----
function kans_conf(string $key, string $default): string {
    static $conf = null;
    if ($conf === null) {
        $conf = [];
        $dir = dirname(__DIR__, 2);
        foreach (['/etc/birdnet/birdnet.conf', $dir . '/birdnet.conf'] as $p) {
            if (!is_readable($p)) continue;
            foreach (file($p, FILE_IGNORE_NEW_LINES) as $line) {
                if ($line === '' || $line[0] === '#') continue;
                if (preg_match('/^\s*([A-Z_][A-Z0-9_]*)\s*=\s*(.*)$/i', $line, $m)) {
                    $val = trim($m[2]);
                    if (strlen($val) >= 2 && $val[0] === '"' && substr($val, -1) === '"') {
                        $val = substr($val, 1, -1);
                    }
                    $conf[$m[1]] = $val;
                }
            }
            break;
        }
    }
    return array_key_exists($key, $conf) ? $conf[$key] : $default;
}

function kans_fail(int $code, string $msg): void {
    http_response_code($code);
    echo json_encode(['error' => $msg]);
    exit;
}

/** Calendar date -> BirdNET's 1..48 week (4 per month). Never an ISO week. */
function kans_week_now(): int {
    $m = (int)date('n');
    $d = (int)date('j');
    return ($m - 1) * 4 + min(3, intdiv($d - 1, 7)) + 1;
}

// ---- Parameters ----
$lat = isset($_GET['lat']) && is_numeric($_GET['lat'])
     ? (float)$_GET['lat'] : (float)kans_conf('LATITUDE', '52');
$lon = isset($_GET['lon']) && is_numeric($_GET['lon'])
     ? (float)$_GET['lon'] : (float)kans_conf('LONGITUDE', '5');
if ($lat < -90 || $lat > 90 || $lon < -180 || $lon > 180) {
    kans_fail(400, 'lat/lon out of range');
}

$week = isset($_GET['week']) && is_numeric($_GET['week'])
      ? (int)$_GET['week'] : kans_week_now();
$week = max(1, min(KANS_WEEKS, $week));

$threshold = isset($_GET['threshold']) && is_numeric($_GET['threshold'])
           ? (float)$_GET['threshold'] : KANS_DEFAULT_TH;
$threshold = max(0.001, min(1.0, $threshold));

// Snap to the cache grid. Measured: rounding lat/lon to 0.25 deg keeps a
// Jaccard of ~0.96 against the exact point, so this costs essentially nothing
// and turns "every pin drag" into "one run per 28 km cell".
$gLat = round($lat / KANS_GRID) * KANS_GRID;
$gLon = round($lon / KANS_GRID) * KANS_GRID;

// ---- Layer 1: the model run (expensive, ~1-2 s cold on a Pi 3B+) ----
$modelSig  = sprintf('%.2f|%.2f|%d|%.4f|%s', $gLat, $gLon, $week, $threshold,
                     (string)@filemtime($SCRIPT));
$cacheRoot = sys_get_temp_dir() . '/avian-kans-cache';
$modelFile = $cacheRoot . '/m-' . md5($modelSig) . '.json';
if (!is_dir($cacheRoot)) @mkdir($cacheRoot, 0700, true);

$scored = null;
if (is_file($modelFile) && (time() - (int)@filemtime($modelFile)) < KANS_MODEL_TTL) {
    $hit = json_decode((string)@file_get_contents($modelFile), true);
    if (is_array($hit) && isset($hit['species'])) $scored = $hit['species'];
}

if ($scored === null) {
    if (!is_file($SCRIPT)) kans_fail(500, 'vogelkans.py not found');

    // The venv the Pi installs (scripts/install_services.sh exports this same
    // path as PYTHON_VIRTUAL_ENV). No sudo: model/ is group-readable, so
    // php-fpm's caddy user can run this directly.
    $candidates = array_filter([
        kans_conf('AV_PYTHON', ''),
        $ROOT . '/birdnet/bin/python3',
        '/usr/bin/python3',
    ]);
    $python = null;
    foreach ($candidates as $c) {
        if (is_file($c) && is_executable($c)) { $python = $c; break; }
    }
    if ($python === null) kans_fail(500, 'no python interpreter found');

    // %.6F (not %f) so the decimal separator is always a dot, whatever the
    // locale php-fpm happens to be running under.
    $cmd = sprintf(
        '%s %s --lat %s --lon %s --week %s --threshold %s --json 2>/dev/null',
        escapeshellarg($python),
        escapeshellarg($SCRIPT),
        escapeshellarg(sprintf('%.6F', $gLat)),
        escapeshellarg(sprintf('%.6F', $gLon)),
        escapeshellarg((string)$week),
        escapeshellarg(sprintf('%.6F', $threshold))
    );
    $out = [];
    $rc  = 0;
    @exec($cmd, $out, $rc);
    $parsed = json_decode(implode('', $out), true);
    if ($rc !== 0 || !is_array($parsed) || !isset($parsed['species'])) {
        kans_fail(500, 'range model failed');
    }
    $scored = $parsed['species'];

    // Atomic install, with the same key-space bound + GC as birdnet-api.php.
    $entries = @scandir($cacheRoot);
    if (is_array($entries) && count($entries) < 500) {
        $tmp = $modelFile . '.tmp.' . getmypid();
        if (@file_put_contents($tmp, json_encode(['species' => $scored])) !== false) {
            @rename($tmp, $modelFile);
        }
    }
    if (mt_rand(0, 49) === 0 && is_array($entries)) {
        foreach ($entries as $f) {
            if (substr($f, -5) !== '.json') continue;
            $p = $cacheRoot . '/' . $f;
            if (time() - (int)@filemtime($p) > KANS_MODEL_TTL) @unlink($p);
        }
    }
}

// ---- Enrichment ----
// Deliberately NOT disk-cached. The expensive part (the model run) already is,
// and everything below is a few ms: two json_decodes, one scandir, one
// DISTINCT. Caching it would have to key on birds.db's mtime, which changes on
// every detection - that would mint a fresh cache file every few minutes,
// blow through the entry bound above within a day, and then starve the model
// cache that actually matters.
$tax = is_readable($TAXONOMY)
     ? json_decode((string)file_get_contents($TAXONOMY), true) : null;
if (!is_array($tax) || !isset($tax['sp'])) {
    kans_fail(500, 'taxonomy.json missing - run avian/scripts/fetch_taxonomy.py');
}
$groups   = $tax['groups']   ?? [];
$families = $tax['families'] ?? [];
$spTax    = $tax['sp'];
// array_search returns false for "not found", and (int)false is 0 - which
// would silently file every unknown species under whatever group happens to
// be first. Compare strictly.
$fallbackGroup = array_search('Overig', $groups, true);
if ($fallbackGroup === false) {
    $groups[] = 'Overig';
    $fallbackGroup = count($groups) - 1;
}

$namesNl = is_readable($NAMES_NL)
         ? json_decode((string)file_get_contents($NAMES_NL), true) : [];
if (!is_array($namesNl)) $namesNl = [];

// Which species we ship artwork for. One scandir beats a stat() per row; the
// slug rule mirrors cutout.php's, and the -2 flight pose collapses onto it.
$art = [];
foreach ((@scandir($ILLUS_DIR) ?: []) as $f) {
    if (substr($f, -4) !== '.png') continue;
    $art[preg_replace('/-2$/', '', substr($f, 0, -4))] = true;
}

// Which species this unit has ever heard. Cheap DISTINCT - no moments table
// needed, we only want presence. Hidden (moderated-away) detections don't
// count, matching every other surface.
$heard = [];
if (is_file($DB_PATH)) {
    try {
        $db = new SQLite3($DB_PATH, SQLITE3_OPEN_READONLY);
        $db->busyTimeout(2000);
        $hasHidden = (bool)$db->querySingle(
            "SELECT name FROM sqlite_master WHERE type='table' AND name='av_hidden_detections'");
        $sql = 'SELECT DISTINCT Sci_Name FROM detections'
             . ($hasHidden ? ' WHERE rowid NOT IN (SELECT det_rowid FROM av_hidden_detections)' : '');
        $res = $db->query($sql);
        while ($res && ($row = $res->fetchArray(SQLITE3_ASSOC))) {
            $heard[$row['Sci_Name']] = true;
        }
        $db->close();
    } catch (Throwable $e) {
        // A missing or locked DB just means we can't mark "ooit gehoord".
    }
}

$species = [];
$nArt = $nHeard = 0;
foreach ($scored as $row) {
    if (!is_array($row) || count($row) < 2) continue;
    $sci = (string)$row[0];
    $p   = (float)$row[1];
    $t   = $spTax[$sci] ?? null;
    $slug = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($sci)), '-');
    $hasArt   = isset($art[$slug]);
    $hasHeard = isset($heard[$sci]);
    if ($hasArt) $nArt++;
    if ($hasHeard) $nHeard++;
    $species[] = [
        'sci'    => $sci,
        'nl'     => $namesNl[$sci] ?? null,
        'group'  => $groups[$t[0] ?? $fallbackGroup] ?? 'Overig',
        'family' => $t ? ($families[$t[1]] ?? '') : '',
        'code'   => $t[3] ?? null,
        'p'      => round($p, 4),
        'art'    => $hasArt,
        'heard'  => $hasHeard,
    ];
}

echo json_encode([
    'species' => $species,
    'groups'  => $groups,
    'counts'  => ['total' => count($species), 'art' => $nArt, 'heard' => $nHeard],
    'lat'     => $lat,
    'lon'     => $lon,
    'grid'    => ['lat' => $gLat, 'lon' => $gLon],
    'week'    => $week,
    'weeks'   => KANS_WEEKS,
    'threshold' => $threshold,
    'as_of'   => date('c'),
]);
