<?php

require_once __DIR__ . '/../includes/background_jobs.php';

function adminIsActivePage($currentPage, $pageName) {
    $groups = [
        'users' => ['users', 'create_user', 'edit_user'],
        'events' => ['events', 'event_setup'],
    ];

    foreach ($groups as $parent => $children) {
        if ($pageName === $parent && in_array($currentPage, $children, true)) {
            return 'active';
        }
    }

    return $currentPage === $pageName ? 'active' : '';
}

function renderAdminHeader($title, $currentPage = '') {
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title><?= htmlspecialchars($title) ?> | E-Passport Admin</title>
        <script>
            (function () {
                try {
                    var stored = localStorage.getItem('epassport_admin_sidebar');
                    var collapsed = stored === 'collapsed'
                        || (stored === null && window.innerWidth < 992);
                    if (collapsed) {
                        document.documentElement.classList.add('admin-nav-collapsed');
                    }
                } catch (e) {}
            })();
        </script>
        <link rel="stylesheet" href="<?= htmlspecialchars(epassport_vendor_asset('bootstrap/bootstrap.min.css')) ?>">
        <link rel="stylesheet" href="<?= htmlspecialchars(epassport_vendor_asset('bootstrap-icons/bootstrap-icons.css')) ?>">
        <style>
            :root {
                --admin-royal: #1f2f88;
                --admin-royal-dark: #172367;
                --admin-gold: #f0b429;
                --admin-gold-soft: #fff4d8;
                --admin-bg: #eef2ff;
                --admin-surface: #ffffff;
                --admin-border: #d7def2;
                --admin-text: #1c2556;
                --admin-muted: #5f6791;
            }
            body {
                background: radial-gradient(circle at 5% 5%, #ffffff 0%, var(--admin-bg) 45%, #e2e8ff 100%);
                color: var(--admin-text);
            }
            .admin-shell {
                min-height: 100vh;
                display: flex;
                align-items: stretch;
            }
            .admin-sidebar {
                --admin-sidebar-width: 260px;
                width: var(--admin-sidebar-width);
                flex: 0 0 var(--admin-sidebar-width);
                max-width: var(--admin-sidebar-width);
                background: linear-gradient(185deg, var(--admin-royal-dark), var(--admin-royal) 70%);
                color: #e8ecff;
                padding: 1.15rem 0.9rem 1.3rem;
                box-shadow: 8px 0 25px rgba(23, 35, 103, 0.2);
                position: sticky;
                top: 0;
                height: 100vh;
                overflow-x: hidden;
                overflow-y: auto;
                z-index: 1040;
                transition: width 0.22s ease, flex-basis 0.22s ease, max-width 0.22s ease, padding 0.22s ease;
            }
            html.admin-nav-collapsed .admin-sidebar {
                --admin-sidebar-width: 76px;
                padding-left: 0.55rem;
                padding-right: 0.55rem;
            }
            .admin-sidebar::after {
                content: '';
                position: absolute;
                inset: auto 0 0 0;
                height: 5px;
                background: linear-gradient(to right, transparent, var(--admin-gold), transparent);
            }
            .admin-sidebar-head {
                display: flex;
                align-items: flex-start;
                justify-content: space-between;
                gap: 0.4rem;
                margin-bottom: 1.15rem;
            }
            html.admin-nav-collapsed .admin-sidebar-head {
                flex-direction: column;
                align-items: center;
            }
            .admin-brand-block { min-width: 0; }
            .brand {
                font-weight: 700;
                letter-spacing: 0.09em;
                text-transform: uppercase;
                font-size: 0.75rem;
                color: #f5d275;
                white-space: nowrap;
            }
            .brand-title {
                font-size: 1.1rem;
                color: #ffffff;
                font-weight: 700;
                white-space: nowrap;
            }
            html.admin-nav-collapsed .admin-brand-block {
                display: none;
            }
            .admin-sidebar-toggle {
                flex-shrink: 0;
                width: 2rem;
                height: 2rem;
                border: 1px solid rgba(240, 180, 41, 0.35);
                background: rgba(255, 255, 255, 0.08);
                color: #f5d275;
                border-radius: 0.5rem;
                display: inline-flex;
                align-items: center;
                justify-content: center;
                padding: 0;
                line-height: 1;
                cursor: pointer;
            }
            .admin-sidebar-toggle:hover {
                background: rgba(255, 255, 255, 0.16);
                color: #fff;
            }
            .admin-nav a {
                display: flex;
                align-items: center;
                gap: 0.55rem;
                color: #d7ddff;
                text-decoration: none;
                padding: 0.62rem 0.75rem;
                border-radius: 0.65rem;
                margin-bottom: 0.34rem;
                transition: all 0.18s ease;
                border: 1px solid transparent;
                white-space: nowrap;
            }
            .admin-nav a:hover, .admin-nav a.active {
                background: rgba(255, 255, 255, 0.12);
                color: #ffffff;
                border-color: rgba(240, 180, 41, 0.42);
            }
            .admin-nav a i {
                width: 1.15rem;
                flex-shrink: 0;
                text-align: center;
                font-size: 1.05rem;
            }
            .admin-nav-label {
                overflow: hidden;
                text-overflow: ellipsis;
            }
            html.admin-nav-collapsed .admin-nav a {
                justify-content: center;
                padding: 0.7rem 0.35rem;
            }
            html.admin-nav-collapsed .admin-nav-label {
                display: none;
            }
            .admin-sidebar-backdrop {
                display: none;
            }
            .admin-main {
                flex: 1 1 auto;
                min-width: 0;
                padding: 1.5rem;
            }
            .admin-topbar {
                background: linear-gradient(130deg, #ffffff, #f6f8ff);
                border: 1px solid var(--admin-border);
                border-left: 6px solid var(--admin-gold);
                border-radius: 0.95rem;
                padding: 1rem 1.2rem;
                margin-bottom: 1rem;
                box-shadow: 0 12px 24px rgba(31, 47, 136, 0.08);
            }
            .admin-panel {
                background: var(--admin-surface);
                border: 1px solid var(--admin-border);
                border-radius: 0.95rem;
                padding: 1.25rem;
                box-shadow: 0 8px 20px rgba(31, 47, 136, 0.06);
            }
            .metric-card {
                border: 1px solid var(--admin-border);
                border-radius: 0.85rem;
                padding: 1rem;
                background: linear-gradient(145deg, #ffffff, #f8f9ff);
            }
            .metric-label {
                color: var(--admin-muted);
                font-size: 0.8rem;
                text-transform: uppercase;
                letter-spacing: 0.06em;
                margin-bottom: 0.25rem;
            }
            .metric-split {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 1rem;
            }
            .metric-split-label {
                color: var(--admin-muted);
                font-size: 0.78rem;
                margin-bottom: 0.15rem;
            }
            .table {
                --bs-table-striped-bg: #f6f8ff;
                --bs-table-hover-bg: #ebefff;
            }
            .table thead th {
                background: #e9eeff;
                color: var(--admin-royal-dark);
                border-bottom-width: 1px;
                font-weight: 700;
            }
            .form-label { font-weight: 600; color: #2c386f; }
            .form-control:focus, .form-select:focus {
                border-color: #a0afe9;
                box-shadow: 0 0 0 0.2rem rgba(31, 47, 136, 0.15);
            }
            .btn-primary {
                --bs-btn-bg: var(--admin-royal);
                --bs-btn-border-color: var(--admin-royal);
                --bs-btn-hover-bg: #263aa7;
                --bs-btn-hover-border-color: #263aa7;
                --bs-btn-active-bg: #1a2b82;
                --bs-btn-active-border-color: #1a2b82;
            }
            .btn-success {
                --bs-btn-bg: var(--admin-gold);
                --bs-btn-border-color: var(--admin-gold);
                --bs-btn-color: #2d2100;
                --bs-btn-hover-bg: #dca018;
                --bs-btn-hover-border-color: #dca018;
                --bs-btn-hover-color: #2d2100;
                --bs-btn-active-bg: #ca9416;
                --bs-btn-active-border-color: #ca9416;
                --bs-btn-active-color: #2d2100;
            }
            .badge-soft-gold {
                background: var(--admin-gold-soft);
                color: #845a00;
                border: 1px solid #f4d289;
            }
            .admin-quick-card {
                border: 1px solid var(--admin-border);
                border-radius: 0.85rem;
                padding: 1rem 1.1rem;
                height: 100%;
                background: linear-gradient(145deg, #ffffff, #f8f9ff);
                transition: box-shadow 0.2s ease;
            }
            .admin-quick-card:hover {
                box-shadow: 0 10px 22px rgba(31, 47, 136, 0.08);
            }
            .admin-quick-card h2 {
                font-size: 1.05rem;
                color: var(--admin-royal-dark);
            }
            .admin-page-header {
                display: flex;
                flex-wrap: wrap;
                align-items: flex-start;
                justify-content: space-between;
                gap: 1rem;
            }
            .admin-meta-pill {
                display: inline-flex;
                align-items: center;
                gap: 0.45rem;
                padding: 0.55rem 0.9rem;
                border-radius: 999px;
                background: linear-gradient(135deg, #eef2ff, #e8ecff);
                border: 1px solid var(--admin-border);
                color: var(--admin-royal-dark);
                font-size: 0.82rem;
                font-weight: 600;
                white-space: nowrap;
            }
            .admin-meta-pill i { color: var(--admin-gold); }
            .admin-section-title {
                font-size: 0.72rem;
                font-weight: 700;
                letter-spacing: 0.08em;
                text-transform: uppercase;
                color: var(--admin-muted);
                margin-bottom: 0.65rem;
            }
            .metric-card.metric-success .metric-value { color: #198754; }
            .metric-card.metric-danger .metric-value { color: #dc3545; }
            .metric-card.metric-warning {
                background: linear-gradient(145deg, #fffbf0, #fff8e6);
                border-color: #f4d289;
            }
            .metric-card.metric-warning .metric-value { color: #9a6700; }
            .metric-card.metric-muted {
                background: linear-gradient(145deg, #f8f9ff, #f3f5fb);
            }
            .metric-hint {
                font-size: 0.78rem;
                color: var(--admin-muted);
                margin-top: 0.35rem;
                line-height: 1.35;
            }
            .admin-export-card .form-label,
            .admin-filter-card .form-label {
                font-size: 0.78rem;
                margin-bottom: 0.25rem;
                color: var(--admin-muted);
                font-weight: 600;
                text-transform: uppercase;
                letter-spacing: 0.04em;
            }
            .admin-records-card .table thead th {
                font-size: 0.78rem;
                text-transform: uppercase;
                letter-spacing: 0.04em;
                white-space: nowrap;
            }
            .admin-records-card .table td {
                font-size: 0.88rem;
                vertical-align: middle;
            }
            .admin-records-card .student-sub {
                font-size: 0.78rem;
                color: var(--admin-muted);
            }
            .admin-tools-bar {
                display: flex;
                flex-direction: column;
                gap: 0.5rem;
            }
            .admin-tools-row {
                display: grid;
                gap: 0.45rem;
                min-width: 0;
            }
            .admin-tools-search {
                grid-template-columns: minmax(0, 1fr) auto auto;
            }
            .admin-tools-filters {
                grid-template-columns: repeat(4, minmax(0, 1fr)) auto auto;
            }
            .admin-tools-row .form-control,
            .admin-tools-row .form-select,
            .admin-tools-row .btn {
                min-width: 0;
                width: 100%;
            }
            .admin-page-actions {
                display: flex;
                flex-wrap: wrap;
                gap: 0.4rem;
                justify-content: flex-end;
                margin-left: auto;
            }
            @media (max-width: 991px) {
                .admin-tools-search {
                    grid-template-columns: 1fr 1fr;
                }
                .admin-tools-search .form-control {
                    grid-column: 1 / -1;
                }
                .admin-tools-filters {
                    grid-template-columns: 1fr 1fr;
                }
            }
            .admin-tools-filters-3 {
                grid-template-columns: repeat(3, minmax(0, 1fr));
            }
            .admin-tools-filters-4 {
                grid-template-columns: repeat(4, minmax(0, 1fr));
            }
            .admin-tools-filter-actions {
                grid-column: 1 / -1;
                display: flex;
                flex-wrap: wrap;
                gap: 0.45rem;
            }
            .admin-tools-filter-actions .btn {
                width: auto;
            }
            .validation-step-label {
                font-size: 0.78rem;
                font-weight: 700;
                letter-spacing: 0.04em;
                text-transform: uppercase;
                color: var(--admin-muted);
                margin-bottom: 0.45rem;
            }
            .validation-inline-form {
                display: flex;
                flex-wrap: wrap;
                gap: 0.35rem;
                align-items: center;
            }
            .validation-inline-form .form-select {
                width: auto;
                min-width: 7.25rem;
            }
            @media (max-width: 991px) {
                .admin-tools-filters-3,
                .admin-tools-filters-4 {
                    grid-template-columns: 1fr 1fr;
                }
            }
            .admin-records-card .cell-detail {
                font-size: 0.82rem;
                color: var(--admin-muted);
            }
            @media (max-width: 991px) {
                .admin-sidebar {
                    position: fixed;
                    left: 0;
                    top: 0;
                    height: 100vh;
                }
                .admin-main {
                    padding: 0.85rem;
                    margin-left: 76px;
                }
                html:not(.admin-nav-collapsed) .admin-sidebar-backdrop {
                    display: block;
                    position: fixed;
                    inset: 0;
                    background: rgba(23, 35, 103, 0.35);
                    z-index: 1035;
                }
                .admin-topbar {
                    flex-wrap: wrap;
                    gap: 0.65rem;
                }
            }
            .admin-user-chip {
                display: inline-flex;
                align-items: center;
                gap: 0.45rem;
                padding: 0.45rem 0.75rem;
                border-radius: 999px;
                background: #ffffff;
                border: 1px solid var(--admin-border);
                color: var(--admin-royal-dark);
                font-size: 0.82rem;
                font-weight: 600;
            }
            .admin-user-chip i { color: var(--admin-gold); }
            .dashboard-welcome {
                display: flex;
                flex-wrap: wrap;
                justify-content: space-between;
                align-items: center;
                gap: 1rem;
                padding: 1.2rem 1.25rem;
                border-radius: 0.95rem;
                border: 1px solid var(--admin-border);
                background: linear-gradient(135deg, #ffffff 0%, #f6f8ff 55%, #eef2ff 100%);
                box-shadow: 0 10px 24px rgba(31, 47, 136, 0.07);
            }
            .dashboard-welcome-kicker {
                font-size: 0.78rem;
                text-transform: uppercase;
                letter-spacing: 0.08em;
                color: var(--admin-muted);
                font-weight: 700;
            }
            .dashboard-welcome-meta { color: var(--admin-muted); font-size: 0.88rem; }
            .dashboard-pill-warning {
                background: linear-gradient(135deg, #fff8e6, #fff4d8);
                border-color: #f4d289;
                color: #845a00;
            }
            .dashboard-pill-warning i { color: #9a6700; }
            .metric-card-link {
                display: block;
                text-decoration: none;
                color: inherit;
                height: 100%;
                transition: transform 0.18s ease, box-shadow 0.18s ease;
            }
            .metric-card-link:hover {
                transform: translateY(-2px);
                box-shadow: 0 12px 24px rgba(31, 47, 136, 0.1);
            }
            .dashboard-attention-card {
                display: flex;
                gap: 0.85rem;
                align-items: flex-start;
                padding: 0.95rem 1rem;
                border-radius: 0.85rem;
                border: 1px solid var(--admin-border);
                background: #ffffff;
                height: 100%;
            }
            .dashboard-attention-card--warning {
                background: linear-gradient(145deg, #fffbf0, #fff8e6);
                border-color: #f4d289;
            }
            .dashboard-attention-card--danger {
                background: linear-gradient(145deg, #fff5f5, #ffecec);
                border-color: #f1b0b7;
            }
            .dashboard-attention-card--info {
                background: linear-gradient(145deg, #f8f9ff, #eef2ff);
            }
            .dashboard-attention-icon {
                width: 2.2rem;
                height: 2.2rem;
                border-radius: 0.75rem;
                display: inline-flex;
                align-items: center;
                justify-content: center;
                background: rgba(31, 47, 136, 0.08);
                color: var(--admin-royal);
                flex-shrink: 0;
            }
            .dashboard-status-row {
                display: grid;
                grid-template-columns: 5.5rem 1fr 2.5rem;
                gap: 0.65rem;
                align-items: center;
                margin-bottom: 0.55rem;
            }
            .dashboard-status-label {
                font-size: 0.78rem;
                font-weight: 600;
                color: var(--admin-muted);
            }
            .dashboard-status-track {
                height: 0.55rem;
                border-radius: 999px;
                background: #e9eeff;
                overflow: hidden;
            }
            .dashboard-status-fill {
                height: 100%;
                border-radius: 999px;
                min-width: 0;
                transition: width 0.3s ease;
            }
            .dashboard-status-count {
                font-size: 0.82rem;
                font-weight: 700;
                color: var(--admin-royal-dark);
                text-align: right;
            }
            .dashboard-next-scan {
                display: flex;
                flex-wrap: wrap;
                align-items: center;
                gap: 0.35rem 0.5rem;
                padding: 0.65rem 0.85rem;
                border-radius: 0.65rem;
                background: #eef4ff;
                border: 1px solid #c8d8f8;
                color: #1f3a78;
                font-size: 0.86rem;
                font-weight: 600;
            }
            .dashboard-next-scan i { color: #24379e; }
            .dashboard-next-scan__tz {
                font-size: 0.76rem;
                font-weight: 500;
                color: #5c6b94;
            }
            .dashboard-empty-state {
                text-align: center;
                padding: 2rem 1rem;
            }
            .dashboard-empty-state i {
                font-size: 2rem;
                color: var(--admin-gold);
                margin-bottom: 0.75rem;
            }
            .dashboard-activity-list {
                display: flex;
                flex-direction: column;
                gap: 0.75rem;
            }
            .dashboard-activity-item {
                display: flex;
                gap: 0.75rem;
                align-items: flex-start;
            }
            .dashboard-activity-icon {
                width: 2rem;
                height: 2rem;
                border-radius: 999px;
                background: #eef2ff;
                color: var(--admin-royal);
                display: inline-flex;
                align-items: center;
                justify-content: center;
                flex-shrink: 0;
            }
            .dashboard-activity-title {
                font-size: 0.88rem;
                font-weight: 700;
                color: var(--admin-royal-dark);
            }
            .dashboard-activity-meta {
                font-size: 0.76rem;
                color: var(--admin-muted);
            }
            .dashboard-activity-detail {
                font-size: 0.78rem;
                color: var(--admin-muted);
                margin-top: 0.15rem;
            }
            .dashboard-action-card {
                display: flex;
                flex-direction: column;
                align-items: center;
                justify-content: center;
                gap: 0.55rem;
                min-height: 7rem;
                padding: 1rem;
                border-radius: 0.85rem;
                border: 1px solid var(--admin-border);
                background: linear-gradient(145deg, #ffffff, #f8f9ff);
                text-decoration: none;
                color: var(--admin-royal-dark);
                font-size: 0.88rem;
                font-weight: 600;
                text-align: center;
                transition: transform 0.18s ease, box-shadow 0.18s ease, border-color 0.18s ease;
            }
            .dashboard-action-card i {
                font-size: 1.35rem;
                color: var(--admin-gold);
            }
            .dashboard-action-card:hover {
                transform: translateY(-2px);
                box-shadow: 0 12px 24px rgba(31, 47, 136, 0.1);
                border-color: rgba(240, 180, 41, 0.55);
                color: var(--admin-royal-dark);
            }
            .reports-hero {
                display: flex;
                flex-wrap: wrap;
                justify-content: space-between;
                align-items: center;
                gap: 1rem;
                padding: 1.2rem 1.25rem;
                border-radius: 0.95rem;
                border: 1px solid var(--admin-border);
                background: linear-gradient(135deg, #ffffff 0%, #f6f8ff 55%, #eef2ff 100%);
                box-shadow: 0 10px 24px rgba(31, 47, 136, 0.07);
            }
            .reports-hero-kicker {
                font-size: 0.78rem;
                text-transform: uppercase;
                letter-spacing: 0.08em;
                color: var(--admin-muted);
                font-weight: 700;
            }
            .reports-hero-meta { color: var(--admin-muted); font-size: 0.88rem; }
            .reports-stat-card {
                display: flex;
                align-items: center;
                gap: 0.85rem;
                padding: 0.95rem 1rem;
                border: 1px solid var(--admin-border);
                border-radius: 0.85rem;
                background: linear-gradient(145deg, #ffffff, #f8f9ff);
                height: 100%;
            }
            .reports-stat-icon {
                width: 2.5rem;
                height: 2.5rem;
                border-radius: 0.75rem;
                display: inline-flex;
                align-items: center;
                justify-content: center;
                flex-shrink: 0;
                font-size: 1.1rem;
            }
            .reports-stat-icon--attendance { background: #e8f5ee; color: #198754; }
            .reports-stat-icon--penalty { background: #fff4e5; color: #9a6700; }
            .reports-stat-icon--audit { background: #eef2ff; color: var(--admin-royal); }
            .reports-stat-icon--users { background: #f3e8ff; color: #6f42c1; }
            .reports-stat-icon--events { background: #e7f5ff; color: #0d6efd; }
            .reports-stat-value {
                font-size: 1.35rem;
                font-weight: 700;
                color: var(--admin-royal-dark);
                line-height: 1.1;
            }
            .reports-stat-label {
                font-size: 0.76rem;
                color: var(--admin-muted);
                text-transform: uppercase;
                letter-spacing: 0.04em;
            }
            .report-card {
                position: relative;
                border: 1px solid var(--admin-border);
                border-radius: 0.95rem;
                background: #ffffff;
                overflow: hidden;
                box-shadow: 0 8px 18px rgba(31, 47, 136, 0.05);
            }
            .report-card-accent {
                position: absolute;
                left: 0;
                top: 0;
                bottom: 0;
                width: 5px;
            }
            .report-card-accent--attendance { background: #198754; }
            .report-card-accent--penalty { background: #f0b429; }
            .report-card-accent--audit { background: var(--admin-royal); }
            .report-card-accent--users { background: #6f42c1; }
            .report-card-accent--events { background: #0d6efd; }
            .report-card-inner { padding: 1.15rem 1.2rem 1.15rem 1.35rem; }
            .report-card-header {
                display: flex;
                gap: 0.85rem;
                align-items: flex-start;
                margin-bottom: 1rem;
            }
            .report-card-icon {
                width: 2.5rem;
                height: 2.5rem;
                border-radius: 0.75rem;
                display: inline-flex;
                align-items: center;
                justify-content: center;
                flex-shrink: 0;
            }
            .report-card-icon--attendance { background: #e8f5ee; color: #198754; }
            .report-card-icon--penalty { background: #fff4e5; color: #9a6700; }
            .report-card-icon--audit { background: #eef2ff; color: var(--admin-royal); }
            .report-card-icon--users { background: #f3e8ff; color: #6f42c1; }
            .report-card-icon--events { background: #e7f5ff; color: #0d6efd; }
            .report-card-footer {
                display: flex;
                flex-wrap: wrap;
                justify-content: space-between;
                align-items: center;
                gap: 0.85rem;
                margin-top: 1rem;
                padding-top: 1rem;
                border-top: 1px solid #eef2ff;
            }
            .report-preview-count {
                display: flex;
                flex-direction: column;
                gap: 0.15rem;
            }
            .report-preview-label {
                font-size: 0.72rem;
                text-transform: uppercase;
                letter-spacing: 0.06em;
                color: var(--admin-muted);
                font-weight: 600;
            }
            .report-preview-count strong {
                font-size: 1.1rem;
                color: var(--admin-royal-dark);
            }
            .report-date-presets .btn-light {
                border: 1px solid var(--admin-border);
                color: var(--admin-royal-dark);
                font-size: 0.78rem;
            }
            .report-date-presets .btn-light:hover {
                background: #eef2ff;
                border-color: #c7d2fe;
            }
            .reports-tip-card {
                padding: 1rem;
                border: 1px solid var(--admin-border);
                border-radius: 0.85rem;
                background: linear-gradient(145deg, #ffffff, #f8f9ff);
                height: 100%;
            }
            .reports-tip-card i {
                display: block;
                font-size: 1.25rem;
                color: var(--admin-gold);
                margin-bottom: 0.5rem;
            }
            .reports-tip-card strong {
                display: block;
                margin-bottom: 0.35rem;
                color: var(--admin-royal-dark);
            }
        </style>
    </head>
    <body>
    <div class="admin-sidebar-backdrop" id="adminSidebarBackdrop"></div>
    <div class="d-flex admin-shell">
        <aside class="admin-sidebar" id="adminSidebar">
            <div class="admin-sidebar-head">
                <div class="admin-brand-block">
                    <div class="brand">E-Passport</div>
                    <div class="brand-title">Admin Control</div>
                </div>
                <button type="button" class="admin-sidebar-toggle" id="adminSidebarToggle" aria-label="Collapse or expand menu" aria-controls="adminSidebar" aria-expanded="true">
                    <i class="bi bi-layout-sidebar-inset"></i>
                </button>
            </div>

            <nav class="admin-nav">
                <?php
                $menuItems = [
                    [
                        'key' => 'dashboard',
                        'label' => 'Dashboard',
                        'icon' => 'bi bi-speedometer2',
                        'link' => 'dashboard.php'
                    ],
                    [
                        'key' => 'users',
                        'label' => 'Users',
                        'icon' => 'bi bi-people',
                        'link' => 'users.php'
                    ],
                    [
                        'key' => 'events',
                        'label' => 'Events',
                        'icon' => 'bi bi-calendar-event',
                        'link' => 'events.php'
                    ],
                    [
                        'key' => 'attendance_management',
                        'label' => 'Attendance',
                        'icon' => 'bi bi-clipboard-check',
                        'link' => 'attendance_management.php'
                    ],
                    [
                        'key' => 'penalty_management',
                        'label' => 'Penalty Management',
                        'icon' => 'bi bi-exclamation-triangle',
                        'link' => 'penalty_management.php'
                    ],
                    [
                        'key' => 'geofence_map',
                        'label' => 'Live Geofence Map',
                        'icon' => 'bi bi-geo-alt',
                        'link' => 'geofence_map.php'
                    ],
                    [
                        'key' => 'live_tracking',
                        'label' => 'Live Student Tracking',
                        'icon' => 'bi bi-broadcast',
                        'link' => 'live_tracking.php'
                    ],
                    [
                        'key' => 'student_validation',
                        'label' => 'Student Validation',
                        'icon' => 'bi bi-mortarboard',
                        'link' => 'student_validation.php'
                    ],
                    [
                        'key' => 'reports',
                        'label' => 'Reports',
                        'icon' => 'bi bi-file-earmark-bar-graph',
                        'link' => 'reports.php'
                    ],
                    [
                        'key' => 'audit_logs',
                        'label' => 'Audit Logs',
                        'icon' => 'bi bi-journal-text',
                        'link' => 'audit_logs.php'
                    ]
                ];

                foreach ($menuItems as $item) {
                ?>
                    <a class="<?= adminIsActivePage($currentPage, $item['key']) ?>"
                       href="<?= htmlspecialchars($item['link']) ?>"
                       title="<?= htmlspecialchars($item['label']) ?>">
                        <i class="<?= htmlspecialchars($item['icon']) ?>" aria-hidden="true"></i>
                        <span class="admin-nav-label"><?= htmlspecialchars($item['label']) ?></span>
                    </a>
                <?php
                }
                ?>
                <a href="../logout.php" title="Logout">
                    <i class="bi bi-box-arrow-right" aria-hidden="true"></i>
                    <span class="admin-nav-label">Logout</span>
                </a>
            </nav>
        </aside>

        <main class="admin-main">
            <header class="admin-topbar d-flex justify-content-between align-items-center">
                <div class="d-flex align-items-start gap-2 min-w-0">
                    <button type="button" class="btn btn-outline-secondary btn-sm d-lg-none mt-1" id="adminSidebarToggleTop" aria-label="Toggle menu">
                        <i class="bi bi-list"></i>
                    </button>
                    <div>
                        <h1 class="h4 mb-0"><?= htmlspecialchars($title) ?></h1>
                    </div>
                </div>
                <div class="d-flex align-items-center gap-2">
                    <?php if (!empty($_SESSION['name'])): ?>
                        <span class="admin-user-chip">
                            <i class="bi bi-person-circle"></i>
                            <?= htmlspecialchars((string) $_SESSION['name']) ?>
                        </span>
                    <?php endif; ?>
                    <a href="../logout.php" class="btn btn-outline-secondary btn-sm">Logout</a>
                </div>
            </header>
    <?php
}

function renderAdminFooter() {
    ?>
        </main>
    </div>
    <script>
        (function () {
            var key = 'epassport_admin_sidebar';
            var toggle = document.getElementById('adminSidebarToggle');
            var toggleTop = document.getElementById('adminSidebarToggleTop');
            var backdrop = document.getElementById('adminSidebarBackdrop');
            var nav = document.querySelector('.admin-nav');

            function isCollapsed() {
                return document.documentElement.classList.contains('admin-nav-collapsed');
            }

            function setCollapsed(collapsed) {
                document.documentElement.classList.toggle('admin-nav-collapsed', collapsed);
                try {
                    localStorage.setItem(key, collapsed ? 'collapsed' : 'expanded');
                } catch (e) {}
                var expanded = !collapsed;
                if (toggle) {
                    toggle.setAttribute('aria-expanded', expanded ? 'true' : 'false');
                }
                if (toggleTop) {
                    toggleTop.setAttribute('aria-expanded', expanded ? 'true' : 'false');
                }
            }

            function toggleNav() {
                setCollapsed(!isCollapsed());
            }

            setCollapsed(isCollapsed());

            if (toggle) {
                toggle.addEventListener('click', toggleNav);
            }
            if (toggleTop) {
                toggleTop.addEventListener('click', toggleNav);
            }
            if (backdrop) {
                backdrop.addEventListener('click', function () {
                    setCollapsed(true);
                });
            }
            if (nav) {
                nav.addEventListener('click', function (event) {
                    if (window.innerWidth < 992 && event.target.closest('a')) {
                        setCollapsed(true);
                    }
                });
            }
        })();
    </script>
    <?php epassport_render_dialog_script(); ?>
    <?php epassport_render_background_jobs_script(); ?>
    </body>
    </html>
    <?php
}
