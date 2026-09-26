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

$uptime = static function (BlueprintAdminLibrary $blueprint, Request $request) use ($configuration, $monitorIdsFrom) {
    $config = $configuration($blueprint, $request);
    $range = (string) $request->query('range', '24h');
    $rangeSeconds = ['24h' => 24 * 60 * 60, '7d' => 7 * 24 * 60 * 60, '28d' => 28 * 24 * 60 * 60];
    if (!array_key_exists($range, $rangeSeconds)) {
        return response()->json(['message' => 'The uptime range must be 24h, 7d, or 28d.'], 422);
    }
    if ($config['serverId'] === '' || count($config['monitorIds']) === 0) {
        return response()->json(['configured' => false, 'range' => $range, 'buckets' => []]);
    }
    if ($config['apiUrl'] === '' || $config['apiKey'] === '') {
        return response()->json(['message' => 'UptimeKit API configuration is incomplete.'], 503);
    }

    $client = Http::withToken($config['apiKey'])
        ->acceptJson()
        ->withHeaders(['X-Organization-Slug' => $config['organizationSlug']]);
    $incidentQuery = ['status' => 'all', 'limit' => 1000];
    if ($config['statusPageId'] !== '') $incidentQuery['statusPageId'] = $config['statusPageId'];
    $incidentResponse = $client->get($config['apiUrl'].'/incidents', $incidentQuery);
    $maintenanceResponse = $client->get($config['apiUrl'].'/maintenance', [
        'statusPageId' => $config['statusPageId'],
    ]);
    $statusPageResponse = $config['statusPageId'] !== ''
        ? $client->get($config['apiUrl'].'/status-pages/'.rawurlencode($config['statusPageId']))
        : null;

    if ($incidentResponse->failed() || $maintenanceResponse->failed()) {
        $response = $incidentResponse->failed() ? $incidentResponse : $maintenanceResponse;
        return response()->json(['message' => 'UptimeKit request failed.', 'details' => $response->json()], $response->status());
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

Route::get('/maintenance', fn (BlueprintAdminLibrary $blueprint, Request $request) => $forward($blueprint, $request, 'GET'));
Route::post('/maintenance', fn (BlueprintAdminLibrary $blueprint, Request $request) => $forward($blueprint, $request, 'POST'));
Route::patch('/maintenance/{id}', fn (BlueprintAdminLibrary $blueprint, Request $request, string $id) => $forward($blueprint, $request, 'PATCH', $id));
Route::delete('/maintenance/{id}', fn (BlueprintAdminLibrary $blueprint, Request $request, string $id) => $forward($blueprint, $request, 'DELETE', $id));
Route::get('/uptime', fn (BlueprintAdminLibrary $blueprint, Request $request) => $uptime($blueprint, $request));
