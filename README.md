<div align="center">
  <img src="assets/uptimekit-maintenance-logo.svg" alt="UptimeKit logo" width="120" height="120">

  <h1>UptimeKit Integration for Pterodactyl</h1>

  <p>
    Manage maintenance windows and view service availability directly from the Pterodactyl panel.
  </p>
</div>

## What it offers

This integration connects Pterodactyl servers to UptimeKit monitors and status pages. Panel users can manage maintenance for the server they are viewing, while administrators control which UptimeKit monitors belong to each server.
- Create, edit, and delete maintenance windows from the server console.
- Show scheduled, active, and completed windows with their title, description, and time range.
- Display availability for the last 24 hours, 7 days, or 28 days.
- Show an uptime percentage and a color-coded history of operational periods, degraded service, outages, and maintenance.
- Open the matching public UptimeKit status page from the server view.
- Associate one Pterodactyl server with one or more UptimeKit monitors.
- Keep maintenance history scoped to the mapped monitors for each server.

## Maintenance windows in the server view

Once a server is mapped to UptimeKit monitors, a Maintenance button appears in the server console. It opens a single view for the server's maintenance schedule.

Users can create a window with:

- A title
- An optional description
- A start date and time
- An end date and time

Active windows are clearly marked. Scheduled and completed windows remain available for review, with recent history kept visible in the panel for up to 30 days.

Editing a window updates its time range in UptimeKit. Deleting a window removes it from the connected UptimeKit maintenance schedule after confirmation.

## Controlled server stops

Each server mapping has its own stop policy. When the policy is required, the panel disables the Stop and Kill controls until the server has an active maintenance window.

This lets teams connect operational access to their maintenance process. Servers can still use a relaxed policy when stopping should remain available at any time.

## Availability at a glance

The server view includes an availability panel for mapped monitors. Users can switch between three time ranges:

- 24 hours
- 7 days
- 28 days

The history bar separates normal operation, degraded service, partial outages, major outages, and maintenance. Hovering over a period shows its time range and related incident or maintenance title. The calculated uptime percentage excludes planned maintenance windows.

When a public status page is available, an external link opens it in a new tab for a complete service overview.

## Panel setup

An administrator configures the integration from the Pterodactyl extension settings page:

1. Enter the UptimeKit API URL.
2. Enter the UptimeKit organization slug.
3. Add an UptimeKit API key with permission to read and manage maintenance windows.
4. Enter the UptimeKit status page ID.
5. Add a mapping for each Pterodactyl server.
6. Enter one or more UptimeKit monitor IDs for each server.
7. Choose whether the server's Stop and Kill controls require an active maintenance window.

After saving, the configured server views load their maintenance windows and availability directly from UptimeKit.

## A focused workflow for operations teams

The integration keeps the maintenance workflow close to the server controls that operators already use. Planned work can be scheduled before a change, the server can be protected while maintenance is active, and recent availability can be checked without leaving Pterodactyl.
