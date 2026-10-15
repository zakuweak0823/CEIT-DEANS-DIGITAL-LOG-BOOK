<div align="center">
<img src="assets/images/CVSU.jpeg" alt="Cavite State University seal" width="112">

<h1>CEIT Dean's Office Digital Logbook</h1>

<p><strong>A document and inventory tracking system for the CVSU College of Engineering and Information Technology Dean's Office.</strong></p>

<p>Manage office records through a PHP and MySQL admin portal, with a React mobile app for on-the-go logbook tasks.</p>

<p>
<a href="https://www.php.net/"><img src="https://img.shields.io/badge/PHP-8%2B-777BB4?logo=php&logoColor=white" alt="PHP 8 or later"></a>
<a href="https://www.mysql.com/"><img src="https://img.shields.io/badge/Database-MySQL%20%7C%20MariaDB-4479A1?logo=mysql&logoColor=white" alt="MySQL or MariaDB"></a>
<a href="https://react.dev/"><img src="https://img.shields.io/badge/Mobile-React-61DAFB?logo=react&logoColor=black" alt="React"></a>
<a href="https://vite.dev/"><img src="https://img.shields.io/badge/Build-Vite-646CFF?logo=vite&logoColor=white" alt="Vite"></a>
<a href="https://capacitorjs.com/"><img src="https://img.shields.io/badge/Android-Capacitor-119EFF?logo=capacitor&logoColor=white" alt="Capacitor"></a>
</p>
</div>

---

## Contents

