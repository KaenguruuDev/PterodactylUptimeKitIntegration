@php
  $decodedMappings = json_decode($serverMonitorMappings, true);
  $mappingRows = [];
  if (is_array($decodedMappings)) {
    foreach ($decodedMappings as $serverUuid => $mapping) {
      $isStructuredMapping = is_array($mapping) && array_key_exists('monitorIds', $mapping);
      $monitorIds = $isStructuredMapping ? $mapping['monitorIds'] : $mapping;
      $mappingRows[] = [
        'serverUuid' => $serverUuid,
        'monitorIds' => is_array($monitorIds) ? implode(', ', $monitorIds) : $monitorIds,
        'enforceStop' => $isStructuredMapping ? ($mapping['enforceStop'] ?? true) : true,
      ];
    }
  }
  if (count($mappingRows) === 0) $mappingRows[] = ['serverUuid' => '', 'monitorIds' => '', 'enforceStop' => true];
@endphp

<style>
  .uptimekit-config-box .form-group {
    margin-bottom: 20px;
  }

  .uptimekit-config-box .form-group:last-child {
    margin-bottom: 0;
  }

  .uptimekit-mapping-section {
    margin-top: 20px;
    padding-top: 18px;
    border-top: 1px solid currentColor;
  }

  .uptimekit-mapping-heading {
    margin: 0;
    font-weight: 600;
  }

  .uptimekit-mapping-list {
    display: grid;
    gap: 10px;
    margin-top: 10px;
  }

  .uptimekit-mapping-table-header {
    display: none;
    margin: 0;
  }

  .uptimekit-mapping-table-header label {
    margin-bottom: 5px;
    font-weight: 600;
  }

  .uptimekit-mapping-table-header .help-block {
    margin: 0;
  }

  .uptimekit-mapping-row {
    margin: 0;
    padding: 15px;
  }

  .uptimekit-mapping-row .form-group {
    margin-bottom: 0;
  }

  .uptimekit-mapping-row label {
    display: block;
    margin-bottom: 7px;
    font-size: 12px;
    font-weight: 600;
    text-transform: uppercase;
  }

  .uptimekit-mapping-row .mapping-enforce-stop-label {
    display: flex;
    align-items: center;
    min-height: 40px;
    margin: 0;
    font-size: 13px;
    font-weight: 400;
    text-transform: none;
    white-space: nowrap;
  }

  .uptimekit-mapping-row .mapping-enforce-stop {
    position: static;
    margin: 0 8px 0 0;
  }

  .uptimekit-mapping-actions {
    display: flex;
    justify-content: flex-end;
    align-items: flex-end;
  }

  .uptimekit-mapping-actions .btn {
    width: 40px;
    height: 40px;
    border-radius: 4px;
  }

  .uptimekit-add-button {
    margin-top: -4px;
  }

  @media (max-width: 767px) {
    .uptimekit-mapping-row {
      padding: 10px;
    }

    .uptimekit-mapping-actions {
      justify-content: flex-start;
    }

    .uptimekit-mapping-actions .btn {
      margin-top: 0;
    }
  }

  @media (min-width: 768px) {
    .uptimekit-mapping-table-header {
      display: block;
    }

    .uptimekit-mapping-row .mapping-column-label {
      display: none;
    }
  }
</style>

