<?php
session_start();
require_once 'api/config.php';

$serverActivo = isServerActive();
$user = isset($_SESSION['username']) ? $_SESSION['username'] : null;
$nivel = isset($_SESSION['nivel']) ? $_SESSION['nivel'] : null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AltChecks - Token Monitoring System</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Syne:wght@700;800&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/lucide@latest"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        :root {
            --bg-primary: #0a0d1a;
            --bg-secondary: #0f1535;
            --bg-surface: #141a3d;
            --accent-primary: #4f46e5;
            --accent-secondary: #7c3aed;
            --accent-gradient: linear-gradient(135deg, #4f46e5, #7c3aed);
            --text-primary: #f0f4ff;
            --text-muted: #8892b0;
            --border-color: rgba(79, 70, 229, 0.2);

            --success: #00D97E;
            --warning: #FFD666;
            --error: #FF4757;
            --free: #FFD666;
            --vip: #00D97E;
            --admin: #7B5CFF;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'DM Sans', sans-serif;
            background-color: var(--bg-primary);
            background-image: 
                radial-gradient(rgba(79, 70, 229, 0.12) 0%, transparent 50%),
                radial-gradient(rgba(124, 58, 237, 0.18) 50%, transparent 50%),
                radial-gradient(rgba(255, 255, 255, 0.04) 1px, transparent 1px);
            background-size: 100% 100%, 100% 100%, 24px 24px;
            background-position: 0 0, 100% 100%, 0 0;
            background-attachment: fixed;
            color: var(--text-primary);
            min-height: 100vh;
            line-height: 1.65;
            font-size: clamp(0.9rem, 2vw, 1rem);
        }

        h1, h2, h3, h4 {
            font-family: 'Syne', sans-serif;
            font-weight: 700;
            letter-spacing: 0.02em;
        }

        h1 { font-size: clamp(1.8rem, 4vw, 3rem); }
        h2 { font-size: clamp(1.5rem, 3vw, 2.25rem); }
        h3 { font-size: clamp(1.25rem, 2.5vw, 1.75rem); }
        h4 { font-size: clamp(1.1rem, 2vw, 1.35rem); }

        .font-mono {
            font-family: 'JetBrains Mono', monospace;
        }

        p, label, input, textarea, select {
            font-family: 'DM Sans', sans-serif;
        }

        i[data-lucide] {
            display: inline-flex;
            align-items: center;
            vertical-align: middle;
        }

        .icon-wrapper {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .glass {
            background: rgba(20, 26, 61, 0.6);
            backdrop-filter: blur(12px);
            border: 1px solid rgba(79, 70, 229, 0.15);
            border-radius: 16px;
            box-shadow: 0 4px 24px rgba(0, 0, 0, 0.3);
            transition: all 0.3s ease;
        }

        .glass:hover {
            border-color: rgba(79, 70, 229, 0.4);
            box-shadow: 0 0 20px rgba(79, 70, 229, 0.12);
        }

        .card {
            background: rgba(20, 26, 61, 0.6);
            backdrop-filter: blur(12px);
            border: 1px solid rgba(79, 70, 229, 0.15);
            border-radius: 16px;
            box-shadow: 0 4px 24px rgba(0, 0, 0, 0.3);
            transition: all 0.3s ease;
        }

        .card:hover {
            border-color: rgba(79, 70, 229, 0.4);
            box-shadow: 0 0 20px rgba(79, 70, 229, 0.12);
        }

        body {
            background-image: radial-gradient(rgba(255, 255, 255, 0.04) 1px, transparent 1px);
            background-size: 24px 24px;
        }

        /* Login */
        .login-container {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            background: linear-gradient(135deg, var(--bg-primary) 0%, var(--bg-secondary) 100%);
        }

        .login-box {
            background: var(--bg-surface);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            padding: 40px;
            width: 100%;
            max-width: 400px;
            animation: fadeIn 0.3s ease;
            background: rgba(20, 26, 61, 0.6);
            backdrop-filter: blur(12px);
            border: 1px solid rgba(79, 70, 229, 0.15);
            box-shadow: 0 4px 24px rgba(0, 0, 0, 0.3);
        }

        .login-logo {
            text-align: center;
            margin-bottom: 30px;
        }

        .login-logo h1 {
            font-size: 28px;
            font-weight: 700;
            color: var(--accent-primary);
            letter-spacing: 2px;
        }

        .login-logo .icon {
            font-size: 40px;
            margin-bottom: 10px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            font-size: 14px;
            color: var(--text-muted);
            margin-bottom: 8px;
        }

        .form-group input {
            width: 100%;
            padding: 14px 16px;
            background: rgba(20, 26, 61, 0.6);
            backdrop-filter: blur(12px);
            border: 1px solid rgba(79, 70, 229, 0.15);
            border-radius: 10px;
            color: var(--text-primary);
            font-size: 16px;
            transition: all 0.3s ease;
            box-shadow: 0 4px 24px rgba(0, 0, 0, 0.3);
        }

        .form-group input:focus {
            outline: none;
            border-color: rgba(79, 70, 229, 0.4);
            box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.2), 0 0 20px rgba(79, 70, 229, 0.12);
        }

        .form-group input::placeholder {
            color: var(--text-muted);
        }

        .btn {
            padding: 10px 22px;
            border: none;
            border-radius: 10px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.25s ease;
            width: 100%;
        }

        .btn-primary {
            background: linear-gradient(135deg, #4f46e5, #7c3aed);
            color: white;
        }

        .btn-primary:hover {
            box-shadow: 0 0 18px rgba(124, 58, 237, 0.45);
            transform: scale(1.03);
        }

        .btn-primary:active {
            transform: scale(0.97);
        }

        .btn-secondary {
            background: transparent;
            border: 1px solid var(--accent-primary);
            color: var(--text-primary);
        }

        .btn-secondary:hover {
            background: rgba(79, 70, 229, 0.12);
        }

        .login-error {
            background: rgba(255, 71, 87, 0.1);
            border: 1px solid var(--error);
            color: var(--error);
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 14px;
            display: none;
        }

        .login-footer {
            text-align: center;
            margin-top: 20px;
            font-size: 12px;
            color: var(--text-muted);
        }

        .app-footer {
            text-align: center;
            padding: 16px 24px;
            font-size: 12px;
            color: var(--text-muted);
            border-top: 1px solid rgba(79, 70, 229, 0.1);
            margin-top: 24px;
        }

        .hidden { display: none !important; }

        /* Dashboard */
        .dashboard {
            display: none;
            min-height: 100vh;
        }

        .dashboard.active {
            display: block;
        }

        /* Header */
        .header {
            background: rgba(10, 13, 26, 0.85);
            backdrop-filter: blur(14px);
            border-bottom: 1px solid rgba(79, 70, 229, 0.15);
            padding: 16px 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: sticky;
            top: 0;
            z-index: 100;
        }

        .header-logo {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .header-logo h1 {
            font-size: 22px;
            font-weight: 700;
            color: var(--accent-primary);
            letter-spacing: 1px;
            font-family: 'Syne', sans-serif;
        }

        .header-logo .icon {
            font-size: 24px;
        }

        .header-info {
            display: flex;
            align-items: center;
            gap: 20px;
        }

        .server-status {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 13px;
            padding: 8px 12px;
            background: var(--bg-surface);
            border-radius: 20px;
        }

        .server-status .dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: var(--error);
        }

        .server-status.active .dot {
            background: var(--success);
            box-shadow: 0 0 8px var(--success);
        }

        .user-badge {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 8px 16px;
            background: var(--bg-surface);
            border-radius: 8px;
        }

        .user-badge .username {
            font-weight: 500;
        }

        .user-badge .level {
            font-size: 12px;
            padding: 4px 10px;
            border-radius: 12px;
            font-weight: 600;
            text-transform: uppercase;
        }

        .level-1 { background: #666; color: #fff; }
        .level-2 { background: var(--accent-primary); color: #fff; }
        .level-3 { background: var(--success); color: #000; }
        .level-admin { background: var(--admin); color: #fff; }
        .level-free, .level-vip { display: none; }

        .btn-logout {
            padding: 10px 22px;
            background: transparent;
            border: 1px solid var(--error);
            color: var(--error);
            border-radius: 10px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            transition: all 0.25s ease;
        }

        .btn-logout:hover {
            background: var(--error);
            color: white;
            box-shadow: 0 0 18px rgba(255, 71, 87, 0.45);
            transform: scale(1.03);
        }

        .btn-logout:active {
            transform: scale(0.97);
        }

        /* Navigation */
        .nav-tabs {
            display: flex;
            gap: 4px;
            padding: 16px 24px;
            background: rgba(10, 13, 26, 0.85);
            backdrop-filter: blur(14px);
            border-bottom: 1px solid rgba(79, 70, 229, 0.15);
            overflow-x: auto;
        }

        .nav-tab {
            padding: 12px 20px;
            background: transparent;
            border: none;
            color: var(--text-muted);
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            border-radius: 8px;
            transition: all 0.25s ease;
            white-space: nowrap;
        }

        .nav-tab:hover {
            background: var(--bg-surface);
            color: var(--text-primary);
        }

        .nav-tab.active {
            background: var(--accent-primary);
            color: white;
        }

        /* Main Content */
        .main-content {
            padding: 48px;
            max-width: 1400px;
            margin: 0 auto;
        }

.section {
            display: none;
            padding: 48px 0;
        }

        .section.active {
            display: block;
            animation: fadeIn 0.3s ease;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        @media (max-width: 768px) {
            .main-content {
                padding: 24px;
            }
            .section {
                padding: 24px 0;
            }
        }

        /* Scroll entrance animations */
        .animate-on-scroll {
            opacity: 0;
            transform: translateY(20px);
            transition: opacity 0.5s ease, transform 0.5s ease;
        }

        .animate-on-scroll.visible {
            opacity: 1;
            transform: translateY(0);
        }

        /* Disclaimer */
        .disclaimer {
            background: rgba(20, 26, 61, 0.6);
            backdrop-filter: blur(12px);
            border: 1px solid rgba(79, 70, 229, 0.15);
            border-radius: 16px;
            padding: 16px 20px;
            margin-bottom: 24px;
            box-shadow: 0 4px 24px rgba(0, 0, 0, 0.3);
            transition: all 0.3s ease;
        }

        .disclaimer:hover {
            border-color: rgba(79, 70, 229, 0.4);
            box-shadow: 0 0 20px rgba(79, 70, 229, 0.12);
        }

        .disclaimer h3 {
            color: var(--warning);
            font-size: 14px;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .disclaimer ul {
            font-size: 13px;
            color: var(--text-muted);
            padding-left: 20px;
        }

        .disclaimer li {
            margin-bottom: 4px;
        }

        /* Cards */
        .section-title {
            font-size: 20px;
            font-weight: 600;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .section-title .count {
            font-size: 14px;
            background: var(--bg-surface);
            padding: 4px 12px;
            border-radius: 12px;
            color: var(--text-muted);
        }

        .tokens-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 16px;
            margin-bottom: 32px;
        }

        .token-card {
            background: rgba(20, 26, 61, 0.6);
            backdrop-filter: blur(12px);
            border: 1px solid rgba(79, 70, 229, 0.15);
            border-radius: 16px;
            padding: 20px;
            box-shadow: 0 4px 24px rgba(0, 0, 0, 0.3);
            transition: all 0.3s ease;
            cursor: pointer;
        }

        .token-card:hover {
            border-color: rgba(79, 70, 229, 0.4);
            box-shadow: 0 0 20px rgba(79, 70, 229, 0.12);
        }

        .token-card-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 12px;
        }

        .token-name {
            font-size: 16px;
            font-weight: 600;
        }

        .token-symbol {
            font-size: 13px;
            color: var(--text-muted);
        }

        .chain-badge {
            font-size: 11px;
            padding: 4px 10px;
            border-radius: 12px;
            font-weight: 600;
            text-transform: uppercase;
        }

        .chain-solana { background: #9945FF; color: white; }
        .chain-ethereum { background: #627EEA; color: white; }
        .chain-base { background: #0052FF; color: white; }
        .chain-arbitrum { background: #28A0F0; color: white; }
        .chain-bsc { background: #F3BA2F; color: white; }

        .token-price {
            font-size: 22px;
            font-weight: 700;
            margin: 12px 0;
        }

        .token-change {
            display: flex;
            gap: 16px;
            margin-bottom: 12px;
        }

        .token-change span {
            font-size: 13px;
        }

        .positive { color: var(--success); }
        .negative { color: var(--error); }

        .token-stats {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 8px;
            font-size: 12px;
            color: var(--text-muted);
            margin-bottom: 16px;
        }

        .token-stats div {
            display: flex;
            justify-content: space-between;
        }

        .token-address {
            display: flex;
            align-items: center;
            gap: 8px;
            background: var(--bg-primary);
            padding: 10px 12px;
            border-radius: 6px;
            font-family: 'JetBrains Mono', monospace;
            font-size: 11px;
            color: var(--text-muted);
        }

        .token-address span {
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            flex: 1;
        }

        .btn-copy {
            background: var(--bg-surface);
            border: none;
            color: var(--accent-primary);
            padding: 6px 10px;
            border-radius: 10px;
            cursor: pointer;
            font-size: 12px;
            font-weight: 600;
            transition: all 0.25s ease;
        }

        .btn-copy:hover {
            background: var(--accent-primary);
            color: white;
            box-shadow: 0 0 18px rgba(124, 58, 237, 0.45);
            transform: scale(1.03);
        }

        .btn-copy:active {
            transform: scale(0.97);
        }

        .token-status {
            margin-top: 12px;
            padding: 8px 12px;
            border-radius: 6px;
            font-size: 12px;
            text-align: center;
            font-weight: 500;
        }

        .status-monitoring { background: rgba(123, 92, 255, 0.2); color: var(--accent-primary); }
        .status-new { background: rgba(0, 217, 126, 0.2); color: var(--success); }

        /* Free User Token */
        .free-token-container {
            max-width: 500px;
            margin: 0 auto;
        }

        .free-token-card {
            background: rgba(20, 26, 61, 0.6);
            backdrop-filter: blur(12px);
            border: 2px solid var(--warning);
            border-radius: 16px;
            padding: 32px;
            text-align: center;
            box-shadow: 0 4px 24px rgba(0, 0, 0, 0.3);
            transition: all 0.3s ease;
        }

        .free-token-card:hover {
            box-shadow: 0 0 20px rgba(79, 70, 229, 0.12);
        }

        .free-token-card .icon {
            font-size: 48px;
            margin-bottom: 16px;
        }

        .free-token-card .name {
            font-size: 28px;
            font-weight: 700;
            margin-bottom: 8px;
        }

        .free-token-card .chain {
            font-size: 14px;
            color: var(--text-muted);
            margin-bottom: 24px;
        }

        .free-token-details {
            background: var(--bg-primary);
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 20px;
        }

        .free-token-details .row {
            display: flex;
            justify-content: space-between;
            padding: 12px 0;
            border-bottom: 1px solid var(--border-color);
        }

        .free-token-details .row:last-child {
            border-bottom: none;
        }

        .free-token-details .label {
            color: var(--text-muted);
            font-size: 14px;
        }

        .free-token-details .value {
            font-weight: 600;
            font-size: 14px;
        }

        .tiempo-restante {
            background: rgba(255, 214, 102, 0.1);
            border: 1px solid var(--warning);
            border-radius: 12px;
            padding: 20px;
            margin-top: 20px;
        }

        .tiempo-restante .label {
            font-size: 14px;
            color: var(--warning);
            margin-bottom: 8px;
        }

        .tiempo-restante .time {
            font-size: 28px;
            font-weight: 700;
            color: var(--warning);
        }

        .upgrade-banner {
            background: linear-gradient(135deg, var(--accent-primary) 0%, var(--accent-secondary) 100%);
            border-radius: 12px;
            padding: 24px;
            margin-top: 24px;
            text-align: center;
        }

        .upgrade-banner h3 {
            margin-bottom: 8px;
        }

        .upgrade-banner p {
            font-size: 14px;
            opacity: 0.9;
        }

        /* Historial Table */
        .historial-table {
            width: 100%;
            border-collapse: collapse;
            background: var(--bg-surface);
            border-radius: 12px;
            overflow: hidden;
        }

        .historial-table th,
        .historial-table td {
            padding: 14px 16px;
            text-align: left;
            border-bottom: 1px solid rgba(79, 70, 229, 0.15);
        }

        .historial-table th {
            background: rgba(20, 26, 61, 0.6);
            backdrop-filter: blur(12px);
            font-weight: 600;
            font-size: 13px;
            color: var(--text-muted);
            text-transform: uppercase;
        }

        .historial-table tr:hover {
            background: rgba(79, 70, 229, 0.08);
        }

        .profit-positive {
            color: #22c55e;
            background: rgba(34, 197, 94, 0.12);
            padding: 2px 10px;
            border-radius: 999px;
            display: inline-block;
            font-weight: 600;
        }

        .profit-negative {
            color: #ef4444;
            background: rgba(239, 68, 68, 0.12);
            padding: 2px 10px;
            border-radius: 999px;
            display: inline-block;
            font-weight: 600;
        }

        .profit-badge {
            font-size: 0.75rem;
            padding: 2px 10px;
            border-radius: 999px;
            margin-left: 8px;
        }

        .table-wrapper {
            width: 100%;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
            border-radius: 12px;
        }

        .table-wrapper thead {
            position: sticky;
            top: 0;
            z-index: 10;
        }

        .table-wrapper th {
            background: #0f1535;
        }

        .table-wrapper tbody tr:nth-child(odd) {
            background: rgba(20, 26, 61, 0.5);
        }

        .table-wrapper tbody tr:nth-child(even) {
            background: rgba(20, 26, 61, 0.3);
        }

        .table-wrapper tbody tr:hover {
            background: rgba(79, 70, 229, 0.08);
        }

        .table-wrapper td {
            padding: 12px 16px;
            border-bottom: 1px solid rgba(79, 70, 229, 0.1);
        }

        @media (max-width: 640px) {
            .table-wrapper thead {
                display: none;
            }
            .table-wrapper tbody tr {
                display: block;
                background: rgba(20, 26, 61, 0.85);
                border-radius: 12px;
                margin-bottom: 12px;
                padding: 12px 16px;
                border: 1px solid rgba(79, 70, 229, 0.15);
            }
            .table-wrapper td {
                display: flex;
                justify-content: space-between;
                align-items: center;
                padding: 6px 0;
                border: none;
                font-size: 0.875rem;
            }
            .table-wrapper td::before {
                content: attr(data-label);
                font-weight: 600;
                color: #8892b0;
                margin-right: 12px;
            }
        }

        .profit-badge.profit-positive {
            background: rgba(46, 204, 113, 0.2);
            color: var(--success);
        }

        .profit-badge.profit-negative {
            background: rgba(231, 76, 60, 0.2);
            color: var(--error);
        }

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
            margin-bottom: 32px;
        }

        .admin-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
            margin-bottom: 32px;
        }

        .detail-modal {
            text-align: left;
        }

        .detail-section {
            margin-bottom: 16px;
            padding-bottom: 12px;
            border-bottom: 1px solid rgba(79, 70, 229, 0.1);
        }

        .detail-section:last-child {
            border-bottom: none;
            margin-bottom: 0;
            padding-bottom: 0;
        }

        .detail-section-title {
            font-weight: 600;
            font-size: 13px;
            color: var(--accent-primary);
            margin-bottom: 8px;
            letter-spacing: 0.5px;
        }

        .detail-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 3px 0;
            font-size: 13px;
        }

        .detail-label {
            color: var(--text-muted);
        }

        .detail-value {
            color: var(--text-primary);
            font-family: 'JetBrains Mono', monospace;
            font-size: 12px;
        }

        .daily-earnings {
            display: flex;
            flex-direction: column;
            gap: 6px;
            margin-top: 12px;
        }

        .daily-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px 14px;
            background: rgba(20, 26, 61, 0.4);
            border-radius: 8px;
            border: 1px solid rgba(79, 70, 229, 0.1);
        }

        .daily-date {
            font-size: 13px;
            color: var(--text-muted);
            font-family: 'JetBrains Mono', monospace;
        }

        .daily-value {
            font-size: 15px;
            font-weight: 700;
        }

        .daily-count {
            font-size: 11px;
            color: var(--text-muted);
        }

        .stat-card {
            background: rgba(20, 26, 61, 0.6);
            backdrop-filter: blur(12px);
            border: 1px solid rgba(79, 70, 229, 0.15);
            border-radius: 16px;
            padding: 20px;
            box-shadow: 0 4px 24px rgba(0, 0, 0, 0.3);
            transition: all 0.3s ease;
        }

        .stat-card:hover {
            border-color: rgba(79, 70, 229, 0.4);
            box-shadow: 0 0 20px rgba(79, 70, 229, 0.12);
        }

        .stat-card .value {
            font-size: 32px;
            font-weight: 700;
            color: var(--accent-primary);
        }

        .stat-card .label {
            font-size: 14px;
            color: var(--text-muted);
            margin-top: 4px;
        }

        .admin-users {
            background: rgba(20, 26, 61, 0.6);
            backdrop-filter: blur(12px);
            border: 1px solid rgba(79, 70, 229, 0.15);
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 4px 24px rgba(0, 0, 0, 0.3);
        }

        .admin-users-header {
            padding: 20px;
            border-bottom: 1px solid rgba(79, 70, 229, 0.15);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .admin-users-table {
            width: 100%;
        }

        .admin-users-table th,
        .admin-users-table td {
            padding: 14px 20px;
            text-align: left;
            border-bottom: 1px solid rgba(79, 70, 229, 0.15);
        }

        .admin-users-table th {
            font-weight: 600;
            font-size: 13px;
            color: var(--text-muted);
            text-transform: uppercase;
            background: rgba(20, 26, 61, 0.4);
        }

        .admin-users-table tr:hover {
            background: rgba(79, 70, 229, 0.08);
        }
            background: var(--bg-secondary);
            font-weight: 600;
            font-size: 13px;
            color: var(--text-muted);
        }

        .admin-users-table tr:hover {
            background: var(--bg-surface);
        }

        .btn-edit, .btn-delete {
            padding: 10px 22px;
            border: none;
            border-radius: 10px;
            cursor: pointer;
            font-size: 12px;
            font-weight: 600;
            margin-right: 4px;
            transition: all 0.25s ease;
        }

        .btn-edit {
            background: linear-gradient(135deg, #4f46e5, #7c3aed);
            color: white;
        }

        .btn-edit:hover {
            box-shadow: 0 0 18px rgba(124, 58, 237, 0.45);
            transform: scale(1.03);
        }

        .btn-edit:active {
            transform: scale(0.97);
        }

        .btn-delete {
            background: linear-gradient(135deg, #ff4757, #ff6b7a);
            color: white;
        }

        .btn-delete:hover {
            box-shadow: 0 0 18px rgba(255, 71, 87, 0.45);
            transform: scale(1.03);
        }

        .btn-delete:active {
            transform: scale(0.97);
        }

        .btn-add {
            background: linear-gradient(135deg, #00d97e, #00f5a0);
            color: #000;
            padding: 10px 22px;
            border: none;
            border-radius: 10px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.25s ease;
        }

        .btn-add:hover {
            box-shadow: 0 0 18px rgba(0, 217, 126, 0.45);
            transform: scale(1.03);
        }

        .btn-add:active {
            transform: scale(0.97);
        }

        .admin-section {
            margin-bottom: 32px;
        }

        .criterios-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 12px;
            margin-bottom: 24px;
        }

        .criterio-card {
            background: rgba(20, 26, 61, 0.6);
            backdrop-filter: blur(12px);
            border: 1px solid rgba(79, 70, 229, 0.15);
            border-radius: 16px;
            padding: 16px;
            box-shadow: 0 4px 24px rgba(0, 0, 0, 0.3);
            transition: all 0.3s ease;
        }

        .criterio-card:hover {
            border-color: rgba(79, 70, 229, 0.4);
            box-shadow: 0 0 20px rgba(79, 70, 229, 0.12);
        }

        .criterio-card .label {
            font-size: 12px;
            color: var(--text-muted);
            margin-bottom: 4px;
        }

        .criterio-card .value {
            font-size: 18px;
            font-weight: 600;
            color: var(--accent-primary);
        }

        .coin-action-nuevo { background: rgba(0, 217, 126, 0.2); color: var(--success); }
        .coin-action-actualizado { background: rgba(123, 92, 255, 0.2); color: var(--accent-primary); }
        .coin-action-tp { background: rgba(0, 217, 126, 0.3); color: var(--success); }
        .coin-action-sl { background: rgba(255, 71, 87, 0.3); color: var(--error); }
        .coin-action-rechazado { background: rgba(157, 149, 168, 0.2); color: var(--text-muted); }

        /* Modal */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.8);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }

        .modal.active {
            display: flex;
        }

        .modal-content {
            background: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            padding: 32px;
            width: 100%;
            max-width: 400px;
            background: rgba(20, 26, 61, 0.6);
            backdrop-filter: blur(12px);
            border: 1px solid rgba(79, 70, 229, 0.15);
            box-shadow: 0 4px 24px rgba(0, 0, 0, 0.3);
            transition: all 0.3s ease;
        }

        .modal-content:hover {
            border-color: rgba(79, 70, 229, 0.4);
            box-shadow: 0 0 20px rgba(79, 70, 229, 0.12);
        }

        .modal-content h2 {
            margin-bottom: 20px;
        }

        .modal-actions {
            display: flex;
            gap: 12px;
            margin-top: 20px;
        }

        .modal-actions .btn {
            flex: 1;
        }

        /* Loading */
        .loading {
            text-align: center;
            padding: 40px;
            color: var(--text-muted);
        }

        .loading-spinner {
            width: 40px;
            height: 40px;
            border: 3px solid var(--border-color);
            border-top-color: var(--accent-primary);
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin: 0 auto 16px;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        /* Responsive */
        @media (max-width: 768px) {
            .header {
                flex-direction: column;
                gap: 16px;
                padding: 12px 16px;
            }

            .header-info {
                width: 100%;
                justify-content: space-between;
            }

            .nav-tabs {
                padding: 12px 16px;
            }

            .main-content {
                padding: 16px;
            }

            .tokens-grid {
                grid-template-columns: 1fr;
            }

            .login-box {
                padding: 24px;
            }

            .admin-stats {
                grid-template-columns: 1fr 1fr;
            }

            .historial-table {
                display: block;
                overflow-x: auto;
            }

            .admin-users-table {
                display: block;
                overflow-x: auto;
            }

            .admin-users {
                overflow-x: hidden;
            }

            .admin-users-header {
                flex-direction: column;
                gap: 12px;
                align-items: flex-start;
            }

            .btn-edit, .btn-delete {
                padding: 8px 14px;
                font-size: 14px;
            }
        }

        @media (max-width: 480px) {
            .admin-stats {
                grid-template-columns: 1fr;
            }

            .user-badge .username {
                display: none;
            }

            .btn-edit, .btn-delete {
                display: inline-block;
                min-width: 40px;
            }
        }
    </style>
</head>
<body>
    <!-- Login -->
    <div class="login-container" id="loginSection">
        <div class="login-box">
            <div class="login-logo">
                <i data-lucide="bar-chart-3" class="icon" style="width:40px;height:40px;stroke:var(--accent-primary);stroke-width:1.75"></i>
                <h1>ALTCHECKS</h1>
            </div>
            <div class="login-error" id="loginError"></div>
            <form id="loginForm">
                <div class="form-group">
                    <label>Username</label>
                    <input type="text" id="username" placeholder="Enter username" required>
                </div>
                <div class="form-group">
                    <label>Access PIN</label>
                    <input type="password" id="pin" placeholder="Enter PIN" required maxlength="10">
                </div>
                <button type="submit" class="btn btn-primary">Login</button>
            </form>
            <div class="login-footer">
                © 2026 AltChecks - Token Monitoring System
            </div>
            <div style="margin-top:16px;text-align:center;">
                <button type="button" onclick="document.getElementById('pricingSection').classList.toggle('hidden')" style="background:none;border:none;color:var(--accent-primary);cursor:pointer;font-size:0.9rem;">View Plans and Prices ▾</button>
            </div>
            <div id="pricingSection" class="hidden" style="margin-top:16px;background:rgba(45,40,80,0.5);border-radius:12px;padding:16px;">
                <div style="display:flex;flex-direction:column;gap:12px;">
                    <div style="background:var(--bg-secondary);padding:16px;border-radius:10px;border:1px solid var(--border-color);">
                        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;">
                            <span style="color:#aaa;font-weight:700;font-size:1.1rem;">BASIC</span>
                            <span style="color:#fff;font-size:1.4rem;font-weight:700;">$20<small style="color:#666;font-size:0.8rem;font-weight:400;">/28 days</small></span>
                        </div>
                        <div style="color:#888;font-size:0.8rem;line-height:1.6;">
                            • 5-10 tokens/week<br>
                            • 5-10% daily earnings<br>
                            • No API access<br>
                            • Update every 6h
                        </div>
                    </div>
                    <div style="background:var(--bg-secondary);padding:16px;border-radius:10px;border:1px solid var(--accent-primary);">
                        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;">
                            <span style="color:var(--accent-primary);font-weight:700;font-size:1.1rem;">PRO</span>
                            <span style="color:#fff;font-size:1.4rem;font-weight:700;">$20<small style="color:#666;font-size:0.8rem;font-weight:400;">/14 days</small></span>
                        </div>
                        <div style="color:#888;font-size:0.8rem;line-height:1.6;">
                            • 10-30 tokens/week<br>
                            • 15-25% daily earnings<br>
                            • Limited API<br>
                            • No live support
                        </div>
                    </div>
                    <div style="background:var(--bg-secondary);padding:16px;border-radius:10px;border:1px solid var(--success);">
                        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;">
                            <span style="color:var(--success);font-weight:700;font-size:1.1rem;">ULTRA</span>
                            <span style="color:#fff;font-size:1.4rem;font-weight:700;">$20<small style="color:#666;font-size:0.8rem;font-weight:400;">/7 days</small></span>
                        </div>
                        <div style="color:#888;font-size:0.8rem;line-height:1.6;">
                            • 50+ tokens/week<br>
                            • 35-50% daily earnings<br>
                            • Unlimited API<br>
                            • 24/7 live support
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Dashboard -->
    <div class="dashboard" id="dashboardSection">
        <!-- Header -->
        <header class="header">
            <div class="header-logo">
                <i data-lucide="bar-chart-3" style="width:24px;height:24px;stroke:var(--accent-primary);stroke-width:1.75"></i>
                <h1>ALTCHECKS</h1>
            </div>
            <div class="header-info">
                <div class="server-status" id="serverStatus">
                    <span class="dot"></span>
                    <span class="text">Server: Inactive</span>
                </div>
                <div class="user-badge">
                    <span class="username" id="userName"></span>
                    <span class="level" id="userLevel"></span>
                </div>
                <button class="btn-logout" id="logoutBtn">Logout</button>
            </div>
        </header>

        <!-- Navigation -->
        <nav class="nav-tabs" id="navTabs">
            <button class="nav-tab active" data-tab="overview">Overview</button>
            <button class="nav-tab" data-tab="tokens" id="tabTokens">Tokens</button>
            <button class="nav-tab" data-tab="historial" id="tabHistorial">History</button>
            <button class="nav-tab" data-tab="banned" id="tabBanned">Banned</button>
            <button class="nav-tab" data-tab="config" id="tabConfig">Config</button>
        </nav>

        <!-- Main Content -->
        <main class="main-content">
            <!-- Disclaimer -->
            <div class="disclaimer">
                <i data-lucide="alert-triangle" style="width:20px;height:20px;stroke:var(--warning);stroke-width:1.75"></i> IMPORTANT NOTICE
                <ul>
                    <li>Invest only capital you can afford to lose</li>
                    <li>Never more than 5% of your capital per trade</li>
                    <li>DYOR - Do your own research</li>
                    <li>Past results don't guarantee future results</li>
                </ul>
            </div>

            <!-- Overview Section -->
            <section class="section active" id="overviewSection">
                <div id="overviewContent">
                    <div class="loading">
                        <div class="loading-spinner"></div>
                        <p>Cargando...</p>
                    </div>
                </div>
            </section>

            <!-- Tokens Section -->
            <section class="section" id="tokensSection">
                <h2 class="section-title">Active Gems <span class="count" id="tokensCount">0</span></h2>
                <div class="tokens-grid" id="tokensGrid"></div>
            </section>

            <!-- Historial Section -->
            <section class="section" id="historialSection">
                <h2 class="section-title">Trade History</h2>
                <div class="table-wrapper">
                    <table class="historial-table">
                        <thead>
                            <tr>
                                <th>Token</th>
                                <th>Chain</th>
                                <th>Entry</th>
                                <th>Exit</th>
                                <th>Profit</th>
                                <th>Duration</th>
                                <th>Reason</th>
                                <th>Address</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="historialTable"></tbody>
                    </table>
                </div>
            </section>

            <!-- Banned Section (Admin Only) -->
            <section class="section" id="bannedSection">
                <i data-lucide="ban" style="width:24px;height:24px;stroke:var(--error);stroke-width:1.75"></i> Banned Tokens
                <div class="table-wrapper">
                    <table>
                        <thead>
                            <tr>
                                <th>Token Address</th>
                                <th>Pair Address</th>
                                <th>Chain</th>
                                <th>Reason</th>
                                <th>Date</th>
                            </tr>
                        </thead>
                        <tbody id="bannedTable"></tbody>
                    </table>
                </div>
            </section>

            <!-- Config Section (Admin Only) -->
            <section class="section" id="configSection">
                <div id="adminContent">
                    <div class="admin-stats" id="adminStats"></div>

                    <div class="admin-users">
                        <div class="admin-users-header">
                            <i data-lucide="users" style="width:20px;height:20px;stroke:var(--accent-primary);stroke-width:1.75"></i> User Management
                            <button class="btn-add" id="addUserBtn">+ Add User</button>
                        </div>
                        <div class="table-wrapper">
                            <table class="admin-users-table">
                                <thead>
                                    <tr>
                                        <th>Username</th>
                                        <th>Level</th>
                                        <th>Status</th>
                                        <th>Last Login</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody id="usersTable"></tbody>
                            </table>
                        </div>
                    </div>
                </div>

                    <div class="admin-section" style="margin-top:30px;">
                        <h3 class="section-title" style="cursor:pointer;display:flex;align-items:center;gap:8px;" onclick="document.getElementById('criteriosContent').style.display = document.getElementById('criteriosContent').style.display === 'none' ? 'block' : 'none';">
                            <i data-lucide="clipboard-list" style="width:18px;height:18px;stroke:var(--text-muted);stroke-width:1.75"></i> System Criteria <span style="font-size:0.8rem;color:var(--text-muted);">(click to expand)</span>
                        </h3>
                        <div id="criteriosContent" style="display:none;">
                            <div class="criterios-grid" id="criteriosGrid"></div>
                        </div>
                    </div>

                    <div class="admin-section" style="margin-top:30px;">
                        <i data-lucide="coins" style="width:20px;height:20px;stroke:var(--accent-primary);stroke-width:1.75"></i> Reviewed Coins
                        <div class="table-wrapper">
                            <table class="historial-table">
                                <thead>
                                    <tr>
                                        <th>Token</th>
                                        <th>Chain</th>
                                        <th>Price</th>
                                        <th>MC</th>
                                        <th>Reason</th>
                                        <th>Date</th>
                                    </tr>
                                </thead>
                                <tbody id="coinsTable"></tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </section>
        </main>
    </div>

    <!-- Modal -->
    <div class="modal" id="userModal">
        <div class="modal-content">
            <h2 id="modalTitle">Add User</h2>
            <form id="userForm">
                <div class="form-group">
                    <label>Username</label>
                    <input type="text" id="modalUsername" required>
                </div>
                <div class="form-group">
                    <label>PIN</label>
                    <input type="text" id="modalPin" required maxlength="10">
                </div>
                <div class="form-group">
                    <label>Level</label>
                    <select id="modalNivel" style="width:100%;padding:14px 16px;background:rgba(20,26,61,0.6);border:1px solid rgba(79,70,229,0.15);border-radius:10px;color:var(--text-primary);font-size:16px;backdrop-filter:blur(12px);" onchange="document.getElementById('modalNivelDetalle').style.display=this.value==='vip'?'block':'none'">
                        <option value="vip">VIP (Basic/Pro/Ultra)</option>
                        <option value="admin">Admin</option>
                    </select>
                </div>
                <div class="form-group" id="modalNivelDetalle" style="display:none;">
                    <label>Plan</label>
                    <select id="modalNivelDetalleSelect" style="width:100%;padding:14px 16px;background:rgba(20,26,61,0.6);border:1px solid rgba(79,70,229,0.15);border-radius:10px;color:var(--text-primary);font-size:16px;backdrop-filter:blur(12px);">
                        <option value="1">Basic (20%)</option>
                        <option value="2">Pro (50%)</option>
                        <option value="3">Ultra (100%)</option>
                    </select>
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" id="closeModalBtn">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save</button>
                </div>
            </form>
        </div>
    </div>

    <footer class="app-footer">
        v1.0.01 &copy; 2026 AltiMaster
    </footer>

    <script>
        console.log('AltChecks: Script loaded');
        
        let currentUser = null;
        let currentToken = null;
        let tiempoRestante = 0;
        let intervalId = null;
        let dataRefreshInterval = null;

        // Check session on load
        function checkSession() {
            const savedUser = localStorage.getItem('altchecks_user');
            const savedToken = localStorage.getItem('altchecks_token');

            if (savedUser && savedToken) {
                currentUser = JSON.parse(savedUser);
                currentToken = savedToken;
                showDashboard();
            }
        }

        function clearSession() {
            localStorage.removeItem('altchecks_user');
            localStorage.removeItem('altchecks_token');
            currentUser = null;
            currentToken = null;
            if (dataRefreshInterval) clearInterval(dataRefreshInterval);
            document.getElementById('loginSection').style.display = 'flex';
            document.getElementById('dashboardSection').classList.remove('active');
        }

        // Check session on page load
        console.log('AltChecks: Running checkSession...');
        checkSession();
        console.log('AltChecks: checkSession done, currentUser:', currentUser);

        // Login
        console.log('AltChecks: Setting up login form listener...');
        document.getElementById('loginForm').addEventListener('submit', async (e) => {
            e.preventDefault();
            const username = document.getElementById('username').value;
            const pin = document.getElementById('pin').value;
            const errorDiv = document.getElementById('loginError');
            errorDiv.style.display = 'none';

            try {
                const res = await fetch('api/auth.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ username, pin })
                });
                const responseText = await res.text();
                if (!res.ok) {
                    console.error('Server returned error:', responseText);
                    throw new Error('HTTP ' + res.status + ': ' + res.statusText);
                }
                let data;
                try {
                    data = JSON.parse(responseText);
                } catch (e) {
                    console.error('Invalid JSON:', responseText);
                    throw new Error('Server returned: ' + responseText.substring(0, 200));
                }

                if (data.success) {
                    currentUser = data.user;
                    currentToken = data.user.token;

                    localStorage.setItem('altchecks_user', JSON.stringify(data.user));
                    localStorage.setItem('altchecks_token', data.user.token);

                    showDashboard();
                } else {
                    errorDiv.textContent = data.error;
                    errorDiv.style.display = 'block';
                }
            } catch (err) {
                console.error('Login error:', err);
                let msg = 'Connection error: ';
                if (err.name === 'SyntaxError') {
                    msg += 'El servidor devolvió un error HTML en vez de JSON. Revisa la consola (Network tab).';
                } else if (err.message && err.message.includes('Failed to fetch')) {
                    msg += 'No se puede conectar al servidor. ¿Apache está corriendo?';
                } else {
                    msg += err.message || 'Unknown error';
                }
                errorDiv.textContent = msg;
                errorDiv.style.display = 'block';
            }
        });

        // Logout
        document.getElementById('logoutBtn').addEventListener('click', () => {
            fetch('api/auth.php', { method: 'DELETE' })
                .then(() => clearSession())
                .catch(() => clearSession());
        });

        // Show Dashboard
        function showDashboard() {
            console.log('AltChecks: showDashboard called');
            document.getElementById('loginSection').style.display = 'none';
            document.getElementById('dashboardSection').classList.add('active');

            console.log('AltChecks: Setting user info...');
            document.getElementById('userName').textContent = currentUser.username;
            const levelBadge = document.getElementById('userLevel');
            const nivelNames = { 1: 'Basic', 2: 'Pro', 3: 'Ultra', admin: 'Admin' };
            const nivelLabel = currentUser.nivel === 'admin' ? 'Admin' : (nivelNames[currentUser.nivel_detalle] || 'Basic');
            levelBadge.textContent = nivelLabel;
            levelBadge.className = 'level level-' + (currentUser.nivel === 'admin' ? 'admin' : currentUser.nivel_detalle);

            console.log('AltChecks: Resetting tabs...');
            // Reset tabs to overview
            document.querySelectorAll('.nav-tab').forEach(t => t.classList.remove('active'));
            document.querySelectorAll('.section').forEach(s => s.classList.remove('active'));
            document.querySelector('.nav-tab[data-tab="overview"]').classList.add('active');
            document.getElementById('overviewSection').classList.add('active');

            console.log('AltChecks: Updating server status...');
            updateServerStatus();

            if (currentUser.nivel === 'admin') {
                document.getElementById('tabConfig').style.display = 'inline-block';
                document.getElementById('tabBanned').style.display = 'inline-block';
            } else {
                document.getElementById('tabConfig').style.display = 'none';
                document.getElementById('tabBanned').style.display = 'none';
            }

            console.log('AltChecks: Calling loadData()...');
            loadData();

            if (dataRefreshInterval) clearInterval(dataRefreshInterval);
            dataRefreshInterval = setInterval(loadData, 10000);
            console.log('AltChecks: showDashboard complete');
        }

        // Server Status
        function updateServerStatus() {
            fetch('api/tokens.php?action=server', {
                headers: { 'Authorization': currentToken }
            })
            .then(r => r.json())
            .then(data => {
                const statusEl = document.getElementById('serverStatus');
                if (data.server.activo) {
                    statusEl.classList.add('active');
                    statusEl.querySelector('.text').textContent = 'Server: Active';
                } else {
                    statusEl.classList.remove('active');
                    statusEl.querySelector('.text').textContent = 'Server: Inactive';
                }
            });
        }

        // Tabs
        console.log('AltChecks: Setting up tab click handlers...');
        document.querySelectorAll('.nav-tab').forEach(tab => {
            tab.addEventListener('click', () => {
                console.log('AltChecks: Tab clicked:', tab.dataset.tab);
                document.querySelectorAll('.nav-tab').forEach(t => t.classList.remove('active'));
                document.querySelectorAll('.section').forEach(s => s.classList.remove('active'));

                tab.classList.add('active');
                const sectionId = tab.dataset.tab + 'Section';
                console.log('AltChecks: Showing section:', sectionId);
                document.getElementById(sectionId).classList.add('active');
            });
        });

        // Load Data
        function loadData() {
            console.log('AltChecks: loadData started, token:', currentToken ? 'present' : 'MISSING', 'user level:', currentUser?.nivel);
            const controller = new AbortController();
            const timeout = setTimeout(() => controller.abort(), 10000);
            
            fetch('api/tokens.php', {
                headers: { 'Authorization': currentToken },
                signal: controller.signal
            })
            .then(r => {
                clearTimeout(timeout);
                console.log('AltChecks: API response status:', r.status);
                return r.json();
            })
            .then(data => {
                console.log('AltChecks: API data received, success:', data.success, 'nivel:', currentUser.nivel);
                if (!data.success) {
                    document.getElementById('overviewContent').innerHTML = '<div class="error">Error: ' + (data.error || 'Unknown error') + '</div>';
                    return;
                }

                console.log('AltChecks: Calling render function for nivel:', currentUser.nivel);
                if (currentUser.nivel === 'free') {
                    renderFreeUser(data);
                } else {
                    renderVipUser(data);
                }
                console.log('AltChecks: Render function called');
            })
            .catch(err => {
                console.error('Error cargando datos:', err);
                const errorMsg = err.name === 'AbortError' ? 'Timeout' : (err.message || 'Connection error');
                document.getElementById('overviewContent').innerHTML = '<div class="error">' + errorMsg + '</div>';
            });

            if (currentUser.nivel === 'admin') {
                loadAdmin();
            }
        }

        // Render Free User
        function renderFreeUser(data) {
            console.log('AltChecks: renderFreeUser called');
            const content = document.getElementById('overviewContent');
            document.getElementById('tabTokens').style.display = 'none';
            document.getElementById('tabHistorial').style.display = 'none';

            if (!data.token) {
                content.innerHTML = `
                    <div class="disclaimer" style="background: rgba(123,92,255,0.1);border-color: var(--accent-primary);">
                        <i data-lucide="wifi-off" style="width:20px;height:20px;stroke:var(--text-muted);stroke-width:1.75"></i> Esperando datos...
                        <p>El servidor está procesando tokens. Por favor espera.</p>
                    </div>
                `;
                return;
            }

            const t = data.token;
            tiempoRestante = data.tiempo_restante;

            content.innerHTML = `
                <div class="free-token-container">
                    <div class="free-token-card">
                        <i data-lucide="loader" class="icon" style="width:40px;height:40px;stroke:var(--warning);stroke-width:1.75"></i>
                        <div class="name">${t.nombre || 'Token'}</div>
                        <div class="chain">${getChainBadge(t.chain_id)} ${t.simbolo || ''}</div>

                        <div class="free-token-details">
                            <div class="row">
                                <span class="label">Price</span>
                                <span class="value">$${formatPrice(t.precio_actual)}</span>
                            </div>
                            <div class="row">
                                <span class="label">Market Cap</span>
                                <span class="value">$${formatNumber(t.market_cap)}</span>
                            </div>
                            <div class="row">
                                <span class="label">Liquidez</span>
                                <span class="value">$${formatNumber(t.liquidez)}</span>
                            </div>
                            <div class="row">
                                <span class="label">Cambio 1h</span>
                                <span class="value ${t.cambio_1h >= 0 ? 'positive' : 'negative'}">${t.cambio_1h}%</span>
                            </div>
                            <div class="row">
                                <span class="label">Cambio 6h</span>
                                <span class="value ${t.cambio_6h >= 0 ? 'positive' : 'negative'}">${t.cambio_6h}%</span>
                            </div>
                        </div>

                        <div class="token-address">
                            <span>${truncateAddress(t.token_address)}</span>
                            <button class="btn-copy" onclick="copyToClipboard('${t.token_address}')">Copiar</button>
                        </div>

                        <div class="tiempo-restante" id="tiempoRestante">
                            <i data-lucide="clock" style="width:16px;height:16px;stroke:var(--text-muted);stroke-width:1.75"></i> Tiempo restante
                            <div class="time" id="countdown">${formatTime(tiempoRestante)}</div>
                        </div>
                    </div>

                    <div class="upgrade-banner">
                        <i data-lucide="zap" style="width:20px;height:20px;stroke:var(--accent-primary);stroke-width:1.75"></i> Actualiza a VIP!
                        <p>Ver TODOS los tokens en tiempo real</p>
                    </div>
                </div>
            `;

            startCountdown();
        }

        // Render VIP/Admin User
        function renderVipUser(data) {
            console.log('AltChecks: renderVipUser STARTED');
            try {
                document.getElementById('tabTokens').style.display = 'inline-block';
                document.getElementById('tabHistorial').style.display = 'inline-block';

                // Calcular ganancias de hoy (día Colombia)
                const ahora = new Date();
                const colombiaOffset = -5 * 60;
                const horaColombia = new Date(ahora.getTime() + colombiaOffset * 60 * 1000);
                const hoyColombia = new Date(horaColombia.getFullYear(), horaColombia.getMonth(), horaColombia.getDate());
                
                let gananciasHoy = 0;
                let tradesHoy = 0;
                if (data.historial && data.historial.length > 0) {
                    data.historial.forEach(h => {
                        if (h.fecha_salida) {
                            const fechaTrade = new Date(h.fecha_salida);
                            const fechaTradeColombia = new Date(fechaTrade.getTime() + colombiaOffset * 60 * 1000);
                            if (fechaTradeColombia >= hoyColombia) {
                                tradesHoy++;
                                gananciasHoy += parseFloat(h.profit_porcentaje) || 0;
                            }
                        }
                    });
                }
                const totalHoy = gananciasHoy;
                const gainClass = totalHoy >= 0 ? 'profit-positive' : 'profit-negative';
                const gainLabel = totalHoy >= 0 ? '+' : '';

                console.log('AltChecks: Rendering stats...');
                // Overview
                let statsHtml = `
                    <div class="stats-grid">
                        <div class="stat-card">
                            <div class="value">${data.tokens?.length || 0}</div>
                            <div class="label">Active Tokens</div>
                        </div>
                        <div class="stat-card">
                            <div class="value ${gainClass}">${gainLabel}${totalHoy.toFixed(2)}%</div>
                            <div class="label">Today's Earnings</div>
                        </div>
                        <div class="stat-card">
                            <div class="value">${data.historial?.length || 0}</div>
                            <div class="label">In History</div>
                        </div>
                    </div>
                `;
                document.getElementById('overviewContent').innerHTML = statsHtml;
                console.log('AltChecks: Stats rendered, tokens:', data.tokens?.length);

                // Daily Earnings (previous days)
                const hoyDate = hoyColombia.getFullYear() + '-' +
                    (hoyColombia.getMonth() + 1).toString().padStart(2, '0') + '-' +
                    hoyColombia.getDate().toString().padStart(2, '0');
                fetch('api/tokens.php?action=earnings_by_day', {
                    headers: { 'Authorization': currentToken }
                })
                .then(r => r.json())
                .then(ed => {
                    if (!ed.success || !ed.earnings || ed.earnings.length === 0) return;
                    let dailyHtml = '<div class="section" style="margin-top:24px;"><h3 class="section-title">Daily Earnings</h3><div class="daily-earnings">';
                    ed.earnings.forEach(e => {
                        if (e.entry_date === hoyDate) return;
                        const val = parseFloat(e.total_earnings);
                        const cls = val >= 0 ? 'profit-positive' : 'profit-negative';
                        const label = val >= 0 ? '+' : '';
                        const date = new Date(e.entry_date + 'T00:00:00');
                        const fmt = (date.getMonth()+1).toString().padStart(2,'0') + '/' + date.getDate().toString().padStart(2,'0') + '/' + date.getFullYear();
                        dailyHtml += `<div class="daily-row"><span class="daily-date">${fmt}</span><span class="daily-value ${cls}">${label}${val.toFixed(2)}%</span><span class="daily-count">${e.total_trades} trade${e.total_trades > 1 ? 's' : ''}</span></div>`;
                    });
                    dailyHtml += '</div></div>';
                    if (dailyHtml.includes('daily-row')) {
                        document.getElementById('overviewContent').insertAdjacentHTML('beforeend', dailyHtml);
                    }
                });

                // Tokens Grid
                document.getElementById('tokensCount').textContent = data.tokens?.length || 0;
                const grid = document.getElementById('tokensGrid');
                console.log('AltChecks: Rendering token grid, element exists:', !!grid);

                if (data.tokens && data.tokens.length > 0) {
                    grid.innerHTML = data.tokens.map(t => renderTokenCard(t)).join('');
                } else {
                    grid.innerHTML = '<p style="color:var(--text-muted);grid-column:1/-1;text-align:center;padding:40px;">No hay tokens activos</p>';
                }
                console.log('AltChecks: Token grid rendered');

                // Historial
                console.log('AltChecks: Rendering historial...');
                const historialTable = document.getElementById('historialTable');
                console.log('AltChecks: historialTable element:', !!historialTable);
                if (data.historial && data.historial.length > 0) {
                    historialTable.innerHTML = data.historial.map(h => `
                        <tr>
                            <td data-label="Token">
                                <div style="font-weight:600;">${h.nombre || h.simbolo || '?'}</div>
                                ${h.tag ? `<div style="font-size:0.7rem;color:var(--text-muted);margin-top:2px;">${h.tag}</div>` : ''}
                            </td>
                            <td data-label="Chain">${getChainBadge(h.chain_id)}</td>
                            <td data-label="Entry">$${formatPrice(h.precio_entrada)}</td>
                            <td data-label="Exit">$${formatPrice(h.precio_salida)}</td>
                            <td data-label="Profit" class="${h.profit_porcentaje >= 0 ? 'profit-positive' : 'profit-negative'}">${h.profit_porcentaje}%</td>
                            <td data-label="Duration">${h.duracion_minutos} min</td>
                            <td data-label="Reason">${getRazonSalida(h.razon_salida)}</td>
                            <td data-label="Address" style="font-size:0.75rem;">
                                <span title="${h.token_address}">${h.token_address ? h.token_address.substring(0, 6) + '...' + h.token_address.substring(h.token_address.length - 4) : '-'}</span>
                                ${h.token_address ? `<button class="btn-copy" onclick="copyToClipboard('${h.token_address}')">Copiar</button>` : ''}
                            </td>
                            ${currentUser.nivel === 'admin' ? `<td data-label="Actions"><button onclick="showTokenDetail(${h.id})" class="btn-edit" style="margin-right:4px;">Details</button><button onclick="banHistorial(${h.id})" class="btn-delete">Ban</button></td>` : ''}
                        </tr>
                    `).join('');
                } else {
                    historialTable.innerHTML = '<tr><td colspan="9" style="text-align:center;color:var(--text-muted);">No history</td></tr>';
                }
                console.log('AltChecks: Historial rendered');

                console.log('AltChecks: renderVipUser COMPLETE');
            } catch (e) {
                console.error('AltChecks: renderVipUser ERROR:', e);
            }
        }

        function renderTokenCard(t) {
            console.log('AltChecks: renderTokenCard called for:', t.nombre || t.simbolo);
            const precioEntrada = parseFloat(t.precio_entrada);
            const precioMax = parseFloat(t.precio_maximo) || precioEntrada;
            const profit = precioEntrada > 0 ? ((parseFloat(t.precio_actual) - precioEntrada) / precioEntrada * 100) : 0;
            const profitClass = profit >= 0 ? 'profit-positive' : 'profit-negative';
            const profitLabel = profit >= 0 ? '+' : '';
            
            const creado = t.primer_check ? new Date(t.primer_check) : null;
            const minutosActivo = creado ? Math.floor((new Date() - creado) / 60000) : 0;
            const tiempoLabel = minutosActivo < 60 ? `${minutosActivo}m` : `${Math.floor(minutosActivo/60)}h ${minutosActivo%60}m`;

            return `
                <div class="token-card">
                    <div class="token-card-header">
                        <div>
                            <div class="token-name">${t.nombre || 'Token'}</div>
                            <div class="token-symbol">${t.simbolo || ''}</div>
                        </div>
                        <span class="chain-badge ${getChainClass(t.chain_id)}">${t.chain_id}</span>
                    </div>
                    <div class="token-price">$${formatPrice(t.precio_actual)} <span class="profit-badge ${profitClass}">${profitLabel}${profit.toFixed(2)}%</span></div>
                    <div class="token-change">
                        <span>1h: <span class="${t.cambio_1h >= 0 ? 'positive' : 'negative'}">${t.cambio_1h}%</span></span>
                        <span>6h: <span class="${t.cambio_6h >= 0 ? 'positive' : 'negative'}">${t.cambio_6h}%</span></span>
                        <span>24h: <span class="${t.cambio_24h >= 0 ? 'positive' : 'negative'}">${t.cambio_24h}%</span></span>
                    </div>
                    <div class="token-stats">
                        <div><i data-lucide="clock" style="width:14px;height:14px;stroke:var(--text-muted);stroke-width:2"></i><span>${tiempoLabel}</span></div>
                        <div><span>Peak</span><span>$${formatPrice(precioMax)}</span></div>
                        <div><span>MC</span><span>$${formatNumber(t.market_cap)}</span></div>
                    </div>
                    <div class="token-address">
                        <span>${truncateAddress(t.token_address)}</span>
                        <button class="btn-copy" onclick="copyToClipboard('${t.token_address}')">Copiar</button>
                    </div>
                    <div class="token-status status-${t.estado}"><i data-lucide="${t.estado === 'monitoreando' ? 'activity' : 'sparkles'}" style="width:14px;height:14px;stroke:currentColor;stroke-width:2;margin-right:4px"></i>${t.estado === 'monitoreando' ? 'Monitoreando' : 'Nuevo'}</div>
                </div>
            `;
        }

        // Ban historial token (admin only)
        function banHistorial(historialId) {
            Swal.fire({ icon: 'warning', title: 'Delete token', text: 'Delete this token from history?', showCancelButton: true, confirmButtonText: 'Yes', cancelButtonText: 'Cancel' })
            .then((result) => {
                if (!result.isConfirmed) return;
                fetch('api/admin.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Authorization': currentToken },
                    body: JSON.stringify({ action: 'ban_historial', historial_id: historialId })
                })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        Swal.fire({ icon: 'success', title: 'Success', text: 'Token banned from history', timer: 2000 });
                        loadData();
                    } else {
                        Swal.fire({ icon: 'error', title: 'Error', text: data.error || 'Unknown error' });
                    }
                })
                .catch(err => Swal.fire({ icon: 'error', title: 'Error', text: 'Connection error' }));
            });
        }

        // Show token detail modal (admin only)
        function showTokenDetail(historialId) {
            fetch('api/tokens.php?action=detail&id=' + historialId, {
                headers: { 'Authorization': currentToken }
            })
            .then(r => r.json())
            .then(data => {
                if (!data.success) {
                    Swal.fire({ icon: 'error', title: 'Error', text: data.error || 'Unknown error' });
                    return;
                }
                const d = data.detail;
                const extra = data.token_extra;
                const profit = parseFloat(d.profit_porcentaje);
                const profitClass = profit >= 0 ? 'profit-positive' : 'profit-negative';
                const profitLabel = profit >= 0 ? '+' : '';

                Swal.fire({
                    title: (d.nombre || d.simbolo || 'Token') + ' <span style="font-size:0.7rem;opacity:0.6">' + (d.chain_id || '') + '</span>',
                    html: `
                        <div class="detail-modal">
                            <div class="detail-section">
                                <div class="detail-section-title">🔍 Discovery</div>
                                <div class="detail-row"><span class="detail-label">Date</span><span class="detail-value">${extra?.creado_en ? formatDate(extra.creado_en) : 'N/A'}</span></div>
                                <div class="detail-row"><span class="detail-label">Price</span><span class="detail-value">${d.precio_descubrimiento ? '$' + parseFloat(d.precio_descubrimiento).toFixed(8) : 'N/A'}</span></div>
                            </div>
                            <div class="detail-section">
                                <div class="detail-section-title">🚪 Entry</div>
                                <div class="detail-row"><span class="detail-label">Date</span><span class="detail-value">${d.fecha_entrada ? formatDate(d.fecha_entrada) : 'N/A'}</span></div>
                                <div class="detail-row"><span class="detail-label">Price</span><span class="detail-value">$${d.precio_entrada ? parseFloat(d.precio_entrada).toFixed(8) : 'N/A'}</span></div>
                            </div>
                            <div class="detail-section">
                                <div class="detail-section-title">📈 Peak</div>
                                <div class="detail-row"><span class="detail-label">Price</span><span class="detail-value">$${extra?.precio_maximo ? parseFloat(extra.precio_maximo).toFixed(8) : 'N/A'}</span></div>
                            </div>
                            <div class="detail-section">
                                <div class="detail-section-title">🏁 Exit</div>
                                <div class="detail-row"><span class="detail-label">Date</span><span class="detail-value">${d.fecha_salida ? formatDate(d.fecha_salida) : 'N/A'}</span></div>
                                <div class="detail-row"><span class="detail-label">Price</span><span class="detail-value">$${d.precio_salida ? parseFloat(d.precio_salida).toFixed(8) : 'N/A'}</span></div>
                            </div>
                            <div class="detail-section">
                                <div class="detail-section-title">📊 Summary</div>
                                <div class="detail-row"><span class="detail-label">Profit</span><span class="detail-value ${profitClass}">${profitLabel}${profit.toFixed(2)}%</span></div>
                                <div class="detail-row"><span class="detail-label">Duration</span><span class="detail-value">${d.duracion_minutos || 0} min</span></div>
                                <div class="detail-row"><span class="detail-label">Reason</span><span class="detail-value">${getRazonSalida(d.razon_salida)}</span></div>
                                ${d.tag ? `<div class="detail-row"><span class="detail-label">Tag</span><span class="detail-value">${d.tag}</span></div>` : ''}
                                ${d.es_reentry ? `<div class="detail-row"><span class="detail-label">Re-entry</span><span class="detail-value">Yes (x${d.reentry_count || 1})</span></div>` : ''}
                            </div>
                        </div>
                    `,
                    width: 480,
                    padding: '24px',
                    background: 'var(--bg-primary)',
                    showCloseButton: true,
                    showConfirmButton: false,
                    customClass: { popup: 'swal-detail' }
                });
            })
            .catch(err => Swal.fire({ icon: 'error', title: 'Error', text: 'Connection error' }));
        }

        // Admin
        function loadAdmin() {
            // Heartbeat - señal del servidor
            fetch('api/tokens.php?action=server', {
                headers: { 'Authorization': currentToken }
            })
            .then(r => r.json())
            .then(data => {
                const s = data.server?.status;
                let html = '<div class="stat-card"><div class="label">Server Signal</div>';
                if (s?.ultimo_check) {
                    const diffMin = Math.floor((new Date() - new Date(s.ultimo_check)) / 60000);
                    const color = diffMin < 2 ? 'var(--success)' : diffMin < 10 ? 'var(--warning)' : 'var(--error)';
                    const label = diffMin < 1 ? 'Just now' : diffMin === 1 ? '1 min ago' : diffMin + ' min ago';
                    html += `<div class="value" style="color:${color}">${label}</div>`;
                } else {
                    html += '<div class="value" style="color:var(--error)">No signal</div>';
                }
                html += '</div>';
                html += `<div class="stat-card"><div class="label">Active Tokens</div><div class="value">${s?.tokens_activos || 0}</div></div>`;
                document.getElementById('adminStats').innerHTML = html;
            });

            // Cargar usuarios
            fetch('api/admin.php?action=usuarios', {
                headers: { 'Authorization': currentToken }
            })
            .then(r => r.json())
            .then(data => {
                if (!data.success) return;
                const nivelNames = { 1: 'Basic', 2: 'Pro', 3: 'Ultra' };
                document.getElementById('usersTable').innerHTML = data.usuarios.map(u => `
                    <tr>
                        <td data-label="Username">${u.username}</td>
                        <td data-label="Level"><span class="level level-${u.nivel === 'admin' ? 'admin' : (u.nivel_detalle || 1)}">${u.nivel === 'admin' ? 'Admin' : (nivelNames[u.nivel_detalle] || 'Basic')}</span></td>
                        <td data-label="Status">${u.activo ? 'Active' : 'Inactive'}</td>
                        <td data-label="Last Login">${u.ultimo_login || 'Never'}</td>
                        <td data-label="Actions">
                            <button class="btn-edit" onclick="editUser(${u.id}, '${u.username}', '${u.nivel}', ${u.nivel_detalle || 1})">Edit</button>
                            ${u.id !== currentUser.id ? `<button class="btn-delete" onclick="deleteUser(${u.id})">Delete</button>` : ''}
                        </td>
                    </tr>
                `).join('');
            });

            // Cargar tokens baneados
            fetch('api/admin.php?action=tokens_banned', {
                headers: { 'Authorization': currentToken }
            })
            .then(r => r.json())
            .then(data => {
                if (!data.success) return;
                document.getElementById('bannedTable').innerHTML = data.tokens_banned.map(t => `
                    <tr>
                        <td data-label="Token Address"><span class="address" title="${t.token_address}">${t.token_address.substring(0, 12)}...</span></td>
                        <td data-label="Pair Address"><span class="address" title="${t.pair_address}">${t.pair_address.substring(0, 12)}...</span></td>
                        <td data-label="Chain">${t.chain_id}</td>
                        <td data-label="Reason">${t.razon || 'N/A'}</td>
                        <td data-label="Date">${t.banneado_en}</td>
                    </tr>
                `).join('');
            });

            // Cargar criterios
            fetch('api/admin.php?action=criterios', {
                headers: { 'Authorization': currentToken }
            })
            .then(r => r.json())
            .then(data => {
                if (!data.success) return;
                document.getElementById('criteriosGrid').innerHTML = data.criterios.map(c => `
                    <div class="criterio-card">
                        <div class="label">${c.label}</div>
                        <div class="value">${c.valor}</div>
                    </div>
                `).join('');
            });

            // Cargar coins revisadas
            fetch('api/admin.php?action=coins_revisadas&limit=50', {
                headers: { 'Authorization': currentToken }
            })
            .then(r => r.json())
            .then(data => {
                if (!data.success) return;
                const coinsTable = document.getElementById('coinsTable');
                if (data.coins && data.coins.length > 0) {
                    coinsTable.innerHTML = data.coins.map(c => `
                        <tr>
                            <td data-label="Token">${c.nombre || truncateAddress(c.pair_address)}</td>
                            <td data-label="Chain">${c.chain_id || '-'}</td>
                            <td data-label="Price">$${formatPrice(c.precio)}</td>
                            <td data-label="MC">$${formatNumber(c.market_cap)}</td>
                            <td data-label="Action"><span class="coin-action-${c.accion}">${c.accion}</span></td>
                            <td data-label="Reason">${c.razon || '-'}</td>
                            <td data-label="Date">${formatDate(c.revisado_en)}</td>
                        </tr>
                    `).join('');
                } else {
                    coinsTable.innerHTML = '<tr><td colspan="7" style="text-align:center;color:var(--text-muted);">No reviewed coins</td></tr>';
                }
            });
        }

        // Modal
        let editingUserId = null;
        document.getElementById('addUserBtn').addEventListener('click', () => {
            editingUserId = null;
            document.getElementById('modalTitle').textContent = 'Add User';
            document.getElementById('userForm').reset();
            document.getElementById('userModal').classList.add('active');
        });

        document.getElementById('closeModalBtn').addEventListener('click', () => {
            document.getElementById('userModal').classList.remove('active');
        });

        document.getElementById('userForm').addEventListener('submit', async (e) => {
            e.preventDefault();
            const username = document.getElementById('modalUsername').value;
            const pin = document.getElementById('modalPin').value;
            const nivel = document.getElementById('modalNivel').value;
            const nivelDetalle = document.getElementById('modalNivelDetalleSelect')?.value || (nivel === 'vip' ? 1 : null);

            console.log('Saving user - nivelDetalle:', nivelDetalle, 'nivel:', nivel);

            const action = editingUserId ? 'editar_usuario' : 'crear_usuario';
            const body = editingUserId
                ? { action, id: editingUserId, pin, nivel, nivel_detalle: nivelDetalle }
                : { action, username, pin, nivel, nivel_detalle: nivelDetalle };

            console.log('Request body:', JSON.stringify(body));

            const res = await fetch('api/admin.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Authorization': currentToken },
                body: JSON.stringify(body)
            });
            const data = await res.json();
            console.log('Server response:', data);

            if (data.success) {
                document.getElementById('userModal').classList.remove('active');
                loadAdmin();
            } else {
                Swal.fire({ icon: 'error', title: 'Error', text: data.error });
            }
        });

        window.editUser = (id, username, nivel, nivelDetalle = 1) => {
            editingUserId = id;
            document.getElementById('modalTitle').textContent = 'Edit User';
            document.getElementById('modalUsername').value = username;
            document.getElementById('modalUsername').disabled = true;
            document.getElementById('modalPin').value = '';
            document.getElementById('modalNivel').value = nivel;
            document.getElementById('modalNivel').dispatchEvent(new Event('change'));
            document.getElementById('modalNivelDetalle').style.display = nivel === 'vip' ? 'block' : 'none';
            if (document.getElementById('modalNivelDetalleSelect')) {
                document.getElementById('modalNivelDetalleSelect').value = String(nivelDetalle);
            }
            document.getElementById('userModal').classList.add('active');
        };

        window.deleteUser = async (id) => {
            const result = await Swal.fire({ icon: 'warning', title: 'Delete user', text: 'Are you sure?', showCancelButton: true, confirmButtonText: 'Yes, delete', cancelButtonText: 'Cancel' });
            if (!result.isConfirmed) return;
            const res = await fetch('api/admin.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Authorization': currentToken },
                body: JSON.stringify({ action: 'eliminar_usuario', id })
            });
            const data = await res.json();
            if (data.success) loadAdmin();
            else Swal.fire({ icon: 'error', title: 'Error', text: data.error });
        };

        // Countdown
        function startCountdown() {
            if (intervalId) clearInterval(intervalId);
            intervalId = setInterval(() => {
                if (tiempoRestante > 0) {
                    tiempoRestante--;
                    const el = document.getElementById('countdown');
                    if (el) el.textContent = formatTime(tiempoRestante);
                }
            }, 1000);
        }

        // Helpers
        function formatPrice(p) {
            if (!p) return '0.00000000';
            return parseFloat(p).toFixed(8);
        }

        function formatNumber(n) {
            if (!n) return '0';
            let num = parseFloat(n);
            if (isNaN(num)) return '0';
            if (num >= 1e9) return (num / 1e9).toFixed(2) + 'B';
            if (num >= 1e6) return (num / 1e6).toFixed(2) + 'M';
            if (num >= 1e3) return (num / 1e3).toFixed(2) + 'K';
            return num.toFixed(2);
        }

        function formatTime(seconds) {
            const h = Math.floor(seconds / 3600);
            const m = Math.floor((seconds % 3600) / 60);
            const s = seconds % 60;
            return `${h}h ${m}m ${s}s`;
        }

        function formatDate(dateStr) {
            if (!dateStr) return '-';
            const d = new Date(dateStr);
            return d.toLocaleString('es-ES', {
                day: '2-digit',
                month: '2-digit',
                hour: '2-digit',
                minute: '2-digit'
            });
        }

        function truncateAddress(addr) {
            if (!addr) return '';
            return addr.substring(0, 6) + '...' + addr.substring(addr.length - 4);
        }

        function getChainBadge(chain) {
            return `<span class="chain-badge ${getChainClass(chain)}">${chain}</span>`;
        }

        function getChainClass(chain) {
            const map = {
                'solana': 'chain-solana',
                'ethereum': 'chain-ethereum',
                'base': 'chain-base',
                'arbitrum': 'chain-arbitrum',
                'bsc': 'chain-bsc'
            };
            return map[chain?.toLowerCase()] || 'chain-solana';
        }

        function getRazonSalida(r) {
            const map = { 'tp': 'TP', 'sl': 'SL', 'save_tp': 'Save TP', 'caida_pico': 'Drop', 'timeout': 'Timeout', 'expirado': 'Expired', 'ban': 'Banned', 'manual': 'Manual' };
            return map[r] || r;
        }

        window.copyToClipboard = (text) => {
            navigator.clipboard.writeText(text).then(() => {
                Swal.fire({ icon: 'success', text: 'Copied to clipboard', timer: 1500, position: 'top' });
            });
        };

        // Scroll entrance animations
        try {
            if ('IntersectionObserver' in window) {
                const animateOnScroll = document.querySelectorAll('.stat-card, .token-card, .criterio-card, .disclaimer, .free-token-card, .modal-content');
                animateOnScroll.forEach(el => el.classList.add('animate-on-scroll'));
                
                const observer = new IntersectionObserver((entries) => {
                    entries.forEach(entry => {
                        if (entry.isIntersecting) {
                            entry.target.classList.add('visible');
                        }
                    });
                }, { threshold: 0.1 });

                animateOnScroll.forEach(el => observer.observe(el));
            } else {
                document.querySelectorAll('.animate-on-scroll').forEach(el => {
                    el.classList.add('visible');
                });
            }
        } catch (e) {
            console.error('Scroll animation error:', e);
        }

        // Initialize Lucide icons
        try {
            lucide.createIcons();
        } catch (e) {
            console.error('Lucide icons error:', e);
        }
        
        console.log('AltChecks: Init complete');
    </script>
</body>
</html>