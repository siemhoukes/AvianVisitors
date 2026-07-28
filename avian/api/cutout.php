<?php
// AvianVisitors - bird image resolver.
//
// Lookup chain for /avian/api/cutout.php?sci=Calypte+anna:
//   1. ../assets/illustrations/<slug>.png   (450+ bundled kachō-e renders)
//   2. ../assets/cutouts/<slug>.png         (background-removed photo)
//   3. cached rembg of a Wikipedia photo at $HOME/BirdSongs/Extracted/cutouts/
//   4. fresh Wikipedia -> rembg -> cache (skipped gracefully if rembg unset)
//
// The frontend's <img src> points here for every species - bundled
// hits return instantly; cold misses fall through to the dynamic path.
//
// Default LAN deploy ships without auth. To expose publicly, gate
// /avian/api/* with basic_auth in your Caddyfile - see avian/forwarding/.

declare(strict_types=1);

$sci = trim((string)($_GET['sci'] ?? ''));
if ($sci === '') {
    http_response_code(400);
    echo 'sci required';
    exit;
}
// Binomial / trinomial pattern. Rejects path-traversal payloads and
// junk before any filesystem or upstream lookup.
if (!preg_match('/^[A-Za-z]{2,40}(?:[ ][a-z]{2,40}){1,3}$/', $sci)) {
    http_response_code(400);
    echo 'invalid sci';
    exit;
}

// Slugify scientific name for filename + cache key.
$slug = preg_replace('/[^a-z0-9]+/', '-', strtolower($sci));
$slug = trim((string)$slug, '-');

// pose=1 (default) is perched. pose=2 is flight. Clamp to a two-digit
// positive integer so a malformed ?pose= can't break the path.
$pose = (int)($_GET['pose'] ?? 1);
if ($pose < 1 || $pose > 99) $pose = 1;
$poseSuffix = $pose === 1 ? '' : "-$pose";

// Optional thumbnail width. The bundled illustrations average ~680 KB (they
// are full-size renders meant for the collage and the detail modal), which is
// fine for the handful an atlas page shows but not for the Vogelkans list -
// scrolling its ~200 rows of 40px thumbs would otherwise pull ~130 MB, over
// whatever connection the caravan happens to be on. ?w= serves a cached
// downscale instead. Clamped to a sane band; 0 means "full size", the
// behaviour every existing caller gets.
$thumbW = (int)($_GET['w'] ?? 0);
if ($thumbW !== 0 && ($thumbW < 32 || $thumbW > 400)) $thumbW = 0;

// Build (once) and return a downscaled copy of $src, or null if we can't -
// no GD, no writable cache dir, unreadable source. Callers fall back to the
// full-size file, so a failure here costs bandwidth, never a broken image.
function thumb_path(string $src, int $w): ?string {
    if ($w <= 0 || !function_exists('imagecreatefrompng')) return null;
    $dir = dirname(__DIR__, 3) . '/BirdSongs/Extracted/cutouts/thumb';
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) return null;
    // mtime in the key so a regenerated illustration invalidates its thumb.
    $key = md5($src . '|' . (string)@filemtime($src) . '|' . $w);
    $out = "$dir/$key.png";
    if (is_file($out) && filesize($out) > 256) return $out;

    $im = @imagecreatefrompng($src);
    if ($im === false) return null;
    $sw = imagesx($im);
    $sh = imagesy($im);
    if ($sw <= 0 || $sh <= 0) { imagedestroy($im); return null; }
    if ($sw <= $w) { imagedestroy($im); return null; }   // already small enough
    $nh = max(1, (int)round($sh * ($w / $sw)));
    $dst = imagecreatetruecolor($w, $nh);
    // The illustrations are transparent PNGs; without these two the alpha
    // channel is flattened to black.
    imagealphablending($dst, false);
    imagesavealpha($dst, true);
    imagecopyresampled($dst, $im, 0, 0, 0, 0, $w, $nh, $sw, $sh);
    $tmp = $out . '.tmp.' . getmypid();
    $ok = @imagepng($dst, $tmp, 8);
    imagedestroy($im);
    imagedestroy($dst);
    if (!$ok) { @unlink($tmp); return null; }
    @rename($tmp, $out);
    return is_file($out) ? $out : null;
}

function serve_png(string $path): void {
    global $thumbW;
    $t = thumb_path($path, $thumbW);
    if ($t !== null) $path = $t;
    header('Content-Type: image/png');
    header('Cache-Control: public, max-age=86400');
    header('Content-Length: ' . (string)filesize($path));
    readfile($path);
    exit;
}

// 1. Bundled illustration with pose suffix (the kachō-e PNG the repo
//    ships with). 450+ species cover both perched + flight.
$bundled = dirname(__DIR__) . "/assets/illustrations/{$slug}{$poseSuffix}.png";
if (is_file($bundled) && filesize($bundled) > 1024) {
    serve_png($bundled);
}
// Pose-2 missing? Fall back to pose-1 so the flight tab still shows
// the perched render instead of breaking to the photo fallback.
if ($pose !== 1) {
    $fallback = dirname(__DIR__) . "/assets/illustrations/$slug.png";
    if (is_file($fallback) && filesize($fallback) > 1024) {
        serve_png($fallback);
    }
}
// 2. Bundled cutout (background-removed photo, fallback for species
//    without an illustration).
$cutout = dirname(__DIR__) . "/assets/cutouts/$slug.png";
if (is_file($cutout) && filesize($cutout) > 1024) {
    serve_png($cutout);
}

