<?php

namespace Pterodactyl\Http\Controllers\Admin\Extensions\Uptimekit;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\Rule;
use Illuminate\View\Factory as ViewFactory;
use Illuminate\View\View;
use Pterodactyl\BlueprintFramework\Libraries\ExtensionLibrary\Admin\BlueprintAdminLibrary as BlueprintExtensionLibrary;
use Pterodactyl\Http\Controllers\Controller;
use Pterodactyl\Http\Requests\Admin\AdminFormRequest;

class UptimekitExtensionController extends Controller
{
    public function __construct(
        private ViewFactory $view,
        private BlueprintExtensionLibrary $blueprint,
    ) {}

    public function index(): View
    {
        return $this->view->make(
            'admin.extensions.uptimekit.index',
            [
                'root' => '/admin/extensions/uptimekit',
                'blueprint' => $this->blueprint,
                'apiUrl' => $this->blueprint->dbGet('uptimekit', 'apiUrl') ?? '',
                'organizationSlug' => $this->blueprint->dbGet('uptimekit', 'organizationSlug') ?? '',
                'apiKeyConfigured' => (string) ($this->blueprint->dbGet('uptimekit', 'apiKey') ?? '') !== '',
                'allowInsecureApiUrl' => (bool) ($this->blueprint->dbGet('uptimekit', 'allowInsecureApiUrl') ?? false),
                'statusPageId' => $this->blueprint->dbGet('uptimekit', 'statusPageId') ?? '',
                'serverMonitorMappings' => $this->blueprint->dbGet('uptimekit', 'serverMonitorMappings') ?? '{}',
            ]
        );
    }

    public function update(UptimekitSettingsFormRequest $request): RedirectResponse
    {
        foreach ($request->only(['apiUrl', 'organizationSlug', 'statusPageId']) as $key => $value) {
            $this->blueprint->dbSet('uptimekit', $key, $value);
        }
        $this->blueprint->dbSet(
            'uptimekit',
            'allowInsecureApiUrl',
            $request->boolean('allowInsecureApiUrl') ? '1' : '0'
        );
        if ($request->filled('apiKey')) {
            $this->blueprint->dbSet('uptimekit', 'apiKey', $request->input('apiKey'));
        }

        $mapping = [];
        $serverUuids = $request->input('mappingServerUuids', []);
        $monitorIds = $request->input('mappingMonitorIds', []);
        $enforceStops = $request->input('mappingEnforceStop', []);
        foreach ($serverUuids as $index => $serverUuid) {
            $serverUuid = trim((string) $serverUuid);
            $monitors = array_values(array_filter(array_map('trim', explode(',', (string) ($monitorIds[$index] ?? '')))));
            if ($serverUuid !== '' && count($monitors) > 0) {
                $mapping[$serverUuid] = [
                    'monitorIds' => $monitors,
                    'enforceStop' => (string) ($enforceStops[$index] ?? '1') === '1',
                ];
            }
        }
        $this->blueprint->dbSet('uptimekit', 'serverMonitorMappings', json_encode($mapping));

        return redirect()->route('admin.extensions.uptimekit.index');
    }

