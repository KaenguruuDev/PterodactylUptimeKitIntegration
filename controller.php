<?php

namespace Pterodactyl\Http\Controllers\Admin\Extensions\Uptimekit;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\RedirectResponse;
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
