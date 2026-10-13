# Patch History — V1.00

This file records the feature and documentation patches discussed and applied
in the project chat. Patch sections are a readable release-history grouping;
they are not Git tags. Commit references are included where the corresponding
work was committed.

## V1.00 — Project and admin improvements

### Patch 1 — Project publication and mobile source

**Git commit:** `b82df29` — `Publish clean project sources`

- Published the project source to the private
  `zakuweak0823/CEIT-DEANS-DIGITAL-LOG-BOOK` repository with a clean root
  history.
- Included the mobile app source and supporting project folders while
  excluding dependencies and generated build output.
- Added ignore rules for local dependencies, generated files, and sensitive
  local data; removed the database dump from the published source snapshot.
- Preserved the PHP/MySQL web app, React/Vite mobile client, and Capacitor
  Android project.

### Patch 2 — Footer and README improvements

**Git commit:** `c8f3f47` — `Polish project README for GitHub`

- Added a branded, thin admin footer with online/offline status.
- Reworked the README with project features, technology badges, setup steps,
  mobile and Android instructions, project layout, security guidance, and
  troubleshooting.
- Added the repository description text in chat for use on GitHub.

The footer refinements were made before the clean source publication and are
part of the published source snapshot; they do not have a separate feature
commit.

### Patch 3 — Admin server recovery

**Git commit:** `bcd85eb` — `Add admin server recovery experience`

- Replaced an unhelpful database connection failure with a styled recovery
  page and retry guidance.
- Returned a retryable HTTP 503 response, using JSON for API clients and an
  HTML recovery page for browser requests.
- Added the `server_health.php` health endpoint.
- Added an authenticated admin outage notice with manual retry and automatic
  recovery checks.
- Distinguished browser-offline status from an unavailable office server.
- Documented the recovery behavior in the README.

### Patch 4 — Admin settings and Quick Access Mode

**Git commit:** `707b78e` — `Add admin settings and mobile compact mode`

- Added a dedicated admin Settings page and `/settings` route.
- Added browser-specific settings for activity polling, sound alerts, optional
  desktop alerts, and the floating pending-documents shortcut.
- Added Compact Mode and synchronized its preference between Settings, the
  admin menu, and browser tabs.
- Changed Compact Mode into a mobile-style workspace with a full-width layout,
  responsive content, touch-friendly controls, card-style tables, and
  mobile-style dialogs.
- Replaced the long bottom shortcut strip with a short primary navigation
  dock and a **More** bottom sheet containing the remaining admin sections.
- Lightened the Compact Mode theme and kept the desktop sidebar hidden while
  Compact Mode is enabled.
- Updated the README to explain the settings and mobile-style Compact Mode.

## Earlier chat requests included in the project snapshot

These requests were part of the same project conversation. They do not all
map to separate commits or a distinct versioned release:

- Created and iteratively improved the project README.
- Refined the admin footer to be thinner and better suited to narrow screens.
- Added and refined a floating pending-documents shortcut.
- Added a dedicated server-disconnected experience for the admin portal.
- Added admin preferences, then moved them into a separate Settings page.
- Added the option to hide or restore the floating pending-documents shortcut.
- Iterated on Compact Mode based on feedback: from tighter spacing, to quick
  access, to a whole-workspace mobile-style layout, and finally to a compact
  five-item dock with a **More** sheet.
- Committed and pushed the server-recovery patch and the admin-settings/mobile
  Compact Mode patch to `main`.

## Versioning note

`V1.00` is the initial consolidated patch-history document. Future project
changes can be added under a new version heading, with the related Git commit
hash when available.
