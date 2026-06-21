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
        $stmt = $db->prepare('UPDATE detections SET Lat = :nlat, Lon = :nlon WHERE Lat = :olat AND Lon = :olon');
        $stmt->bindValue(':nlat', $nlat);
        $stmt->bindValue(':nlon', $nlon);
        $stmt->bindValue(':olat', (float)$body['old_lat']);
        $stmt->bindValue(':olon', (float)$body['old_lon']);
        $stmt->execute();
        echo json_encode(['ok' => true, 'moved' => $db->changes()]);
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'write failed']);
}
