<?php
// AvianVisitors - edit a map stop, for the Reisjournaal "locaties" menu. POST JSON:
//   {op:"move",   old_lat, old_lon, new_lat, new_lon}  - re-stamp the stop's coords
//   {op:"delete", old_lat, old_lon}                    - drop the stop's detections
// op defaults to "move".
//
// This WRITES to birds.db (the only write path in /avian/api). It is meant for
// the trusted LAN only - gate it behind Caddy basic_auth (see the audio/
// password setup) before exposing the Pi beyond your own network.

declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST only']);
    exit;
}

// Same resolution as birdnet-api.php: walk up from the symlinked api dir.
$DB_PATH = dirname(__DIR__, 2) . '/scripts/birds.db';

// When the moved stop IS the current location - the coords birdnet.conf stamps
// on every NEW detection - relocate "where I am now" too: rewrite the active
// LATITUDE/LONGITUDE and reload the analyzer, so new detections land at the
// corrected spot. Auto-location stays ON; we leave the LAST_AUTO_* baseline
// untouched, so the correction survives reboots until the Pi physically moves
// far enough for a new IP fix to land here (see auto_location.sh). Without this,
// only old rows move and new detections keep landing at the old IP-derived spot.
// Past stops (coords that don't match the current location) are just re-stamped.
function relocate_current_if_match(float $olat, float $olon, float $nlat, float $nlon): bool {
    $conf = '/etc/birdnet/birdnet.conf';
    $s = @file_get_contents($conf);
    if ($s === false) return false;
    if (!preg_match('/^LATITUDE=([\-0-9.]+)/m', $s, $a)) return false;
    if (!preg_match('/^LONGITUDE=([\-0-9.]+)/m', $s, $b)) return false;
    if (abs((float)$a[1] - $olat) > 0.005 || abs((float)$b[1] - $olon) > 0.005) {
        return false;   // moved stop is a past location, not the current one
    }
    $clat = number_format($nlat, 4, '.', '');
    $clon = number_format($nlon, 4, '.', '');
    // Move only the active location. We deliberately DON'T touch AUTO_LOCATION
    // or the LAST_AUTO_* baseline: auto-location stays on, and because the
    // baseline is unchanged this correction sticks until the Pi physically
    // moves far enough for a new IP fix to land here (see auto_location.sh).
    $s = preg_replace('/^LATITUDE=.*$/m', 'LATITUDE=' . $clat, $s, 1);
    $s = preg_replace('/^LONGITUDE=.*$/m', 'LONGITUDE=' . $clon, $s, 1);
    // The conf is owned by the install user (the web user is "other" = read
    // only), so stage it in /tmp and copy into place with the web user's
    // passwordless sudo - this follows the symlink and keeps owner/perms, the
    // same way BirdNET-Pi's own settings form persists the conf.
    $tmp = '/tmp/avian_conf_' . getmypid();
    if (@file_put_contents($tmp, $s) === false) return false;
    @exec('sudo /bin/cp ' . escapeshellarg($tmp) . ' ' . escapeshellarg($conf) . ' 2>/dev/null');
    @unlink($tmp);
    // reload the analyzer so new detections use the corrected location + species
    // list (sudoers permits this exact restart); backgrounded so the request
    // returns immediately (the model reload takes ~15s).
    @exec('sudo /bin/systemctl restart birdnet_analysis > /dev/null 2>&1 &');
    return true;
}

$body = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($body)) {
    http_response_code(400);
    echo json_encode(['error' => 'bad json']);
    exit;
}
$op = $body['op'] ?? 'move';
$need = $op === 'delete' ? ['old_lat', 'old_lon'] : ['old_lat', 'old_lon', 'new_lat', 'new_lon'];
foreach ($need as $k) {
    if (!isset($body[$k]) || !is_numeric($body[$k])) {
        http_response_code(400);
        echo json_encode(['error' => "missing/invalid $k"]);
        exit;
    }
}

try {
    $db = new SQLite3($DB_PATH, SQLITE3_OPEN_READWRITE);
    $db->busyTimeout(3000);
    if ($op === 'delete') {
        $stmt = $db->prepare('DELETE FROM detections WHERE Lat = :olat AND Lon = :olon');
        $stmt->bindValue(':olat', (float)$body['old_lat']);
        $stmt->bindValue(':olon', (float)$body['old_lon']);
        $stmt->execute();
        echo json_encode(['ok' => true, 'deleted' => $db->changes()]);
    } else {
        $nlat = (float)$body['new_lat'];
        $nlon = (float)$body['new_lon'];
        if ($nlat < -90 || $nlat > 90 || $nlon < -180 || $nlon > 180) {
            http_response_code(400);
            echo json_encode(['error' => 'coords out of range']);
            exit;
        }
        $olat = (float)$body['old_lat'];
        $olon = (float)$body['old_lon'];
        $stmt = $db->prepare('UPDATE detections SET Lat = :nlat, Lon = :nlon WHERE Lat = :olat AND Lon = :olon');
        $stmt->bindValue(':nlat', $nlat);
        $stmt->bindValue(':nlon', $nlon);
        $stmt->bindValue(':olat', $olat);
        $stmt->bindValue(':olon', $olon);
        $stmt->execute();
        $moved = $db->changes();
        $relocated = relocate_current_if_match($olat, $olon, $nlat, $nlon);
        echo json_encode(['ok' => true, 'moved' => $moved, 'relocated_current' => $relocated]);
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'write failed']);
}
