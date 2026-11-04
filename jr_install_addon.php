<?php
/**
 * ONE-TIME Installer for JReviews 5.x Add-ons.
 *
 * Supports directory auto-scanning, ZIP archive upload via UI, CLI execution,
 * and direct query execution.
 *
 * SAFETY WARNING: Run on test environments first!
 * 
 * USAGE:
 *   1. Upload this file to your Joomla root directory (alongside configuration.php).
 *   2. Set JR_ONETIME_TOKEN below to a secure, random string (min 16 chars).
 *   3. Open https://YOUR-SITE/jr_install_addon.php in your browser.
 *   4. Select an existing uninstalled folder or upload a ZIP package.
 *   5. Upon success, the script automatically self-deletes (if JR_SELF_DELETE is true).
 */

// ------------------------- CONFIGURATION -------------------------
const JR_ONETIME_TOKEN = 'CHANGE_THIS_TO_A_LONG_RANDOM_SECRET_KEY';
const JR_SELF_DELETE   = true;
// -----------------------------------------------------------------

ini_set('display_errors', '1');
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

// Handle CLI execution mode
if (PHP_SAPI === 'cli') {
    parse_str(implode('&', array_slice($argv, 1)), $_GET);
}

function jr_is_json_request(): bool
{
    return isset($_SERVER['HTTP_ACCEPT']) && str_contains($_SERVER['HTTP_ACCEPT'], 'application/json');
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

// Security Check: Token validation
if (JR_ONETIME_TOKEN === 'CHANGE_THIS_TO_A_LONG_RANDOM_SECRET_KEY' || strlen(JR_ONETIME_TOKEN) < 16) {
    jr_stop('Security key error: Set a secure JR_ONETIME_TOKEN in the file (minimum 16 characters).', 403);
}

$providedToken = (string) ($_POST['token'] ?? $_GET['token'] ?? '');

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
        <title>JReviews Add-on Installer</title>
        <style>
            :root { --bg: #0f172a; --card: #1e293b; --text: #f8fafc; --muted: #94a3b8; --primary: #3b82f6; --primary-hover: #2563eb; --border: #334155; --success: #22c55e; --danger: #ef4444; }
            * { box-sizing: border-box; margin: 0; padding: 0; font-family: system-ui, -apple-system, sans-serif; }
            body { background: var(--bg); color: var(--text); display: flex; align-items: center; justify-content: center; min-height: 100vh; padding: 20px; }
            .container { background: var(--card); border: 1px solid var(--border); border-radius: 12px; max-width: 600px; width: 100%; padding: 32px; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.5); }
            h1 { font-size: 1.5rem; margin-bottom: 8px; color: #fff; }
            p.subtitle { color: var(--muted); font-size: 0.875rem; margin-bottom: 24px; }
            .form-group { margin-bottom: 20px; }
            label { display: block; font-size: 0.875rem; font-weight: 500; margin-bottom: 8px; color: var(--muted); }
            input[type="text"], input[type="password"], select { width: 100%; padding: 12px; background: #0f172a; border: 1px solid var(--border); border-radius: 6px; color: #fff; font-size: 0.95rem; outline: none; transition: border 0.2s; }
            input:focus, select:focus { border-color: var(--primary); }
            .tabs { display: flex; gap: 8px; margin-bottom: 20px; border-bottom: 1px solid var(--border); padding-bottom: 12px; }
            .tab-btn { background: none; border: none; color: var(--muted); font-weight: 600; padding: 8px 12px; cursor: pointer; border-radius: 4px; }
            .tab-btn.active { background: var(--primary); color: #fff; }
            .tab-content { display: none; }
            .tab-content.active { display: block; }
            .drop-zone { border: 2px dashed var(--border); border-radius: 8px; padding: 30px; text-align: center; cursor: pointer; transition: all 0.2s; background: #0f172a; }
            .drop-zone:hover, .drop-zone.dragover { border-color: var(--primary); background: rgba(59, 130, 246, 0.05); }
            .drop-zone p { font-size: 0.9rem; color: var(--muted); }
            .drop-zone input { display: none; }
            .file-name { margin-top: 10px; font-weight: 600; color: var(--success); font-size: 0.85rem; }
            button.submit-btn { width: 100%; padding: 12px; background: var(--primary); border: none; border-radius: 6px; color: #fff; font-weight: 600; font-size: 1rem; cursor: pointer; transition: background 0.2s; }
            button.submit-btn:hover { background: var(--primary-hover); }
            #output { margin-top: 24px; background: #090d16; border: 1px solid var(--border); border-radius: 6px; padding: 16px; font-family: monospace; font-size: 0.85rem; max-height: 250px; overflow-y: auto; display: none; white-space: pre-wrap; }
        </style>
    </head>
    <body>
        <div class="container">
            <h1>JReviews Add-on Installer</h1>
            <p class="subtitle">Quick installer for JReviews 5.x extensions</p>

            <form id="installForm" method="POST" enctype="multipart/form-data">
                <div class="form-group">
                    <label for="token">Security Token</label>
                    <input type="password" id="token" name="token" placeholder="Enter JR_ONETIME_TOKEN" value="<?php echo htmlspecialchars($_GET['token'] ?? ''); ?>" required>
                </div>

                <div class="tabs">
                    <button type="button" class="tab-btn active" onclick="switchTab('scan')">Discovered Add-ons</button>
                    <button type="button" class="tab-btn" onclick="switchTab('upload')">Upload ZIP Package</button>
                    <button type="button" class="tab-btn" onclick="switchTab('manual')">Manual Entry</button>
                </div>

                <div id="tab-scan" class="tab-content active">
                    <div class="form-group">
                        <label for="addon_select">Select Discovered Folder</label>
                        <select id="addon_select" name="addon_select">
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

                <button type="submit" class="submit-btn" id="submitBtn">Install Add-on</button>
            </form>

            <div id="output"></div>
        </div>

        <script>
            let currentTab = 'scan';

            function switchTab(tabName) {
                currentTab = tabName;
                document.querySelectorAll('.tab-btn').forEach(btn => btn.classList.remove('active'));
                document.querySelectorAll('.tab-content').forEach(content => content.classList.remove('active'));
                
                event.target.classList.add('active');
                document.getElementById('tab-' + tabName).classList.add('active');
            }

            const dropZone = document.getElementById('dropZone');
            const fileInput = document.getElementById('zip_file');
            const fileNameDiv = document.getElementById('fileName');

            ['dragenter', 'dragover'].forEach(eventName => {
                dropZone.addEventListener(eventName, (e) => { e.preventDefault(); dropZone.classList.add('dragover'); });
            });
            ['dragleave', 'drop'].forEach(eventName => {
                dropZone.addEventListener(eventName, (e) => { e.preventDefault(); dropZone.classList.remove('dragover'); });
            });
            dropZone.addEventListener('drop', (e) => {
                if (e.dataTransfer.files.length) {
                    fileInput.files = e.dataTransfer.files;
                    updateFileName();
                }
            });
            fileInput.addEventListener('change', updateFileName);

            function updateFileName() {
                if (fileInput.files.length) {
                    fileNameDiv.textContent = 'Selected: ' + fileInput.files[0].name;
                }
            }

            document.getElementById('installForm').addEventListener('submit', async function(e) {
                e.preventDefault();
                const output = document.getElementById('output');
                const btn = document.getElementById('submitBtn');
                
                output.style.display = 'block';
                output.textContent = 'Starting installation process...\n';
                btn.disabled = true;

                const formData = new FormData(this);
                formData.append('tab_mode', currentTab);

                try {
                    const response = await fetch('jr_install_addon.php', {
                        method: 'POST',
                        headers: { 'Accept': 'text/plain' },
                        body: formData
                    });
                    const text = await response.text();
                    output.textContent = text;
                } catch (err) {
                    output.textContent += '\n[FAIL] Network error or server crashed: ' + err.message;
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

// Verify token on processing
if (!hash_equals(JR_ONETIME_TOKEN, $providedToken)) {
    jr_stop('Forbidden: Invalid security token.', 403);
}

// Enable response header formatting for non-JSON text stream
if (!headers_sent() && !jr_is_json_request()) {
    header('Content-Type: text/plain; charset=utf-8');
    header('X-Robots-Tag: noindex');
}

$tabMode = (string) ($_POST['tab_mode'] ?? '');
$addon   = (string) ($_POST['addon_manual'] ?? $_POST['addon_select'] ?? $_GET['addon'] ?? '');

// ---------------------- ZIP Upload Extraction ----------------------
if ($tabMode === 'upload' || !empty($_FILES['zip_file']['tmp_name'])) {
    if (empty($_FILES['zip_file']['tmp_name']) || $_FILES['zip_file']['error'] !== UPLOAD_ERR_OK) {
        jr_stop('File upload error. Please select a valid ZIP package.');
    }

    if (!class_exists('ZipArchive')) {
        jr_stop('PHP ZipArchive extension is missing on your server.');
    }

    $zip = new ZipArchive();
    if ($zip->open($_FILES['zip_file']['tmp_name']) !== true) {
        jr_stop('Failed to open uploaded ZIP file.');
    }

    $targetDir = __DIR__ . '/components/com_jreviews_addons';
    if (!is_dir($targetDir) && !mkdir($targetDir, 0755, true)) {
        jr_stop('Failed to create target components/com_jreviews_addons directory.');
    }

    // Identify extension folder name inside archive by seeking XML file
    $addonName = '';
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $filename = $zip->getNameIndex($i);
        if (pathinfo($filename, PATHINFO_EXTENSION) === 'xml') {
            $baseName = pathinfo($filename, PATHINFO_FILENAME);
            if (str_contains($filename, '/')) {
                $parts = explode('/', trim($filename, '/'));
                if (count($parts) === 2 && $parts[0] === $baseName) {
                    $addonName = $baseName;
                    break;
                }
            } else {
                $addonName = $baseName;
                break;
            }
        }
    }

    if (empty($addonName)) {
        $zip->close();
        jr_stop('Could not locate a valid add-on manifest XML in the ZIP root or subfolder.');
    }

    if (!$zip->extractTo($targetDir)) {
        $zip->close();
        jr_stop('Failed to extract ZIP archive into components/com_jreviews_addons/. Check directory permissions.');
    }
    $zip->close();

    $addon = $addonName;
    jr_out('Archive extracted successfully into com_jreviews_addons/' . $addon);
}

if (!preg_match('/^[a-z0-9_]{2,60}$/', $addon)) {
    jr_stop('Parameter "addon" must consist of lowercase Latin letters, numbers, and underscores.');
}

// ---------------------- 1. Bootstrap Joomla ----------------------
define('_JEXEC', 1);
define('JPATH_BASE', __DIR__);

if (!is_file(JPATH_BASE . '/includes/defines.php') || !is_file(JPATH_BASE . '/includes/framework.php')) {
    jr_stop('This installer script must reside in your Joomla root directory.');
}

ob_start();

try {
    require_once JPATH_BASE . '/includes/defines.php';
    require_once JPATH_BASE . '/includes/framework.php';

    $container = \Joomla\CMS\Factory::getContainer();

    $container->alias('session.web', 'session.web.site')
        ->alias('session', 'session.web.site')
        ->alias('JSession', 'session.web.site')
        ->alias(\Joomla\CMS\Session\Session::class, 'session.web.site')
        ->alias(\Joomla\Session\Session::class, 'session.web.site')
        ->alias(\Joomla\Session\SessionInterface::class, 'session.web.site');

    $app = $container->get(\Joomla\CMS\Application\SiteApplication::class);
    \Joomla\CMS\Factory::$application = $app;

    if (is_callable([$app, 'createExtensionNamespaceMap'])) {
        $app->createExtensionNamespaceMap();
    }

    jr_out('Joomla ' . JVERSION . ' loaded');

    // ---------------------- 2. Validate Add-on Directory ----------------------
    $addonsDir = JPATH_ROOT . '/components/com_jreviews_addons';
    $addonDir  = $addonsDir . '/' . $addon;
    $xmlFile   = $addonDir . '/' . $addon . '.xml';

    if (!is_dir($addonDir)) {
        throw new RuntimeException("Add-on directory missing: $addonDir");
    }

    if (!is_file($xmlFile)) {
        throw new RuntimeException("Manifest file missing: $xmlFile");
    }

    $xml = simplexml_load_file($xmlFile, 'SimpleXMLElement', LIBXML_NOCDATA);

    if ($xml === false) {
        throw new RuntimeException('Manifest file cannot be parsed as valid XML.');
    }

    if ((string) $xml->name !== $addon) {
        throw new RuntimeException('Manifest <name>' . (string) $xml->name . '</name> does not match folder name "' . $addon . '".');
    }

    jr_out('Manifest: ' . (string) $xml->title . ' v' . (string) $xml->version);

    if ((string) $xml->required_jreviews_version === '') {
        jr_out('Warning: <required_jreviews_version> omitted in manifest — core might label add-on as Outdated', false);
    }

    // ---------------------- 3. Bootstrap JReviews ----------------------
    $framework = JPATH_ROOT . '/components/com_jreviews/jreviews/framework.php';

    if (!is_file($framework)) {
        throw new RuntimeException('com_jreviews framework not found. Ensure s2framework and JReviews are installed.');
    }

    require_once $framework;

    $actionClass = '\\JReviews\\App\\Actions\\InstallAddonAction';

    if (!class_exists($actionClass)) {
        throw new RuntimeException('InstallAddonAction class missing. Verify s2framework and JReviews plugins are enabled.');
    }

    jr_out('JReviews framework loaded successfully');

    // ---------------------- 4. Execute Installation ----------------------
    $actionClass::fromDirectory($addonDir);

    jr_out('InstallAddonAction::fromDirectory() executed successfully');

    // Clear Joomla System Cache
    try {
        if (class_exists('\\Joomla\\CMS\\Factory')) {
            $cache = \Joomla\CMS\Factory::getContainer()->get(\Joomla\CMS\Cache\CacheControllerFactoryInterface::class)->createCacheController('output');
            $cache->clean();
            jr_out('Joomla system cache cleared');
        }
    } catch (\Throwable $cacheEx) {
        // Cache cleanup error non-fatal
    }

    // ---------------------- 5. Verification ----------------------
    $row = \JReviews\App\Models\Addon::where('name', $addon)->first();

    if (!$row) {
        throw new RuntimeException('DB Record missing in jreviews_addons table.');
    }

    jr_out(sprintf('jreviews_addons status: id=%d name=%s state=%d version=%s', $row->id, $row->name, $row->state, $row->version));

    if ((int) $row->state !== 1) {
        jr_out('Warning: State is not equal to 1 — add-on is inactive!', false);
    }

    $deleted = false;
    if (JR_SELF_DELETE) {
        $deleted = @unlink(__FILE__);
    }

    jr_out($deleted ? 'Installer script successfully self-deleted.' : 'Please remove jr_install_addon.php manually from your server!', $deleted);
    jr_out('Success! Open the JReviews Control Panel and refresh the page (Ctrl+F5).');
} catch (\Throwable $e) {
    $log = ob_get_clean();
    echo $log;
    jr_out(get_class($e) . ': ' . $e->getMessage(), false);
    echo '  Trace: ' . $e->getFile() . ':' . $e->getLine() . "\n";
    echo "Script WAS NOT self-deleted. Please review errors and remove the file manually.\n";
    exit;
}

echo ob_get_clean();