<?php
// AvianVisitors - JSON facade over BirdNET-Pi's birds.db. Read-only.
// Symlinked into the BirdNET-Pi Caddy site root at /avian/api/.
//
// Endpoints (?action=...):
//   stats       - totals (detections, unique species, today, last hour)
//   lifelist    - every species with first_seen, last_seen, total_count
//   recent      - &hours=N (default 24): species heard in the window
//   species     - &sci=<sci_name>: per-species detail page
//   timeseries  - &days=N: daily detection counts per species
//   firstseen   - every species' earliest detection
//
// Detection *counts* everywhere are "moments", not raw rows: a species'
// consecutive detections within a silence gap collapse into one episode, so a
// bird singing non-stop (worse with OVERLAP on) no longer buries the one-off
// visitors. Toggle it and set the gap from the admin settings panel
// (AV_GROUP_ENABLED / AV_GROUP_GAP_SEC in birdnet.conf); see the block below.
//
// Default LAN deploy ships without auth. If you've exposed the Pi via
// Cloudflare or a tunnel, add a Caddy `basic_auth` matcher around the
// /avian/api/* path - see avian/forwarding/.

declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=30');

// PHP resolves __DIR__ through symlinks to the realpath. This script
// lives at $HOME/BirdNET-Pi/avian/api/birdnet-api.php (served via the
// ${EXTRACTED}/avian symlink). dirname(..., 2) walks to the BirdNET-Pi
// install root. Works under any username because we never bake the
// home directory in. getenv('HOME') would resolve to /var/lib/caddy
// under PHP-FPM (BirdNET-Pi runs it as the caddy user), so it can't
// be relied on.
$DB_PATH = dirname(__DIR__, 2) . '/scripts/birds.db';

if (!file_exists($DB_PATH)) {
    http_response_code(503);
    echo json_encode(['error' => 'birds.db not found']);
    exit;
}

$action = $_GET['action'] ?? 'stats';

// ---- Response cache ----
// The collage frontend polls five actions every 30 s per open tab, and every
// request below materialises the "moments" temp table with a window function
// over the whole detections table - noticeable CPU on a Pi 3B+, multiplied by
// each viewer on a public deploy. Cache the JSON on disk, keyed on the action,
// its parameters, and the mtimes of birds.db + birdnet.conf: a new detection
// or settings change invalidates instantly, and identical polls (or extra
// viewers) in between are served without touching SQLite. The short TTL bounds
// the drift of 'now'-relative windows (e.g. the last-hour stat) while the DB
// is quiet. Set AV_API_CACHE_SEC=0 in birdnet.conf to disable.
$AV_CACHEABLE = ['stats', 'lifelist', 'recent', 'species', 'timeseries', 'firstseen', 'locations', 'journey'];
$avCacheTtl = max(0, min(3600, (int)av_conf_value('AV_API_CACHE_SEC', '120')));
$avCacheFile = null;
if ($avCacheTtl > 0 && in_array($action, $AV_CACHEABLE, true)) {
    $sig = [$action];
    // 'location' (the "deze plek" filter) must be in the key: without it, a
    // ?location=1 request and a plain ?hours=24 request (both otherwise
    // param-less) would collide on the same cache entry and serve each
    // other's data.
    foreach (['hours', 'from', 'to', 'sci', 'days', 'limit', 'location'] as $p) $sig[] = (string)($_GET[$p] ?? '');
    $sig[] = (string)@filemtime($DB_PATH);
    foreach (['/etc/birdnet/birdnet.conf', dirname(__DIR__, 2) . '/birdnet.conf'] as $cp) {
        $sig[] = (string)@filemtime($cp);
    }
    $avCacheDir = sys_get_temp_dir() . '/avian-api-cache';
    if (!is_dir($avCacheDir)) @mkdir($avCacheDir, 0700, true);
    $avCacheFile = $avCacheDir . '/' . md5(implode("\x1f", $sig)) . '.json';
    if (is_file($avCacheFile) && (time() - (int)@filemtime($avCacheFile)) < $avCacheTtl) {
        $hit = @file_get_contents($avCacheFile);
        if ($hit !== false && $hit !== '') {
            echo $hit;
            exit;
        }
    }
    ob_start();
}

try {
    $db = new SQLite3($DB_PATH, SQLITE3_OPEN_READONLY);
    $db->busyTimeout(2000);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'db open failed']);
    exit;
}

