<?php

function af_get_current_station(): string {
    static $resolved_station = null;
    if ($resolved_station !== null) {
        return $resolved_station;
    }

    af_start_secure_session();

    // Staff users are pinned to the station selected at login.
    if (($_SESSION['cabin_staff_role'] ?? '') === 'staff' && !empty($_SESSION['active_station'])) {
        $station = strtoupper(trim((string) $_SESSION['active_station']));
        if (preg_match('/^[A-Z0-9]{3,4}$/', $station)) {
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
                setcookie('af_station', $station, time() + (86400 * 30), "/", "", false, true);
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
            $default_airlines = [
                ['AeroFind Cabin', 'AF', 'https://ui-avatars.com/api/?name=AF&background=f43f5e&color=fff', 'aerofind.online'],
                ['Lufthansa', 'LH', 'https://logo.clearbit.com/lufthansa.com', 'lufthansa.com'],
                ['British Airways', 'BA', 'https://logo.clearbit.com/britishairways.com', 'britishairways.com'],
                ['Emirates', 'EK', 'https://logo.clearbit.com/emirates.com', 'emirates.com'],
                ['Delta Air Lines', 'DL', 'https://logo.clearbit.com/delta.com', 'delta.com']
            ];
            $stmt = $pdo->prepare("INSERT OR IGNORE INTO airlines (name, code, logo, domain) VALUES (?, ?, ?, ?)");
            foreach ($default_airlines as $al) {
                $stmt->execute($al);
            }

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
    header('X-Frame-Options: SAMEORIGIN');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(self), geolocation=(), microphone=()');
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

function af_h($value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function af_settings(PDO $pdo): array {
    $defaults = [
        'company_name' => 'AeroFind Cabin Recovery',
        'company_short_name' => 'AeroFind',
        'company_tagline' => 'Cabin Operations Division',
        'company_initials' => 'AF',
        'company_logo' => '',
        'favicon_url' => '',
        'company_accent' => '#f43f5e',
        'passenger_view_days' => '30',
        'items_per_page' => '20',
        'passenger_card_layout' => 'dual',
        'pickup_info' => 'Main Terminal, Cabin Recovery Lost & Found Desk. Please bring a valid ID and the reference code.',
        'staff_notification_email' => 'staff@example.com',
        'staff_login_email' => 'staff@example.com',
        'developer_contact_email' => 'support@aerofind.online',
        'admin_username' => 'admin',
        'admin_password_hash' => '',
        'staff_username' => 'admin',
        'staff_password_hash' => '',
        'email_delivery_method' => 'php_mail',
        'smtp_host' => '',
        'smtp_port' => '587',
        'smtp_encryption' => 'tls',
        'smtp_user' => 'smtp_user@example.com',
        'smtp_password' => 'your_smtp_password',
        'smtp_from_email' => 'noreply@example.com',
    ];

    $rows = $pdo->query("SELECT key, value FROM settings")->fetchAll();
    foreach ($rows as $row) {
        $defaults[$row['key']] = $row['value'];
    }
    return $defaults;
}

function af_setting(PDO $pdo, string $key, string $value): void {
    $stmt = $pdo->prepare("INSERT OR REPLACE INTO settings (key, value) VALUES (?, ?)");
    $stmt->execute([$key, $value]);
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
        return $value;
    }
    return filter_var($value, FILTER_VALIDATE_URL) ? $value : '';
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

    $dir = __DIR__ . '/uploads/' . ($field === 'company_logo_upload' || $field === 'favicon_upload' ? 'branding' : 'cabin_items');
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    $safe_prefix = preg_replace('/[^A-Za-z0-9_-]/', '_', $prefix);
    $filename = $safe_prefix . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $extensions[$mime];
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
        return '<img src="' . af_h($logo) . '" alt="' . af_h($settings['company_short_name'] ?? 'Company') . '" class="' . af_h($classes) . ' object-contain bg-white p-1">';
    }
    return '<span class="' . af_h($text_classes) . '">' . af_h($initials) . '</span>';
}

function af_email_accent(array $settings): string {
    $accent = trim((string) ($settings['company_accent'] ?? '#f43f5e'));
    return preg_match('/^#[0-9A-Fa-f]{6}$/', $accent) ? $accent : '#f43f5e';
}

function af_email_initials(array $settings): string {
    return strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $settings['company_initials'] ?? 'AF'), 0, 4)) ?: 'AF';
}

