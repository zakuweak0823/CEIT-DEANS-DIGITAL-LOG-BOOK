<div align="center">

# DIT Logbook

### CEIT Dean's Office Digital Logbook

An office document and inventory tracking system with a PHP and MySQL admin
portal, plus a React mobile client for everyday logbook tasks.

[![PHP](https://img.shields.io/badge/PHP-MySQL-777BB4?logo=php&logoColor=white)](https://www.php.net/)
[![React](https://img.shields.io/badge/Mobile-React-61DAFB?logo=react&logoColor=black)](https://react.dev/)
[![Vite](https://img.shields.io/badge/Build-Vite-646CFF?logo=vite&logoColor=white)](https://vite.dev/)
[![Capacitor](https://img.shields.io/badge/Android-Capacitor-119EFF?logo=capacitor&logoColor=white)](https://capacitorjs.com/)

</div>

---

## Overview

DIT Logbook helps the CEIT Dean's Office register and follow documents,
bulk paper submissions, and borrowed items. The browser-based admin portal
provides record management and reporting, while the mobile app connects to the
same PHP API.

## Features

- **Dashboard** — at-a-glance document status counts and pending-item notice.
- **Document workflows** — register, search, review, and track incoming and
  outgoing documents through release.
- **Bulk papers** — register batches and track their paper-level records and
  status.
- **Borrowed items** — manage item categories, borrower details, and return
  status.
- **Reports and activity** — review document activity, search records, and
  generate date-filtered reports.
- **SMS and notifications** — configure notifications, send client updates,
  and view message logs.
- **Mobile client** — submit and manage documents, batches, and borrowed items;
  check client status and view reports.
- **Responsive admin workspace** — adaptive navigation, a compact footer, and a
  live browser network indicator.

## Technology

| Area | Tools |
| --- | --- |
| Admin portal and API | PHP, MySQLi |
| Database | MySQL / MariaDB |
| Mobile web app | React, Vite |
| Android wrapper | Capacitor |
| Web server (local setup) | Apache with `mod_rewrite` |

## Getting started

### Requirements

- XAMPP (Apache, MySQL, and PHP with MySQLi enabled)
- Apache `mod_rewrite` enabled for clean page routes
- Node.js and npm (only needed to develop or build the mobile app)
- PHP cURL enabled for SMS provider requests

### 1. Install the web application

1. Copy or clone the project into your XAMPP web root:
   `C:\xampp\htdocs\DIT-LOGBOOK_RETRY`.
2. Start **Apache** and **MySQL** in the XAMPP Control Panel.
3. Import [`database.sql`](database.sql) into MySQL using phpMyAdmin or the
   MySQL command line. The script creates the `dit_logbook` database and its
   tables.
4. Check the database connection settings in [`db.php`](db.php). The defaults
   are `localhost`, user `root`, an empty password, and database `dit_logbook`.
5. Open [http://localhost/DIT-LOGBOOK_RETRY/](http://localhost/DIT-LOGBOOK_RETRY/)
   in your browser.

Apache URL rewriting must be enabled so the extension-free routes work.

### 2. Sign in

The schema inserts an initial account:

| Username | Password |
| --- | --- |
| `admin` | `admin` |

Change the default credentials immediately after the first sign-in. The
application hashes the seeded plaintext password when it is first used.

### 3. Run the mobile client

From the project directory:

```powershell
cd mobile-ui
npm install
npm run dev
```

Open the Vite URL shown in the terminal (normally
[`http://localhost:5173`](http://localhost:5173)). Keep Apache and MySQL
running: the Vite development server proxies API calls to the PHP project at
`/DIT-LOGBOOK_RETRY`.

To create a production web build:

```powershell
npm run build
```

The generated files are written to `mobile-ui/dist`, the web directory used by
Capacitor. To build and run the Android app on a configured Android device or
emulator:

```powershell
npm run android
```

### Mobile API configuration

The Android emulator default points to
`http://10.0.2.2/DIT-LOGBOOK_RETRY/mobile-api.php` (`10.0.2.2` maps to the
development host from the emulator). For a different server, provide
`VITE_API_ENDPOINT` when building the mobile app, with the full URL to
`mobile-api.php`.

## Project structure

```text
.
├── mobile-api.php             # API used by the mobile client
├── database.sql               # Database creation and schema
├── db.php                     # MySQL connection and query helpers
├── dashboard.php              # Admin dashboard
├── incoming.php               # Incoming document workflow
├── outgoing.php               # Outgoing document workflow
├── batch_papers.php            # Bulk paper registration
├── batch_tracker.php           # Batch tracking
├── borrowed.php                # Borrowed item management
├── reports.php                # Reports
├── sms.php                     # SMS logs and operations
└── mobile-ui/                  # React, Vite, and Capacitor app
```

Other admin pages cover records, item categories, notification settings, and
document activity.

## Security and deployment notes

- Do not deploy with the default `admin` / `admin` credentials.
- Update [`db.php`](db.php) with a dedicated database account and a strong
  password in production.
- Do not commit database exports or production data. Use `database.sql` for
  schema setup; local database dumps are excluded from version control.
- Use HTTPS when the app is reachable outside a local development machine.
- Keep API keys and provider credentials out of source control.
- Configure the mobile API endpoint for the deployed server before creating a
  production mobile build.

## Project notes

The project folder does not include Git patch history. This README documents
the features and setup visible in the current source rather than attributing
them to a specific sequence of historical patches.
