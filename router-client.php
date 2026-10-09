<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Pterodactyl\BlueprintFramework\Libraries\ExtensionLibrary\Admin\BlueprintAdminLibrary;
use Pterodactyl\Http\Middleware\Api\Client\Server\AuthenticateServerAccess;
use Pterodactyl\Models\Server;

$helpers = require __DIR__ . '/routes/client/helpers.php';
$configuration = $helpers['configuration'];
$upstreamError = $helpers['upstreamError'];
$maintenanceForClient = $helpers['maintenanceForClient'];
$apiConfigurationError = $helpers['apiConfigurationError'];
$connectionError = $helpers['connectionError'];
$monitorIdsFrom = $helpers['monitorIdsFrom'];

$forward = (require __DIR__ . '/routes/client/maintenance.php')(
    $configuration,
    $upstreamError,
    $maintenanceForClient,
    $apiConfigurationError,
    $connectionError,
    $monitorIdsFrom
);
$uptime = (require __DIR__ . '/routes/client/uptime.php')(
    $configuration,
    $monitorIdsFrom,
    $upstreamError,
    $apiConfigurationError,
    $connectionError
);

Route::prefix('/servers/{server}')->middleware(AuthenticateServerAccess::class)->group(function () use ($forward, $uptime): void {
    Route::get('/maintenance', fn(BlueprintAdminLibrary $blueprint, Request $request, Server $server) => $forward($blueprint, $request, $server, 'GET'));
    Route::post('/maintenance', fn(BlueprintAdminLibrary $blueprint, Request $request, Server $server) => $forward($blueprint, $request, $server, 'POST'));
    Route::patch('/maintenance/{id}', fn(BlueprintAdminLibrary $blueprint, Request $request, Server $server, string $id) => $forward($blueprint, $request, $server, 'PATCH', $id));
    Route::delete('/maintenance/{id}', fn(BlueprintAdminLibrary $blueprint, Request $request, Server $server, string $id) => $forward($blueprint, $request, $server, 'DELETE', $id));
    Route::get('/uptime', fn(BlueprintAdminLibrary $blueprint, Request $request, Server $server) => $uptime($blueprint, $request, $server));
});