- [Overview](#overview)
- [Start here](#start-here)
- [What you can do](#what-you-can-do)
- [Technology](#technology)
- [Get started](#get-started)
- [Windows setup and computer-to-computer use](docs/WINDOWS-SETUP.md)
- [Mobile app](#mobile-app)
- [Project layout](#project-layout)
- [Patch history](#patch-history)
- [Security before deployment](#security-before-deployment)
- [Troubleshooting](#troubleshooting)

## Overview

DIT Logbook provides one place to record office document activity, follow
incoming and outgoing submissions, organize bulk papers, and keep track of
borrowed items. The browser-based admin portal and mobile client use the same
PHP API and MySQL database.

### Start here

| Goal | Start with |
| --- | --- |
| Set up the admin portal on Windows | [Windows setup guide](docs/WINDOWS-SETUP.md) |
| Start the local/LAN server | Double-click [`start-office-server.bat`](start-office-server.bat) from the project folder inside XAMPP's `htdocs`. |
| Move the app and records to another computer | [Transfer instructions](docs/WINDOWS-SETUP.md#move-the-application-and-database) |
| Run or build the mobile client | [Mobile app instructions](#mobile-app) |
| Deploy outside a trusted local network | Read [Security before deployment](#security-before-deployment) first. |

## What you can do

| Area | Capabilities |
| --- | --- |
| **Dashboard** | Review document status summaries and see pending incoming documents. |
| **Incoming documents** | Register submissions, search records, review details, and prepare documents for release. |
| **Outgoing documents** | Track released documents and send client notifications. |
| **Bulk papers** | Register batches, associate paper-level records, and follow batch status. |
| **Borrowed items** | Manage item categories, borrowers, due dates, and return status. |
| **Records and reports** | Search logbook records, inspect document activity, and generate date-filtered reports. |
| **SMS and notifications** | Configure SMS and email, tune per-browser activity alerts, send client updates, and review SMS logs. |
| **Admin Assistant** | Get privacy-friendly workflow guidance from a clickable side-peeking launcher and links to admin tools. Optional form assistance previews changes and requires approval before filling fields; it never submits forms. Toggle it in Settings. |
| **Mobile app** | Submit and manage documents, batches, and borrowed items; look up client status and view reports. |

## Admin workspace options

- **Compact Mode** turns the admin portal into a mobile-style workspace, even
  on desktop. It hides the sidebar, adds a bottom navigation dock and More
  sheet, and presents records in touch-friendly cards. Its mint-and-cream
  palette uses CVSU green for active navigation and primary actions.
- **Compact Mode controls** are available in both the administrator dropdown
  and **Settings**. The larger switch shows a clear On/Off state and updates
  immediately when changed.
- **Compact Mode quick actions** use a balanced two-column layout. Incoming
  document forms stay within a readable dialog instead of stretching across
  the full screen.
- **QR scanning from the dashboard** opens the camera tool in a focused,
  responsive dialog. The embedded scanner no longer inherits the Compact
  Mode navigation dock, leaving the camera preview and scan controls usable.
  Camera access on a phone requires opening the app through HTTPS with a
  certificate trusted by that phone. A computer's `localhost` address does
  not carry over to a phone; if HTTPS is unavailable, upload a QR image in the
  scanner instead.
- **Admin Assistant** stays tucked at the screen edge as a clickable handle.
  Hover or focus to reveal it, then click to open the guide. Disable or restore
  it from **Settings**. Form assistance only fills values after an approval
  preview; the assistant never submits a form.
- **Browser preferences** include activity polling, sound and optional
  privacy-conscious desktop alerts, the pending-documents bubble, the
  assistant, and Compact Mode. These preferences are saved in the current
  browser and synchronized between open admin tabs.
- **Server recovery** provides a status screen and retry guidance when the
  office server or database disconnects; the admin interface also shows live
  server and network status.

## Technology

| Component | Technology |
| --- | --- |
| Admin portal and API | PHP, MySQLi |
| Database | MySQL / MariaDB |
| Mobile web app | React, Vite |
| Android app | Capacitor |
| Local web server | Apache with `mod_rewrite` |

## Get started

### Requirements

- Windows with [XAMPP](https://www.apachefriends.org/) (Apache, MySQL, PHP)
- PHP 8+ with the **MySQLi** and **cURL** extensions enabled
- Apache `mod_rewrite` enabled
- [Node.js and npm](https://nodejs.org/) for mobile development
- Android Studio, Android SDK, and a configured device/emulator to run the
  Android app

For the administrator portal, XAMPP and a database are required. Node.js is
only needed for mobile development/builds; Android Studio and the Android SDK
are only needed to build or run the native Android project.

### 1. Set up the web app

1. Put the project folder in the XAMPP web root:
   `C:\xampp\htdocs\<any-folder-name>`. The folder can be renamed or copied
   without editing the app's routing configuration.
2. On first setup, start **Apache** and **MySQL** in the XAMPP Control Panel,
   open phpMyAdmin, and import [`database/schema.sql`](database/schema.sql).
   It creates the `dit_logbook` database and the tables used by the application.
3. Review the connection values in [`db.php`](db.php). The local defaults are:

   | Setting | Default |
   | --- | --- |
   | Host | `localhost` |
   | Database | `dit_logbook` |
   | Username | `root` |
   | Password | *(empty)* |

4. Double-click [`start-office-server.bat`](start-office-server.bat). It starts
   XAMPP Apache and MySQL if needed, finds the current project folder and
   network address, and opens the admin portal. On first run, approve the
   Windows prompt to add a firewall rule for Apache on port 80 **only on
   Private networks**. This avoids manually creating the rule in Windows
   Firewall.

   The launcher displays both the local address and addresses for other
   computers on the same trusted Wi-Fi/network. Keep the host computer and
   XAMPP running while others use the site. If Windows classifies a trusted
   office/home network as Public, change that network to Private in Windows
   Settings; the launcher deliberately does not allow connections on Public
   networks.

For step-by-step first-time setup, firewall behavior, and transfer details,
see [Windows Setup and Computer-to-Computer Use](docs/WINDOWS-SETUP.md).

#### Moving the app and its records to another computer

Copy the whole project folder into that computer's XAMPP `htdocs` directory.
To move existing records, export the `dit_logbook` database as an SQL file from
phpMyAdmin on the old computer, then import that export on the new computer.
[`database/schema.sql`](database/schema.sql) initializes the schema for a
fresh install; it does not contain your live records. Check the new computer's
database username and password in [`db.php`](db.php), then run
[`start-office-server.bat`](start-office-server.bat). Do not copy a live MySQL
data directory while its server is running.

This launcher is for the host computer and other devices on the same trusted
Private network; it does not make the site available over mobile data or the
public internet. Phone camera scanning requires trusted HTTPS, which needs a
certificate configured for the new host. Until HTTPS is set up, use the QR
image-upload option on the scanner.

The extension-free routes depend on Apache URL rewriting. If you see a 404
when opening a page, confirm that `mod_rewrite` is enabled and that Apache
allows the project’s `.htaccess` rules.

### 2. Sign in for the first time

The schema seeds an initial admin account for local setup:

| Username | Initial password |
| --- | --- |
| `admin` | `admin` |

The application upgrades the seeded plaintext password to a password hash
when it is first used. Change the default password immediately after signing
in, and never reuse it on a deployed system.

### 3. Configure notifications (optional)

Open **Settings** from the administrator dropdown to personalize live activity
monitoring, alerts, and the floating pending-documents shortcut for the current
browser, or turn on **Compact Mode** from the administrator dropdown.
Compact Mode switches the entire admin portal to a mobile-style layout with
four primary shortcuts and a bottom **More** menu for the remaining admin
sections, stacked content, card-based record lists, and a full-width
workspace—even on a desktop. Open **Notification Settings** to
configure system-wide SMS, email, or mobile office access. Activity
preferences are saved in the current browser; desktop alerts are optional
and require browser permission. Provider credentials belong in the application
settings or server environment—not in Git-tracked files or database exports.

### Scan QR codes from a phone on local XAMPP

Phone browsers require a trusted HTTPS origin before they will allow camera
access. The phone and host computer must be on the same trusted Wi-Fi/network,
and the HTTPS certificate must be trusted by the phone and valid for the
address used to open the app. Local IP addresses and certificates are specific
to a host computer; do not assume the address or development certificate from
another computer will work.

1. From the computer, open **Admin Settings** and choose **Download
   certificate** under **Mobile camera certificate**, if a certificate has
   been configured for this host. Transfer the downloaded public certificate
   to the phone using a trusted method.
2. Install that file on the phone as a **CA certificate**. On Android, use
   **Settings → Security → Encryption & credentials → Install a certificate
   → CA certificate** (menu names vary by device). On iPhone, install the
   downloaded profile in **Settings**, then enable full trust for it under
   **Settings → General → About → Certificate Trust Settings**.
3. Open the host's configured HTTPS address in the phone's browser, sign in,
   and use the QR scanner. Allow camera access when prompted.

If the browser still reports an untrusted connection, confirm the CA is
installed and fully trusted, and that the certificate matches the address
being used. A public CA certificate is not a private key, but the matching
private key must never be shared or committed. This local development
certificate is not for a public or production server. See the
[Windows setup guide](docs/WINDOWS-SETUP.md#access-outside-the-local-network)
for the limits of the local setup.

## Mobile app

The main mobile client is the React/Vite app in `mobile-ui/`, with Capacitor
providing its Android project. The separate `mobile-ui/expo-wrapper/` is an
optional Expo wrapper with its own dependencies; the steps below use the
primary Vite/Capacitor app.

### Run the web client locally

From the repository root, install dependencies and start the development
server:

```powershell
cd mobile-ui
npm install
npm run dev
```

Open the Vite URL shown in the terminal (usually
[http://localhost:5173](http://localhost:5173)). Keep Apache and MySQL running:
the Vite development server proxies `/api` requests to the PHP app under
the project's actual `htdocs` folder name.

### Build the web client

```powershell
npm run build
```

The generated web app is written to `mobile-ui/dist`. This output is ignored by
Git and copied into the Android project by Capacitor when syncing/building.
You can recreate generated output with the build command; do not use generated
folders as the source when transferring the project.

### Run on Android

With Android Studio, the Android SDK, and a device or emulator configured:

```powershell
npm run android
```

This script builds the web client and runs the Android app through Capacitor.

### Mobile API endpoint

The Android emulator's default API address uses `10.0.2.2` to reach the host
computer. The project folder is detected at build time. For a deployed API or
another server, set `VITE_API_ENDPOINT` at build time to the complete
`mobile-api.php` URL for that server. A built app cannot use the emulator's
`10.0.2.2` address to reach a production server.

## Project layout

```text
.
├── assets/
│   ├── audio/                        # Admin notification sounds
│   ├── css/                          # Admin stylesheets
│   ├── images/                       # Logos and other web images
│   └── js/                           # Browser-ready JavaScript bundles
├── database/
│   └── schema.sql                    # Fresh-install schema and initial admin seed
├── docs/
│   ├── WINDOWS-SETUP.md              # Windows setup and computer transfer
│   └── Patch V1.00.md                # Project patch history
├── tools/
│   ├── start-office-server.ps1       # XAMPP startup and network helper
│   ├── mobile_submission_console.php
│   └── start_mobile_submission_log.bat
├── start-office-server.bat           # Double-click Windows launcher
├── db.php                            # Database connection and query helpers
├── header.php / footer.php           # Shared admin layout
├── dashboard.php                     # Admin dashboard
├── incoming.php / outgoing.php       # Document workflows
├── batch_papers.php                  # Bulk paper registration
├── batch_tracker.php                 # Batch tracking
├── borrowed.php                      # Borrowed item management
├── records.php                       # Document search
├── reports.php                       # Reports
├── sms.php                           # SMS logs and operations
├── mobile-api.php                    # API for the mobile client
└── mobile-ui/
    ├── src/                         # React/Vite mobile client
    ├── android/                     # Capacitor Android project
    └── expo-wrapper/                # Optional Expo wrapper with separate dependencies
```

The root PHP files intentionally stay together because XAMPP serves this
directory as the application root and the clean routes, shared includes, and
form endpoints rely on those paths. Keep the whole project folder intact when
copying it to another XAMPP `htdocs` directory; the launcher detects its new
folder name. Do not copy `node_modules`, `dist`, or Android build/Gradle output
to move the project; those are generated or machine-specific and can be
recreated from the package manifests and source.

## Patch history

This log summarizes the project patches discussed and delivered in the
repository. Related chat iterations are grouped together; the commit hash
identifies the Git commit that contains the recorded changes.

| Patch | Commit | Summary |
| --- | --- | --- |
| **V1.00 — Source publication** | [`b82df29`](https://github.com/zakuweak0823/CEIT-DEANS-DIGITAL-LOG-BOOK/commit/b82df29) | Published the cleaned PHP/MySQL and React mobile/Capacitor source to the private GitHub repository. Added ignore rules for dependencies, generated output, and local data; excluded the database dump from the published snapshot. |
| **V1.01 — README and admin footer** | [`c8f3f47`](https://github.com/zakuweak0823/CEIT-DEANS-DIGITAL-LOG-BOOK/commit/c8f3f47) | Improved GitHub documentation with setup, features, mobile instructions, security guidance, badges, and troubleshooting. The admin footer was refined into a compact branded status bar showing network availability; footer work predating the clean repository snapshot is included in the published source. |
| **V1.02 — Server recovery** | [`bcd85eb`](https://github.com/zakuweak0823/CEIT-DEANS-DIGITAL-LOG-BOOK/commit/bcd85eb) | Added a styled database-disconnection page, retry guidance, JSON 503 responses, a health-check endpoint, and an authenticated admin outage/recovery notice. Distinguished an unreachable server from a browser offline state. |
| **V1.03 — Admin settings and mobile Compact Mode** | [`707b78e`](https://github.com/zakuweak0823/CEIT-DEANS-DIGITAL-LOG-BOOK/commit/707b78e) | Added a dedicated Settings page and route; browser preferences for activity polling, sound and desktop alerts, and the pending-documents bubble; and a persistent Compact Mode preference. Reworked Compact Mode as a phone-style workspace with card layouts, touch controls, and bottom-sheet dialogs. |
| **V1.04 — Compact navigation refinement** | Included in [`707b78e`](https://github.com/zakuweak0823/CEIT-DEANS-DIGITAL-LOG-BOOK/commit/707b78e) | Hid the sidebar in Compact Mode, lightened its theme, reduced the bottom dock to Dashboard, Pending inbox, New record, Records, and More, and moved other destinations into the More sheet. |
| **V1.05 — Patch history documentation** | [`cde0f54`](https://github.com/zakuweak0823/CEIT-DEANS-DIGITAL-LOG-BOOK/commit/cde0f54) | Added the versioned [`docs/Patch V1.00.md`](docs/Patch%20V1.00.md) history and linked it from the README. |
| **V1.06 — Admin Assistant** | Included in V1.07 below | Added a built-in, browser-side admin guide with suggested questions and links to common workflows. It does not call an external AI service, access record contents, or save chat history. |
| **V1.07 — Movable assistant and safe form help** | [`8fbda35`](https://github.com/zakuweak0823/CEIT-DEANS-DIGITAL-LOG-BOOK/commit/8fbda35) | Moved the assistant to a draggable side launcher with a smooth, on-demand panel and saved position. Added field-value previews that require approval before filling visible form fields; the assistant never submits forms. Compact Mode hides the CMD log control and stops an active log. |
| **V1.08 — Side-peeking assistant visibility setting** | Included in V1.09 below | Changed the assistant launcher to peek from the screen edge while keeping a clickable handle visible, revealing the full launcher on hover, focus, or drag. Added a Settings switch to hide or restore the assistant; the preference is saved per browser and synchronized across admin tabs. |
| **V1.09 — Compact Mode pastel palette and clearer switch** | [`f923104`](https://github.com/zakuweak0823/CEIT-DEANS-DIGITAL-LOG-BOOK/commit/f923104) | Replaced Compact Mode's gray-heavy surfaces with a soft mint-and-cream palette using CVSU green for emphasis. Improved the Compact Mode switch with a larger control and synchronized On/Off status badges in Settings and the admin dropdown. Also includes the V1.08 assistant visibility setting. |
| **V1.10 — Compact Mode quick actions and QR scanner** | [`5c1a9bc`](https://github.com/zakuweak0823/CEIT-DEANS-DIGITAL-LOG-BOOK/commit/5c1a9bc) | Constrained Compact Mode quick actions to a balanced two-column grid and kept dashboard action dialogs within readable widths. Isolated embedded scanner and batch tools from Compact Mode's full-screen shell and bottom dock so QR scanning controls remain visible and usable. |
| **V1.11 — Clear mobile camera security guidance** | [`a65ca54`](https://github.com/zakuweak0823/CEIT-DEANS-DIGITAL-LOG-BOOK/commit/a65ca54) | Detects insecure HTTP contexts before requesting camera access and explains that phone `localhost` points to the phone itself. Directs admins to use a trusted HTTPS address or the existing local QR-image upload fallback. |
| **V1.12 — Local HTTPS for phone QR scanning** | [`5453bb9`](https://github.com/zakuweak0823/CEIT-DEANS-DIGITAL-LOG-BOOK/commit/5453bb9) | Configured this XAMPP computer with a private development CA and an IP-matched Apache certificate, and documented how to install the CA on a phone and open the app over trusted HTTPS. The server and CA private keys remain outside the repository. |
| **V1.13 — Certificate download in Admin Settings** | [`28d774d`](https://github.com/zakuweak0823/CEIT-DEANS-DIGITAL-LOG-BOOK/commit/28d774d) | Added a download button to Admin Settings for the public local development CA certificate, with a clear unavailable notice if the certificate is not installed on the server. Updated the phone QR-scanning setup instructions to use the button. |

Earlier chat iterations included repeated README and footer refinements,
pending-document bubble improvements and its hide/show setting, admin
notification/activity preferences, and several rounds of Compact Mode
redesign. Some iterations were consolidated into later commits rather than
saved as separate Git patches. See [Patch V1.00.md](docs/Patch%20V1.00.md) for the
expanded notes.

## Security before deployment

- Replace the seeded admin password immediately.
- Use a dedicated MySQL account with a strong password; do not deploy with the
  default XAMPP `root` account.
- Serve the application over HTTPS outside a local development environment.
- The local XAMPP CA described above is development-only; do not deploy it or
  copy its private key to another machine.
- Keep SMS/email credentials and private configuration out of source control.
- Do not commit database exports or production data. `db2.sql` and generated
  dependency/build folders are intentionally excluded from Git.
- Configure `VITE_API_ENDPOINT` for the deployed server before producing a
  production mobile build.

## Troubleshooting

| Problem | Things to check |
| --- | --- |
| Database connection error | Start MySQL and confirm the host, username, password, and database in `db.php`. |
| Clean page routes return 404 | Enable Apache `mod_rewrite` and allow `.htaccess` overrides for the project directory. |
| Another computer cannot open the site | Run `start-office-server.bat` on the host computer, approve its Private-network firewall prompt, and use the network URL it prints. Keep both computers on the same trusted network and leave Apache/MySQL running. |
| Mobile app cannot reach the API | Keep Apache/MySQL running and verify the Vite proxy or `VITE_API_ENDPOINT`. On Android emulators, use `10.0.2.2` rather than `localhost`. |
| SMS sending fails | Check cURL, notification settings, provider credentials, and provider account status. |
| Android build cannot find a device | Open the Android project in Android Studio, install the required SDK components, and start an emulator or connect a device. |

---

<div align="center">
Built for the CVSU CEIT Dean's Office.
</div>
