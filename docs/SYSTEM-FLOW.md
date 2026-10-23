# System Flow & How Everything Works

This document explains **how the CEIT Dean's Office Digital Logbook actually
works end to end** — from the moment a request reaches Apache, through
authentication, every document/batch lifecycle, the QR tracking system, the
mobile API, SMS, reporting, and the maintenance tools.

It is the technical companion to the [README](../README.md). Where the README
describes *what you can do*, this page describes *how the system does it*, which
files are responsible, and — importantly — **why certain workarounds exist**
because of local XAMPP, LAN, or browser limitations.

---

## Contents

1. [Big picture](#1-big-picture)
2. [Folder and file map](#2-folder-and-file-map)
3. [Request flow (how a page is served)](#3-request-flow-how-a-page-is-served)
4. [Authentication and session flow](#4-authentication-and-session-flow)
5. [The document lifecycle](#5-the-document-lifecycle)
6. [Bulk paper (batch) lifecycle](#6-bulk-paper-batch-lifecycle)
7. [Borrowed items flow](#7-borrowed-items-flow)
8. [QR tracking flow](#8-qr-tracking-flow)
9. [Mobile app and API flow](#9-mobile-app-and-api-flow)
10. [SMS and notification flow](#10-sms-and-notification-flow)
11. [Overdue (SLA) flow](#11-overdue-sla-flow)
12. [Reports, records, and exports](#12-reports-records-and-exports)
13. [Settings and configuration flow](#13-settings-and-configuration-flow)
14. [Database schema walkthrough](#14-database-schema-walkthrough)
15. [Security flow (defence in depth)](#15-security-flow-defence-in-depth)
16. [CMD log data maintenance](#16-cmd-log-data-maintenance)
17. [Workarounds and why they exist](#17-workarounds-and-why-they-exist)
18. [Failure and recovery flow](#18-failure-and-recovery-flow)
19. [Sequence diagrams](#19-sequence-diagrams)
20. [Glossary](#20-glossary)

---

## 1. Big picture

The system is **one PHP application + one MySQL database + one React client**,
all served from a single XAMPP folder.

```text
                     ┌─────────────────────────────────────────────┐
                     │              Browser (admin)                │
                     │  dashboard / incoming / outgoing / batches  │
                     └───────────────┬─────────────────────────────┘
                                     │ HTTP(S), PHP session cookie
                                     ▼
┌───────────────────────────────────────────────────────────────────────────┐
│                      Apache (XAMPP) on the host computer                  │
│                                                                           │
│  .htaccess  ─── rewrites clean URLs ───┐                                  │
│                                        ▼                                  │
│  router.php (PHP built-in server only) ── resolves clean URLs             │
│                                        │                                  │
│  auth.php ── session + CSRF + role    │      document_tracking.php        │
│  db.php   ── connection + safe_query ─┼──►   batch_support.php            │
│  sms_service.php ── PhilSMS + logging │      sms_support / sla_support    │
│                                        ▼                                  │
│  dashboard.php  incoming.php  outgoing.php  batch_papers.php              │
│  borrowed.php   records.php   reports.php   sms.php  settings.php         │
│  mobile-api.php  document_tracker.php  server_health.php  users.php       │
└───────────────┬──────────────────────────────────────┬────────────────────┘
                │                                      │
                ▼                                      ▼
        ┌───────────────┐                     ┌────────────────────┐
        │ MySQL          │                     │ PhilSMS HTTP API   │
        │ dit_logbook    │                     │ (outbound only)    │
        └───────────────┘                     └────────────────────┘
                ▲
                │ JSON over /api (proxied by Vite)
┌───────────────┴───────────────────────────────────────────────────────────┐
│  Mobile client: mobile-ui (React + Vite), Android build via Capacitor     │
│  Phone browser ──HTTPS :5173──► Vite dev server ──proxy /api──► mobile-api │
└───────────────────────────────────────────────────────────────────────────┘
```

**Three access paths, each with its own trust level:**

| Path | Who | Auth | What it can do |
| --- | --- | --- | --- |
| Admin portal | Office staff | PHP session + CSRF + role | Everything |
| Mobile API | Mobile client on the LAN | Kiosk switch, or bearer token | Logbook operations |
| Public tracker | Anyone holding a QR link | Unguessable token only | Read one record's status |

The key design rule: **the admin portal is never exposed by a token.** QR links
can only ever read a limited status subset from the tracker page.

---

## 2. Folder and file map

| File / folder | Role in the flow |
| --- | --- |
| `db.php` | Opens the MySQLi connection (`dit_logbook`, UTF-8 `utf8mb4`), defines `safe_query()`, `safe_scalar()`, phone validation, and the **database-down recovery page**. |
| `auth.php` | Starts the session with hardened cookie flags, exposes `ensure_admin()`, `ensure_super_admin()`, `verify_csrf_request()`, `csrf_token()`, `sanitize()`, `show_alert()`. |
| `router.php` | Router used **only** by PHP's built-in server (`php -S`). Apache uses `.htaccess` instead. |
| `.htaccess` | Apache rewrite rules for extension-free URLs and the `document-tracker` alias. |
| `header.php` / `footer.php` | Shared layout, sidebar, page titles, live status widgets. `header.php` reads `SCRIPT_FILENAME` (set by the router) so navigation highlighting works with clean URLs. |
| `login.php` / `logout.php` / `forgot_password.php` | Sign-in, sign-out, recovery guidance. |
| `users.php` | Super Admin account management (create / reset / delete / change own password). |
| `incoming.php` | Incoming document create, edit, tracking QR issue, priority toggle, **Prepare for Release**. |
| `outgoing.php` | Outgoing document edit, status change, SMS notification, tracking QR. |
| `quick_document.php` | Read-only list of records created through mobile Quick Submit (`entry_source = 'quick_submit'`). |
| `batch_papers.php` | Bulk submissions: register batch, link document-level papers, intake receipt, status advance, priority. |
| `batch_tracker.php` | Admin-facing batch lookup by QR/code. |
| `borrowed.php` / `item_categories.php` | Borrowed items and categories. |
| `records.php` | Cross-cutting document search. |
| `reports.php` | Date-filtered reports, print layout, Excel export. |
| `sms.php` | SMS logs, manual send, status refresh. |
| `notification_settings.php` | Communication (SMS/email) configuration. |
| `settings.php` | Admin Settings hub (kiosk, tracker, SLA, form labels, mobile appearance, certificate download). |
| `mobile_api.php` | The JSON API used by the mobile client. |
| `document_tracker.php` | Public, mobile-friendly status page (reads via `mobile-api.php`). |
| `document_tracking.php` | Tracking token helpers, tracker base-URL normalisation, schema bootstrap. |
| `batch_support.php` | Batch workflow settings + `ensure_batch_storage()` migration bootstrap. |
| `sla_support.php` | Overdue thresholds and queries. |
| `sms_service.php` | PhilSMS integration, logging, delivery-status refresh. |
| `server_health.php` | JSON health check used by the recovery page. |
| `mobile-api.php`, `admin_document_activity.php`, `server_health.php` | Return JSON (including on DB outage). |
| `mobile-ui/` | React/Vite mobile client + Capacitor Android project. |
| `start-office-server.bat` → `tools/start-office-server.ps1` | Windows launcher: starts XAMPP, adds a Private-only firewall rule, prints LAN URLs. |

> **Why flat?** XAMPP serves this folder as the application root, and the clean
> routes, shared includes, and POST endpoints rely on those relative paths.
> Moving PHP into subfolders would break every `?action=` endpoint and the
> service-worker-free navigation.

---

## 3. Request flow (how a page is served)

There are two serving modes, and they must behave identically.

### 3a. Apache (normal office use)

```text
GET /DIT-LOGBOOK_RETRY/incoming
        │
        ▼
.htaccess
  RewriteCond ... (GET/HEAD only)
  RewriteRule ^(dashboard|...|incoming|...)/?$  $1.php  [END]
        │
        ▼
incoming.php  (SCRIPT_NAME = /DIT-LOGBOOK_RETRY/incoming.php)
```

1. **Canonical redirect:** if someone types `incoming.php`, `.htaccess` sends a
   `302` to `/incoming` — but **only for GET/HEAD**. POSTs are never redirected,
   so form submissions keep their body.
2. **Alias:** `document-tracker` maps to `document_tracker.php`.
3. **Root:** `/` redirects to `dashboard`.

### 3b. PHP built-in server (`php -S ... router.php`)

`router.php` mirrors the same rules in PHP:

1. Redirect `*.php` GET/HEAD requests to their clean path.
2. Resolve `document-tracker` → `document_tracker.php`.
3. Only allow an **explicit allow-list** of pages (`$adminPages` + `users`).
   Unknown pages return `404` — never an arbitrary PHP file.
4. Before including the target script it rewrites
   `$_SERVER['SCRIPT_FILENAME']` and `$_SERVER['SCRIPT_NAME']`.

> **Workaround explained:** `header.php` decides which sidebar link is active
> from `SCRIPT_FILENAME`. Because the router rewrites that variable, active
> navigation works identically under Apache and the built-in server. Without
> this, clean URLs would highlight nothing.

`... return false;` in the router lets the built-in server serve real static
files (CSS, JS, images, and `mobile-api.php`) directly.

---

## 4. Authentication and session flow

### 4a. Sign-in

```text
POST login (username, password, csrf_token)
  │
  ├─ verify_csrf_request()          ── 403 on missing/mismatched token
  ├─ SELECT password, role FROM users WHERE username = ?
  │
  ├─ password_verify(password, hash) → success
  │     ├─ session_regenerate_id(true)        (fixation defence)
  │     ├─ $_SESSION['admin']                 = username
  │     ├─ $_SESSION['admin_role']            = role
  │     ├─ $_SESSION['admin_password_hash']   = hash
  │     └─ redirect → dashboard
  │
  └─ legacy path: plaintext $hash === $password
        ├─ password_hash() → UPDATE users
        ├─ set the new hash in the session
        └─ redirect → dashboard
```

The **seeded `admin`/`admin`** account starts as plaintext and is upgraded to a
`password_hash()` value the first time it signs in successfully.

### 4b. Every protected page

```text
ensure_admin()
  ├─ no $_SESSION['admin'] → redirect to login
  ├─ re-read role + password hash from users
  ├─ role not in (admin, super_admin) → destroy session, redirect
  ├─ session hash ≠ database hash  → destroy session, redirect
  ├─ refresh $_SESSION['admin_role']
  └─ verify_csrf_request()  (POST only)
```

> **Password-bound sessions:** storing the current password hash in the session
> means *any* password change (Super Admin reset or self-change) instantly signs
> that account out everywhere. There is no separate "revoke sessions" table.

### 4c. CSRF

- `csrf_token()` lazily generates `bin2hex(random_bytes(32))` once per session.
- Every form renders it as a hidden field.
- `hash_equals()` compares in constant time (no timing oracle).

### 4d. Roles

| Role | Guard | Scope |
| --- | --- | --- |
| `super_admin` | `ensure_super_admin()` | All pages + `users.php` |
| `admin` | `ensure_admin()` | All pages except `users.php` |

Extra guards in `users.php`: a Super Admin account cannot be reset/deleted via
the portal, and nobody can delete their own account.

---

## 5. The document lifecycle

`documents` holds **both** directions in one table, distinguished by `type`.
This is the single most important structural decision in the system.

### 5.1 Status values

| Domain | Status | Meaning | Where it appears |
| --- | --- | --- | --- |
| Incoming | `Pending` | Received by the office, not yet processed | Incoming page |
| Outgoing | `For Release` | Ready to be claimed | Outgoing page |
| Outgoing | `Released` | Handed to the client | Outgoing page (history) |

Incoming page shows **only** `Pending`. Outgoing shows `For Release` and
`Released`. A document is never in two lists at once:

```text
type=Incoming, status=Pending      → Incoming page
type=Outgoing, status=For Release  → Outgoing page (top, oldest first)
type=Outgoing, status=Released     → Outgoing page (below, newest first)
```

### 5.2 Recording an incoming document

`incoming.php` → `isset($_POST['save'])`

1. Read `sender`, `submitter_name`, `submitter_type`, `subject`, `remarks`,
   `client_phone`.
2. Validate: submitter name required (≤255 chars), submitter type must be
   `Student | Faculty | Other`, contact number must be blank or exactly 11
   digits.
3. `INSERT INTO documents(type, sender, submitter_name, recipient, client_phone,
   subject, remarks, status, submitter_type) VALUES('Incoming', ?, ?, ?, ?, ?, ?,
   'Pending', ?)` — `recipient` is stored as an **empty string** because incoming
   forms deliberately do not collect a recipient.
4. Flash success; clear the form.

### 5.3 Prepare for Release (the state transition)

`incoming.php` → `isset($_POST['prepare_release'])`

```text
① SELECT sender, recipient, status FROM documents
     WHERE doc_id = ? AND type = 'Incoming'
② not found          → "already moved" message
③ status ≠ Pending   → "only pending documents" warning
④ UPDATE documents
     SET type = 'Outgoing',
         status = 'For Release',
         sender = <old recipient, or "Dean's Office" if blank>,
         recipient = <old sender>
     WHERE doc_id = ? AND type = 'Incoming' AND status = 'Pending'
⑤ affected_rows ≠ 1  → "document may have changed" error (race lost)
```

**Why the transition is an UPDATE, not a copy:** the `doc_id` stays the same, so
the tracking token, contact number, and audit trail follow the document into
Outgoing. `document_tracking_reference()` (`DIT-000123`) is derived from
`doc_id`, so the QR a client already holds keeps working after release.

**Why the WHERE clause repeats the status:** a mobile client or second staff
member may act on the same document at the same time. Repeating
`type='Incoming' AND status='Pending'` in the write makes the operation
atomic — exactly one caller can win.

**Why sender/recipient swap:** an incoming document arrives *from* the
submitter *to* the office. Once released it travels *from* the office *to* the
submitter. If no recipient was ever saved, `Dean's Office` becomes the sender so
the row is never empty.

### 5.4 Outgoing operations

| Action | Handler | Guard in SQL |
| --- | --- | --- |
| Edit subject / remarks / phone | `update` | `doc_id = ? AND type = 'Outgoing'` |
| Change status | `status_doc_id` | `type='Outgoing' AND status IN ('For Release','Released') AND status <> new` |
| Notify client | `sms_doc_id` | only `type='Outgoing' AND status='For Release'` |
| Issue tracking QR | `issue_tracking_qr` | `FOR UPDATE` transaction, only fills a `NULL` token |

Status changes are applied with a SQL predicate rather than a read-then-write,
so two staff members clicking at the same moment cannot both "win".

### 5.5 Ordering rules

| List | Order |
| --- | --- |
| Incoming | Priority first, then oldest first (FIFO) |
| Outgoing | `For Release` first, oldest first; then `Released`, newest first. A sort control switches to newest first |
| Bulk papers | Priority first, then newest |

**Why FIFO:** the office queue should surface the longest-waiting item, not the
newest. Priority is a separate flag (Faculty submissions), so it is applied as a
first sort key instead of changing `date_created`.

### 5.6 Priority

`UPDATE documents SET is_priority = IF(is_priority=1,0,1) WHERE doc_id = ? AND
type='Incoming' AND submitter_type='Faculty'`

> **Why Faculty only:** the toggle is deliberately enforced in SQL, so a
> crafted POST cannot mark a Student submission as priority. Affected rows `0`
> produces the "Priority is available only for faculty submissions" warning.

---

## 6. Bulk paper (batch) lifecycle

Bulk submissions are **batches that contain documents**. Three tables cooperate:

```text
document_batches  ──1:N──►  batch_papers  ──1:1──►  documents
   (the batch)               (the link)              (each paper)
```

### 6.1 Registration

`batch_papers.php` (admin) or `mobile-api.php` action `add_batch`:

1. Generate `batch_code` = `BP-YYYYMMDD-XXXXXX` (6 random hex chars).
2. Insert `document_batches` with `document_type` (`Incoming`/`Outgoing`),
   `batch_type`, `sender`, `sender_type`, `paper_count`, `is_priority`, `status='Pending'`.
3. Insert one `documents` row per paper (status `Pending`) and one `batch_papers`
   row linking each `doc_id` to the batch.
4. Optionally issue a `tracking_token` for the batch itself.
5. Mobile path runs inside a transaction and commits only after every insert.

`normalize_document_pages()` validates the per-paper page numbers (0–9999,
matching `paper_count`) and stores them as JSON.

### 6.2 Intake receipt (two modes)

Set in **Admin Settings → `intake_confirmation_mode`**:

| Mode | When the receipt is captured | Staff workflow |
| --- | --- | --- |
| `confirm_with_release` *(recommended)* | In the **same** POST that moves `Pending → For Release` | One action, one screen |
| `confirm_before_release` | Separately via **Scan CEIT QR** | Scan first, then advance status |

Receipt confirmation writes, in one atomic `UPDATE ... WHERE received_at IS NULL`:

```text
received_paper_count = <counted>
intake_expected_count = paper_count
intake_notes          = <note>
received_at           = NOW()
received_by           = <admin username>
```

Rules:

- Received count must be `0…9999`.
- If `received ≠ expected`, a note (≤1000 chars) is **required**.
- `WHERE received_at IS NULL` makes a second confirmation a no-op — a receipt
  can never be overwritten.
- **QR scanning itself never confirms receipt.** Scans are read-only lookups.

> **Why keep scans read-only?** A stray scan (or a phone left on the counter)
> must not be able to record a legal intake event. Only an explicit, confirmed
> staff action writes the receipt.

### 6.3 Status advance

`batch_papers.php` → `status_batch_id`:

```text
BEGIN TRANSACTION
  SELECT status, paper_count, received_at FROM document_batches
    WHERE batch_id = ? FOR UPDATE              ← row lock
  nextStatus = Pending → For Release
             | For Release → Released
             | Released → error ("already released")

  if mode = confirm_before_release
     and status = Pending
     and received_at IS NULL → DomainException (scan the QR first)

  if mode = confirm_with_release and no receipt yet
     → capture receipt here (see 6.2)

  UPDATE document_batches SET status = next
  UPDATE documents d JOIN batch_papers p ON p.doc_id = d.doc_id
     SET d.status = next WHERE p.batch_id = ?
COMMIT
```

> **Why `FOR UPDATE`:** two staff could otherwise advance the same batch twice,
> skipping `For Release`. The lock serialises them, and the whole sequence either
> commits or rolls back together (`rollBack()` on any `Throwable`).

> **Why a `DomainException`?** Domain errors are *expected* business-rule
> violations, so their message is shown verbatim. Any other exception is logged
> and replaced with a generic message — no SQL or stack traces leak to the UI.

### 6.4 Immutability after confirmation

Once `received_at` is set:

- `paper_count` cannot be edited (it is stored as `intake_expected_count`).
- The batch and its papers cannot be deleted, preserving the receipt.

Batch **processing status** (`Pending/For Release/Released`) remains independently
visible from receipt confirmation. A batch can be confirmed but still `Pending`.

---

## 7. Borrowed items flow

```text
item_categories ──1:N──► borrowed_items
```

1. `borrowed.php` ensures `borrower_phone` exists (idempotent `ALTER TABLE`).
2. Create: item name + borrower required; phone blank or 11 digits; optional
   `category_id` (invalid/`0` becomes `NULL` rather than a broken FK).
3. Status starts as `Borrowed`.
4. Return: status → `Returned` and `returned_date` set.
5. Filters: `All | Unreturned | Returned`.

> **Why `NULL` instead of `0` for category:** `borrowed_items.category_id` is a
> foreign key. Writing `0` would violate the constraint; `NULL` means
> "uncategorised".

Borrowed items are **not** part of the Outgoing overdue tile (they have their own
loan threshold). See [section 11](#11-overdue-sla-flow).

---

## 8. QR tracking flow

### 8.1 Token anatomy

```text
create_document_tracking_token() = bin2hex(random_bytes(32))  → 64 hex chars
document_tracking_reference($id) = 'DIT-' . str_pad($id, 6, '0', STR_PAD_LEFT)
```

Two independent identifiers on purpose:

| Identifier | Guessable? | Purpose |
| --- | --- | --- |
| `doc_id` / `DIT-000123` | Yes | Human reference on screen and in reports |
| `tracking_token` | No (2²⁵⁶ space) | The QR link's only credential |

The database stores the **token**, not a hash, because the token must be
re-encoded into a QR code at display time. Integrity is provided by 256 bits of
entropy plus a regex gate (`/^[a-f0-9]{64}$/`).

### 8.2 Issuing

| Flow | When |
| --- | --- |
| Mobile submission | Automatically, inside the submission transaction |
| Admin Incoming / Outgoing | Optional "issue tracking QR" action |
| Batch | Optional `tracking_token` on `document_batches` |

Issuance is always `UPDATE ... SET tracking_token = ? WHERE doc_id = ? AND
tracking_token IS NULL` — an existing token is never replaced, so a previously
printed QR keeps working forever.

### 8.3 Base URL resolution

`get_document_tracking_settings()`:

```text
configured = settings['document_tracker_base_url']   (normalised, optional)
enabled    = settings['document_tracking_enabled']   (default: enabled)

baseUrl = configured !== '' ? configured
                            : get_request_document_tracker_base_url()
final   = baseUrl . '/document-tracker'
```

`normalize_document_tracker_base_url()` rejects anything that is not
`http(s)://host[:port][/path]` and strips trailing slashes / query / fragment.
If no base URL can be derived, QR issuance **throws** instead of printing a
broken link.

> **Workaround explained:** under XAMPP the app usually lives at
> `http://<LAN-IP>/DIT-LOGBOOK_RETRY`. The request-derived fallback builds that
> path from `HTTP_HOST` + `dirname(SCRIPT_NAME)`, so links include the project
> folder name automatically — no hard-coded `/dit_logbook/`.

> **Workaround explained (the `:5173` trap):** the Vite dev server on `:5173`
> serves the *mobile app*. The tracker page and its JSON API are served by
> *Apache*. So `document_tracker_base_url` must never point to `:5173`.
> If someone scans an old QR whose host is an old Wi-Fi address, they should open
> the **mobile app's scanner** instead of the phone camera: the app verifies the
> token against the *current* server and re-opens the tracker there.

### 8.4 Reading

```text
Phone camera / in-app scanner
        │
        ▼
GET /document-tracker?token=<64 hex>
        │
        ▼
document_tracker.php
  ├─ ensure_document_tracking_schema()
  ├─ tracking disabled?      → "tracking is currently turned off"
  ├─ token fails regex?      → "invalid tracking code"
  └─ valid                   → fetch(mobile-api.php?action=document_tracker_status&token=…)
                                    │
                                    ▼
                             returns ONLY:
                               reference, type, subject, status, date
```

`mobile-api.php` returns `503` when tracking is disabled (this blocks *existing*
QR links too, not only new ones) and `404` for unknown/invalid tokens. Both the
page and the API send `X-Robots-Tag: noindex`.

> **Why the page calls the API instead of querying directly:** one read path
> means one place to enforce the enabled switch, the regex gate, and the field
> allow-list. It also means the tracker works from any device that can reach the
> API, independent of the admin session.

### 8.5 Shared document-type awareness

The tracker does not care whether a document is Incoming or Outgoing — it
reports whatever `status` is current. That is why a released document's QR
automatically shows `Released` without reissuing anything.

---

## 9. Mobile app and API flow

### 9.1 Boot sequence of `mobile-api.php`

The API deliberately runs its setup steps **before** dispatch, in this order:

```text
1. CORS headers (allow-list only)
2. action=document_tracker_status   ← handled before auth (public)
3. action=batch_tracker_status      ← handled before auth (public)
4. ensure entry_source column        ← idempotent migration
5. CREATE TABLE IF NOT EXISTS mobile_submission_logs
6. read mobile_kiosk_enabled         ← default ON when the row is missing
7. ensure_batch_storage()            ← batch schema migration
8. load form labels / app settings / batch workflow
9. POST login                        ← issues bearer token
10. bearer token verification
11. kiosk gate: !kioskEnabled && !token → 401
12. authenticated action dispatch
```

**Why the early exits for tracker actions:** the public tracker page must work
even when the kiosk is disabled. Moving those two actions above the gate is the
workaround that lets a printed QR keep working on a locked-down office network.

**Why `mobile_kiosk_enabled` defaults to ON when the row is missing:** a fresh
import of `schema.sql` contains no kiosk row. Treating "no preference saved" as
enabled makes the mobile app usable immediately after setup; administrators are
told in the README to turn it off on shared networks.

### 9.2 Authentication model

```text
POST { action: "login", username, password }
   │
   ├─ password_verify() (or legacy plaintext comparison)
   ├─ upgrade plaintext → password_hash() on success
   ├─ token = bin2hex(random_bytes(32))       ← returned ONCE
   ├─ store hash('sha256', token) in mobile_api_tokens, expires in 30 days
   ├─ DELETE expired tokens
   └─ { success, token, username, expiresAt }
```

Subsequent calls:

```text
Authorization: Bearer <64 hex>   (also read via getallheaders() fallback)
   │
   ├─ hash the presented token (SHA-256)
   ├─ SELECT user_id FROM mobile_api_tokens
   │    WHERE token_hash = ? AND expires_at > NOW()
   └─ gate: kiosk enabled  OR  token valid
```

> **Why only digests are stored:** a leaked database dump cannot be replayed
> against the API. The raw token exists only in the client.

> **Why `getallheaders()` fallback:** some Apache/PHP-FPM configurations do not
> populate `$_SERVER['HTTP_AUTHORIZATION']`. Checking the raw header list is a
> portability workaround that costs nothing on servers where it does work.

> **Known limitation:** the current React client has no sign-in screen. Turning
> the kiosk off therefore blocks it entirely until a sign-in UI is added.

### 9.3 Action catalogue

| Action | Method | Effect |
| --- | --- | --- |
| `login` / `logout` | POST | Issue / revoke bearer token |
| `get_agenda` | POST | Day's records, optional type + search |
| `prepare_incoming` / `release_incoming` | POST | Same transition as admin Prepare for Release |
| `set_outgoing_status` | POST | `For Release ↔ Released` |
| `notify_client` | POST | SMS for a `Released` outgoing document |
| `client_status_lookup` | POST | By 11-digit phone number; documents **UNION** batches |
| `get_report` | POST | Date-range report rows |
| `set_batch_status` | POST | Advance a batch one step |
| `add_batch` / `update_batch` | POST | Register / edit a bulk submission |
| `add_incoming` / `quick_submit_incoming` | POST | Mobile submission (+ tracking token) |
| `add_outgoing` | POST | Outgoing record |
| `add_borrowed` / `mark_borrowed_returned` | POST | Borrowed items |
| `document_tracker_status` | GET | Public single-document status |
| `batch_tracker_status` | GET | Public batch existence check |

### 9.4 Mobile submission (Standard vs Quick)

`add_incoming` and `quick_submit_incoming` share a branch but differ:

| | Standard (`add_incoming`) | Quick (`quick_submit_incoming`) |
| --- | --- | --- |
| Contact number | **Required** (11 digits) | Optional |
| `entry_source` | `standard` | `quick_submit` |
| Audit log `submission_type` | `Standard Submit` | `Quick Submit` |
| Shown in | Incoming page | `quick_document.php` |

The whole submission runs in one transaction:

```text
BEGIN
  INSERT documents (status='Pending', recipient="Dean's Office")
  INSERT mobile_submission_logs
  error_log("[Mobile submission] … Incoming document #N saved")
  if tracking enabled: UPDATE documents SET tracking_token = random
COMMIT
→ { reference: 'DIT-000123', trackerUrl, status: 'Pending' }
```

> **Why the tracker URL is returned immediately:** the client needs to render a
> QR before the user leaves the kiosk. If tracking is off, `trackerUrl` is
> `null` and the UI simply omits the QR.

> **Why a separate `mobile_submission_logs` table:** it records *how* a record
> arrived (device-side audit) independently of `documents`, so clearing or
> migrating document data does not lose the submission history unexpectedly —
> and the CMD log can count it separately.

### 9.5 Client status lookup

```sql
SELECT … FROM documents WHERE <digits-only client_phone> = ?
UNION ALL
SELECT … FROM document_batches WHERE recipient_contact_type = 'Contact'
                                   AND <digits-only recipient> = ?
ORDER BY date DESC LIMIT 20
```

Phone numbers are compared after stripping `+ - ( ) .` and spaces on **both**
sides, so `0917-123-4567` and `09171234567` match. Batch rows are only included
when the batch was explicitly addressed to a contact number.

### 9.6 CORS and dev proxy

`$allowedOrigins = ['http://localhost:5173', 'http://localhost',
'capacitor://localhost', 'ionic://localhost']` — matched against
`HTTP_ORIGIN`; native clients that send no `Origin` are also allowed.

In development, Vite proxies `/api` to the PHP app under the project's actual
`htdocs` folder name, so the browser never issues a cross-origin request at all.
CORS matters only for a built web app served from a different origin.

---

## 10. SMS and notification flow

```text
Trigger (outgoing "Notify client", sms.php manual send, mobile notify_client)
        │
        ▼
send_logged_sms($conn, $recipient, $message)
  ├─ trim, normalise recipient            → length 7…15
  ├─ ensure_sms_provider_tracking_schema()
  ├─ sms_enabled setting = 0?             → log Failed, return
  ├─ API token missing?                   → log Failed, return
  ├─ POST https://dashboard.philsms.com/api/v3/sms/send
  │     { recipient, sender_id, type, message }
  │        type = 'unicode' if non-ASCII else 'plain'
  ├─ parse message record → uid / message_id
  │                         status / delivery_status
  └─ persist attempt in sms_logs
       accepted + known status → that status
       accepted + no status    → 'Pending'   (never assume Delivered)
       rejected                → 'Failed'
```

`refresh_philsms_sms_statuses()` pulls delivery updates for stored
`provider_message_id` values; `provider_status_checked_at` throttles refreshes.

> **Why "Pending" instead of "Delivered":** PhilSMS can accept a message without
> immediately confirming delivery, and sometimes without an ID. Writing
> `Delivered` would be a lie; writing `Pending` and telling the operator *not to
> resend yet* prevents duplicate SMS charges.

> **Why gate on `sms_enabled`:** it lets an administrator disable outbound
> messages instantly (e.g. during a provider outage or credit exhaustion)
> without touching credentials.

Browser-side notifications are separate: activity polling, sounds, and
top-of-screen submission alerts are per-browser preferences stored locally, not
server settings.

---

## 11. Overdue (SLA) flow

```text
settings.key_name = 'sla_settings'  (JSON)
{ "enabled": true, "incomingDays": 3, "outgoingDays": 2, "loanDays": 7 }
```

`get_sla_overdue()` computes:

```sql
-- documents
(type='Incoming' AND status='Pending'     AND date_created < NOW() - INTERVAL ? DAY)
OR
(type='Outgoing' AND status='For Release' AND date_created < NOW() - INTERVAL ? DAY)

-- loans
status <> 'Returned' AND date_borrowed < NOW() - INTERVAL ? DAY
```

Notes:

- `sla_clamp_days()` clamps each threshold to `1…365`; anything else falls back
  to the default, so a corrupted setting can never produce a negative interval.
- When `enabled` is false the function returns empty arrays — the header badge
  and dashboard pop-out simply disappear.
- **Only documents are counted as overdue.** `documentCount` feeds the dashboard
  tiles and the sidebar badge; loans are reported separately.

> **Why separate incoming/outgoing thresholds:** an incoming document waits for
> office *processing*; an outgoing one waits for the *client* to claim it. Those
> are different service promises.

---

## 12. Reports, records, and exports

```text
reports.php
  ├─ report_type ∈ { All, Incoming, Outgoing, Borrowed Items, SMS Logs }
  ├─ from / to (validated Y-m-d; from ≤ to)
  ├─ preview table: 25 rows per page
  ├─ search + status filters (affect the visible set and record count)
  ├─ Print → portrait layout, university letterhead,
  │          repeated table headings, SMS status guide
  └─ Excel export → formatted workbook, full (untruncated) content
```

`records.php` is the cross-source document search (submitter, sender, recipient,
subject, remarks, status).

> **Why print truncates but Excel does not:** paper has fixed columns; a
> 4,000-character remark would destroy the page layout. The workbook has real
> column widths, so it keeps every character.

Pagination rule: admin tables 10 rows, reports 25 rows — **printing and export
always include every matching record**, not just the current page.

---

## 13. Settings and configuration flow

All system-wide settings live in one key/value table:

| `key_name` | Type | Consumed by |
| --- | --- | --- |
| `mobile_kiosk_enabled` | `'0'` / `'1'` | `mobile-api.php` |
| `document_tracking_enabled` | `'0'` / `'1'` | `document_tracking.php`, trackers |
| `document_tracker_base_url` | URL | `document_tracking.php` |
| `sla_settings` | JSON | `sla_support.php` |
| `intake_confirmation_mode` | string | `batch_support.php` |
| `mobile_form_labels` | JSON | `mobile_form_settings.php` |
| `mobile_app_settings` | JSON | `mobile_app_settings.php` |
| `philsms_api_token`, `philsms_sender_id`, `sms_enabled` | string | `sms_service.php` |

Writes use `INSERT … ON DUPLICATE KEY UPDATE` so a missing row is created on
first save.

**Two kinds of preference — don't mix them up:**

| Scope | Where it is stored | Syncs across devices? |
| --- | --- | --- |
| Server settings (kiosk, tracker, SLA, labels, theme) | MySQL `settings` | Yes |
| Browser preferences (polling, sounds, alerts, Compact Mode, assistant) | Browser local storage | No — per browser |

Form labels are sanitised on read: a value must be non-empty and ≤60 characters,
and the legacy label `From` is silently rewritten to the default, which is the
workaround for data saved before the "Submitter type" rename.

---

## 14. Database schema walkthrough

```text
users ──1:N──► mobile_api_tokens
users ──1:N──► documents.entry_source (informational)
item_categories ──1:N──► borrowed_items
document_batches ──1:N──► batch_papers ──1:1──► documents
settings (flat key/value)
sms_logs (independent, keyed by phone number)
mobile_submission_logs (independent, records mobile arrival)
```

| Table | Purpose | Key fields |
| --- | --- | --- |
| `users` | Admin accounts | `username` UNIQUE, `password` (hash), `role` |
| `mobile_api_tokens` | Bearer sessions | `token_hash` CHAR(64) UNIQUE, `expires_at` |
| `documents` | Every document, both directions | `type`, `status`, `tracking_token`, `submitter_type`, `is_priority`, `entry_source` |
| `mobile_submission_logs` | Mobile arrival audit | `document_id`, `submission_type` |
| `item_categories` | Borrowable categories | `category_name` UNIQUE |
| `borrowed_items` | Loans | `status`, `due_date`, `returned_date` |
| `document_batches` | Bulk submissions | `batch_code` UNIQUE, `tracking_token`, receipt columns |
| `batch_papers` | Batch ↔ document links | `doc_id` UNIQUE, `ON DELETE CASCADE` |
| `sms_logs` | Outbound SMS | `provider_message_id`, `status` |
| `settings` | Configuration | `key_name` UNIQUE |

### 14.1 Self-healing schema

Several entry points call **idempotent** migration helpers on every request:

| Helper | Called by | Adds |
| --- | --- | --- |
| `ensure_document_tracking_schema()` | tracker page, admin pages | `tracking_token` + unique index |
| `ensure_batch_storage()` | batch pages, mobile API | batch columns, receipt columns, indexes, status backfill |
| `ensure_sms_provider_tracking_schema()` | `sms_service.php` | `provider_message_id`, `provider_status_checked_at` |
| inline `ALTER TABLE … IF NOT EXISTS` | `mobile-api.php`, `borrowed.php` | `entry_source`, `borrower_phone` |

> **Why not rely on `schema.sql` alone?** Existing installations are upgraded by
> copying files. Running the migrations at runtime means an older database keeps
> working after a code update without a manual SQL step. The cost is a few extra
> `SHOW INDEX` / `ALTER TABLE` checks per request, which is acceptable at this
> scale.

> **Why `doc_id` is UNIQUE in `batch_papers`:** a paper belongs to at most one
> batch, which prevents a document being double-counted in two receipts.

---

## 15. Security flow (defence in depth)

| Layer | Control | Where |
| --- | --- | --- |
| Transport | HTTPS for phone camera / non-local use | Apache / Vite `dev:lan` |
| Session | `HttpOnly`, `SameSite=Lax`, `Secure` when HTTPS | `auth.php` |
| Session | ID regenerated on sign-in | `login.php` |
| Session | Bound to the current password hash | `auth.php`, `login.php` |
| Authorisation | Role re-read from DB on every request | `ensure_admin()` |
| CSRF | Per-session random token, constant-time compare | `verify_csrf_request()` |
| SQL | Prepared statements for every value | `safe_query()` |
| Output | `htmlspecialchars(ENT_QUOTES, 'UTF-8')` | `sanitize()` |
| Validation | Server-side types, lengths, ranges, enums | every handler |
| Errors | Exceptions logged, generic messages shown | `db.php`, handlers |
| Public data | Tracker returns an allow-listed field set | `mobile-api.php` |
| Tokens | 32 random bytes, SHA-256 digests for API tokens | `document_tracking.php`, API |
| Destructive actions | Confirm dialog / two text phrases | UI, CMD log |
| Routing | Explicit allow-list, no arbitrary includes | `router.php`, `.htaccess` |
| Files | `database/.htaccess` blocks direct SQL access | `database/` |
| Network | Firewall rule Private-only | `start-office-server.ps1` |
| Caching | `Cache-Control: no-store` on live endpoints | API, health |
| Secrets | `.env`, dumps, CA, keys ignored by Git | `.gitignore` |

**Known gaps** (mitigate with your network/hosting): no MFA, no login rate
limiting or lockout, no app-set CSP/HSTS, and kiosk mode is unauthenticated by
design when enabled.

---

## 16. CMD log data maintenance

A terminal-style console that performs **one-way destructive maintenance** with
guarded confirmations.

```text
/clear                          → clears only the terminal display
/clear incoming|outgoing|batches|borrowed|sms
/purge <same scopes>
/clear all  |  /purge all
/cancel     → abort at either prompt
```

Confirmation flow:

```text
warning + affected-record counts
  → type YES
  → type the exact phrase, e.g. DELETE INCOMING
  → 120-second expiry after which the request dies
```

Behaviour after clearing:

- Emptied tables get `AUTO_INCREMENT = 1`.
- **Incoming and Outgoing share the `documents` counter.** If records of the
  other direction remain, the counter is *not* reset, so existing `doc_id`s are
  never reused.
- `all` also resets batch, batch-paper, borrowed, SMS, and mobile-submission
  counters.
- Admin accounts, settings, categories, and API tokens are **kept**.

> **Why two confirmations + expiry:** the operation cannot be undone from the
> UI. A single mis-click would otherwise be catastrophic; forcing a typed phrase
> makes the intent deliberate, and the expiry prevents a forgotten half-finished
> prompt from being completed later by someone else.

---

## 17. Workarounds and why they exist

This is the section to read before debugging anything.

| # | Situation | Workaround in the code | Why it is needed |
| --- | --- | --- | --- |
| 1 | Clean URLs without a framework | `.htaccess` for Apache, `router.php` for `php -S` | The app must run both from XAMPP and from the built-in server |
| 2 | Active sidebar link with clean URLs | Router rewrites `SCRIPT_FILENAME` | `header.php` keys navigation off the real script name |
| 3 | Folder rename without code edits | Link base built from `dirname(SCRIPT_NAME)` / `HTTP_HOST` | The project may live at any `htdocs/<name>` |
| 4 | Phone camera needs HTTPS | `npm run dev:lan` generates a host CA + IP-matched cert | Browsers refuse `getUserMedia()` on plain HTTP |
| 5 | Phone cannot trust an unknown CA | Settings downloads `ceit-logbook-local-ca.crt` to install on the phone | The phone must trust the issuer, not just the host |
| 6 | `localhost` on a phone points at the phone | UI detects insecure context and explains; QR **image upload** fallback | `localhost` is device-local, not the host PC |
| 7 | Old QRs contain an old Wi-Fi IP | In-app scanner re-validates the token against the current server | Printed codes must survive an IP change |
| 8 | `:5173` vs Apache confusion | Tracker base URL is configurable and documented as Apache-only | Vite serves the app; Apache serves the tracker API |
| 9 | Vite dev cannot call PHP | `/api` proxy to the real `htdocs` folder name | Avoids CORS and hard-coded paths in development |
| 10 | Android emulator cannot see the host | Default endpoint uses `10.0.2.2` | The emulator's loopback is itself |
| 11 | Built app on a real server | `VITE_API_ENDPOINT` at build time | `10.0.2.2` is meaningless on a physical device |
| 12 | Authorization header missing | `getallheaders()` fallback in the API | Some Apache/PHP setups drop `HTTP_AUTHORIZATION` |
| 13 | Concurrent status updates | Status predicates repeated in the `UPDATE` + `FOR UPDATE` locks | Two staff can click at once; only one must win |
| 14 | Legacy plaintext seed password | Upgrade to `password_hash()` on first successful login | The schema seeds `admin`/`admin` for local setup |
| 15 | Missing kiosk setting | Treated as **enabled** when the row is absent | Fresh installs have no row; documented as a risk |
| 16 | Older databases after an update | Runtime idempotent migrations | Files are copied over an existing install |
| 17 | No SQL step when adding features | `CREATE TABLE IF NOT EXISTS` + `ADD COLUMN IF NOT EXISTS` | Zero-downtime upgrades at office scale |
| 18 | Pre-rename form labels | `From` on read is rewritten to the default label | Data saved before the "Submitter type" change |
| 19 | Phone number formatting differences | Digits-only comparison in lookup queries | `0917-123-4567` vs `09171234567` |
| 20 | PhilSMS accepting without an ID | Log `Pending`, tell the operator not to resend | Prevents duplicate charges |
| 21 | DB outage shows raw errors | `render_database_unavailable_response()` | Users see a recovery page; JSON clients get 503 |
| 22 | Automatic recovery | Recovery page polls `server_health.php` every 10 s and on `online` | Staff should not have to guess when to retry |
| 23 | Windows firewall blocks LAN | Launcher installs a **Private-only** rule | Public networks are deliberately excluded |
| 24 | Report print overflow | Long details truncated in print only | Paper has fixed columns; Excel keeps everything |
| 25 | Admin Assistant without an AI service | Purely client-side rules, approval preview, never submits | No record data may leave the browser |
| 26 | Camera in Compact Mode | Scanner runs in an isolated dialog | The bottom navigation dock covered the preview |

---

## 18. Failure and recovery flow

```text
Any page → db.php connects
   │ success → normal request
   └ failure ─► render_database_unavailable_response()
                  ├─ JSON caller (Accept/Content-Type JSON or a known JSON script)
                  │     → 503 { success: false, retryable: true }
                  │     + Cache-Control: no-store + Retry-After: 15
                  └─ Browser
                        → styled recovery page (no connection details)
                        → "Try again" / "Return to admin portal"
                        → polls server_health.php every 10 s
                        → on 200 OK: window.location.replace(recoveryUrl)
```

`server_health.php` is the single probe: it reports whether the database
answers, so the recovery page has one source of truth.

> **Why not simply retry automatically?** Auto-redirecting on every poll would
> flicker and could interrupt a half-seen error. Replacing the location only on a
> confirmed healthy response is deliberate.

---

## 19. Sequence diagrams

### 19.1 Staff receives and releases a document

```text
Staff        incoming.php        documents (DB)       Client QR
  │               │                    │                  │
  ├─ save ───────►│ INSERT Incoming    │                  │
  │               │ status=Pending ───►│                  │
  ├─ issue QR ───►│ UPDATE token ─────►│                  │
  │               │                    │                  │
  │               │◄── print QR ───────┼──────────────────┤
  │               │                    │        scan ─────►│
  │               │                    │◄── tracker GET ──┤
  │               │                    │── status only ──►│
  ├─ prepare ────►│ SELECT … FOR UPDATE│                  │
  │  release      │ UPDATE type=Outgoing, status=For Release
  │               │ (sender↔recipient) ─►│                 │
  │               │                    │    same token still valid
  │               │                    │◄── tracker GET ──┤
  │               │                    │── "For Release" ►│
  ├─ notify ─────►│ send_logged_sms ───┼──────► PhilSMS   │
  └─ release ────►│ status=Released ──►│                  │
                  │                    │◄── tracker GET ──┤
                  │                    │── "Released" ───►│
```

### 19.2 Mobile submission

```text
Phone (Vite app)          Vite proxy         mobile-api.php        DB
     │                        │                    │               │
     ├─ POST /api ───────────►│ ──────────────────►│               │
     │  add_incoming          │  /mobile-api.php   ├─ kiosk gate   │
     │                        │                    ├─ BEGIN        │
     │                        │                    ├─ INSERT doc ─►│
     │                        │                    ├─ INSERT log ─►│
     │                        │                    ├─ token ──────►│
     │                        │                    ├─ COMMIT       │
     │◄── reference+trackerUrl┼────────────────────┤               │
     ├─ render QR             │                    │               │
     └─ done                  │                    │               │
```

### 19.3 Batch advance (confirm with release)

```text
Staff      batch_papers.php               DB
  │              │                         │
  ├─ advance ───►│ BEGIN                    │
  │              ├─ SELECT … FOR UPDATE ──►│ (row locked)
  │              ├─ receipt required?       │
  │              │    received ≠ expected → note required
  │              ├─ UPDATE receipt WHERE received_at IS NULL ─►│
  │              ├─ UPDATE batch status ──────────────────────►│
  │              ├─ UPDATE linked documents ──────────────────►│
  │              ├─ COMMIT                  │
  │◄─ flash ─────┤  (rollback on any error) │
```

---

## 20. Glossary

| Term | Meaning here |
| --- | --- |
| **Batch / bulk papers** | One submission containing many papers, tracked as a unit with per-paper document rows |
| **Intake receipt** | The recorded physical count received, with expected count, note, time, and staff name |
| **Kiosk mode** | Unauthenticated mobile access controlled by `mobile_kiosk_enabled` |
| **Tracking token** | 64-hex, unguessable credential embedded in a QR code |
| **Reference** | Human-facing `DIT-000123`, derived from `doc_id` |
| **SLA / overdue** | Configurable day thresholds per direction and for loans |
| **Prepare for Release** | Incoming → Outgoing transition |
| **Priority** | Faculty-only submission flag that floats to the top of a list |
| **Safe query** | `safe_query()` — a prepared statement wrapper that always closes on failure |
| **Recovery page** | Styled 503 page with automatic health polling |
| **Compact Mode** | Phone-style admin layout available on any screen size |

---

## Where to look next

- [README](../README.md) — setup, features, patch history
- [Security guide](SECURITY.md) — security model and deployment checklist
- [Windows setup](WINDOWS-SETUP.md) — first-time setup and computer transfer
- [Patch history](Patch%20V1.00.md) — versioned change log
