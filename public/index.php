<?php

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

/*
|--------------------------------------------------------------------------
| Check If The Application Is Under Maintenance
|--------------------------------------------------------------------------
|
| If the application is in maintenance / demo mode via the "down" command
| we will load this file so that any pre-rendered content can be shown
| instead of starting the framework, which could cause an exception.
|
*/

if (file_exists(__DIR__.'/../storage/framework/maintenance.php')) {
    require __DIR__.'/../storage/framework/maintenance.php';
}

/*
|--------------------------------------------------------------------------
| Register The Auto Loader
|--------------------------------------------------------------------------
|
| Composer provides a convenient, automatically generated class loader for
| this application. We just need to utilize it! We'll simply require it
| into the script here so we don't need to manually load our classes.
|
*/

require __DIR__.'/../vendor/autoload.php';

/*
|--------------------------------------------------------------------------
| Guard: ext-psr PECL extension conflict
|--------------------------------------------------------------------------
|
| The PECL "psr" extension (ext-psr) provides PSR interfaces as native C
| classes with PSR-3 v1 method signatures. Monolog v3 and psr/log v3 use
| PSR-3 v3 signatures (Stringable|string $message). When ext-psr is loaded,
| PHP resolves Psr\Log\LoggerInterface from the extension (v1 signatures)
| instead of from Composer's psr/log package (v3 signatures), causing:
|   "Declaration of Monolog\Logger::emergency(...) must be compatible with
|    PsrExt\Log\LoggerInterface::emergency(...)"
|
| Fix: disable ext-psr in your hosting PHP settings (Hostinger hPanel →
| Advanced → PHP Configuration → Extensions → uncheck "psr").
|
*/

if (extension_loaded('psr')) {
    http_response_code(500);
    die(
        '<h1>Server Configuration Error</h1>'
        . '<p>The PHP <code>ext-psr</code> extension is loaded and conflicts with '
        . 'Monolog v3 / psr/log v3 (PSR-3 signature mismatch).</p>'
        . '<p><strong>Fix:</strong> Disable the <code>psr</code> PHP extension in your '
        . 'hosting control panel (Hostinger hPanel → Advanced → PHP Configuration '
        . '→ Extensions → uncheck "psr").</p>'
    );
}

/*
|--------------------------------------------------------------------------
| Run The Application
|--------------------------------------------------------------------------
|
| Once we have the application, we can handle the incoming request using
| the application's HTTP kernel. Then, we will send the response back
| to this client's browser, allowing them to enjoy our application.
|
*/

$app = require_once __DIR__.'/../bootstrap/app.php';

$kernel = $app->make(Kernel::class);

$response = tap($kernel->handle(
    $request = Request::capture()
))->send();

$kernel->terminate($request, $response);