// --- "Moments": collapse a species' consecutive detections into one episode.
// A continuously-singing bird (a blackbird whistling for 15s, and worse with
// OVERLAP on) writes many rows per second, so counting raw rows lets one
// persistent singer bury the one-off visitors in every graph. A *moment* is a
// run of same-species detections with no gap longer than the configured gap;
// all counts in this file are moments, not raw rows. The raw detections table
// is never modified - this stays read-only.
//
// Both knobs live in birdnet.conf and are editable from the admin settings
// panel (AV_GROUP_ENABLED, AV_GROUP_GAP_SEC - see avian/api/config.php). These
// defaults are served when the keys are absent, and MUST match that whitelist.
const MOMENT_GROUP_DEFAULT = true;   // AV_GROUP_ENABLED
const MOMENT_GAP_DEFAULT   = 15;     // AV_GROUP_GAP_SEC (seconds)

// Read one birdnet.conf value (system copy preferred, repo copy as fallback),
// parsed once and cached. Mirrors config.php's reader for the two AV_ keys.
function av_conf_value(string $key, string $default): string {
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
            break;   // first readable file wins, matching config.php's CONF_PATH
        }
    }
    return array_key_exists($key, $conf) ? $conf[$key] : $default;
}

$groupOn = !in_array(
    strtolower(trim(av_conf_value('AV_GROUP_ENABLED', MOMENT_GROUP_DEFAULT ? 'true' : 'false'))),
    ['false', '0', 'no', 'off', ''], true
);
$gapSec = (int)av_conf_value('AV_GROUP_GAP_SEC', (string)MOMENT_GAP_DEFAULT);
$gapSec = max(0, min(3600, $gapSec));

// Admin-hidden detections (see moderation.php) are excluded here so a hidden
// recognition disappears from every page - collage, atlas, stats, map - while
// the raw `detections` table stays untouched. This DB connection is READONLY,
// so we can't CREATE TABLE IF NOT EXISTS; check sqlite_master instead and fall
// back to no filtering on a fresh install where the table doesn't exist yet.
$hasHiddenTbl = (bool)one($db, "SELECT name FROM sqlite_master WHERE type='table' AND name='av_hidden_detections'");
$hiddenFilter = $hasHiddenTbl
    ? ' AND rowid NOT IN (SELECT det_rowid FROM av_hidden_detections)'
    : '';

// Narrow the moments build to the rows the current action can actually use.
// Windowed actions (recent / timeseries) and the single-species detail never
// look outside their slice, so feeding the whole history into the window
// function is wasted work - the dominant per-request cost on a Pi 3B+ once
// the table has a season of detections in it. The one-day margin before a
// window keeps boundary moments intact: the grouping gap is clamped to
// <= 3600 s, so context from at most an hour before the window can matter.
// All-time actions (stats, lifelist, firstseen, journey, locations) - and
// 'recent' in 'deze plek' mode, which has no time cap by design - keep the
// full scan.
function av_moments_date_filter(string $action): string {
    if ($action === 'species') {
        $sci = (string)($_GET['sci'] ?? '');
        if ($sci !== '') return "Sci_Name = '" . SQLite3::escapeString($sci) . "'";
    } elseif ($action === 'recent') {
        if (($_GET['location'] ?? '') === '1') return '';   // deze plek - no time cap
        $from = (string)($_GET['from'] ?? '');
        if ($from !== '' || (string)($_GET['to'] ?? '') !== '') {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
                return "Date >= date('" . $from . "', '-1 day')";
            }
            return '';   // open-ended range - keep the full scan
        }
        $hours = max(1, min(1000000, (int)($_GET['hours'] ?? 24)));
        if ($hours >= 1000000) return '';   // the ALL window
        return "Date >= date('now', 'localtime', '-" . (intdiv($hours, 24) + 2) . " day')";
    } elseif ($action === 'timeseries') {
        $days = max(1, min(90, (int)($_GET['days'] ?? 30)));
        // by_hour looks back 30 days regardless of the daily window.
        return "Date >= date('now', 'localtime', '-" . (max($days, 30) + 1) . " day')";
    }
    return '';
}
$momentsDateFilter = av_moments_date_filter($action);
// Single combined WHERE for the moments build: always-true base (so both
// optional filters can just AND onto it) plus the hidden-detections
// exclusion plus the per-action date narrowing.
$momentsFilter = 'WHERE 1=1' . $hiddenFilter . ($momentsDateFilter !== '' ? ' AND ' . $momentsDateFilter : '');

