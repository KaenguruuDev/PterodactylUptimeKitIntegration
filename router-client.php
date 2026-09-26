<?php

use Illuminate\Http\Request;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response as HttpResponse;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Pterodactyl\BlueprintFramework\Libraries\ExtensionLibrary\Admin\BlueprintAdminLibrary;
use Pterodactyl\Http\Middleware\Api\Client\Server\AuthenticateServerAccess;
use Pterodactyl\Models\Permission;
use Pterodactyl\Models\Server;

$configuration = static function (BlueprintAdminLibrary $blueprint, Server $server): array {
    $serverId = (string) $server->uuid;
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
        'allowInsecureApiUrl' => (bool) ($blueprint->dbGet('uptimekitmaintenancetoggle', 'allowInsecureApiUrl') ?? false),
        'organizationSlug' => (string) ($blueprint->dbGet('uptimekitmaintenancetoggle', 'organizationSlug') ?? ''),
        'apiKey' => (string) ($blueprint->dbGet('uptimekitmaintenancetoggle', 'apiKey') ?? ''),
    ];
};

$upstreamError = static function (HttpResponse $response, array $config, string $resource = 'request') {
    $status = $response->status();
    $payload = $response->json();
    $detail = null;
    $code = null;
    if (is_array($payload)) {
        foreach (['message', 'error', 'title'] as $field) {
            if (isset($payload[$field]) && is_scalar($payload[$field])) {
                $detail = trim((string) $payload[$field]);
                break;
            }
        }
        if (isset($payload['code']) && is_scalar($payload['code'])) {
            $candidateCode = (string) $payload['code'];
            if (preg_match('/^[A-Za-z0-9._-]{1,100}$/', $candidateCode)) $code = $candidateCode;
        }
    }
    if ($detail !== null) {
        $detail = str_replace((string) $config['apiKey'], '[redacted]', $detail);
        $detail = preg_replace('/Bearer\s+[A-Za-z0-9._~+\/=\-]+/i', 'Bearer [redacted]', $detail);
        $detail = mb_substr($detail, 0, 500);
    }

    $message = match ($status) {
        401 => 'UptimeKit rejected the configured API key.',
        403 => 'The configured UptimeKit API key does not have permission for this request.',
        404 => "The requested UptimeKit {$resource} was not found. Check the API URL and configured resource IDs.",
        422 => 'UptimeKit rejected the request data.',
        429 => 'UptimeKit is rate limiting requests. Try again shortly.',
        default => $status >= 500
            ? 'UptimeKit is currently unavailable or returned a server error.'
            : 'The UptimeKit request failed.',
    };

    return response()->json(array_filter([
        'message' => $message,
        'upstreamStatus' => $status,
        'upstreamCode' => $code,
        'upstreamMessage' => $detail,
    ], static fn ($value): bool => $value !== null && $value !== ''), $status >= 500 ? 502 : $status);
};

$maintenanceForClient = static function (array $item): array {
    $result = [];
    foreach (['id', 'title', 'description', 'startAt', 'endAt', 'status'] as $field) {
        if (array_key_exists($field, $item) && (is_scalar($item[$field]) || $item[$field] === null)) {
            $result[$field] = $item[$field] === null ? null : (string) $item[$field];
        }
    }
    if (isset($item['monitorIds']) && is_array($item['monitorIds'])) {
        $result['monitorIds'] = array_values(array_map('strval', array_filter($item['monitorIds'], 'is_scalar')));
    }

    return $result;
};

$apiConfigurationError = static function (array $config) {
    if ($config['apiUrl'] === '' || $config['apiKey'] === '' || $config['organizationSlug'] === '') {
        return response()->json(['message' => 'UptimeKit API configuration is incomplete.'], 503);
    }
    $scheme = strtolower((string) parse_url($config['apiUrl'], PHP_URL_SCHEME));
    if ($scheme !== 'https' && !($config['allowInsecureApiUrl'] && $scheme === 'http')) {
        return response()->json([
            'message' => 'The UptimeKit API URL must use HTTPS unless insecure HTTP is enabled by an administrator.',
        ], 503);
    }

    return null;
};

