<?php

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response as HttpResponse;
use Pterodactyl\BlueprintFramework\Libraries\ExtensionLibrary\Admin\BlueprintAdminLibrary;
use Pterodactyl\Models\Server;

return [
    'configuration' => static function (BlueprintAdminLibrary $blueprint, Server $server): array {
        $serverId = (string) $server->uuid;
        $mappings = json_decode(
            (string) ($blueprint->dbGet('uptimekit', 'serverMonitorMappings') ?? '{}'),
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
            'statusPageId' => (string) ($blueprint->dbGet('uptimekit', 'statusPageId') ?? ''),
            'apiUrl' => rtrim((string) ($blueprint->dbGet('uptimekit', 'apiUrl') ?? ''), '/'),
            'allowInsecureApiUrl' => (bool) ($blueprint->dbGet('uptimekit', 'allowInsecureApiUrl') ?? false),
            'organizationSlug' => (string) ($blueprint->dbGet('uptimekit', 'organizationSlug') ?? ''),
            'apiKey' => (string) ($blueprint->dbGet('uptimekit', 'apiKey') ?? ''),
        ];
    },

    'upstreamError' => static function (HttpResponse $response, array $config, string $resource = 'request') {
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
        ], static fn($value): bool => $value !== null && $value !== ''), $status >= 500 ? 502 : $status);
    },

    'maintenanceForClient' => static function (array $item): array {
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
    },

    'apiConfigurationError' => static function (array $config) {
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
    },

    'connectionError' => static function (ConnectionException $exception, array $config) {
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
    },

    'monitorIdsFrom' => static function (array $item): array {
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
    },
];
