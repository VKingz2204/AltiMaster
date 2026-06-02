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
    <title>AltiChecker - Token Monitoring System</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Syne:wght@700;800&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/lucide@latest"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        :root {
            --bg-primary: #070712;
            --bg-secondary: #0d0d1a;
            --bg-surface: #121225;
            --accent-primary: #00d4ff;
            --accent-secondary: #ff006e;
            --accent-gradient: linear-gradient(135deg, #00d4ff, #ff006e);
            --text-primary: #f0f4ff;
            --text-muted: #6b7294;
            --border-color: rgba(0, 212, 255, 0.15);
            --success: #00ff88;
            --warning: #ffbe0b;
            --error: #ff0054;
            --glass-bg: rgba(18, 18, 37, 0.6);
            --glass-border: rgba(0, 212, 255, 0.1);
            --glass-shadow: 0 4px 24px rgba(0, 0, 0, 0.3);
            --admin: #00d4ff;
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
                radial-gradient(rgba(0, 212, 255, 0.08) 0%, transparent 60%),
                radial-gradient(rgba(255, 0, 110, 0.05) 0%, transparent 50%),
                linear-gradient(rgba(0, 212, 255, 0.03) 1px, transparent 1px),
                linear-gradient(90deg, rgba(0, 212, 255, 0.03) 1px, transparent 1px);
            background-size: 100% 100%, 100% 100%, 24px 24px, 24px 24px;
            background-position: 0 0, 100% 100%, 0 0, 0 0;
            background-attachment: fixed;
            animation: gridMove 20s linear infinite;
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

        p, label, input, textarea, select {
            font-family: 'DM Sans', sans-serif;
        }

        i[data-lucide] {
            display: inline-flex;
            align-items: center;
            vertical-align: middle;
        }

        /* Login */
        .login-container {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            background: linear-gradient(135deg, var(--bg-primary) 0%, #0a0a1e 50%, var(--bg-secondary) 100%);
        }

        .login-box {
            background: rgba(18, 18, 37, 0.7);
            backdrop-filter: blur(20px) saturate(1.5);
            border: 1px solid rgba(0, 212, 255, 0.2);
            border-radius: 16px;
            padding: 40px;
            width: 100%;
            max-width: 400px;
            animation: fadeIn 0.3s ease, pulseGlow 3s ease-in-out infinite;
            box-shadow: 0 4px 24px rgba(0, 0, 0, 0.3);
        }

        .login-logo {
            text-align: center;
            margin-bottom: 30px;
        }

        .login-logo h1 {
            font-size: 28px;
            font-weight: 700;
            letter-spacing: 2px;
            background: var(--accent-gradient);
            background-size: 200% auto;
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            animation: shimmer 3s linear infinite;
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
            border: 1px solid var(--glass-border);
            border-radius: 10px;
            color: var(--text-primary);
            font-size: 16px;
        }
        .form-group textarea {
            padding: 14px 16px;
            background: rgba(20, 26, 61, 0.4);
            border: 1px solid var(--glass-border);
            border-radius: 10px;
            color: var(--text-primary);
            font-size: 16px;
            width: 100%;
            resize: vertical;
        }

        .form-group input:focus,
        .form-group textarea:focus {
            border-color: var(--accent-primary);
        }

        /* Login extras */
        .login-error {
            color: var(--error);
            background: rgba(255, 0, 84, 0.1);
            border: 1px solid rgba(255, 0, 84, 0.2);
            border-radius: 8px;
            padding: 10px 14px;
            margin-bottom: 16px;
            font-size: 13px;
            display: none;
        }

        .login-footer {
            text-align: center;
            margin-top: 20px;
            font-size: 12px;
            color: var(--text-muted);
        }

        /* Dashboard Layout */
        .dashboard {
            display: none;
            min-height: 100vh;
        }

        .dashboard.active {
            display: block;
        }

        .header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 12px 24px;
            background: var(--glass-bg);
            backdrop-filter: blur(16px) saturate(1.2);
            border-bottom: 1px solid var(--glass-border);
            position: sticky;
            top: 0;
            z-index: 100;
        }

        .header-logo {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .header-logo h1 {
            font-size: 20px;
            font-weight: 800;
            letter-spacing: 2px;
            background: var(--accent-gradient);
            background-size: 200% auto;
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .header-info {
            display: flex;
            align-items: center;
            gap: 16px;
        }

        /* Server Status */
        .server-status {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 12px;
            color: var(--text-muted);
        }

        .server-status .dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: var(--error);
            transition: background 0.3s;
        }

        .server-status.active .dot {
            background: var(--success);
            box-shadow: 0 0 8px rgba(0, 255, 136, 0.5);
        }

        /* User Badge */
        .user-badge {
            display: flex;
            align-items: center;
            gap: 8px;
            background: rgba(0, 212, 255, 0.08);
            border: 1px solid var(--glass-border);
            border-radius: 20px;
            padding: 6px 14px;
            font-size: 13px;
        }

        .user-badge .username {
            color: var(--text-primary);
            font-weight: 600;
        }

        .level {
            padding: 2px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
        }

        .level-free { background: #666; color: #fff; }
        .level-pro { background: var(--accent-primary); color: #070712; }
        .level-admin { background: var(--admin); color: #070712; }

        /* Refresh Button */
        .btn-refresh {
            background: rgba(0, 212, 255, 0.1);
            border: 1px solid var(--glass-border);
            border-radius: 8px;
            padding: 8px 12px;
            color: var(--accent-primary);
            cursor: pointer;
            transition: all 0.25s ease;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .btn-refresh:hover {
            background: rgba(0, 212, 255, 0.2);
            border-color: var(--accent-primary);
        }

        .btn-refresh.spinning svg {
            animation: spin 1s linear infinite;
        }

        /* Logout */
        .btn-logout {
            padding: 8px 18px;
            background: transparent;
            border: 1px solid var(--error);
            color: var(--error);
            border-radius: 8px;
            cursor: pointer;
            font-size: 13px;
            font-weight: 600;
            transition: all 0.25s ease;
        }

        .btn-logout:hover {
            background: rgba(255, 0, 84, 0.15);
            box-shadow: 0 0 12px rgba(255, 0, 84, 0.3);
        }

        /* Navigation */
        .nav-tabs {
            display: flex;
            gap: 6px;
            padding: 12px 24px;
            background: var(--glass-bg);
            backdrop-filter: blur(16px) saturate(1.2);
            border-bottom: 1px solid var(--glass-border);
            overflow-x: auto;
        }

        .nav-tab {
            padding: 8px 20px;
            border: 1px solid var(--glass-border);
            border-radius: 10px;
            background: transparent;
            color: var(--text-muted);
            cursor: pointer;
            font-size: 13px;
            font-weight: 600;
            transition: all 0.25s ease;
            white-space: nowrap;
        }

        .nav-tab:hover {
            border-color: var(--accent-primary);
            color: var(--accent-primary);
        }

        .nav-tab.active {
            background: rgba(0, 212, 255, 0.15);
            border-color: var(--accent-primary);
            color: var(--accent-primary);
            box-shadow: 0 0 12px rgba(0, 212, 255, 0.2);
        }

        /* Main Content */
        .main-content {
            padding: 24px;
            max-width: 1400px;
            margin: 0 auto;
        }

        /* Section Visibility */
        .section {
            display: none;
        }

        .section.active {
            display: block;
        }

        /* Section Title */
        .section-title {
            font-size: 20px;
            font-weight: 600;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 12px;
            color: var(--text-primary);
        }

        .section-title .count {
            font-size: 14px;
            background: var(--bg-surface);
            padding: 4px 12px;
            border-radius: 12px;
            color: var(--text-muted);
        }

        /* Tokens Grid */
        .tokens-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 16px;
        }

        /* Token Card */
        .token-card {
            background: var(--glass-bg);
            backdrop-filter: blur(16px) saturate(1.2);
            border: 1px solid var(--glass-border);
            border-radius: 16px;
            padding: 20px;
            box-shadow: var(--glass-shadow);
            transition: all 0.35s cubic-bezier(0.25, 0.46, 0.45, 0.94);
            animation: slideIn 0.4s ease forwards;
        }

        .token-card:hover {
            transform: translateY(-6px);
            border-color: rgba(0, 212, 255, 0.35);
            box-shadow: 0 0 30px rgba(0, 212, 255, 0.2), 0 8px 32px rgba(0, 0, 0, 0.4);
        }

        .token-card-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 12px;
        }

        .token-name {
            font-size: 16px;
            font-weight: 700;
            color: var(--text-primary);
        }

        .token-symbol {
            font-size: 12px;
            color: var(--text-muted);
            margin-top: 2px;
        }

        .token-price {
            font-size: 20px;
            font-weight: 700;
            color: var(--text-primary);
            display: flex;
            align-items: center;
            gap: 8px;
        }

        /* Chain Badge */
        .chain-badge {
            padding: 3px 10px;
            border-radius: 8px;
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            white-space: nowrap;
        }

        .chain-solana { background: rgba(153, 69, 255, 0.2); color: #9945ff; }
        .chain-ethereum { background: rgba(98, 126, 234, 0.2); color: #627eea; }
        .chain-base { background: rgba(0, 82, 255, 0.2); color: #0052ff; }
        .chain-arbitrum { background: rgba(40, 160, 240, 0.2); color: #28a0f0; }
        .chain-bsc { background: rgba(243, 186, 47, 0.2); color: #f3ba2f; }

        /* Buttons */
        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 10px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            transition: all 0.25s ease;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }

        .btn-primary {
            background: var(--accent-gradient);
            color: #070712;
            width: 100%;
            padding: 14px;
            font-size: 16px;
        }

        .btn-primary:hover {
            box-shadow: 0 0 20px rgba(0, 212, 255, 0.4);
            transform: translateY(-1px);
        }

        .btn-secondary {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid var(--glass-border);
            color: var(--text-primary);
        }

        .btn-secondary:hover {
            background: rgba(255, 255, 255, 0.1);
            border-color: var(--accent-primary);
        }

        /* Table Wrapper */
        .table-wrapper {
            background: var(--glass-bg);
            backdrop-filter: blur(16px) saturate(1.2);
            border: 1px solid var(--glass-border);
            border-radius: 16px;
            overflow: hidden;
            box-shadow: var(--glass-shadow);
        }

        /* Historial Table */
        .historial-table {
            width: 100%;
            border-collapse: collapse;
        }

        .historial-table th,
        .historial-table td {
            padding: 14px 16px;
            text-align: left;
            border-bottom: 1px solid var(--glass-border);
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
            background: rgba(0, 212, 255, 0.06);
        }

        .hist-date-header td {
            cursor: pointer;
            background: rgba(0, 212, 255, 0.06);
            font-weight: 600;
            font-size: 14px;
        }

        .hist-date-header:hover td {
            background: rgba(0, 212, 255, 0.12);
        }

        /* Disclaimer */
        .disclaimer {
            background: var(--glass-bg);
            backdrop-filter: blur(16px) saturate(1.2);
            border: 1px solid var(--glass-border);
            border-radius: 16px;
            padding: 16px 20px;
            margin-bottom: 24px;
            box-shadow: var(--glass-shadow);
            transition: all 0.35s cubic-bezier(0.25, 0.46, 0.45, 0.94);
            display: flex;
            align-items: flex-start;
            gap: 12px;
            font-size: 14px;
            font-weight: 600;
        }

        .disclaimer:hover {
            border-color: rgba(0, 212, 255, 0.35);
            box-shadow: 0 0 30px rgba(0, 212, 255, 0.18), 0 8px 32px rgba(0, 0, 0, 0.4);
        }

        .disclaimer ul {
            font-size: 13px;
            color: var(--text-muted);
            padding-left: 20px;
        }

        .disclaimer li {
            margin-bottom: 4px;
        }

        /* Footer */
        .app-footer {
            text-align: center;
            padding: 16px 24px;
            font-size: 12px;
            color: var(--text-muted);
            border-top: 1px solid var(--glass-border);
            margin-top: 24px;
        }

        /* Hidden */
        .hidden { display: none !important; }

        /* Scroll Animations */
        .animate-on-scroll {
            opacity: 0;
            transform: translateY(30px);
            transition: all 0.6s cubic-bezier(0.25, 0.46, 0.45, 0.94);
        }

        .animate-on-scroll.visible {
            opacity: 1;
            transform: translateY(0);
        }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        .profit-badge.profit-positive {
            background: rgba(46, 204, 113, 0.2);
            color: var(--success);
        }

        .profit-badge.profit-negative {
            background: rgba(231, 76, 60, 0.2);
            color: var(--error);
        }

        .profit-positive { color: var(--success); }
        .profit-negative { color: var(--error); }
        .text { color: inherit; }
        .hist-toggle { display: inline-block; width: 16px; text-align: center; font-size: 11px; color: var(--text-muted); }

        /* Stats Grid */
        .stats-grid,
        .admin-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
            margin-bottom: 32px;
        }

        .api-key-card {
            background: var(--glass-bg);
            backdrop-filter: blur(16px) saturate(1.2);
            border: 1px solid var(--glass-border);
            border-radius: 16px;
            padding: 20px;
            margin-bottom: 24px;
            box-shadow: var(--glass-shadow);
            transition: all 0.35s cubic-bezier(0.25, 0.46, 0.45, 0.94);
        }
        .api-key-card:hover {
            border-color: rgba(0, 212, 255, 0.35);
            box-shadow: 0 0 30px rgba(0, 212, 255, 0.18), 0 8px 32px rgba(0, 0, 0, 0.4);
        }

        .api-key-card .key-display {
            font-family: 'JetBrains Mono', monospace;
            font-size: 14px;
            color: var(--text-primary);
            background: rgba(0, 0, 0, 0.3);
            padding: 10px 14px;
            border-radius: 8px;
            word-break: break-all;
            margin: 10px 0;
            letter-spacing: 0.5px;
        }

        .api-key-card .key-actions {
            display: flex;
            gap: 8px;
            margin-top: 10px;
        }

        .criteria-form {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
            gap: 12px;
            margin-bottom: 20px;
        }

        .criteria-field {
            background: rgba(20, 26, 61, 0.4);
            border: 1px solid var(--glass-border);
            border-radius: 12px;
            padding: 14px;
        }

        .criteria-field .field-label {
            font-size: 12px;
            color: var(--text-muted);
            margin-bottom: 4px;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .criteria-field input {
            width: 100%;
            background: rgba(0, 0, 0, 0.3);
            border: 1px solid var(--glass-border);
            border-radius: 6px;
            padding: 8px 10px;
            color: var(--text-primary);
            font-family: 'JetBrains Mono', monospace;
            font-size: 14px;
            outline: none;
            transition: border-color 0.2s;
        }

        .criteria-field input:focus {
            border-color: var(--accent-primary);
        }

        .criteria-field input:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        .criteria-field .static-value {
            font-size: 16px;
            font-weight: 600;
            color: var(--text-primary);
            font-family: 'JetBrains Mono', monospace;
            padding: 4px 0;
        }

        .detail-modal {
            text-align: left;
        }

        .detail-section {
            margin-bottom: 16px;
            padding-bottom: 12px;
            border-bottom: 1px solid var(--glass-border);
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

        /* Token Card */
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
            padding: 6px 14px;
            background: rgba(0, 212, 255, 0.1);
            border: 1px solid rgba(0, 212, 255, 0.2);
            border-radius: 6px;
            color: var(--accent-primary);
            cursor: pointer;
            font-size: 11px;
            font-weight: 600;
            font-family: 'JetBrains Mono', monospace;
            transition: all 0.2s ease;
        }

        .btn-copy:hover {
            background: rgba(0, 212, 255, 0.2);
        }

        .token-status {
            margin-top: 12px;
            padding: 8px 12px;
            border-radius: 6px;
            font-size: 12px;
            text-align: center;
            font-weight: 500;
        }

        .status-monitoreando { background: rgba(123, 92, 255, 0.2); color: var(--accent-primary); }
        .status-nuevo { background: rgba(0, 217, 126, 0.2); color: var(--success); }

        .token-icon {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background: var(--accent-gradient);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
            font-weight: 700;
            color: #fff;
            flex-shrink: 0;
        }

        .token-info-modal {
            text-align: left;
        }

        .info-section {
            margin-bottom: 14px;
            padding-bottom: 10px;
            border-bottom: 1px solid var(--glass-border);
        }

        .info-section:last-child {
            border-bottom: none;
            margin-bottom: 0;
            padding-bottom: 0;
        }

        .info-section-title {
            font-weight: 600;
            font-size: 12px;
            color: var(--accent-primary);
            margin-bottom: 6px;
            letter-spacing: 0.5px;
            text-transform: uppercase;
        }

        .info-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 2px 0;
            font-size: 13px;
        }

        .info-label {
            color: var(--text-muted);
        }

        .info-value {
            color: var(--text-primary);
            font-family: 'JetBrains Mono', monospace;
            font-size: 12px;
        }

        .calendar-wrapper {
            margin-top: 24px;
            background: var(--glass-bg);
            backdrop-filter: blur(16px) saturate(1.2);
            border: 1px solid var(--glass-border);
            border-radius: 16px;
            padding: 20px;
            box-shadow: var(--glass-shadow);
        }

        .calendar-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 16px;
        }

        .calendar-header h3 {
            font-size: 18px;
            font-weight: 700;
            letter-spacing: 0.5px;
        }

        .calendar-nav {
            background: rgba(0, 212, 255, 0.1);
            border: 1px solid var(--glass-border);
            border-radius: 8px;
            padding: 6px 12px;
            color: var(--accent-primary);
            cursor: pointer;
            transition: all 0.2s ease;
            font-size: 14px;
            font-weight: 600;
        }

        .calendar-nav:hover {
            background: rgba(0, 212, 255, 0.2);
            border-color: var(--accent-primary);
        }

        .calendar-grid {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 4px;
        }

        .calendar-weekday {
            text-align: center;
            font-size: 11px;
            font-weight: 700;
            color: var(--text-muted);
            text-transform: uppercase;
            padding: 6px 0;
            letter-spacing: 0.5px;
        }

        .cal-cell {
            background: rgba(20, 26, 61, 0.3);
            border: 1px solid var(--glass-border);
            border-radius: 8px;
            min-height: 70px;
            padding: 6px;
            text-align: center;
            cursor: pointer;
            transition: all 0.2s ease;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: flex-start;
            gap: 2px;
            position: relative;
        }

        .cal-cell:hover {
            border-color: var(--accent-primary);
            transform: translateY(-2px);
            box-shadow: 0 0 12px rgba(0, 212, 255, 0.15);
        }

        .cal-cell.empty {
            cursor: default;
            opacity: 0.3;
        }

        .cal-cell.empty:hover {
            transform: none;
            border-color: var(--glass-border);
            box-shadow: none;
        }

        .cal-cell.positive {
            background: rgba(0, 255, 136, 0.08);
            border-color: rgba(0, 255, 136, 0.2);
        }

        .cal-cell.positive:hover {
            border-color: var(--success);
            box-shadow: 0 0 12px rgba(0, 255, 136, 0.2);
        }

        .cal-cell.negative {
            background: rgba(255, 0, 84, 0.08);
            border-color: rgba(255, 0, 84, 0.2);
        }

        .cal-cell.negative:hover {
            border-color: var(--error);
            box-shadow: 0 0 12px rgba(255, 0, 84, 0.2);
        }

        .cal-cell.today {
            border-color: var(--accent-primary);
            border-width: 2px;
        }

        .cal-day-num {
            font-size: 13px;
            font-weight: 700;
            color: var(--text-primary);
            width: 100%;
            text-align: center;
        }

        .cal-cell.today .cal-day-num {
            color: var(--accent-primary);
        }

        .cal-cell .cal-amount {
            font-size: 11px;
            font-weight: 600;
            font-family: 'JetBrains Mono', monospace;
            line-height: 1.2;
        }

        .cal-cell .cal-pct {
            font-size: 10px;
            font-family: 'JetBrains Mono', monospace;
            opacity: 0.8;
            line-height: 1.2;
        }

        .cal-cell .cal-no-data {
            font-size: 10px;
            color: var(--text-muted);
            margin-top: 4px;
        }

        .cal-footer {
            margin-top: 12px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px 14px;
            background: rgba(20, 26, 61, 0.4);
            border-radius: 10px;
            border: 1px solid var(--glass-border);
            font-size: 13px;
        }

        .cal-footer .cal-month-total {
            font-weight: 700;
            font-family: 'JetBrains Mono', monospace;
        }

        .stat-card {
            background: var(--glass-bg);
            backdrop-filter: blur(16px) saturate(1.2);
            border: 1px solid var(--glass-border);
            border-radius: 16px;
            padding: 24px;
            box-shadow: var(--glass-shadow);
            transition: all 0.35s cubic-bezier(0.25, 0.46, 0.45, 0.94);
            position: relative;
            overflow: hidden;
        }
        .stat-card::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: conic-gradient(from 0deg, transparent, rgba(0,212,255,0.03), transparent, rgba(255,0,110,0.03), transparent);
            animation: spin 8s linear infinite;
            z-index: 0;
        }
        .stat-card > * {
            position: relative;
            z-index: 1;
        }
        .stat-card:hover {
            border-color: rgba(0, 212, 255, 0.35);
            box-shadow: 0 0 30px rgba(0, 212, 255, 0.2), 0 8px 32px rgba(0, 0, 0, 0.4);
            transform: translateY(-3px);
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
            background: var(--glass-bg);
            backdrop-filter: blur(16px) saturate(1.2);
            border: 1px solid var(--glass-border);
            border-radius: 16px;
            overflow: hidden;
            box-shadow: var(--glass-shadow);
            transition: all 0.35s cubic-bezier(0.25, 0.46, 0.45, 0.94);
        }
        .admin-users:hover {
            border-color: rgba(0, 212, 255, 0.35);
            box-shadow: 0 0 30px rgba(0, 212, 255, 0.18), 0 8px 32px rgba(0, 0, 0, 0.4);
        }

        .admin-users-header {
            padding: 20px;
            border-bottom: 1px solid var(--glass-border);
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
            border-bottom: 1px solid var(--glass-border);
        }

        .admin-users-table th {
            font-weight: 600;
            font-size: 13px;
            color: var(--text-muted);
            text-transform: uppercase;
            background: rgba(20, 26, 61, 0.4);
        }

        .admin-users-table tr:hover {
            background: var(--bg-surface);
        }

        /* Banned Table */
        #bannedSection table {
            width: 100%;
        }

        #bannedSection th,
        #bannedSection td {
            padding: 12px 14px;
            text-align: left;
            border-bottom: 1px solid var(--glass-border);
        }

        #bannedSection th {
            background: rgba(20, 26, 61, 0.6);
            font-weight: 600;
            font-size: 12px;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        #bannedSection tbody tr {
            transition: background 0.2s ease;
        }

        #bannedSection tbody tr:hover {
            background: rgba(0, 212, 255, 0.06);
        }

        #bannedSection .address {
            font-family: 'JetBrains Mono', monospace;
            font-size: 11px;
            color: var(--text-muted);
            cursor: help;
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
            background: linear-gradient(135deg, #00d4ff, #0099ff);
            color: #070712;
        }

        .btn-edit:hover {
            box-shadow: 0 0 18px rgba(0, 212, 255, 0.45);
            transform: scale(1.03);
        }

        .btn-edit:active {
            transform: scale(0.97);
        }

        .btn-delete {
            background: linear-gradient(135deg, #ff0054, #ff006e);
            color: white;
        }

        .btn-delete:hover {
            box-shadow: 0 0 18px rgba(255, 0, 84, 0.45);
            transform: scale(1.03);
        }

        .btn-delete:active {
            transform: scale(0.97);
        }

        .btn-add {
            background: linear-gradient(135deg, #00ff88, #00d4ff);
            color: #070712;
            padding: 10px 22px;
            border: none;
            border-radius: 10px;
            cursor: pointer;
            font-weight: 700;
            transition: all 0.25s ease;
        }

        .btn-add:hover {
            box-shadow: 0 0 20px rgba(0, 255, 136, 0.4);
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
            background: var(--glass-bg);
            backdrop-filter: blur(16px) saturate(1.2);
            border: 1px solid var(--glass-border);
            border-radius: 16px;
            padding: 16px;
            box-shadow: var(--glass-shadow);
            transition: all 0.35s cubic-bezier(0.25, 0.46, 0.45, 0.94);
        }

        .criterio-card:hover {
            transform: translateY(-3px);
            border-color: rgba(0, 212, 255, 0.35);
            box-shadow: 0 0 30px rgba(0, 212, 255, 0.18), 0 8px 32px rgba(0, 0, 0, 0.4);
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
            background: rgba(14, 14, 30, 0.95);
            backdrop-filter: blur(20px) saturate(1.3);
            border: 1px solid var(--glass-border);
            border-radius: 16px;
            padding: 32px;
            width: 100%;
            max-width: 400px;
            box-shadow: 0 8px 40px rgba(0, 0, 0, 0.5), 0 0 60px rgba(0, 212, 255, 0.08);
            animation: modalFadeIn 0.25s ease forwards;
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

        /* Loading - Skeleton */
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

        /* Toast notifications */
        .toast-container {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 10000;
            display: flex;
            flex-direction: column;
            gap: 10px;
            pointer-events: none;
        }
        .toast {
            padding: 14px 20px;
            border-radius: 12px;
            backdrop-filter: blur(16px) saturate(1.5);
            border: 1px solid;
            font-size: 14px;
            font-weight: 600;
            animation: toastIn 0.4s ease forwards;
            pointer-events: auto;
            min-width: 250px;
            box-shadow: 0 4px 24px rgba(0,0,0,0.3);
        }
        .toast.toast-exit { animation: fadeOut 0.3s ease forwards; }
        .toast-tp { background: rgba(0,255,136,0.15); border-color: rgba(0,255,136,0.3); color: var(--success); }
        .toast-sl { background: rgba(255,0,84,0.15); border-color: rgba(255,0,84,0.3); color: var(--error); }
        .toast-info { background: rgba(0,212,255,0.15); border-color: rgba(0,212,255,0.3); color: var(--accent-primary); }
        .toast .toast-sub { font-size: 12px; opacity: 0.7; font-weight: 400; margin-top: 2px; }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        @keyframes pulseGlow {
            0%, 100% { box-shadow: 0 0 8px rgba(0,212,255,0.2), 0 0 16px rgba(0,212,255,0.1); }
            50% { box-shadow: 0 0 16px rgba(0,212,255,0.4), 0 0 32px rgba(0,212,255,0.2); }
        }
        @keyframes shimmer {
            0% { background-position: -200% center; }
            100% { background-position: 200% center; }
        }
        @keyframes gridMove {
            0% { background-position: 0 0, 0 0, 0 0, 0 0; }
            100% { background-position: 0 0, 0 0, 24px 24px, 24px 24px; }
        }
        @keyframes slideIn {
            from { opacity: 0; transform: translateY(30px) scale(0.95); }
            to { opacity: 1; transform: translateY(0) scale(1); }
        }
        @keyframes fadeOut {
            from { opacity: 1; transform: translateY(0); }
            to { opacity: 0; transform: translateY(-20px); }
        }
        @keyframes toastIn {
            from { opacity: 0; transform: translateX(100%); }
            to { opacity: 1; transform: translateX(0); }
        }
        @keyframes modalFadeIn {
            from { opacity: 0; transform: translateY(20px) scale(0.96); }
            to { opacity: 1; transform: translateY(0) scale(1); }
        }

        /* Utility Classes */
        .input-mono {
            background: rgba(0,0,0,0.3);
            border: 1px solid var(--glass-border);
            border-radius: 6px;
            padding: 10px;
            color: var(--text-primary);
            font-family: 'JetBrains Mono', monospace;
            font-size: 13px;
        }
        .input-danger {
            background: rgba(0,0,0,0.3);
            border: 1px solid rgba(239, 68, 68, 0.3);
            border-radius: 6px;
            padding: 10px;
            color: var(--text-primary);
            font-family: 'JetBrains Mono', monospace;
            font-size: 13px;
        }
        .input-mono:focus,
        .input-danger:focus {
            outline: none;
            border-color: var(--accent-primary);
        }
        .form-select {
            width: 100%;
            padding: 14px 16px;
            background: rgba(20, 26, 61, 0.6);
            border: 1px solid var(--glass-border);
            border-radius: 10px;
            color: var(--text-primary);
            font-size: 16px;
            backdrop-filter: blur(12px);
        }
        .form-select:focus {
            outline: none;
            border-color: var(--accent-primary);
        }
        .flex-row {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .flex-row-wrap {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }
        .status-text {
            font-size: 13px;
            color: var(--text-muted);
            align-self: center;
        }
        .icon-sm { width: 18px; height: 18px; stroke-width: 1.75; }
        .icon-md { width: 24px; height: 24px; stroke-width: 1.75; }
        .icon-lg { width: 40px; height: 40px; stroke-width: 1.75; }
        .error { color: var(--error); font-size: 13px; }
        .address { font-family: 'JetBrains Mono', monospace; font-size: 12px; color: var(--text-muted); }
        .swal-token-info { background: var(--bg-secondary) !important; border: 1px solid var(--glass-border) !important; }
        .swal-detail { background: var(--bg-secondary) !important; border: 1px solid var(--glass-border) !important; }

        /* Responsive */
        @media (max-width: 768px) {
            .header {
                flex-direction: column;
                gap: 12px;
                padding: 12px 16px;
            }

            .header-info {
                width: 100%;
                justify-content: space-between;
            }

            .nav-tabs {
                padding: 12px 16px;
                gap: 4px;
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
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

            .historial-table,
            .admin-users-table {
                display: block;
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
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

            .modal-content {
                margin: 16px;
                max-width: none;
            }
        }

        @media (max-width: 640px) {
            .admin-stats {
                grid-template-columns: 1fr;
                gap: 12px;
            }

            .criterios-grid {
                grid-template-columns: 1fr 1fr;
            }

            .stats-grid {
                grid-template-columns: 1fr 1fr;
                gap: 12px;
            }

            .stat-card { padding: 16px; }
            .stat-card .value { font-size: 26px; }

            .historial-table th,
            .historial-table td {
                padding: 10px 12px;
                font-size: 13px;
            }

            .modal-content { padding: 24px; }
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

            .tokens-grid {
                gap: 12px;
            }

            .token-card {
                padding: 14px;
            }

            .criterios-grid {
                grid-template-columns: 1fr;
            }

            .stats-grid {
                grid-template-columns: 1fr;
                gap: 10px;
            }

            .main-content {
                padding: 12px;
            }

            .header { padding: 10px 12px; }
            .nav-tabs { padding: 10px 12px; }
        }
    </style>
</head>
<body>
    <!-- Login -->
    <div class="login-container" id="loginSection">
        <div class="login-box">
            <div class="login-logo">
                <i data-lucide="bar-chart-3" class="icon-lg" style="stroke:var(--accent-primary);"></i>
                <h1>ALTICHECKER</h1>
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
                © 2026 AltiChecker - Token Monitoring System
            </div>
        </div>
    </div>

    <!-- Dashboard -->
    <div class="dashboard" id="dashboardSection">
        <!-- Header -->
        <header class="header">
            <div class="header-logo">
                <i data-lucide="bar-chart-3" style="width:24px;height:24px;stroke:var(--accent-primary);stroke-width:1.75"></i>
                <h1>ALTICHECKER</h1>
            </div>
            <div class="header-info">
                <div class="server-status" id="serverStatus">
                    <span class="dot"></span>
                    <span class="text">Server: Inactive</span>
                </div>
                <button class="btn-refresh" id="refreshBtn" onclick="manualRefresh()" title="Refresh data">
                    <i data-lucide="refresh-cw" style="width:16px;height:16px;stroke-width:2"></i>
                </button>
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
                <div class="flex-row" style="margin-bottom:12px;">
                    <i data-lucide="ban" class="icon-md" style="stroke:var(--error);"></i>
                    <span class="section-title">Banned Tokens</span>
                </div>
                <div class="admin-section flex-row-wrap" style="margin-bottom:16px;">
                    <input type="text" id="banTokenAddress" placeholder="Token address..." class="input-danger" style="flex:2;min-width:200px;">
                    <input type="text" id="banTokenName" placeholder="Name (optional)..." class="input-danger" style="flex:1;min-width:120px;">
                    <button onclick="banearTokenManual()" class="btn-delete" style="padding:10px 20px;">Ban Token</button>
                    <button onclick="exportBannedTokens()" class="btn-edit" style="padding:10px 20px;">Export JSON</button>
                    <button onclick="importBannedTokens()" class="btn-add" style="padding:10px 20px;">Import JSON</button>
                    <div id="banTokenStatus" class="status-text"></div>
                </div>
                <div class="table-wrapper">
                    <table>
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Token Address</th>
                                <th>Pair Address</th>
                                <th>Chain</th>
                                <th>Reason</th>
                                <th>Date</th>
                                <th>Actions</th>
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

                    <div class="api-key-card" id="apiKeyCard">
                        <div class="flex-row" style="margin-bottom:8px;">
                            <i data-lucide="key" class="icon-sm" style="stroke:var(--accent-primary);"></i>
                            <span style="font-weight:600;font-size:14px;">API Key</span>
                        </div>
                        <div class="key-display" id="apiKeyDisplay">Loading...</div>
                        <div class="key-actions">
                            <button class="btn-edit" onclick="revealApiKey()" id="btnRevealKey">Reveal</button>
                            <button class="btn-copy" onclick="copyApiKey()" id="btnCopyKey">Copy</button>
                            <button class="btn-edit" onclick="regenerateApiKey()" id="btnRegenKey">Rehacer</button>
                            <span id="regenCountdown" style="font-size:12px;display:flex;align-items:center;"></span>
                        </div>
                    </div>

                    <div class="admin-section" id="criteriaSection" style="margin-bottom:24px;">
                        <h3 class="section-title">
                            <i data-lucide="sliders" class="icon-sm" style="stroke:var(--text-muted);"></i> Trading Criteria
                        </h3>
                        <div id="criteriaContent">
                            <div class="criteria-form" id="criteriaForm"></div>
                            <button class="btn-add" onclick="saveCriteria()" id="btnSaveCriteria" style="display:none;">Save Criteria</button>
                        </div>
                    </div>

                    <div class="admin-section" id="manualCoinSection" style="margin-bottom:24px;">
                        <h3 class="section-title">
                            <i data-lucide="plus-circle" class="icon-sm" style="stroke:var(--accent-primary);"></i> Add Manual Coin
                        </h3>
                        <div class="flex-row">
                            <input type="text" id="manualCoinAddress" placeholder="Solana token address..." class="input-mono" style="flex:1;">
                            <button class="btn-add" onclick="addManualCoin()" id="btnAddManualCoin">Add Coin</button>
                        </div>
                        <div id="manualCoinStatus" class="status-text" style="margin-top:8px;"></div>
                    </div>

                    <div class="admin-users">
                    <div class="admin-users-header">
                        <div class="flex-row">
                            <i data-lucide="users" class="icon-sm" style="stroke:var(--accent-primary);"></i>
                            <span class="section-title">User Management</span>
                        </div>
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
                    <select id="modalNivel" class="form-select">
                        <option value="free">Free</option>
                        <option value="vip">Pro</option>
                        <option value="admin">Admin</option>
                    </select>
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" id="closeModalBtn">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save</button>
                </div>
            </form>
        </div>
    </div>

    <div class="toast-container" id="toastContainer"></div>

    <footer class="app-footer">
        v1.0.01 &copy; 2026 AltiMaster
    </footer>

    <script>
        console.log('AltiChecker: Script loaded');
        
        let currentUser = null;
        let currentToken = null;
        let dataRefreshInterval = null;

        // Check session on load
        function checkSession() {
            const savedUser = localStorage.getItem('altiChecker_user');
            const savedToken = localStorage.getItem('altiChecker_token');

            if (savedUser && savedToken) {
                currentUser = JSON.parse(savedUser);
                currentToken = savedToken;
                showDashboard();
            }
        }

        function clearSession() {
            localStorage.removeItem('altiChecker_user');
            localStorage.removeItem('altiChecker_token');
            currentUser = null;
            currentToken = null;
            if (dataRefreshInterval) clearInterval(dataRefreshInterval);
            document.getElementById('loginSection').style.display = 'flex';
            document.getElementById('dashboardSection').classList.remove('active');
        }

        // Check session on page load
        console.log('AltiChecker: Running checkSession...');
        checkSession();
        console.log('AltiChecker: checkSession done, currentUser:', currentUser);

        // Login
        console.log('AltiChecker: Setting up login form listener...');
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

                    localStorage.setItem('altiChecker_user', JSON.stringify(data.user));
                    localStorage.setItem('altiChecker_token', data.user.token);

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
            console.log('AltiChecker: showDashboard called');
            document.getElementById('loginSection').style.display = 'none';
            document.getElementById('dashboardSection').classList.add('active');

            console.log('AltiChecker: Setting user info...');
            document.getElementById('userName').textContent = currentUser.username;
            const levelBadge = document.getElementById('userLevel');
            const nivelNames = { free: 'Free', vip: 'Pro', admin: 'Admin' };
            const nivelLabel = nivelNames[currentUser.nivel] || 'Free';
            levelBadge.textContent = nivelLabel;
            levelBadge.className = 'level level-' + (currentUser.nivel === 'admin' ? 'admin' : (currentUser.nivel === 'vip' ? 'pro' : 'free'));

            console.log('AltiChecker: Resetting tabs...');
            // Reset tabs to overview
            document.querySelectorAll('.nav-tab').forEach(t => t.classList.remove('active'));
            document.querySelectorAll('.section').forEach(s => s.classList.remove('active'));
            document.querySelector('.nav-tab[data-tab="overview"]').classList.add('active');
            document.getElementById('overviewSection').classList.add('active');

            console.log('AltiChecker: Updating server status...');
            updateServerStatus();

            const isAdmin = currentUser.nivel === 'admin';
            document.getElementById('tabConfig').style.display = (currentUser.nivel !== 'free') ? 'inline-block' : 'none';
            document.getElementById('tabHistorial').style.display = 'inline-block';
            document.getElementById('tabBanned').style.display = isAdmin ? 'inline-block' : 'none';

            console.log('AltiChecker: Calling loadData()...');
            loadData();
            console.log('AltiChecker: showDashboard complete');
        }

        // Manual Refresh
        function manualRefresh() {
            const btn = document.getElementById('refreshBtn');
            btn.classList.add('spinning');
            loadData();
            setTimeout(() => btn.classList.remove('spinning'), 2000);
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
        console.log('AltiChecker: Setting up tab click handlers...');
        document.querySelectorAll('.nav-tab').forEach(tab => {
            tab.addEventListener('click', () => {
                console.log('AltiChecker: Tab clicked:', tab.dataset.tab);
                document.querySelectorAll('.nav-tab').forEach(t => t.classList.remove('active'));
                document.querySelectorAll('.section').forEach(s => s.classList.remove('active'));

                tab.classList.add('active');
                const sectionId = tab.dataset.tab + 'Section';
                console.log('AltiChecker: Showing section:', sectionId);
                document.getElementById(sectionId).classList.add('active');
            });
        });

        // Load Data
        function loadData() {
            console.log('AltiChecker: loadData started, token:', currentToken ? 'present' : 'MISSING', 'user level:', currentUser?.nivel);
            const controller = new AbortController();
            const timeout = setTimeout(() => controller.abort(), 10000);
            
            fetch('api/tokens.php', {
                headers: { 'Authorization': currentToken },
                signal: controller.signal
            })
            .then(r => {
                clearTimeout(timeout);
                console.log('AltiChecker: API response status:', r.status);
                return r.json();
            })
            .then(data => {
                console.log('AltiChecker: API data received, success:', data.success, 'nivel:', currentUser.nivel);
                if (!data.success) {
                    document.getElementById('overviewContent').innerHTML = '<div class="error">Error: ' + (data.error || 'Unknown error') + '</div>';
                    return;
                }

                renderDashboard(data);
            })
            .catch(err => {
                console.error('Error cargando datos:', err);
                const errorMsg = err.name === 'AbortError' ? 'Timeout' : (err.message || 'Connection error');
                document.getElementById('overviewContent').innerHTML = '<div class="error">' + errorMsg + '</div>';
            });

            loadConfigData();
        }



        // Render Dashboard
        function renderDashboard(data) {
            console.log('AltiChecker: renderDashboard STARTED');
            try {
                document.getElementById('tabTokens').style.display = 'inline-block';

                const hoyServer = new Date();
                const hoyServerDate = hoyServer.getFullYear() + '-' + String(hoyServer.getMonth() + 1).padStart(2, '0') + '-' + String(hoyServer.getDate()).padStart(2, '0');

                let gananciasHoy = 0;
                let tradesHoy = 0;
                if (data.historial && data.historial.length > 0) {
                    data.historial.forEach(h => {
                        if (h.fecha_entrada) {
                            const fechaTrade = new Date(h.fecha_entrada);
                            const ft = fechaTrade.getFullYear() + '-' + String(fechaTrade.getMonth() + 1).padStart(2, '0') + '-' + String(fechaTrade.getDate()).padStart(2, '0');
                            if (ft === hoyServerDate) {
                                tradesHoy++;
                                gananciasHoy += parseFloat(h.profit_porcentaje) || 0;
                            }
                        }
                    });
                }
                const totalHoy = gananciasHoy;
                const gainClass = totalHoy >= 0 ? 'profit-positive' : 'profit-negative';
                const gainLabel = totalHoy >= 0 ? '+' : '';

                console.log('AltiChecker: Rendering stats...');
                // Overview
                const wallet = data.wallet || { saldo: 1000.00, profit_30d: 0 };
                const walletClass = wallet.profit_30d >= 0 ? 'profit-positive' : 'profit-negative';
                const walletLabel = wallet.profit_30d >= 0 ? '+' : '';
                let statsHtml = `
                    <div class="stats-grid">
                        <div class="stat-card">
                            <div class="value">${data.tokens?.length || 0}</div>
                            <div class="label">Active Tokens</div>
                        </div>
                        <div class="stat-card">
                            <div class="value profit-positive" style="color:var(--success);">$${formatNumber(wallet.saldo)}</div>
                            <div class="label">Saldo Actual</div>
                        </div>
                        <div class="stat-card">
                            <div class="value ${walletClass}">${walletLabel}$${wallet.profit_30d.toFixed(2)}</div>
                            <div class="label">Profit 30 días</div>
                        </div>
                        <div class="stat-card">
                            <div class="value">${tradesHoy || 0}</div>
                            <div class="label">Trades Hoy</div>
                        </div>
                    </div>
                `;
                document.getElementById('overviewContent').innerHTML = statsHtml;
                console.log('AltiChecker: Stats rendered, tokens:', data.tokens?.length);

                // Calendar: Daily Earnings
                const hoyDate = hoyServerDate;
                currentCalendarMonth = currentCalendarMonth || hoyDate.substring(0, 7);
                loadCalendar(currentCalendarMonth);

                // Tokens Grid
                document.getElementById('tokensCount').textContent = data.tokens?.length || 0;
                const grid = document.getElementById('tokensGrid');
                console.log('AltiChecker: Rendering token grid, element exists:', !!grid);
                const coinsTagsMap = data.coins_tags || {};

                if (data.tokens && data.tokens.length > 0) {
                    grid.innerHTML = data.tokens.map((t, idx) => renderTokenCard(t, idx, coinsTagsMap)).join('');
                } else {
                    grid.innerHTML = '<p style="color:var(--text-muted);grid-column:1/-1;text-align:center;padding:40px;">No hay tokens activos</p>';
                }
                console.log('AltiChecker: Token grid rendered');

                // Historial
                console.log('AltiChecker: Rendering historial...');
                const historialTable = document.getElementById('historialTable');
                console.log('AltiChecker: historialTable element:', !!historialTable);
                if (data.historial && data.historial.length > 0) {
                    const groups = {};
                    data.historial.forEach(h => {
                        if (!h.fecha_entrada) return;
                        const d = new Date(h.fecha_entrada);
                        const key = d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
                        if (!groups[key]) groups[key] = [];
                        groups[key].push(h);
                    });

                    const sortedKeys = Object.keys(groups).sort().reverse();
                    let html = '';
                    sortedKeys.forEach(key => {
                        const trades = groups[key];
                        const totalProfit = trades.reduce((s, t) => s + parseFloat(t.profit_porcentaje || 0), 0);
                        const profitClass = totalProfit >= 0 ? 'profit-positive' : 'profit-negative';
                        const profitLabel = totalProfit >= 0 ? '+' : '';
                        const isToday = key === hoyDate;
                        const dateParts = key.split('-');
                        const fmt = dateParts[1] + '/' + dateParts[2] + '/' + dateParts[0];

                        const groupClass = 'hist-group-' + key.replace(/-/g, '');
                        html += `<tr class="hist-date-header" data-group="${groupClass}" onclick="toggleHistGroup(this)">
                            <td colspan="9">
                                <span class="hist-toggle">${isToday ? '▼' : '▶'}</span>
                                <span style="margin-left:6px;">${fmt}</span>
                                <span class="${profitClass}" style="margin-left:10px;font-weight:700;font-size:13px;">${profitLabel}${totalProfit.toFixed(2)}%</span>
                                <span style="color:var(--text-muted);font-size:12px;margin-left:8px;">(${trades.length} trade${trades.length > 1 ? 's' : ''})</span>
                            </td>
                        </tr>`;

                        trades.forEach(h => {
                            const style = isToday ? '' : ' style="display:none;"';
                            const hNameNorm = (h.nombre || '').toUpperCase().trim();
                            const hTagData = coinsTagsMap ? coinsTagsMap[hNameNorm] : null;
                            let hTagHtml = '';
                            if (hTagData) {
                                const hParts = [];
                                if (parseInt(hTagData.strong_count) > 0) hParts.push(`${hTagData.strong_count}xS`);
                                if (parseInt(hTagData.okay_count) > 0) hParts.push(`${hTagData.okay_count}xOk`);
                                if (parseInt(hTagData.checking_count) > 0) hParts.push(`${hTagData.checking_count}xCh`);
                                if (parseInt(hTagData.inestable_count) > 0) hParts.push(`${hTagData.inestable_count}xI`);
                                if (hParts.length > 0) hTagHtml = `<div style="font-size:0.65rem;color:var(--text-muted);margin-top:1px;">${hParts.join(', ')}</div>`;
                            }
                            html += `<tr class="${groupClass}"${style}>
                                <td data-label="Token">
                                    <div style="font-weight:600;">${h.nombre || h.simbolo || '?'}</div>
                                    ${h.tag ? `<div style="font-size:0.7rem;color:var(--text-muted);margin-top:2px;">${h.tag}</div>` : ''}
                                    ${hTagHtml}
                                </td>
                                <td data-label="Chain">${getChainBadge(h.chain_id)}</td>
                                <td data-label="Entry">$${formatPrice(h.precio_entrada)}</td>
                                <td data-label="Exit">$${formatPrice(h.precio_salida)}</td>
                                <td data-label="Profit" class="${h.profit_porcentaje >= 0 ? 'profit-positive' : 'profit-negative'}">${h.profit_dolares ? (h.profit_dolares >= 0 ? '+' : '') + '$' + parseFloat(h.profit_dolares).toFixed(2) + ' ' : ''}(${h.profit_porcentaje}%)</td>
                                <td data-label="Duration">${h.duracion_minutos} min</td>
                                <td data-label="Reason">${getRazonSalida(h.razon_salida)}</td>
                                <td data-label="Address" style="font-size:0.75rem;">
                                    <span title="${h.token_address}">${h.token_address ? h.token_address.substring(0, 6) + '...' + h.token_address.substring(h.token_address.length - 4) : '-'}</span>
                                    ${h.token_address ? `<button class="btn-copy" onclick="copyToClipboard('${h.token_address}', this)">Copiar</button>` : ''}
                                </td>
                                ${currentUser.nivel === 'admin' ? `<td data-label="Actions"><button onclick="showTokenDetail(${h.id})" class="btn-edit" style="margin-right:4px;">Details</button><button onclick="banHistorial(${h.id})" class="btn-delete">Ban</button></td>` : ''}
                            </tr>`;
                        });
                    });
                    historialTable.innerHTML = html;
                } else {
                    historialTable.innerHTML = '<tr><td colspan="9" style="text-align:center;color:var(--text-muted);">No history</td></tr>';
                }
                console.log('AltiChecker: Historial rendered');

                window.toggleHistGroup = function(headerRow) {
                    const className = headerRow.getAttribute('data-group');
                    if (!className) return;
                    const rows = document.querySelectorAll('.' + className);
                    const isHidden = rows.length > 0 && rows[0].style.display === 'none';
                    rows.forEach(r => r.style.display = isHidden ? '' : 'none');
                    const toggle = headerRow.querySelector('.hist-toggle');
                    if (toggle) toggle.textContent = isHidden ? '▼' : '▶';
                };

                console.log('AltiChecker: renderDashboard COMPLETE');
            } catch (e) {
                console.error('AltiChecker: renderDashboard ERROR:', e);
            }
        }

        // Calendar variables
        let currentCalendarMonth = null;

        // Load calendar data
        function loadCalendar(month) {
            fetch('api/tokens.php?action=earnings_by_day&month=' + encodeURIComponent(month), {
                headers: { 'Authorization': currentToken }
            })
            .then(r => r.json())
            .then(data => {
                if (!data.success) return;
                renderCalendar(data.earnings || [], data.month, data.today);
            })
            .catch(() => {});
        }

        // Render Calendar
        function renderCalendar(earnings, month, today) {
            const [year, m] = month.split('-').map(Number);
            const firstDay = new Date(year, m - 1, 1);
            const lastDay = new Date(year, m, 0);
            const daysInMonth = lastDay.getDate();
            let startDay = firstDay.getDay() - 1;
            if (startDay < 0) startDay = 6;

            const monthNames = ['January','February','March','April','May','June','July','August','September','October','November','December'];
            const monthLabel = monthNames[m - 1] + ' ' + year;

            const earningsMap = {};
            let monthTotalPct = 0, monthTotalDollars = 0, monthTrades = 0;
            earnings.forEach(e => {
                earningsMap[e.entry_date] = e;
                monthTotalPct += parseFloat(e.total_earnings_pct || 0);
                monthTotalDollars += parseFloat(e.total_profit_dollars || 0);
                monthTrades += parseInt(e.total_trades || 0);
            });

            let html = '<div class="calendar-wrapper" id="calendarWidget">';
            html += '<div class="calendar-header">';
            html += '<button class="calendar-nav" onclick="prevMonth()">◀</button>';
            html += '<h3>' + monthLabel + '</h3>';
            html += '<button class="calendar-nav" onclick="nextMonth()">▶</button>';
            html += '</div><div class="calendar-grid">';
            ['Mon','Tue','Wed','Thu','Fri','Sat','Sun'].forEach(d => {
                html += '<div class="calendar-weekday">' + d + '</div>';
            });
            for (let i = 0; i < startDay; i++) {
                html += '<div class="cal-cell empty"></div>';
            }
            for (let d = 1; d <= daysInMonth; d++) {
                const dateStr = year + '-' + String(m).padStart(2,'0') + '-' + String(d).padStart(2,'0');
                const e = earningsMap[dateStr];
                const isToday = dateStr === today;
                let cellClass = 'cal-cell';
                if (isToday) cellClass += ' today';
                if (e) {
                    const pct = parseFloat(e.total_earnings_pct);
                    cellClass += pct >= 0 ? ' positive' : ' negative';
                    const pctLabel = pct >= 0 ? '+' : '';
                    const dolLabel = e.total_profit_dollars >= 0 ? '+' : '';
                    html += '<div class="' + cellClass + '" onclick="showDayDetails(\'' + dateStr + '\')">';
                    html += '<div class="cal-day-num">' + d + '</div>';
                    html += '<div class="cal-amount">' + dolLabel + '$' + Math.abs(e.total_profit_dollars).toFixed(2) + '</div>';
                    html += '<div class="cal-pct">(' + pctLabel + pct.toFixed(2) + '%)</div></div>';
                } else {
                    html += '<div class="' + cellClass + '">';
                    html += '<div class="cal-day-num">' + d + '</div>';
                    html += '<div class="cal-no-data">—</div></div>';
                }
            }
            html += '</div>';
            const mClass = monthTotalPct >= 0 ? 'profit-positive' : 'profit-negative';
            const mLabel = monthTotalPct >= 0 ? '+' : '';
            html += '<div class="cal-footer">';
            html += '<span>' + monthNames[m - 1] + ' total: <span class="cal-month-total ' + mClass + '">' + mLabel + '$' + monthTotalDollars.toFixed(2) + ' (' + mLabel + monthTotalPct.toFixed(2) + '%)</span></span>';
            html += '<span>' + monthTrades + ' trade' + (monthTrades !== 1 ? 's' : '') + '</span>';
            html += '</div></div>';

            const existing = document.getElementById('calendarWidget');
            if (existing) {
                existing.outerHTML = html;
            } else {
                document.getElementById('overviewContent').insertAdjacentHTML('beforeend', html);
            }
        }

        function prevMonth() {
            const [year, m] = currentCalendarMonth.split('-').map(Number);
            const d = new Date(year, m - 2, 1);
            currentCalendarMonth = d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0');
            loadCalendar(currentCalendarMonth);
        }

        function nextMonth() {
            const [year, m] = currentCalendarMonth.split('-').map(Number);
            const d = new Date(year, m, 1);
            currentCalendarMonth = d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0');
            loadCalendar(currentCalendarMonth);
        }

        // Show day details popup
        function showDayDetails(date) {
            fetch('api/tokens.php?action=earnings_detail&date=' + encodeURIComponent(date), {
                headers: { 'Authorization': currentToken }
            })
            .then(r => r.json())
            .then(data => {
                if (!data.success || !data.trades || data.trades.length === 0) return;
                const d = new Date(date + 'T00:00:00');
                const fmt = (d.getMonth()+1) + '/' + d.getDate() + '/' + d.getFullYear();
                const totalClass = data.total_profit_dollars >= 0 ? 'profit-positive' : 'profit-negative';
                const totalLabel = data.total_profit_dollars >= 0 ? '+' : '';
                const tradesHtml = data.trades.map(t => {
                    const pct = parseFloat(t.profit_porcentaje);
                    const dol = parseFloat(t.profit_dolares || 0);
                    const cls = pct >= 0 ? 'profit-positive' : 'profit-negative';
                    const lbl = pct >= 0 ? '+' : '';
                    return `<div class="detail-row"><span class="detail-label">${t.nombre || t.simbolo || '?'} <span class="chain-badge ${getChainClass(t.chain_id)}" style="font-size:9px;">${t.chain_id}</span></span><span class="detail-value ${cls}">${lbl}$${Math.abs(dol).toFixed(2)} (${lbl}${pct.toFixed(2)}%)</span></div>`;
                }).join('');
                Swal.fire({
                    title: '📅 ' + fmt,
                    width: 480,
                    padding: '24px',
                    background: 'var(--bg-primary)',
                    showCloseButton: true,
                    showConfirmButton: false,
                    customClass: { popup: 'swal-detail' },
                    html: `<div class="detail-modal"><div class="detail-section" style="max-height:300px;overflow-y:auto;">${tradesHtml}</div><div class="detail-section" style="border-bottom:none;"><div class="detail-row"><span class="detail-label" style="font-weight:700;">Total</span><span class="detail-value ${totalClass}" style="font-weight:700;">${totalLabel}$${data.total_profit_dollars.toFixed(2)} (${totalLabel}${data.total_profit_pct.toFixed(2)}%)</span></div><div class="detail-row" style="margin-top:4px;"><span class="detail-label">Trades</span><span class="detail-value">${data.trades.length}</span></div></div></div>`
                });
            })
            .catch(() => {});
        }

        function showTokenInfo(chainId, tokenAddress, tokenId) {
            fetch('api/tokens.php?action=token_info&chain_id=' + encodeURIComponent(chainId) + '&token_address=' + encodeURIComponent(tokenAddress), {
                headers: { 'Authorization': currentToken }
            })
            .then(r => r.json())
            .then(data => {
                if (!data.success) {
                    Swal.fire({ icon: 'error', title: 'Error', text: data.error || 'Unknown error' });
                    return;
                }

                const p = data.pair;
                const profile = data.profile;
                const iconUrl = profile?.icon || '';
                const dexUrl = profile?.url || 'https://dexscreener.com/' + chainId + '/' + tokenAddress;
                const description = profile?.description || '';
                const links = data.links || [];

                const twitter = links.find(l => l.type === 'twitter');
                const website = links.find(l => l.label === 'Website' || l.type === 'website');
                const telegram = links.find(l => l.type === 'telegram');

                const price = p ? parseFloat(p.priceUsd || 0) : 0;
                const mc = p ? (p.marketCap || 0) : 0;
                const liq = p ? (p.liquidity?.usd || 0) : 0;
                const change1h = p ? (p.priceChange?.h1 || 0) : 0;
                const change6h = p ? (p.priceChange?.h6 || 0) : 0;
                const change24h = p ? (p.priceChange?.h24 || 0) : 0;
                const pairCreated = p?.pairCreatedAt || null;

                Swal.fire({
                    title: (profile?.description ? profile.description.substring(0, 60) + (profile.description.length > 60 ? '...' : '') : ''),
                    html: `
                        <div class="token-info-modal">
                            <div style="text-align:center;margin-bottom:16px;">
                                ${iconUrl ? `<img src="${iconUrl}" style="width:64px;height:64px;border-radius:50%;border:2px solid var(--accent-primary);margin-bottom:8px;" onerror="this.style.display='none'">` : ''}
                                <div style="font-size:20px;font-weight:700;color:var(--text-primary);">${p?.baseToken?.name || 'Token'}</div>
                                <div style="font-size:13px;color:var(--text-muted);">${p?.baseToken?.symbol || ''} <span class="chain-badge ${getChainClass(chainId)}">${chainId}</span></div>
                                <div style="font-size:24px;font-weight:700;margin-top:8px;color:var(--text-primary);">$${formatPrice(price)}</div>
                            </div>

                            ${description ? `<div class="info-section"><div class="info-section-title">Description</div><div style="font-size:13px;color:var(--text-muted);line-height:1.5;">${description}</div></div>` : ''}

                            <div class="info-section">
                                <div class="info-section-title">Prices</div>
                                <div class="info-row"><span class="info-label">Current</span><span class="info-value">$${formatPrice(price)}</span></div>
                                <div class="info-row"><span class="info-label">Change 1h</span><span class="info-value ${change1h >= 0 ? 'profit-positive' : 'profit-negative'}">${change1h >= 0 ? '+' : ''}${change1h}%</span></div>
                                <div class="info-row"><span class="info-label">Change 6h</span><span class="info-value ${change6h >= 0 ? 'profit-positive' : 'profit-negative'}">${change6h >= 0 ? '+' : ''}${change6h}%</span></div>
                                <div class="info-row"><span class="info-label">Change 24h</span><span class="info-value ${change24h >= 0 ? 'profit-positive' : 'profit-negative'}">${change24h >= 0 ? '+' : ''}${change24h}%</span></div>
                                <div class="info-row"><span class="info-label">Market Cap</span><span class="info-value">$${formatNumber(mc)}</span></div>
                                <div class="info-row"><span class="info-label">Liquidity</span><span class="info-value">$${formatNumber(liq)}</span></div>
                            </div>

                            <div class="info-section">
                                <div class="info-section-title">Timing</div>
                                <div class="info-row"><span class="info-label">Pair Created</span><span class="info-value">${pairCreated ? formatDate(pairCreated) : 'N/A'}</span></div>
                                ${data.twitter_created_at ? `<div class="info-row"><span class="info-label">Twitter Created</span><span class="info-value">${formatDate(data.twitter_created_at)}</span></div>` : ''}
                            </div>

                            ${links.length > 0 ? `<div class="info-section"><div class="info-section-title">Links</div>${links.map(l => {
                                const label = l.type === 'twitter' ? 'X / Twitter' : l.label || l.type || 'Link';
                                const icon = l.type === 'twitter' ? '\u{1F426}' : l.type === 'telegram' ? '\u{1F4E8}' : '\u{1F517}';
                                return `<div class="info-row"><span class="info-label">${icon} ${label}</span><span class="info-value"><a href="${l.url}" target="_blank" rel="noopener" style="color:var(--accent-primary);font-size:12px;">${l.url.length > 35 ? l.url.substring(0,35)+'...' : l.url}</a></span></div>`;
                            }).join('')}</div>` : ''}

                            <div class="info-section">
                                <div class="info-section-title">Address</div>
                                <div class="info-row"><span class="info-value" style="font-size:11px;word-break:break-all;">${tokenAddress}</span></div>
                                <div style="text-align:center;margin-top:6px;"><button class="btn-copy" onclick="copyToClipboard('${tokenAddress}', this)" style="font-size:12px;">Copy Address</button></div>
                            </div>

                            <div style="text-align:center;margin-top:12px;">
                                <a href="${dexUrl}" target="_blank" rel="noopener" style="color:var(--accent-primary);font-size:12px;">View on DexScreener ↗</a>
                            </div>
                        </div>
                    `,
                    width: 480,
                    padding: '24px',
                    background: 'var(--bg-primary)',
                    showCloseButton: true,
                    showConfirmButton: false,
                    customClass: { popup: 'swal-token-info' }
                });

                // Load icon after modal opens
                if (iconUrl) {
                    const img = new Image();
                    img.onload = () => {
                        const el = document.getElementById('token-icon-' + tokenId);
                        if (el) el.innerHTML = '<img src="' + iconUrl + '" style="width:32px;height:32px;border-radius:50%;">';
                    };
                    img.src = iconUrl;
                }
            })
            .catch(err => Swal.fire({ icon: 'error', title: 'Network error', text: err.message || 'unknown' }));
        }

        function renderTokenCard(t, idx, coinsTagsMap) {
            const precioEntrada = parseFloat(t.precio_entrada);
            const profit = precioEntrada > 0 ? ((parseFloat(t.precio_actual) - precioEntrada) / precioEntrada * 100) : 0;
            const profitClass = profit >= 0 ? 'profit-positive' : 'profit-negative';
            const profitLabel = profit >= 0 ? '+' : '';
            const delay = (idx || 0) * 0.04;

            const nameNorm = (t.nombre || '').toUpperCase().trim();
            const tagData = coinsTagsMap ? coinsTagsMap[nameNorm] : null;
            let tagHtml = '';
            let countHtml = '';
            if (tagData) {
                const totalCount = (parseInt(tagData.strong_count) || 0) + (parseInt(tagData.checking_count) || 0) + (parseInt(tagData.okay_count) || 0) + (parseInt(tagData.inestable_count) || 0);
                countHtml = ` (x${totalCount})`;
                const parts = [];
                if (parseInt(tagData.strong_count) > 0) parts.push(`<span style="color:var(--success);font-size:0.65rem;">${tagData.strong_count}xS</span>`);
                if (parseInt(tagData.okay_count) > 0) parts.push(`<span style="color:var(--accent-primary);font-size:0.65rem;">${tagData.okay_count}xOk</span>`);
                if (parseInt(tagData.checking_count) > 0) parts.push(`<span style="color:var(--warning);font-size:0.65rem;">${tagData.checking_count}xCh</span>`);
                if (parseInt(tagData.inestable_count) > 0) parts.push(`<span style="color:var(--error);font-size:0.65rem;">${tagData.inestable_count}xI</span>`);
                if (parts.length > 0) tagHtml = `<div style="display:flex;gap:4px;margin-top:2px;">${parts.join('')}</div>`;
            } else {
                countHtml = ' <span style="color:var(--success);font-size:0.65rem;font-weight:700;">NEW</span>';
            }

            return `
                <div class="token-card" onclick="showTokenInfo('${t.chain_id}', '${t.token_address}', ${t.id})" style="cursor:pointer;animation-delay:${delay}s">
                    <div class="token-card-header">
                        <div style="display:flex;align-items:center;gap:10px;">
                            <div class="token-icon" id="token-icon-${t.id}">${(t.nombre || '?')[0]}</div>
                            <div>
                                <div class="token-name">${t.nombre || 'Token'}${countHtml}</div>
                                ${tagHtml}
                                <div class="token-symbol">${t.simbolo || ''}</div>
                            </div>
                        </div>
                        <span class="chain-badge ${getChainClass(t.chain_id)}">${t.chain_id}</span>
                    </div>
                    <div class="token-price">$${formatPrice(t.precio_actual)} <span class="profit-badge ${profitClass}">${profitLabel}${profit.toFixed(2)}%</span></div>
                    ${t.monto_invertido ? `<div style="font-size:0.7rem;color:var(--text-muted);margin:4px 0;">Entry: $${parseFloat(t.monto_invertido).toFixed(2)} → $${(parseFloat(t.monto_invertido) * (1 + profit/100)).toFixed(2)}</div>` : ''}
                    ${currentUser.nivel === 'admin' ? `
                        <button class="btn-delete" onclick="event.stopPropagation();forceExitToken(${t.id},'${(t.nombre || t.simbolo || 'Token').replace(/'/g, "\\'")}')" 
                                style="margin-top:6px;font-size:11px;padding:4px 10px;width:100%;">Exit</button>
                    ` : ''}
                    <div class="token-status status-${t.estado}" style="margin-top:6px;font-size:11px;text-align:center;">${t.estado === 'monitoreando' ? 'Monitoreando' : 'Nuevo'}</div>
                </div>
            `;
        }

        // Force exit token (admin only)
        function forceExitToken(tokenId, tokenName) {
            Swal.fire({
                icon: 'warning',
                title: 'Exit ' + tokenName + '?',
                text: 'This will immediately close this position at current price.',
                showCancelButton: true,
                confirmButtonText: 'Yes, exit',
                confirmButtonColor: 'var(--error)',
                cancelButtonText: 'Cancel'
            }).then(result => {
                if (!result.isConfirmed) return;
                fetch('api/admin.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Authorization': currentToken },
                    body: JSON.stringify({ action: 'force_exit', token_id: tokenId })
                })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        Swal.fire({ icon: 'success', text: 'Token exited', timer: 1500, position: 'top' });
                        loadData();
                    } else {
                        Swal.fire({ icon: 'error', title: 'Error', text: data.error || 'Unknown error' });
                    }
                })
                .catch(err => Swal.fire({ icon: 'error', title: 'Network error', text: err.message || 'unknown' }));
            });
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
                .catch(err => Swal.fire({ icon: 'error', title: 'Network error', text: err.message || 'unknown' }));
            });
        }

        // Manual ban token (admin only)
        function banearTokenManual() {
            const address = document.getElementById('banTokenAddress').value.trim();
            const name = document.getElementById('banTokenName').value.trim();
            const status = document.getElementById('banTokenStatus');
            if (!address) { status.textContent = 'Enter a token address'; return; }
            status.textContent = 'Banning...';
            fetch('api/admin.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Authorization': currentToken },
                body: JSON.stringify({ action: 'banear_token', token_address: address, nombre: name || null })
            })
            .then(async r => {
                const text = await r.text();
                try {
                    const data = JSON.parse(text);
                    if (data.success) {
                        status.textContent = 'Token banned successfully';
                        document.getElementById('banTokenAddress').value = '';
                        document.getElementById('banTokenName').value = '';
                        loadConfigData();
                    } else {
                        status.textContent = data.error || 'Error';
                    }
                } catch (e) {
                    status.textContent = 'Server error: ' + text.substring(0, 80);
                }
            })
            .catch(err => { status.textContent = 'Network error: ' + (err.message || 'unknown'); });
        }

        // Export banned tokens as JSON
        function exportBannedTokens() {
            const status = document.getElementById('banTokenStatus');
            status.textContent = 'Exporting...';
            fetch('api/admin.php?action=tokens_banned', {
                headers: { 'Authorization': currentToken }
            })
            .then(r => r.json())
            .then(data => {
                if (!data.success || !data.tokens_banned.length) {
                    status.textContent = 'No banned tokens to export';
                    return;
                }
                const blob = new Blob([JSON.stringify(data.tokens_banned, null, 2)], { type: 'application/json' });
                const a = document.createElement('a');
                a.href = URL.createObjectURL(blob);
                a.download = 'banned_tokens.json';
                a.click();
                status.textContent = 'Exported ' + data.tokens_banned.length + ' tokens';
                loadConfigData();
            })
            .catch(() => { status.textContent = 'Export error'; });
        }

        // Import banned tokens from JSON file
        function importBannedTokens() {
            const input = document.createElement('input');
            input.type = 'file';
            input.accept = '.json';
            input.onchange = (e) => {
                const file = e.target.files[0];
                if (!file) return;
                const reader = new FileReader();
                reader.onload = (ev) => {
                    const status = document.getElementById('banTokenStatus');
                    status.textContent = 'Importing...';
                    try {
                        const tokens = JSON.parse(ev.target.result);
                        if (!Array.isArray(tokens) || !tokens.length) {
                            status.textContent = 'Invalid file: empty or not an array';
                            return;
                        }
                        fetch('api/admin.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json', 'Authorization': currentToken },
                            body: JSON.stringify({ action: 'import_banned', tokens: tokens })
                        })
                        .then(async r => {
                            const text = await r.text();
                            try {
                                const data = JSON.parse(text);
                                if (data.success) {
                                    status.textContent = 'Imported: ' + data.imported + ', Skipped (duplicates): ' + data.skipped;
            if (currentUser.nivel !== 'free') loadConfigData();
                                } else {
                                    status.textContent = 'Error: ' + (data.error || 'Unknown');
                                }
                            } catch (e) {
                                status.textContent = 'Server error: ' + text.substring(0, 80);
                            }
                        })
                        .catch(err => { status.textContent = 'Network error: ' + (err.message || 'unknown'); });
                    } catch (err) {
                        status.textContent = 'Invalid JSON file';
                    }
                };
                reader.readAsText(file);
            };
            input.click();
        }

        // Delete/unban a single banned token
        function deleteBannedToken(id) {
            if (!confirm('Unban this token?')) return;
            fetch('api/admin.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Authorization': currentToken },
                body: JSON.stringify({ action: 'delete_banned', id: id })
            })
            .then(async r => {
                const text = await r.text();
                try {
                    const data = JSON.parse(text);
                    if (data.success) {
                        document.getElementById('banTokenStatus').textContent = 'Token unbanned';
                        loadConfigData();
                    } else {
                        document.getElementById('banTokenStatus').textContent = data.error || 'Error';
                    }
                } catch (e) {
                    document.getElementById('banTokenStatus').textContent = 'Server error: ' + text.substring(0, 80);
                }
            })
            .catch(err => { document.getElementById('banTokenStatus').textContent = 'Network error: ' + (err.message || 'unknown'); });
        }

        // Config: reveal API key
        let _fullApiKey = '';
        function revealApiKey() {
            const el = document.getElementById('apiKeyDisplay');
            const btn = document.getElementById('btnRevealKey');
            if (el.textContent === _fullApiKey) {
                el.textContent = _fullApiKey.substring(0, 4) + '••••••••' + _fullApiKey.slice(-3);
                btn.textContent = 'Reveal';
            } else {
                el.textContent = _fullApiKey;
                btn.textContent = 'Hide';
            }
        }

        function copyApiKey() {
            const btn = document.getElementById('btnCopyKey');
            const doCopy = (text) => {
                const ta = document.createElement('textarea');
                ta.value = text;
                ta.style.position = 'fixed';
                ta.style.left = '-9999px';
                document.body.appendChild(ta);
                ta.select();
                document.execCommand('copy');
                document.body.removeChild(ta);
                btn.textContent = '✓ Copied!';
                btn.style.background = 'var(--success)';
                btn.style.borderColor = 'var(--success)';
                btn.style.color = '#fff';
                setTimeout(() => {
                    btn.textContent = 'Copy';
                    btn.style.background = '';
                    btn.style.borderColor = '';
                    btn.style.color = '';
                }, 2000);
            };
            if (_fullApiKey) { doCopy(_fullApiKey); return; }
            fetch('api/user_config.php?action=get_api_key', { headers: { 'Authorization': currentToken } })
            .then(r => r.json())
            .then(d => { if (d.success && d.api_key) { _fullApiKey = d.api_key.key; doCopy(d.api_key.key); } });
        }

        // Config: regenerate API key
        function regenerateApiKey() {
            const btn = document.getElementById('btnRegenKey');
            btn.disabled = true;
            btn.textContent = 'Regenerating...';
            fetch('api/user_config.php?action=regenerate_key', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Authorization': currentToken },
                body: '{}'
            })
            .then(r => r.json())
            .then(data => {
                if (data.error === 'too_soon') {
                    const h = data.retry_after_hours || 0;
                    const m = data.retry_after_minutes || 0;
                    document.getElementById('regenCountdown').textContent = `Available in ${h}h ${m}m`;
                    btn.disabled = false;
                    btn.textContent = 'Rehacer';
                    setTimeout(() => document.getElementById('regenCountdown').textContent = '', 8000);
                } else if (data.success) {
                    _fullApiKey = data.api_key;
                    document.getElementById('apiKeyDisplay').textContent = data.api_key.substring(0, 4) + '••••••••' + data.api_key.slice(-3);
                    document.getElementById('btnRevealKey').textContent = 'Reveal';
                    document.getElementById('regenCountdown').textContent = 'Key regenerated!';
                    btn.disabled = false;
                    btn.textContent = 'Rehacer';
                    setTimeout(() => document.getElementById('regenCountdown').textContent = '', 4000);
                } else {
                    Swal.fire({ icon: 'error', title: 'Error', text: data.error || 'Unknown error' });
                    btn.disabled = false;
                    btn.textContent = 'Rehacer';
                }
            })
            .catch(() => {
                btn.disabled = false;
                btn.textContent = 'Rehacer';
            });
        }

        // Config: save criteria
        function saveCriteria() {
            const editable = ['stop_loss_pct', 'take_profit_pct', 'max_wait_minutes', 'save_profit_pct'];
            const payload = {};
            editable.forEach(k => {
                const el = document.getElementById('criteria-' + k);
                if (el) payload[k] = parseFloat(el.value);
            });
            const btn = document.getElementById('btnSaveCriteria');
            btn.textContent = 'Saving...';
            btn.disabled = true;
            fetch('api/user_config.php?action=criteria', {
                method: 'PATCH',
                headers: { 'Content-Type': 'application/json', 'Authorization': currentToken },
                body: JSON.stringify(payload)
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    btn.textContent = 'Saved!';
                    setTimeout(() => { btn.textContent = 'Save Criteria'; btn.disabled = false; }, 2000);
                } else {
                    Swal.fire({ icon: 'error', title: 'Error', text: data.error || 'Unknown error' });
                    btn.textContent = 'Save Criteria';
                    btn.disabled = false;
                }
            })
            .catch(() => {
                btn.textContent = 'Save Criteria';
                btn.disabled = false;
            });
        }

        // Manual coin insertion
        function addManualCoin() {
            const address = document.getElementById('manualCoinAddress').value.trim();
            if (!address) return;
            const btn = document.getElementById('btnAddManualCoin');
            const status = document.getElementById('manualCoinStatus');
            btn.disabled = true;
            btn.textContent = 'Adding...';
            status.textContent = '';
            fetch('api/admin.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Authorization': currentToken },
                body: JSON.stringify({ action: 'insertar_token_manual', token_address: address })
            })
            .then(r => r.json())
            .then(d => {
                document.getElementById('manualCoinAddress').value = '';
                if (d.success) {
                    status.textContent = '✓ Coin added. The server will process it shortly.';
                    status.style.color = 'var(--success)';
                } else {
                    status.textContent = '✗ ' + (d.error || 'Error');
                    status.style.color = 'var(--error)';
                }
                setTimeout(() => { status.textContent = ''; status.style.color = ''; }, 5000);
            })
            .catch(err => {
                status.textContent = '✗ Network error: ' + (err.message || 'unknown');
                status.style.color = 'var(--error)';
                setTimeout(() => { status.textContent = ''; status.style.color = ''; }, 5000);
            })
            .finally(() => { btn.disabled = false; btn.textContent = 'Add Coin'; });
        }

        // Show token detail modal (admin only)
        function showTokenDetail(historialId) {
            Promise.all([
                fetch('api/tokens.php?action=detail&id=' + historialId, { headers: { 'Authorization': currentToken } }).then(r => r.json()),
                fetch('api/tokens.php?action=token_info&chain_id=solana&token_address=', { headers: { 'Authorization': currentToken } }).then(r => r.json()).catch(() => null)
            ])
            .then(([detailData]) => {
                if (!detailData.success) {
                    Swal.fire({ icon: 'error', title: 'Error', text: detailData.error || 'Unknown error' });
                    return;
                }
                const d = detailData.detail;
                const extra = detailData.token_extra;
                const profit = parseFloat(d.profit_porcentaje);
                const profitClass = profit >= 0 ? 'profit-positive' : 'profit-negative';
                const profitLabel = profit >= 0 ? '+' : '';

                // Fetch social links for this token
                fetch('api/tokens.php?action=token_info&chain_id=' + encodeURIComponent(d.chain_id) + '&token_address=' + encodeURIComponent(d.token_address), {
                    headers: { 'Authorization': currentToken }
                })
                .then(r => r.json())
                .then(infoData => {
                    const links = infoData.links || [];
                    const twitter = links.find(l => l.type === 'twitter');
                    const website = links.find(l => l.label === 'Website' || l.type === 'website');
                    const telegram = links.find(l => l.type === 'telegram');
                    let linksHtml = '';
                    const twitterCreated = infoData.twitter_created_at || null;
                    if (twitter || website || telegram || twitterCreated) {
                        linksHtml = '<div class="detail-section"><div class="detail-section-title">Links</div>';
                        if (twitter) linksHtml += `<div class="detail-row"><span class="detail-label">Twitter</span><span class="detail-value"><a href="${twitter.url}" target="_blank" style="color:var(--accent-primary);font-size:11px;">${twitter.url.substring(0, 30)}...</a></span></div>`;
                        if (twitterCreated) linksHtml += `<div class="detail-row"><span class="detail-label">Twitter Created</span><span class="detail-value">${formatDate(twitterCreated)}</span></div>`;
                        if (telegram) linksHtml += `<div class="detail-row"><span class="detail-label">Telegram</span><span class="detail-value"><a href="${telegram.url}" target="_blank" style="color:var(--accent-primary);font-size:11px;">${telegram.url.substring(0, 30)}...</a></span></div>`;
                        if (website) linksHtml += `<div class="detail-row"><span class="detail-label">Website</span><span class="detail-value"><a href="${website.url}" target="_blank" style="color:var(--accent-primary);font-size:11px;">${website.url.substring(0, 30)}...</a></span></div>`;
                        linksHtml += '</div>';
                    }

                    Swal.fire({
                        title: (d.nombre || d.simbolo || 'Token') + ' <span style="font-size:0.7rem;opacity:0.6">' + (d.chain_id || '') + '</span>',
                        html: `
                            <div class="detail-modal">
                                <div class="detail-section">
                                    <div class="detail-section-title">Discovery</div>
                                    <div class="detail-row"><span class="detail-label">Date</span><span class="detail-value">${extra?.creado_en ? formatDate(extra.creado_en) : 'N/A'}</span></div>
                                    <div class="detail-row"><span class="detail-label">Price</span><span class="detail-value">${d.precio_descubrimiento ? '$' + parseFloat(d.precio_descubrimiento).toFixed(8) : 'N/A'}</span></div>
                                </div>
                                <div class="detail-section">
                                    <div class="detail-section-title">Entry</div>
                                    <div class="detail-row"><span class="detail-label">Date</span><span class="detail-value">${d.fecha_entrada ? formatDate(d.fecha_entrada) : 'N/A'}</span></div>
                                    <div class="detail-row"><span class="detail-label">Price</span><span class="detail-value">$${d.precio_entrada ? parseFloat(d.precio_entrada).toFixed(8) : 'N/A'}</span></div>
                                </div>
                                <div class="detail-section">
                                    <div class="detail-section-title">Peak</div>
                                    <div class="detail-row"><span class="detail-label">Price</span><span class="detail-value">$${extra?.precio_maximo ? parseFloat(extra.precio_maximo).toFixed(8) : 'N/A'}</span></div>
                                </div>
                                <div class="detail-section">
                                    <div class="detail-section-title">Exit</div>
                                    <div class="detail-row"><span class="detail-label">Date</span><span class="detail-value">${d.fecha_salida ? formatDate(d.fecha_salida) : 'N/A'}</span></div>
                                    <div class="detail-row"><span class="detail-label">Price</span><span class="detail-value">$${d.precio_salida ? parseFloat(d.precio_salida).toFixed(8) : 'N/A'}</span></div>
                                </div>
                                ${linksHtml}
                                    <div class="detail-section">
                                        <div class="detail-section-title">Summary</div>
                                        <div class="detail-row"><span class="detail-label">Profit</span><span class="detail-value ${profitClass}">${profitLabel}${profit.toFixed(2)}%</span></div>
                                        ${d.monto_invertido ? `<div class="detail-row"><span class="detail-label">Entry Amount</span><span class="detail-value">$${parseFloat(d.monto_invertido).toFixed(2)}</span></div>` : ''}
                                        ${d.monto_invertido ? `<div class="detail-row"><span class="detail-label">Return Amount</span><span class="detail-value" class="${profitClass}">$${(parseFloat(d.monto_invertido) + parseFloat(d.profit_dolares || 0)).toFixed(2)}</span></div>` : ''}
                                    <div class="detail-row"><span class="detail-label">Duration</span><span class="detail-value">${d.duracion_minutos || 0} min</span></div>
                                    <div class="detail-row"><span class="detail-label">Reason</span><span class="detail-value">${getRazonSalida(d.razon_salida)}</span></div>
                                    ${d.tag ? `<div class="detail-row"><span class="detail-label">Tag</span><span class="detail-value">${d.tag}</span></div>` : ''}
                                    ${d.es_reentry ? `<div class="detail-row"><span class="detail-label">Re-entry</span><span class="detail-value">Yes</span></div>` : ''}
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
                .catch(() => {
                    Swal.fire({
                        title: (d.nombre || d.simbolo || 'Token') + ' <span style="font-size:0.7rem;opacity:0.6">' + (d.chain_id || '') + '</span>',
                        html: `<div class="detail-modal">
                            <div class="detail-section"><div class="detail-section-title">Summary</div>
                            <div class="detail-row"><span class="detail-label">Profit</span><span class="detail-value ${profitClass}">${profitLabel}${profit.toFixed(2)}%</span></div>
                            <div class="detail-row"><span class="detail-label">Duration</span><span class="detail-value">${d.duracion_minutos || 0} min</span></div>
                            <div class="detail-row"><span class="detail-label">Reason</span><span class="detail-value">${getRazonSalida(d.razon_salida)}</span></div>
                            ${d.tag ? `<div class="detail-row"><span class="detail-label">Tag</span><span class="detail-value">${d.tag}</span></div>` : ''}</div></div>`,
                        width: 480, padding: '24px', background: 'var(--bg-primary)',
                        showCloseButton: true, showConfirmButton: false,
                        customClass: { popup: 'swal-detail' }
                    });
                });
            })
            .catch(err => Swal.fire({ icon: 'error', title: 'Network error', text: err.message || 'unknown' }));
        }

        // Config Data
        function loadConfigData() {
            const isAdminCd = currentUser.nivel === 'admin';
            const isProCd = currentUser.nivel === 'vip';
            
            // Show/hide manual coin section (Pro and Admin)
            const manualCoinSection = document.getElementById('manualCoinSection');
            if (manualCoinSection) manualCoinSection.style.display = (isProCd || isAdminCd) ? 'block' : 'none';
            
            // Show/hide admin-only sections
            const addBtn = document.getElementById('addUserBtn');
            if (addBtn) addBtn.style.display = isAdminCd ? '' : 'none';
            const userMgmt = document.querySelector('.admin-users');
            if (userMgmt) userMgmt.style.display = isAdminCd ? '' : 'none';
            const adminStats = document.getElementById('adminStats');
            if (adminStats) adminStats.style.display = isAdminCd ? '' : 'none';

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

            // Cargar API Key
            fetch('api/user_config.php?action=get_api_key', {
                headers: { 'Authorization': currentToken }
            })
            .then(r => r.json())
            .then(data => {
                if (data.success && data.api_key) {
                    document.getElementById('apiKeyDisplay').textContent = data.api_key.key_obfuscated;
                    _fullApiKey = data.api_key.key;
                }
            });

            // Cargar criteria del usuario (nuevo system_criteria)
            fetch('api/user_config.php?action=criteria', {
                headers: { 'Authorization': currentToken }
            })
            .then(r => r.json())
            .then(data => {
                if (!data.success) return;
                const c = data.criteria;
                const editable = data.editable_fields || [];
                const fields = [
                    { key: 'stop_loss_pct', label: 'Stop Loss (%)', suffix: '%', negative: true },
                    { key: 'take_profit_pct', label: 'Take Profit (%)', suffix: '%', positive: true },
                    { key: 'max_wait_minutes', label: 'Max Wait (minutes)', suffix: ' min', integer: true },
                    { key: 'save_profit_pct', label: 'Save Profit - Drop from Peak (%)', suffix: '%', negative: true }
                ];
                let html = '';
                const isEditable = editable.length > 0;
                fields.forEach(f => {
                    const canEdit = editable.includes(f.key);
                    const val = c[f.key];
                    html += '<div class="criteria-field">';
                    html += `<div class="field-label">${f.label}`;

                    html += '</div>';
                    if (canEdit) {
                        html += `<input type="number" step="0.1" id="criteria-${f.key}" value="${val}" data-key="${f.key}">`;
                    } else {
                        html += `<div class="static-value">${val}${f.suffix || ''}</div>`;
                    }
                    html += '</div>';
                });
                document.getElementById('criteriaForm').innerHTML = html;
                document.getElementById('btnSaveCriteria').style.display = isEditable ? 'inline-block' : 'none';
            });

            // Cargar usuarios
            fetch('api/admin.php?action=usuarios', {
                headers: { 'Authorization': currentToken }
            })
            .then(r => r.json())
            .then(data => {
                if (!data.success) return;
                const nivelLabels = { 'admin': 'Admin', 'vip': 'Pro', 'free': 'Free' };
                document.getElementById('usersTable').innerHTML = data.usuarios.map(u => `
                    <tr>
                        <td data-label="Username">${u.username}</td>
                        <td data-label="Level"><span class="level level-${u.nivel === 'admin' ? 'admin' : (u.nivel === 'vip' ? 'pro' : 'free')}">${nivelLabels[u.nivel] || 'Free'}</span></td>
                        <td data-label="Status">${u.activo ? 'Active' : 'Inactive'}</td>
                        <td data-label="Last Login">${u.ultimo_login || 'Never'}</td>
                        <td data-label="Actions">
                            <button class="btn-edit" onclick="editUser(${u.id}, '${u.username}', '${u.nivel}')">Edit</button>
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
                        <td data-label="Name">${t.nombre || 'N/A'}</td>
                        <td data-label="Token Address"><span class="address" title="${t.token_address}">${t.token_address.substring(0, 12)}...</span></td>
                        <td data-label="Pair Address"><span class="address" title="${t.pair_address}">${t.pair_address.substring(0, 12)}...</span></td>
                        <td data-label="Chain">${t.chain_id}</td>
                        <td data-label="Reason">${t.razon || 'N/A'}</td>
                        <td data-label="Date">${t.banneado_en}</td>
                        <td data-label="Actions"><button class="btn-delete" onclick="deleteBannedToken(${t.id})" style="padding:5px 12px;font-size:11px;">Unban</button></td>
                    </tr>
                `).join('');
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

            const action = editingUserId ? 'editar_usuario' : 'crear_usuario';
            const body = editingUserId
                ? { action, id: editingUserId, pin, nivel }
                : { action, username, pin, nivel };


            const res = await fetch('api/admin.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Authorization': currentToken },
                body: JSON.stringify(body)
            });
            const data = await res.json();
            console.log('Server response:', data);

            if (data.success) {
                document.getElementById('userModal').classList.remove('active');
                loadConfigData();
            } else {
                Swal.fire({ icon: 'error', title: 'Error', text: data.error });
            }
        });

        window.editUser = (id, username, nivel) => {
            editingUserId = id;
            document.getElementById('modalTitle').textContent = 'Edit User';
            document.getElementById('modalUsername').value = username;
            document.getElementById('modalUsername').disabled = true;
            document.getElementById('modalPin').value = '';
            document.getElementById('modalNivel').value = nivel;
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
            if (data.success) loadConfigData();
            else Swal.fire({ icon: 'error', title: 'Error', text: data.error });
        };

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

        window.copyToClipboard = (text, btn) => {
            const ta = document.createElement('textarea');
            ta.value = text;
            ta.style.position = 'fixed';
            ta.style.left = '-9999px';
            document.body.appendChild(ta);
            ta.select();
            document.execCommand('copy');
            document.body.removeChild(ta);
            if (btn) {
                const orig = btn.textContent;
                btn.textContent = '✓ Copied!';
                btn.style.background = 'var(--success)';
                btn.style.borderColor = 'var(--success)';
                btn.style.color = '#fff';
                setTimeout(() => {
                    btn.textContent = orig;
                    btn.style.background = '';
                    btn.style.borderColor = '';
                    btn.style.color = '';
                }, 2000);
            } else {
                Swal.fire({ icon: 'success', text: 'Copied to clipboard', timer: 1500, position: 'top' });
            }
        };

        // Scroll entrance animations
        try {
            if ('IntersectionObserver' in window) {
                const animateOnScroll = document.querySelectorAll('.stat-card, .token-card, .criterio-card, .disclaimer, .modal-content');
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
        
        // Mouse glow tracking
        document.addEventListener('mousemove', e => {
            document.documentElement.style.setProperty('--mouse-x', e.clientX + 'px');
            document.documentElement.style.setProperty('--mouse-y', e.clientY + 'px');
        });

        // Particles background
        (function() {
            const canvas = document.createElement('canvas');
            canvas.style.cssText = 'position:fixed;top:0;left:0;width:100%;height:100%;pointer-events:none;z-index:0';
            document.body.prepend(canvas);
            const ctx = canvas.getContext('2d');
            let p = [];
            function sz() { canvas.width = window.innerWidth; canvas.height = window.innerHeight; }
            window.addEventListener('resize', sz); sz();
            for (let i = 0; i < 50; i++) p.push({ x: Math.random() * canvas.width, y: Math.random() * canvas.height, vx: (Math.random() - 0.5) * 0.4, vy: (Math.random() - 0.5) * 0.4, r: Math.random() * 2 + 0.5 });
            function anim() {
                ctx.clearRect(0, 0, canvas.width, canvas.height);
                p.forEach((a, i) => {
                    a.x += a.vx; a.y += a.vy;
                    if (a.x < 0) a.x = canvas.width; if (a.x > canvas.width) a.x = 0;
                    if (a.y < 0) a.y = canvas.height; if (a.y > canvas.height) a.y = 0;
                    ctx.beginPath(); ctx.arc(a.x, a.y, a.r, 0, Math.PI * 2);
                    ctx.fillStyle = 'rgba(0,212,255,0.3)'; ctx.fill();
                    for (let j = i + 1; j < p.length; j++) {
                        const dx = p[j].x - a.x, dy = p[j].y - a.y, dist = Math.sqrt(dx * dx + dy * dy);
                        if (dist < 100) { ctx.beginPath(); ctx.moveTo(a.x, a.y); ctx.lineTo(p[j].x, p[j].y); ctx.strokeStyle = `rgba(0,212,255,${0.05 * (1 - dist / 100)})`; ctx.lineWidth = 0.5; ctx.stroke(); }
                    }
                });
                requestAnimationFrame(anim);
            }
            anim();
        })();

        // Mouse glow CSS injection
        const glowStyle = document.createElement('style');
        glowStyle.textContent = 'body::after{content:"";position:fixed;top:0;left:0;width:100%;height:100%;background:radial-gradient(600px at var(--mouse-x) var(--mouse-y),rgba(0,212,255,0.04),transparent);pointer-events:none;z-index:9999}';
        document.head.appendChild(glowStyle);

        // Toast system
        window.showToast = function(message, type, subtitle) {
            const container = document.getElementById('toastContainer');
            const t = document.createElement('div');
            t.className = 'toast toast-' + (type || 'info');
            t.innerHTML = message + (subtitle ? '<div class="toast-sub">' + subtitle + '</div>' : '');
            container.appendChild(t);
            setTimeout(() => { t.classList.add('toast-exit'); setTimeout(() => t.remove(), 300); }, 3500);
        };

        // Confetti on TP
        window.showConfetti = function() {
            const colors = ['#00ff88', '#00d4ff', '#ff006e', '#ffbe0b'];
            const canvas = document.createElement('canvas');
            canvas.style.cssText = 'position:fixed;top:0;left:0;width:100%;height:100%;pointer-events:none;z-index:10001';
            document.body.appendChild(canvas);
            const ctx = canvas.getContext('2d');
            canvas.width = window.innerWidth; canvas.height = window.innerHeight;
            let confetti = [];
            for (let i = 0; i < 60; i++) {
                confetti.push({ x: Math.random() * canvas.width, y: -20, w: Math.random() * 8 + 4, h: Math.random() * 4 + 2, c: colors[Math.floor(Math.random() * colors.length)], vx: (Math.random() - 0.5) * 4, vy: Math.random() * 3 + 2, r: Math.random() * 360 });
            }
            let frames = 0;
            function tick() {
                ctx.clearRect(0, 0, canvas.width, canvas.height);
                confetti.forEach(c => { c.x += c.vx; c.y += c.vy; c.r += 5; ctx.save(); ctx.translate(c.x, c.y); ctx.rotate(c.r * Math.PI / 180); ctx.fillStyle = c.c; ctx.fillRect(-c.w/2, -c.h/2, c.w, c.h); ctx.restore(); });
                if (++frames < 120) requestAnimationFrame(tick); else canvas.remove();
            }
            tick();
        };

        console.log('AltiChecker: Init complete');
    </script>
</body>
</html>