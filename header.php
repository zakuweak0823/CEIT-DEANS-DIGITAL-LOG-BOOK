<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$adminName = $_SESSION['admin'] ?? null;
$showAdminLoginArrival = $adminName !== null && !empty($_SESSION['admin_login_arrival']);
if ($showAdminLoginArrival) {
    unset($_SESSION['admin_login_arrival']);
}
$today = date('F j, Y');
$time = date('g:i A');
// The router preserves the original script filename so active navigation works with clean URLs.
$currentPage = basename($_SERVER['SCRIPT_FILENAME'] ?? $_SERVER['PHP_SELF'] ?? '');
$pageTitles = [
    'dashboard.php' => 'Dashboard',
    'quick_document.php' => 'Quick Submit Records',
    'incoming.php' => 'Incoming Documents',
    'outgoing.php' => 'Outgoing Documents',
    'batch_papers.php' => 'Batch Papers',
    'batch_tracker.php' => 'Batch Tracker',
    'borrowed.php' => 'Borrowed Items',
    'item_categories.php' => 'Item Categories',
    'sms.php' => 'SMS Logs',
    'notification_settings.php' => 'Communication Settings',
    'settings.php' => 'Settings',
    'reports.php' => 'Reports',
    'records.php' => 'Document Records',
];
$currentPageTitle = $pageTitles[$currentPage] ?? 'Admin Portal';
$adminInitial = strtoupper(substr((string)$adminName, 0, 1));
$isQuickDocumentActive = $currentPage === 'quick_document.php';
$currentReportType = $_GET['report_type'] ?? '';
$sidebarActiveClass = static function (string $page) use ($currentPage): string {
    return $currentPage === $page ? ' active' : '';
};
$isDocumentReportActive = $currentPage === 'reports.php' && !in_array($currentReportType, ['Borrowed Items', 'SMS Logs'], true);
$isSmsReportActive = $currentPage === 'reports.php' && $currentReportType === 'SMS Logs';
$pendingDocumentCount = null;
$latestDocumentId = null;
if ($adminName && isset($conn) && function_exists('safe_query')) {
    $pendingCountStatement = safe_query(
        $conn,
        "SELECT
            (SELECT COUNT(*) FROM documents WHERE type = 'Incoming' AND status = 'Pending') AS pending_count,
            COALESCE((SELECT MAX(doc_id) FROM documents), 0) AS latest_document_id"
    );
    if ($pendingCountStatement === false) {
        error_log('Admin document activity baseline could not be loaded.');
    } else {
        $pendingCountResult = mysqli_stmt_get_result($pendingCountStatement);
        $pendingCountRow = $pendingCountResult ? mysqli_fetch_assoc($pendingCountResult) : null;
        if ($pendingCountRow !== null) {
            $pendingDocumentCount = (int)$pendingCountRow['pending_count'];
            $latestDocumentId = (int)$pendingCountRow['latest_document_id'];
        }
        mysqli_stmt_close($pendingCountStatement);
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>CEIT Deans Office Digital Logbook</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <!-- Bump this asset version when shared styles change to refresh browser caches. -->
    <link rel="stylesheet" href="assets/css/style.css?v=responsive-admin-tables-5">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body<?php echo $adminName ? ' class="admin-body' . ($showAdminLoginArrival ? ' admin-login-arrival' : '') . (($_GET['embed'] ?? '') === '1' ? ' admin-embedded-body' : '') . '" data-latest-document-id="' . (int)($latestDocumentId ?? 0) . '" data-document-activity-ready="' . ($latestDocumentId === null ? 'false' : 'true') . '"' : ($currentPage === 'login.php' ? ' class="login-page"' : ''); ?>>
<?php if ($adminName): ?>
<script>
try {
    const theme = JSON.parse(window.localStorage.getItem('ceit-admin-preferences-v1') || '{}').adminTheme;
    if (theme === 'civic' || theme === 'glass') document.body.classList.add('admin-theme-' + theme);
} catch (error) {
    console.warn('Admin appearance preference could not be applied before rendering.', error);
}
</script>
<?php endif; ?>
<?php if ($adminName): ?>
    <?php if ($showAdminLoginArrival): ?>
        <div class="admin-login-transition" role="status" aria-live="polite">
            <span class="admin-login-transition-mark"><img src="assets/images/CVSU.jpeg" alt=""></span>
            <span class="admin-login-transition-title">Welcome back</span>
            <span class="admin-login-transition-caption">Opening your workspace</span>
            <span class="admin-login-transition-progress" aria-hidden="true"></span>
        </div>
    <?php endif; ?>
    <?php
    $pendingBadge = $pendingDocumentCount === null ? '!' : ($pendingDocumentCount > 99 ? '99+' : (string)$pendingDocumentCount);
    ?>
    <div class="admin-submission-toast" id="adminSubmissionToast" role="status" aria-live="polite" aria-atomic="true" hidden>
        <span class="admin-submission-toast-icon" aria-hidden="true"><i class="bi bi-inbox"></i></span>
        <span class="admin-submission-toast-copy">
            <strong>New document submitted</strong>
            <small>A new record is ready to review.</small>
        </span>
        <a class="admin-submission-toast-link" href="records">View records</a>
        <button class="admin-submission-toast-close" type="button" aria-label="Dismiss new submission notification"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
    </div>
    <section class="admin-assistant" aria-label="Admin Assistant">
        <button class="admin-assistant-launcher" type="button" aria-label="Open Admin Assistant" aria-expanded="false" aria-controls="adminAssistantPanel" aria-keyshortcuts="ArrowUp ArrowDown ArrowLeft ArrowRight" title="Drag to move; use arrow keys when focused.">
            <i class="bi bi-stars" aria-hidden="true"></i>
            <span>Help</span>
        </button>
        <div class="admin-assistant-panel" id="adminAssistantPanel" aria-labelledby="adminAssistantTitle" hidden>
            <div class="admin-assistant-header">
                <div class="admin-assistant-heading">
                    <span class="admin-assistant-mark" aria-hidden="true"><i class="bi bi-stars"></i></span>
                    <div>
                        <h2 id="adminAssistantTitle">Admin Assistant</h2>
                        <p>Private, built-in admin guide</p>
                    </div>
                </div>
                <button class="admin-assistant-close" type="button" aria-label="Close Admin Assistant"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
            </div>
            <div class="admin-assistant-messages" role="log" aria-live="polite" aria-relevant="additions text" aria-label="Assistant conversation">
                <div class="admin-assistant-message is-assistant">
                    <p>Hi! I can guide you around the admin portal and prepare form values for your approval. I never submit forms. What do you need?</p>
                </div>
            </div>
            <div class="admin-assistant-suggestions" aria-label="Suggested questions">
                <button type="button" data-assistant-question="Where can I review pending documents?">Pending documents</button>
                <button type="button" data-assistant-question="How do I register a document?">Register a document</button>
                <button type="button" data-assistant-question="Where are reports?">Find reports</button>
                <button type="button" data-assistant-question="Help me fill this form">Help with this form</button>
            </div>
            <div class="admin-assistant-form">
                <label class="visually-hidden" for="adminAssistantInput">Ask the Admin Assistant</label>
                <input id="adminAssistantInput" name="question" type="text" maxlength="240" autocomplete="off" placeholder="Ask about a page or task">
                <button type="button" aria-label="Send question" data-assistant-send><i class="bi bi-arrow-up" aria-hidden="true"></i></button>
            </div>
            <p class="admin-assistant-privacy">This guide runs in your browser. Don’t enter passwords or sensitive details.</p>
        </div>
    </section>
<div class="admin-layout">
    <aside class="sidebar bg-white border-end shadow-sm">
        <div class="sidebar-brand border-bottom">
            <a class="sidebar-brand-link" href="dashboard" aria-label="CEIT Deans Office Digital Logbook dashboard">
                <span class="sidebar-brand-mark" aria-hidden="true">
                    <img class="brand-logo" src="assets/images/CVSU.jpeg" alt="">
                </span>
                <span class="sidebar-brand-copy">
                    <strong>CEIT Deans Office</strong>
                    <span>Digital Logbook</span>
                    <small><i class="bi bi-shield-check" aria-hidden="true"></i> Admin workspace</small>
                </span>
            </a>
        </div>
        <div class="sidebar-nav px-3 py-3">
            <div class="sidebar-section text-uppercase fw-semibold text-muted mb-2">Workspace</div>
            <a href="dashboard" class="sidebar-link<?php echo $sidebarActiveClass('dashboard.php'); ?>"<?php echo $currentPage === 'dashboard.php' ? ' aria-current="page"' : ''; ?>><i class="bi bi-speedometer2 me-2"></i>Dashboard</a>
            <div class="sidebar-section text-uppercase fw-semibold text-muted mt-4 mb-2">Documents</div>
            <a href="quick_document" class="sidebar-link<?php echo $isQuickDocumentActive ? ' active' : ''; ?>"<?php echo $isQuickDocumentActive ? ' aria-current="page"' : ''; ?>><i class="bi bi-lightning-charge me-2"></i>Quick Submit Records</a>
            <a href="incoming" class="sidebar-link<?php echo $sidebarActiveClass('incoming.php'); ?>"<?php echo $currentPage === 'incoming.php' ? ' aria-current="page"' : ''; ?>><i class="bi bi-box-arrow-in-down me-2"></i>Incoming Documents</a>
            <a href="outgoing" class="sidebar-link<?php echo $sidebarActiveClass('outgoing.php'); ?>"<?php echo $currentPage === 'outgoing.php' ? ' aria-current="page"' : ''; ?>><i class="bi bi-box-arrow-up me-2"></i>Outgoing Documents</a>
            <a href="batch_papers" class="sidebar-link<?php echo $sidebarActiveClass('batch_papers.php'); ?>"<?php echo $currentPage === 'batch_papers.php' ? ' aria-current="page"' : ''; ?>><i class="bi bi-files me-2"></i>Batch Papers</a>
            <div class="sidebar-section text-uppercase fw-semibold text-muted mt-4 mb-2">Borrowed Items</div>
            <a href="borrowed" class="sidebar-link<?php echo $sidebarActiveClass('borrowed.php'); ?>"<?php echo $currentPage === 'borrowed.php' ? ' aria-current="page"' : ''; ?>><i class="bi bi-box-seam me-2"></i>Borrowed Items</a>
            <a href="item_categories" class="sidebar-link<?php echo $sidebarActiveClass('item_categories.php'); ?>"<?php echo $currentPage === 'item_categories.php' ? ' aria-current="page"' : ''; ?>><i class="bi bi-tags me-2"></i>Item Categories</a>
            <div class="sidebar-section text-uppercase fw-semibold text-muted mt-4 mb-2">Communication</div>
            <a href="sms" class="sidebar-link<?php echo $sidebarActiveClass('sms.php'); ?>"<?php echo $currentPage === 'sms.php' ? ' aria-current="page"' : ''; ?>><i class="bi bi-chat-dots me-2"></i>SMS Logs</a>
            <a href="notification_settings" class="sidebar-link<?php echo $sidebarActiveClass('notification_settings.php'); ?>"<?php echo $currentPage === 'notification_settings.php' ? ' aria-current="page"' : ''; ?>><i class="bi bi-chat-dots me-2"></i>Communication Settings</a>
            <div class="sidebar-section text-uppercase fw-semibold text-muted mt-4 mb-2">Reports</div>
            <a href="reports?report_type=All%20Reports&amp;generate=1" class="sidebar-link<?php echo $isDocumentReportActive ? ' active' : ''; ?>"<?php echo $isDocumentReportActive ? ' aria-current="page"' : ''; ?>><i class="bi bi-file-earmark-text me-2"></i>All Reports</a>
            <a href="reports?report_type=SMS%20Logs&amp;generate=1" class="sidebar-link<?php echo $isSmsReportActive ? ' active' : ''; ?>"<?php echo $isSmsReportActive ? ' aria-current="page"' : ''; ?>><i class="bi bi-chat-left-text me-2"></i>SMS Reports</a>
        </div>
        <div class="civic-sidebar-footer">
            <span class="civic-sidebar-status"><i aria-hidden="true"></i> LOCAL WORKSPACE</span>
            <span class="civic-sidebar-footer-copy">Your office records, in one place.</span>
            <a href="settings"><i class="bi bi-sliders2" aria-hidden="true"></i>Workspace settings<i class="bi bi-arrow-up-right" aria-hidden="true"></i></a>
        </div>
    </aside>
    <div class="main-panel">
        <nav class="topbar navbar navbar-expand-lg navbar-light bg-white border-bottom px-4 py-3">
            <div class="container-fluid px-0">
                <button class="btn btn-outline-secondary d-xl-none me-3" type="button" data-bs-toggle="offcanvas" data-bs-target="#sidebarOffcanvas" aria-controls="sidebarOffcanvas" aria-label="Open navigation menu">
                    <i class="bi bi-list" aria-hidden="true"></i>
                </button>
                <div class="topbar-title">
                    <strong><?php echo sanitize($currentPageTitle); ?></strong>
                    <small><i class="bi bi-calendar3 me-1" aria-hidden="true"></i><?php echo sanitize($today); ?></small>
                </div>
                <form class="topbar-search flex-grow-1 mx-3 d-none d-md-flex" method="GET" action="records" role="search">
                    <div class="input-group">
                        <label class="input-group-text bg-white border-end-0" for="topbarDocumentSearch"><i class="bi bi-search" aria-hidden="true"></i><span class="visually-hidden">Search records</span></label>
                        <input type="search" id="topbarDocumentSearch" name="search" class="form-control border-start-0" placeholder="Search documents">
                    </div>
                </form>
                <div class="d-flex align-items-center">
                    <div class="dropdown">
                        <button class="btn admin-profile-toggle dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Open administrator menu for <?php echo sanitize($adminName); ?>">
                            <span class="admin-avatar rounded-circle d-inline-flex align-items-center justify-content-center" aria-hidden="true"><?php echo sanitize($adminInitial); ?></span>
                            <span class="admin-profile-copy">
                                <strong><?php echo sanitize($adminName); ?></strong>
                                <small>Administrator</small>
                            </span>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li><a class="dropdown-item<?php echo $currentPage === 'settings.php' ? ' active' : ''; ?>" href="settings"<?php echo $currentPage === 'settings.php' ? ' aria-current="page"' : ''; ?>><i class="bi bi-gear me-2" aria-hidden="true"></i>Settings</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li class="px-3 py-2 admin-mode-menu-item">
                                <div class="d-flex align-items-start gap-2">
                                    <i class="bi bi-lightning-charge-fill text-success mt-1" aria-hidden="true"></i>
                                    <div class="flex-grow-1">
                                        <div class="d-flex align-items-center justify-content-between gap-3 mb-1">
                                            <label class="small fw-semibold mb-0" for="adminCompactModeToggle">Compact Mode</label>
                                            <span class="admin-compact-mode-state" id="adminCompactModeStatus" role="status">Off</span>
                                        </div>
                                        <div class="form-check form-switch admin-compact-mode-switch mb-1">
                                            <input class="form-check-input" type="checkbox" role="switch" id="adminCompactModeToggle" aria-label="Compact Mode">
                                            <label class="form-check-label small" for="adminCompactModeToggle">Use the mobile-style workspace</label>
                                        </div>
                                    </div>
                                </div>
                            </li>
                            <li class="admin-cmd-log-menu-divider"><hr class="dropdown-divider"></li>
                            <li class="admin-cmd-log-menu-item">
                                <button class="dropdown-item" type="button" id="mobileSubmissionLogToggle" aria-pressed="false" data-csrf-token="<?php echo sanitize(csrf_token()); ?>">
                                    <i class="bi bi-terminal me-2" aria-hidden="true"></i><span>Start CMD log</span>
                                </button>
                            </li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item admin-sign-out" href="logout"><i class="bi bi-box-arrow-right me-2" aria-hidden="true"></i>Sign out</a></li>
                        </ul>
                    </div>
                </div>
            </div>
        </nav>
        <nav class="admin-quick-access" aria-label="Primary navigation">
            <div class="admin-quick-access-inner">
                <div class="admin-quick-access-links">
                    <a href="dashboard"<?php echo $currentPage === 'dashboard.php' ? ' aria-current="page"' : ''; ?>><i class="bi bi-speedometer2" aria-hidden="true"></i>Dashboard</a>
                    <a href="incoming?status=Pending"<?php echo $currentPage === 'incoming.php' && ($_GET['status'] ?? '') === 'Pending' ? ' aria-current="page"' : ''; ?>><i class="bi bi-inbox" aria-hidden="true"></i>Pending inbox<?php if ($pendingDocumentCount !== null && $pendingDocumentCount > 0): ?><span class="admin-quick-access-count"><?php echo sanitize($pendingBadge); ?></span><?php endif; ?></a>
                    <a href="quick_document"<?php echo $currentPage === 'quick_document.php' ? ' aria-current="page"' : ''; ?>><i class="bi bi-plus-circle" aria-hidden="true"></i>New record</a>
                    <a href="records"<?php echo $currentPage === 'records.php' ? ' aria-current="page"' : ''; ?>><i class="bi bi-search" aria-hidden="true"></i>Records</a>
                    <button class="admin-quick-access-more<?php echo (in_array($currentPage, ['outgoing.php', 'batch_papers.php', 'batch_tracker.php', 'borrowed.php', 'item_categories.php', 'sms.php', 'notification_settings.php', 'reports.php', 'settings.php'], true) || ($currentPage === 'incoming.php' && ($_GET['status'] ?? '') !== 'Pending')) ? ' is-current' : ''; ?>" type="button" data-bs-toggle="offcanvas" data-bs-target="#quickAccessMore" aria-controls="quickAccessMore" aria-label="Open more admin sections">
                        <i class="bi bi-grid" aria-hidden="true"></i><span>More</span>
                    </button>
                </div>
            </div>
        </nav>
        <div class="offcanvas offcanvas-bottom admin-quick-access-sheet" tabindex="-1" id="quickAccessMore" aria-labelledby="quickAccessMoreLabel">
            <div class="offcanvas-header">
                <div>
                    <h5 class="offcanvas-title mb-0" id="quickAccessMoreLabel">More sections</h5>
                    <small class="text-muted">All admin tools in one place</small>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close sections menu"></button>
            </div>
            <div class="offcanvas-body">
                <div class="admin-quick-access-grid">
                    <a href="incoming"<?php echo $currentPage === 'incoming.php' && ($_GET['status'] ?? '') !== 'Pending' ? ' aria-current="page"' : ''; ?>><i class="bi bi-box-arrow-in-down" aria-hidden="true"></i><span>Incoming</span></a>
                    <a href="outgoing"<?php echo $currentPage === 'outgoing.php' ? ' aria-current="page"' : ''; ?>><i class="bi bi-box-arrow-up" aria-hidden="true"></i><span>Outgoing</span></a>
                    <a href="batch_papers"<?php echo $currentPage === 'batch_papers.php' ? ' aria-current="page"' : ''; ?>><i class="bi bi-files" aria-hidden="true"></i><span>Batch papers</span></a>
                    <a href="batch_tracker"<?php echo $currentPage === 'batch_tracker.php' ? ' aria-current="page"' : ''; ?>><i class="bi bi-diagram-3" aria-hidden="true"></i><span>Batch tracker</span></a>
                    <a href="borrowed"<?php echo $currentPage === 'borrowed.php' ? ' aria-current="page"' : ''; ?>><i class="bi bi-box-seam" aria-hidden="true"></i><span>Borrowed items</span></a>
                    <a href="item_categories"<?php echo $currentPage === 'item_categories.php' ? ' aria-current="page"' : ''; ?>><i class="bi bi-tags" aria-hidden="true"></i><span>Categories</span></a>
                    <a href="sms"<?php echo $currentPage === 'sms.php' ? ' aria-current="page"' : ''; ?>><i class="bi bi-chat-dots" aria-hidden="true"></i><span>SMS logs</span></a>
                    <a href="notification_settings"<?php echo $currentPage === 'notification_settings.php' ? ' aria-current="page"' : ''; ?>><i class="bi bi-chat-dots" aria-hidden="true"></i><span>Communication</span></a>
                    <a href="reports?report_type=All%20Reports&amp;generate=1"<?php echo $isDocumentReportActive ? ' aria-current="page"' : ''; ?>><i class="bi bi-file-earmark-bar-graph" aria-hidden="true"></i><span>All Reports</span></a>
                    <a href="reports?report_type=SMS%20Logs&amp;generate=1"<?php echo $currentPage === 'reports.php' && $currentReportType === 'SMS Logs' ? ' aria-current="page"' : ''; ?>><i class="bi bi-chat-left-text" aria-hidden="true"></i><span>SMS reports</span></a>
                    <a href="settings"<?php echo $currentPage === 'settings.php' ? ' aria-current="page"' : ''; ?>><i class="bi bi-gear" aria-hidden="true"></i><span>Settings</span></a>
                </div>
            </div>
        </div>
        <div class="offcanvas offcanvas-start" tabindex="-1" id="sidebarOffcanvas" aria-labelledby="sidebarOffcanvasLabel">
            <div class="offcanvas-header">
                <div>
                    <h5 class="offcanvas-title mb-0" id="sidebarOffcanvasLabel">Navigation</h5>
                    <small class="text-muted"><?php echo sanitize($currentPageTitle); ?></small>
                </div>
                <button type="button" class="btn-close text-reset" data-bs-dismiss="offcanvas" aria-label="Close"></button>
            </div>
            <div class="offcanvas-body px-0">
                <div class="sidebar-nav px-3">
                    <div class="sidebar-section text-uppercase fw-semibold text-muted mb-2">Workspace</div>
                    <a href="dashboard" class="sidebar-link<?php echo $sidebarActiveClass('dashboard.php'); ?>"<?php echo $currentPage === 'dashboard.php' ? ' aria-current="page"' : ''; ?>><i class="bi bi-speedometer2 me-2"></i>Dashboard</a>
                    <div class="sidebar-section text-uppercase fw-semibold text-muted mt-4 mb-2">Documents</div>
                    <a href="quick_document" class="sidebar-link<?php echo $isQuickDocumentActive ? ' active' : ''; ?>"<?php echo $isQuickDocumentActive ? ' aria-current="page"' : ''; ?>><i class="bi bi-lightning-charge me-2"></i>Quick Submit Records</a>
                    <a href="incoming" class="sidebar-link<?php echo $sidebarActiveClass('incoming.php'); ?>"<?php echo $currentPage === 'incoming.php' ? ' aria-current="page"' : ''; ?>><i class="bi bi-box-arrow-in-down me-2"></i>Incoming Documents</a>
                    <a href="outgoing" class="sidebar-link<?php echo $sidebarActiveClass('outgoing.php'); ?>"<?php echo $currentPage === 'outgoing.php' ? ' aria-current="page"' : ''; ?>><i class="bi bi-box-arrow-up me-2"></i>Outgoing Documents</a>
                    <a href="batch_papers" class="sidebar-link<?php echo $sidebarActiveClass('batch_papers.php'); ?>"<?php echo $currentPage === 'batch_papers.php' ? ' aria-current="page"' : ''; ?>><i class="bi bi-files me-2"></i>Batch Papers</a>
                    <div class="sidebar-section text-uppercase fw-semibold text-muted mt-4 mb-2">Borrowed Items</div>
                    <a href="borrowed" class="sidebar-link<?php echo $sidebarActiveClass('borrowed.php'); ?>"<?php echo $currentPage === 'borrowed.php' ? ' aria-current="page"' : ''; ?>><i class="bi bi-box-seam me-2"></i>Borrowed Items</a>
                    <a href="item_categories" class="sidebar-link<?php echo $currentPage === 'item_categories.php' ? ' active' : ''; ?>"<?php echo $currentPage === 'item_categories.php' ? ' aria-current="page"' : ''; ?>><i class="bi bi-tags me-2"></i>Item Categories</a>
                    <div class="sidebar-section text-uppercase fw-semibold text-muted mt-4 mb-2">Communication</div>
                    <a href="sms" class="sidebar-link<?php echo $sidebarActiveClass('sms.php'); ?>"<?php echo $currentPage === 'sms.php' ? ' aria-current="page"' : ''; ?>><i class="bi bi-chat-dots me-2"></i>SMS Logs</a>
                    <a href="notification_settings" class="sidebar-link<?php echo $sidebarActiveClass('notification_settings.php'); ?>"<?php echo $currentPage === 'notification_settings.php' ? ' aria-current="page"' : ''; ?>><i class="bi bi-chat-dots me-2"></i>Communication Settings</a>
                    <div class="sidebar-section text-uppercase fw-semibold text-muted mt-4 mb-2">Reports</div>
                    <a href="reports?report_type=All%20Reports&amp;generate=1" class="sidebar-link<?php echo $isDocumentReportActive ? ' active' : ''; ?>"<?php echo $isDocumentReportActive ? ' aria-current="page"' : ''; ?>><i class="bi bi-file-earmark-text me-2"></i>All Reports</a>
                    <a href="reports?report_type=SMS%20Logs&amp;generate=1" class="sidebar-link<?php echo $isSmsReportActive ? ' active' : ''; ?>"<?php echo $isSmsReportActive ? ' aria-current="page"' : ''; ?>><i class="bi bi-chat-left-text me-2"></i>SMS Reports</a>
                </div>
            </div>
        </div>
        <main class="admin-content container-fluid">
<?php else: ?>
<nav class="navbar navbar-expand-lg navbar-dark bg-primary shadow-sm<?php echo $currentPage === 'login.php' ? ' login-page-navbar' : ''; ?>">
    <div class="container">
        <a class="navbar-brand d-flex align-items-center" href="dashboard">
            <?php if ($currentPage === 'login.php'): ?>
                <img class="login-brand-logo me-3" src="assets/images/CVSU.jpeg" alt="">
            <?php else: ?>
                <span class="brand-icon me-2">CVSU</span>
            <?php endif; ?>
            <div>
                <div class="fw-bold">CEIT Deans Office Digital Logbook</div>
                <small class="text-light opacity-75">Dean's Office</small>
            </div>
        </a>
        <?php if ($currentPage !== 'login.php'): ?>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navMenu" aria-controls="navMenu" aria-expanded="false" aria-label="Toggle navigation">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navMenu">
                <ul class="navbar-nav ms-auto align-items-center">
                    <li class="nav-item"><a class="nav-link" href="login">Admin Login</a></li>
                    <li class="nav-item ms-lg-3 mt-3 mt-lg-0">
                        <span class="badge bg-light text-primary py-2 px-3"><?php echo $today; ?> &bull; <?php echo $time; ?></span>
                    </li>
                </ul>
            </div>
        <?php endif; ?>
    </div>
</nav>
<main class="<?php echo $currentPage === 'login.php' ? 'login-page-main' : 'container my-5'; ?>">
<?php endif; ?>
