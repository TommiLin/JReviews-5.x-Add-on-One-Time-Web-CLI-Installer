<?php
/**
 * ONE-TIME & MAINTENANCE Installer for JReviews 5.x Add-ons.
 *
 * Features:
 *   - Auto-scanning, ZIP upload, and Manual entry tabs.
 *   - Automatic DB Backup & Interactive Rollback Panel.
 *   - Safe .lock file execution blocking mechanism.
 *   - Quick Purge & Self-Destruct Panel with mutually exclusive options.
 *   - Interactive Auto-scrolling Terminal Output with auto-scroll on submit.
 *   - Local Installation History Log (`install_history.json`).
 *   - GitHub Release / Update Checker integration.
 *   - Server Diagnostics and Manifest XML preview.
 *
 * SAFETY WARNING: Run on test environments first!
 */

// ------------------------- CONFIGURATION -------------------------
const JR_ONETIME_TOKEN = 'CHANGE_THIS_TO_A_LONG_RANDOM_SECRET_KEY';

/**
 * Default post-installation behavior:
 *   - 'delete' : Automatically delete this installer script after success.
 *   - 'reset'  : Keep the script, but create jr_install.lock to block re-execution.
 *   - 'keep'   : Keep the script as is.
 */
const JR_POST_INSTALL = 'delete';

// GitHub Updates XML URL
const JR_UPDATE_XML_URL = 'https://raw.githubusercontent.com/TommiLin/JReviews-5.x-Add-on-One-Time-Web-CLI-Installer/main/jreviews_installer_updates.xml';
const JR_CURRENT_VERSION = '2.3.2';
// -----------------------------------------------------------------

ini_set('display_errors', '1');
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

if (PHP_SAPI === 'cli') {
    parse_str(implode('&', array_slice($argv, 1)), $_GET);
}

// Check for Lock File (Prevents re-execution without modifying PHP source)
$lockFile = __DIR__ . '/jr_install.lock';
if (is_file($lockFile)) {
    if (isset($_GET['action']) && in_array($_GET['action'], ['get_manifest', 'diagnostics', 'get_history', 'check_updates', 'get_backups'])) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => 'Installer is locked. Remove jr_install.lock to unlock.']);
        exit;
    }
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "[FAIL] Installer is locked by 'jr_install.lock'. Please delete the lock file or the installer script from the server.";
    exit;
}

function jr_is_json_request(): bool
{
    return (isset($_SERVER['HTTP_ACCEPT']) && str_contains($_SERVER['HTTP_ACCEPT'], 'application/json'))
        || (isset($_GET['action']) && in_array($_GET['action'], ['get_manifest', 'diagnostics', 'get_history', 'check_updates', 'get_backups', 'do_rollback', 'purge_all_backups', 'terminate_self']));
}

function jr_out(string $msg, bool $ok = true): void
{
    if (jr_is_json_request()) {
        return;
    }
    echo ($ok ? '[ OK ] ' : '[FAIL] ') . $msg . "\n";
}

