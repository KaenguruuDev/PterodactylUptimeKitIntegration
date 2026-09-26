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
  .uptimekit-mapping-list {
    display: grid;
    gap: 10px;
  }

  .uptimekit-mapping-row {
    margin: 0;
    padding: 12px;
    border: 1px solid #526579;
    border-left: 3px solid #3c8dbc;
    border-radius: 3px;
    background: #34495e;
  }

  .uptimekit-mapping-row .form-group {
    margin-bottom: 0;
  }

  .uptimekit-mapping-row label {
    margin-bottom: 5px;
    color: #f4f4f4;
    font-size: 12px;
    font-weight: 600;
    text-transform: uppercase;
  }

  .uptimekit-mapping-actions {
    padding-top: 25px;
  }

  @media (max-width: 767px) {
    .uptimekit-mapping-actions {
      padding-top: 10px;
    }
  }
</style>

<div class="row">
  <div class="col-xs-12 col-md-8">
    <div class="box">
      <div class="box-header with-border">
        <h3 class="box-title">UptimeKit maintenance configuration</h3>
      </div>

      <form action="{{ $root }}" method="POST">
        <div class="box-body">
          <div class="form-group">
            <label for="apiUrl">API URL</label>
            <input id="apiUrl" name="apiUrl" type="url" class="form-control" value="{{ old('apiUrl', $apiUrl) }}" placeholder="https://api.example.com" required>
          </div>

          <div class="form-group">
            <label for="organizationSlug">Organization slug</label>
            <input id="organizationSlug" name="organizationSlug" type="text" class="form-control" value="{{ old('organizationSlug', $organizationSlug) }}" placeholder="my-organization" required>
          </div>

          <div class="form-group">
            <label for="apiKey">API key</label>
            <input id="apiKey" name="apiKey" type="password" class="form-control" value="{{ old('apiKey', $apiKey) }}" autocomplete="new-password" required>
          </div>

          <div class="form-group">
            <label for="statusPageId">UptimeKit status page ID</label>
            <input id="statusPageId" name="statusPageId" type="text" class="form-control" value="{{ old('statusPageId', $statusPageId) }}" placeholder="status-page-id" required>
          </div>

          <div class="form-group">
            <div class="clearfix">
              <label class="pull-left">Server monitor mappings</label>
              <button type="button" id="add-mapping" class="btn btn-primary btn-xs pull-right" title="Add server mapping" aria-label="Add server mapping"><i class="fa fa-plus"></i></button>
            </div>
            <p class="help-block">Each card maps one Pterodactyl server to its UptimeKit monitor(s).</p>
            <div id="server-monitor-mappings" class="uptimekit-mapping-list">
              @foreach ($mappingRows as $mappingRow)
                <div class="row uptimekit-mapping-row">
                  <div class="col-sm-4 form-group">
                    <label>Server UUID</label>
                    <input name="mappingServerUuids[]" type="text" class="form-control" value="{{ $mappingRow['serverUuid'] }}" placeholder="Pterodactyl server UUID">
                  </div>
                  <div class="col-sm-4 form-group">
                    <label>Monitor IDs</label>
                    <input name="mappingMonitorIds[]" type="text" class="form-control" value="{{ $mappingRow['monitorIds'] }}" placeholder="UptimeKit monitor ID(s), comma-separated">
                  </div>
                  <div class="col-sm-2 form-group">
                    <label>Stop policy</label>
                    <input name="mappingEnforceStop[]" type="hidden" value="{{ $mappingRow['enforceStop'] ? '1' : '0' }}">
                    <label class="checkbox-inline" style="padding-left: 0; text-transform: none; font-weight: normal;">
                      <input class="mapping-enforce-stop" type="checkbox" {{ $mappingRow['enforceStop'] ? 'checked' : '' }}>
                      Require active window
                    </label>
                  </div>
                  <div class="col-sm-2 uptimekit-mapping-actions">
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
