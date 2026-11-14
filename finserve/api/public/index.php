<?php

declare(strict_types=1);

use App\Core\Env;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;

require dirname(__DIR__) . '/src/bootstrap.php';

ini_set('display_errors', '0');
$requestId = bin2hex(random_bytes(16));

// CORS for the Android apps (Capacitor WebView origin). They authenticate with bearer tokens, never
// cookies, so no credentials are allowed cross-origin. Browsers on the same domain don't need this.
$origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
$allowedOrigins = array_filter(array_map('trim', explode(',', (string) Env::get('CORS_ORIGINS', 'http://localhost,https://localhost,capacitor://localhost'))));
if ($origin !== '' && in_array($origin, $allowedOrigins, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
    header('Access-Control-Expose-Headers: X-Request-Id');
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
        header('Access-Control-Allow-Methods: GET, POST, PATCH, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Authorization, Content-Type, Idempotency-Key, X-Requested-With');
        header('Access-Control-Max-Age: 600');
        http_response_code(204);
        exit;
    }
}

try {
    $request = Request::fromGlobals();
    $requestId = $request->id;

    $router = new Router();
    (require dirname(__DIR__) . '/src/routes.php')($router);

    $response = $router->dispatch($request);
} catch (HttpException $e) {
    $response = Response::error($e->status, $e->errorCode, $e->getMessage(), $requestId, $e->fields);
} catch (Throwable $e) {
    // Full detail goes to the server log only, tagged with the request ID the client receives.
    error_log(sprintf('[%s] %s: %s in %s:%d', $requestId, $e::class, $e->getMessage(), $e->getFile(), $e->getLine()));
    $message = Env::bool('APP_DEBUG') ? $e->getMessage() : 'An unexpected error occurred.';
    $response = Response::error(500, 'SERVER_ERROR', $message, $requestId);
}

$response->send($requestId);
