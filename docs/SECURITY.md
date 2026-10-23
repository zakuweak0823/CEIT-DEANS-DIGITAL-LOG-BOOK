# Security Guide

This guide describes the security controls and deployment boundaries of the
CEIT Dean's Office Digital Logbook. It is operational guidance, not a security
certification or a substitute for an independent review.

## Security model

The system has three distinct access paths:

1. **Administrator portal** — PHP pages use an administrator session. Mutating
   form requests are checked for a session CSRF token. Super Admin account
   management is restricted by a database-verified role check; standard
   administrators retain the existing logbook access.
2. **Mobile logbook API** — Admin Settings can allow kiosk access without
   sign-in. The API supports bearer-token sign-in when kiosk access is disabled,
   but the current React/Vite mobile app has no sign-in screen and cannot use
   those tokens. Disabling kiosk access therefore blocks the current mobile app.
   API tokens are returned at sign-in, while their SHA-256 digests are stored in
   MySQL with an expiry time.
3. **Document and batch trackers** — A public status page accepts a long,
   random office-issued token. Anyone holding a valid token can see the limited
   status details returned by that tracker. This is a bearer link, not an
   administrator login.

These paths have different permissions. Hiding a link in the interface,
restricting CORS, or using an unguessable tracker URL does not replace access
control for the mobile API or admin portal.

## Controls present in the application

- **Admin sessions:** the PHP session cookie is configured as `HttpOnly` and
  `SameSite=Lax`; the `Secure` cookie flag is enabled when the request is
  served over HTTPS. The session ID is regenerated after successful admin
  sign-in. Sessions are bound to the current password hash so resetting an
  account password invalidates its existing portal sessions.
- **Request forgery checks:** admin POST requests use a per-session,
  cryptographically random CSRF token and a constant-time comparison.
- **Password storage:** new passwords use PHP's `password_hash()` and
  `password_verify()`. The legacy seeded plaintext password is upgraded to a
  hash when it is successfully used; it remains unsafe until changed. New
  administrator and reset passwords must be at least 12 characters.
- **Database access:** the shared query helper uses MySQLi prepared
  statements for bound values. Dynamic SQL identifiers still require
  allow-listing because placeholders only bind values.
- **Output encoding:** HTML-facing values are escaped with UTF-8 HTML entity
  encoding at output. Keep this encoding at the presentation boundary and
  apply context-appropriate encoding for JavaScript, URLs, and other formats.
- **Tracking tokens:** document and batch tracking tokens are generated from
  32 random bytes and represented as 64 hexadecimal characters. Tracker
  responses return status-oriented fields rather than admin pages.
- **Mobile authentication:** API bearer tokens expire after 30 days and only
  their SHA-256 digests are stored. The current React/Vite mobile app does not
  include a sign-in flow and uses the kiosk access setting.
- **Cross-origin requests:** the mobile API only returns CORS headers for a
  small configured set of local app origins and also supports native clients
  that omit an `Origin` header. CORS is a browser policy, not an
  authentication mechanism.
- **Repository hygiene:** ignore rules cover local environment files,
  configured database-export patterns, the local CA certificate, dependency
  folders, and generated build output. Check `git status` before committing;
  ignore rules do not remove files that are already tracked.

## Required setup before use

1. On a fresh installation, sign in with the initial local account
   (`admin` / `admin`) only for setup, then immediately set a unique, strong
   password in **Administrator accounts**. On an existing installation,
   back up the database and run
   [`database/promote_admin_to_super_admin.sql`](../database/promote_admin_to_super_admin.sql)
   once to grant the existing `admin` account Super Admin access; then sign in
   and immediately change its password. Do not reuse that password elsewhere.
2. Replace the default XAMPP database account with a dedicated MySQL user and
   a strong password. Grant only the database privileges the application
   needs.
3. Review **Admin Settings → Mobile logbook access**. Disable unauthenticated
   kiosk access unless every device that can reach the server is trusted to
   create and change records.
4. Keep the server on a trusted network. The Windows helper creates firewall
   rules for Private networks; do not approve those rules on an untrusted
   network or expose XAMPP, Apache, MySQL, or the development Vite server to
   the public internet.
5. Use properly configured HTTPS for remote access. The local XAMPP
   development CA is host-specific and is not a production certificate.
   Protect its private keys and never distribute them; only the public CA
   certificate may be installed on trusted phones.
6. Keep SMS/email provider credentials out of source files, Git commits,
   screenshots, and support logs. Treat the database and its backups as
   sensitive because application settings and logbook records may contain
   confidential information.
7. Configure a secure backup process for the database and test restoration.
   Store exports outside the web root and outside the Git repository, restrict
   access, and encrypt them using your organization's approved process.
8. Keep PHP, MySQL/MariaDB, Apache, Node dependencies, and the host operating
   system patched. Rebuild the web and Android clients from trusted source and
   review changes before deployment.

## Important limitations

- The seeded `admin` / `admin` account is public setup information. A fresh
  installation must not be left reachable with these credentials.
- Super Admins can create, reset, and remove standard administrator accounts.
  Administrators with a recovery phone can also reset a forgotten password
  themselves with a one-time SMS code (6 digits, 10 minutes, single use, locked
  after 5 wrong tries, requests throttled, codes never stored in the SMS logs).
  Without a recovery phone, a Super Admin must reset it. A Super Admin without
  a recovery phone needs the server owner to run
  `php tools/reset_admin_password.php <username>` (command line only) or use
  database access; the app does not send password-reset email. SMS recovery is
  only as strong as control of the recovery phone, so keep those numbers
  accurate and use phones only the account holder controls.
- Mobile kiosk mode is intentionally unauthenticated. When enabled, anyone
  able to reach the configured mobile API may use its kiosk workflows. A
  firewall or trusted network boundary is essential in that mode.
- The current React/Vite mobile app is kiosk-only. Turning kiosk access off
  blocks that app; its API sign-in endpoint is available to clients that
  implement bearer-token authentication.
- A tracking QR is a bearer credential. Anyone who receives a photo, scan, or
  copied URL may view the status associated with that token. Share tracking
  codes only with their intended recipient and disable tracking in Admin
  Settings if links should stop working.
- The local development TLS certificate and Private-network firewall helper
  are for trusted LAN development, not public hosting.
- The admin login does not provide multi-factor authentication or automated
  email password resets. Apply strong unique credentials and suitable network
  protections; consider an approved reverse proxy or identity provider before
  broader deployment.
- `HttpOnly` and `SameSite` cookies do not replace HTTPS. Plain HTTP can expose
  session credentials and submitted data to other parties on the network.

## If a credential or device is exposed

1. Disable unauthenticated mobile kiosk access and restrict network access
   while investigating.
2. Change affected administrator, database, and SMS/email provider
   credentials. Revoke mobile sessions by removing their rows from
   `mobile_api_tokens` if needed.
3. Disable document or batch tracking to invalidate public tracker URLs, then
   reissue tokens only for records that still need tracking.
4. Review web server, application, database, and provider logs for unexpected
   access. Preserve relevant logs securely and follow the organization's
   incident response and privacy-notification procedures.
5. Restore from a known-good backup only after addressing the cause and
   verifying that the backup is trustworthy.

## Related documentation

- [Main project README](../README.md)
- [Windows setup and LAN access](WINDOWS-SETUP.md)