// 3. Dynamic cache from a previous Wikipedia + rembg run.
$cacheDir = dirname(__DIR__, 3) . '/BirdSongs/Extracted/cutouts';
$cachePath = "$cacheDir/$slug.png";
if (is_file($cachePath) && filesize($cachePath) > 1024) {
    serve_png($cachePath);
}

// 4. Fresh Wikipedia fetch + rembg. Skipped if rembg-cli isn't on
//    PATH - the resolver returns a 404 in that case rather than
//    burning a Wikipedia request we can't use.
$rembg = '/usr/local/bin/rembg-cli';
if (!is_executable($rembg)) {
    http_response_code(404);
    echo 'no illustration bundled for ' . htmlspecialchars($sci) . ' (install rembg-cli to enable Wikipedia fallback)';
    exit;
}

if (!is_dir($cacheDir)) @mkdir($cacheDir, 0755, true);

// Wikipedia's REST API asks for a contact-able identifier. Override
// via the AV_USER_AGENT env var (set in /etc/php/*/fpm/pool.d/www.conf
// or your shell) if your install hammers their endpoint at scale.
$ua = getenv('AV_USER_AGENT') ?: 'AvianVisitors/1.0 (+https://github.com/Twarner491/AvianVisitors)';
$ctx = stream_context_create([
    'http' => ['header' => "User-Agent: $ua\r\n", 'timeout' => 12],
]);
$wpUrl = 'https://en.wikipedia.org/api/rest_v1/page/summary/' . rawurlencode($sci);
$wpJson = @file_get_contents($wpUrl, false, $ctx);
$srcUrl = null;
if ($wpJson !== false) {
    $j = json_decode($wpJson, true);
    $srcUrl = $j['originalimage']['source'] ?? $j['thumbnail']['source'] ?? null;
}
// Defensive: only follow URLs on Wikimedia / Wikipedia hosts so a
// poisoned summary endpoint can't redirect us to arbitrary servers.
if ($srcUrl !== null) {
    $host = parse_url((string)$srcUrl, PHP_URL_HOST) ?: '';
    if (!preg_match('/(?:^|\.)(?:wikimedia\.org|wikipedia\.org)$/i', $host)) {
        $srcUrl = null;
    }
}
if (!$srcUrl) {
    http_response_code(404);
    echo 'no Wikipedia photo for ' . htmlspecialchars($sci);
    exit;
}

$imgBytes = @file_get_contents($srcUrl, false, $ctx);
if (!$imgBytes || strlen($imgBytes) < 1024) {
    http_response_code(503);
    echo 'failed to fetch source image';
    exit;
}

// rembg via the wrapper. u2netp = lightweight model (~50MB peak RAM -
// matters on the Pi 3B+). Temp files because rembg's CLI prefers
// real paths.
$tmpInBase  = tempnam(sys_get_temp_dir(), 'rembg-in-');
$tmpOutBase = tempnam(sys_get_temp_dir(), 'rembg-out-');
@unlink($tmpInBase); @unlink($tmpOutBase);
$tmpIn  = $tmpInBase  . '.jpg';
$tmpOut = $tmpOutBase . '.png';
file_put_contents($tmpIn, $imgBytes);

$cmd = sprintf(
    '%s i -m u2netp -ppm %s %s 2>&1',
    escapeshellarg($rembg),
    escapeshellarg($tmpIn),
    escapeshellarg($tmpOut)
);
$out = shell_exec($cmd);
@unlink($tmpIn);

if (!is_file($tmpOut) || filesize($tmpOut) < 1024) {
    @unlink($tmpOut);
    http_response_code(500);
    header('Content-Type: text/plain');
    echo "rembg failed (see your Pi's logs for details)";
    error_log("rembg failed for $sci: " . ($out ?? '(no output)'));
    exit;
}

// Tight-crop to the bird's bounding box + downscale to 800px max edge
// so cache stays small.
$im = @imagecreatefrompng($tmpOut);
if ($im !== false) {
    $cropped = @imagecropauto($im, IMG_CROP_TRANSPARENT);
    if ($cropped !== false) {
        imagedestroy($im);
        $im = $cropped;
    }
    $w = imagesx($im); $h = imagesy($im);
    $max = 800;
    if ($w > $max || $h > $max) {
        $scale = $max / max($w, $h);
        $nw = (int)($w * $scale); $nh = (int)($h * $scale);
        $resized = imagecreatetruecolor($nw, $nh);
        imagealphablending($resized, false);
        imagesavealpha($resized, true);
        imagecopyresampled($resized, $im, 0, 0, 0, 0, $nw, $nh, $w, $h);
        imagedestroy($im);
        $im = $resized;
    }
    imagealphablending($im, false);
    imagesavealpha($im, true);
    imagepng($im, $tmpOut, 6);
    imagedestroy($im);
}

// Atomic install: rename is atomic on the same filesystem, so any
// concurrent reader either sees the old cached file or the new one,
// never a half-written PNG.
@rename($tmpOut, $cachePath);
serve_png($cachePath);
