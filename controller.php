<?php

namespace Pterodactyl\Http\Controllers\Admin\Extensions\uptimekitmaintenancetoggle;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\Rule;
use Illuminate\View\Factory as ViewFactory;
use Illuminate\View\View;
use Pterodactyl\BlueprintFramework\Libraries\ExtensionLibrary\Admin\BlueprintAdminLibrary as BlueprintExtensionLibrary;
use Pterodactyl\Http\Controllers\Controller;
use Pterodactyl\Http\Requests\Admin\AdminFormRequest;

class UptimekitmaintenancetoggleExtensionController extends Controller
{
    public function __construct(
        private ViewFactory $view,
        private BlueprintExtensionLibrary $blueprint,
    ) {}

    public function index(): View
    {
        return $this->view->make(
            'admin.extensions.uptimekitmaintenancetoggle.index',
            [
                'root' => '/admin/extensions/uptimekitmaintenancetoggle',
                'blueprint' => $this->blueprint,
                'apiUrl' => $this->blueprint->dbGet('uptimekitmaintenancetoggle', 'apiUrl') ?? '',
                'organizationSlug' => $this->blueprint->dbGet('uptimekitmaintenancetoggle', 'organizationSlug') ?? '',
                'apiKeyConfigured' => (string) ($this->blueprint->dbGet('uptimekitmaintenancetoggle', 'apiKey') ?? '') !== '',
                'allowInsecureApiUrl' => (bool) ($this->blueprint->dbGet('uptimekitmaintenancetoggle', 'allowInsecureApiUrl') ?? false),
                'statusPageId' => $this->blueprint->dbGet('uptimekitmaintenancetoggle', 'statusPageId') ?? '',
                'serverMonitorMappings' => $this->blueprint->dbGet('uptimekitmaintenancetoggle', 'serverMonitorMappings') ?? '{}',
            ]
        );
    }

    public function update(UptimekitmaintenancetoggleSettingsFormRequest $request): RedirectResponse
    {
        foreach ($request->only(['apiUrl', 'organizationSlug', 'statusPageId']) as $key => $value) {
            $this->blueprint->dbSet('uptimekitmaintenancetoggle', $key, $value);
        }
        $this->blueprint->dbSet(
            'uptimekitmaintenancetoggle',
            'allowInsecureApiUrl',
            $request->boolean('allowInsecureApiUrl') ? '1' : '0'
        );
        if ($request->filled('apiKey')) {
            $this->blueprint->dbSet('uptimekitmaintenancetoggle', 'apiKey', $request->input('apiKey'));
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
        $this->blueprint->dbSet('uptimekitmaintenancetoggle', 'serverMonitorMappings', json_encode($mapping));

        return redirect()->route('admin.extensions.uptimekitmaintenancetoggle.index');
    }
}

class UptimekitmaintenancetoggleSettingsFormRequest extends AdminFormRequest
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
                Rule::requiredIf(fn (): bool => (string) (
                    app(BlueprintExtensionLibrary::class)->dbGet('uptimekitmaintenancetoggle', 'apiKey') ?? ''
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
