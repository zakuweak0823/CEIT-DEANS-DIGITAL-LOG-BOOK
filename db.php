<?php
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// The shortest password allowed for administrator accounts; every form and check reads this one value.
const ADMIN_PASSWORD_MIN_LENGTH = 8;

// Centralize the connection so admin pages and the mobile API share one UTF-8 database setup.
$host = "localhost";
$user = "root";
$pass = "";
$db = "dit_logbook";

function render_database_unavailable_response(): void
{
    http_response_code(503);
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Retry-After: 15');

    $acceptHeader = (string)($_SERVER['HTTP_ACCEPT'] ?? '');
    $contentType = (string)($_SERVER['CONTENT_TYPE'] ?? '');
    $scriptName = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
    $isJsonRequest = stripos($acceptHeader, 'application/json') !== false
        || stripos($contentType, 'application/json') !== false
        || in_array($scriptName, ['mobile-api.php', 'admin_document_activity.php', 'server_health.php'], true);

    if ($isJsonRequest) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'message' => 'The office database is temporarily unavailable.',
            'retryable' => true,
        ]);
        exit;
    }

    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $recoveryPage = empty($_SESSION['admin']) ? 'login' : 'dashboard';
    $scriptDirectory = rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/'))), '/.');
    $recoveryUrl = ($scriptDirectory === '' ? '' : $scriptDirectory) . '/' . $recoveryPage;
    $healthUrl = ($scriptDirectory === '' ? '' : $scriptDirectory) . '/server_health.php';
    header('Content-Type: text/html; charset=utf-8');
    ?>
    <!doctype html>
    <html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="theme-color" content="#f1f8f4">
        <title>Connection unavailable | CEIT Digital Logbook</title>
        <style>
            :root{color-scheme:light;--green:#0f5132;--deep:#064e3b;--muted:#65766b;--line:#dce9e0;--surface:#fff}
            *{box-sizing:border-box}
            body{min-height:100vh;margin:0;padding:24px;display:grid;place-items:center;background:radial-gradient(ellipse at 50% 0%,#d9f0e2 0,transparent 55%),#f1f8f4;color:#183a2b;font-family:Inter,"Segoe UI",Arial,sans-serif}
            main{width:min(100%,520px);padding:clamp(24px,6vw,42px);border:1px solid rgba(15,81,50,.1);border-radius:24px;background:rgba(255,255,255,.96);box-shadow:0 24px 70px rgba(17,65,41,.12);text-align:center}
            .brand{display:inline-flex;align-items:center;gap:10px;margin-bottom:30px;color:var(--deep);font-size:13px;font-weight:700;letter-spacing:.02em}
            .brand-mark{width:36px;height:36px;display:grid;place-items:center;border-radius:12px;background:#e7f5ec;color:var(--green)}
            .brand-mark svg{width:21px;height:21px}
            .status-icon{width:76px;height:76px;margin:0 auto 22px;display:grid;place-items:center;border:1px solid #f2dfb7;border-radius:24px;background:#fff8e9;color:#a56408}
            .status-icon svg{width:36px;height:36px}
            .eyebrow{margin:0 0 8px;color:#9a650f;font-size:11px;font-weight:800;letter-spacing:.14em;text-transform:uppercase}
            h1{margin:0;color:#173d2d;font-size:clamp(25px,5vw,32px);letter-spacing:-.035em;line-height:1.15}
            .description{margin:14px auto 0;max-width:390px;color:var(--muted);font-size:15px;line-height:1.65}
            .notice{margin:22px 0;padding:13px 15px;border:1px solid #f1e6cc;border-radius:13px;background:#fffbf2;color:#775923;font-size:13px;line-height:1.55;text-align:left}
            .actions{display:grid;gap:10px;margin-top:24px}
            .button{min-height:48px;display:inline-flex;align-items:center;justify-content:center;gap:9px;padding:12px 18px;border:1px solid transparent;border-radius:13px;background:var(--green);color:white;font:inherit;font-size:14px;font-weight:700;text-decoration:none;cursor:pointer}
            .button:hover{background:var(--deep)}
            .button-secondary{border-color:var(--line);background:white;color:var(--deep)}
            .button-secondary:hover{background:#f6faf7}
            .button:focus-visible{outline:3px solid rgba(15,81,50,.25);outline-offset:3px}
            .hint{margin:18px 0 0;color:#839087;font-size:12px;line-height:1.5}
            @media(prefers-reduced-motion:no-preference){.status-icon{animation:float 3s ease-in-out infinite}@keyframes float{50%{transform:translateY(-4px)}}}
        </style>
    </head>
    <body>
        <main>
            <div class="brand">
                <span class="brand-mark" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M6 3.75h8l4 4v12.5H6z"/><path d="M14 4v4h4M9 12h6M9 15.5h6"/></svg>
                </span>
                <span>CVSU CEIT Dean's Office<br>Digital Logbook</span>
            </div>
            <div class="status-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9a14 14 0 0 1 18 0M6.5 12.5a8.5 8.5 0 0 1 11 0M10 16a3.2 3.2 0 0 1 4 0"/><path d="M12 20h.01M4 4l16 16"/></svg>
            </div>
            <p class="eyebrow">Connection interrupted</p>
            <h1>We can't reach the office database</h1>
            <p class="description">The logbook server is temporarily unable to connect to its database. Your records are not available until the connection is restored.</p>
            <div class="notice" role="status">If you submitted a change just before this message appeared, check the records before trying that action again.</div>
            <div class="actions">
                <a class="button" href="<?php echo htmlspecialchars($recoveryUrl, ENT_QUOTES, 'UTF-8'); ?>">Try again</a>
                <a class="button button-secondary" href="<?php echo htmlspecialchars($recoveryUrl, ENT_QUOTES, 'UTF-8'); ?>">Return to the admin portal</a>
            </div>
            <p class="hint">This page checks for recovery automatically. It will reopen the portal when the database responds.</p>
        </main>
        <script>
            (function () {
                const recoveryUrl = <?php echo json_encode($recoveryUrl); ?>;
                const healthUrl = <?php echo json_encode($healthUrl); ?>;
                let checking = false;
                const checkConnection = async function () {
                    if (checking || !navigator.onLine) return;
                    checking = true;
                    try {
                        const response = await fetch(healthUrl, {
                            cache: 'no-store',
                            headers: { 'Accept': 'application/json' }
                        });
                        if (response.ok) window.location.replace(recoveryUrl);
                    } catch (error) {
                        console.info('The office database is still unavailable; checking again shortly.', error);
                    } finally {
                        checking = false;
                    }
                };
                window.setInterval(checkConnection, 10000);
                window.addEventListener('online', checkConnection);
            }());
        </script>
    </body>
    </html>
    <?php
    exit;
}

try {
    $conn = mysqli_connect($host, $user, $pass, $db);
    if (!$conn) {
        throw new Exception("Connection Failed: " . mysqli_connect_error());
    }
    mysqli_set_charset($conn, "utf8mb4");
} catch (Exception $e) {
    error_log($e->getMessage());
    render_database_unavailable_response();
}

function safe_query($conn, $query, $types = '', $params = []) {
    $stmt = mysqli_prepare($conn, $query);
    if ($stmt === false) {
        error_log("Failed to prepare query: " . mysqli_error($conn));
        return false;
    }
    if ($types !== '' && !empty($params)) {
        // mysqli_stmt_bind_param needs references; keep the values alive for the call.
        $refs = array();
        foreach($params as $key => $value) {
            $refs[$key] = &$params[$key];
        }
        mysqli_stmt_bind_param($stmt, $types, ...$refs);
    }
    if (!mysqli_stmt_execute($stmt)) {
        error_log("Failed to execute query: " . mysqli_stmt_error($stmt));
        mysqli_stmt_close($stmt);
        return false;
    }
    return $stmt;
}

function safe_scalar($conn, $query, $types = '', $params = []) {
    $stmt = safe_query($conn, $query, $types, $params);
    if ($stmt === false) return 0;
    // This helper owns the statement and returns only the first selected value.
    mysqli_stmt_bind_result($stmt, $result);
    mysqli_stmt_fetch($stmt);
    mysqli_stmt_close($stmt);
    return $result ?? 0;
}

function contact_number_input($value) {
    return is_string($value) ? trim($value) : null;
}

function is_valid_contact_number($value): bool {
    return is_string($value) && preg_match('/^[0-9]{11}$/', $value) === 1;
}
?>
