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

## V1.07 — Movable assistant and safe form help

**Git commit:** [`8fbda35`](https://github.com/zakuweak0823/CEIT-DEANS-DIGITAL-LOG-BOOK/commit/8fbda35)

- Moved the Admin Assistant launcher to a draggable side position, with
  position saved locally and keyboard arrow-key movement supported.
- Added a smooth on-demand assistant panel that stays out of the workspace
  until opened.
- Added opt-in field preparation: the assistant previews proposed values and
  waits for admin approval before filling supported visible fields. It never
  submits a form.
- Hid the CMD log control while Compact Mode is active and automatically
  stopped an active CMD log when switching into that mode.
- Updated the README and this patch history.

## V1.08 — Side-peeking assistant visibility setting

- Changed the Admin Assistant launcher to tuck behind the screen edge, leaving
  a clickable handle visible. Hovering, keyboard focus, or dragging reveals
  the full launcher.
- Added an Admin Assistant on/off switch to the admin Settings page.
- Saved the visibility preference in the browser and synchronized changes
  across open admin tabs. The assistant remains enabled by default.
- Updated the README patch summary.

## V1.09 — Compact Mode pastel palette and clearer switch

**Git commit:** [`f923104`](https://github.com/zakuweak0823/CEIT-DEANS-DIGITAL-LOG-BOOK/commit/f923104)

**Includes V1.08 — Side-peeking assistant visibility setting.**

- Replaced the gray-heavy Compact Mode surfaces with a softer mint and cream
  palette, keeping school green as the accent for actions and active navigation.
- Improved the Compact Mode switch with a larger, more distinct control and
  clear On/Off status badges in both Settings and the admin dropdown.
- Kept the switch state synchronized across the two controls and browser tabs.
- Added a Settings control to enable or hide the assistant side launcher, with
  browser-local storage and synchronization across open admin tabs.
- Improved the README with details about the Compact Mode appearance, controls,
  and browser-specific workspace options.

## V1.10 — Compact Mode quick actions and QR scanner

**Git commit:** [`5c1a9bc`](https://github.com/zakuweak0823/CEIT-DEANS-DIGITAL-LOG-BOOK/commit/5c1a9bc)

- Constrained Compact Mode quick actions to a balanced two-column grid with
  less stretched action cards.
- Kept the incoming-document form and dashboard quick-action dialogs within
  readable widths and usable viewport heights.
- Prevented embedded batch/scanner tools from inheriting Compact Mode's full
  workspace shell and bottom navigation dock, keeping QR scan controls visible.
- Updated the README with quick-action and scanner behavior.

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
- Added the in-browser Admin Assistant guide with suggested questions, common
  workflow directions, and direct links. It does not use an external AI
  service, access document contents, or persist chat history.
- Moved the assistant into a draggable side launcher. Its panel opens on
  demand with a smooth transition, and the launcher position is saved in the
  browser.
- Added opt-in form assistance: the admin reviews proposed values and approves
  before supported visible fields are filled. The assistant does not submit
  forms.
- Hid the CMD log control in Compact Mode; an active CMD log is also stopped
  when Compact Mode is enabled.
- Committed and pushed the server-recovery patch and the admin-settings/mobile
  Compact Mode patch to `main`.

## Versioning note

`V1.00` is the initial consolidated patch-history document. Future project
changes can be added under a new version heading, with the related Git commit
hash when available.
