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
        main { width: min(100%, 48rem); }
        .card { overflow: hidden; border: 1px solid #bbf7d0; border-radius: 1.25rem; background: #fff; box-shadow: 0 20px 50px #064e3b14; }
        .body { padding: clamp(1.25rem, 5vw, 3rem); }
        .receipt-letterhead { display: grid; grid-template-columns: 4.5rem minmax(0, 1fr) auto; align-items: center; gap: 1rem; padding-bottom: 1.25rem; border-bottom: 2px solid #0f5132; }
        .receipt-letterhead img { width: 4.25rem; height: 4.25rem; object-fit: contain; }
        .receipt-letterhead p { margin: 0; color: #466356; font-size: .78rem; line-height: 1.45; }
        .receipt-letterhead .receipt-university { color: #064e3b; font-size: 1rem; font-weight: 800; letter-spacing: .035em; }
        .receipt-stamp { text-align: right; }
        .receipt-stamp span { display: block; color: #0f5132; font-size: .68rem; font-weight: 800; letter-spacing: .12em; }
        .receipt-stamp strong { display: block; margin-top: .25rem; color: #064e3b; font-size: .9rem; overflow-wrap: anywhere; }
        h1 { margin: 1.25rem 0 .25rem; color: #064e3b; font-size: 1.55rem; font-weight: 750; line-height: 1.2; }
        .intro { margin: 0 0 1.25rem; color: #526d63; line-height: 1.55; }
        .notice { padding: 1rem; border: 1px solid #bbf7d0; border-radius: 1rem; background: #f0fdf4; }
        .notice p { margin: 0; }
        .record-title { margin: 0 0 1rem; color: #102e24; font-size: 1.15rem; overflow-wrap: anywhere; }
        .receipt-details { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: .85rem; margin: 0 0 1.25rem; }
        .receipt-detail { min-width: 0; padding: .8rem .9rem; border: 1px solid #dce9df; border-radius: .75rem; background: #fbfdfb; }
        .receipt-detail dt { margin-bottom: .2rem; color: #60766b; font-size: .7rem; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; }
        .receipt-detail dd { margin: 0; color: #173d2c; font-size: .95rem; font-weight: 700; overflow-wrap: anywhere; }
        .receipt-reference { color: #0f5132 !important; }
        .status { display: inline-block; padding: .3rem .75rem; border-radius: 999px; background: #fef9c3; color: #713f12; font-size: .85rem; font-weight: 700; line-height: 1.2; }
        .status.is-done { background: #dcfce7; color: #166534; }
        .status.is-release { background: #dbeafe; color: #1e3a8a; }
        .receipt-confirmation { padding: 1rem 1.1rem; border: 1px solid #b7ddc3; border-left: 4px solid #16834a; border-radius: .75rem; background: #f1faf3; }
        .receipt-confirmation p { margin: 0 0 .25rem; }
        .receipt-confirmation p:last-child { margin-bottom: 0; }
        .receipt-signoff { display: grid; grid-template-columns: 1fr 1fr; gap: 2rem; margin-top: 2.5rem; }
        .receipt-signoff div { padding-top: .45rem; border-top: 1px solid #506b5c; color: #526d63; font-size: .78rem; }
        .receipt-print-meta { margin: 1.5rem 0 0; padding-top: .75rem; border-top: 1px solid #dce9df; color: #60766b; font-size: .72rem; text-align: center; }
        .actions { display: flex; flex-wrap: wrap; gap: .6rem; margin-top: 1.25rem; }
        button { min-height: 2.8rem; padding: .7rem 1rem; border: 0; border-radius: .85rem; background: #065f46; color: white; font: inherit; font-weight: 700; cursor: pointer; }
        button.is-secondary { border: 1px solid #86efac; background: #fff; color: #065f46; }
        button:focus-visible, .return-link:focus-visible { outline: 3px solid #6ee7b7; outline-offset: 3px; }
        .return-link { display: inline-flex; align-items: center; justify-content: center; min-height: 2.8rem; margin-top: 1.25rem; padding: .7rem 1rem; border: 1px solid #86efac; border-radius: .85rem; background: #fff; color: #065f46; font-weight: 700; text-decoration: none; }
        .actions + .return-link { margin-top: .6rem; }
        .return-link:hover { background: #f0fdf4; }
        [hidden] { display: none !important; }
        @media (max-width: 520px) {
            .receipt-letterhead { grid-template-columns: 3.4rem minmax(0, 1fr); gap: .7rem; }
            .receipt-letterhead img { width: 3.25rem; height: 3.25rem; }
            .receipt-stamp { grid-column: 1 / -1; display: flex; justify-content: space-between; align-items: baseline; text-align: left; }
            .receipt-details { grid-template-columns: 1fr; gap: .55rem; }
            .receipt-signoff { gap: 1rem; }
        }
        @media print {
            @page { size: A4 portrait; margin: 14mm; }
            body { min-height: 0; display: block; padding: 0; background: #fff; color: #111; print-color-adjust: exact; -webkit-print-color-adjust: exact; }
            main { width: 100%; }
            .card { border: 0; border-radius: 0; box-shadow: none; }
            .body { padding: 0; }
            .receipt-letterhead { grid-template-columns: 20mm minmax(0, 1fr) auto; gap: 5mm; padding-bottom: 4mm; }
            .receipt-letterhead img { width: 19mm; height: 19mm; }
            h1 { margin-top: 7mm; font-size: 18pt; }
            .receipt-detail, .receipt-confirmation { break-inside: avoid; }
            .receipt-detail { padding: 3mm; }
            .receipt-signoff { margin-top: 18mm; }
            .receipt-print-meta { margin-top: 10mm; }
            .no-print { display: none !important; }
        }
    </style>
</head>
<body>
<main>
    <article class="card">
        <div class="body">
            <header class="receipt-letterhead">
                <img src="assets/images/CVSU.jpeg" alt="Cavite State University seal">
                <div>
                    <p>Republic of the Philippines</p>
                    <p class="receipt-university">CAVITE STATE UNIVERSITY</p>
                    <p>College of Engineering and Information Technology</p>
                    <p>Dean's Office · Digital Logbook</p>
                </div>
                <div class="receipt-stamp">
                    <span>DOCUMENT RECORD</span>
                    <strong id="stampReference">Status tracker</strong>
                </div>
            </header>
            <h1>Document status tracker</h1>
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
                    <dl class="receipt-details">
                        <div class="receipt-detail"><dt>Reference</dt><dd class="receipt-reference" id="recordReference"></dd></div>
                        <div class="receipt-detail"><dt>Document type</dt><dd id="recordType"></dd></div>
                        <div class="receipt-detail"><dt>Current status</dt><dd><span class="status" id="recordStatus"></span></dd></div>
                        <div class="receipt-detail"><dt>Date submitted</dt><dd id="recordDate"></dd></div>
                    </dl>
                    <div class="receipt-confirmation">
                        <p><strong>Status recorded by the CEIT Dean's Office</strong></p>
                        <p class="no-print">Status updates are recorded by the office. This page refreshes automatically every minute.</p>
                    </div>
                    <div class="receipt-signoff">
                        <div>CEIT Dean's Office representative</div>
                        <div>Submitter's copy</div>
                    </div>
                    <p class="receipt-print-meta">Generated <span id="generatedAt"></span> · Reference <span id="metaReference"></span> · Verify using the official document tracking link.</p>
                    <div class="actions no-print">
                        <button type="button" id="refreshTracker">Refresh status</button>
                        <button type="button" class="is-secondary" id="printRecord">Print / save record</button>
                    </div>
                </section>
            <?php endif; ?>
            <!-- Hidden until the script finds a safe place to return to; a bare "./" can point at a folder with no page (403 Forbidden). -->
            <a class="return-link no-print" id="returnLink" href="#" hidden>Return</a>
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
        const record = result.record || {};
        const textOrDash = (value) => (value === null || value === undefined || String(value).trim() === '') ? 'Not recorded' : String(value);
        document.getElementById('recordSubject').textContent = textOrDash(record.subject);
        const reference = textOrDash(record.reference);
        document.getElementById('recordReference').textContent = reference;
        document.getElementById('stampReference').textContent = reference;
        document.getElementById('metaReference').textContent = reference;
        document.getElementById('generatedAt').textContent = new Date().toLocaleString('en-PH', { dateStyle: 'long', timeStyle: 'short' });
        document.getElementById('recordType').textContent = textOrDash(record.type);
        document.getElementById('recordDate').textContent = textOrDash(record.date);
        const status = document.getElementById('recordStatus');
        const statusText = textOrDash(record.status);
        status.textContent = statusText;
        status.className = 'status' +
            (['Released', 'Completed', 'Returned'].includes(statusText) ? ' is-done' : '') +
            (statusText === 'For Release' ? ' is-release' : '');
        notice.hidden = true;
        recordPanel.hidden = false;
    } catch (error) {
        recordPanel.hidden = true;
        notice.textContent = error instanceof Error ? error.message : 'Document status could not be loaded. Check your connection and try again.';
    }
}

document.getElementById('refreshTracker').addEventListener('click', refreshDocumentStatus);
document.getElementById('printRecord').addEventListener('click', () => window.print());
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
    if (target) {
        link.href = target.href;
        link.hidden = false;
    } else if (window.location.port === '5173') {
        link.href = '/';
        link.hidden = false;
    } else if (window.history.length > 1) {
        // No known page to go back to (for example, opened from a QR scanner): use the browser's own Back.
        link.addEventListener('click', function (event) {
            event.preventDefault();
            window.history.back();
        });
        link.hidden = false;
    }
})();
</script>
</body>
</html>