function jr_stop(string $msg, int $code = 400): never
{
    if (jr_is_json_request()) {
        if (!headers_sent()) {
            http_response_code($code);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode(['success' => false, 'message' => $msg]);
        exit;
    }

    if (!headers_sent()) {
        http_response_code($code);
        header('Content-Type: text/plain; charset=utf-8');
    }
    jr_out($msg, false);
    exit;
}

function jr_log_security(string $message): void
{
    if (class_exists('\\Joomla\\CMS\\Log\\Log')) {
        try {
            \Joomla\CMS\Log\Log::add($message, \Joomla\CMS\Log\Log::WARNING, 'jreviews_installer');
        } catch (\Throwable $e) {
            // Log fallback ignore
        }
    }
}

function jr_save_history(string $addon, string $status, string $details = ''): void
{
    $historyFile = __DIR__ . '/components/com_jreviews_addons/install_history.json';
    $history = [];

    if (is_file($historyFile)) {
        $content = file_get_contents($historyFile);
        $history = json_decode($content, true) ?? [];
    }

    array_unshift($history, [
        'timestamp' => date('Y-m-d H:i:s'),
        'addon'     => $addon,
        'status'    => $status,
        'details'   => $details
    ]);

    $history = array_slice($history, 0, 50);
    @file_put_contents($historyFile, json_encode($history, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

// Security Token Check
if (JR_ONETIME_TOKEN === 'CHANGE_THIS_TO_A_LONG_RANDOM_SECRET_KEY' || strlen(JR_ONETIME_TOKEN) < 16) {
    jr_stop('Security key error: Set a secure JR_ONETIME_TOKEN in the file (minimum 16 characters).', 403);
}

// Bootstrap Joomla for DB operations
if (PHP_SAPI !== 'cli' && isset($_GET['action']) && in_array($_GET['action'], ['get_backups', 'do_rollback', 'purge_all_backups', 'terminate_self'])) {
    $providedToken = (string) ($_GET['token'] ?? $_POST['token'] ?? '');
    if (!hash_equals(JR_ONETIME_TOKEN, $providedToken)) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => 'Invalid security token']);
        exit;
    }

    define('_JEXEC', 1);
    define('JPATH_BASE', __DIR__);
    if (is_file(JPATH_BASE . '/includes/defines.php') && is_file(JPATH_BASE . '/includes/framework.php')) {
        require_once JPATH_BASE . '/includes/defines.php';
        require_once JPATH_BASE . '/includes/framework.php';
        $container = \Joomla\CMS\Factory::getContainer();
        $container->alias('session.web', 'session.web.site')
            ->alias('session', 'session.web.site')
            ->alias('JSession', 'session.web.site')
            ->alias(\Joomla\CMS\Session\Session::class, 'session.web.site')
            ->alias(\Joomla\Session\SessionInterface::class, 'session.web.site');
        $app = $container->get(\Joomla\CMS\Application\SiteApplication::class);
        \Joomla\CMS\Factory::$application = $app;
    }

    header('Content-Type: application/json; charset=utf-8');
    $db = \Joomla\CMS\Factory::getContainer()->get('DatabaseDriver');
    $prefix = $db->getPrefix();

    if ($_GET['action'] === 'get_backups') {
        $tables = $db->setQuery('SHOW TABLES LIKE ' . $db->quote($prefix . 'jreviews_addons_bak_%'))->loadColumn();
        echo json_encode(['success' => true, 'backups' => $tables ?: []]);
        exit;
    }

    if ($_GET['action'] === 'purge_all_backups') {
        $tables = $db->setQuery('SHOW TABLES LIKE ' . $db->quote($prefix . 'jreviews_addons_bak_%'))->loadColumn();
        $count = 0;
        foreach ($tables as $t) {
            $db->setQuery('DROP TABLE IF EXISTS ' . $db->quoteName($t))->execute();
            $count++;
        }
        echo json_encode(['success' => true, 'message' => "Successfully dropped $count backup table(s)."]);
        exit;
    }

    if ($_GET['action'] === 'do_rollback') {
        $backupTable = (string) ($_GET['table'] ?? '');
        $addonToClean = (string) ($_GET['addon'] ?? '');

        if (!$backupTable || !str_starts_with($backupTable, $prefix . 'jreviews_addons_bak_')) {
            echo json_encode(['success' => false, 'message' => 'Invalid backup table selected.']);
            exit;
        }

        try {
            $targetTable = $prefix . 'jreviews_addons';
            $db->setQuery('DROP TABLE IF EXISTS ' . $db->quoteName($targetTable))->execute();
            $db->setQuery('RENAME TABLE ' . $db->quoteName($backupTable) . ' TO ' . $db->quoteName($targetTable))->execute();

            if ($addonToClean && preg_match('/^[a-z0-9_]{2,60}$/', $addonToClean)) {
                $addonDir = __DIR__ . '/components/com_jreviews_addons/' . $addonToClean;
                if (is_dir($addonDir)) {
                    $files = new RecursiveIteratorIterator(
                        new RecursiveDirectoryIterator($addonDir, RecursiveDirectoryIterator::SKIP_DOTS),
                        RecursiveIteratorIterator::CHILD_FIRST
                    );
                    foreach ($files as $fileinfo) {
                        if ($fileinfo->isDir()) {
                            rmdir($fileinfo->getRealPath());
                        } else {
                            unlink($fileinfo->getRealPath());
                        }
                    }
                    rmdir($addonDir);
                }
            }

            echo json_encode(['success' => true, 'message' => 'Rollback completed successfully! Database restored and addon folder cleaned.']);
        } catch (\Throwable $e) {
            echo json_encode(['success' => false, 'message' => 'Rollback failed: ' . $e->getMessage()]);
        }
        exit;
    }

    if ($_GET['action'] === 'terminate_self') {
        while (ob_get_level()) {
            ob_end_clean();
        }
        
        $delBackups = !empty($_POST['del_backups']);
        $delHistory = !empty($_POST['del_history']);
        $createLock = !empty($_POST['create_lock']);
        $delScript  = !empty($_POST['del_script']);

        $logs = [];

        try {
            if ($delBackups) {
                $tables = $db->setQuery('SHOW TABLES LIKE ' . $db->quote($prefix . 'jreviews_addons_bak_%'))->loadColumn();
                if ($tables) {
                    foreach ($tables as $t) {
                        $db->setQuery('DROP TABLE IF EXISTS ' . $db->quoteName($t))->execute();
                    }
                    $logs[] = 'Dropped ' . count($tables) . ' backup table(s) from DB.';
                } else {
                    $logs[] = 'No backup tables found to drop.';
                }
            }

            if ($delHistory) {
                $historyFile = __DIR__ . '/components/com_jreviews_addons/install_history.json';
                if (is_file($historyFile)) {
                    @unlink($historyFile);
                    $logs[] = 'Removed install_history.json log file.';
                } else {
                    $logs[] = 'History log file not found.';
                }
            }

            if ($createLock && !$delScript) {
                if (@file_put_contents(__DIR__ . '/jr_install.lock', date('Y-m-d H:i:s')) !== false) {
                    $logs[] = 'Created jr_install.lock file successfully.';
                } else {
                    $logs[] = 'Warning: Could not create jr_install.lock file (permission denied).';
                }
            }

            $responseJson = json_encode(['success' => true, 'logs' => $logs, 'script_deleted' => $delScript]);
            
            header('Content-Type: application/json; charset=utf-8');
            echo $responseJson;
            
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            } else {
                @ob_flush();
                @flush();
            }

            if ($delScript) {
                if (is_file(__DIR__ . '/jr_install.lock')) {
                    @unlink(__DIR__ . '/jr_install.lock');
                }
                @unlink(__FILE__);
            }
        } catch (\Throwable $ex) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'message' => 'Purge error: ' . $ex->getMessage()]);
        }
        exit;
    }
}

$providedToken = (string) ($_POST['token'] ?? $_GET['token'] ?? '');