function af_email_paragraphs(array $paragraphs): string {
    $html = '';
    foreach ($paragraphs as $paragraph) {
        $paragraph = trim((string) $paragraph);
        if ($paragraph === '') {
            continue;
        }
        $html .= '<p class="body-copy" style="margin:0 0 14px; color:#334155; font-size:15px; line-height:1.65;">' . af_h($paragraph) . '</p>';
    }
    return $html;
}

function af_email_rows(array $rows): string {
    $html = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;">';
    foreach ($rows as $row) {
        $label = af_h($row['label'] ?? '');
        $value = trim((string) ($row['value'] ?? ''));
        $highlight = !empty($row['highlight']);
        $value_style = $highlight ? 'color:#0f172a; font-weight:800; letter-spacing:.02em;' : 'color:#0f172a; font-weight:700;';
        $html .= '<tr>';
        $html .= '<td style="padding:12px 0; border-bottom:1px solid #e2e8f0; color:#64748b; font-size:12px; font-weight:800; text-transform:uppercase; letter-spacing:.08em; vertical-align:top;">' . $label . '</td>';
        $html .= '<td align="right" style="padding:12px 0 12px 18px; border-bottom:1px solid #e2e8f0; ' . $value_style . ' font-size:14px; line-height:1.45; vertical-align:top;">' . af_h($value !== '' ? $value : 'Not supplied') . '</td>';
        $html .= '</tr>';
    }
    $html .= '</table>';
    return $html;
}

function af_email_data_table(array $headers, array $rows, string $empty_message): string {
    if (!$rows) {
        return '<p style="margin:0; color:#64748b; font-size:14px; line-height:1.6;">' . af_h($empty_message) . '</p>';
    }

    $html = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse; font-size:13px;">';
    $html .= '<tr>';
    foreach ($headers as $index => $header) {
        $align = $index === count($headers) - 1 ? 'right' : 'left';
        $html .= '<th align="' . $align . '" style="padding:0 0 10px; color:#64748b; font-size:11px; font-weight:800; text-transform:uppercase; letter-spacing:.08em;">' . af_h($header) . '</th>';
    }
    $html .= '</tr>';
    foreach ($rows as $row) {
        $html .= '<tr>';
        foreach (array_values($row) as $index => $value) {
            $align = $index === count($headers) - 1 ? 'right' : 'left';
            $weight = $index === 0 ? 'font-weight:800;' : 'font-weight:600;';
            $html .= '<td align="' . $align . '" style="padding:10px 0; border-top:1px solid #e2e8f0; color:#0f172a; line-height:1.45; ' . $weight . '">' . af_h($value) . '</td>';
        }
        $html .= '</tr>';
    }
    $html .= '</table>';
    return $html;
}

