# Windows Setup and Computer-to-Computer Use

This guide covers setting up the CEIT Dean's Digital Logbook on a Windows
computer with XAMPP and accessing it from other devices on the same trusted
local network.

## Requirements

- Windows and XAMPP with Apache, MySQL/MariaDB, and PHP 8+
- PHP extensions `mysqli` and `curl`
- Apache `mod_rewrite` enabled
- The project folder placed somewhere inside XAMPP's `htdocs` directory

For example, the project may be located at
`C:\xampp\htdocs\DIT-LOGBOOK_RETRY` or at
`C:\xampp\htdocs\Office Logbook`. The folder name can vary; the app detects it
automatically.

## First-time setup

1. Copy the complete project folder into XAMPP's `htdocs` directory.
2. Start MySQL in the XAMPP Control Panel. Open phpMyAdmin and import
   [`database/schema.sql`](../database/schema.sql) to initialize a new, empty
   database.
3. Check the connection settings in [`db.php`](../db.php). The defaults are
   database `dit_logbook`, user `root`, and an empty password. Adjust them if
   the local MySQL account is configured differently.
4. Double-click [`start-office-server.bat`](../start-office-server.bat).
   The launcher starts MySQL and Apache if needed, detects the project
   directory, and opens the admin portal.
5. On first launch, approve the Windows administrator prompt. It creates a
   firewall rule allowing the detected XAMPP Apache executable on TCP port 80
   **only on Private networks**. MySQL is not opened to the network.
6. Sign in with the initial credentials shown in the main
   [README](../README.md#2-sign-in-for-the-first-time), then change the
   password.

The launcher prints a local URL and any detected Private-network addresses.
To open the admin portal from another computer, use one of those network
addresses, such as `http://192.168.1.25/DIT-LOGBOOK_RETRY/`. Keep the host
computer connected to the network with Apache and MySQL running.

If Windows classifies a trusted office or home network as **Public**, the
firewall rule intentionally blocks other computers. Change only a network you
trust to **Private** in Windows Settings, then run the launcher again. Do not
enable inbound access on Public networks.

## Move the application and database

1. On the old computer, use phpMyAdmin to export the `dit_logbook` database as
   an SQL file. `database/schema.sql` is only the schema initializer; it does
   not contain your live records.
2. Copy the project folder to a location inside the new computer's XAMPP
   `htdocs` directory. You may use a different folder name.
3. On the new computer, start MySQL and import the SQL export in phpMyAdmin.
   Do not copy a live MySQL data directory while its server is running.
4. Check the MySQL settings in the copied `db.php` file. Update them if the
   new computer uses different database credentials.
5. Double-click `start-office-server.bat` on the new computer and approve the
   Private-network firewall prompt. Use the network URL it prints on other
   computers.
6. In **Admin Settings → Document status tracking**, update the Tracker base
   URL to the new computer's phone-reachable Apache project URL. Use HTTPS if
   Apache HTTPS is configured; otherwise, on a trusted local network, use the
   printed HTTP LAN URL. Do not include `:5173`, which is only the Vite mobile
   app. QR tracking settings are stored in the database, so an imported
   database may still contain the old computer's URL.

There is no need to edit `.htaccess` or the mobile UI's API path when the
project folder is renamed.

## Access outside the local network

This setup is for the host computer and devices on the same trusted local
network. It does **not** make the website available over mobile data or the
public internet. Do not expose XAMPP, Apache, or MySQL directly to the
internet. For remote access, deploy the application to a properly secured
server with HTTPS.

Phone camera scanning requires a trusted HTTPS connection. Certificates are
specific to a host, so do not copy the old computer's private keys. For the
React/Vite mobile app, start the office server first, then run `npm install`
and `npm run dev:lan` from `mobile-ui` on the new computer. Its setup step
creates a new local CA and a server certificate matching the new computer's
LAN IP with XAMPP OpenSSL. Open the Vite HTTPS LAN address it prints (port
5173), then download the public CA from Admin Settings and install/trust it on
each phone. Keep the private keys under XAMPP's Apache `conf\ssl.key`; they
must not be transferred or committed. The launcher allows ports 80, 443, and
5173 only on Private networks; stop any existing Vite process using port 5173
before starting the LAN server.

## Troubleshooting

| Problem | What to check |
| --- | --- |
| Launcher cannot find XAMPP | Put the project folder somewhere inside XAMPP's `htdocs` directory. |
| MySQL or Apache does not start | Check the corresponding XAMPP window and confirm another service is not already using port 3306 or 80. |
| Database connection error | Start MySQL; confirm the imported database is named `dit_logbook` and check `db.php` credentials. |
| Another computer cannot connect | Run the launcher on the host, approve the firewall prompt, make sure both computers share the same trusted Private network, and use the printed network URL. |
| Clean admin page URLs return 404 | Confirm Apache `mod_rewrite` is enabled and `.htaccess` overrides are allowed. |
| Phone camera is unavailable | For the Vite mobile app, use `npm run dev:lan` and open its `https://<office-LAN-IP>:5173/` address. Install and trust the office CA certificate on the phone. Run `start-office-server.bat` to configure the Private-network firewall rules; upload a QR image if HTTPS is not available. |
| `npm run dev:lan` cannot find or create the LAN certificate | Run the command from `mobile-ui` so its `predev:lan` setup runs. Confirm XAMPP is above this project in `htdocs`, `apache\bin\openssl.exe` exists, and your Windows account can write to Apache's `conf\ssl.crt` and `conf\ssl.key` folders. |
| npm reports audit or install-script warnings | Those warnings are separate from LAN TLS setup. Do not run `npm audit fix --force` just to start the mobile app; it can change dependency versions. Run `npm install`, then `npm run dev:lan` from `mobile-ui`. |
| QR tracker opens but says “Failed to fetch” | Use the office Apache LAN address with the project folder, for example `http://<office-LAN-IP>/<project-folder>/document-tracker?...` or HTTPS if Apache HTTPS is configured. In Admin Settings, set the Tracker base URL to that Apache project URL without `:5173`; port 5173 is only for the Vite mobile app. Confirm Apache/PHP and MySQL are running. |

For mobile web development and Android builds, continue with the
[Mobile app section in the main README](README.md#mobile-app).