// ---------------------- AJAX: System Diagnostics ----------------------
if (isset($_GET['action']) && $_GET['action'] === 'diagnostics') {
    header('Content-Type: application/json; charset=utf-8');
    if (!hash_equals(JR_ONETIME_TOKEN, $providedToken)) {
        echo json_encode(['success' => false, 'message' => 'Invalid security token']);
        exit;
    }
    $addonsDir = __DIR__ . '/components/com_jreviews_addons';
    $jrFramework = __DIR__ . '/components/com_jreviews/jreviews/framework.php';
    $checks = [
        'php_version' => ['title' => 'PHP Version (>= 8.1)', 'ok' => version_compare(PHP_VERSION, '8.1.0', '>='), 'info' => PHP_VERSION],
        'zip_archive' => ['title' => 'ZipArchive Extension', 'ok' => class_exists('ZipArchive'), 'info' => class_exists('ZipArchive') ? 'Installed' : 'Missing'],
        'addons_writable' => ['title' => 'Add-ons Directory Writable', 'ok' => is_dir($addonsDir) ? is_writable($addonsDir) : is_writable(__DIR__ . '/components'), 'info' => is_dir($addonsDir) ? (is_writable($addonsDir) ? 'Writable' : 'Read-only') : 'Parent folder check'],
        'jreviews_framework' => ['title' => 'JReviews Framework', 'ok' => is_file($jrFramework), 'info' => is_file($jrFramework) ? 'Found' : 'Not installed / Missing']
    ];
    echo json_encode(['success' => true, 'checks' => $checks]);
    exit;
}

// ---------------------- AJAX: Get Manifest ----------------------
if (isset($_GET['action']) && $_GET['action'] === 'get_manifest') {
    header('Content-Type: application/json; charset=utf-8');
    if (!hash_equals(JR_ONETIME_TOKEN, $providedToken)) {
        echo json_encode(['success' => false, 'message' => 'Invalid security token']);
        exit;
    }
    $addonName = (string) ($_GET['addon_name'] ?? '');
    if (!preg_match('/^[a-z0-9_]{2,60}$/', $addonName)) {
        echo json_encode(['success' => false, 'message' => 'Invalid add-on name']);
        exit;
    }
    $xmlFile = __DIR__ . '/components/com_jreviews_addons/' . $addonName . '/' . $addonName . '.xml';
    if (!is_file($xmlFile)) {
        echo json_encode(['success' => false, 'message' => 'Manifest file not found']);
        exit;
    }
    $xml = simplexml_load_file($xmlFile, 'SimpleXMLElement', LIBXML_NOCDATA);
    echo json_encode([
        'success' => true,
        'manifest' => [
            'title'       => (string) ($xml->title ?? $addonName),
            'version'     => (string) ($xml->version ?? 'Unknown'),
            'author'      => (string) ($xml->author ?? 'Unknown'),
            'description' => (string) ($xml->description ?? 'No description provided.'),
            'min_jr'      => (string) ($xml->required_jreviews_version ?? 'Not specified')
        ]
    ]);
    exit;
}

// ---------------------- AJAX: Fetch History ----------------------
if (isset($_GET['action']) && $_GET['action'] === 'get_history') {
    header('Content-Type: application/json; charset=utf-8');
    if (!hash_equals(JR_ONETIME_TOKEN, $providedToken)) {
        echo json_encode(['success' => false, 'message' => 'Invalid security token']);
        exit;
    }
    $historyFile = __DIR__ . '/components/com_jreviews_addons/install_history.json';
    $history = is_file($historyFile) ? json_decode(file_get_contents($historyFile), true) : [];
    echo json_encode(['success' => true, 'history' => $history ?? []]);
    exit;
}

// ---------------------- AJAX: GitHub Release Checker ----------------------
if (isset($_GET['action']) && $_GET['action'] === 'check_updates') {
    header('Content-Type: application/json; charset=utf-8');
    if (!hash_equals(JR_ONETIME_TOKEN, $providedToken)) {
        echo json_encode(['success' => false, 'message' => 'Invalid security token']);
        exit;
    }
    $ch = curl_init(JR_UPDATE_XML_URL);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Joomla-JReviews-Installer');
    curl_setopt($ch, CURLOPT_TIMEOUT, 8);
    $response = curl_exec($ch);
    curl_close($ch);
    if (!$response) {
        echo json_encode(['success' => false, 'message' => 'Could not fetch remote XML from GitHub.']);
        exit;
    }
    $xml = simplexml_load_string($response);
    $latestVersion = (string) $xml->update->version;
    $downloadUrl   = (string) $xml->update->downloadurl;
    $hasUpdate     = version_compare($latestVersion, JR_CURRENT_VERSION, '>');
    echo json_encode(['success' => true, 'current_version' => JR_CURRENT_VERSION, 'latest_version' => $latestVersion, 'has_update' => $hasUpdate, 'download_url' => $downloadUrl]);
    exit;
}

