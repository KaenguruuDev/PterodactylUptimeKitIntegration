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

        if ($statusPageResponse && $statusPageResponse->failed()) {
            return $upstreamError($statusPageResponse, $config, 'status page API endpoint');
        }

        $incidentPayload = $incidentResponse->json();
        $maintenancePayload = $maintenanceResponse->json();
        $statusPagePayload = $statusPageResponse ? $statusPageResponse->json() : null;

        $describePayload = static function (mixed $payload): string {
            if ($payload === null) {
                return 'The response body was empty or was not valid JSON.';
            }
            if (!is_array($payload)) {
                return 'Expected a JSON object or array, received ' . get_debug_type($payload) . '.';
            }
            if ($payload === []) {
                return 'Expected a non-empty JSON object or array, received an empty response.';
            }

            $keys = array_keys($payload);
            $isList = $keys === range(0, count($keys) - 1);
            if ($isList) {
                return 'Expected a JSON object, received an array with ' . count($payload) . ' item(s).';
            }

            $fields = array_values(array_filter($keys, 'is_string'));
            $fields = array_slice($fields, 0, 20);
            $suffix = count($keys) > count($fields) ? ', …' : '';

            return 'Received a JSON object with fields: ' . implode(', ', $fields) . $suffix . '.';
        };
        $payloadError = static function (mixed $payload): ?string {
            if (!is_array($payload)) return null;
            foreach (['message', 'error', 'title', 'detail'] as $field) {
                if (isset($payload[$field]) && is_scalar($payload[$field])) {
                    $detail = trim((string) $payload[$field]);
                    if ($detail !== '') return $detail;
                }
            }

            return null;
        };
        $responseBodyPreview = static function ($response) use ($config): ?string {
            if (!is_object($response) || !method_exists($response, 'body')) return null;
            $body = trim((string) $response->body());
            if ($body === '') return null;
            $body = str_replace((string) $config['apiKey'], '[redacted]', $body);
            $body = preg_replace('/Bearer\s+[A-Za-z0-9._~+\/=\-]+/i', 'Bearer [redacted]', $body);

            return mb_substr($body, 0, 1000) . (mb_strlen($body) > 1000 ? '…' : '');
        };
        $invalidPayload = static function (
            string $resource,
            mixed $payload,
            $response = null,
            ?string $reason = null
        ) use ($describePayload, $payloadError, $responseBodyPreview, $config) {
            $detail = $reason ?? $describePayload($payload);
            if ($reportedError = $payloadError($payload)) {
                $detail = "UptimeKit reported: {$reportedError} {$detail}";
            }
            if ($body = $responseBodyPreview($response)) {
                $contentType = method_exists($response, 'header')
                    ? (string) $response->header('Content-Type')
                    : '';
                $isHtml = str_contains(strtolower($contentType), 'text/html')
                    || str_starts_with(strtolower(ltrim($body)), '<!doctype html')
                    || str_starts_with(strtolower(ltrim($body)), '<html');
                if ($isHtml) {
                    $detail .= ' The configured URL returned the UptimeKit web application instead of the JSON API. Set API URL to the API base, usually ending in /api/rpc, not the dashboard URL.';
                }
                $detail .= ' Response body' . ($contentType !== '' ? ' (' . $contentType . ')' : '') . ': ' . $body;
            }
            $detail = str_replace((string) $config['apiKey'], '[redacted]', $detail);
            $detail = preg_replace('/Bearer\s+[A-Za-z0-9._~+\/=\-]+/i', 'Bearer [redacted]', $detail);

            return response()->json(array_filter([
                'message' => "UptimeKit returned an invalid {$resource} response.",
                'upstreamCategory' => 'invalid_payload',
                'upstreamStatus' => $response?->status(),
                'upstreamMessage' => mb_substr($detail, 0, 500),
            ], static fn($value): bool => $value !== null && $value !== ''), 502);
        };
        $isList = static function (array $value): bool {
            $expectedKey = 0;
            foreach (array_keys($value) as $key) {
                if ($key !== $expectedKey++) return false;
            }

            return true;
        };
        $extractList = static function (mixed $payload, string $container) use ($isList): ?array {
            if (is_array($payload) && array_key_exists($container, $payload)) {
                $payload = $payload[$container];
            }
            if (!is_array($payload) || !$isList($payload)) return null;
            foreach ($payload as $item) {
                if (!is_array($item)) return null;
            }

            return $payload;
        };
        $statusPage = is_array($statusPagePayload) && isset($statusPagePayload['data'])
            ? $statusPagePayload['data']
            : $statusPagePayload;
        if ($statusPageResponse && (!is_array($statusPage) || $statusPage === [])) {
            return $invalidPayload('status page', $statusPagePayload, $statusPageResponse);
        }
        $incidents = $extractList($incidentPayload, 'items');
        if ($incidents === null) return $invalidPayload('incidents', $incidentPayload, $incidentResponse);
        $maintenances = $extractList($maintenancePayload, 'data');
        if ($maintenances === null) return $invalidPayload('maintenance', $maintenancePayload, $maintenanceResponse);
        $statusPageUrl = $config['statusPageUrl'] !== '' ? $config['statusPageUrl'] : null;
        $mappedIds = array_fill_keys($config['monitorIds'], true);
        $overlapsMappedMonitor = static function (array $item) use ($monitorIdsFrom, $mappedIds): bool {
            foreach ($monitorIdsFrom($item) as $id) if (isset($mappedIds[$id])) return true;
            return false;
        };
        $incidents = array_values(array_filter($incidents, static fn($item): bool => is_array($item) && $overlapsMappedMonitor($item)));
        $maintenances = array_values(array_filter($maintenances, static fn($item): bool => is_array($item) && $overlapsMappedMonitor($item)));

        $validateDateFields = static function (array $item, array $startFields, array $endFields, string $resource) use ($invalidPayload) {
            $start = null;
            foreach ($startFields as $field) {
                if (array_key_exists($field, $item) && $item[$field] !== null && $item[$field] !== '') {
                    $start = $item[$field];
                    break;
                }
            }
            $end = null;
            foreach ($endFields as $field) {
                if (array_key_exists($field, $item) && $item[$field] !== null && $item[$field] !== '') {
                    $end = $item[$field];
                    break;
                }
            }
            if (!is_string($start) || $start === '' || strtotime($start) === false) {
                return $invalidPayload(
                    $resource,
                    $item,
                    $resource === 'incidents' ? $incidentResponse : $maintenanceResponse,
                    'An item is missing a valid start date. Expected one of: ' . implode(', ', $startFields) . '.'
                );
            }
            if ($end !== null && (!is_string($end) || ($end !== '' && strtotime($end) === false))) {
                return $invalidPayload(
                    $resource,
                    $item,
                    $resource === 'incidents' ? $incidentResponse : $maintenanceResponse,
                    'An item contains an invalid end date. Expected one of: ' . implode(', ', $endFields) . '.'
                );
            }

            return null;
        };
        foreach ($incidents as $item) {
            if ($validationError = $validateDateFields($item, ['startedAt', 'startAt'], ['endedAt', 'endAt'], 'incidents')) {
                return $validationError;
            }
        }
        foreach ($maintenances as $item) {
            if ($validationError = $validateDateFields($item, ['startAt', 'startedAt'], ['endAt', 'endedAt'], 'maintenance')) {
                return $validationError;
            }
        }

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
            $startValue = $item['startedAt'] ?? $item['startAt'] ?? '';
            $endValue = $item['endedAt'] ?? $item['endAt'] ?? '';
            $startAt = strtotime($startValue);
            $endAt = strtotime($endValue);
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
                if ($overlaps($item['startedAt'] ?? $item['startAt'] ?? null, $item['endedAt'] ?? $item['endAt'] ?? null, $start, $end)) {
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
