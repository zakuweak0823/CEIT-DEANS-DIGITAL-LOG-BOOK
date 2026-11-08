# Changelog

## Unreleased

- Displayed both the date and time in Borrowed Items report timestamps.
- Simplified the document tracker to a status page and added a dedicated
  university-reference action on outgoing and released document rows.
- Kept document references and priority controls visible in the incoming and
  outgoing tables, wrapping long references instead of widening the table.
- Generated date-based CEIT references with a random numeric suffix for new
  documents while preserving previously issued references.
- Fixed mobile QR image selection, decoding of large images, and tracker links
  opened from a submission on the mobile development server.
- Added an optional university tracking reference to outgoing documents and
  their public tracker, separate from the existing CEIT reference.
- Suggested editable tracking details for status changes and release, and
  replaced successful admin outcome dialogs with top-of-page notifications.
- Fixed resending failed SMS logs stored with the PhilSMS 63 country prefix.
- Centered admin table headings and date values in the bulk and borrowed lists.
- Show the existing sort buttons above sortable admin tables on all screen
  sizes, wrapping them on narrow screens.
- Matched the default admin table styling to Compact Mode and removed the
  shadow beside sticky action columns.
- Hid document tracker record numbers from the address bar while keeping
  per-tab refresh and session-checked access.
- Redirected browser navigation to known page files to their public URLs, so
  opening a page from the editor no longer shows a folder-access error.
- Removed duplicate root PHP wrappers; a shared router preserves existing public
  URLs while implementations remain in `pages/`, `api/`, and `includes/`.
  Historical copies now load shared dependencies directly.
- Organized mobile image and audio assets, and extracted standalone admin
  footer and page scripts into browser JavaScript files.
- Split mobile status presentation, record helpers, and the status panel into
  focused modules; moved mobile dashboard query helpers into shared PHP code.
- Organized the browser QR bundle source under `tools/` and documented the
  purpose of routing, historical copies, and local files.
- Moved the default private SMS configuration to the protected `config` folder,
  retaining the previous external location as a compatibility fallback.
- Changed mobile document status tiles to count today's documents without
  changing saved statuses, and added a Super Admin database backup download.
- Improved spacing and accessibility of the SMS compose button, and blocked
  direct web access to server-only backup, configuration, database and tool
  directories.
- Added a 15-minute account-wide sign-in cooldown after five failed password
  attempts, shared between the admin portal and mobile API.
- Removed QR tokens from tracker address bars after opening a link, while
  retaining session-bound access for refreshes.
- Added public tracking details to staff status changes and release actions in
  the admin portal, including bulk submissions and their linked documents.
- Allowed staff to mark incoming documents from any submitter as priority.
- Removed university-logo frames and added status explanations and table sorting
  to the admin workspace, including Borrowed Items and reports.
- Replaced the table sort dropdowns with sortable column headings, including
  accessible heading controls for narrow-screen card layouts.
- Moved PhilSMS configuration and the SMS enable switch to a private server
  file or environment variables, and removed provider and SMTP configuration
  from the portal.
  Existing database credentials must be removed separately after migration.
- Removed the "Use the mobile-style workspace" description from the
  administrator menu. The Compact Mode switch and its Settings counterpart
  remain available.
- Organized PHP page, API, and shared implementations into `pages/`, `api/`,
  and `includes/`; direct web access to implementation folders is blocked.
- Moved the dashboard-specific inline stylesheet into
  `assets/css/dashboard.css`.
- Changed the visible mobile app's automatic refresh interval from 3 to
  15 seconds to reduce repeated API requests. Pull-to-refresh, reconnect, and
  returning to the app still trigger a refresh.
- Updated setup and architecture documentation for the reorganized files.

For earlier changes, see [Patch History](docs/Patch%20V1.00.md).