// ---------------------- Render Web UI ----------------------
if (PHP_SAPI !== 'cli' && $_SERVER['REQUEST_METHOD'] === 'GET' && empty($_GET['addon']) && empty($_GET['action'])) {
    header('Content-Type: text/html; charset=utf-8');
    header('X-Robots-Tag: noindex, nofollow');
    
    $addonsPath = __DIR__ . '/components/com_jreviews_addons';
    $scannedAddons = [];
    if (is_dir($addonsPath)) {
        $dirs = array_diff(scandir($addonsPath), ['.', '..']);
        foreach ($dirs as $dir) {
            if (is_dir($addonsPath . '/' . $dir) && is_file($addonsPath . '/' . $dir . '/' . $dir . '.xml')) {
                $scannedAddons[] = $dir;
            }
        }
    }
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>JReviews Add-on Installer v<?php echo JR_CURRENT_VERSION; ?></title>
        <style>
            :root { --bg: #0f172a; --card: #1e293b; --text: #f8fafc; --muted: #94a3b8; --primary: #3b82f6; --primary-hover: #2563eb; --border: #334155; --success: #22c55e; --danger: #ef4444; --warning: #f59e0b; }
            * { box-sizing: border-box; margin: 0; padding: 0; font-family: system-ui, -apple-system, sans-serif; }
            body { background: var(--bg); color: var(--text); display: flex; align-items: center; justify-content: center; min-height: 100vh; padding: 20px; }
            .container { background: var(--card); border: 1px solid var(--border); border-radius: 12px; max-width: 640px; width: 100%; padding: 32px; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.5); }
            .header-flex { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 20px; }
            h1 { font-size: 1.5rem; color: #fff; }
            p.subtitle { color: var(--muted); font-size: 0.875rem; }
            .header-btns { display: flex; gap: 6px; flex-wrap: wrap; }
            .diag-btn { background: #334155; color: #fff; border: none; padding: 6px 10px; font-size: 0.8rem; border-radius: 4px; cursor: pointer; transition: background 0.2s; }
            .diag-btn:hover { background: #475569; }
            .diag-btn.danger { background: rgba(239, 68, 68, 0.2); color: #fca5a5; border: 1px solid var(--danger); }
            .diag-btn.danger:hover { background: var(--danger); color: #fff; }

            /* Terminate Drawer Panel */
            .terminate-box { background: rgba(239, 68, 68, 0.08); border: 1px solid var(--danger); border-radius: 8px; padding: 16px; margin-bottom: 20px; display: none; }
            .terminate-box h3 { color: var(--danger); font-size: 1rem; margin-bottom: 8px; }
            .terminate-box p { font-size: 0.825rem; color: var(--muted); margin-bottom: 12px; }
            .purge-options { display: flex; flex-direction: column; gap: 8px; margin-bottom: 14px; font-size: 0.85rem; }
            .purge-options label { display: flex; align-items: center; gap: 8px; cursor: pointer; color: #f8fafc; }

            .form-group { margin-bottom: 20px; }
            label { display: block; font-size: 0.875rem; font-weight: 500; margin-bottom: 8px; color: var(--muted); }
            input[type="text"], input[type="password"], select { width: 100%; padding: 12px; background: #0f172a; border: 1px solid var(--border); border-radius: 6px; color: #fff; font-size: 0.95rem; outline: none; transition: border 0.2s; }
            input:focus, select:focus { border-color: var(--primary); }
            .tabs { display: flex; gap: 6px; margin-bottom: 20px; border-bottom: 1px solid var(--border); padding-bottom: 12px; flex-wrap: wrap; }
            .tab-btn { background: none; border: none; color: var(--muted); font-weight: 600; padding: 8px 12px; cursor: pointer; border-radius: 4px; font-size: 0.85rem; }
            .tab-btn.active { background: var(--primary); color: #fff; }
            .tab-content { display: none; }
            .tab-content.active { display: block; }
            .drop-zone { border: 2px dashed var(--border); border-radius: 8px; padding: 30px; text-align: center; cursor: pointer; transition: all 0.2s; background: #0f172a; }
            .drop-zone:hover, .drop-zone.dragover { border-color: var(--primary); background: rgba(59, 130, 246, 0.05); }
            .drop-zone p { font-size: 0.9rem; color: var(--muted); }
            .drop-zone input { display: none; }
            .file-name { margin-top: 10px; font-weight: 600; color: var(--success); font-size: 0.85rem; }
            
            .checkbox-group { display: flex; align-items: center; gap: 10px; background: #0f172a; border: 1px solid var(--border); padding: 12px; border-radius: 6px; margin-bottom: 20px; }
            .checkbox-group input { width: 18px; height: 18px; cursor: pointer; }
            .checkbox-group label { margin-bottom: 0; cursor: pointer; color: #fff; }
            .backup-warning { background: rgba(245, 158, 11, 0.1); border: 1px solid var(--warning); color: var(--warning); border-radius: 6px; padding: 12px; font-size: 0.825rem; margin-bottom: 20px; display: none; line-height: 1.4; }

            .manifest-card { background: #0f172a; border: 1px solid var(--border); border-radius: 6px; padding: 14px; margin-top: 12px; font-size: 0.85rem; display: none; }
            .manifest-card h4 { color: var(--primary); font-size: 0.95rem; margin-bottom: 6px; }
            .manifest-card p { color: var(--muted); margin-bottom: 4px; }
            .manifest-card span.badge { background: #1e293b; padding: 2px 6px; border-radius: 4px; font-size: 0.75rem; color: #fff; border: 1px solid var(--border); }
            .update-banner { background: rgba(34, 197, 94, 0.1); border: 1px solid var(--success); color: var(--success); padding: 10px; border-radius: 6px; margin-bottom: 16px; font-size: 0.85rem; display: none; }

            .diag-results { background: #0f172a; border: 1px solid var(--border); border-radius: 6px; padding: 12px; margin-bottom: 20px; font-size: 0.85rem; display: none; }
            .diag-row { display: flex; justify-content: space-between; padding: 6px 0; border-bottom: 1px solid #1e293b; }
            .diag-row:last-child { border-bottom: none; }
            .status-ok { color: var(--success); font-weight: 600; }
            .status-fail { color: var(--danger); font-weight: 600; }

            .history-table { width: 100%; border-collapse: collapse; font-size: 0.825rem; }
            .history-table th, .history-table td { padding: 8px 12px; text-align: left; border-bottom: 1px solid var(--border); }
            .history-table th { color: var(--muted); font-weight: 600; background: #0f172a; }

            button.submit-btn { width: 100%; padding: 12px; background: var(--primary); border: none; border-radius: 6px; color: #fff; font-weight: 600; font-size: 1rem; cursor: pointer; transition: background 0.2s; display: flex; align-items: center; justify-content: center; gap: 8px; }
            button.submit-btn:hover { background: var(--primary-hover); }
            button.danger-btn { background: var(--danger); }
            button.danger-btn:hover { background: #dc2626; }
            .spinner { border: 2px solid rgba(255,255,255,0.3); border-top: 2px solid #fff; border-radius: 50%; width: 16px; height: 16px; animation: spin 0.8s linear infinite; display: none; }
            @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }

            .terminal { margin-top: 24px; background: #090d16; border: 1px solid var(--border); border-radius: 8px; padding: 16px; font-family: 'Courier New', Courier, monospace; font-size: 0.85rem; height: 240px; overflow-y: auto; display: none; color: #a3e635; box-shadow: inset 0 2px 6px rgba(0,0,0,0.8); }
            .terminal-line { margin-bottom: 4px; line-height: 1.4; word-break: break-all; }
            .terminal-line.fail { color: var(--danger); }
            .terminal-line.ok { color: var(--success); }
        </style>
    </head>
    <body>
        <div class="container">
            <div class="header-flex">
                <div>
                    <h1>JReviews Add-on Installer</h1>
                    <p class="subtitle">v<?php echo JR_CURRENT_VERSION; ?> — Maintenance Utility</p>
                </div>
                <div class="header-btns">
                    <button type="button" class="diag-btn" onclick="toggleUpdates()">Updates</button>
                    <button type="button" class="diag-btn" onclick="toggleDiagnostics()">Diagnostics</button>
                    <button type="button" class="diag-btn danger" onclick="toggleTerminateBox()">⚡ Terminate & Delete</button>
                </div>
            </div>

            <div class="terminate-box" id="terminateBox">
                <h3>⚡ Quick Purge & Self-Destruct Panel</h3>
                <p>Select what to clean up before closing or destroying this installer utility:</p>
                <div class="purge-options">
                    <label><input type="checkbox" id="purge_db_backups" checked> Drop all <code>jreviews_addons_bak_*</code> tables from DB</label>
                    <label><input type="checkbox" id="purge_history_log" checked> Delete local <code>install_history.json</code> file</label>
                    <label><input type="checkbox" id="purge_create_lock" onchange="handleMutualExclusive('lock')"> Create <code>jr_install.lock</code> to block future runs</label>
                    <label><input type="checkbox" id="purge_unlink_script" checked onchange="handleMutualExclusive('script')"> Permanently delete this <code>jr_install_addon.php</code> file</label>
                </div>
                <button type="button" class="submit-btn danger-btn" onclick="executeSelfDestruct()">Execute Selected Purge Actions</button>
            </div>

            <div class="update-banner" id="updateBanner"></div>
            <div class="diag-results" id="diagResults"></div>

            <form id="installForm" method="POST" enctype="multipart/form-data">
                <div class="form-group">
                    <label for="token">Security Token</label>
                    <input type="password" id="token" name="token" placeholder="Enter JR_ONETIME_TOKEN" value="<?php echo htmlspecialchars($_GET['token'] ?? ''); ?>" required onchange="fetchManifest()">
                </div>

                <div class="tabs">
                    <button type="button" class="tab-btn active" onclick="switchTab('scan', event)">Discovered</button>
                    <button type="button" class="tab-btn" onclick="switchTab('upload', event)">Upload ZIP</button>
                    <button type="button" class="tab-btn" onclick="switchTab('manual', event)">Manual</button>
                    <button type="button" class="tab-btn" onclick="switchTab('rollback', event)">Rollback / Backups</button>
                    <button type="button" class="tab-btn" onclick="switchTab('history', event)">History</button>
                </div>

                <div id="tab-scan" class="tab-content active">
                    <div class="form-group">
                        <label for="addon_select">Select Discovered Folder</label>
                        <select id="addon_select" name="addon_select" onchange="fetchManifest()">
                            <?php if (empty($scannedAddons)): ?>
                                <option value="">-- No uninstalled add-on folders found --</option>
                            <?php else: ?>
                                <?php foreach ($scannedAddons as $addon): ?>
                                    <option value="<?php echo htmlspecialchars($addon); ?>"><?php echo htmlspecialchars($addon); ?></option>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </select>
                    </div>
                </div>

                <div id="tab-upload" class="tab-content">
                    <div class="form-group">
                        <div class="drop-zone" id="dropZone" onclick="document.getElementById('zip_file').click()">
                            <p>Drag & drop your Add-on ZIP archive here or <strong>browse</strong></p>
                            <input type="file" id="zip_file" name="zip_file" accept=".zip">
                            <div class="file-name" id="fileName"></div>
                        </div>
                    </div>
                </div>

                <div id="tab-manual" class="tab-content">
                    <div class="form-group">
                        <label for="addon_manual">Add-on Directory Name</label>
                        <input type="text" id="addon_manual" name="addon_manual" placeholder="e.g. addons_manager">
                    </div>
                </div>

                <div id="tab-rollback" class="tab-content">
                    <div class="form-group">
                        <label for="backup_select">Available DB Backups (`jreviews_addons`)</label>
                        <select id="backup_select" name="backup_select">
                            <option value="">-- Click refresh to load backups --</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="rollback_addon_name">Add-on Folder to Clean on Rollback (optional)</label>
                        <input type="text" id="rollback_addon_name" placeholder="e.g. my_broken_addon">
                    </div>
                    <div style="display: flex; gap: 10px;">
                        <button type="button" class="submit-btn danger-btn" onclick="executeRollback()" style="flex: 2;">Execute Rollback</button>
                        <button type="button" class="submit-btn" onclick="purgeAllBackups()" style="flex: 1; background: #475569;">Purge All DB Backups</button>
                    </div>
                </div>

                <div id="tab-history" class="tab-content">
                    <div id="historyContainer"><p style="color:var(--muted); font-size: 0.85rem;">Click tab to load history...</p></div>
                </div>

                <div class="manifest-card" id="manifestCard"></div>

                <div id="installControls">
                    <div class="form-group" style="margin-top: 20px;">
                        <div class="checkbox-group">
                            <input type="checkbox" id="create_backup" name="create_backup" value="1" onchange="toggleBackupWarning()">
                            <label for="create_backup">Create DB Backup before install</label>
                        </div>
                    </div>

                    <div class="backup-warning" id="backupWarning">
                        ⚠️ <strong>Backup Option Enabled:</strong> A snapshot of your <code>jreviews_addons</code> table will be created prior to installation. DO NOT close or refresh this page until execution completes. Script auto-deletion is automatically set to "Keep script as is".
                    </div>

                    <div class="form-group">
                        <label for="post_action">Post-Installation Action</label>
                        <select id="post_action" name="post_action">
                            <option value="delete" <?php echo JR_POST_INSTALL === 'delete' ? 'selected' : ''; ?>>Delete installer script automatically</option>
                            <option value="reset" <?php echo JR_POST_INSTALL === 'reset' ? 'selected' : ''; ?>>Create .lock file & keep script</option>
                            <option value="keep" <?php echo JR_POST_INSTALL === 'keep' ? 'selected' : ''; ?>>Keep script as is</option>
                        </select>
                    </div>

                    <button type="submit" class="submit-btn" id="submitBtn">
                        <span class="spinner" id="btnSpinner"></span>
                        <span id="btnText">Install Add-on</span>
                    </button>
                </div>
            </form>

            <div class="terminal" id="terminal">
                <div id="terminalContent"></div>
            </div>
        </div>

        <script>
            let currentTab = 'scan';

            function toggleTerminateBox() {
                const box = document.getElementById('terminateBox');
                box.style.display = (box.style.display === 'block') ? 'none' : 'block';
            }

            function handleMutualExclusive(source) {
                const lockCb = document.getElementById('purge_create_lock');
                const scriptCb = document.getElementById('purge_unlink_script');
                if (source === 'lock' && lockCb.checked) {
                    scriptCb.checked = false;
                } else if (source === 'script' && scriptCb.checked) {
                    lockCb.checked = false;
                }
            }

            function switchTab(tabName, evt) {
                currentTab = tabName;
                document.querySelectorAll('.tab-btn').forEach(btn => btn.classList.remove('active'));
                document.querySelectorAll('.tab-content').forEach(content => content.classList.remove('active'));
                
                if (evt && evt.target) {
                    evt.target.classList.add('active');
                }
                document.getElementById('tab-' + tabName).classList.add('active');

                document.getElementById('installControls').style.display = (tabName === 'rollback') ? 'none' : 'block';

                if (tabName === 'scan') fetchManifest();
                if (tabName === 'history') fetchHistory();
                if (tabName === 'rollback') fetchBackups();
            }

            function toggleBackupWarning() {
                const isChecked = document.getElementById('create_backup').checked;
                document.getElementById('backupWarning').style.display = isChecked ? 'block' : 'none';
                if (isChecked) document.getElementById('post_action').value = 'keep';
            }

            async function executeSelfDestruct() {
                const token = document.getElementById('token').value;
                if (!token) { alert('Enter Security Token first.'); return; }

                if (!confirm('Are you sure you want to execute the selected purge and self-destruct actions?')) return;

                const formData = new FormData();
                formData.append('del_backups', document.getElementById('purge_db_backups').checked ? '1' : '');
                formData.append('del_history', document.getElementById('purge_history_log').checked ? '1' : '');
                formData.append('create_lock', document.getElementById('purge_create_lock').checked ? '1' : '');
                formData.append('del_script', document.getElementById('purge_unlink_script').checked ? '1' : '');

                const term = document.getElementById('terminal');
                const content = document.getElementById('terminalContent');
                term.style.display = 'block';
                content.innerHTML = '<div class="terminal-line">Executing self-destruct sequence...</div>';
                term.scrollIntoView({ behavior: 'smooth' });

                try {
                    const res = await fetch(`jr_install_addon.php?action=terminate_self&token=${encodeURIComponent(token)}`, {
                        method: 'POST',
                        body: formData
                    });
                    
                    const rawText = await res.text();
                    if (!rawText.trim()) {
                        throw new Error('Server returned empty response (script might have self-deleted instantly).');
                    }

                    if (rawText.trim().startsWith('<') || rawText.includes('<!DOCTYPE') || rawText.includes('<br')) {
                        throw new Error('Server returned HTML error page instead of JSON. Check PHP error logs.');
                    }

                    const data = JSON.parse(rawText);
                    if (data.success && data.logs) {
                        data.logs.forEach(msg => {
                            content.innerHTML += `<div class="terminal-line ok">[ OK ] ${msg}</div>`;
                        });
                        if (data.script_deleted) {
                            content.innerHTML += `<div class="terminal-line fail">[TERMINATED] Script file deleted. Access revoked.</div>`;
                            document.getElementById('installForm').style.opacity = '0.3';
                            document.getElementById('installForm').style.pointerEvents = 'none';
                        }
                    } else {
                        content.innerHTML += `<div class="terminal-line fail">[FAIL] ${data.message || 'Unknown error'}</div>`;
                    }
                } catch (e) {
                    content.innerHTML += `<div class="terminal-line fail">[FAIL] Execution error: ${e.message}</div>`;
                }
                term.scrollTop = term.scrollHeight;
            }

            async function purgeAllBackups() {
                const token = document.getElementById('token').value;
                if (!token) { alert('Enter Security Token first.'); return; }
                if (!confirm('Drop ALL jreviews_addons_bak_* tables from the database?')) return;

                try {
                    const res = await fetch(`jr_install_addon.php?action=purge_all_backups&token=${encodeURIComponent(token)}`);
                    const data = await res.json();
                    alert(data.message);
                    fetchBackups();
                } catch (e) {
                    alert('Error purging backups: ' + e.message);
                }
            }

            async function fetchBackups() {
                const token = document.getElementById('token').value;
                const select = document.getElementById('backup_select');
                if (!token) { select.innerHTML = '<option value="">Enter Security Token first</option>'; return; }

                try {
                    const res = await fetch(`jr_install_addon.php?action=get_backups&token=${encodeURIComponent(token)}`);
                    const data = await res.json();
                    if (data.success && data.backups) {
                        if (!data.backups.length) {
                            select.innerHTML = '<option value="">-- No database backups found --</option>';
                            return;
                        }
                        let html = '';
                        data.backups.forEach(b => { html += `<option value="${b}">${b}</option>`; });
                        select.innerHTML = html;
                    }
                } catch (e) { select.innerHTML = '<option value="">Error loading backups</option>'; }
            }

            async function executeRollback() {
                const token = document.getElementById('token').value;
                const table = document.getElementById('backup_select').value;
                const addon = document.getElementById('rollback_addon_name').value.trim();

                if (!token || !table) { alert('Select security token and backup table.'); return; }
                if (!confirm(`Restore database from "${table}" and clean folder "${addon || 'none'}"?`)) return;

                const term = document.getElementById('terminal');
                const content = document.getElementById('terminalContent');
                term.style.display = 'block';
                content.innerHTML = '<div class="terminal-line">Executing rollback...</div>';
                term.scrollIntoView({ behavior: 'smooth' });

                try {
                    const res = await fetch(`jr_install_addon.php?action=do_rollback&token=${encodeURIComponent(token)}&table=${encodeURIComponent(table)}&addon=${encodeURIComponent(addon)}`);
                    const data = await res.json();
                    content.innerHTML += `<div class="terminal-line ${data.success ? 'ok' : 'fail'}">${data.success ? '[ OK ]' : '[FAIL]'} ${data.message}</div>`;
                    if (data.success) fetchBackups();
                } catch (e) {
                    content.innerHTML += `<div class="terminal-line fail">[FAIL] Network error during rollback: ${e.message}</div>`;
                }
                term.scrollTop = term.scrollHeight;
            }

            async function toggleUpdates() {
                const banner = document.getElementById('updateBanner');
                // Если блок уже виден — скрываем его при повторном клике
                if (banner.style.display === 'block') {
                    banner.style.display = 'none';
                    return;
                }

                const token = document.getElementById('token').value;
                if (!token) { alert('Enter Security Token first.'); return; }
                
                banner.style.display = 'block';
                banner.textContent = 'Checking updates...';
                try {
                    const res = await fetch(`jr_install_addon.php?action=check_updates&token=${encodeURIComponent(token)}`);
                    const data = await res.json();
                    if (data.success) {
                        banner.innerHTML = data.has_update ? `🚀 <strong>New Version (${data.latest_version}):</strong> <a href="${data.download_url}" target="_blank" style="color:#fff;">Download from GitHub</a>` : `✅ You are on the latest version.`;
                    }
                } catch (e) { banner.textContent = 'Update check failed.'; }
            }

            async function toggleDiagnostics() {
                const box = document.getElementById('diagResults');
                // Если блок уже виден — скрываем его при повторном клике
                if (box.style.display === 'block') {
                    box.style.display = 'none';
                    return;
                }

                const token = document.getElementById('token').value;
                if (!token) { alert('Enter Token first.'); return; }
                
                box.style.display = 'block';
                box.innerHTML = '<div class="diag-row"><span>Running diagnostics...</span></div>';
                try {
                    const res = await fetch(`jr_install_addon.php?action=diagnostics&token=${encodeURIComponent(token)}`);
                    const data = await res.json();
                    if (data.success && data.checks) {
                        let html = '';
                        for (const k in data.checks) {
                            const c = data.checks[k];
                            html += `<div class="diag-row"><span>${c.title}</span><span class="${c.ok ? 'status-ok' : 'status-fail'}">${c.ok ? 'PASS' : 'FAIL'}</span></div>`;
                        }
                        box.innerHTML = html;
                    }
                } catch (e) { box.innerHTML = '<div class="diag-row"><span>Diagnostics failed.</span></div>'; }
            }

            async function fetchHistory() {
                const token = document.getElementById('token').value;
                const container = document.getElementById('historyContainer');
                if (!token) { container.innerHTML = '<p style="color:var(--danger)">Enter Security Token.</p>'; return; }
                const res = await fetch(`jr_install_addon.php?action=get_history&token=${encodeURIComponent(token)}`);
                const data = await res.json();
                if (data.success && data.history) {
                    let html = '<table class="history-table"><thead><tr><th>Time</th><th>Add-on</th><th>Status</th></tr></thead><tbody>';
                    data.history.forEach(row => {
                        html += `<tr><td>${row.timestamp}</td><td><strong>${row.addon}</strong></td><td style="color:${row.status === 'SUCCESS' ? 'var(--success)' : 'var(--danger)'}">${row.status}</td></tr>`;
                    });
                    container.innerHTML = html + '</tbody></table>';
                }
            }

            async function fetchManifest() {
                if (currentTab !== 'scan') return;
                const token = document.getElementById('token').value;
                const addonSelect = document.getElementById('addon_select');
                const card = document.getElementById('manifestCard');
                if (!token || !addonSelect || !addonSelect.value) { card.style.display = 'none'; return; }
                const res = await fetch(`jr_install_addon.php?action=get_manifest&token=${encodeURIComponent(token)}&addon_name=${encodeURIComponent(addonSelect.value)}`);
                const data = await res.json();
                if (data.success && data.manifest) {
                    const m = data.manifest;
                    card.innerHTML = `<h4>${m.title} <span class="badge">v${m.version}</span></h4><p>${m.description}</p>`;
                    card.style.display = 'block';
                }
            }

            const dropZone = document.getElementById('dropZone');
            const fileInput = document.getElementById('zip_file');
            const fileNameDiv = document.getElementById('fileName');
            fileInput.addEventListener('change', () => { if (fileInput.files.length) fileNameDiv.textContent = 'Selected: ' + fileInput.files[0].name; });

            function appendTerminalLine(text) {
                const term = document.getElementById('terminal');
                const content = document.getElementById('terminalContent');
                term.style.display = 'block';
                const div = document.createElement('div');
                div.className = 'terminal-line ' + (text.includes('[FAIL]') ? 'fail' : (text.includes('[ OK ]') ? 'ok' : ''));
                div.textContent = text;
                content.appendChild(div);
                term.scrollTop = term.scrollHeight;
            }

            document.getElementById('installForm').addEventListener('submit', async function(e) {
                e.preventDefault();
                document.getElementById('terminalContent').innerHTML = '';
                
                // Автопрокрутка к терминалу при старте установки
                const term = document.getElementById('terminal');
                term.style.display = 'block';
                term.scrollIntoView({ behavior: 'smooth' });

                const btn = document.getElementById('submitBtn');
                btn.disabled = true;
                const formData = new FormData(this);
                formData.set('addon', currentTab === 'scan' ? document.getElementById('addon_select').value : document.getElementById('addon_manual').value);
                formData.append('tab_mode', currentTab);
                try {
                    const res = await fetch('jr_install_addon.php', { method: 'POST', body: formData });
                    const text = await res.text();
                    text.split('\n').forEach(line => { if (line.trim()) appendTerminalLine(line); });
                } catch (err) {
                    appendTerminalLine('[FAIL] Network error: ' + err.message);
                } finally {
                    btn.disabled = false;
                }
            });
        </script>
    </body>
    </html>
    <?php
    exit;
}

// Processing installation token validation
if (!hash_equals(JR_ONETIME_TOKEN, $providedToken)) {
    jr_log_security('Unauthorized access attempt.');
    jr_stop('Forbidden: Invalid security token.', 403);
}

if (!headers_sent() && !jr_is_json_request()) {
    header('Content-Type: text/plain; charset=utf-8');
    header('X-Robots-Tag: noindex');
}

$tabMode      = (string) ($_POST['tab_mode'] ?? '');
$addon        = (string) ($_POST['addon'] ?? $_POST['addon_manual'] ?? $_POST['addon_select'] ?? $_GET['addon'] ?? '');
$postAction   = (string) ($_POST['post_action'] ?? JR_POST_INSTALL);
$createBackup = !empty($_POST['create_backup']);

// ZIP upload processing
if ($tabMode === 'upload' || !empty($_FILES['zip_file']['tmp_name'])) {
    if (empty($_FILES['zip_file']['tmp_name']) || $_FILES['zip_file']['error'] !== UPLOAD_ERR_OK) {
        jr_stop('File upload error.');
    }
    $zip = new ZipArchive();
    if ($zip->open($_FILES['zip_file']['tmp_name']) !== true) {
        jr_stop('Failed to open uploaded ZIP file.');
    }
    $targetDir = __DIR__ . '/components/com_jreviews_addons';
    if (!is_dir($targetDir)) mkdir($targetDir, 0755, true);

    $addonName = '';
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $filename = $zip->getNameIndex($i);
        if (pathinfo($filename, PATHINFO_EXTENSION) === 'xml') {
            $addonName = pathinfo($filename, PATHINFO_FILENAME);
            break;
        }
    }
    if (!$addonName) { $zip->close(); jr_stop('Manifest XML not found in ZIP.'); }
    $zip->extractTo($targetDir);
    $zip->close();
    $addon = $addonName;
}

if (!preg_match('/^[a-z0-9_]{2,60}$/', $addon)) {
    jr_stop('Invalid addon name format.');
}

define('_JEXEC', 1);
define('JPATH_BASE', __DIR__);
require_once JPATH_BASE . '/includes/defines.php';
require_once JPATH_BASE . '/includes/framework.php';

while (ob_get_level()) {
    ob_end_clean();
}
ob_start();

try {
    $container = \Joomla\CMS\Factory::getContainer();
    $container->alias('session.web', 'session.web.site')
        ->alias('session', 'session.web.site')
        ->alias('JSession', 'session.web.site')
        ->alias(\Joomla\CMS\Session\Session::class, 'session.web.site')
        ->alias(\Joomla\Session\SessionInterface::class, 'session.web.site');
    $app = $container->get(\Joomla\CMS\Application\SiteApplication::class);
    \Joomla\CMS\Factory::$application = $app;

    jr_out('Joomla ' . JVERSION . ' loaded');

    // Create Backup if requested
    if ($createBackup) {
        $db = \Joomla\CMS\Factory::getContainer()->get('DatabaseDriver');
        $prefix = $db->getPrefix();
        $tableName = $prefix . 'jreviews_addons';
        $backupTable = $prefix . 'jreviews_addons_bak_' . date('Ymd_His');

        $db->setQuery('CREATE TABLE ' . $db->quoteName($backupTable) . ' LIKE ' . $db->quoteName($tableName))->execute();
        $db->setQuery('INSERT INTO ' . $db->quoteName($backupTable) . ' SELECT * FROM ' . $db->quoteName($tableName))->execute();

        jr_out("DB Backup created: $backupTable");
        $postAction = 'keep';
    }

    $addonDir = JPATH_ROOT . '/components/com_jreviews_addons/' . $addon;
    if (!is_dir($addonDir)) throw new RuntimeException("Add-on directory missing: $addonDir");

    require_once JPATH_ROOT . '/components/com_jreviews/jreviews/framework.php';
    \JReviews\App\Actions\InstallAddonAction::fromDirectory($addonDir);
    jr_out('InstallAddonAction::fromDirectory() executed successfully');

    $row = \JReviews\App\Models\Addon::where('name', $addon)->first();
    if (!$row) throw new RuntimeException('DB Record missing in jreviews_addons table.');

    jr_out(sprintf('jreviews_addons status: id=%d name=%s state=%d version=%s', $row->id, $row->name, $row->state, $row->version));
    jr_save_history($addon, 'SUCCESS', 'Version: ' . $row->version);

    echo ob_get_clean();

    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    } else {
        @ob_flush();
        @flush();
    }

    if ($postAction === 'delete') {
        @unlink(__FILE__);
    } elseif ($postAction === 'reset') {
        @file_put_contents(__DIR__ . '/jr_install.lock', date('Y-m-d H:i:s'));
    }
} catch (\Throwable $e) {
    jr_save_history($addon, 'FAILED', $e->getMessage());
    echo ob_get_clean();
    jr_out($e->getMessage(), false);
    exit;
}
