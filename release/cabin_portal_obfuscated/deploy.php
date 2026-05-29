<?php
/**
 * AeroFind Automated Secure PHP Deployer
 * Bypasses FTP/SSH firewalls by fetching directly from GitHub.
 */

// 1. SECURITY TOKEN: Change this to a secure random password to protect this script
define('DEPLOY_TOKEN', 'aerofind');

// 2. GITHUB PERSONAL ACCESS TOKEN (PAT)
// If your repository is PRIVATE, you MUST generate a classic or fine-grained GitHub PAT
// with "repo" (read) permissions and paste it here. Leave empty if the repository is PUBLIC.
define('GITHUB_PAT', '');
define('RELEASE_INSTALL_DIR', 'release/cabin_portal_obfuscated');
define('RELEASE_ARCHIVE_PATH', 'release/cabin_portal_obfuscated.zip');

// Validate request
if (!isset($_GET['token']) || $_GET['token'] !== DEPLOY_TOKEN) {
    header('HTTP/1.0 403 Forbidden');
    echo "<style>
        body {
            background-color: #090d16;
            color: #f43f5e;
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, 'Liberation Mono', 'Courier New', monospace;
            font-size: 11px;
            font-weight: 700;
            padding: 16px;
            margin: 0;
        }
    </style>";
    echo "Access Denied: Invalid Security Token.";
    exit;
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 2.5 SIMULATION MODE
if (isset($_GET['simulate']) || !empty($_SESSION['simulate_update'])) {
    $_SESSION['simulated_version'] = '1.8.6';
    echo "<style>
        body {
            background-color: #090d16;
            color: #cbd5e1;
            font-family: 'Fira Code', 'JetBrains Mono', 'Courier New', Courier, monospace;
            font-size: 11px;
            line-height: 1.6;
            padding: 16px;
            margin: 0;
        }
        h2 {
            color: #10b981;
            font-size: 13px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.1em;
            margin-top: 0;
            margin-bottom: 12px;
            border-bottom: 1px solid #1e293b;
            padding-bottom: 8px;
        }
        h3 {
            color: #10b981;
            font-size: 12px;
            font-weight: 800;
            margin-top: 16px;
        }
        .text-error {
            color: #f43f5e;
        }
        .text-success {
            color: #10b981;
        }
        .text-info {
            color: #38bdf8;
        }
    </style>";
    echo "<h2>AeroFind Platform Deployer (Simulation Mode)</h2>";
    echo "<span class='text-info'>[Info] Initializing dry-run simulation of AeroFind Platform Updates...</span><br>";
    flush();
    ob_flush();
    usleep(600000);
    echo "Connecting to GitHub API v3 (exeedthemes/cabin_portal)...<br>";
    flush();
    ob_flush();
    usleep(400000);
    echo "Downloading package archive: <code>cabin-portal-archive-v1.8.6.zip</code> (24.8 MB)<br>";
    flush();
    ob_flush();
    usleep(800000);
    echo "<span class='text-success'>[Success] Downloaded successfully.</span><br>";
    echo "Extracting package archive...<br>";
    flush();
    ob_flush();
    usleep(600000);
    echo "<span class='text-success'>[Success] Extraction complete (via ZipArchive).</span><br>";
    echo "Analyzing integrity signature... Verified.<br>";
    flush();
    ob_flush();
    usleep(400000);
    echo "Updating live files...<br>";
    flush();
    ob_flush();
    usleep(300000);
    echo "Updating <code>bootstrap.php</code>... <span class='text-success'>done</span><br>";
    flush();
    ob_flush();
    usleep(250000);
    echo "Updating <code>staff.php</code>... <span class='text-success'>done</span><br>";
    flush();
    ob_flush();
    usleep(250000);
    echo "Updating <code>db_config.php</code>... <span class='text-info'>[Info] Retaining active database configuration (skipped db_config.php overwrite).</span><br>";
    flush();
    ob_flush();
    usleep(300000);
    echo "Updating UI Assets & components... <span class='text-success'>done</span><br>";
    flush();
    ob_flush();
    usleep(300000);
    echo "Cleaning up temporary files...<br>";
    flush();
    ob_flush();
    usleep(400000);
    echo "<h3>[Success] Simulation Deployment successful! Your site is fully updated to v1.8.6.</h3>";
    exit;
}

// Configuration
// If private, we use the official API endpoint. If public, the standard archive zip works.
$is_private = (GITHUB_PAT !== '');
$repo_url = $is_private
    ? "https://api.github.com/repos/exeedthemes/cabin_portal/zipball/main"
    : "https://github.com/exeedthemes/cabin_portal/archive/refs/heads/main.zip";

$zip_file = __DIR__ . "/temp_deploy.zip";
$extract_to = __DIR__ . "/temp_extract/";

echo "<style>
    body {
        background-color: #090d16;
        color: #cbd5e1;
        font-family: 'Fira Code', 'JetBrains Mono', 'Courier New', Courier, monospace;
        font-size: 11px;
        line-height: 1.6;
        padding: 16px;
        margin: 0;
    }
    h2 {
        color: #10b981;
        font-size: 13px;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: 0.1em;
        margin-top: 0;
        margin-bottom: 12px;
        border-bottom: 1px solid #1e293b;
        padding-bottom: 8px;
    }
    h3 {
        color: #10b981;
        font-size: 12px;
        font-weight: 800;
        margin-top: 16px;
    }
    .text-error {
        color: #f43f5e;
    }
    .text-success {
        color: #10b981;
    }
    .text-info {
        color: #38bdf8;
    }
</style>";
echo "<h2>AeroFind Platform Deployer</h2>";
echo "Fetching latest obfuscated package build from GitHub...<br>";

// Set longer timeout
set_time_limit(180);

// Download the ZIP from GitHub
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $repo_url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_USERAGENT, 'AeroFindDeployer');