$connectionError = static function (ConnectionException $exception, array $config) {
    $exceptionMessage = strtolower($exception->getMessage());
    [$category, $reason] = match (true) {
        str_contains($exceptionMessage, 'curl error 6'),
        str_contains($exceptionMessage, 'could not resolve host'),
        str_contains($exceptionMessage, 'name or service not known') => ['dns_failure', 'the API hostname could not be resolved'],
        str_contains($exceptionMessage, 'curl error 7'),
        str_contains($exceptionMessage, 'connection refused'),
        str_contains($exceptionMessage, 'failed to connect') => ['connection_refused', 'the API host refused the connection or is not listening'],
        str_contains($exceptionMessage, 'curl error 28'),
        str_contains($exceptionMessage, 'timed out') => ['timeout', 'the connection timed out'],
        str_contains($exceptionMessage, 'curl error 60'),
        str_contains($exceptionMessage, 'certificate problem'),
        str_contains($exceptionMessage, 'certificate verify failed') => ['tls_certificate', 'TLS certificate validation failed'],
        str_contains($exceptionMessage, 'curl error 35'),
        str_contains($exceptionMessage, 'ssl connect') => ['tls_handshake', 'the TLS handshake failed'],
        default => ['connection_failed', 'the API could not be reached'],
    };
    $scheme = strtolower((string) parse_url($config['apiUrl'], PHP_URL_SCHEME));
    $httpOverrideActive = $scheme === 'http' && $config['allowInsecureApiUrl'];
    $message = "Could not connect to the configured UptimeKit API: {$reason}.";
    if ($httpOverrideActive) {
        $message .= ' The insecure HTTP developer option is enabled, so HTTPS enforcement did not block this request.';
    }

    return response()->json([
        'message' => $message,
        'upstreamCategory' => $category,
        'httpOverrideActive' => $httpOverrideActive,
    ], 502);
};

$monitorIdsFrom = static function (array $item): array {
    $ids = $item['monitorIds'] ?? null;
    if (is_array($ids)) return array_values(array_filter($ids, 'is_scalar'));

    $links = $item['monitors'] ?? [];
    if (!is_array($links)) return [];

    $ids = [];
    foreach ($links as $link) {
        if (!is_array($link)) continue;
        $id = $link['monitorId'] ?? $link['id'] ?? ($link['monitor']['id'] ?? null);
        if (is_scalar($id) && (string) $id !== '') $ids[] = (string) $id;
    }

    return array_values(array_unique($ids));
};

