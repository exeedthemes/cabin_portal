<?php

define('AEROFIND_VERSION', '1.7.0');

/**
 * Gets the deploy token from deploy.php dynamically.
 */
function af_get_deploy_token(): string {
    $deploy_file = __DIR__ . '/deploy.php';
    if (file_exists($deploy_file)) {
        $content = file_get_contents($deploy_file);
        if (preg_match("/define\s*\(\s*['\"]DEPLOY_TOKEN['\"]\s*,\s*['\"](.*?)['\"]\s*\)/", $content, $matches)) {
            return $matches[1];
        }
    }
    return 'aerofind_secure_deploy_2026'; // Fallback to default
}

/**
 * Checks GitHub for the latest release version.
 * Caches results in session to prevent rate limits.
 */
function af_check_for_updates(bool $force = false): array {
    $current_version = AEROFIND_VERSION;
    $now = time();
    
    // Support simulation for testing and demo purposes
    $simulate = isset($_GET['simulate_update']) || (isset($_SESSION['simulate_update']) && $_SESSION['simulate_update']);
    if (isset($_GET['simulate_update'])) {
        $_SESSION['simulate_update'] = ($_GET['simulate_update'] === '1');
    }
    
    if (!$force && !$simulate && !empty($_SESSION['af_update_info']) && !empty($_SESSION['af_update_info_time'])) {
        if ($now - $_SESSION['af_update_info_time'] < 3600) {
            return $_SESSION['af_update_info'];
        }
    }
    
    $repo = 'exeedthemes/aerofind';
    
    if ($simulate) {
        $result = [
            'success' => true,
            'current_version' => $current_version,
            'latest_version' => '1.8.0',
            'update_available' => true,
            'release_notes' => "### 🚀 AeroFind Enterprise v1.8.0\n\n- **Live Deployment Progress**: Visual real-time terminal output with terminal-styled progress counters.\n- **Improved Update Engine**: Smoother package updates and improved folder permission checks.\n- **Optimized Security Shield**: Nonce-based CSP updates and strict same-site proxy validation.",
            'html_url' => "https://github.com/{$repo}",
            'published_at' => date('Y-m-d H:i:s'),
            'checked_at' => date('Y-m-d H:i:s'),
            'simulated' => true
        ];
        $_SESSION['af_update_info'] = $result;
        $_SESSION['af_update_info_time'] = $now;
        return $result;
    }
    
    $url = "https://api.github.com/repos/{$repo}/releases/latest";
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'AeroFindUpdater');
    curl_setopt($ch, CURLOPT_TIMEOUT, 6);
    
    // Add token header if configured in deploy.php
    $deploy_file = __DIR__ . '/deploy.php';
    if (file_exists($deploy_file)) {
        $content = file_get_contents($deploy_file);
        if (preg_match("/define\s*\(\s*['\"]GITHUB_PAT['\"]\s*,\s*['\"](.*?)['\"]\s*\)/", $content, $matches)) {
            $pat = trim($matches[1]);
            if ($pat !== '') {
                curl_setopt($ch, CURLOPT_HTTPHEADER, [
                    "Authorization: Bearer " . $pat,
                    "Accept: application/vnd.github+json"
                ]);
            }
        }
    }
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    $result = [
        'success' => false,
        'current_version' => $current_version,
        'latest_version' => $current_version,
        'update_available' => false,
        'release_notes' => '',
        'html_url' => "https://github.com/{$repo}",
        'published_at' => '',
        'checked_at' => date('Y-m-d H:i:s'),
        'error' => ''
    ];
    
    if ($http_code === 200 && !empty($response)) {
        $data = json_decode($response, true);
        if (json_last_error() === JSON_ERROR_NONE && !empty($data['tag_name'])) {
            $latest_version = ltrim($data['tag_name'], 'v');
            $result['success'] = true;
            $result['latest_version'] = $latest_version;
            $result['release_notes'] = $data['body'] ?? '';
            $result['html_url'] = $data['html_url'] ?? "https://github.com/{$repo}";
            $result['published_at'] = !empty($data['published_at']) ? date('Y-m-d H:i:s', strtotime($data['published_at'])) : '';
            
            if (version_compare($latest_version, $current_version, '>')) {
                $result['update_available'] = true;
            }
        } else {
            $result['error'] = 'Invalid response from GitHub API.';
        }
    } else {
        $result['error'] = "GitHub API returned HTTP code {$http_code}. Configure a GITHUB_PAT in deploy.php or append ?simulate_update=1 to simulate updates.";
    }
    
    $_SESSION['af_update_info'] = $result;
    $_SESSION['af_update_info_time'] = $now;
    
    return $result;
}

