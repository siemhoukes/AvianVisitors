<?php
// AvianVisitors - manual, date+time travel schedule (the "reisschema").
//
// Each entry = "from this timestamp, the unit is at <place>". The ACTIVE entry
// is the most recent one whose timestamp is <= now; it drives birdnet.conf
// LATITUDE/LONGITUDE (the BirdNET species-occurrence filter) and therefore
// where NEW detections are stamped. Replaces the IP geolocation (which is wrong
// here because the caravan router is registered in another country).
//
//   GET  -> {"schedule":[{id,from_ts,lat,lon,label,until_ts,n,species...}...], "active":{...}|null, "now":"YYYY-MM-DDTHH:MM"}
//   POST {"op":"add",    from_ts,lat,lon,label}
//   POST {"op":"update", id, from_ts,lat,lon,label}
//   POST {"op":"delete", id}
// On any change that moves the ACTIVE location, birdnet.conf is rewritten and
// birdnet_analysis restarted (same mechanism as location-edit.php). A boot/cron
// script (apply_location_schedule.sh) re-applies it so a FUTURE-dated move
// activates on its own when its time arrives.
//
// Gating: Caddy basic_auth (both tiers) - see scripts/update_caddyfile.sh.
// This WRITES birds.db + birdnet.conf, so it must stay behind that gate.

declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

// PHP-FPM defaults to UTC; align "now" with the SYSTEM local timezone so the
// active-entry comparison matches both what the user picks in the browser
// (local wall-clock from the datetime-local field) and the cron apply-script
// (apply_location_schedule.sh uses system local `date`). Without this the
// schedule would activate hours early/late.
$av_tz = @trim((string)@file_get_contents('/etc/timezone'));
if ($av_tz !== '') @date_default_timezone_set($av_tz);

$DB_PATH = dirname(__DIR__, 2) . '/scripts/birds.db';
$CONF    = '/etc/birdnet/birdnet.conf';
$TS_RE   = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/';   // datetime-local format

function db_open(string $path, int $mode): SQLite3 {
    $db = new SQLite3($path, $mode);
    $db->busyTimeout(3000);
    $db->exec('CREATE TABLE IF NOT EXISTS av_location_schedule ('
        . 'id INTEGER PRIMARY KEY AUTOINCREMENT, from_ts TEXT NOT NULL, '
        . 'lat REAL NOT NULL, lon REAL NOT NULL, label TEXT)');
    return $db;
}

function schedule_rows(SQLite3 $db): array {
    $out = [];
    $res = $db->query('SELECT id, from_ts, lat, lon, label FROM av_location_schedule ORDER BY from_ts ASC, id ASC');
    while ($r = $res->fetchArray(SQLITE3_ASSOC)) {
        $out[] = ['id' => (int)$r['id'], 'from_ts' => (string)$r['from_ts'],
                  'lat' => (float)$r['lat'], 'lon' => (float)$r['lon'],
                  'label' => (string)($r['label'] ?? '')];
    }
    return $out;
}

function active_entry(array $rows, string $now): ?array {
    $active = null;
    foreach ($rows as $r) {                 // rows are sorted ascending by from_ts
        if ($r['from_ts'] <= $now) $active = $r;
    }
    return $active;
}

function sql_ts(string $ts): string {
    $out = str_replace('T', ' ', substr($ts, 0, 16));
    return strlen($out) === 16 ? $out . ':00' : $out;
}

function detections_between(SQLite3 $db, string $from, ?string $to): array {
    $where = "WHERE datetime(Date || ' ' || Time) >= datetime(:from)";
    if ($to !== null && $to !== '') {
        $where .= " AND datetime(Date || ' ' || Time) < datetime(:to)";
    }
    // Admin-hidden detections (see moderation.php) drop out of the reisschema
    // species lists too - "hide from all the pages" includes the map/kaart.
    $hasHiddenTbl = $db->querySingle("SELECT name FROM sqlite_master WHERE type='table' AND name='av_hidden_detections'");
    if ($hasHiddenTbl) $where .= ' AND rowid NOT IN (SELECT det_rowid FROM av_hidden_detections)';
    $sql =
        "SELECT Sci_Name AS sci, Com_Name AS com, COUNT(*) AS n, MAX(Confidence) AS best_conf, "
        . "MIN(Date||' '||Time) AS first_seen, MAX(Date||' '||Time) AS last_seen "
        . "FROM detections " . $where . " "
        . "GROUP BY Sci_Name ORDER BY n DESC, last_seen DESC";
    $st = $db->prepare($sql);
    $st->bindValue(':from', sql_ts($from), SQLITE3_TEXT);
    if ($to !== null && $to !== '') $st->bindValue(':to', sql_ts($to), SQLITE3_TEXT);
    $res = $st->execute();
    $out = [];
    while ($r = $res->fetchArray(SQLITE3_ASSOC)) {
        $out[] = [
            'sci' => (string)$r['sci'],
            'com' => (string)($r['com'] ?? ''),
            'n' => (int)$r['n'],
            'best_conf' => isset($r['best_conf']) ? (float)$r['best_conf'] : null,
            'first_seen' => (string)$r['first_seen'],
            'last_seen' => (string)$r['last_seen'],
        ];
    }
    return $out;
}

