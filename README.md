<div align="center">
<img src="CVSU.jpeg" alt="Cavite State University seal" width="112">

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
- [What you can do](#what-you-can-do)
- [Technology](#technology)
- [Get started](#get-started)
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

### 1. Set up the web app

1. Put the project folder in the XAMPP web root:
   `C:\xampp\htdocs\DIT-LOGBOOK_RETRY`.
2. Start **Apache** and **MySQL** in the XAMPP Control Panel.
3. In phpMyAdmin, import [`database.sql`](database.sql). It creates the
   `dit_logbook` database and the tables used by the application.
4. Review the connection values in [`db.php`](db.php). The local defaults are:

   | Setting | Default |
   | --- | --- |
   | Host | `localhost` |
   | Database | `dit_logbook` |
   | Username | `root` |
   | Password | *(empty)* |

5. Open [http://localhost/DIT-LOGBOOK_RETRY/](http://localhost/DIT-LOGBOOK_RETRY/)
   in your browser.

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

## Mobile app

### Run the web client locally

From the repository root:

```powershell
cd mobile-ui
npm install
npm run dev
```

Open the Vite URL shown in the terminal (usually
[http://localhost:5173](http://localhost:5173)). Keep Apache and MySQL running:
the Vite development server proxies `/api` requests to the PHP app under
`/DIT-LOGBOOK_RETRY`.

### Build the web client

```powershell
npm run build
```

The generated web app is written to `mobile-ui/dist`. This output is ignored by
Git and is used as the Capacitor web asset directory.

### Run on Android

With Android Studio, the Android SDK, and a device or emulator configured:

```powershell
npm run android
```

This script builds the web client and runs the Android app through Capacitor.

### Mobile API endpoint

The bundled Android default is
`http://10.0.2.2/DIT-LOGBOOK_RETRY/mobile-api.php`. Android emulators use
`10.0.2.2` to reach the development host machine. For another host, provide
`VITE_API_ENDPOINT` at build time as the complete URL to `mobile-api.php`.

## Project layout

```text
.
├── database.sql                     # Application schema and initial admin seed
├── db.php                           # Database connection and query helpers
├── header.php / footer.php          # Shared admin layout
├── dashboard.php                    # Admin dashboard
├── incoming.php / outgoing.php      # Document workflows
├── batch_papers.php                 # Bulk paper registration
├── batch_tracker.php                # Batch tracking
├── borrowed.php                     # Borrowed item management
├── records.php                      # Document search
├── reports.php                      # Reports
├── sms.php                          # SMS logs and operations
├── mobile-api.php                   # API for the mobile client
├── tools/                           # Mobile submission console utilities
├── tone/                            # Admin notification audio
└── mobile-ui/
    ├── src/                         # React/Vite mobile client
    ├── android/                     # Capacitor Android project
    └── expo-wrapper/                # Expo wrapper project
```

Other root-level PHP pages provide authentication, quick submission, item
categories, notification settings, and document activity.

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
| **V1.05 — Patch history documentation** | [`cde0f54`](https://github.com/zakuweak0823/CEIT-DEANS-DIGITAL-LOG-BOOK/commit/cde0f54) | Added the versioned `Patch V1.00.md` history and linked it from the README. |
| **V1.06 — Admin Assistant** | Included in V1.07 below | Added a built-in, browser-side admin guide with suggested questions and links to common workflows. It does not call an external AI service, access record contents, or save chat history. |
| **V1.07 — Movable assistant and safe form help** | [`8fbda35`](https://github.com/zakuweak0823/CEIT-DEANS-DIGITAL-LOG-BOOK/commit/8fbda35) | Moved the assistant to a draggable side launcher with a smooth, on-demand panel and saved position. Added field-value previews that require approval before filling visible form fields; the assistant never submits forms. Compact Mode hides the CMD log control and stops an active log. |
| **V1.08 — Side-peeking assistant visibility setting** | Included in V1.09 below | Changed the assistant launcher to peek from the screen edge while keeping a clickable handle visible, revealing the full launcher on hover, focus, or drag. Added a Settings switch to hide or restore the assistant; the preference is saved per browser and synchronized across admin tabs. |
| **V1.09 — Compact Mode pastel palette and clearer switch** | [`f923104`](https://github.com/zakuweak0823/CEIT-DEANS-DIGITAL-LOG-BOOK/commit/f923104) | Replaced Compact Mode's gray-heavy surfaces with a soft mint-and-cream palette using CVSU green for emphasis. Improved the Compact Mode switch with a larger control and synchronized On/Off status badges in Settings and the admin dropdown. Also includes the V1.08 assistant visibility setting. |

Earlier chat iterations included repeated README and footer refinements,
pending-document bubble improvements and its hide/show setting, admin
notification/activity preferences, and several rounds of Compact Mode
redesign. Some iterations were consolidated into later commits rather than
saved as separate Git patches. See [Patch V1.00.md](Patch%20V1.00.md) for the
expanded notes.

## Security before deployment

- Replace the seeded admin password immediately.
- Use a dedicated MySQL account with a strong password; do not deploy with the
  default XAMPP `root` account.
- Serve the application over HTTPS outside a local development environment.
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
| Mobile app cannot reach the API | Keep Apache/MySQL running and verify the Vite proxy or `VITE_API_ENDPOINT`. On Android emulators, use `10.0.2.2` rather than `localhost`. |
| SMS sending fails | Check cURL, notification settings, provider credentials, and provider account status. |
| Android build cannot find a device | Open the Android project in Android Studio, install the required SDK components, and start an emulator or connect a device. |

---

<div align="center">
Built for the CVSU CEIT Dean's Office.
</div>
