<?php

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Pterodactyl\BlueprintFramework\Libraries\ExtensionLibrary\Admin\BlueprintAdminLibrary;
use Pterodactyl\Models\Permission;
use Pterodactyl\Models\Server;

return static function (
    $configuration,
    $upstreamError,
    $maintenanceForClient,
    $apiConfigurationError,
    $connectionError,
    $monitorIdsFrom
) {
    return static function (BlueprintAdminLibrary $blueprint, Request $request, Server $server, string $method, ?string $id = null) use ($configuration, $upstreamError, $maintenanceForClient, $apiConfigurationError, $connectionError, $monitorIdsFrom) {
        $config = $configuration($blueprint, $server);
        if (count($config['monitorIds']) === 0) {
            if ($method === 'GET') return response()->json(['configured' => false, 'windows' => []]);
            return response()->json(['message' => 'No UptimeKit monitor mapping exists for this server.'], 422);
        }
        if ($configurationError = $apiConfigurationError($config)) return $configurationError;
        if ($method !== 'GET' && !$request->user()->can(Permission::ACTION_CONTROL_STOP, $server)) {
            return response()->json(['message' => 'You do not have permission to manage maintenance for this server.'], 403);
        }

        $client = Http::withToken($config['apiKey'])
            ->acceptJson()
            ->withHeaders(['X-Organization-Slug' => $config['organizationSlug']]);
        $path = '/maintenance' . ($id ? '/' . rawurlencode($id) : '');

        if ($id !== null) {
            try {
                $lookup = $client->get($config['apiUrl'] . $path);
            } catch (ConnectionException $exception) {
                return $connectionError($exception, $config);
            }
            if ($lookup->failed()) return $upstreamError($lookup, $config, 'maintenance window');

            $item = $lookup->json();
            $item = is_array($item) && isset($item['data']) ? $item['data'] : $item;
            $itemMonitorIds = is_array($item) ? $monitorIdsFrom($item) : [];
            $mappedMonitorIds = array_map('strval', $config['monitorIds']);
            $belongsToServer = count($itemMonitorIds) > 0
                && count(array_diff(array_map('strval', $itemMonitorIds), $mappedMonitorIds)) === 0;
            $itemStatusPageId = is_array($item) ? ($item['statusPageId'] ?? null) : null;
            if ($itemStatusPageId === null && is_array($item['statusPage'] ?? null)) {
                $itemStatusPageId = $item['statusPage']['id'] ?? null;
            }
            if (!$belongsToServer || ($itemStatusPageId !== null && (string) $itemStatusPageId !== $config['statusPageId'])) {
                return response()->json(['message' => 'The requested maintenance window was not found for this server.'], 404);
            }
        }

        try {
            if ($method === 'GET') {
                $response = $client->get($config['apiUrl'] . $path, [
                    'statusPageId' => $config['statusPageId'],
                ]);
            } elseif ($method === 'POST') {
                $body = $request->validate([
                    'title' => ['required', 'string', 'max:255'],
                    'description' => ['nullable', 'string'],
                    'startAt' => ['required', 'date'],
                    'endAt' => ['required', 'date', 'after:startAt'],
                ]);
                $response = $client->post($config['apiUrl'] . $path, [
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
                $response = $client->patch($config['apiUrl'] . $path, [
                    'startAt' => $body['startAt'],
                    'endAt' => $body['endAt'],
                ]);
            } else {
                $response = $client->delete($config['apiUrl'] . $path);
            }
        } catch (ConnectionException $exception) {
            return $connectionError($exception, $config);
        }

        if ($response->failed()) {
            return $upstreamError($response, $config, $id === null ? 'maintenance API endpoint' : 'maintenance window');
        }

        $payload = $response->json();
        if ($method === 'DELETE') return response()->json(['deleted' => (string) $id], $response->status());
        if ($method !== 'GET') {
            $item = is_array($payload) && isset($payload['data']) ? $payload['data'] : $payload;
            return response()->json(is_array($item) ? $maintenanceForClient($item) : [], $response->status());
        }

        $items = is_array($payload) && isset($payload['data']) ? $payload['data'] : $payload;
        $items = is_array($items) ? $items : [];
        $items = array_values(array_filter($items, static function ($item) use ($config, $monitorIdsFrom): bool {
            if (!is_array($item)) return false;
            return count(array_intersect(array_map('strval', $config['monitorIds']), array_map('strval', $monitorIdsFrom($item)))) > 0;
        }));

        $windows = array_map($maintenanceForClient, $items);

        return response()->json([
            'configured' => true,
            'enforceStop' => $config['enforceStop'],
            'windows' => $windows,
        ]);
};
};
