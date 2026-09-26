<?php

namespace Pterodactyl\Http\Controllers\Admin\Extensions\uptimekitmaintenancetoggle;

use Illuminate\Http\RedirectResponse;
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
                'apiKey' => $this->blueprint->dbGet('uptimekitmaintenancetoggle', 'apiKey') ?? '',
                'statusPageId' => $this->blueprint->dbGet('uptimekitmaintenancetoggle', 'statusPageId') ?? '',
                'serverMonitorMappings' => $this->blueprint->dbGet('uptimekitmaintenancetoggle', 'serverMonitorMappings') ?? '{}',
            ]
        );
    }

    public function update(UptimekitmaintenancetoggleSettingsFormRequest $request): RedirectResponse
    {
        foreach ($request->only(['apiUrl', 'organizationSlug', 'apiKey', 'statusPageId']) as $key => $value) {
            $this->blueprint->dbSet('uptimekitmaintenancetoggle', $key, $value);
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
    public function rules(): array
    {
        return [
            'apiUrl' => ['required', 'url'],
            'organizationSlug' => ['required', 'string', 'max:255'],
            'apiKey' => ['required', 'string', 'max:2048'],
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
            'statusPageId' => 'status page ID',
            'mappingServerUuids' => 'server UUID mappings',
            'mappingMonitorIds' => 'monitor ID mappings',
        ];
    }
}