if ($is_private) {
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer " . GITHUB_PAT,
        "Accept: application/vnd.github+json"
    ]);
}

$data = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
if (defined('PHP_VERSION_ID') && PHP_VERSION_ID < 80500) {
    $closeFn = 'curl_close';
    if (function_exists($closeFn)) {
        @$closeFn($ch);
    }
}

if ($http_code !== 200) {
    $err_msg = "<span class='text-error'>[Error] Download failed with HTTP Status Code $http_code.</span><br>";
    if ($http_code === 404) {
        $err_msg .= "If this is a <b>PRIVATE repository</b>, you must generate a GitHub Personal Access Token (PAT) and paste it into the <code>GITHUB_PAT</code> field inside <code>deploy.php</code>.";
    } else {
        $err_msg .= "Response summary: " . htmlspecialchars(substr($data, 0, 300));
    }
    die($err_msg);
}

// Check if download is a valid ZIP archive (check magic bytes 'PK')
if (substr($data, 0, 2) !== 'PK') {
    die("<span class='text-error'>[Error] Downloaded file is invalid or corrupt. (The file did not contain ZIP archive magic signatures. It might be a GitHub error page. Content: <pre>" . htmlspecialchars(substr($data, 0, 300)) . "</pre>)</span>");
}

file_put_contents($zip_file, $data);
echo "<span class='text-success'>[Success] Downloaded repository package successfully.</span><br>Extracting archive...<br>";

// Extract the ZIP
$extracted = false;
if (class_exists('ZipArchive')) {
    $zip = new ZipArchive;
    if ($zip->open($zip_file) === TRUE) {
        @mkdir($extract_to, 0755, true);
        $zip->extractTo($extract_to);
        $zip->close();
        $extracted = true;
        echo "<span class='text-success'>[Success] Extraction complete (via ZipArchive).</span><br>";
    }
}

if (!$extracted) {
    // Try system unzip command as fallback
    echo "ZipArchive extension not found, trying system unzip command fallback...<br>";
    @mkdir($extract_to, 0755, true);
    $output = [];
    $return_var = 0;
    exec("unzip -o " . escapeshellarg($zip_file) . " -d " . escapeshellarg($extract_to) . " 2>&1", $output, $return_var);
    if ($return_var === 0) {
        $extracted = true;
        echo "<span class='text-success'>[Success] Extraction complete (via system unzip).</span><br>";
    } else {
        unlink($zip_file);
        die("<span class='text-error'>[Error] Failed to extract ZIP file. Reason: <br>" . implode("<br>", $output) . "</span>");
    }
}

// Locate the extracted repository root folder inside the zip
// GitHub zips the folder as "repo-name-branchname/" or "owner-repo-commit/"
$dirs = array_merge(
    glob($extract_to . "exeedthemes-cabin_portal-*", GLOB_ONLYDIR),
    glob($extract_to . "cabin_portal-*", GLOB_ONLYDIR)
);
if (empty($dirs)) {
    // Fallback: look for any extracted directory
    $dirs = glob($extract_to . "*", GLOB_ONLYDIR);
}

if (empty($dirs)) {
    // Clean up
    rmdir_recursive($extract_to);
    unlink($zip_file);
    die("<span class='text-error'>[Error] Could not locate extracted repository folder inside temp_extract.</span>");
}

$extracted_root = $dirs[0];
$release_root = locate_release_root($extracted_root, $extract_to);

echo "Installing obfuscated release package...<br>";
// Copy the obfuscated release build from the repository archive to the live site directory.
copy_directory($release_root, __DIR__);

// Clean up temporary files
echo "Cleaning up temporary files...<br>";
rmdir_recursive($extract_to);
unlink($zip_file);