function af_render_email(array $settings, array $options): string {
    $company_name = $settings['company_name'] ?? 'AeroFind Cabin Recovery';
    $company_short_name = $settings['company_short_name'] ?? 'AeroFind';
    $tagline = $settings['company_tagline'] ?? 'Cabin Operations Division';
    $accent = af_email_accent($settings);
    $title = (string) ($options['title'] ?? $company_name);
    $preheader = (string) ($options['preheader'] ?? $title);
    $eyebrow = (string) ($options['eyebrow'] ?? 'Cabin Recovery');
    $paragraphs = $options['paragraphs'] ?? [];
    $rows = $options['rows'] ?? [];
    $sections = $options['sections'] ?? [];
    $note_title = trim((string) ($options['note_title'] ?? ''));
    $note = trim((string) ($options['note'] ?? ''));
    $footer = trim((string) ($options['footer'] ?? 'This is an automated message from the cabin recovery portal.'));
    $initials = af_email_initials($settings);

    $row_block = $rows ? '<div class="detail-card" style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:12px; padding:6px 18px; margin:22px 0;">' . af_email_rows($rows) . '</div>' : '';
    $section_block = '';
    foreach ($sections as $section) {
        $section_title = trim((string) ($section['title'] ?? ''));
        $section_html = (string) ($section['html'] ?? '');
        if ($section_html === '') {
            continue;
        }
        $section_block .= '<div style="margin:24px 0 0;">';
        if ($section_title !== '') {
            $section_block .= '<h3 style="margin:0 0 12px; color:#0f172a; font-size:13px; font-weight:900; text-transform:uppercase; letter-spacing:.09em;">' . af_h($section_title) . '</h3>';
        }
        $section_block .= '<div style="background:#ffffff; border:1px solid #e2e8f0; border-radius:12px; padding:18px;">' . $section_html . '</div></div>';
    }
    $note_block = $note !== ''
        ? '<div style="background:#fff7ed; border-left:4px solid ' . $accent . '; border-radius:10px; padding:16px 18px; margin:24px 0 0;">'
            . ($note_title !== '' ? '<p style="margin:0 0 6px; color:#0f172a; font-size:12px; font-weight:900; text-transform:uppercase; letter-spacing:.08em;">' . af_h($note_title) . '</p>' : '')
            . '<p style="margin:0; color:#334155; font-size:14px; line-height:1.6;">' . nl2br(af_h($note)) . '</p></div>'
        : '';

    return '<!doctype html>
<html>
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>' . af_h($title) . '</title>
    <style>
        @media only screen and (max-width: 620px) {
            .email-shell { width: 100% !important; }
            .email-card { padding: 28px 20px !important; border-radius: 0 !important; }
            .brand-title { font-size: 18px !important; }
            .email-title { font-size: 24px !important; }
            .body-copy { font-size: 14px !important; }
        }
    </style>
</head>
<body style="margin:0; padding:0; background:#e2e8f0; font-family:Arial, Helvetica, sans-serif; color:#0f172a;">
    <span style="display:none !important; visibility:hidden; opacity:0; color:transparent; height:0; width:0; overflow:hidden;">' . af_h($preheader) . '</span>
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse; background:#e2e8f0;">
        <tr>
            <td align="center" style="padding:28px 12px;">
                <table role="presentation" class="email-shell" width="600" cellpadding="0" cellspacing="0" style="width:600px; max-width:600px; border-collapse:collapse;">
                    <tr>
                        <td class="email-card" style="background:#ffffff; border-radius:16px; overflow:hidden; border:1px solid #cbd5e1; box-shadow:0 16px 40px rgba(15,23,42,.12);">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;">
                                <tr>
                                    <td style="background:#0f172a; padding:26px 28px;">
                                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;">
                                            <tr>
                                                <td width="56" style="vertical-align:middle;">
                                                    <div style="width:48px; height:48px; border-radius:12px; background:' . $accent . '; color:#ffffff; font-size:18px; font-weight:900; line-height:48px; text-align:center;">' . af_h($initials) . '</div>
                                                </td>
                                                <td style="vertical-align:middle;">
                                                    <p class="brand-title" style="margin:0; color:#ffffff; font-size:20px; font-weight:900; letter-spacing:0;">' . af_h($company_short_name) . '</p>
                                                    <p style="margin:4px 0 0; color:#cbd5e1; font-size:12px; font-weight:700; text-transform:uppercase; letter-spacing:.08em;">' . af_h($tagline) . '</p>
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                                <tr>
                                    <td style="padding:32px 32px 30px;">
                                        <p style="margin:0 0 10px; color:' . $accent . '; font-size:12px; font-weight:900; text-transform:uppercase; letter-spacing:.09em;">' . af_h($eyebrow) . '</p>
                                        <h1 class="email-title" style="margin:0 0 18px; color:#0f172a; font-size:28px; line-height:1.2; font-weight:900; letter-spacing:0;">' . af_h($title) . '</h1>
                                        ' . af_email_paragraphs($paragraphs) . '
                                        ' . $row_block . '
                                        ' . $section_block . '
                                        ' . $note_block . '
                                    </td>
                                </tr>
                                <tr>
                                    <td style="background:#f8fafc; border-top:1px solid #e2e8f0; padding:20px 32px;">
                                        <p style="margin:0; color:#64748b; font-size:12px; line-height:1.6;">' . af_h($footer) . '</p>
                                        <p style="margin:8px 0 0; color:#94a3b8; font-size:11px; line-height:1.5;">' . af_h($company_name) . '</p>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>';
}
