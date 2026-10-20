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

## V1.11 — Clear mobile camera security guidance

**Git commit:** [`a65ca54`](https://github.com/zakuweak0823/CEIT-DEANS-DIGITAL-LOG-BOOK/commit/a65ca54)

- Check for an insecure context before attempting camera access and show
  guidance tailored to phones using an HTTP LAN address.
- Explain that `localhost` on a phone points to the phone itself, and that
  camera use needs an HTTPS address trusted by the phone.
- Point admins to the built-in QR-image upload fallback when HTTPS is not
  available.
- Document the mobile camera HTTPS requirement in the README.

## V1.12 — Local HTTPS for phone QR scanning

**Git commit:** [`5453bb9`](https://github.com/zakuweak0823/CEIT-DEANS-DIGITAL-LOG-BOOK/commit/5453bb9). Certificate files are excluded from Git.

- Configured this computer's Apache HTTPS virtual host with a private
  development CA and a server certificate matching its current LAN IP.
- Made the public CA certificate downloadable locally so it can be installed
  as a trusted CA on a phone connected to the same Wi-Fi.
- Documented Android and iPhone trust steps, the HTTPS app address, and the
  CA fingerprint in the README.
- Kept the CA private key and Apache server private key outside the repository;
  the public CA download file is ignored by Git.

## V1.13 — Certificate download in Admin Settings

**Git commit:** [`28d774d`](https://github.com/zakuweak0823/CEIT-DEANS-DIGITAL-LOG-BOOK/commit/28d774d). The certificate file is excluded from Git.

- Added a **Download certificate** button to Admin Settings for downloading
  the public local development CA certificate.
- Show a clear availability message if the development certificate file is
  not present on the server.
- Updated the README instructions to download the certificate from Settings
  before transferring it to a phone.

## V1.14 — Clear submitter fields and reliable QR tracking

**Git commit:** [`e51c23c`](https://github.com/zakuweak0823/CEIT-DEANS-DIGITAL-LOG-BOOK/commit/e51c23c)

- Renamed the Student/Faculty category to **Submitter type** in the mobile
  form and shared Admin Settings labels, distinguishing it from the required
  **Name of person submitting**.
- Clarified the admin-only **Submitting department or office** field as
  optional for documents delivered on behalf of a department; individual
  submissions need only the person's name and submitter type.
- Kept the submitting department optional when editing incoming documents,
  and stopped requiring the legacy sender value for saving an incoming record.
- Fixed the standalone tracker to build its status API path correctly when
  the project is installed in a named XAMPP `htdocs` subfolder, preventing
  cross-origin fetch failures on phones.
- Expanded the README and Windows setup troubleshooting with the submitter
  field distinctions and the separate Apache tracker/Vite mobile URLs.

## V1.15 — Automatic LAN camera certificates

**Git commit:** [`b1f2fbd`](https://github.com/zakuweak0823/CEIT-DEANS-DIGITAL-LOG-BOOK/commit/b1f2fbd)

- Added a Windows `predev:lan` setup step that finds XAMPP OpenSSL, detects
  the active Private-network IP, and creates a host-specific CA and matching
  TLS certificate when needed.
- Keeps CA and server private keys in the local XAMPP Apache configuration
  directories; only the public CA certificate is copied to the ignored
  project-root download path used by Admin Settings.
- Reuses an existing valid certificate for the current LAN address without
  requiring the CA private key to be present on the development computer.
- Documented first-run phone trust steps and clarified that npm audit warnings
  do not require `npm audit fix --force`.
- Clarified that the QR Tracker base URL must be the new host's Apache project
  URL (not Vite port 5173); update this database setting after moving the
  project and importing its records.

## V1.16 — Admin network setup helper

**Git commit:** [`69b5916`](https://github.com/zakuweak0823/CEIT-DEANS-DIGITAL-LOG-BOOK/commit/69b5916)

- Added an Admin Settings download button for a Windows helper that refreshes
  LAN certificates, starts XAMPP services, configures firewall access on
  Private networks only, and starts Vite when its dependencies are installed.
- Automatically replaces an unavailable CA signing key when the current
  certificate no longer matches the network, retaining the old public CA
  file as a backup and warning that phones must trust the replacement CA.
- Added a one-click way to fill the Tracker base URL from the address used to
  open Admin Settings, with guidance to use the host's Wi-Fi address for phone
  access instead of `localhost`.
- Documented network conditions that still require manual action, including
  phone CA trust, Public Wi-Fi profiles, and guest-network isolation.

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