// Materialise moment ids ONCE per request into the connection's (writable)
// temp schema - works despite the READONLY main db, and the multi-query stats
// endpoint doesn't re-sessionise each time. `moment_id` runs per species, so a
// moment's identity is (Sci_Name, moment_id). We fall through to
// one-moment-per-row (i.e. raw counts) when grouping is switched off OR this
// SQLite predates window functions (< 3.25).
$built = false;
if ($groupOn) {
    $momentsSql =
      "CREATE TEMP TABLE moments AS "
    . "WITH gap AS ("
    . "  SELECT Date, Time, Sci_Name, Com_Name, Confidence, File_Name, Lat, Lon, "
    . "         julianday(Date || ' ' || Time) AS jd, "
    . "         CASE WHEN (julianday(Date || ' ' || Time) "
    . "              - LAG(julianday(Date || ' ' || Time)) "
    . "                  OVER (PARTITION BY Sci_Name ORDER BY julianday(Date || ' ' || Time))) "
    . "              * 86400.0 <= " . $gapSec . " THEN 0 ELSE 1 END AS new_moment "
    . "  FROM detections " . $momentsFilter . ") "
    . "SELECT Date, Time, Sci_Name, Com_Name, Confidence, File_Name, Lat, Lon, "
    . "  SUM(new_moment) OVER (PARTITION BY Sci_Name ORDER BY jd ROWS UNBOUNDED PRECEDING) AS moment_id "
    . "FROM gap";
    // SQLite3 throws under PHP 8.1+ but returns false on older builds; @
    // silences the warning, try/catch handles the exception.
    try { $built = @$db->exec($momentsSql); } catch (Throwable $e) { $built = false; }
}
if ($built === false) {
    $db->exec(
      "CREATE TEMP TABLE moments AS "
    . "SELECT Date, Time, Sci_Name, Com_Name, Confidence, File_Name, Lat, Lon, rowid AS moment_id "
    . "FROM detections " . $momentsFilter
    );
}

function rows(SQLite3 $db, string $sql, array $bind = []): array {
    $stmt = $db->prepare($sql);
    foreach ($bind as $k => $v) $stmt->bindValue($k, $v);
    $res = $stmt->execute();
    $out = [];
    while ($r = $res->fetchArray(SQLITE3_ASSOC)) $out[] = $r;
    return $out;
}
function one(SQLite3 $db, string $sql, array $bind = []) {
    $r = rows($db, $sql, $bind);
    return $r[0] ?? null;
}

function sched_sql_ts(string $ts): string {
    $out = str_replace('T', ' ', substr($ts, 0, 16));
    return strlen($out) === 16 ? $out . ':00' : $out;
}

function schedule_rows_read(SQLite3 $db): array {
    $has = one($db, "SELECT name FROM sqlite_master WHERE type='table' AND name='av_location_schedule'");
    if (!$has) return [];
    $rs = rows($db, 'SELECT id, from_ts, lat, lon, label FROM av_location_schedule ORDER BY from_ts ASC, id ASC');
    $out = [];
    foreach ($rs as $r) {
        $out[] = ['id' => (int)$r['id'], 'from_ts' => (string)$r['from_ts'],
                  'lat' => (float)$r['lat'], 'lon' => (float)$r['lon'],
                  'label' => (string)($r['label'] ?? '')];
    }
    return $out;
}

function detections_between(SQLite3 $db, string $from, ?string $to): array {
    $where = "WHERE datetime(Date || ' ' || Time) >= datetime(:from)";
    $bind = [':from' => sched_sql_ts($from)];
    if ($to !== null && $to !== '') {
        $where .= " AND datetime(Date || ' ' || Time) < datetime(:to)";
        $bind[':to'] = sched_sql_ts($to);
    }
    $rs = rows($db,
      "SELECT Sci_Name AS sci, Com_Name AS com, COUNT(DISTINCT moment_id) AS n, MAX(Confidence) AS best_conf, "
    . "       MIN(Date||' '||Time) AS first_seen, MAX(Date||' '||Time) AS last_seen "
    . "FROM moments " . $where . " "
    . "GROUP BY Sci_Name ORDER BY n DESC, last_seen DESC",
      $bind
    );
    $out = [];
    foreach ($rs as $r) {
        $out[] = ['sci' => (string)$r['sci'], 'com' => (string)($r['com'] ?? ''),
                  'n' => (int)$r['n'],
                  'best_conf' => isset($r['best_conf']) ? (float)$r['best_conf'] : null,
                  'first_seen' => (string)$r['first_seen'], 'last_seen' => (string)$r['last_seen']];
    }
    return $out;
}

