<?php
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));
// Generated with the verified private Pharmacy POS release path at deployment.
$private = '__PRIVATE_APPLICATION__';
if (!is_file($private.'/.installed')) { http_response_code(503); exit('Office setup is in progress.'); }
if (file_exists($maintenance = $private.'/storage/framework/maintenance.php')) require $maintenance;
require $private.'/vendor/autoload.php';
$app = require $private.'/bootstrap/app.php';
$app->usePublicPath(__DIR__);
$app->afterBootstrapping(LoadConfiguration::class, function ($app) {
    $app['config']->set([
        'operations.office_only' => true,
        'app.url' => 'https://pharmacyposoffice.pwarmyar.com',
        'session.cookie' => 'pharmacy_platform_office_session',
        'session.domain' => null,
        'session.secure' => true,
    ]);
});
$kernel = $app->make(Kernel::class);
$response = $kernel->handle($request = Request::capture())->send();
$kernel->terminate($request, $response);
