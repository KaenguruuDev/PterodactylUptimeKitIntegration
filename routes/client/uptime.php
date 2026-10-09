<?php

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Pterodactyl\BlueprintFramework\Libraries\ExtensionLibrary\Admin\BlueprintAdminLibrary;
use Pterodactyl\Models\Server;

return static function (
    $configuration,
    $monitorIdsFrom,
    $upstreamError,
    $apiConfigurationError,
    $connectionError
) {
    return static function (BlueprintAdminLibrary $blueprint, Request $request, Server $server) use ($configuration, $monitorIdsFrom, $upstreamError, $apiConfigurationError, $connectionError) {
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
            $incidentResponse = $client->get($config['apiUrl'] . '/incidents', $incidentQuery);
            $maintenanceResponse = $client->get($config['apiUrl'] . '/maintenance', [
                'statusPageId' => $config['statusPageId'],
            ]);
            $statusPageResponse = $config['statusPageId'] !== ''
                ? $client->get($config['apiUrl'] . '/status-pages/' . rawurlencode($config['statusPageId']))
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
                    : 'https://' . rtrim($statusPage['domain'], '/');
            } elseif (!empty($statusPage['slug']) && is_string($statusPage['slug'])) {
                $apiParts = parse_url($config['apiUrl']);
                if (!empty($apiParts['scheme']) && !empty($apiParts['host'])) {
                    $statusPageUrl = $apiParts['scheme'] . '://' . $apiParts['host']
                        . (!empty($apiParts['port']) ? ':' . $apiParts['port'] : '')
                        . '/' . rawurlencode($statusPage['slug']);
                }
            }
        }
        $mappedIds = array_fill_keys($config['monitorIds'], true);
        $overlapsMappedMonitor = static function (array $item) use ($monitorIdsFrom, $mappedIds): bool {
            foreach ($monitorIdsFrom($item) as $id) if (isset($mappedIds[$id])) return true;
            return false;
        };
        $incidents = array_values(array_filter($incidents, static fn($item): bool => is_array($item) && $overlapsMappedMonitor($item)));
        $maintenances = array_values(array_filter($maintenances, static fn($item): bool => is_array($item) && $overlapsMappedMonitor($item)));

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
};