function schedule_locations(SQLite3 $db): array {
    $rows = schedule_rows_read($db);
    $n = count($rows);
    for ($i = 0; $i < $n; $i++) {
        $until = ($i + 1 < $n) ? $rows[$i + 1]['from_ts'] : null;
        $species = detections_between($db, $rows[$i]['from_ts'], $until);
        $total = 0; $first = null; $last = null;
        foreach ($species as $s) {
            $total += (int)$s['n'];
            if ($first === null || $s['first_seen'] < $first) $first = $s['first_seen'];
            if ($last === null || $s['last_seen'] > $last) $last = $s['last_seen'];
        }
        $rows[$i]['until_ts'] = $until;
        $rows[$i]['species'] = $species;
        $rows[$i]['n'] = $total;
        $rows[$i]['first_seen'] = $first;
        $rows[$i]['last_seen'] = $last;
    }
    return $rows;
}

function schedule_for_detection(array $schedule, string $seenAt): ?array {
    $ts = str_replace(' ', 'T', substr($seenAt, 0, 16));
    $active = null;
    foreach ($schedule as $row) {
        if ($row['from_ts'] <= $ts) $active = $row;
        else break;
    }
    return $active;
}

switch ($action) {

    case 'stats': {
        // Detection totals are moment counts; a moment's key is (Sci_Name, moment_id).
        // Species counts are grouping-invariant, so they read the raw table.
        $total       = (int)(one($db, "SELECT COUNT(DISTINCT Sci_Name || '/' || moment_id) AS n FROM moments")['n'] ?? 0);
        $species     = (int)(one($db, 'SELECT COUNT(DISTINCT Sci_Name) AS n FROM detections')['n'] ?? 0);
        $today       = (int)(one($db, "SELECT COUNT(DISTINCT Sci_Name || '/' || moment_id) AS n FROM moments WHERE Date = DATE('now','localtime')")['n'] ?? 0);
        $todaySpec   = (int)(one($db, "SELECT COUNT(DISTINCT Sci_Name) AS n FROM detections WHERE Date = DATE('now','localtime')")['n'] ?? 0);
        $lastHour    = (int)(one($db, "SELECT COUNT(DISTINCT Sci_Name || '/' || moment_id) AS n FROM moments WHERE Date = DATE('now','localtime') AND Time >= TIME('now','localtime','-1 hour')")['n'] ?? 0);
        $week        = (int)(one($db, "SELECT COUNT(DISTINCT Sci_Name || '/' || moment_id) AS n FROM moments WHERE Date >= DATE('now','localtime','-7 day')")['n'] ?? 0);
        $weekSpec    = (int)(one($db, "SELECT COUNT(DISTINCT Sci_Name) AS n FROM detections WHERE Date >= DATE('now','localtime','-7 day')")['n'] ?? 0);
        $first       = one($db, 'SELECT MIN(Date) AS d FROM detections');
        echo json_encode([
            'totals'    => ['detections' => $total, 'species' => $species],
            'today'     => ['detections' => $today, 'species' => $todaySpec],
            'last_hour' => ['detections' => $lastHour],
            'week'      => ['detections' => $week,  'species' => $weekSpec],
            'started'   => $first['d'] ?? null,
            'as_of'     => date('c'),
        ]);
        break;
    }

    case 'lifelist': {
        // n = moment count (matches the `recent` action's alias so the
        // frontend can read either response interchangeably).
        $rs = rows($db,
          "SELECT Sci_Name AS sci, Com_Name AS com, MIN(Date||' '||Time) AS first_seen, "
        . "       MAX(Date||' '||Time) AS last_seen, COUNT(DISTINCT moment_id) AS n, MAX(Confidence) AS best_conf "
        . "FROM moments GROUP BY Sci_Name ORDER BY first_seen ASC"
        );
        echo json_encode(['species' => $rs, 'as_of' => date('c')]);
        break;
    }

    case 'recent': {
        // Three filter modes share this action's response shape:
        //   ?hours=N           - rolling window (1..1,000,000h; ALL = the cap)
        //   ?from=&to=         - custom date range (DATUM picker), inclusive
        //   ?location=1        - "deze plek": every detection stamped with the
        //                        CURRENT LATITUDE/LONGITUDE (birdnet.conf), no
        //                        time cap. Same semantics + tolerance as the
        //                        SmallTV "location" window (scripts/smalltv_push.py
        //                        species_at_location()) - reuses each detection's
        //                        own Lat/Lon column, not the reisschema, so it
        //                        works even without any schedule stops configured.
        // $win is the WHERE fragment + bind shared by both queries below.
        $useLocation = ($_GET['location'] ?? '') === '1';
        $from = (string)($_GET['from'] ?? '');
        $to   = (string)($_GET['to'] ?? '');
        $useRange = !$useLocation && ($from !== '' || $to !== '');
        $hours = max(1, min(1000000, (int)($_GET['hours'] ?? 24)));
        $locationAvailable = false;
        if ($useLocation) {
            $curLat = av_conf_value('LATITUDE', '');
            $curLon = av_conf_value('LONGITUDE', '');
            $locationAvailable = is_numeric($curLat) && is_numeric($curLon);
            if ($locationAvailable) {
                $win = 'Lat IS NOT NULL AND Lon IS NOT NULL AND ABS(Lat - :lat) < :tol AND ABS(Lon - :lon) < :tol';
                $bind = [':lat' => (float)$curLat, ':lon' => (float)$curLon, ':tol' => 0.02];
            } else {
                // No LATITUDE/LONGITUDE configured yet - degrade to "nothing
                // matches" rather than erroring, same as an empty window.
                $win = '0';
                $bind = [];
            }
        } elseif ($useRange) {
            $conds = []; $bind = [];
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) { $conds[] = 'Date >= :from'; $bind[':from'] = $from; }
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $to))   { $conds[] = 'Date <= :to';   $bind[':to'] = $to; }
            $win = $conds ? implode(' AND ', $conds) : '1=1';
        } else {
            $win = "(julianday('now','localtime') - julianday(Date||' '||Time)) * 24 <= :hrs";
            $bind = [':hrs' => $hours];
        }
        // species-collapsed view: one row per species seen in the window,
        // n = distinct moments in the window (not raw rows).
        $rs = rows($db,
          "SELECT Sci_Name AS sci, Com_Name AS com, COUNT(DISTINCT moment_id) AS n, MAX(Confidence) AS best_conf, "
        . "       MAX(Date||' '||Time) AS last_seen "
        . "FROM moments WHERE $win "
        . "GROUP BY Sci_Name ORDER BY last_seen DESC",
          $bind
        );
        // for each row, attach the file of the top-confidence detection in the
        // window. Queries the raw table directly (same $win works there too -
        // Date/Time/Lat/Lon all exist on both), so also needs the hidden-detection
        // exclusion moments already applies.
        foreach ($rs as &$r) {
            $best = one($db,
              "SELECT File_Name AS file, Date AS d, Time AS t, Confidence AS conf "
            . "FROM detections WHERE Sci_Name = :sn AND $win" . $hiddenFilter . " "
            . "ORDER BY Confidence DESC LIMIT 1",
              array_merge($bind, [':sn' => $r['sci']])
            );
            $r['top_file'] = $best['file'] ?? null;
            $r['top_at']   = isset($best['d']) ? ($best['d'].' '.$best['t']) : null;
        }
        echo json_encode(['hours' => $hours, 'from' => $from, 'to' => $to,
                          'location' => $useLocation, 'location_available' => $locationAvailable,
                          'species' => $rs, 'as_of' => date('c')]);
        break;
    }

    case 'species': {
        $sci = $_GET['sci'] ?? '';
        if ($sci === '') { http_response_code(400); echo json_encode(['error' => 'sci= required']); break; }
        $detections = rows($db,
          "SELECT Date AS d, Time AS t, File_Name AS file, Confidence AS conf "
        . "FROM detections WHERE Sci_Name = :sn" . $hiddenFilter . " ORDER BY Date DESC, Time DESC LIMIT 500",
          [':sn' => $sci]
        );
        $summary = one($db,
          "SELECT Com_Name AS com, COUNT(DISTINCT moment_id) AS total, MIN(Date||' '||Time) AS first_seen, "
        . "       MAX(Date||' '||Time) AS last_seen, MAX(Confidence) AS best_conf "
        . "FROM moments WHERE Sci_Name = :sn",
          [':sn' => $sci]
        );
        echo json_encode(['sci' => $sci, 'summary' => $summary, 'detections' => $detections]);
        break;
    }

    case 'timeseries': {
        // Aggregated time-bucketed counts for the stats charts.
        //   daily   - last $days days, detections + unique species per day
        //   by_hour - detections grouped by hour of day, last 30 days
        // The frontend backfills missing dates with zero - sparse data days
        // are otherwise dropped by the GROUP BY.
        $days = max(1, min(90, (int)($_GET['days'] ?? 30)));
        $daily = rows($db,
          "SELECT Date AS date, COUNT(DISTINCT Sci_Name || '/' || moment_id) AS detections, COUNT(DISTINCT Sci_Name) AS species "
        . "FROM moments "
        . "WHERE Date >= DATE('now','localtime','-".($days - 1)." day') "
        . "GROUP BY Date ORDER BY Date"
        );
        $by_hour = rows($db,
          "SELECT CAST(strftime('%H', Time) AS INT) AS hour, COUNT(DISTINCT Sci_Name || '/' || moment_id) AS detections "
        . "FROM moments "
        . "WHERE Date >= DATE('now','localtime','-30 day') "
        . "GROUP BY hour ORDER BY hour"
        );
        echo json_encode([
            'days'    => $days,
            'daily'   => $daily,
            'by_hour' => $by_hour,
            'as_of'   => date('c'),
        ]);
        break;
    }

    case 'firstseen': {
        // Most recent additions to the life list - first detection per
        // species, sorted by first_seen DESC. Powers the "First Detections"
        // section on the stats view.
        $limit = max(1, min(50, (int)($_GET['limit'] ?? 10)));
        $rs = rows($db,
          "SELECT Sci_Name AS sci, Com_Name AS com, MIN(Date||' '||Time) AS first_seen, "
        . "       COUNT(DISTINCT moment_id) AS total "
        . "FROM moments GROUP BY Sci_Name ORDER BY first_seen DESC LIMIT :lim",
          [':lim' => $limit]
        );
        echo json_encode(['species' => $rs, 'as_of' => date('c')]);
        break;
    }

    case 'mapconfig': {
        // Stadia Maps key for the watercolour basemap (set in php-fpm env).
        echo json_encode(['stadia_key' => getenv('STADIA_API_KEY') ?: '']);
        break;
    }

    case 'locations': {
        // Compatibility endpoint: locations are now the reisschema stops.
        // Birds attach to a stop by detection time, not by the old raw Lat/Lon
        // clusters, so there is only one binding location system.
        echo json_encode(['locations' => schedule_locations($db), 'as_of' => date('c')]);
        break;
    }

    case 'journey': {
        // First-heard location per species, attached to the matching
        // reisschema window instead of the old stored detection coordinates.
        $schedule = schedule_rows_read($db);
        $rs = rows($db,
          "SELECT Sci_Name AS sci, Com_Name AS com, MIN(Date||' '||Time) AS first_seen, COUNT(DISTINCT moment_id) AS n "
        . "FROM moments "
        . "GROUP BY Sci_Name ORDER BY first_seen ASC"
        );
        $out = [];
        foreach ($rs as $r) {
            $stop = schedule_for_detection($schedule, (string)$r['first_seen']);
            if ($stop === null) continue;
            $out[] = ['sci' => (string)$r['sci'], 'com' => (string)($r['com'] ?? ''),
                      'lat' => $stop['lat'], 'lon' => $stop['lon'],
                      'label' => $stop['label'], 'from_ts' => $stop['from_ts'],
                      'first_seen' => (string)$r['first_seen'], 'n' => (int)$r['n']];
        }
        echo json_encode(['species' => $out, 'as_of' => date('c')]);
        break;
    }

    default:
        http_response_code(404);
        echo json_encode(['error' => 'unknown action']);
}

// ---- Cache store (see the cache check above the DB open) ----
if ($avCacheFile !== null) {
    $body = (string)ob_get_contents();
    ob_end_flush();
    // http_response_code() reports false when nothing set one explicitly
    // (e.g. under CLI); that means the default 200, so treat it as such.
    $avRespCode = http_response_code();
    if ($body !== '' && ($avRespCode === 200 || $avRespCode === false)) {
        // Bound the key space: parameters are attacker-influencable (?hours=N),
        // so refuse to grow the dir without limit; stale keys age out via GC.
        $entries = @scandir($avCacheDir);
        if (is_array($entries) && count($entries) < 300) {
            $tmp = $avCacheFile . '.tmp.' . getmypid();
            if (@file_put_contents($tmp, $body) !== false) @rename($tmp, $avCacheFile);
        }
        // Occasional GC: drop entries invalidated hours ago.
        if (mt_rand(0, 49) === 0 && is_array($entries)) {
            foreach ($entries as $f) {
                if (substr($f, -5) !== '.json') continue;
                $p = $avCacheDir . '/' . $f;
                if (time() - (int)@filemtime($p) > 86400) @unlink($p);
            }
        }
    }
}
