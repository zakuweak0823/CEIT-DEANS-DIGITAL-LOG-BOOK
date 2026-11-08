# Changelog

## Unreleased

- Removed the "Use the mobile-style workspace" description from the
  administrator menu. The Compact Mode switch and its Settings counterpart
  remain available.
- Organized PHP page, API, and shared implementations into `pages/`, `api/`,
  and `includes/`. Root-level compatibility shims preserve existing URLs and
  direct web access to the implementation folders is blocked.
- Moved the dashboard-specific inline stylesheet into
  `assets/css/dashboard.css`.
- Changed the visible mobile app's automatic refresh interval from 3 to
  15 seconds to reduce repeated API requests. Pull-to-refresh, reconnect, and
  returning to the app still trigger a refresh.
- Updated setup and architecture documentation for the reorganized files.

For earlier changes, see [Patch History](docs/Patch%20V1.00.md).
