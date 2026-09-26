<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Pterodactyl\BlueprintFramework\Libraries\ExtensionLibrary\Admin\BlueprintAdminLibrary;

$configuration = static function (BlueprintAdminLibrary $blueprint, Request $request): array {
    $serverId = (string) $request->input('serverId', $request->query('serverId', ''));
    $mappings = json_decode(
        (string) ($blueprint->dbGet('uptimekitmaintenancetoggle', 'serverMonitorMappings') ?? '{}'),
        true
    );
    $mapping = is_array($mappings) ? ($mappings[$serverId] ?? []) : [];
    $isStructuredMapping = is_array($mapping) && array_key_exists('monitorIds', $mapping);
    $monitorIds = $isStructuredMapping ? ($mapping['monitorIds'] ?? []) : $mapping;
    $monitorIds = is_array($monitorIds) ? array_values($monitorIds) : [$monitorIds];

    return [
        'serverId' => $serverId,
        'monitorIds' => array_values(array_filter($monitorIds)),
        'enforceStop' => $isStructuredMapping ? (bool) ($mapping['enforceStop'] ?? true) : true,
        'statusPageId' => (string) ($blueprint->dbGet('uptimekitmaintenancetoggle', 'statusPageId') ?? ''),
        'apiUrl' => rtrim((string) ($blueprint->dbGet('uptimekitmaintenancetoggle', 'apiUrl') ?? ''), '/'),
        'organizationSlug' => (string) ($blueprint->dbGet('uptimekitmaintenancetoggle', 'organizationSlug') ?? ''),
        'apiKey' => (string) ($blueprint->dbGet('uptimekitmaintenancetoggle', 'apiKey') ?? ''),
    ];
};

$forward = static function (BlueprintAdminLibrary $blueprint, Request $request, string $method, ?string $id = null) use ($configuration) {
    $config = $configuration($blueprint, $request);
    if ($config['serverId'] === '' || count($config['monitorIds']) === 0) {
        if ($method === 'GET') return response()->json(['configured' => false, 'windows' => []]);
        return response()->json(['message' => 'No UptimeKit monitor mapping exists for this server.'], 422);
    }
    if ($config['apiUrl'] === '' || $config['apiKey'] === '') {
        return response()->json(['message' => 'UptimeKit API configuration is incomplete.'], 503);
    }

    $client = Http::withToken($config['apiKey'])
        ->acceptJson()
        ->withHeaders(['X-Organization-Slug' => $config['organizationSlug']]);
    $path = '/maintenance'.($id ? '/'.rawurlencode($id) : '');

    if ($method === 'GET') {
        $response = $client->get($config['apiUrl'].$path, [
            'statusPageId' => $config['statusPageId'],
        ]);
    } elseif ($method === 'POST') {
        $body = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'startAt' => ['required', 'date'],
            'endAt' => ['required', 'date', 'after:startAt'],
        ]);
        $response = $client->post($config['apiUrl'].$path, [
            'title' => $body['title'],
            'description' => $body['description'] ?? '',
            'status' => 'scheduled',
            'monitorIds' => $config['monitorIds'],
            'statusPageId' => $config['statusPageId'],
            'startAt' => $body['startAt'],
            'endAt' => $body['endAt'],
        ]);
    } elseif ($method === 'PATCH') {
        $body = $request->validate([
            'startAt' => ['required', 'date'],
            'endAt' => ['required', 'date', 'after:startAt'],
        ]);
        $response = $client->patch($config['apiUrl'].$path, [
            'startAt' => $body['startAt'],
            'endAt' => $body['endAt'],
        ]);
    } else {
        $response = $client->delete($config['apiUrl'].$path);
    }

    if ($response->failed()) {
        return response()->json(['message' => 'UptimeKit request failed.', 'details' => $response->json()], $response->status());
    }

    $payload = $response->json();
    if ($method !== 'GET') return response()->json($payload, $response->status());

    $items = is_array($payload) && isset($payload['data']) ? $payload['data'] : $payload;
    $items = is_array($items) ? $items : [];
    $items = array_values(array_filter($items, static function (array $item) use ($config): bool {
        $itemMonitorIds = $item['monitorIds'] ?? [];
        return count(array_intersect($config['monitorIds'], is_array($itemMonitorIds) ? $itemMonitorIds : [])) > 0;
    }));

    $windows = array_map(static function (array $item): array {
        return [
            'id' => (string) ($item['id'] ?? ''),
            'title' => (string) ($item['title'] ?? ''),
            'description' => (string) ($item['description'] ?? ''),
            'startAt' => $item['startAt'] ?? null,
            'endAt' => $item['endAt'] ?? null,
            'status' => $item['status'] ?? null,
            'monitorIds' => $item['monitorIds'] ?? [],
        ];
    }, $items);

    return response()->json([
        'configured' => true,
        'enforceStop' => $config['enforceStop'],
        'windows' => $windows,
    ]);
};

Route::get('/maintenance', fn (BlueprintAdminLibrary $blueprint, Request $request) => $forward($blueprint, $request, 'GET'));
Route::post('/maintenance', fn (BlueprintAdminLibrary $blueprint, Request $request) => $forward($blueprint, $request, 'POST'));
Route::patch('/maintenance/{id}', fn (BlueprintAdminLibrary $blueprint, Request $request, string $id) => $forward($blueprint, $request, 'PATCH', $id));
Route::delete('/maintenance/{id}', fn (BlueprintAdminLibrary $blueprint, Request $request, string $id) => $forward($blueprint, $request, 'DELETE', $id));
