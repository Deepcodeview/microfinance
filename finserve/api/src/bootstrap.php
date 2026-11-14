<?php

declare(strict_types=1);

// PSR-4 style autoloader: App\Core\Router -> src/Core/Router.php
spl_autoload_register(function (string $class): void {
    $prefix = 'App\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

App\Core\Env::load(dirname(__DIR__) . '/.env');
date_default_timezone_set('UTC');
bcscale(10);
