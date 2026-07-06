<?php
// AvianVisitors - admin hide/unhide for detections ("waarnemingen beheren").
//
// Lets an admin remove a bad/embarrassing recognition (misfire, human
// speech misclassified as a bird, etc.) from every page - collage, atlas,
// stats, map - without touching BirdNET-Pi's raw detections table. Hidden
// rowids are kept in a small companion table (av_hidden_detections); the
// raw table is only ever read here, never written, matching the
// read-only-detections philosophy in birdnet-api.php.
//
//   GET  ?limit=&offset=&q=   -> {moments:[{sci,com,file,best_conf,
//                                  first_seen,last_seen,n,rowids,hidden}...],
//                                 total, as_of}
//        Lists MOMENTS (same grouping as the rest of the app - a species'
//        consecutive detections within the configured gap collapse into
//        one row), newest first, INCLUDING already-hidden ones (the admin
//        needs to see those to unhide them). Each moment carries the raw
//        `rowids` it's made of - hide/unhide acts on that exact list, so
//        the client never has to guess at moment identity across requests.
//   POST {op:"hide"|"unhide", rowids:[...]}
//
// Gating: Caddy basicauth, ADMIN TIER ONLY (not pensionado) - see the
// AUTH_ADMIN block in scripts/update_caddyfile.sh. Hiding a recognition is
// a moderation action for Siem, not something the parents need.

declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (getenv('AV_REQUIRE_AUTH') === '1' && empty($_SERVER['HTTP_AUTHORIZATION'])) {
    http_response_code(401);
    echo json_encode(['error' => 'unauthorized']);
    exit;
}

$DB_PATH = dirname(__DIR__, 2) . '/scripts/birds.db';
if (!file_exists($DB_PATH)) {
    http_response_code(503);
    echo json_encode(['error' => 'birds.db not found']);
    exit;
}

function db_open(string $path): SQLite3 {
    $db = new SQLite3($path, SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE);
    $db->busyTimeout(3000);
    $db->exec('CREATE TABLE IF NOT EXISTS av_hidden_detections ('
        . 'det_rowid INTEGER PRIMARY KEY, hidden_at TEXT NOT NULL)');
    return $db;
}

// Same grouping knobs as birdnet-api.php (AV_GROUP_ENABLED / AV_GROUP_GAP_SEC)
// so "one recognition" here means the same thing as everywhere else in the app.
const MOMENT_GROUP_DEFAULT = true;
const MOMENT_GAP_DEFAULT   = 15;

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
            break;
        }
    }
    return array_key_exists($key, $conf) ? $conf[$key] : $default;
}

function rows(SQLite3 $db, string $sql, array $bind = []): array {
    $stmt = $db->prepare($sql);
    foreach ($bind as $k => $v) $stmt->bindValue($k, $v);
    $res = $stmt->execute();
    $out = [];
    while ($r = $res->fetchArray(SQLITE3_ASSOC)) $out[] = $r;
    return $out;
}

