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
            if ($method === 'POST' && is_array($item)) {
                // The create endpoint returns only {id}. Fill the client
                // response with the validated request data so the newly
                // created window can be rendered immediately.
                $item = array_merge([
                    'title' => (string) $request->input('title'),
                    'description' => (string) ($request->input('description') ?? ''),
                    'startAt' => (string) $request->input('startAt'),
                    'endAt' => (string) $request->input('endAt'),
                    'status' => 'scheduled',
                    'monitorIds' => $config['monitorIds'],
                ], $item);
            }
            return response()->json(is_array($item) ? $maintenanceForClient($item) : [], $response->status());
        }

        $items = is_array($payload) && isset($payload['data']) ? $payload['data'] : $payload;
        $items = is_array($items) ? $items : [];

        // UptimeKit's list response does not include the incident-monitor
        // relationships, while the detail response does. Hydrate each item
        // before applying the server monitor scope so valid windows are not
        // discarded as unrelated.
        $hydratedItems = [];
        foreach ($items as $item) {
            if (!is_array($item)) continue;
            $monitorIds = $monitorIdsFrom($item);
            $itemId = $item['id'] ?? null;
            if (count($monitorIds) === 0 && is_scalar($itemId) && (string) $itemId !== '') {
                try {
                    $detailResponse = $client->get($config['apiUrl'] . '/maintenance/' . rawurlencode((string) $itemId));
                } catch (ConnectionException $exception) {
                    return $connectionError($exception, $config);
                }
                if ($detailResponse->failed()) {
                    return $upstreamError($detailResponse, $config, 'maintenance window');
                }
                $detail = $detailResponse->json();
                $detail = is_array($detail) && isset($detail['data']) ? $detail['data'] : $detail;
                if (is_array($detail)) $item = array_merge($item, $detail);
            }

            $hydratedItems[] = $item;
        }

        $mappedMonitorIds = array_map('strval', $config['monitorIds']);
        $items = array_values(array_filter($hydratedItems, static function (array $item) use ($mappedMonitorIds, $monitorIdsFrom): bool {
            return count(array_intersect($mappedMonitorIds, array_map('strval', $monitorIdsFrom($item)))) > 0;
        }));

        $windows = array_map($maintenanceForClient, $items);

        return response()->json([
            'configured' => true,
            'enforceStop' => $config['enforceStop'],
            'windows' => $windows,
        ]);
};
};