    public function post(Request $request): RedirectResponse
    {
        $config = [
            'apiUrl' => rtrim(trim((string) ($request->input('apiUrl') ?: $this->blueprint->dbGet('uptimekit', 'apiUrl'))), '/'),
            'organizationSlug' => trim((string) ($request->input('organizationSlug') ?: $this->blueprint->dbGet('uptimekit', 'organizationSlug'))),
            'apiKey' => (string) ($request->input('apiKey') ?: $this->blueprint->dbGet('uptimekit', 'apiKey')),
            'statusPageId' => trim((string) ($request->input('statusPageId') ?: $this->blueprint->dbGet('uptimekit', 'statusPageId'))),
            'allowInsecureApiUrl' => $request->boolean('allowInsecureApiUrl'),
        ];
        $failure = static function (string $message): RedirectResponse {
            return redirect()->route('admin.extensions.uptimekit.index')->with('uptimekitTest', [
                'ok' => false,
                'message' => $message,
            ]);
        };

        if ($config['apiUrl'] === '' || $config['organizationSlug'] === '' || $config['apiKey'] === '' || $config['statusPageId'] === '') {
            return $failure('Enter the API URL, organization slug, API key, and status page ID before testing.');
        }
        $scheme = strtolower((string) parse_url($config['apiUrl'], PHP_URL_SCHEME));
        if ($scheme !== 'https' && !($config['allowInsecureApiUrl'] && $scheme === 'http')) {
            return $failure('The API URL must use HTTPS unless insecure HTTP is enabled.');
        }
        $responseDetail = static function ($response) use ($config): string {
            $contentType = method_exists($response, 'header')
                ? (string) $response->header('Content-Type')
                : '';
            $body = trim((string) $response->body());
            $body = str_replace($config['apiKey'], '[redacted]', $body);
            $body = preg_replace('/Bearer\s+[A-Za-z0-9._~+\/=\-]+/i', 'Bearer [redacted]', $body);
            $isHtml = str_contains(strtolower($contentType), 'text/html')
                || str_starts_with(strtolower(ltrim($body)), '<!doctype html')
                || str_starts_with(strtolower(ltrim($body)), '<html');
            $body = $body === '' ? '<empty>' : mb_substr($body, 0, 1000) . (mb_strlen($body) > 1000 ? '…' : '');

            return ($isHtml
                ? ' The configured URL returned the UptimeKit web application instead of the JSON API. Set API URL to the API base, usually ending in /api/rpc, not the dashboard URL.'
                : '') . ' Content-Type: ' . ($contentType !== '' ? $contentType : '<missing>') . '. Response body: ' . $body;
        };

        try {
            $response = Http::withToken($config['apiKey'])
                ->acceptJson()
                ->withHeaders(['X-Organization-Slug' => $config['organizationSlug']])
                ->get($config['apiUrl'] . '/status-pages/' . rawurlencode($config['statusPageId']));
        } catch (ConnectionException $exception) {
            return $failure('Could not connect to UptimeKit: ' . $exception->getMessage());
        }

        $payload = $response->json();
        if ($response->failed()) {
            $detail = is_array($payload) ? ($payload['message'] ?? $payload['error'] ?? null) : null;
            $message = 'UptimeKit returned HTTP ' . $response->status() . '.';
            if (is_scalar($detail) && trim((string) $detail) !== '') $message .= ' ' . trim((string) $detail);
            $message .= $responseDetail($response);

            return $failure($message);
        }
        if (!is_array($payload) || $payload === []) {
            return $failure('UptimeKit returned HTTP ' . $response->status() . ' but the status page response was empty or not a JSON object.' . $responseDetail($response));
        }
        if (isset($payload['data']) && (!is_array($payload['data']) || $payload['data'] === [])) {
            return $failure('UptimeKit returned HTTP ' . $response->status() . ' but its status page data was empty.' . $responseDetail($response));
        }

        return redirect()->route('admin.extensions.uptimekit.index')->with('uptimekitTest', [
            'ok' => true,
            'message' => 'UptimeKit responded successfully for the configured status page.',
        ]);
    }
}

class UptimekitSettingsFormRequest extends AdminFormRequest
{
    protected function failedValidation(Validator $validator): void
    {
        // Never flash a submitted API key into session-backed old input.
        $this->request->remove('apiKey');
        parent::failedValidation($validator);
    }

    public function rules(): array
    {
        return [
            'apiUrl' => [
                'required',
                'url',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    $scheme = strtolower((string) parse_url((string) $value, PHP_URL_SCHEME));
                    $allowedSchemes = $this->boolean('allowInsecureApiUrl') ? ['https', 'http'] : ['https'];
                    if (!in_array($scheme, $allowedSchemes, true)) {
                        $fail($this->boolean('allowInsecureApiUrl')
                            ? 'The API URL must use HTTP or HTTPS.'
                            : 'The API URL must use HTTPS unless insecure HTTP is explicitly enabled.');
                    }
                },
            ],
            'organizationSlug' => ['required', 'string', 'max:255'],
            'apiKey' => [
                Rule::requiredIf(fn(): bool => (string) (
                    app(BlueprintExtensionLibrary::class)->dbGet('uptimekit', 'apiKey') ?? ''
                ) === ''),
                'nullable',
                'string',
                'max:2048',
            ],
            'allowInsecureApiUrl' => ['required', 'boolean'],
            'statusPageId' => ['required', 'string', 'max:255'],
            'mappingServerUuids' => ['nullable', 'array'],
            'mappingServerUuids.*' => ['nullable', 'string', 'max:255'],
            'mappingMonitorIds' => ['nullable', 'array'],
            'mappingMonitorIds.*' => ['nullable', 'string', 'max:2048'],
        ];
    }

    public function attributes(): array
    {
        return [
            'apiUrl' => 'API URL',
            'organizationSlug' => 'organization slug',
            'apiKey' => 'API key',
            'allowInsecureApiUrl' => 'insecure HTTP developer option',
            'statusPageId' => 'status page ID',
            'mappingServerUuids' => 'server UUID mappings',
            'mappingMonitorIds' => 'monitor ID mappings',
        ];
    }
}
