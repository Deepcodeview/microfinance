<?php
// Public entry for the API. The code lives in ../finserve/api (refused to browsers by finserve/.htaccess).
// Looks upwards so it also works if the finserve folder sits next to the website folder instead.
for ($dir = dirname(__DIR__), $i = 0; $i < 4; $dir = dirname($dir), $i++) {
    if (is_file($dir . '/finserve/api/public/index.php') && is_file($dir . '/finserve/api/.env')) {
        require $dir . '/finserve/api/public/index.php';
        return;
    }
}
http_response_code(503);
header('Content-Type: application/json');
echo '{"error":{"code":"NOT_INSTALLED","message":"KBSAVE is not set up yet. Open /kbsave-setup.php on this site."}}';