function schedule_with_birds(SQLite3 $db): array {
    $rows = schedule_rows($db);
    $n = count($rows);
    for ($i = 0; $i < $n; $i++) {
        $until = ($i + 1 < $n) ? $rows[$i + 1]['from_ts'] : null;
        $species = detections_between($db, $rows[$i]['from_ts'], $until);
        $total = 0;
        $first = null;
        $last = null;
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

// Push the active location into birdnet.conf + reload the analyzer, but only if
// it actually changed. Mirrors location-edit.php's relocate (stage in /tmp, then
// the web user's passwordless `sudo cp`, which preserves the symlink + owner).
function apply_active(?array $active, string $conf): bool {
    if ($active === null) return false;
    $s = @file_get_contents($conf);
    if ($s === false) return false;
    $clat = number_format($active['lat'], 4, '.', '');
    $clon = number_format($active['lon'], 4, '.', '');
    $haveLat = preg_match('/^LATITUDE=([\-0-9.]+)/m', $s, $a) ? (float)$a[1] : null;
    $haveLon = preg_match('/^LONGITUDE=([\-0-9.]+)/m', $s, $b) ? (float)$b[1] : null;
    if ($haveLat !== null && $haveLon !== null
        && abs($haveLat - (float)$clat) < 0.0005 && abs($haveLon - (float)$clon) < 0.0005) {
        return false;   // already there - no write, no restart
    }
    if (preg_match('/^LATITUDE=.*$/m', $s)) $s = preg_replace('/^LATITUDE=.*$/m', 'LATITUDE=' . $clat, $s, 1);
    else $s = rtrim($s, "\n") . "\nLATITUDE=" . $clat . "\n";
    if (preg_match('/^LONGITUDE=.*$/m', $s)) $s = preg_replace('/^LONGITUDE=.*$/m', 'LONGITUDE=' . $clon, $s, 1);
    else $s = rtrim($s, "\n") . "\nLONGITUDE=" . $clon . "\n";
    $tmp = '/tmp/avian_sched_' . getmypid();
    if (@file_put_contents($tmp, $s) === false) return false;
    @exec('sudo /bin/cp ' . escapeshellarg($tmp) . ' ' . escapeshellarg($conf) . ' 2>/dev/null');
    @unlink($tmp);
    @exec('sudo /bin/systemctl restart birdnet_analysis > /dev/null 2>&1 &');
    return true;
}

function respond(SQLite3 $db, string $conf, bool $applied = false): void {
    $rows = schedule_with_birds($db);
    $now = date('Y-m-d\TH:i');
    echo json_encode(['schedule' => $rows, 'active' => active_entry($rows, $now),
                      'now' => $now, 'applied' => $applied]);
}

if (!file_exists($DB_PATH)) { http_response_code(503); echo json_encode(['error' => 'birds.db not found']); exit; }

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    if ($method === 'GET') {
        $db = db_open($DB_PATH, SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE);
        respond($db, $CONF);
        exit;
    }

    if ($method === 'POST') {
        $body = json_decode((string)file_get_contents('php://input'), true);
        if (!is_array($body)) { http_response_code(400); echo json_encode(['error' => 'bad json']); exit; }
        $op = $body['op'] ?? '';
        $db = db_open($DB_PATH, SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE);

        if ($op === 'delete') {
            $id = (int)($body['id'] ?? 0);
            if ($id <= 0) { http_response_code(400); echo json_encode(['error' => 'bad id']); exit; }
            $st = $db->prepare('DELETE FROM av_location_schedule WHERE id = :id');
            $st->bindValue(':id', $id, SQLITE3_INTEGER);
            $st->execute();
        } elseif ($op === 'add' || $op === 'update') {
            $ts = (string)($body['from_ts'] ?? '');
            $lat = $body['lat'] ?? null;
            $lon = $body['lon'] ?? null;
            $label = trim((string)($body['label'] ?? ''));
            if (!preg_match($TS_RE, $ts)) { http_response_code(400); echo json_encode(['error' => 'bad from_ts']); exit; }
            if (!is_numeric($lat) || !is_numeric($lon)) { http_response_code(400); echo json_encode(['error' => 'bad coords']); exit; }
            $lat = (float)$lat; $lon = (float)$lon;
            if ($lat < -90 || $lat > 90 || $lon < -180 || $lon > 180) { http_response_code(400); echo json_encode(['error' => 'coords out of range']); exit; }
            if (mb_strlen($label) > 120) $label = mb_substr($label, 0, 120);
            if ($op === 'add') {
                $st = $db->prepare('INSERT INTO av_location_schedule (from_ts, lat, lon, label) VALUES (:t, :la, :lo, :lb)');
            } else {
                $id = (int)($body['id'] ?? 0);
                if ($id <= 0) { http_response_code(400); echo json_encode(['error' => 'bad id']); exit; }
                $st = $db->prepare('UPDATE av_location_schedule SET from_ts=:t, lat=:la, lon=:lo, label=:lb WHERE id=:id');
                $st->bindValue(':id', $id, SQLITE3_INTEGER);
            }
            $st->bindValue(':t', $ts, SQLITE3_TEXT);
            $st->bindValue(':la', $lat, SQLITE3_FLOAT);
            $st->bindValue(':lo', $lon, SQLITE3_FLOAT);
            $st->bindValue(':lb', $label, SQLITE3_TEXT);
            $st->execute();
        } else {
            http_response_code(400); echo json_encode(['error' => 'unknown op']); exit;
        }

        // Re-evaluate the active location and push it to the analyzer if it moved.
        $rows = schedule_rows($db);
        $applied = apply_active(active_entry($rows, date('Y-m-d\TH:i')), $CONF);
        respond($db, $CONF, $applied);
        exit;
    }

    http_response_code(405);
    echo json_encode(['error' => 'method not allowed']);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'schedule failed']);
}