function af_get_current_station(): string {
    static $resolved_station = null;
    if ($resolved_station !== null) {
        return $resolved_station;
    }

    af_start_secure_session();

    // Station-scoped users are pinned to the station selected at login.
    if (in_array(($_SESSION['cabin_staff_role'] ?? ''), ['staff', 'supervisor'], true) && !empty($_SESSION['active_station'])) {
        $station = strtoupper(trim((string) $_SESSION['active_station']));
        if (preg_match('/^[A-Z0-9]{3,4}$/', $station)) {
            $resolved_station = $station;
            return $resolved_station;
        }
    }

    $is_admin = (($_SESSION['cabin_staff_role'] ?? '') === 'admin');

    // Public users are locked to their first selected station for this browser session.
    if (!$is_admin && !empty($_SESSION['active_station'])) {
        $station = strtoupper(trim((string) $_SESSION['active_station']));
        if (array_key_exists($station, af_stations())) {
            $resolved_station = $station;
            return $resolved_station;
        }
    }

    if (!$is_admin && !empty($_COOKIE['af_station'])) {
        $station = strtoupper(trim($_COOKIE['af_station']));
        if (preg_match('/^[A-Z0-9]{3,4}$/', $station) && array_key_exists($station, af_stations())) {
            $_SESSION['active_station'] = $station;
            $resolved_station = $station;
            return $resolved_station;
        }
    }

    // 1. Resolve via Query or POST Parameter
    $req_station = $_GET['station'] ?? $_POST['station'] ?? '';
    if (!empty($req_station)) {
        $station = strtoupper(trim($req_station));
        if (preg_match('/^[A-Z0-9]{3,4}$/', $station) && array_key_exists($station, af_stations())) {
            $_SESSION['active_station'] = $station;
            if (!headers_sent()) {
                af_set_cookie('af_station', $station, time() + (86400 * 30));
            }
            $resolved_station = $station;
            return $resolved_station;
        }
    }

    // 2. Resolve via Session Cache
    if (!empty($_SESSION['active_station'])) {
        $station = strtoupper(trim((string) $_SESSION['active_station']));
        if (array_key_exists($station, af_stations())) {
            $resolved_station = $station;
            return $resolved_station;
        }
    }

    // 3. Resolve via Cookie
    if (!empty($_COOKIE['af_station'])) {
        $station = strtoupper(trim($_COOKIE['af_station']));
        if (preg_match('/^[A-Z0-9]{3,4}$/', $station) && array_key_exists($station, af_stations())) {
            $_SESSION['active_station'] = $station;
            $resolved_station = $station;
            return $resolved_station;
        }
    }

    // 4. Resolve via REST API Custom Header
    if (!empty($_SERVER['HTTP_X_STATION'])) {
        $station = strtoupper(trim($_SERVER['HTTP_X_STATION']));
        if (preg_match('/^[A-Z0-9]{3,4}$/', $station) && array_key_exists($station, af_stations())) {
            $resolved_station = $station;
            return $resolved_station;
        }
    }

    // 5. Default Fallback Station
    $stations = af_stations();
    $resolved_station = array_key_first($stations) ?: 'MUC';
    return $resolved_station;
}

function af_has_selected_station(): bool {
    af_start_secure_session();
    $stations = af_stations();
    $candidates = [
        $_GET['station'] ?? '',
        $_POST['station'] ?? '',
        $_SESSION['active_station'] ?? '',
        $_COOKIE['af_station'] ?? '',
    ];

    foreach ($candidates as $candidate) {
        $station = strtoupper(trim((string) $candidate));
        if (preg_match('/^[A-Z0-9]{3,4}$/', $station) && array_key_exists($station, $stations)) {
            return true;
        }
    }

    return false;
}

