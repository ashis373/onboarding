<?php
// Onboard SPA Controller & Static Asset Fallback Server
// Ensures assets (JS/CSS/images) are properly served even under complex rewrite environments,
// while SPA application routes render index.html seamlessly.

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);

// 1. Direct Static Asset Resolution
if (preg_match('#/(?:dist/)?assets/([^/]+\.(js|css|png|jpg|jpeg|svg|webp|woff2?|ttf|ico|map))$#i', $uri, $m)) {
    $file = $m[1];
    $ext = strtolower($m[2]);
    
    $candidatePaths = [
        __DIR__ . '/dist/assets/' . $file,
        __DIR__ . '/assets/' . $file,
    ];

    foreach ($candidatePaths as $p) {
        if (is_file($p)) {
            $mimes = [
                'js' => 'application/javascript; charset=UTF-8',
                'css' => 'text/css; charset=UTF-8',
                'png' => 'image/png',
                'jpg' => 'image/jpeg',
                'jpeg' => 'image/jpeg',
                'svg' => 'image/svg+xml',
                'webp' => 'image/webp',
                'woff2' => 'font/woff2',
                'woff' => 'font/woff',
                'ttf' => 'font/ttf',
                'ico' => 'image/x-icon',
                'map' => 'application/json'
            ];
            $contentType = $mimes[$ext] ?? 'application/octet-stream';
            header("Content-Type: $contentType");
            header("Cache-Control: public, max-age=31536000, immutable");
            readfile($p);
            exit;
        }
    }
}

// 2. SPA Route Resolution
header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-cache, no-store, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

$distIndex = __DIR__ . '/dist/index.html';
if (is_file($distIndex)) {
    $html = file_get_contents($distIndex);
    if (isset($_SERVER['REQUEST_URI']) && strpos($_SERVER['REQUEST_URI'], '/internal/corhis') !== false) {
        $html = str_replace('="/onboard/', '="/internal/corhis/onboard/', $html);
    }
    echo $html;
    exit;
}

$devIndex = __DIR__ . '/index.html';
if (is_file($devIndex)) {
    readfile($devIndex);
    exit;
}

http_response_code(500);
echo '<!doctype html><html><head><meta charset="utf-8"><title>Onboard</title></head><body style="font:14px/1.4 sans-serif;padding:24px">';
echo '<strong>Onboard build not found.</strong><br>Expected at: <code>' . htmlspecialchars($distIndex) . '</code>'; 
echo '</body></html>';
?>