<div class="row">
  <div class="col-xs-12 col-lg-10 col-lg-offset-1">
    <div class="box uptimekit-config-box">
      <div class="box-header with-border">
        <h3 class="box-title">UptimeKit maintenance configuration</h3>
      </div>

      <form action="{{ $root }}" method="POST">
        <div class="box-body">
          <div class="form-group">
            <label for="apiUrl">API URL</label>
            <input id="apiUrl" name="apiUrl" type="url" class="form-control" value="{{ old('apiUrl', $apiUrl) }}" placeholder="https://api.example.com" required>
            <p class="help-block">The base URL of the UptimeKit API that the panel will call.</p>
          </div>

          <div class="form-group">
            <label for="organizationSlug">Organization slug</label>
            <input id="organizationSlug" name="organizationSlug" type="text" class="form-control" value="{{ old('organizationSlug', $organizationSlug) }}" placeholder="my-organization" required>
            <p class="help-block">The UptimeKit organization that owns the status page and monitors.</p>
          </div>

          <div class="form-group">
            <label for="apiKey">API key</label>
            <input id="apiKey" name="apiKey" type="password" class="form-control" value="{{ old('apiKey', $apiKey) }}" autocomplete="new-password" required>
            <p class="help-block">An UptimeKit API key with permission to read and manage maintenance windows.</p>
          </div>

          <div class="form-group">
            <label for="statusPageId">UptimeKit status page ID</label>
            <input id="statusPageId" name="statusPageId" type="text" class="form-control" value="{{ old('statusPageId', $statusPageId) }}" placeholder="status-page-id" required>
            <p class="help-block">The status page where maintenance windows for this panel should appear.</p>
          </div>

          <div class="form-group uptimekit-mapping-section">
            <div class="clearfix">
              <p class="uptimekit-mapping-heading pull-left">Server monitor mappings</p>
              <button type="button" id="add-mapping" class="btn btn-primary btn-xs pull-right uptimekit-add-button" title="Add server mapping" aria-label="Add server mapping"><i class="fa fa-plus"></i></button>
            </div>
            <p class="help-block">Map each Pterodactyl server to its UptimeKit monitors.</p>
            <div id="server-monitor-mappings" class="uptimekit-mapping-list">
              <div class="row uptimekit-mapping-table-header">
                <div class="col-sm-4">
                  <label>Server UUID</label>
                  <p class="help-block">The UUID of the Pterodactyl server.</p>
                </div>
                <div class="col-sm-4">
                  <label>Monitor IDs</label>
                  <p class="help-block">One or more UptimeKit monitor IDs, separated by commas.</p>
                </div>
                <div class="col-sm-3">
                  <label>Stop policy</label>
                  <p class="help-block">Blocks Stop/Kill until an active maintenance window exists. Uncheck to allow stopping anytime.</p>
                </div>
              </div>
              @foreach ($mappingRows as $mappingRow)
                <div class="row uptimekit-mapping-row well well-sm">
                  <div class="col-sm-4 form-group">
                    <label class="mapping-column-label">Server UUID</label>
                    <input name="mappingServerUuids[]" type="text" class="form-control" value="{{ $mappingRow['serverUuid'] }}" placeholder="Pterodactyl server UUID">
                  </div>
                  <div class="col-sm-4 form-group">
                    <label class="mapping-column-label">Monitor IDs</label>
                    <input name="mappingMonitorIds[]" type="text" class="form-control" value="{{ $mappingRow['monitorIds'] }}" placeholder="UptimeKit monitor ID(s), comma-separated">
                  </div>
                  <div class="col-sm-3 form-group">
                    <label class="mapping-column-label">Stop policy</label>
                    <input name="mappingEnforceStop[]" type="hidden" value="{{ $mappingRow['enforceStop'] ? '1' : '0' }}">
                    <label class="mapping-enforce-stop-label">
                      <input class="mapping-enforce-stop" type="checkbox" {{ $mappingRow['enforceStop'] ? 'checked' : '' }}>
                      Required
                    </label>
                  </div>
                  <div class="col-sm-1 uptimekit-mapping-actions">
                    <button type="button" class="btn btn-danger btn-sm remove-mapping" title="Remove server mapping" aria-label="Remove server mapping"><i class="fa fa-trash"></i></button>
                  </div>
                </div>
              @endforeach
            </div>
          </div>
        </div>

        <div class="box-footer">
          {{ csrf_field() }}
          <button type="submit" name="_method" value="PATCH" class="btn btn-primary">Save configuration</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
  document.getElementById('add-mapping').addEventListener('click', function () {
    var row = document.querySelector('.uptimekit-mapping-row').cloneNode(true);
    row.querySelectorAll('input').forEach(function (input) { input.value = ''; });
    row.querySelector('.mapping-enforce-stop').checked = true;
    row.querySelector('input[name="mappingEnforceStop[]"]').value = '1';
    document.getElementById('server-monitor-mappings').appendChild(row);
  });

  document.getElementById('server-monitor-mappings').addEventListener('change', function (event) {
    if (!event.target.classList.contains('mapping-enforce-stop')) return;
    event.target.closest('.uptimekit-mapping-row').querySelector('input[name="mappingEnforceStop[]"]').value = event.target.checked ? '1' : '0';
  });

  document.getElementById('server-monitor-mappings').addEventListener('click', function (event) {
    if (!event.target.closest('.remove-mapping')) return;
    var rows = document.querySelectorAll('.uptimekit-mapping-row');
    if (rows.length > 1) event.target.closest('.uptimekit-mapping-row').remove();
  });
</script>