function af_stations(): array {
    $stations_file = __DIR__ . '/stations.json';
    $stations = [];
    if (file_exists($stations_file)) {
        $decoded = json_decode((string) file_get_contents($stations_file), true);
        if (is_array($decoded)) {
            foreach ($decoded as $code => $name) {
                $code = strtoupper(trim((string) $code));
                $name = trim((string) $name);
                if (preg_match('/^[A-Z0-9]{3,4}$/', $code) && $name !== '') {
                    $stations[$code] = $name;
                }
            }
        }
    }

    return $stations ?: ['MUC' => 'Munich'];
}

function af_station_db_file(string $station): string {
    $safe_station = preg_replace('/[^A-Za-z0-9_-]/', '', strtoupper(trim($station)));
    if ($safe_station === 'MUC' || $safe_station === '') {
        return __DIR__ . '/cabin_db.sqlite';
    }
    return __DIR__ . '/cabin_db_' . $safe_station . '.sqlite';
}

function af_db(): PDO {
    static $pdos = [];
    $station = af_get_current_station();

    if (isset($pdos[$station]) && $pdos[$station] instanceof PDO) {
        return $pdos[$station];
    }

    $safe_station = preg_replace('/[^A-Za-z0-9_-]/', '', strtoupper(trim($station)));
    $db_file = af_station_db_file($station);

    $is_new_db = !file_exists($db_file);

    try {
        $pdo = new PDO('sqlite:' . $db_file);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        // Auto-provision schema if it's a newly created SQLite database
        $pdo->exec("CREATE TABLE IF NOT EXISTS settings (key TEXT PRIMARY KEY, value TEXT)");
        $pdo->exec("CREATE TABLE IF NOT EXISTS deleted_items (tag_no TEXT PRIMARY KEY)");
        $pdo->exec("CREATE TABLE IF NOT EXISTS deleted_airlines (code TEXT PRIMARY KEY)");
        $pdo->exec("CREATE TABLE IF NOT EXISTS audit_log (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            action TEXT,
            entity_type TEXT,
            entity_id TEXT,
            actor TEXT,
            details TEXT,
            created_at DATETIME
        )");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_audit_log_entity ON audit_log (entity_type, entity_id, created_at)");
        $pdo->exec("CREATE TABLE IF NOT EXISTS pickup_staff_profiles (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            staff_name TEXT NOT NULL,
            staff_email TEXT NOT NULL,
            normalized_name TEXT NOT NULL UNIQUE,
            normalized_email TEXT NOT NULL UNIQUE,
            first_seen_at DATETIME,
            last_seen_at DATETIME
        )");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_pickup_staff_profiles_email ON pickup_staff_profiles (normalized_email)");
        $pdo->exec("CREATE TABLE IF NOT EXISTS items (
            tag_no TEXT,
            item_description TEXT,
            contents TEXT,
            pax_name TEXT,
            pax_address TEXT,
            pax_contact_no REAL,
            other_info TEXT,
            user_comments TEXT,
            delivery_info TEXT,
            comments REAL,
            status TEXT DEFAULT 'Found',
            pax_email TEXT,
            photo TEXT,
            created_at DATETIME
        )");
        $pdo->exec("CREATE TABLE IF NOT EXISTS pending_reports (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            report_ref TEXT UNIQUE,
            tag_no TEXT UNIQUE,
            airline TEXT,
            flight_number TEXT,
            item_description TEXT,
            pax_name TEXT,
            pax_email TEXT,
            pax_contact_no TEXT,
            seat_info TEXT,
            photo TEXT,
            status TEXT DEFAULT 'Pending',
            matched_tag_no TEXT,
            staff_notes TEXT,
            privacy_consent_at DATETIME,
            privacy_consent_ip TEXT,
            created_at DATETIME,
            reviewed_at DATETIME
        )");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_pending_reports_status ON pending_reports (status, created_at)");
        $pending_columns = $pdo->query("PRAGMA table_info(pending_reports)")->fetchAll(PDO::FETCH_ASSOC);
        $pending_column_names = array_column($pending_columns, 'name');
        if (!in_array('tag_no', $pending_column_names, true)) {
            $pdo->exec("ALTER TABLE pending_reports ADD COLUMN tag_no TEXT");
        }
        if (!in_array('privacy_consent_at', $pending_column_names, true)) {
            $pdo->exec("ALTER TABLE pending_reports ADD COLUMN privacy_consent_at DATETIME");
        }
        if (!in_array('privacy_consent_ip', $pending_column_names, true)) {
            $pdo->exec("ALTER TABLE pending_reports ADD COLUMN privacy_consent_ip TEXT");
        }
        $pdo->exec("CREATE TABLE IF NOT EXISTS airlines (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT,
            code TEXT UNIQUE,
            logo TEXT,
            domain TEXT
        )");

        // Automatically seed some initial airlines for new stations so they have immediate data
        if ($is_new_db) {
            // Clone settings from MUC (primary) if it exists, ensuring smooth configuration inheritance
            $master_db_file = __DIR__ . '/cabin_db.sqlite';
            if ($safe_station !== 'MUC' && file_exists($master_db_file)) {
                try {
                    $master_pdo = new PDO('sqlite:' . $master_db_file);
                    $master_pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                    $master_settings = $master_pdo->query("SELECT key, value FROM settings")->fetchAll(PDO::FETCH_ASSOC);
                    
                    if (!empty($master_settings)) {
                        $set_stmt = $pdo->prepare("INSERT OR REPLACE INTO settings (key, value) VALUES (?, ?)");
                        foreach ($master_settings as $s) {
                            $set_stmt->execute([$s['key'], $s['value']]);
                        }
                    }
                } catch (Exception $e) {
                    error_log("Failed to inherit settings from MUC: " . $e->getMessage());
                }
            }
        }

        $pdos[$station] = $pdo;
        return $pdo;
    } catch (PDOException $e) {
        error_log("Failed to connect to SQLite database for station [$station]: " . $e->getMessage());
        throw new RuntimeException("Could not connect to the station database terminal.");
    }
}

