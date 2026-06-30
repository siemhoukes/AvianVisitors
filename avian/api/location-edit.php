<?php
// AvianVisitors - legacy map coordinate edit endpoint.
//
// Locations are now owned by av_location_schedule via location-schedule.php.
// Keeping this endpoint as a fail-closed compatibility stub prevents old UI or
// cached clients from re-stamping raw detection coordinates or rewriting
// birdnet.conf outside the reisschema flow.

declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

http_response_code(410);
echo json_encode([
    'error' => 'location edits moved to reisschema',
    'use' => 'location-schedule.php',
]);
