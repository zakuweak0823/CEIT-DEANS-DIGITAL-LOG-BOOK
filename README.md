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
| **SMS and notifications** | Configure notification settings, send client updates, and review SMS logs. |
| **Mobile app** | Submit and manage documents, batches, and borrowed items; look up client status and view reports. |

The admin interface also includes responsive navigation, a compact footer
with live server/network status, and a recovery screen when the office server
or database disconnects.

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

Open **Notification Settings** in the admin portal to configure SMS or email
notifications. Provider credentials belong in the application settings or
server environment—not in Git-tracked files or database exports.

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
