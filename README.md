<div align="center">
  <img src="assets/uptimekit.svg" alt="UptimeKit logo" width="120" height="120">

  <h1>UptimeKit Integration for Pterodactyl</h1>

  <p>
    Manage maintenance windows and view service availability directly from the Pterodactyl panel.
  </p>
</div>

## Features

This integration connects Pterodactyl servers to UptimeKit monitors and status pages. Administrators map monitors to individual servers, while server members manage maintenance and view availability from the server console.

**Maintenance windows.** Create, edit, delete, and review scheduled, active, and completed windows.

**Availability.** View uptime percentages and color-coded history for the last 24 hours, 7 days, or 28 days.

**Status page.** Open the mapped public UptimeKit status page from the server view.

**Monitor mappings.** Scope maintenance and incident history to the monitors assigned to each server.

**Stop policy.** Optionally require an active maintenance window before the panel enables Stop and Kill.

## Panel setup

Configure these values from the Pterodactyl extension settings page. Mapping fields can be repeated for each server.

| Configuration | Allowed values | Function | Important notes |
| --- | --- | --- | --- |
| API URL | HTTPS URL; standard deployments usually end in `/api/rpc` | UptimeKit JSON API base URL | Do not use the dashboard or public status-page URL. HTTP is allowed only when the insecure developer option is enabled. |
| Organization slug | Non-empty string | Selects the UptimeKit organization | The organization owns the configured status page and monitors. |
| API key | UptimeKit API key | Authenticates API requests | Must be able to read incidents, maintenance windows, and status pages, and create, update, and delete maintenance windows. |
| Insecure HTTP | Enabled or disabled | Allows an HTTP API URL | Keep disabled in production; HTTP sends the API key without transport encryption. |
| Status page API ID | Non-empty UptimeKit status page ID | Selects the status page used for maintenance and incident data | This is not the organization slug, public status-page slug, or public URL. |
| Server UUID | Pterodactyl server UUID | Identifies the server receiving a mapping | Add one row per server mapping. |
| Monitor IDs | One or more IDs separated by commas | Assigns UptimeKit monitors to the server | Maintenance and incident history is filtered through these IDs. |
| Public status page URL | Optional HTTP or HTTPS URL | Provides the link opened from the server view | This is a public link, not the API status page ID. |
| Stop policy | Required or relaxed | Controls whether the panel enables Stop and Kill only during active maintenance; malformed availability data also keeps them disabled | This is a browser UI aid, not server-side authorization. |

After entering the values, use **Test UptimeKit connection**, then save the configuration.

## Using the integration

After a server has a monitor mapping, a **Maintenance** button appears in its console view. Users can create a window with a title, optional description, start time, and end time. Times are entered in the browser's local timezone.

Server members can view maintenance windows. Creating, editing, or deleting them requires the server's `control.stop` permission. Maintenance data is filtered to the mapped monitors; an existing window can only be changed when all of its associated monitors belong to that server's mapping.

Completed windows remain visible for up to 30 days. The availability panel provides 24-hour, 7-day, and 28-day views. Its history bar distinguishes operational periods, degraded service, partial outages, major outages, and maintenance. Uptime is calculated from incidents and maintenance windows, with planned maintenance excluded from the percentage.

When a public status-page URL is configured, the availability panel includes a link to it.

## Permissions and stop policy

The stop policy disables the visible **Stop** and **Kill** controls until the server has an active maintenance window. It also keeps them disabled when the UptimeKit availability response is malformed. Native Pterodactyl disabled states and hints remain unchanged. This is a panel UI workflow aid, not a server-side authorization boundary: users or API clients that already have Pterodactyl's `control.stop` permission can still call the underlying power API directly. Use Pterodactyl permissions when stopping a server must be prevented as an access-control rule.

## Troubleshooting

- **The connection test returns HTML:** the configured URL is probably the UptimeKit dashboard. Use the JSON API base URL, usually ending in `/api/rpc`.
- **The API returns 401 or 403:** check the API key and its permissions.
- **The Maintenance button does not appear:** check that the server UUID and at least one monitor ID are mapped correctly.
- **A server cannot see a window:** confirm that the window belongs to the configured status page and has at least one mapped monitor.