function hidden_rowid_set(SQLite3 $db): array {
    $set = [];
    foreach (rows($db, 'SELECT det_rowid FROM av_hidden_detections') as $r) {
        $set[(int)$r['det_rowid']] = true;
    }
    return $set;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$db = db_open($DB_PATH);

if ($method === 'GET') {
    $groupOn = !in_array(
        strtolower(trim(av_conf_value('AV_GROUP_ENABLED', MOMENT_GROUP_DEFAULT ? 'true' : 'false'))),
        ['false', '0', 'no', 'off', ''], true
    );
    $gapSec = (int)av_conf_value('AV_GROUP_GAP_SEC', (string)MOMENT_GAP_DEFAULT);
    $gapSec = max(0, min(3600, $gapSec));

    $built = false;
    if ($groupOn) {
        $sql =
          "CREATE TEMP TABLE mod_grp AS "
        . "WITH gap AS ("
        . "  SELECT rowid AS rid, Date, Time, Sci_Name, Com_Name, Confidence, File_Name, "
        . "         julianday(Date || ' ' || Time) AS jd, "
        . "         CASE WHEN (julianday(Date || ' ' || Time) "
        . "              - LAG(julianday(Date || ' ' || Time)) "
        . "                  OVER (PARTITION BY Sci_Name ORDER BY julianday(Date || ' ' || Time))) "
        . "              * 86400.0 <= " . $gapSec . " THEN 0 ELSE 1 END AS new_moment "
        . "  FROM detections) "
        . "SELECT rid, Date, Time, Sci_Name, Com_Name, Confidence, File_Name, jd, "
        . "  SUM(new_moment) OVER (PARTITION BY Sci_Name ORDER BY jd ROWS UNBOUNDED PRECEDING) AS moment_id "
        . "FROM gap";
        try { $built = @$db->exec($sql); } catch (Throwable $e) { $built = false; }
    }
    if ($built === false) {
        $db->exec(
          "CREATE TEMP TABLE mod_grp AS "
        . "SELECT rowid AS rid, Date, Time, Sci_Name, Com_Name, Confidence, File_Name, "
        . "       julianday(Date || ' ' || Time) AS jd, rowid AS moment_id FROM detections"
        );
    }
    $db->exec(
      "CREATE TEMP TABLE mod_moments AS "
    . "SELECT rid, Date, Time, Sci_Name, Com_Name, Confidence, File_Name, moment_id, "
    . "  ROW_NUMBER() OVER (PARTITION BY Sci_Name, moment_id ORDER BY Confidence DESC, jd DESC) AS rn "
    . "FROM mod_grp"
    );

    $q = trim((string)($_GET['q'] ?? ''));
    $where = '1=1';
    $bind = [];
    if ($q !== '') {
        $where = '(Sci_Name LIKE :q OR Com_Name LIKE :q)';
        $bind[':q'] = '%' . $q . '%';
    }

    $total = (int)(rows($db,
        "SELECT COUNT(*) AS n FROM (SELECT 1 FROM mod_moments WHERE $where GROUP BY Sci_Name, moment_id)",
        $bind
    )[0]['n'] ?? 0);

    $limit  = max(1, min(500, (int)($_GET['limit'] ?? 100)));
    $offset = max(0, (int)($_GET['offset'] ?? 0));
    $bind[':lim'] = $limit;
    $bind[':off'] = $offset;

    $moments = rows($db,
      "SELECT Sci_Name AS sci, "
    . "  MAX(CASE WHEN rn = 1 THEN Com_Name END) AS com, "
    . "  MAX(CASE WHEN rn = 1 THEN File_Name END) AS file, "
    . "  MAX(Confidence) AS best_conf, "
    . "  MIN(Date || ' ' || Time) AS first_seen, "
    . "  MAX(Date || ' ' || Time) AS last_seen, "
    . "  COUNT(*) AS n, "
    . "  GROUP_CONCAT(rid) AS rowids "
    . "FROM mod_moments WHERE $where GROUP BY Sci_Name, moment_id "
    . "ORDER BY last_seen DESC LIMIT :lim OFFSET :off",
      $bind
    );

    $hidden = hidden_rowid_set($db);
    $out = [];
    foreach ($moments as $m) {
        $rowids = array_map('intval', array_filter(explode(',', (string)$m['rowids']), 'strlen'));
        $hitCount = 0;
        foreach ($rowids as $rid) if (isset($hidden[$rid])) $hitCount++;
        $out[] = [
            'sci'        => (string)$m['sci'],
            'com'        => (string)($m['com'] ?? ''),
            'file'       => $m['file'] ?? null,
            'best_conf'  => isset($m['best_conf']) ? (float)$m['best_conf'] : null,
            'first_seen' => (string)$m['first_seen'],
            'last_seen'  => (string)$m['last_seen'],
            'n'          => (int)$m['n'],
            'rowids'     => $rowids,
            'hidden'     => $hitCount > 0 && $hitCount === count($rowids),
            'partially_hidden' => $hitCount > 0 && $hitCount < count($rowids),
        ];
    }
    echo json_encode(['moments' => $out, 'total' => $total, 'limit' => $limit, 'offset' => $offset, 'as_of' => date('c')]);
    exit;
}

if ($method === 'POST') {
    $body = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($body)) { http_response_code(400); echo json_encode(['error' => 'bad json']); exit; }
    $op = $body['op'] ?? '';
    $rowids = array_values(array_unique(array_map('intval', (array)($body['rowids'] ?? []))));
    $rowids = array_filter($rowids, function ($v) { return $v > 0; });
    if (!$rowids) { http_response_code(400); echo json_encode(['error' => 'rowids required']); exit; }
    // Cap batch size - a moment is a handful of rows in practice; this
    // just guards against a malformed/huge payload.
    if (count($rowids) > 5000) { http_response_code(400); echo json_encode(['error' => 'too many rowids']); exit; }

    if ($op === 'hide') {
        $st = $db->prepare("INSERT OR IGNORE INTO av_hidden_detections (det_rowid, hidden_at) VALUES (:r, datetime('now','localtime'))");
        foreach ($rowids as $rid) { $st->bindValue(':r', $rid, SQLITE3_INTEGER); $st->execute(); $st->reset(); }
    } elseif ($op === 'unhide') {
        $st = $db->prepare('DELETE FROM av_hidden_detections WHERE det_rowid = :r');
        foreach ($rowids as $rid) { $st->bindValue(':r', $rid, SQLITE3_INTEGER); $st->execute(); $st->reset(); }
    } else {
        http_response_code(400); echo json_encode(['error' => 'unknown op']); exit;
    }
    echo json_encode(['ok' => true, 'op' => $op, 'rowids' => array_values($rowids)]);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'method not allowed']);
