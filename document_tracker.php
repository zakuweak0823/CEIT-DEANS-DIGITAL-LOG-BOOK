<?php
require_once 'db.php';
require_once 'document_tracking.php';

header('X-Robots-Tag: noindex, nofollow');
$storageReady = true;
$trackerEnabled = false;
try {
    ensure_document_tracking_schema($conn);
    $trackingSettings = get_document_tracking_settings($conn);
    $trackerEnabled = $trackingSettings['enabled'];
} catch (Throwable $error) {
    error_log('Document tracker could not be initialized: ' . $error->getMessage());
    http_response_code(500);
    $storageReady = false;
}
$trackingToken = trim((string)($_GET['token'] ?? ''));
$apiPath = trim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/'))), '/.');
$apiUrl = ($apiPath === '' ? '' : '/' . $apiPath) . '/mobile-api.php';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#064e3b">
    <meta name="robots" content="noindex,nofollow">
    <title>Document Tracker | CEIT Dean's Office</title>
    <style>
        :root { color-scheme: light; font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; background: #f0fdf4; color: #12372d; }
        * { box-sizing: border-box; }
        body { min-height: 100vh; margin: 0; padding: max(1rem, env(safe-area-inset-top)) 1rem max(1rem, env(safe-area-inset-bottom)); display: grid; place-items: center; }
        main { width: min(100%, 34rem); }
        .card { overflow: hidden; border: 1px solid #bbf7d0; border-radius: 1.5rem; background: #fff; box-shadow: 0 20px 50px #064e3b14; }
        .brand { padding: 1.5rem 1.5rem 1.25rem; border-bottom: 1px solid #dcfce7; }
        .eyebrow { margin: 0 0 .4rem; color: #047857; font-size: .75rem; font-weight: 800; letter-spacing: .14em; text-transform: uppercase; }
        h1 { margin: 0; color: #064e3b; font-size: clamp(1.45rem, 5vw, 2rem); line-height: 1.15; }
        .body { padding: 1.5rem; }
        .intro { margin: 0 0 1.25rem; color: #526d63; line-height: 1.55; }
        .notice { padding: 1rem; border: 1px solid #bbf7d0; border-radius: 1rem; background: #f0fdf4; }
        .notice p { margin: 0; }
        .record-title { margin: 0 0 1.25rem; color: #102e24; font-size: 1.15rem; overflow-wrap: anywhere; }
        dl { display: grid; grid-template-columns: minmax(7.5rem, .7fr) 1fr; gap: .9rem 1rem; margin: 0; }
        dt { color: #527066; font-size: .9rem; }
        dd { margin: 0; color: #183b30; font-weight: 650; overflow-wrap: anywhere; }
        .status { display: inline-flex; align-items: center; border-radius: 999px; padding: .4rem .75rem; background: #fef9c3; color: #1f2937; font-size: .88rem; }
        .status.is-done { background: #dcfce7; color: #166534; }
        .footer { margin: 1.25rem 0 0; color: #687d75; font-size: .85rem; line-height: 1.5; }
        button { min-height: 2.8rem; margin-top: 1.25rem; padding: .7rem 1rem; border: 0; border-radius: .85rem; background: #065f46; color: white; font: inherit; font-weight: 700; cursor: pointer; }
        button:focus-visible, .return-link:focus-visible { outline: 3px solid #6ee7b7; outline-offset: 3px; }
        .return-link { display: inline-flex; align-items: center; justify-content: center; min-height: 2.8rem; margin: 1.25rem 0 0 .6rem; padding: .7rem 1rem; border: 1px solid #86efac; border-radius: .85rem; background: #fff; color: #065f46; font-weight: 700; text-decoration: none; }
        .return-link:hover { background: #f0fdf4; }
        [hidden] { display: none !important; }
        @media (max-width: 420px) { .brand, .body { padding: 1.2rem; } dl { grid-template-columns: 1fr; gap: .3rem; } dd { margin-bottom: .55rem; } }
    </style>
</head>
<body>
<main>
    <article class="card">
        <header class="brand">
            <p class="eyebrow">CEIT Dean's Office</p>
            <h1>Document status tracker</h1>
        </header>
        <div class="body">
            <p class="intro">This page shows the latest status recorded by the office for the document linked to your official tracking QR code.</p>
            <?php if (!$storageReady): ?>
                <div class="notice" role="alert"><p>Tracking is temporarily unavailable. Please try again later or contact the office.</p></div>
            <?php elseif (!$trackerEnabled): ?>
                <div class="notice" role="status"><p>Document tracking is currently turned off by the office.</p></div>
            <?php elseif (preg_match('/^[a-f0-9]{64}$/', $trackingToken) !== 1): ?>
                <div class="notice" role="alert"><p>This tracking code is invalid. Scan the QR code issued by the CEIT Dean's Office.</p></div>
            <?php else: ?>
                <section id="trackerNotice" class="notice" role="status" aria-live="polite"><p>Checking your tracking code…</p></section>
                <section id="trackerRecord" hidden>
                    <h2 class="record-title" id="recordSubject"></h2>
                    <dl>
                        <dt>Reference</dt><dd id="recordReference"></dd>
                        <dt>Document type</dt><dd id="recordType"></dd>
                        <dt>Current status</dt><dd><span class="status" id="recordStatus"></span></dd>
                        <dt>Date submitted</dt><dd id="recordDate"></dd>
                    </dl>
                    <p class="footer">Status updates are recorded by the office. This page refreshes automatically every minute.</p>
                    <button type="button" id="refreshTracker">Refresh status</button>
                </section>
            <?php endif; ?>
            <a class="return-link" id="returnLink" href="./">Return</a>
        </div>
    </article>
</main>
<?php if ($storageReady && $trackerEnabled && preg_match('/^[a-f0-9]{64}$/', $trackingToken) === 1): ?>
<script>
const trackerApi = <?php echo json_encode($apiUrl, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
const trackerToken = <?php echo json_encode($trackingToken, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
const notice = document.getElementById('trackerNotice');
const recordPanel = document.getElementById('trackerRecord');

async function refreshDocumentStatus() {
    notice.hidden = false;
    notice.textContent = 'Checking your tracking code…';
    try {
        const response = await fetch(`${trackerApi}?action=document_tracker_status&token=${encodeURIComponent(trackerToken)}`, { cache: 'no-store' });
        const result = await response.json();
        if (!response.ok || !result.success) throw new Error(result.message || 'This tracking code could not be verified.');
        document.getElementById('recordSubject').textContent = result.record.subject;
        document.getElementById('recordReference').textContent = result.record.reference;
        document.getElementById('recordType').textContent = result.record.type;
        document.getElementById('recordDate').textContent = result.record.date;
        const status = document.getElementById('recordStatus');
        status.textContent = result.record.status;
        status.classList.toggle('is-done', ['Released', 'Completed', 'Returned'].includes(result.record.status));
        notice.hidden = true;
        recordPanel.hidden = false;
    } catch (error) {
        recordPanel.hidden = true;
        notice.textContent = error instanceof Error ? error.message : 'Document status could not be loaded. Check your connection and try again.';
    }
}

document.getElementById('refreshTracker').addEventListener('click', refreshDocumentStatus);
refreshDocumentStatus();
window.setInterval(refreshDocumentStatus, 60000);
</script>
<?php endif; ?>
<script>
(function () {
    const link = document.getElementById('returnLink');
    if (!link) return;
    const sameHost = (value) => {
        if (!value) return null;
        try {
            const url = new URL(value, window.location.href);
            if (!['http:', 'https:'].includes(url.protocol) || url.username || url.password || url.hostname !== window.location.hostname) return null;
            if (window.location.protocol === 'https:' && url.protocol === 'http:' && url.port === '') url.protocol = 'https:';
            return url;
        } catch (error) {
            return null;
        }
    };
    const requested = sameHost(new URLSearchParams(window.location.search).get('return') || '');
    const referrer = document.referrer ? sameHost(document.referrer) : null;
    const target = requested || (referrer && !/tracker/i.test(referrer.pathname) ? referrer : null);
    if (target) link.href = target.href;
    else if (window.location.port === '5173') link.href = '/';
})();
</script>
</body>
</html>