function af_security_headers(string $type = 'html'): void {
    header_remove('X-Powered-By');
    header('X-Frame-Options: SAMEORIGIN');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(self), geolocation=(), microphone=()');
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
    if ($type === 'html') {
        header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' https://cdn.tailwindcss.com; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com; img-src 'self' data: https:; connect-src 'self'; frame-ancestors 'self'; base-uri 'self'; form-action 'self'");
    }
    if ($type === 'json') {
        header('Content-Type: application/json; charset=utf-8');
    }
}

function af_start_secure_session(): void {
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function af_cookie_secure(): bool {
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
}

function af_set_cookie(string $name, string $value, int $expires): void {
    setcookie($name, $value, [
        'expires' => $expires,
        'path' => '/',
        'secure' => af_cookie_secure(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function af_h($value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function af_local_config(): array {
    static $config = null;
    if ($config !== null) {
        return $config;
    }

    $file = __DIR__ . '/config.local.php';
    if (!is_file($file)) {
        $config = [];
        return $config;
    }

    $loaded = require $file;
    $config = is_array($loaded) ? $loaded : [];
    return $config;
}

function af_api_secret(): string {
    $secret = getenv('AF_API_SECRET');
    if ($secret === false || trim($secret) === '') {
        $secret = $_SERVER['AF_API_SECRET'] ?? '';
    }
    if (trim((string) $secret) === '') {
        $config = af_local_config();
        $secret = $config['api_secret'] ?? '';
    }
    return trim((string) $secret);
}

function af_settings(PDO $pdo): array {
    $defaults = [
        'company_name' => 'AeroFind Cabin Recovery',
        'company_short_name' => 'AeroFind',
        'company_tagline' => 'Cabin Operations Division',
        'company_initials' => 'AF',
        'company_logo' => '',
        'favicon_url' => '',
        'legal_company_name' => '',
        'legal_form' => '',
        'legal_representative' => '',
        'legal_street_address' => '',
        'legal_postal_city' => '',
        'legal_country' => 'Germany',
        'legal_email' => '',
        'legal_phone' => '',
        'legal_register' => '',
        'legal_vat_id' => '',
        'privacy_contact_email' => '',
        'data_role_description' => 'We process cabin lost-and-found data as a ground handling service provider on behalf of the responsible airline, unless a separate agreement states otherwise.',
        'active_record_retention_days' => '180',
        'closed_record_retention_days' => '365',
        'sensitive_photo_retention_days' => '30',
        'company_accent' => '#f43f5e',
        'passenger_view_days' => '30',
        'items_per_page' => '20',
        'passenger_card_layout' => 'dual',
        'pickup_info' => 'Main Terminal, Cabin Recovery Lost & Found Desk. Please bring a valid ID and the reference code.',
        'pickup_staff_email_domain' => '',
        'staff_notification_email' => 'staff@aerofind.online',
        'admin_notification_email' => '',
        'notify_admin_supervisor_on_add' => '1',
        'notify_admin_supervisor_on_delete' => '1',
        'notify_admin_supervisor_on_status' => '1',
        'staff_login_email' => 'staff@example.com',
        'developer_contact_email' => 'support@aerofind.online',
        'admin_username' => 'admin',
        'admin_password_hash' => '',
        'staff_username' => 'admin',
        'staff_password_hash' => '',
        'supervisor_username' => '',
        'supervisor_password_hash' => '',
        'email_delivery_method' => 'php_mail',
        'smtp_host' => '',
        'smtp_port' => '587',
        'smtp_encryption' => 'tls',
        'smtp_user' => 'noreply@aerofind.online',
        'smtp_password' => '',
        'smtp_from_email' => 'noreply@aerofind.online',
        'database_backup_interval_days' => '7',
    ];

    $rows = $pdo->query("SELECT key, value FROM settings")->fetchAll();
    foreach ($rows as $row) {
        $defaults[$row['key']] = $row['value'];
    }
    return $defaults;
}

function af_pickup_info(array $settings, string $station_code = '', string $station_name = ''): string {
    $pickup_info = trim((string) ($settings['pickup_info'] ?? ''));
    if ($pickup_info !== '') {
        return $pickup_info;
    }

    $station_label = trim($station_code . ($station_name !== '' ? ' - ' . $station_name : ''));
    if ($station_label !== '') {
        return $station_label;
    }

    return 'Main Terminal, Cabin Recovery Lost & Found Desk. Please bring a valid ID and the reference code.';
}

function af_setting(PDO $pdo, string $key, string $value): void {
    $stmt = $pdo->prepare("INSERT OR REPLACE INTO settings (key, value) VALUES (?, ?)");
    $stmt->execute([$key, $value]);
}

function af_audit_log(PDO $pdo, string $action, string $entity_type, string $entity_id, string $actor = '', array $details = []): void {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS audit_log (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            action TEXT,
            entity_type TEXT,
            entity_id TEXT,
            actor TEXT,
            details TEXT,
            created_at DATETIME
        )");
        $stmt = $pdo->prepare("INSERT INTO audit_log (action, entity_type, entity_id, actor, details, created_at) VALUES (?, ?, ?, ?, ?, datetime('now'))");
        $stmt->execute([
            $action,
            $entity_type,
            $entity_id,
            $actor,
            json_encode($details, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        ]);
    } catch (Throwable $e) {
        error_log("AeroFind audit log warning: " . $e->getMessage());
    }
}

function af_csrf_token(): string {
    af_start_secure_session();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function af_verify_csrf(): void {
    af_start_secure_session();
    $token = $_POST['csrf_token'] ?? '';
    if (!is_string($token) || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
        http_response_code(403);
        exit('Invalid security token.');
    }
}

function af_csrf_input(): string {
    return '<input type="hidden" name="csrf_token" value="' . af_h(af_csrf_token()) . '">';
}

function af_valid_url_or_path(string $value): string {
    $value = trim($value);
    if ($value === '') {
        return '';
    }
    if (preg_match('#^uploads/branding/[A-Za-z0-9._-]+$#', $value)) {
        return file_exists(__DIR__ . '/' . $value) ? $value : '';
    }
    return filter_var($value, FILTER_VALIDATE_URL) ? $value : '';
}

function af_client_ip(): string {
    return substr((string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), 0, 64);
}

function af_rate_limit(string $scope, int $max_attempts, int $window_seconds): bool {
    $safe_scope = preg_replace('/[^A-Za-z0-9_.-]/', '_', $scope);
    $dir = __DIR__ . '/uploads/.rate_limits';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }

    $bucket = hash('sha256', $safe_scope . '|' . af_client_ip());
    $file = $dir . '/' . $bucket . '.json';
    $now = time();
    $hits = [];

    $handle = @fopen($file, 'c+');
    if (!$handle) {
        return true;
    }

    try {
        flock($handle, LOCK_EX);
        $raw = stream_get_contents($handle);
        $decoded = json_decode($raw ?: '[]', true);
        if (is_array($decoded)) {
            $hits = array_values(array_filter($decoded, fn($ts) => is_int($ts) && $ts > ($now - $window_seconds)));
        }
        if (count($hits) >= $max_attempts) {
            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, json_encode($hits));
            return false;
        }
        $hits[] = $now;
        ftruncate($handle, 0);
        rewind($handle);
        fwrite($handle, json_encode($hits));
        return true;
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

function af_can_compress_uploaded_photo(string $mime): bool {
    if (!extension_loaded('gd')) {
        return false;
    }

    return $mime === 'image/jpeg'
        || $mime === 'image/png'
        || ($mime === 'image/webp' && function_exists('imagecreatefromwebp'));
}

function af_create_image_from_upload(string $tmp, string $mime) {
    if ($mime === 'image/jpeg') {
        return @imagecreatefromjpeg($tmp);
    }
    if ($mime === 'image/png') {
        return @imagecreatefrompng($tmp);
    }
    if ($mime === 'image/webp' && function_exists('imagecreatefromwebp')) {
        return @imagecreatefromwebp($tmp);
    }

    return false;
}

function af_apply_jpeg_orientation($image, string $tmp, string $mime) {
    if ($mime !== 'image/jpeg' || !function_exists('exif_read_data')) {
        return $image;
    }

    $exif = @exif_read_data($tmp);
    $orientation = (int) ($exif['Orientation'] ?? 1);
    if ($orientation === 3) {
        return imagerotate($image, 180, 0) ?: $image;
    }
    if ($orientation === 6) {
        return imagerotate($image, -90, 0) ?: $image;
    }
    if ($orientation === 8) {
        return imagerotate($image, 90, 0) ?: $image;
    }

    return $image;
}

function af_save_compressed_uploaded_photo(string $tmp, string $target, string $mime): bool {
    $source = af_create_image_from_upload($tmp, $mime);
    if (!$source) {
        return false;
    }

    $source = af_apply_jpeg_orientation($source, $tmp, $mime);
    $width = imagesx($source);
    $height = imagesy($source);
    $max_dimension = 1280;
    $scale = min(1, $max_dimension / max($width, $height));
    $target_width = max(1, (int) round($width * $scale));
    $target_height = max(1, (int) round($height * $scale));

    $canvas = imagecreatetruecolor($target_width, $target_height);
    if (!$canvas) {
        return false;
    }

    $white = imagecolorallocate($canvas, 255, 255, 255);
    imagefilledrectangle($canvas, 0, 0, $target_width, $target_height, $white);
    imagecopyresampled($canvas, $source, 0, 0, 0, 0, $target_width, $target_height, $width, $height);
    imageinterlace($canvas, true);
    $saved = imagejpeg($canvas, $target, 68);

    return $saved;
}

function af_uploaded_photo_is_already_optimized(string $tmp, string $mime, array $info, int $size): bool {
    if ($mime !== 'image/jpeg') {
        return false;
    }

    $width = (int) ($info[0] ?? 0);
    $height = (int) ($info[1] ?? 0);
    if ($width < 1 || $height < 1 || max($width, $height) > 1280 || $size > 900 * 1024) {
        return false;
    }

    if (function_exists('exif_read_data')) {
        $exif = @exif_read_data($tmp);
        $orientation = (int) ($exif['Orientation'] ?? 1);
        if ($orientation !== 1) {
            return false;
        }
    }

    return true;
}

function af_store_uploaded_image(string $field, string $prefix): string {
    if (empty($_FILES[$field]) || !is_array($_FILES[$field]) || $_FILES[$field]['error'] === UPLOAD_ERR_NO_FILE) {
        return '';
    }
    if ($_FILES[$field]['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Image upload failed.');
    }
    if (($_FILES[$field]['size'] ?? 0) > 5 * 1024 * 1024) {
        throw new RuntimeException('Image upload is too large. Maximum size is 5 MB.');
    }

    $tmp = $_FILES[$field]['tmp_name'];
    $info = @getimagesize($tmp);
    if (!$info || empty($info['mime'])) {
        throw new RuntimeException('Only image uploads are allowed.');
    }

    $extensions = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
        'image/x-icon' => 'ico',
        'image/vnd.microsoft.icon' => 'ico',
    ];
    $mime = $info['mime'];
    if (!isset($extensions[$mime])) {
        throw new RuntimeException('Unsupported image type.');
    }

    $is_branding_upload = $field === 'company_logo_upload' || $field === 'favicon_upload';
    $dir = __DIR__ . '/uploads/' . ($is_branding_upload ? 'branding' : 'cabin_items');
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    $safe_prefix = preg_replace('/[^A-Za-z0-9_-]/', '_', $prefix);
    $base_filename = $safe_prefix . '_' . time() . '_' . bin2hex(random_bytes(4));
    if (!$is_branding_upload && af_uploaded_photo_is_already_optimized($tmp, $mime, $info, (int) ($_FILES[$field]['size'] ?? 0))) {
        $filename = $base_filename . '.jpg';
        $target = $dir . '/' . $filename;
        if (!move_uploaded_file($tmp, $target)) {
            throw new RuntimeException('Could not save uploaded image.');
        }
        return 'uploads/' . basename($dir) . '/' . $filename;
    }

    if (!$is_branding_upload && af_can_compress_uploaded_photo($mime)) {
        $filename = $base_filename . '.jpg';
        $target = $dir . '/' . $filename;
        if (af_save_compressed_uploaded_photo($tmp, $target, $mime)) {
            return 'uploads/' . basename($dir) . '/' . $filename;
        }
    }

    $filename = $base_filename . '.' . $extensions[$mime];
    $target = $dir . '/' . $filename;
    if (!move_uploaded_file($tmp, $target)) {
        throw new RuntimeException('Could not save uploaded image.');
    }

    return 'uploads/' . basename($dir) . '/' . $filename;
}

function af_brand_logo_html(array $settings, string $classes, string $text_classes = 'text-white'): string {
    $logo = af_valid_url_or_path($settings['company_logo'] ?? '');
    $initials = strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $settings['company_initials'] ?? 'AF'), 0, 4)) ?: 'AF';
    if ($logo !== '') {
        return '<img src="' . af_h($logo) . '" alt="' . af_h($settings['company_short_name'] ?? 'Company') . '" class="' . af_h($classes) . ' object-contain bg-white p-1" loading="lazy" decoding="async" data-smooth-image>';
    }
    return '<span class="' . af_h($text_classes) . '">' . af_h($initials) . '</span>';
}

function af_station_backup_file(string $station): string {
    $safe_station = preg_replace('/[^A-Za-z0-9_-]/', '', strtoupper(trim($station)));
    if ($safe_station === 'MUC' || $safe_station === '') {
        return __DIR__ . '/cabin_db_backup.sqlite';
    }
    return __DIR__ . '/cabin_db_backup_' . $safe_station . '.sqlite';
}

function af_run_auto_backups(): void {
    static $run = false;
    if ($run) {
        return;
    }
    $run = true;

    $stations = af_stations();
    $now = time();

    foreach ($stations as $code => $name) {
        try {
            $db_file = af_station_db_file($code);
            if (!file_exists($db_file)) {
                continue;
            }

            // Open temporary sqlite connection to check settings for this station
            $pdo = new PDO('sqlite:' . $db_file);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

            $settings = af_settings($pdo);
            $interval_days = (int) ($settings['database_backup_interval_days'] ?? 7);

            // If interval is <= 0, automatic backups are disabled for this station
            if ($interval_days <= 0) {
                continue;
            }

            $last_backup = (int) ($settings['last_backup_time'] ?? 0);
            $cutoff = $last_backup + ($interval_days * 86400);

            if ($now >= $cutoff) {
                $backup_file = af_station_backup_file($code);
                
                // Copy current database file to the station-specific backup path
                if (copy($db_file, $backup_file)) {
                    af_setting($pdo, 'last_backup_time', (string)$now);
                }
            }
        } catch (Exception $e) {
            error_log("AeroFind: Automatic backup failed for station [$code]: " . $e->getMessage());
        }
    }
}

// Auto-trigger backup check at most once per hour to minimize I/O overhead
$backup_lock_file = __DIR__ . '/uploads/.last_backup_check';
if (!file_exists(dirname($backup_lock_file))) {
    @mkdir(dirname($backup_lock_file), 0755, true);
}
if (!file_exists($backup_lock_file) || (time() - filemtime($backup_lock_file)) > 3600) {
    @file_put_contents($backup_lock_file, (string)time());
    af_run_auto_backups();
}