$forward = static function (BlueprintAdminLibrary $blueprint, Request $request, Server $server, string $method, ?string $id = null) use ($configuration, $upstreamError, $maintenanceForClient, $apiConfigurationError, $connectionError, $monitorIdsFrom) {
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
    $path = '/maintenance'.($id ? '/'.rawurlencode($id) : '');

    if ($id !== null) {
        try {
            $lookup = $client->get($config['apiUrl'].$path);
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

$uptime = static function (BlueprintAdminLibrary $blueprint, Request $request, Server $server) use ($configuration, $monitorIdsFrom, $upstreamError, $apiConfigurationError, $connectionError) {
    $config = $configuration($blueprint, $server);
    $range = (string) $request->query('range', '24h');
    $rangeSeconds = ['24h' => 24 * 60 * 60, '7d' => 7 * 24 * 60 * 60, '28d' => 28 * 24 * 60 * 60];
    if (!array_key_exists($range, $rangeSeconds)) {
        return response()->json(['message' => 'The uptime range must be 24h, 7d, or 28d.'], 422);
    }
    if (count($config['monitorIds']) === 0) {
        return response()->json(['configured' => false, 'range' => $range, 'buckets' => []]);
    }
    if ($configurationError = $apiConfigurationError($config)) return $configurationError;

    $client = Http::withToken($config['apiKey'])
        ->acceptJson()
        ->withHeaders(['X-Organization-Slug' => $config['organizationSlug']]);
    $incidentQuery = ['status' => 'all', 'limit' => 1000];
    if ($config['statusPageId'] !== '') $incidentQuery['statusPageId'] = $config['statusPageId'];
    try {
        $incidentResponse = $client->get($config['apiUrl'].'/incidents', $incidentQuery);
        $maintenanceResponse = $client->get($config['apiUrl'].'/maintenance', [
            'statusPageId' => $config['statusPageId'],
        ]);
        $statusPageResponse = $config['statusPageId'] !== ''
            ? $client->get($config['apiUrl'].'/status-pages/'.rawurlencode($config['statusPageId']))
            : null;
    } catch (ConnectionException $exception) {
        return $connectionError($exception, $config);
    }

    if ($incidentResponse->failed() || $maintenanceResponse->failed()) {
        $response = $incidentResponse->failed() ? $incidentResponse : $maintenanceResponse;
        return $upstreamError($response, $config, $incidentResponse->failed() ? 'incidents API endpoint' : 'maintenance API endpoint');
    }

    $incidentPayload = $incidentResponse->json();
    $maintenancePayload = $maintenanceResponse->json();
    $statusPagePayload = $statusPageResponse && $statusPageResponse->successful() ? $statusPageResponse->json() : null;
    $statusPage = is_array($statusPagePayload) && isset($statusPagePayload['data'])
        ? $statusPagePayload['data']
        : $statusPagePayload;
    $incidents = is_array($incidentPayload) && isset($incidentPayload['items']) ? $incidentPayload['items'] : $incidentPayload;
    $maintenances = is_array($maintenancePayload) && isset($maintenancePayload['data']) ? $maintenancePayload['data'] : $maintenancePayload;
    $incidents = is_array($incidents) ? $incidents : [];
    $maintenances = is_array($maintenances) ? $maintenances : [];
    $statusPageUrl = null;
    if (is_array($statusPage)) {
        $candidateUrl = $statusPage['url'] ?? $statusPage['publicUrl'] ?? null;
        if (is_string($candidateUrl) && filter_var($candidateUrl, FILTER_VALIDATE_URL)) {
            $statusPageUrl = $candidateUrl;
        } elseif (!empty($statusPage['domain']) && is_string($statusPage['domain'])) {
            $statusPageUrl = preg_match('#^https?://#i', $statusPage['domain'])
                ? rtrim($statusPage['domain'], '/')
                : 'https://'.rtrim($statusPage['domain'], '/');
        } elseif (!empty($statusPage['slug']) && is_string($statusPage['slug'])) {
            $apiParts = parse_url($config['apiUrl']);
            if (!empty($apiParts['scheme']) && !empty($apiParts['host'])) {
                $statusPageUrl = $apiParts['scheme'].'://'.$apiParts['host']
                    .(!empty($apiParts['port']) ? ':'.$apiParts['port'] : '')
                    .'/'.rawurlencode($statusPage['slug']);
            }
        }
    }
    $mappedIds = array_fill_keys($config['monitorIds'], true);
    $overlapsMappedMonitor = static function (array $item) use ($monitorIdsFrom, $mappedIds): bool {
        foreach ($monitorIdsFrom($item) as $id) if (isset($mappedIds[$id])) return true;
        return false;
    };
    $incidents = array_values(array_filter($incidents, static fn ($item): bool => is_array($item) && $overlapsMappedMonitor($item)));
    $maintenances = array_values(array_filter($maintenances, static fn ($item): bool => is_array($item) && $overlapsMappedMonitor($item)));

    $now = time();
    $bucketCount = 24;
    $bucketSeconds = intdiv($rangeSeconds[$range], $bucketCount);
    $rangeStart = $now - $rangeSeconds[$range];
    $overlaps = static function (?string $start, ?string $end, int $bucketStart, int $bucketEnd) use ($now): bool {
        $startAt = $start ? strtotime($start) : false;
        $endAt = $end ? strtotime($end) : $now;
        return $startAt !== false && $endAt !== false && $startAt < $bucketEnd && $endAt > $bucketStart;
    };
    $severityStatus = ['critical' => 'major_outage', 'major' => 'partial_outage', 'minor' => 'degraded', 'degraded' => 'degraded'];
    $severityRank = ['operational' => 0, 'degraded' => 1, 'partial_outage' => 2, 'major_outage' => 3];
    $boundaries = [$rangeStart, $now];
    foreach (array_merge($incidents, $maintenances) as $item) {
        $startAt = !empty($item['startedAt']) ? strtotime($item['startedAt']) : strtotime($item['startAt'] ?? '');
        $endAt = !empty($item['endedAt']) ? strtotime($item['endedAt']) : strtotime($item['endAt'] ?? '');
        if ($startAt === false) continue;
        $endAt = $endAt === false ? $now : $endAt;
        if ($startAt >= $now || $endAt <= $rangeStart) continue;
        $boundaries[] = max($rangeStart, $startAt);
        $boundaries[] = min($now, $endAt);
    }
    $boundaries = array_values(array_unique($boundaries));
    sort($boundaries, SORT_NUMERIC);
    $monitoredSeconds = 0;
    $downtimeSeconds = 0;
    for ($index = 0; $index < count($boundaries) - 1; $index++) {
        $start = $boundaries[$index];
        $end = $boundaries[$index + 1];
        if ($end <= $start) continue;
        $underMaintenance = false;
        foreach ($maintenances as $item) {
            if ($overlaps($item['startAt'] ?? null, $item['endAt'] ?? null, $start, $end)) {
                $underMaintenance = true;
                break;
            }
        }
        if ($underMaintenance) continue;
        $monitoredSeconds += $end - $start;
        foreach ($incidents as $item) {
            if ($overlaps($item['startedAt'] ?? null, $item['endedAt'] ?? null, $start, $end)) {
                $downtimeSeconds += $end - $start;
                break;
            }
        }
    }
    $uptimePercent = $monitoredSeconds > 0
        ? round((($monitoredSeconds - $downtimeSeconds) / $monitoredSeconds) * 100, 2)
        : 100;
    $buckets = [];
    for ($index = 0; $index < $bucketCount; $index++) {
        $start = $rangeStart + ($index * $bucketSeconds);
        $end = $index === $bucketCount - 1 ? $now : $start + $bucketSeconds;
        $status = 'operational';
        $titles = [];
        foreach ($maintenances as $item) {
            if (!$overlaps($item['startAt'] ?? null, $item['endAt'] ?? null, $start, $end)) continue;
            $status = 'maintenance';
            if (!empty($item['title'])) $titles[] = (string) $item['title'];
        }
        foreach ($incidents as $item) {
            if (!$overlaps($item['startedAt'] ?? null, $item['endedAt'] ?? null, $start, $end)) continue;
            if (!empty($item['title'])) $titles[] = (string) $item['title'];
            if ($status === 'maintenance') continue;
            $candidate = $severityStatus[$item['severity'] ?? ''] ?? 'major_outage';
            if ($severityRank[$candidate] > $severityRank[$status]) $status = $candidate;
        }
        $buckets[] = [
            'startAt' => gmdate('c', $start),
            'endAt' => gmdate('c', $end),
            'status' => $status,
            'titles' => array_values(array_unique($titles)),
        ];
    }

    return response()->json([
        'configured' => true,
        'range' => $range,
        'statusPageUrl' => $statusPageUrl,
        'uptimePercent' => $uptimePercent,
        'buckets' => $buckets,
    ]);
};

Route::prefix('/servers/{server}')->middleware(AuthenticateServerAccess::class)->group(function () use ($forward, $uptime): void {
    Route::get('/maintenance', fn (BlueprintAdminLibrary $blueprint, Request $request, Server $server) => $forward($blueprint, $request, $server, 'GET'));
    Route::post('/maintenance', fn (BlueprintAdminLibrary $blueprint, Request $request, Server $server) => $forward($blueprint, $request, $server, 'POST'));
    Route::patch('/maintenance/{id}', fn (BlueprintAdminLibrary $blueprint, Request $request, Server $server, string $id) => $forward($blueprint, $request, $server, 'PATCH', $id));
    Route::delete('/maintenance/{id}', fn (BlueprintAdminLibrary $blueprint, Request $request, Server $server, string $id) => $forward($blueprint, $request, $server, 'DELETE', $id));
    Route::get('/uptime', fn (BlueprintAdminLibrary $blueprint, Request $request, Server $server) => $uptime($blueprint, $request, $server));
});