echo "<h3>[Success] Deployment successful! Your site is fully updated.</h3>";

// --- Helper Functions ---

function locate_release_root($repo_root, $extract_to)
{
    $release_zip = $repo_root . '/' . RELEASE_ARCHIVE_PATH;
    if (is_file($release_zip)) {
        $release_extract_to = rtrim($extract_to, '/') . '/release_package/';
        echo "Extracting tracked obfuscated release archive: <code>" . htmlspecialchars(RELEASE_ARCHIVE_PATH) . "</code><br>";

        $extracted = false;
        if (class_exists('ZipArchive')) {
            $zip = new ZipArchive;
            if ($zip->open($release_zip) === TRUE) {
                @mkdir($release_extract_to, 0755, true);
                $zip->extractTo($release_extract_to);
                $zip->close();
                $extracted = true;
            }
        }

        if (!$extracted) {
            @mkdir($release_extract_to, 0755, true);
            $output = [];
            $return_var = 0;
            exec("unzip -o " . escapeshellarg($release_zip) . " -d " . escapeshellarg($release_extract_to) . " 2>&1", $output, $return_var);
            if ($return_var !== 0) {
                die("<span class='text-error'>[Error] Failed to extract bundled obfuscated release archive. Reason:<br>" . implode("<br>", array_map('htmlspecialchars', $output)) . "</span>");
            }
        }

        $release_dir = $release_extract_to . 'cabin_portal_obfuscated';
        if (is_dir($release_dir)) {
            return $release_dir;
        }
    }

    $release_dir = $repo_root . '/' . RELEASE_INSTALL_DIR;
    if (is_dir($release_dir)) {
        echo "Tracked release archive not found; using obfuscated release directory: <code>" . htmlspecialchars(RELEASE_INSTALL_DIR) . "</code><br>";
        return $release_dir;
    }

    die("<span class='text-error'>[Error] No obfuscated release package found in the downloaded repository archive. Expected <code>" . htmlspecialchars(RELEASE_INSTALL_DIR) . "</code>.</span>");
}

function copy_directory($src, $dst)
{
    $dir = opendir($src);
    @mkdir($dst, 0755, true);
    while (false !== ($file = readdir($dir))) {
        if (($file != '.') && ($file != '..')) {
            // Keep production database credentials safe: never overwrite an existing db_config.php file
            if ($file === 'db_config.php' && file_exists($dst . '/' . $file)) {
                echo "<span class='text-info'>[Info] Retaining active database configuration (skipped db_config.php overwrite).</span><br>";
                continue;
            }
            if ($file === 'config.local.php' && file_exists($dst . '/' . $file)) {
                echo "<span class='text-info'>[Info] Retaining active local application secret (skipped config.local.php overwrite).</span><br>";
                continue;
            }
            if (is_dir($src . '/' . $file)) {
                copy_directory($src . '/' . $file, $dst . '/' . $file);
            } elseif ($file === 'deploy.php' && file_exists($dst . '/' . $file)) {
                copy_deploy_script_preserving_credentials($src . '/' . $file, $dst . '/' . $file);
            } else {
                copy($src . '/' . $file, $dst . '/' . $file);
            }
        }
    }
    closedir($dir);
}

function copy_deploy_script_preserving_credentials($src, $dst)
{
    $new_content = file_get_contents($src);
    $current_content = file_get_contents($dst);
    if ($new_content === false || $current_content === false) {
        echo "<span class='text-error'>[Warning] Could not update deploy.php safely; retaining existing deployment script.</span><br>";
        return;
    }

    foreach (['DEPLOY_TOKEN', 'GITHUB_PAT'] as $constant) {
        $pattern = "/define\s*\(\s*['\"]" . preg_quote($constant, '/') . "['\"]\s*,\s*(['\"])(.*?)\\1\s*\)\s*;/";
        if (preg_match($pattern, $current_content, $matches)) {
            $escaped_value = addcslashes($matches[2], "\\'");
            $new_content = preg_replace_callback(
                $pattern,
                static function () use ($constant, $escaped_value) {
                    return "define('" . $constant . "', '" . $escaped_value . "');";
                },
                $new_content,
                1
            );
        }
    }

    if (file_put_contents($dst, $new_content, LOCK_EX) === false) {
        echo "<span class='text-error'>[Warning] Could not write updated deploy.php; retaining existing deployment script.</span><br>";
        return;
    }

    echo "<span class='text-info'>[Info] Updated deploy.php while preserving deployment credentials.</span><br>";
}

function rmdir_recursive($dir)
{
    if (!is_dir($dir))
        return;
    $files = array_diff(scandir($dir), array('.', '..'));
    foreach ($files as $file) {
        (is_dir("$dir/$file")) ? rmdir_recursive("$dir/$file") : unlink("$dir/$file");
    }
    return rmdir($dir);
}
