<?php
require_once __DIR__ . '/bootstrap.php';
af_security_headers();
af_start_secure_session();

$db_file = __DIR__ . '/cabin_db.sqlite';
$pdo = af_db();
$db_settings = af_settings($pdo);

function normalize_tag($tag)
{
    if (!$tag)
        return "";
    $tag = strtoupper(trim($tag));
    // Remove all whitespace
    $tag = preg_replace('/\s+/', '', $tag);

    // ID- check
    if (preg_match('/^ID-?(\d+.*)$/', $tag, $matches)) {
        return "ID-" . $matches[1];
    }

    // PP- check
    $tag_clean = str_replace('ID', '', $tag);
    if (preg_match('/^PP-?(\d+.*)$/', $tag_clean, $matches)) {
        return "PP-" . $matches[1];
    }

    return $tag;
}

function next_item_tag(PDO $pdo)
{
    $stmt = $pdo->query("
        SELECT tag_no
        FROM (
            SELECT tag_no FROM items WHERE tag_no GLOB 'ID-[0-9]*'
            UNION ALL
            SELECT tag_no FROM pending_reports WHERE tag_no GLOB 'ID-[0-9]*'
        )
        ORDER BY CAST(substr(tag_no, 4) AS INTEGER) DESC
        LIMIT 1
    ");
    $last_tag = $stmt->fetchColumn();
    if (!$last_tag || !preg_match('/^ID-(\d+)/i', $last_tag, $matches)) {
        return 'ID-0001';
    }

    $width = strlen($matches[1]);
    $next_num = ((int) $matches[1]) + 1;
    return 'ID-' . str_pad((string) $next_num, max(4, $width), '0', STR_PAD_LEFT);
}

function next_passport_tag(PDO $pdo)
{
    $stmt = $pdo->query("
        SELECT tag_no
        FROM items
        WHERE tag_no GLOB 'PP-[0-9]*'
        ORDER BY CAST(substr(tag_no, 4) AS INTEGER) DESC
        LIMIT 1
    ");
    $last_tag = $stmt->fetchColumn();
    if (!$last_tag || !preg_match('/^PP-(\d+)/i', $last_tag, $matches)) {
        return 'PP-0001';
    }

    $width = strlen($matches[1]);
    $next_num = ((int) $matches[1]) + 1;
    return 'PP-' . str_pad((string) $next_num, max(4, $width), '0', STR_PAD_LEFT);
}

function remove_claim_request_notes($notes)
{
    $lines = preg_split('/\R+/', (string) $notes);
    $kept = [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        if ($line === 'Passenger claim request submitted') {
            continue;
        }
        if (preg_match('/^Claim submitted \d{4}-\d{2}-\d{2} \d{2}:\d{2}\b/', $line)) {
            continue;
        }
        $kept[] = $line;
    }
    return implode("\n", $kept);
}

function staff_email_plain_text($body_html)
{
    $text = preg_replace('/<\s*br\s*\/?>/i', "\n", $body_html);
    $text = preg_replace('/<\/\s*(p|div|h[1-6]|tr|table)\s*>/i', "\n", $text);
    $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace("/[ \t]+/", ' ', $text);
    $text = preg_replace("/\n{3,}/", "\n\n", $text);
    return trim($text);
}

function send_staff_php_mail($to, $subject, $body_html, $from)
{
    $to = trim((string) $to);
    $from = trim((string) $from);
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    $safe_from = filter_var($from, FILTER_VALIDATE_EMAIL) ? $from : 'noreply@aerofind.online';
    $safe_subject = str_replace(["\r", "\n"], ' ', $subject);
    $domain = substr(strrchr($safe_from, "@"), 1) ?: 'aerofind.online';
    $boundary = 'af_' . bin2hex(random_bytes(12));
    $message_id = sprintf('<%s.%s@%s>', time(), bin2hex(random_bytes(6)), $domain);

    $headers = [];
    $headers[] = "From: AeroFind Cabin Recovery <$safe_from>";
    $headers[] = "Reply-To: $safe_from";
    $headers[] = "Date: " . date(DATE_RFC2822);
    $headers[] = "Message-ID: $message_id";
    $headers[] = "MIME-Version: 1.0";
    $headers[] = "Content-Type: multipart/alternative; boundary=\"$boundary\"";
    $headers[] = "X-Mailer: AeroFind Cabin Portal";

    $body = "--$boundary\r\n";
    $body .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $body .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
    $body .= staff_email_plain_text($body_html) . "\r\n\r\n";
    $body .= "--$boundary\r\n";
    $body .= "Content-Type: text/html; charset=UTF-8\r\n";
    $body .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
    $body .= $body_html . "\r\n\r\n";
    $body .= "--$boundary--";

    $header_text = str_replace("\r\n", "\n", implode("\r\n", $headers));
    $sent = @mail($to, $safe_subject, $body, $header_text, "-f$safe_from");
    if (!$sent) {
        $sent = @mail($to, $safe_subject, $body, $header_text);
    }

    $mail_dir = __DIR__ . '/uploads/emails';
    if (!is_dir($mail_dir)) {
        @mkdir($mail_dir, 0755, true);
    }
    $safe_to = preg_replace('/[^A-Za-z0-9_-]/', '_', $to);
    $safe_file_subject = preg_replace('/[^A-Za-z0-9_-]/', '_', substr($safe_subject, 0, 32));
    $mail_filename = $mail_dir . '/' . time() . '_' . $safe_to . '_' . $safe_file_subject . '.eml';
    @file_put_contents($mail_filename, "To: $to\nSubject: $safe_subject\n$header_text\n\n$body");

    return $sent;
}

function send_staff_configured_email($to, $subject, $body_html, array $settings): array
{
    $to = trim((string) $to);
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return ['sent' => false, 'method' => 'none', 'error' => "Invalid recipient email address: $to"];
    }

    $method = $settings['email_delivery_method'] ?? 'php_mail';
    $from = $settings['smtp_from_email'] ?? 'noreply@aerofind.online';
    if ($method !== 'smtp' || empty($settings['smtp_host'])) {
        $sent = send_staff_php_mail($to, $subject, $body_html, $from);
        return ['sent' => $sent, 'method' => 'php_mail', 'error' => $sent ? '' : 'PHP mail() did not confirm delivery.'];
    }

    $host = trim($settings['smtp_host']);
    $port = (int) ($settings['smtp_port'] ?? '587');
    $user = trim($settings['smtp_user'] ?? '');
    $pass = $settings['smtp_password'] ?? '';
    $encryption = $settings['smtp_encryption'] ?? 'tls';
    $safe_from = filter_var($from, FILTER_VALIDATE_EMAIL) ? $from : 'noreply@aerofind.online';

    try {
        $socket_host = $encryption === 'ssl' ? 'ssl://' . $host : $host;
        $socket = @fsockopen($socket_host, $port, $errno, $errstr, 8);
        if (!$socket) {
            return ['sent' => false, 'method' => 'smtp', 'error' => "Could not connect to SMTP server $host:$port ($errno: $errstr)"];
        }
        stream_set_timeout($socket, 10);
        $read_resp = function ($socket) {
            $data = '';
            while ($str = fgets($socket, 515)) {
                $data .= $str;
                if (substr($str, 3, 1) === ' ') {
                    break;
                }
            }
            return $data;
        };
        $expect = function ($socket, $codes, $step) use ($read_resp) {
            $response = $read_resp($socket);
            foreach ((array) $codes as $code) {
                if (strpos($response, (string) $code) === 0) {
                    return;
                }
            }
            throw new Exception("$step failed: " . trim($response));
        };

        $expect($socket, 220, 'SMTP banner');
        fwrite($socket, "EHLO " . ($_SERVER['SERVER_NAME'] ?: 'localhost') . "\r\n");
        $expect($socket, 250, 'EHLO');
        if ($encryption === 'tls') {
            fwrite($socket, "STARTTLS\r\n");
            $expect($socket, 220, 'STARTTLS');
            if (!@stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new Exception('STARTTLS failed');
            }
            fwrite($socket, "EHLO " . ($_SERVER['SERVER_NAME'] ?: 'localhost') . "\r\n");
            $expect($socket, 250, 'EHLO after STARTTLS');
        }
        if ($user !== '' && $pass !== '') {
            fwrite($socket, "AUTH LOGIN\r\n");
            $expect($socket, 334, 'AUTH LOGIN');
            fwrite($socket, base64_encode($user) . "\r\n");
            $expect($socket, 334, 'SMTP username');
            fwrite($socket, base64_encode($pass) . "\r\n");
            $expect($socket, 235, 'SMTP authentication');
        } elseif ($user !== '' || $pass !== '') {
            throw new Exception('SMTP username and password must both be set.');
        }

        fwrite($socket, "MAIL FROM: <$safe_from>\r\n");
        $expect($socket, 250, 'MAIL FROM');
        fwrite($socket, "RCPT TO: <$to>\r\n");
        $expect($socket, [250, 251], 'RCPT TO');
        fwrite($socket, "DATA\r\n");
        $expect($socket, 354, 'DATA');

        $safe_subject = str_replace(["\r", "\n"], ' ', $subject);
        $domain = substr(strrchr($safe_from, "@"), 1) ?: 'aerofind.online';
        $boundary = 'af_' . bin2hex(random_bytes(12));
        $headers = [
            "To: <$to>",
            "Subject: $safe_subject",
            "From: AeroFind Cabin Recovery <$safe_from>",
            "Reply-To: $safe_from",
            "Date: " . date(DATE_RFC2822),
            "Message-ID: <" . time() . "." . bin2hex(random_bytes(6)) . "@$domain>",
            "MIME-Version: 1.0",
            "Content-Type: multipart/alternative; boundary=\"$boundary\"",
            "X-Mailer: AeroFind Cabin Portal"
        ];
        $body = "--$boundary\r\n";
        $body .= "Content-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n";
        $body .= staff_email_plain_text($body_html) . "\r\n\r\n";
        $body .= "--$boundary\r\n";
        $body .= "Content-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n";
        $body .= $body_html . "\r\n\r\n--$boundary--";
        fwrite($socket, implode("\r\n", $headers) . "\r\n\r\n" . preg_replace('/^\./m', '..', $body) . "\r\n.\r\n");
        $expect($socket, 250, 'Message delivery');
        fwrite($socket, "QUIT\r\n");
        fclose($socket);
        return ['sent' => true, 'method' => 'smtp', 'error' => ''];
    } catch (Exception $e) {
        if (isset($socket) && is_resource($socket)) {
            fclose($socket);
        }
        return ['sent' => false, 'method' => 'smtp', 'error' => $e->getMessage()];
    }
}

$staff_username = $db_settings['staff_username'] ?? 'admin';
$staff_password_hash = $db_settings['staff_password_hash'] ?? '';
$admin_username = $db_settings['admin_username'] ?? 'admin';
$admin_password_hash = $db_settings['admin_password_hash'] ?? '';
$company_name = $db_settings['company_name'] ?? 'AeroFind Cabin Recovery';
$company_short_name = $db_settings['company_short_name'] ?? 'AeroFind';
$company_tagline = $db_settings['company_tagline'] ?? 'Cabin Operations Division';
$company_initials = $db_settings['company_initials'] ?? 'AF';
$company_logo = af_valid_url_or_path($db_settings['company_logo'] ?? '');
$favicon_url = af_valid_url_or_path($db_settings['favicon_url'] ?? '');
$staff_login_email = $db_settings['staff_login_email'] ?? 'staff@aerofind.online';
$developer_contact_email = filter_var($db_settings['developer_contact_email'] ?? '', FILTER_VALIDATE_EMAIL) ? $db_settings['developer_contact_email'] : '';

function station_login_settings(string $station): ?array
{
    $db_file = af_station_db_file($station);
    if (!file_exists($db_file)) {
        return null;
    }

    try {
        $station_pdo = new PDO('sqlite:' . $db_file);
        $station_pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $station_pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        return af_settings($station_pdo);
    } catch (Exception $e) {
        error_log("Could not read login settings for station [$station]: " . $e->getMessage());
        return null;
    }
}

function find_staff_login_station(string $username, string $password): ?string
{
    $stations = af_stations();
    $current_station = af_get_current_station();
    if (isset($stations[$current_station])) {
        $stations = [$current_station => $stations[$current_station]] + $stations;
    }

    foreach ($stations as $code => $name) {
        $settings = station_login_settings($code);
        if ($settings === null) {
            continue;
        }

        $station_staff_username = (string) ($settings['staff_username'] ?? '');
        $station_staff_hash = (string) ($settings['staff_password_hash'] ?? '');
        if ($station_staff_hash !== '' && hash_equals($station_staff_username, $username) && password_verify($password, $station_staff_hash)) {
            return $code;
        }
    }

    return null;
}

function save_stations(array $stations): bool
{
    ksort($stations);
    $json = json_encode($stations, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    return $json !== false && file_put_contents(__DIR__ . '/stations.json', $json . "\n", LOCK_EX) !== false;
}

function station_pdo_for_admin(string $station): PDO
{
    $db_file = af_station_db_file($station);
    $is_new_db = !file_exists($db_file);
    $pdo = new PDO('sqlite:' . $db_file);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

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

    if ($is_new_db && file_exists(__DIR__ . '/cabin_db.sqlite')) {
        $master = new PDO('sqlite:' . __DIR__ . '/cabin_db.sqlite');
        $master->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        foreach (['settings', 'airlines'] as $table) {
            $rows = $master->query("SELECT * FROM $table")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $row) {
                $columns = array_keys($row);
                $placeholders = array_map(fn($column) => ':' . $column, $columns);
                $stmt = $pdo->prepare("INSERT OR IGNORE INTO $table (" . implode(',', $columns) . ") VALUES (" . implode(',', $placeholders) . ")");
                foreach ($row as $column => $value) {
                    $stmt->bindValue(':' . $column, $value);
                }
                $stmt->execute();
            }
        }
    }

    return $pdo;
}

// Pull messages from session
$success_msg = $_SESSION['success_msg'] ?? null;
unset($_SESSION['success_msg']);

$login_error = $_SESSION['login_error'] ?? null;
unset($_SESSION['login_error']);

if (isset($_POST['login_username']) && isset($_POST['login_password'])) {
    $login_username = (string) $_POST['login_username'];
    $login_password = (string) $_POST['login_password'];
    $admin_password_ok = $admin_password_hash !== ''
        ? password_verify($login_password, $admin_password_hash)
        : hash_equals('password', $login_password);
    $staff_password_ok = $staff_password_hash !== ''
        ? password_verify($login_password, $staff_password_hash)
        : hash_equals('password', (string) $_POST['login_password']);

    if (hash_equals($admin_username, $login_username) && $admin_password_ok) {
        session_regenerate_id(true);
        $_SESSION['cabin_staff_loggedin'] = true;
        $_SESSION['cabin_staff_role'] = 'admin';
        $_SESSION['active_station'] = af_get_current_station();
        header("Location: staff.php");
        exit;
    }

    $staff_station = find_staff_login_station($login_username, $login_password);
    if ($staff_station !== null) {
        session_regenerate_id(true);
        $_SESSION['cabin_staff_loggedin'] = true;
        $_SESSION['cabin_staff_role'] = 'staff';
        $_SESSION['active_station'] = $staff_station;
        if (!headers_sent()) {
            setcookie('af_station', $staff_station, time() + (86400 * 30), "/", "", false, true);
        }
        header("Location: staff.php");
        exit;
    } else {
        $_SESSION['login_error'] = "Invalid credentials.";
        header("Location: staff.php");
        exit;
    }
}

if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: staff.php");
    exit;
}

if (isset($_SESSION['cabin_staff_loggedin']) && $_SESSION['cabin_staff_loggedin'] === true) {
    $is_admin = (($_SESSION['cabin_staff_role'] ?? 'staff') === 'admin');

    // ── AJAX: purge preview (count only, no delete) ─────────────────────────
    if (isset($_GET['purge_preview']) && $is_admin) {
        header('Content-Type: application/json');
        $range_type = $_GET['range_type'] ?? 'preset';
        $raw_preset = $_GET['preset_period'] ?? '30';
        $custom_start = $_GET['custom_start'] ?? '';
        $custom_end = $_GET['custom_end'] ?? '';

        if ($range_type === 'preset') {
            if (preg_match('/^year:(\d{4})$/', $raw_preset, $m)) {
                $year = (int) $m[1];
                $start_dt = "$year-01-01 00:00:00";
                $end_dt = "$year-12-31 23:59:59";
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM items WHERE created_at >= ? AND created_at <= ?");
                $stmt->execute([$start_dt, $end_dt]);
                echo json_encode(['count' => (int) $stmt->fetchColumn(), 'from' => "$year-01-01", 'to' => "$year-12-31"]);
            } else {
                $days = max(1, (int) $raw_preset);
                $cutoff = date('Y-m-d', strtotime("-$days days"));
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM items WHERE created_at < ?");
                $stmt->execute([$cutoff . ' 00:00:00']);
                $cnt = (int) $stmt->fetchColumn();
                // oldest record date for display
                $oldest = $pdo->query("SELECT MIN(date(created_at)) FROM items")->fetchColumn();
                echo json_encode(['count' => $cnt, 'from' => ($oldest ?: '—'), 'to' => $cutoff]);
            }
        } elseif ($range_type === 'custom' && $custom_start && $custom_end) {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM items WHERE created_at >= ? AND created_at <= ?");
            $stmt->execute([$custom_start . ' 00:00:00', $custom_end . ' 23:59:59']);
            echo json_encode(['count' => (int) $stmt->fetchColumn(), 'from' => $custom_start, 'to' => $custom_end]);
        } else {
            echo json_encode(['count' => 0, 'from' => '', 'to' => '']);
        }
        exit;
    }
    // ────────────────────────────────────────────────────────────────────────


    // Fetch Settings using shared bootstrap function with defaults
    $db_settings = af_settings($pdo);

    // Default values mapped
    $company_name = $db_settings['company_name'] ?? 'AeroFind Cabin Recovery';
    $company_short_name = $db_settings['company_short_name'] ?? 'AeroFind';
    $company_tagline = $db_settings['company_tagline'] ?? 'Cabin Operations Division';
    $company_initials = $db_settings['company_initials'] ?? 'AF';
    $company_logo = af_valid_url_or_path($db_settings['company_logo'] ?? '');
    $favicon_url = af_valid_url_or_path($db_settings['favicon_url'] ?? '');
    $staff_username = $db_settings['staff_username'] ?? 'admin';
    $admin_username = $db_settings['admin_username'] ?? 'admin';
    $staff_login_email = $db_settings['staff_login_email'] ?? 'staff@aerofind.online';
    $developer_contact_email = filter_var($db_settings['developer_contact_email'] ?? '', FILTER_VALIDATE_EMAIL) ? $db_settings['developer_contact_email'] : '';
    $passenger_view_days = $db_settings['passenger_view_days'] ?? 30;
    $items_per_page = (int) ($db_settings['items_per_page'] ?? 20);
    if ($items_per_page < 1) {
        $items_per_page = 20;
    }
    $passenger_card_layout_value = $db_settings['passenger_card_layout'] ?? 'dual';
    $passenger_card_layout = in_array($passenger_card_layout_value, ['single', 'dual'], true) ? $passenger_card_layout_value : 'dual';
    $pickup_info = $db_settings['pickup_info'] ?? 'Main Terminal, Cabin Recovery Lost & Found Desk. Please bring a valid ID and the reference code.';
    $staff_notification_email = $db_settings['staff_notification_email'] ?? 'staff@aerofind.online';
    $smtp_host = $db_settings['smtp_host'] ?? '';
    $email_delivery_method_value = $db_settings['email_delivery_method'] ?? 'php_mail';
    $email_delivery_method = in_array($email_delivery_method_value, ['php_mail', 'smtp'], true) ? $email_delivery_method_value : 'php_mail';
    $smtp_port = $db_settings['smtp_port'] ?? '587';
    $smtp_encryption = $db_settings['smtp_encryption'] ?? 'tls';
    $smtp_user = $db_settings['smtp_user'] ?? 'noreply@aerofind.online';
    $smtp_password = $db_settings['smtp_password'] ?? '';
    $smtp_from_email = $db_settings['smtp_from_email'] ?? 'noreply@aerofind.online';

    // Fetch Airlines early for modals and templates
    $al = $pdo->query("SELECT * FROM airlines ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

    // Distinct calendar years that have at least one item record — used by the purge UI
    $purge_years = $pdo->query(
        "SELECT DISTINCT CAST(strftime('%Y', created_at) AS INTEGER) AS yr FROM items ORDER BY yr ASC"
    )->fetchAll(PDO::FETCH_COLUMN);

    // Dynamic tag assignment aligned with the current ID-#### pattern.
    $auto_tag = next_item_tag($pdo);
    $auto_passport_tag = next_passport_tag($pdo);

    if (isset($_POST['action'])) {
        af_verify_csrf();
        if ($_POST['action'] === 'add_item') {
            $tag = normalize_tag($_POST['tag_no'] ?? '');
            if ($tag === '' || $tag === 'ID-') {
                $tag = next_item_tag($pdo);
            } elseif ($tag === 'PP-') {
                $tag = next_passport_tag($pdo);
            }
            $photo_name = basename(af_store_uploaded_image('photo', $tag));

            $created_at = !empty($_POST['created_at']) ? $_POST['created_at'] . ' ' . date('H:i:s') : date('Y-m-d H:i:s');

            $stmt = $pdo->prepare("INSERT INTO items (tag_no, item_description, contents, pax_name, pax_contact_no, pax_email, status, other_info, comments, user_comments, delivery_info, photo, created_at) VALUES (:tag, :desc, :contents, :name, :contact, :email, :status, :other, :comments, :user_comments, :delivery_info, :photo, :created_at)");
            $stmt->execute([
                ':tag' => $tag,
                ':desc' => $_POST['item_description'],
                ':contents' => $_POST['contents'] ?? '',
                ':name' => $_POST['pax_name'] ?? '',
                ':contact' => $_POST['pax_contact_no'] ?? '',
                ':email' => $_POST['pax_email'] ?? '',
                ':status' => $_POST['status'],
                ':other' => $_POST['other_info'] ?? '',
                ':comments' => $_POST['comments'] ?? '',
                ':user_comments' => $_POST['user_comments'] ?? '',
                ':delivery_info' => $_POST['delivery_info'] ?? '',
                ':photo' => $photo_name,
                ':created_at' => $created_at
            ]);
            $success_msg = "Item added successfully.";
        }
        if ($_POST['action'] === 'approve_pending_report') {
            $report_id = (int) ($_POST['report_id'] ?? 0);
            $stmt = $pdo->prepare("SELECT * FROM pending_reports WHERE id = ? AND status = 'Pending'");
            $stmt->execute([$report_id]);
            $report = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$report) {
                $login_error = "Pending report not found or already reviewed.";
            } else {
                $tag = normalize_tag($report['tag_no'] ?? '');
                if ($tag === '') {
                    $tag = next_item_tag($pdo);
                }
                $exists_stmt = $pdo->prepare("SELECT COUNT(*) FROM items WHERE tag_no = ?");
                $exists_stmt->execute([$tag]);
                if ((int) $exists_stmt->fetchColumn() > 0) {
                    $tag = next_item_tag($pdo);
                }
                $flight_info = trim(($report['airline'] ?? '') . ' ' . ($report['flight_number'] ?? ''));
                $note = "Approved passenger lost report " . ($report['report_ref'] ?? '');
                $stmt = $pdo->prepare("INSERT INTO items (tag_no, item_description, pax_name, pax_contact_no, pax_email, other_info, comments, user_comments, status, photo, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'Lost', ?, datetime('now'))");
                $stmt->execute([
                    $tag,
                    $report['item_description'] ?? 'No description',
                    $report['pax_name'] ?? '',
                    $report['pax_contact_no'] ?? '',
                    $report['pax_email'] ?? '',
                    $flight_info,
                    $report['seat_info'] ?? '',
                    $note,
                    $report['photo'] ?? ''
                ]);
                $stmt = $pdo->prepare("UPDATE pending_reports SET status = 'Approved', matched_tag_no = ?, reviewed_at = datetime('now') WHERE id = ?");
                $stmt->execute([$tag, $report_id]);

                $mail = ['sent' => false, 'error' => ''];
                if (!empty($report['pax_email'])) {
                    $html = af_render_email($db_settings, [
                        'title' => 'Lost report approved',
                        'preheader' => "Your report has been added as $tag.",
                        'eyebrow' => 'Report reviewed',
                        'paragraphs' => [
                            'Dear ' . ($report['pax_name'] ?: 'Passenger') . ',',
                            'Your lost item report has been reviewed and added to our cabin recovery records.'
                        ],
                        'rows' => [
                            ['label' => 'Item reference', 'value' => $tag, 'highlight' => true],
                            ['label' => 'Report reference', 'value' => $report['report_ref']],
                            ['label' => 'Item', 'value' => $report['item_description']]
                        ],
                        'note_title' => 'What happens next',
                        'note' => 'Staff will contact you if a matching item is recovered.'
                    ]);
                    $mail = send_staff_configured_email($report['pax_email'], "$company_name - Lost Report Approved [$tag]", $html, $db_settings);
                }
                $success_msg = $mail['sent'] ? "Pending report approved and passenger email sent." : "Pending report approved. Passenger email was not sent.";
                if (!$mail['sent'] && !empty($mail['error'])) {
                    error_log("AeroFind pending approval mail warning: " . $mail['error']);
                }
            }
        }
        if ($_POST['action'] === 'duplicate_pending_report') {
            $report_id = (int) ($_POST['report_id'] ?? 0);
            $existing_tag = normalize_tag($_POST['existing_tag_no'] ?? '');
            $staff_notes = trim($_POST['staff_notes'] ?? '');
            $stmt = $pdo->prepare("SELECT * FROM pending_reports WHERE id = ? AND status = 'Pending'");
            $stmt->execute([$report_id]);
            $report = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$report) {
                $login_error = "Pending report not found or already reviewed.";
            } elseif ($existing_tag === '') {
                $login_error = "Error: Existing Tag ID is required.";
            } else {
                // Check if the tag actually exists in items table
                $stmt_chk = $pdo->prepare("SELECT COUNT(*) FROM items WHERE tag_no = ?");
                $stmt_chk->execute([$existing_tag]);
                $exists = $stmt_chk->fetchColumn() > 0;
                if (!$exists) {
                    $login_error = "Error: Existing Tag ID '$existing_tag' does not exist in lost reports inventory.";
                } else {
                    $stmt = $pdo->prepare("UPDATE pending_reports SET status = 'Duplicate', matched_tag_no = ?, staff_notes = ?, reviewed_at = datetime('now') WHERE id = ?");
                    $stmt->execute([$existing_tag, $staff_notes, $report_id]);
                    $mail = ['sent' => false, 'error' => ''];
                    if (!empty($report['pax_email'])) {
                        $duplicate_rows = [];
                        if ($existing_tag !== '') {
                            $duplicate_rows[] = ['label' => 'Existing reference', 'value' => $existing_tag, 'highlight' => true];
                        }
                        $html = af_render_email($db_settings, [
                            'title' => 'Lost report reviewed',
                            'preheader' => 'Your report appears to match an existing recovery record.',
                            'eyebrow' => 'Report reviewed',
                            'paragraphs' => [
                                'Dear ' . ($report['pax_name'] ?: 'Passenger') . ',',
                                'Staff reviewed your report and found that this item appears to already be logged in our cabin recovery system.'
                            ],
                            'rows' => $duplicate_rows,
                            'note_title' => 'Next step',
                            'note' => 'Staff will continue using the existing record and contact you if there is an update.'
                        ]);
                        $mail = send_staff_configured_email($report['pax_email'], "$company_name - Lost Report Already Logged [$existing_tag]", $html, $db_settings);
                    }
                    $success_msg = $mail['sent'] ? "Pending report marked as already logged and passenger email sent." : "Pending report marked as already logged. Passenger email was not sent.";
                    if (!$mail['sent'] && !empty($mail['error'])) {
                        error_log("AeroFind duplicate report mail warning: " . $mail['error']);
                    }
                }
            }
        }
        if ($_POST['action'] === 'reject_pending_report') {
            $report_id = (int) ($_POST['report_id'] ?? 0);
            $staff_notes = trim($_POST['staff_notes'] ?? '');
            $stmt = $pdo->prepare("SELECT * FROM pending_reports WHERE id = ? AND status = 'Pending'");
            $stmt->execute([$report_id]);
            $report = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$report) {
                $login_error = "Pending report not found or already reviewed.";
            } elseif ($staff_notes === '') {
                $login_error = "Error: A rejection reason is required.";
            } else {
                $stmt = $pdo->prepare("UPDATE pending_reports SET status = 'Rejected', staff_notes = ?, reviewed_at = datetime('now') WHERE id = ?");
                $stmt->execute([$staff_notes, $report_id]);
                $mail = ['sent' => false, 'error' => ''];
                if (!empty($report['pax_email'])) {
                    $html = af_render_email($db_settings, [
                        'title' => 'Lost report reviewed',
                        'preheader' => 'Your lost item report was reviewed by staff.',
                        'eyebrow' => 'Report reviewed',
                        'paragraphs' => [
                            'Dear ' . ($report['pax_name'] ?: 'Passenger') . ',',
                            'Staff reviewed your lost item report and did not add a new cabin recovery record at this time.'
                        ],
                        'note_title' => $staff_notes !== '' ? 'Staff note' : '',
                        'note' => $staff_notes
                    ]);
                    $report_ref = $report['report_ref'] ?: ('Report ' . $report_id);
                    $mail = send_staff_configured_email($report['pax_email'], "$company_name - Lost Report Not Added [$report_ref]", $html, $db_settings);
                }
                $success_msg = $mail['sent'] ? "Pending report rejected and passenger email sent." : "Pending report rejected. Passenger email was not sent.";
                if (!$mail['sent'] && !empty($mail['error'])) {
                    error_log("AeroFind rejected report mail warning: " . $mail['error']);
                }
            }
        }
        if ($_POST['action'] === 'toggle_autosync') {
            $autosync = file_exists(__DIR__ . '/.autosync');
            if ($autosync)
                unlink(__DIR__ . '/.autosync');
            else
                touch(__DIR__ . '/.autosync');
        }
        if ($_POST['action'] === 'update_item') {
            $tag = normalize_tag($_POST['tag_no'] ?? '');
            $stored_photo = af_store_uploaded_image('photo', $tag);
            $photo_name = $stored_photo !== '' ? basename($stored_photo) : null;
            $requested_status = $_POST['status'] ?? 'Found';
            $user_comments = $_POST['user_comments'] ?? '';
            $delivery_info = $_POST['delivery_info'] ?? '';
            $comments = $_POST['comments'] ?? '';
            $pax_name = $_POST['pax_name'] ?? '';

            $existing_stmt = $pdo->prepare("SELECT status, pax_name, pax_email, item_description FROM items WHERE tag_no = ?");
            $existing_stmt->execute([$tag]);
            $existing_item = $existing_stmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $existing_status = $existing_item['status'] ?? '';
            $restore_from_claim = ($requested_status === 'Found' && $existing_status === 'Claimed');
            $send_lost_email = ($requested_status === 'Lost' && $existing_status !== 'Lost' && !empty($existing_item['pax_email']));
            if ($restore_from_claim) {
                $user_comments = remove_claim_request_notes($user_comments);
                $delivery_info = remove_claim_request_notes($delivery_info);
                $comments = '';
                $pax_name = '';
            }

            if ($photo_name !== null) {
                $stmt = $pdo->prepare("UPDATE items SET 
                    status = :status,
                    user_comments = :user_comments,
                    delivery_info = :delivery_info,
                    comments = :comments,
                    item_description = :desc,
                    pax_name = :pax_name,
                    other_info = :other_info,
                    photo = :photo
                    WHERE tag_no = :tag");
                $stmt->execute([
                    ':status' => $requested_status,
                    ':user_comments' => $user_comments,
                    ':delivery_info' => $delivery_info,
                    ':comments' => $comments,
                    ':desc' => $_POST['item_description'] ?? '',
                    ':pax_name' => $pax_name,
                    ':other_info' => $_POST['other_info'] ?? '',
                    ':photo' => $photo_name,
                    ':tag' => $tag
                ]);
            } else {
                $stmt = $pdo->prepare("UPDATE items SET 
                    status = :status,
                    user_comments = :user_comments,
                    delivery_info = :delivery_info,
                    comments = :comments,
                    item_description = :desc,
                    pax_name = :pax_name,
                    other_info = :other_info
                    WHERE tag_no = :tag");
                $stmt->execute([
                    ':status' => $requested_status,
                    ':user_comments' => $user_comments,
                    ':delivery_info' => $delivery_info,
                    ':comments' => $comments,
                    ':desc' => $_POST['item_description'] ?? '',
                    ':pax_name' => $pax_name,
                    ':other_info' => $_POST['other_info'] ?? '',
                    ':tag' => $tag
                ]);
            }
            if ($restore_from_claim) {
                $stmt = $pdo->prepare("UPDATE items SET pax_email = '', pax_contact_no = '' WHERE tag_no = ?");
                $stmt->execute([$tag]);
            }
            if ($send_lost_email) {
                $lost_subject = "$company_name - Lost Item Status Update for [$tag]";
                $lost_html = af_render_email($db_settings, [
                    'title' => 'Lost item status update',
                    'preheader' => "Status update for item $tag.",
                    'eyebrow' => 'Status update',
                    'paragraphs' => [
                        'Dear ' . ($existing_item['pax_name'] ?: $pax_name ?: 'Passenger') . ',',
                        'We are sorry to inform you that your item has been marked as lost in our cabin recovery system because it has not been recovered.'
                    ],
                    'rows' => [
                        ['label' => 'Reference', 'value' => $tag, 'highlight' => true],
                        ['label' => 'Item', 'value' => $existing_item['item_description'] ?: ($_POST['item_description'] ?? '')]
                    ],
                    'note_title' => 'Record status',
                    'note' => 'If the item is later recovered, our team can update the record and contact you again.'
                ]);
                $lost_mail = send_staff_configured_email($existing_item['pax_email'], $lost_subject, $lost_html, $db_settings);
                if (!$lost_mail['sent']) {
                    error_log("AeroFind lost status mail warning: " . $lost_mail['error']);
                }
                $success_msg = $lost_mail['sent'] ? "Item details updated and passenger lost email sent." : "Item details updated. Passenger lost email was not sent.";
            } else {
                $success_msg = "Item details updated successfully.";
            }
        }
        if ($_POST['action'] === 'delete_item') {
            $tag = normalize_tag($_POST['tag_no'] ?? '');
            if ($tag) {
                $stmtDel = $pdo->prepare("INSERT OR IGNORE INTO deleted_items (tag_no) VALUES (?)");
                $stmtDel->execute([$tag]);
            }
            $stmt = $pdo->prepare("DELETE FROM items WHERE tag_no = ?");
            $stmt->execute([$tag]);
            $success_msg = "Item deleted successfully.";
        }
        if ($_POST['action'] === 'pickup_item') {
            $tag = normalize_tag($_POST['tag_no'] ?? '');
            $stmt = $pdo->prepare("SELECT item_description, other_info, pax_name, pax_email, pax_contact_no, user_comments, delivery_info FROM items WHERE tag_no = ?");
            $stmt->execute([$tag]);
            $item_for_pickup = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($item_for_pickup) {
                $pickup_stamp = date('Y-m-d H:i');
                $pickup_note = "Picked up on $pickup_stamp";
                if (!empty($item_for_pickup['pax_name'])) {
                    $pickup_note .= " by " . $item_for_pickup['pax_name'];
                }
                $contact_parts = array_filter([
                    $item_for_pickup['pax_email'] ?? '',
                    $item_for_pickup['pax_contact_no'] ?? ''
                ]);
                if (!empty($contact_parts)) {
                    $pickup_note .= " (" . implode(' / ', $contact_parts) . ")";
                }

                $existing_handover = trim($item_for_pickup['delivery_info'] ?? '');
                $updated_handover = trim($existing_handover . ($existing_handover !== '' ? "\n" : '') . $pickup_note);

                $stmt = $pdo->prepare("UPDATE items SET status = 'Delivered', delivery_info = ? WHERE tag_no = ?");
                $stmt->execute([$updated_handover, $tag]);

                $pickup_email_sent = false;
                if (!empty($item_for_pickup['pax_email'])) {
                    $pickup_subject = "AeroFind Cabin Recovery - Pickup Completed for [$tag]";
                    $pickup_html = af_render_email($db_settings, [
                        'title' => 'Pickup completed',
                        'preheader' => "Pickup completed for item $tag.",
                        'eyebrow' => 'Handover complete',
                        'paragraphs' => [
                            'Dear ' . ($item_for_pickup['pax_name'] ?: 'Passenger') . ',',
                            'This confirms that your item pickup has been completed by AeroFind Cabin Recovery.'
                        ],
                        'rows' => [
                            ['label' => 'Reference', 'value' => $tag, 'highlight' => true],
                            ['label' => 'Item', 'value' => $item_for_pickup['item_description'] ?? ''],
                            ['label' => 'Flight/details', 'value' => $item_for_pickup['other_info'] ?? ''],
                            ['label' => 'Completed', 'value' => $pickup_stamp]
                        ],
                        'note_title' => 'Thank you',
                        'note' => 'Thank you for using AeroFind Cabin Recovery.'
                    ]);
                    $pickup_mail = send_staff_configured_email($item_for_pickup['pax_email'], $pickup_subject, $pickup_html, $db_settings);
                    $pickup_email_sent = $pickup_mail['sent'];
                    if (!$pickup_email_sent) {
                        error_log("AeroFind pickup mail warning: " . $pickup_mail['error']);
                    }
                }

                $success_msg = $pickup_email_sent ? "Item marked as picked up and passenger email sent." : "Item marked as picked up. Passenger pickup email was not sent.";
            } else {
                $login_error = "Item not found.";
            }
        }
        if ($_POST['action'] === 'add_airline') {
            if (!$is_admin) {
                $_SESSION['login_error'] = "Only admins can manage airlines.";
                header("Location: staff.php");
                exit;
            }
            $name = $_POST['airline_name'] ?? '';
            $code = strtoupper($_POST['airline_code'] ?? '');
            $logo = "https://www.gstatic.com/flights/airline_logos/70px/" . $code . ".png";
            $domain = ($_POST['airline_domain'] ?? '') ?: strtolower(str_replace(' ', '', $name)) . '.com';
            $stmt = $pdo->prepare("INSERT INTO airlines (name, code, logo, domain) VALUES (?, ?, ?, ?)");
            $stmt->execute([$name, $code, $logo, $domain]);
            $success_msg = "Airline added successfully.";
        }
        if ($_POST['action'] === 'delete_airline') {
            if (!$is_admin) {
                $_SESSION['login_error'] = "Only admins can manage airlines.";
                header("Location: staff.php");
                exit;
            }
            $id = $_POST['airline_id'] ?? 0;
            // Fetch airline code before deleting
            $stmtCode = $pdo->prepare("SELECT code FROM airlines WHERE id = ?");
            $stmtCode->execute([$id]);
            $code = $stmtCode->fetchColumn();
            if ($code) {
                $stmtDel = $pdo->prepare("INSERT OR IGNORE INTO deleted_airlines (code) VALUES (?)");
                $stmtDel->execute([$code]);
            }
            $stmt = $pdo->prepare("DELETE FROM airlines WHERE id = ?");
            $stmt->execute([$id]);
            $success_msg = "Airline deleted successfully.";
        }
        if ($_POST['action'] === 'sync_excel') {
            if (!$is_admin) {
                $_SESSION['login_error'] = "Only admins can sync with Excel.";
                header("Location: staff.php");
                exit;
            }
            syncWithExcel($db_file);
        }
        if ($_POST['action'] === 'restore_backup') {
            if (!$is_admin) {
                $_SESSION['login_error'] = "Only admins can restore backups.";
                header("Location: staff.php");
                exit;
            }
            if (file_exists(__DIR__ . '/cabin_db_backup.sqlite')) {
                copy(__DIR__ . '/cabin_db_backup.sqlite', $db_file);
                $success_msg = "Database restored from backup.";
            } else {
                $login_error = "No backup file found.";
            }
        }
        if ($_POST['action'] === 'delete_records_period') {
            if (!$is_admin) {
                $_SESSION['login_error'] = "Only admins can purge records.";
                header("Location: staff.php");
                exit;
            }

            $range_type = $_POST['range_type'] ?? 'preset';

            if ($range_type === 'preset') {
                $raw_preset = $_POST['preset_period'] ?? '30';

                // Handle calendar-year format: year:2024, year:2025, etc.
                if (preg_match('/^year:(\d{4})$/', $raw_preset, $m)) {
                    $year = (int) $m[1];
                    $start_dt = "$year-01-01 00:00:00";
                    $end_dt = "$year-12-31 23:59:59";

                    $stmtCount = $pdo->prepare("SELECT COUNT(*) FROM items WHERE created_at >= ? AND created_at <= ?");
                    $stmtCount->execute([$start_dt, $end_dt]);
                    $count = $stmtCount->fetchColumn();

                    if ($count > 0) {
                        $stmtInsert = $pdo->prepare("INSERT OR IGNORE INTO deleted_items (tag_no) SELECT tag_no FROM items WHERE created_at >= ? AND created_at <= ?");
                        $stmtInsert->execute([$start_dt, $end_dt]);

                        $stmtDel = $pdo->prepare("DELETE FROM items WHERE created_at >= ? AND created_at <= ?");
                        $stmtDel->execute([$start_dt, $end_dt]);
                        $success_msg = "Successfully purged $count item records from the year $year.";
                    } else {
                        $login_error = "No records found for the year $year.";
                    }
                } else {
                    $preset = (int) $raw_preset;
                    $cutoff = date('Y-m-d H:i:s', strtotime("-$preset days"));

                    $stmtCount = $pdo->prepare("SELECT COUNT(*) FROM items WHERE created_at < ?");
                    $stmtCount->execute([$cutoff]);
                    $count = $stmtCount->fetchColumn();

                    if ($count > 0) {
                        $stmtInsert = $pdo->prepare("INSERT OR IGNORE INTO deleted_items (tag_no) SELECT tag_no FROM items WHERE created_at < ?");
                        $stmtInsert->execute([$cutoff]);

                        $stmtDel = $pdo->prepare("DELETE FROM items WHERE created_at < ?");
                        $stmtDel->execute([$cutoff]);
                        $success_msg = "Successfully purged $count item records older than $preset days.";
                    } else {
                        $login_error = "No records found older than $preset days.";
                    }
                }
            } elseif ($range_type === 'custom') {
                $start = $_POST['custom_start'] ?? '';
                $end = $_POST['custom_end'] ?? '';

                if (!empty($start) && !empty($end)) {
                    $start_dt = $start . " 00:00:00";
                    $end_dt = $end . " 23:59:59";

                    $stmtCount = $pdo->prepare("SELECT COUNT(*) FROM items WHERE created_at >= ? AND created_at <= ?");
                    $stmtCount->execute([$start_dt, $end_dt]);
                    $count = $stmtCount->fetchColumn();

                    if ($count > 0) {
                        $stmtInsert = $pdo->prepare("INSERT OR IGNORE INTO deleted_items (tag_no) SELECT tag_no FROM items WHERE created_at >= ? AND created_at <= ?");
                        $stmtInsert->execute([$start_dt, $end_dt]);

                        $stmtDel = $pdo->prepare("DELETE FROM items WHERE created_at >= ? AND created_at <= ?");
                        $stmtDel->execute([$start_dt, $end_dt]);
                        $success_msg = "Successfully purged $count item records between $start and $end.";
                    } else {
                        $login_error = "No records found for the specified period.";
                    }
                } else {
                    $login_error = "Please specify both start and end dates.";
                }
            }
        }
        if ($_POST['action'] === 'add_station') {
            if (!$is_admin) {
                $_SESSION['login_error'] = "Only admins can manage stations.";
                header("Location: staff.php");
                exit;
            }

            $station_code = strtoupper(trim((string) ($_POST['station_code'] ?? '')));
            $station_name = trim((string) ($_POST['station_name'] ?? ''));
            if (!preg_match('/^[A-Z0-9]{3,4}$/', $station_code)) {
                $login_error = "Station code must be 3 or 4 letters/numbers.";
            } elseif ($station_name === '') {
                $login_error = "Station name is required.";
            } else {
                $stations = af_stations();
                $stations[$station_code] = $station_name;
                if (save_stations($stations)) {
                    station_pdo_for_admin($station_code);
                    $_SESSION['success_msg'] = "Station $station_code added.";
                    header("Location: staff.php?station=" . rawurlencode($station_code));
                    exit;
                }
                $login_error = "Could not save station configuration.";
            }
        }
        if ($_POST['action'] === 'delete_station') {
            if (!$is_admin) {
                $_SESSION['login_error'] = "Only admins can manage stations.";
                header("Location: staff.php");
                exit;
            }

            $station_code = strtoupper(trim((string) ($_POST['station_code'] ?? '')));
            $stations = af_stations();
            if (!isset($stations[$station_code])) {
                $login_error = "Station was not found.";
            } elseif (count($stations) <= 1) {
                $login_error = "At least one station must remain configured.";
            } else {
                unset($stations[$station_code]);
                if (save_stations($stations)) {
                    $next_station = array_key_first($stations);
                    $_SESSION['active_station'] = $next_station;
                    $_SESSION['success_msg'] = "Station $station_code removed from the selector.";
                    header("Location: staff.php?station=" . rawurlencode($next_station));
                    exit;
                }
                $login_error = "Could not save station configuration.";
            }
        }
        if ($_POST['action'] === 'update_station_credentials') {
            if (!$is_admin) {
                $_SESSION['login_error'] = "Only admins can manage station credentials.";
                header("Location: staff.php");
                exit;
            }

            $station_code = strtoupper(trim((string) ($_POST['station_code'] ?? '')));
            $stations = af_stations();
            if (!isset($stations[$station_code])) {
                $login_error = "Station was not found.";
            } else {
                $target_pdo = station_pdo_for_admin($station_code);
                $target_settings = af_settings($target_pdo);
                $station_name = trim((string) ($_POST['station_name'] ?? ''));
                $target_staff_username = trim((string) ($_POST['station_staff_username'] ?? ''));
                $target_admin_username = trim((string) ($_POST['station_admin_username'] ?? ''));

                if ($station_name === '') {
                    $login_error = "Station name is required.";
                } elseif ($target_staff_username === '' || $target_admin_username === '') {
                    $login_error = "Station staff and admin usernames are required.";
                } elseif (!empty($_POST['station_staff_password']) && strlen((string) $_POST['station_staff_password']) < 4) {
                    $login_error = "Station staff password must be at least 4 characters.";
                } elseif (!empty($_POST['station_admin_password']) && strlen((string) $_POST['station_admin_password']) < 4) {
                    $login_error = "Station admin password must be at least 4 characters.";
                } else {
                    $stations[$station_code] = $station_name;
                    save_stations($stations);
                    af_setting($target_pdo, 'staff_username', $target_staff_username);
                    af_setting($target_pdo, 'admin_username', $target_admin_username);
                    if (!empty($_POST['station_staff_password'])) {
                        af_setting($target_pdo, 'staff_password_hash', password_hash((string) $_POST['station_staff_password'], PASSWORD_DEFAULT));
                    } elseif (!array_key_exists('staff_password_hash', $target_settings)) {
                        af_setting($target_pdo, 'staff_password_hash', '');
                    }
                    if (!empty($_POST['station_admin_password'])) {
                        af_setting($target_pdo, 'admin_password_hash', password_hash((string) $_POST['station_admin_password'], PASSWORD_DEFAULT));
                    } elseif (!array_key_exists('admin_password_hash', $target_settings)) {
                        af_setting($target_pdo, 'admin_password_hash', '');
                    }
                    $_SESSION['success_msg'] = "Credentials updated for $station_code.";
                    header("Location: staff.php?station=" . rawurlencode(af_get_current_station()));
                    exit;
                }
            }
        }
        if ($_POST['action'] === 'update_items_per_page') {
            $new_limit = max(1, min(500, (int) ($_POST['items_per_page'] ?? 20)));
            af_setting($pdo, 'items_per_page', (string) $new_limit);
            $_SESSION['success_msg'] = "Pagination rows updated to $new_limit.";
            $query_string = !empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '';
            header("Location: staff.php" . $query_string);
            exit;
        }
        if ($_POST['action'] === 'update_settings') {
            if (!$is_admin) {
                $_SESSION['login_error'] = "Only admins can edit company settings.";
                header("Location: staff.php");
                exit;
            }
            $company_logo = af_valid_url_or_path($_POST['company_logo'] ?? ($db_settings['company_logo'] ?? ''));
            $favicon_url = af_valid_url_or_path($_POST['favicon_url'] ?? ($db_settings['favicon_url'] ?? ''));
            $uploaded_logo = af_store_uploaded_image('company_logo_upload', 'company_logo');
            if ($uploaded_logo !== '') {
                $company_logo = $uploaded_logo;
            }
            $uploaded_favicon = af_store_uploaded_image('favicon_upload', 'favicon');
            if ($uploaded_favicon !== '') {
                $favicon_url = $uploaded_favicon;
            }
            $staff_password_hash = $db_settings['staff_password_hash'] ?? '';
            if (!empty($_POST['staff_password'])) {
                if (strlen((string) $_POST['staff_password']) < 4) {
                    $login_error = "Staff password must be at least 4 characters.";
                } else {
                    $staff_password_hash = password_hash((string) $_POST['staff_password'], PASSWORD_DEFAULT);
                }
            }
            $admin_password_hash = $db_settings['admin_password_hash'] ?? '';
            if (!empty($_POST['admin_password'])) {
                if (strlen((string) $_POST['admin_password']) < 4) {
                    $login_error = "Admin password must be at least 4 characters.";
                } else {
                    $admin_password_hash = password_hash((string) $_POST['admin_password'], PASSWORD_DEFAULT);
                }
            }

            if (isset($login_error)) {
                $_SESSION['login_error'] = $login_error;
                $query_string = !empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '';
                header("Location: staff.php" . $query_string);
                exit;
            }
            $settings_to_update = [
                'company_name' => trim($_POST['company_name'] ?? 'AeroFind Cabin Recovery'),
                'company_short_name' => trim($_POST['company_short_name'] ?? 'AeroFind'),
                'company_tagline' => trim($_POST['company_tagline'] ?? 'Cabin Operations Division'),
                'company_initials' => strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $_POST['company_initials'] ?? 'AF'), 0, 4)) ?: 'AF',
                'company_logo' => $company_logo,
                'favicon_url' => $favicon_url,
                'admin_username' => trim($_POST['admin_username'] ?? 'admin'),
                'admin_password_hash' => $admin_password_hash,
                'staff_username' => trim($_POST['staff_username'] ?? 'admin'),
                'staff_login_email' => filter_var($_POST['staff_login_email'] ?? '', FILTER_VALIDATE_EMAIL) ? $_POST['staff_login_email'] : '',
                'developer_contact_email' => filter_var($_POST['developer_contact_email'] ?? '', FILTER_VALIDATE_EMAIL) ? $_POST['developer_contact_email'] : '',
                'staff_password_hash' => $staff_password_hash,
                'passenger_view_days' => $_POST['passenger_view_days'] ?? 30,
                'items_per_page' => max(1, min(500, (int) ($_POST['items_per_page'] ?? 20))),
                'passenger_card_layout' => in_array(($_POST['passenger_card_layout'] ?? 'dual'), ['single', 'dual'], true) ? $_POST['passenger_card_layout'] : 'dual',
                'pickup_info' => $_POST['pickup_info'] ?? '',
                'staff_notification_email' => $_POST['staff_notification_email'] ?? '',
                'email_delivery_method' => in_array(($_POST['email_delivery_method'] ?? 'php_mail'), ['php_mail', 'smtp'], true) ? $_POST['email_delivery_method'] : 'php_mail',
                'smtp_host' => $_POST['smtp_host'] ?? '',
                'smtp_port' => $_POST['smtp_port'] ?? '25',
                'smtp_encryption' => $_POST['smtp_encryption'] ?? 'none',
                'smtp_user' => $_POST['smtp_user'] ?? '',
                'smtp_password' => ($_POST['smtp_password'] ?? '') !== '' ? $_POST['smtp_password'] : ($db_settings['smtp_password'] ?? ''),
                'smtp_from_email' => $_POST['smtp_from_email'] ?? 'noreply@aerofind.online'
            ];

            $stmt = $pdo->prepare("INSERT OR REPLACE INTO settings (key, value) VALUES (?, ?)");
            foreach ($settings_to_update as $k => $v) {
                $stmt->execute([$k, $v]);
            }
            if (!isset($login_error)) {
                $success_msg = "Settings updated successfully.";
            }

            // Re-fetch Settings immediately using defaults
            $db_settings = af_settings($pdo);
            $passenger_view_days = $db_settings['passenger_view_days'] ?? 30;
            $items_per_page = (int) ($db_settings['items_per_page'] ?? 20);
            if ($items_per_page < 1) {
                $items_per_page = 20;
            }
            $developer_contact_email = filter_var($db_settings['developer_contact_email'] ?? '', FILTER_VALIDATE_EMAIL) ? $db_settings['developer_contact_email'] : '';
            $passenger_card_layout_value = $db_settings['passenger_card_layout'] ?? 'dual';
            $passenger_card_layout = in_array($passenger_card_layout_value, ['single', 'dual'], true) ? $passenger_card_layout_value : 'dual';
            $pickup_info = $db_settings['pickup_info'] ?? 'Main Terminal, Cabin Recovery Lost & Found Desk. Please bring a valid ID and the reference code.';
            $staff_notification_email = $db_settings['staff_notification_email'] ?? 'staff@aerofind.online';
            $smtp_host = $db_settings['smtp_host'] ?? '';
            $email_delivery_method_value = $db_settings['email_delivery_method'] ?? 'php_mail';
            $email_delivery_method = in_array($email_delivery_method_value, ['php_mail', 'smtp'], true) ? $email_delivery_method_value : 'php_mail';
            $smtp_port = $db_settings['smtp_port'] ?? '587';
            $smtp_encryption = $db_settings['smtp_encryption'] ?? 'tls';
            $smtp_user = $db_settings['smtp_user'] ?? 'noreply@aerofind.online';
            $smtp_password = $db_settings['smtp_password'] ?? '';
            $smtp_from_email = $db_settings['smtp_from_email'] ?? 'noreply@aerofind.online';
        }

        if (isset($success_msg)) {
            $_SESSION['success_msg'] = $success_msg;
        }
        if (isset($login_error)) {
            $_SESSION['login_error'] = $login_error;
        }
        $query_string = !empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '';
        header("Location: staff.php" . $query_string);
        exit;
    }
}

function syncWithExcel($db_file)
{
    global $success_msg;
    // Backup existing DB
    copy($db_file, __DIR__ . '/cabin_db_backup.sqlite');
    // Run python import script
    $output = shell_exec("python3 " . escapeshellarg(__DIR__ . '/import_excel.py') . " 2>&1");
    // Update last sync time
    file_put_contents(__DIR__ . '/.last_sync_time', time());
    $success_msg = "Sync Completed Automatically. Backup created.";
}

// Auto-Sync Logic
$xlsx_path = __DIR__ . '/../LOFO Items Tracking ORIGINAL(Automatisch wiederhergestellt).xlsx';
if (file_exists(__DIR__ . '/.autosync') && file_exists($xlsx_path)) {
    $last_sync = file_exists(__DIR__ . '/.last_sync_time') ? (int) file_get_contents(__DIR__ . '/.last_sync_time') : 0;
    if (filemtime($xlsx_path) > $last_sync) {
        syncWithExcel($db_file);
    }
}

if (!isset($_SESSION['cabin_staff_loggedin']) || $_SESSION['cabin_staff_loggedin'] !== true) {
    ?>
    <!DOCTYPE html>
    <html lang="en">

    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title><?= af_h($company_short_name) ?> Staff | Login</title>
        <?php if ($favicon_url !== ''): ?>
            <link rel="icon" href="<?= af_h($favicon_url) ?>"><?php endif; ?>
        <script src="https://cdn.tailwindcss.com"></script>
        <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
        <style>
            :root {
                --bg: #0f172a;
                --input: rgba(255, 255, 255, 0.05);
                --border: rgba(255, 255, 255, 0.1);
            }

            body {
                font-family: 'Inter', sans-serif;
                background: var(--bg);
            }
        </style>
    </head>

    <body class="bg-[#0f172a] text-white flex items-center justify-center min-h-screen p-6">
        <div class="w-full max-w-md bg-slate-800/50 backdrop-blur-xl border border-white/10 p-8 rounded-3xl shadow-2xl">
            <div class="text-center mb-8">
                <div
                    class="w-16 h-16 bg-rose-500 rounded-2xl flex items-center justify-center mx-auto mb-4 shadow-lg shadow-rose-500/20 text-2xl font-bold">
                    <?= af_brand_logo_html($db_settings, 'w-full h-full rounded-2xl') ?>
                </div>
                <h1 class="text-2xl font-bold"><?= af_h($company_short_name) ?> Operations</h1>
                <p class="text-slate-400 text-sm mt-1">Authorized Staff Only</p>
            </div>
            <?php if (isset($login_error))
                echo "<div class='bg-rose-500/10 border border-rose-500/20 text-rose-500 p-3 rounded-xl text-sm mb-6 text-center'>$login_error</div>"; ?>
            <form method="POST" class="space-y-4">
                <div>
                    <label
                        class="block text-[10px] font-black uppercase tracking-widest text-slate-500 mb-2">Username</label>
                    <input type="text" name="login_username" required
                        class="w-full bg-[var(--input)] border-[var(--border)] rounded-xl px-4 py-3 focus:border-rose-500 outline-none transition-all"
                        placeholder="admin">
                </div>
                <div>
                    <label
                        class="block text-[10px] font-black uppercase tracking-widest text-slate-500 mb-2">Password</label>
                    <input type="password" name="login_password" required
                        class="w-full bg-[var(--input)] border-[var(--border)] rounded-xl px-4 py-3 focus:border-rose-500 outline-none transition-all"
                        placeholder="••••••••">
                </div>
                <button type="submit"
                    class="w-full bg-rose-500 hover:bg-rose-600 py-3.5 rounded-xl font-black text-xs uppercase tracking-widest transition-all shadow-lg shadow-rose-500/20 mt-4">Authenticate</button>
            </form>
            <?php /* if ($developer_contact_email !== ''): ?>
<p class="text-center text-[10px] uppercase tracking-widest text-slate-500 mt-6">
    Developer Contact:
    <a href="mailto:<?= af_h($developer_contact_email) ?>" class="text-rose-400 hover:text-rose-300"><?= af_h($developer_contact_email) ?></a>
</p>
<?php endif; */ ?>
        </div>
    </body>

    </html>
    <?php
    exit;
}

// List & Filters
$search = $_GET['search'] ?? '';
$flight_f = $_GET['flight'] ?? '';
$date_f = $_GET['date'] ?? '';
$status_f = $_GET['status_filter'] ?? '';
$start_date = $_GET['start_date'] ?? '';
$end_date = $_GET['end_date'] ?? '';
$filter_year = $_GET['filter_year'] ?? '';
$filter_month = $_GET['filter_month'] ?? '';
$airline_filter = $_GET['airline_filter'] ?? '';
$airline_code = '';
$airline_name = '';
if ($airline_filter) {
    $stmt_al = $pdo->prepare("SELECT name, code FROM airlines WHERE id = ?");
    $stmt_al->execute([$airline_filter]);
    $al_row = $stmt_al->fetch(PDO::FETCH_ASSOC);
    if ($al_row) {
        $airline_code = trim($al_row['code']);
        $airline_name = trim($al_row['name']);
    }
}

// Stats
$total = $pdo->query("SELECT COUNT(*) FROM items WHERE tag_no NOT LIKE 'PP-%'")->fetchColumn();
$found = $pdo->query("SELECT COUNT(*) FROM items WHERE status IN ('Found', 'Claimed') AND tag_no NOT LIKE 'PP-%'")->fetchColumn();
$lost = $pdo->query("SELECT COUNT(*) FROM items WHERE status = 'Lost' AND tag_no NOT LIKE 'PP-%'")->fetchColumn();
$delivered = $pdo->query("SELECT COUNT(*) FROM items WHERE status = 'Delivered' AND tag_no NOT LIKE 'PP-%'")->fetchColumn();
$id_passports = $pdo->query("SELECT COUNT(*) FROM items WHERE tag_no LIKE 'PP-%'")->fetchColumn();
$pending_report_count = $pdo->query("SELECT COUNT(*) FROM pending_reports WHERE status = 'Pending'")->fetchColumn();
$pending_reports = $pdo->query("SELECT * FROM pending_reports WHERE status = 'Pending' ORDER BY created_at DESC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC);

// Pagination settings
if (!isset($items_per_page)) {
    $items_per_page = 20;
}
$page = isset($_GET['p']) ? max(1, (int) $_GET['p']) : 1;
$offset = ($page - 1) * $items_per_page;

// Count total matching items for pagination
$count_q = "SELECT COUNT(*) FROM items WHERE 1=1";
$count_params = [];
if ($search) {
    $count_q .= " AND (tag_no LIKE :s OR item_description LIKE :s OR pax_name LIKE :s)";
    $count_params[':s'] = "%$search%";
}
if ($flight_f) {
    $count_q .= " AND other_info LIKE :fl";
    $count_params[':fl'] = "%$flight_f%";
}
if ($date_f) {
    $count_q .= " AND other_info LIKE :dt";
    $count_params[':dt'] = "%$date_f%";
}
if ($status_f === 'ID_Passport') {
    $count_q .= " AND tag_no LIKE 'PP-%'";
} else {
    $count_q .= " AND tag_no NOT LIKE 'PP-%'";
    if ($status_f) {
        if ($status_f === 'Found') {
            $count_q .= " AND status IN ('Found', 'Claimed', 'Delivered')";
        } else {
            $count_q .= " AND status = :st";
            $count_params[':st'] = $status_f;
        }
    }
}
if ($start_date) {
    $count_q .= " AND created_at >= :start_date";
    $count_params[':start_date'] = $start_date . " 00:00:00";
}
if ($end_date) {
    $count_q .= " AND created_at <= :end_date";
    $count_params[':end_date'] = $end_date . " 23:59:59";
}
if ($filter_year) {
    $count_q .= " AND strftime('%Y', created_at) = :filter_year";
    $count_params[':filter_year'] = $filter_year;
}
if ($filter_month) {
    $count_q .= " AND strftime('%m', created_at) = :filter_month";
    $count_params[':filter_month'] = $filter_month;
}
if ($airline_code !== '') {
    $count_q .= " AND (
        tag_no LIKE :al_code_prefix 
        OR other_info LIKE :al_airline_code 
        OR other_info LIKE :al_code_space 
        OR other_info LIKE :al_code_d0 
        OR other_info LIKE :al_code_d1 
        OR other_info LIKE :al_code_d2 
        OR other_info LIKE :al_code_d3 
        OR other_info LIKE :al_code_d4 
        OR other_info LIKE :al_code_d5 
        OR other_info LIKE :al_code_d6 
        OR other_info LIKE :al_code_d7 
        OR other_info LIKE :al_code_d8 
        OR other_info LIKE :al_code_d9
        " . ($airline_name !== '' ? "OR other_info LIKE :al_name" : "") . "
    )";
    $count_params[':al_code_prefix'] = $airline_code . '%';
    $count_params[':al_airline_code'] = '%Airline: ' . $airline_code . '%';
    $count_params[':al_code_space'] = '%' . $airline_code . ' %';
    for ($i = 0; $i <= 9; $i++) {
        $count_params[':al_code_d' . $i] = '%' . $airline_code . $i . '%';
    }
    if ($airline_name !== '') {
        $count_params[':al_name'] = '%' . $airline_name . '%';
    }
}

$stmt_count = $pdo->prepare($count_q);
$stmt_count->execute($count_params);
$total_matching = $stmt_count->fetchColumn();
$total_pages = max(1, ceil($total_matching / $items_per_page));

// Fetch items for current page
$q = "SELECT * FROM items WHERE 1=1";
$params = [];
if ($search) {
    $q .= " AND (tag_no LIKE :s OR item_description LIKE :s OR pax_name LIKE :s)";
    $params[':s'] = "%$search%";
}
if ($flight_f) {
    $q .= " AND other_info LIKE :fl";
    $params[':fl'] = "%$flight_f%";
}
if ($date_f) {
    $q .= " AND other_info LIKE :dt";
    $params[':dt'] = "%$date_f%";
}
if ($status_f === 'ID_Passport') {
    $q .= " AND tag_no LIKE 'PP-%'";
} else {
    $q .= " AND tag_no NOT LIKE 'PP-%'";
    if ($status_f) {
        if ($status_f === 'Found') {
            $q .= " AND status IN ('Found', 'Claimed', 'Delivered')";
        } else {
            $q .= " AND status = :st";
            $params[':st'] = $status_f;
        }
    }
}
if ($start_date) {
    $q .= " AND created_at >= :start_date";
    $params[':start_date'] = $start_date . " 00:00:00";
}
if ($end_date) {
    $q .= " AND created_at <= :end_date";
    $params[':end_date'] = $end_date . " 23:59:59";
}
if ($filter_year) {
    $q .= " AND strftime('%Y', created_at) = :filter_year";
    $params[':filter_year'] = $filter_year;
}
if ($filter_month) {
    $q .= " AND strftime('%m', created_at) = :filter_month";
    $params[':filter_month'] = $filter_month;
}
if ($airline_code !== '') {
    $q .= " AND (
        tag_no LIKE :al_code_prefix 
        OR other_info LIKE :al_airline_code 
        OR other_info LIKE :al_code_space 
        OR other_info LIKE :al_code_d0 
        OR other_info LIKE :al_code_d1 
        OR other_info LIKE :al_code_d2 
        OR other_info LIKE :al_code_d3 
        OR other_info LIKE :al_code_d4 
        OR other_info LIKE :al_code_d5 
        OR other_info LIKE :al_code_d6 
        OR other_info LIKE :al_code_d7 
        OR other_info LIKE :al_code_d8 
        OR other_info LIKE :al_code_d9
        " . ($airline_name !== '' ? "OR other_info LIKE :al_name" : "") . "
    )";
    $params[':al_code_prefix'] = $airline_code . '%';
    $params[':al_airline_code'] = '%Airline: ' . $airline_code . '%';
    $params[':al_code_space'] = '%' . $airline_code . ' %';
    for ($i = 0; $i <= 9; $i++) {
        $params[':al_code_d' . $i] = '%' . $airline_code . $i . '%';
    }
    if ($airline_name !== '') {
        $params[':al_name'] = '%' . $airline_name . '%';
    }
}
$q .= " ORDER BY CAST(substr(tag_no, 4) AS INTEGER) DESC, created_at DESC LIMIT :limit OFFSET :offset";
$stmt = $pdo->prepare($q);
foreach ($params as $key => $val) {
    $stmt->bindValue($key, $val);
}
$stmt->bindValue(':limit', $items_per_page, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= af_h($company_short_name) ?> Staff | Dashboard</title>
    <?php if ($favicon_url !== ''): ?>
        <link rel="icon" href="<?= af_h($favicon_url) ?>"><?php endif; ?>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: 'class'
        }
    </script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg: #f8fafc;
            --card: #ffffff;
            --text: #0f172a;
            --secondary: #64748b;
            --border: #e2e8f0;
            --input: #f1f5f9;
            --header: #ffffff;
            --accent: #f43f5e;
        }

        .dark {
            --bg: #0f172a;
            --card: rgba(30, 41, 59, 0.7);
            --text: #f8fafc;
            --secondary: #94a3b8;
            --border: rgba(255, 255, 255, 0.1);
            --input: rgba(15, 23, 42, 0.5);
            --header: #0f172a;
            --accent: #f43f5e;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: var(--bg);
            color: var(--text);
            transition: background 0.3s, color 0.3s;
        }

        .text-secondary {
            color: var(--secondary);
        }

        .glass {
            background: var(--card);
            backdrop-filter: blur(20px);
            border: 1px solid var(--border);
            box-shadow: 0 4px 6px -1px rgb(0 0 0 / 0.1);
        }

        .btn-rose {
            background: #f43f5e;
            box-shadow: 0 10px 15px -3px rgba(244, 63, 94, 0.3);
            transition: all 0.2s;
        }

        .btn-rose:hover {
            background: #e11d48;
            transform: translateY(-1px);
        }

        .input-dark {
            background: var(--input);
            border: 1px solid var(--border);
            color: var(--text);
            outline: none;
            transition: border-color 0.15s ease, background-color 0.15s ease;
        }

        .input-dark:focus {
            border-color: #f43f5e;
            background: var(--bg);
        }

        /* Global page transition & autofill box flash prevention */
        input,
        select,
        textarea {
            transition: border-color 0.15s ease-in-out, opacity 0.15s ease-in-out !important;
            outline: none !important;
        }

        /* Premium Custom Select Dropdown Arrow for Visibility & Aesthetics */
        select {
            appearance: none !important;
            -webkit-appearance: none !important;
            -moz-appearance: none !important;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 24 24' stroke='%230f172a'%3E%3Cpath stroke-linecap='round' stroke-linejoin='round' stroke-width='2.5' d='M19 9l-7 7-7-7'/%3E%3C/svg%3E") !important;
            background-repeat: no-repeat !important;
            background-position: right 0.6rem center !important;
            background-size: 0.7rem !important;
            padding-right: 1.75rem !important;
            color: var(--text);
        }

        .dark select {
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 24 24' stroke='%23f8fafc'%3E%3Cpath stroke-linecap='round' stroke-linejoin='round' stroke-width='2.5' d='M19 9l-7 7-7-7'/%3E%3C/svg%3E") !important;
        }

        input:-webkit-autofill,
        input:-webkit-autofill:hover,
        input:-webkit-autofill:focus,
        input:-webkit-autofill:active {
            -webkit-box-shadow: 0 0 0 1000px var(--input) inset !important;
            -webkit-text-fill-color: var(--text) !important;
            transition: background-color 5000s ease-in-out 0s;
        }

        .custom-scroll::-webkit-scrollbar {
            width: 4px;
        }

        .custom-scroll::-webkit-scrollbar-track {
            background: transparent;
        }

        .custom-scroll::-webkit-scrollbar-thumb {
            background: rgba(100, 116, 139, 0.2);
            border-radius: 10px;
        }

        .mobile-flight-badge {
            display: none !important;
        }

        .pending-mobile-meta {
            display: none;
        }

        .select-compact {
            padding: 2px 1.25rem 2px 6px !important;
            background-position: right 0.35rem center !important;
            background-size: 0.5rem !important;
        }

        .theme-toggle {
            cursor: pointer;
            padding: 10px;
            border-radius: 12px;
            background: var(--input);
            border: 1px solid var(--border);
            transition: all 0.2s;
        }

        .theme-toggle:hover {
            background: var(--border);
        }

        .bg-card {
            background: var(--card);
        }

        .border-base {
            border-color: var(--border);
        }

        /* Custom premium Toast container and notification styles */
        .toast-container {
            position: fixed;
            bottom: 24px;
            right: 24px;
            z-index: 9999;
            display: flex;
            flex-direction: column;
            gap: 12px;
            max-width: 400px;
            width: calc(100% - 48px);
            pointer-events: none;
        }

        .toast-card {
            pointer-events: auto;
            display: flex;
            align-items: flex-start;
            gap: 14px;
            padding: 16px 20px;
            border-radius: 16px;
            background: rgba(15, 23, 42, 0.85);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.08);
            transform: translateY(20px) scale(0.95);
            opacity: 0;
            transition: all 0.4s cubic-bezier(0.16, 1, 0.3, 1);
            position: relative;
            overflow: hidden;
        }

        .dark .toast-card {
            background: rgba(15, 23, 42, 0.85);
            border-color: rgba(255, 255, 255, 0.08);
        }

        /* Light mode support */
        html:not(.dark) .toast-card {
            background: rgba(255, 255, 255, 0.9);
            border-color: rgba(0, 0, 0, 0.06);
            box-shadow: 0 20px 40px -15px rgba(0, 0, 0, 0.1);
        }

        html:not(.dark) .toast-card span.text-slate-100 {
            color: #0f172a !important;
        }

        .toast-card.show {
            transform: translateY(0) scale(1);
            opacity: 1;
        }

        .toast-card.hide {
            transform: translateY(20px) scale(0.95);
            opacity: 0;
        }

        .toast-progress {
            position: absolute;
            bottom: 0;
            left: 0;
            height: 3px;
            width: 100%;
            transform-origin: left;
            animation: toast-progress-shrink 4s linear forwards;
        }

        @keyframes toast-progress-shrink {
            from {
                transform: scaleX(1);
            }

            to {
                transform: scaleX(0);
            }
        }

        details.filter-panel>summary {
            list-style: none;
        }

        details.filter-panel>summary::-webkit-details-marker {
            display: none;
        }

        details.filter-panel .filter-chevron {
            transition: transform 0.2s ease;
        }

        details.filter-panel[open] .filter-chevron {
            transform: rotate(180deg);
        }

        .staff-modal-shell {
            padding: 1rem;
            align-items: center;
        }

        .staff-modal-panel {
            border-radius: 1.5rem;
            max-height: min(88vh, 760px);
            overflow-y: auto;
        }

        .staff-modal-close {
            width: 2.25rem;
            height: 2.25rem;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 0.75rem;
            background: var(--input);
            border: 1px solid var(--border);
            line-height: 1;
        }

        .staff-modal-field {
            border-radius: 0.85rem !important;
            padding: 0.75rem 0.9rem !important;
        }

        .airline-list-row {
            padding: 0.75rem;
            border-radius: 1rem;
        }

        .modal-submit-bar {
            position: sticky;
            bottom: -1px;
            padding-top: 0.75rem;
            background: linear-gradient(to top, var(--card) 76%, transparent);
        }

        @media (max-width: 760px) {
            .mobile-flight-badge {
                display: inline-block !important;
            }

            .pending-mobile-meta {
                display: block;
            }

            .staff-header {
                padding: 0.75rem !important;
            }

            .staff-header-inner {
                flex-direction: row !important;
                align-items: center !important;
                justify-content: space-between !important;
                gap: 0.75rem !important;
            }

            .staff-brand {
                min-width: 0;
                gap: 0.65rem !important;
            }

            .staff-logo {
                width: 2.25rem !important;
                height: 2.25rem !important;
                border-radius: 0.75rem !important;
                font-size: 1rem !important;
            }

            .staff-title {
                font-size: 1rem !important;
                max-width: 10rem;
                overflow: hidden;
                text-overflow: ellipsis;
                white-space: nowrap;
            }

            .staff-subtitle {
                display: none;
            }

            .staff-header-actions {
                gap: 0.4rem !important;
                flex-shrink: 0;
            }

            .staff-header-actions button {
                width: 2.25rem !important;
                height: 2.25rem !important;
                border-radius: 0.75rem !important;
            }

            .staff-header-actions a {
                padding: 0.65rem 0.8rem !important;
                font-size: 0.48rem !important;
                border-radius: 0.75rem !important;
            }

            .staff-header-actions .mobile-divider {
                display: none;
            }

            .staff-nav {
                display: flex !important;
                flex-direction: row !important;
                flex-wrap: nowrap !important;
                align-items: center !important;
                justify-content: space-between !important;
                gap: 0.5rem !important;
                overflow: visible !important;
                padding: 0.5rem 0.75rem !important;
            }

            .staff-mobile-tab-switcher {
                display: block !important;
                flex: 0 0 auto !important;
            }

            .staff-nav-tabs {
                display: none !important;
            }

            .staff-nav-tools {
                display: flex !important;
                flex-direction: row !important;
                flex-wrap: nowrap !important;
                align-items: center !important;
                gap: 0.4rem !important;
                flex: 0 0 auto !important;
                border-left: none !important;
                padding-left: 0 !important;
                margin-left: 0 !important;
                border-bottom: none !important;
                padding-bottom: 0 !important;
            }

            .staff-nav-tools #sync-status {
                margin-right: 0 !important;
            }

            .staff-nav-tabs button,
            .staff-nav-tools button,
            .staff-nav-tools a {
                flex: 0 0 auto !important;
                padding: 0.5rem 0.7rem !important;
                font-size: 0.58rem !important;
                border-radius: 0.65rem !important;
                letter-spacing: 0.12em !important;
                white-space: nowrap;
            }

            .staff-nav-tools button[onclick="toggleTheme()"],
            .staff-nav-tools a[href="index.php"] {
                width: 2.1rem !important;
                height: 2.1rem !important;
                padding: 0 !important;
                display: flex !important;
                align-items: center !important;
                justify-content: center !important;
            }

            .staff-nav-tools button[onclick="toggleTheme()"] svg,
            .staff-nav-tools a[href="index.php"] svg {
                width: 0.95rem !important;
                height: 0.95rem !important;
            }

            .staff-nav-tools .mobile-divider {
                display: none !important;
            }

            .staff-fab span.absolute {
                display: none;
            }

            .staff-modal-shell {
                align-items: flex-end;
                padding: 0.625rem;
            }

            .staff-modal-panel {
                width: 100%;
                max-height: calc(100dvh - 1.25rem);
                border-radius: 1.15rem;
                padding: 0.9rem !important;
            }

            .staff-modal-title {
                font-size: 1rem !important;
                line-height: 1.1;
            }

            .staff-modal-icon {
                width: 2.25rem !important;
                height: 2.25rem !important;
                border-radius: 0.85rem !important;
                font-size: 1rem !important;
            }

            .staff-modal-header {
                gap: 0.65rem !important;
                margin-bottom: 1rem !important;
                padding-right: 2.5rem;
            }

            .staff-modal-close {
                top: 0.85rem !important;
                right: 0.85rem !important;
            }

            .staff-modal-form {
                gap: 0.5rem !important;
            }

            .staff-modal-field {
                min-height: 2.35rem;
                font-size: 0.75rem !important;
            }

            .settings-grid {
                gap: 0.75rem !important;
            }

            .settings-section {
                gap: 0.7rem !important;
            }

            .settings-section h3,
            .settings-section label {
                font-size: 0.5rem !important;
                letter-spacing: 0.12em !important;
            }

            .settings-help {
                display: none;
            }

            .airline-add-form {
                display: grid !important;
                grid-template-columns: minmax(0, 1fr) 4.75rem;
                gap: 0.5rem !important;
                margin-bottom: 1rem !important;
            }

            .airline-add-form button {
                grid-column: 1 / -1;
                min-height: 2.45rem;
            }

            .modal-submit-bar {
                margin-left: -0.9rem;
                margin-right: -0.9rem;
                padding: 0.7rem 0.9rem 0;
            }

            .airline-list {
                max-height: 48vh !important;
            }

            .airline-list-row {
                padding: 0.6rem;
                border-radius: 0.85rem;
            }

            .airline-list-row img {
                width: 2rem !important;
                height: 2rem !important;
                border-radius: 0.65rem !important;
            }

            .staff-inventory-table {
                min-width: 0 !important;
                table-layout: auto !important;
                border-collapse: separate !important;
                border-spacing: 0 !important;
            }

            .staff-inventory-table thead {
                display: none;
            }

            .staff-inventory-table,
            .staff-inventory-table tbody,
            .staff-inventory-table tr,
            .staff-inventory-table td {
                display: block;
                width: 100%;
            }

            .staff-inventory-table tbody {
                padding: 0.65rem;
            }

            .staff-inventory-table tbody tr.week-row {
                margin: 0.35rem 0 0.5rem;
                border: 0 !important;
                background: transparent !important;
            }

            .staff-inventory-table tbody tr.week-row td {
                border-radius: 0.85rem;
                background: rgba(244, 63, 94, 0.1);
                border: 1px solid var(--border);
                padding: 0.65rem 0.8rem !important;
            }

            .staff-inventory-table tbody tr:not(.week-row) {
                position: relative;
                margin-bottom: 0.4rem;
                padding: 0.5rem 0.65rem;
                padding-right: 2.5rem !important;
                border: 1px solid var(--border);
                border-left-width: 4px;
                border-radius: 1rem;
                background-color: var(--card);
                overflow: hidden;
                transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
            }

            /* Dynamic Row Backgrounds & Borders on Mobile to match Desktop view */
            .staff-inventory-table tbody tr.status-lost:not(.week-row) {
                background-color: rgba(100, 116, 139, 0.12) !important;
                border-left-color: #64748b !important;
            }

            .dark .staff-inventory-table tbody tr.status-lost:not(.week-row) {
                background-color: rgba(100, 116, 139, 0.15) !important;
            }

            .staff-inventory-table tbody tr.status-lost:not(.week-row):hover {
                background-color: rgba(100, 116, 139, 0.18) !important;
            }

            .dark .staff-inventory-table tbody tr.status-lost:not(.week-row):hover {
                background-color: rgba(100, 116, 139, 0.2) !important;
            }

            .staff-inventory-table tbody tr.status-claimed:not(.week-row) {
                background-color: rgba(79, 70, 229, 0.12) !important;
                border-left-color: #6366f1 !important;
            }

            .dark .staff-inventory-table tbody tr.status-claimed:not(.week-row) {
                background-color: rgba(99, 102, 241, 0.15) !important;
            }

            .staff-inventory-table tbody tr.status-claimed:not(.week-row):hover {
                background-color: rgba(79, 70, 229, 0.18) !important;
            }

            .dark .staff-inventory-table tbody tr.status-claimed:not(.week-row):hover {
                background-color: rgba(99, 102, 241, 0.2) !important;
            }

            .staff-inventory-table tbody tr.status-delivered:not(.week-row) {
                background-color: rgba(5, 150, 105, 0.12) !important;
                border-left-color: #10b981 !important;
            }

            .dark .staff-inventory-table tbody tr.status-delivered:not(.week-row) {
                background-color: rgba(16, 185, 129, 0.15) !important;
            }

            .staff-inventory-table tbody tr.status-delivered:not(.week-row):hover {
                background-color: rgba(5, 150, 105, 0.18) !important;
            }

            .dark .staff-inventory-table tbody tr.status-delivered:not(.week-row):hover {
                background-color: rgba(16, 185, 129, 0.2) !important;
            }

            .staff-inventory-table tbody tr.status-disposed:not(.week-row) {
                background-color: rgba(217, 119, 6, 0.12) !important;
                border-left-color: #f59e0b !important;
            }

            .dark .staff-inventory-table tbody tr.status-disposed:not(.week-row) {
                background-color: rgba(245, 158, 11, 0.15) !important;
            }

            .staff-inventory-table tbody tr.status-disposed:not(.week-row):hover {
                background-color: rgba(217, 119, 6, 0.18) !important;
            }

            .dark .staff-inventory-table tbody tr.status-disposed:not(.week-row):hover {
                background-color: rgba(245, 158, 11, 0.2) !important;
            }

            .staff-inventory-table tbody tr:not(.week-row)::after {
                content: '↓' !important;
                font-family: system-ui, -apple-system, sans-serif !important;
                position: absolute !important;
                top: 0.55rem !important;
                right: 0.75rem !important;
                font-size: 0.75rem !important;
                font-weight: 900 !important;
                color: #0f172a !important;
                /* High contrast for light mode */
                width: 1.35rem !important;
                height: 1.35rem !important;
                background: #ffffff !important;
                /* Pure white for light mode */
                border: 1px solid #cbd5e1 !important;
                /* Slate 300 border for high contrast */
                border-radius: 50% !important;
                display: flex !important;
                align-items: center !important;
                justify-content: center !important;
                transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1) !important;
                box-shadow: 0 3px 8px rgba(0, 0, 0, 0.08) !important;
                z-index: 10 !important;
            }

            .dark .staff-inventory-table tbody tr:not(.week-row)::after {
                background: #1e293b !important;
                border: 1px solid rgba(255, 255, 255, 0.15) !important;
                color: #f8fafc !important;
                box-shadow: 0 3px 8px rgba(0, 0, 0, 0.3) !important;
            }

            .staff-inventory-table tbody tr:not(.week-row).is-expanded::after {
                content: '↑' !important;
                background: #f43f5e !important;
                /* Rose 500 */
                color: #ffffff !important;
                border-color: #f43f5e !important;
                box-shadow: 0 4px 12px rgba(244, 63, 94, 0.4) !important;
            }

            .staff-inventory-table tbody tr:not(.week-row):not(.is-expanded) td:not([data-label="Item"]) {
                display: none !important;
            }

            .staff-inventory-table tbody tr:not(.week-row):not(.is-expanded) td[data-label="Item"] {
                padding: 0 !important;
            }

            /* Collapsed view hides the Change Photo button under the thumbnail */
            .staff-inventory-table tbody tr:not(.week-row):not(.is-expanded) td[data-label="Item"] .change-photo-label {
                display: none !important;
            }

            .staff-inventory-table tbody tr:not(.week-row).is-expanded {
                padding: 0.55rem 0.7rem;
                margin-bottom: 0.5rem;
            }

            .staff-inventory-table tbody tr:not(.week-row) td {
                padding: 0.3rem 0 !important;
                text-align: left !important;
                border: 0;
            }

            .staff-inventory-table tbody tr:not(.week-row) td+td {
                border-top: 1px solid var(--border);
            }

            .staff-inventory-table td[data-label]::before {
                content: attr(data-label);
                display: block;
                margin-bottom: 0.2rem;
                color: var(--secondary);
                font-size: 0.42rem;
                font-weight: 900;
                letter-spacing: 0.12em;
                line-height: 1;
                text-transform: uppercase;
            }

            .staff-inventory-table td[data-label="Item"]::before {
                display: none;
            }

            .staff-inventory-table td[data-label] input {
                width: 100% !important;
                min-height: 1.75rem;
                border: 1px solid var(--border) !important;
                border-radius: 0.45rem !important;
                background-color: var(--bg) !important;
                padding: 0.25rem 0.45rem !important;
                font-size: 0.65rem !important;
            }

            .staff-inventory-table td[data-label] select {
                width: 100% !important;
                min-height: 1.75rem;
                border: 1px solid var(--border) !important;
                border-radius: 0.45rem !important;
                background-color: var(--bg) !important;
                padding: 0.25rem 1.4rem 0.25rem 0.45rem !important;
                font-size: 0.65rem !important;
                appearance: none !important;
                -webkit-appearance: none !important;
                -moz-appearance: none !important;
                background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 24 24' stroke='%230f172a'%3E%3Cpath stroke-linecap='round' stroke-linejoin='round' stroke-width='2.5' d='M19 9l-7 7-7-7'/%3E%3C/svg%3E") !important;
                background-repeat: no-repeat !important;
                background-position: right 0.4rem center !important;
                background-size: 0.6rem !important;
                color: var(--text) !important;
            }

            .dark .staff-inventory-table td[data-label] select {
                background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 24 24' stroke='%23f8fafc'%3E%3Cpath stroke-linecap='round' stroke-linejoin='round' stroke-width='2.5' d='M19 9l-7 7-7-7'/%3E%3C/svg%3E") !important;
            }

            .staff-inventory-table td[data-label="Status"] select {
                text-align: center;
            }

            .staff-inventory-table td[data-label="Actions"]>div {
                display: grid !important;
                grid-template-columns: repeat(3, minmax(0, 1fr));
                gap: 0.35rem !important;
                align-items: stretch !important;
                justify-content: stretch !important;
            }

            .staff-inventory-table td[data-label="Actions"] form {
                display: block !important;
                width: 100%;
            }

            .staff-inventory-table td[data-label="Actions"] button {
                width: 100% !important;
                height: 1.85rem !important;
                min-width: 0;
                border-radius: 0.45rem !important;
                transform: none !important;
            }

            .staff-inventory-table td[data-label="Actions"] svg {
                width: 0.85rem !important;
                height: 0.85rem !important;
            }

            .staff-inventory-table td[data-label="Actions"] .staff-row-actions.is-delivered {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .staff-pending-table {
                min-width: 0 !important;
                table-layout: auto !important;
                border-collapse: separate !important;
                border-spacing: 0 !important;
            }

            .staff-pending-table thead {
                display: none;
            }

            .staff-pending-table,
            .staff-pending-table tbody,
            .staff-pending-table tr,
            .staff-pending-table td {
                display: block;
                width: 100%;
            }

            .staff-pending-table tbody {
                padding: 0.65rem;
            }

            .staff-pending-table tbody tr:not(.pending-empty-row) {
                position: relative;
                margin-bottom: 0.45rem;
                padding: 0.55rem 0.65rem;
                padding-right: 2.5rem !important;
                border: 1px solid var(--border);
                border-left: 4px solid #f59e0b;
                border-radius: 1rem;
                background-color: rgba(245, 158, 11, 0.1);
                overflow: hidden;
                transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
            }

            .dark .staff-pending-table tbody tr:not(.pending-empty-row) {
                background-color: rgba(245, 158, 11, 0.13);
            }

            .staff-pending-table tbody tr:not(.pending-empty-row)::after {
                content: '↓' !important;
                font-family: system-ui, -apple-system, sans-serif !important;
                position: absolute !important;
                top: 0.55rem !important;
                right: 0.75rem !important;
                font-size: 0.75rem !important;
                font-weight: 900 !important;
                color: #0f172a !important;
                width: 1.35rem !important;
                height: 1.35rem !important;
                background: #ffffff !important;
                border: 1px solid #cbd5e1 !important;
                border-radius: 50% !important;
                display: flex !important;
                align-items: center !important;
                justify-content: center !important;
                transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1) !important;
                box-shadow: 0 3px 8px rgba(0, 0, 0, 0.08) !important;
                z-index: 10 !important;
            }

            .dark .staff-pending-table tbody tr:not(.pending-empty-row)::after {
                background: #1e293b !important;
                border: 1px solid rgba(255, 255, 255, 0.15) !important;
                color: #f8fafc !important;
                box-shadow: 0 3px 8px rgba(0, 0, 0, 0.3) !important;
            }

            .staff-pending-table tbody tr:not(.pending-empty-row).is-expanded::after {
                content: '↑' !important;
                background: #f59e0b !important;
                color: #ffffff !important;
                border-color: #f59e0b !important;
                box-shadow: 0 4px 12px rgba(245, 158, 11, 0.35) !important;
            }

            .staff-pending-table tbody tr:not(.pending-empty-row):not(.is-expanded) td:not([data-label="Item"]) {
                display: none !important;
            }

            .staff-pending-table tbody tr:not(.pending-empty-row):not(.is-expanded) td[data-label="Item"] {
                padding: 0 !important;
            }

            .staff-pending-table tbody tr:not(.pending-empty-row).is-expanded {
                padding: 0.55rem 0.7rem;
                margin-bottom: 0.55rem;
            }

            .staff-pending-table tbody tr:not(.pending-empty-row) td {
                padding: 0.35rem 0 !important;
                text-align: left !important;
                border: 0;
            }

            .staff-pending-table tbody tr:not(.pending-empty-row) td+td {
                border-top: 1px solid var(--border);
            }

            .staff-pending-table td[data-label]::before {
                content: attr(data-label);
                display: block;
                margin-bottom: 0.2rem;
                color: var(--secondary);
                font-size: 0.42rem;
                font-weight: 900;
                letter-spacing: 0.12em;
                line-height: 1;
                text-transform: uppercase;
            }

            .staff-pending-table td[data-label="Item"]::before {
                display: none;
            }

            .staff-pending-table td[data-label="Decision"]>div {
                align-items: stretch !important;
                gap: 0.45rem !important;
            }

            .staff-pending-table td[data-label="Decision"] form {
                display: grid !important;
                grid-template-columns: minmax(0, 1fr) auto;
                width: 100%;
                gap: 0.4rem !important;
            }

            .staff-pending-table td[data-label="Decision"] form:first-child {
                display: block !important;
            }

            .staff-pending-table td[data-label="Decision"] input {
                width: 100% !important;
                min-height: 1.9rem;
                border-radius: 0.5rem !important;
                font-size: 0.65rem !important;
            }

            .staff-pending-table td[data-label="Decision"] button {
                min-height: 1.9rem;
                white-space: nowrap;
            }
        }

        /* Smooth transition for collapsing floating action buttons (FAB) */
        .staff-fab {
            transition: transform 0.4s cubic-bezier(0.16, 1, 0.3, 1), opacity 0.4s ease;
        }

        .staff-fab-item {
            transition: transform 0.35s cubic-bezier(0.16, 1, 0.3, 1), opacity 0.35s ease, visibility 0.35s;
        }

        .staff-fab.collapsed .staff-fab-item {
            transform: translateY(20px) scale(0.85);
            opacity: 0;
            visibility: hidden;
            pointer-events: none;
        }
    </style>
    <script>
        const theme = localStorage.getItem('theme') || 'dark';
        if (theme === 'dark') document.documentElement.classList.add('dark');
        function toggleTheme() {
            const isDark = document.documentElement.classList.toggle('dark');
            localStorage.setItem('theme', isDark ? 'dark' : 'light');
        }
        function switchTab(tab) {
            if (tab === 'dashboard') {
                window.location.href = 'staff.php?status_filter=';
            } else if (tab === 'inventory') {
                window.location.href = 'staff.php?status_filter=Found';
            } else if (tab === 'reports') {
                window.location.href = 'staff.php?status_filter=Lost';
            } else if (tab === 'pending') {
                window.location.href = 'staff.php?status_filter=Pending';
            } else if (tab === 'id_passport') {
                window.location.href = 'staff.php?status_filter=ID_Passport';
            }
        }

        function setupDatePlaceholders() {
            document.querySelectorAll('input[data-placeholder]').forEach(input => {
                const syncType = () => {
                    if (input.value) {
                        input.type = 'date';
                    } else if (document.activeElement !== input) {
                        input.type = 'text';
                        input.placeholder = input.dataset.placeholder || '';
                    }
                };

                input.addEventListener('focus', () => {
                    input.type = 'date';
                    if (typeof input.showPicker === 'function') {
                        setTimeout(() => input.showPicker(), 0);
                    }
                });
                input.addEventListener('blur', syncType);
                input.addEventListener('change', syncType);
                syncType();
            });
        }

        // Save scroll position before reload
        window.addEventListener('beforeunload', () => {
            localStorage.setItem('staff_scroll_pos', window.scrollY);
        });

        // Restore scroll position on load
        window.addEventListener('DOMContentLoaded', () => {
            setupDatePlaceholders();
            const pos = localStorage.getItem('staff_scroll_pos');
            if (pos !== null) {
                window.scrollTo(0, parseInt(pos));
                localStorage.removeItem('staff_scroll_pos');
            }
        });
    </script>
    <script>
        window.CABIN_CSRF_TOKEN = <?= json_encode(af_csrf_token()) ?>;
        document.addEventListener('DOMContentLoaded', () => {
            document.querySelectorAll('form[method="POST"], form[method="post"]').forEach((form) => {
                if (!form.querySelector('input[name="csrf_token"]')) {
                    const input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = 'csrf_token';
                    input.value = window.CABIN_CSRF_TOKEN;
                    form.appendChild(input);
                }
            });
        });
    </script>
</head>

<body class="min-h-screen transition-colors">
    <header
        class="staff-header p-4 border-b border-[var(--border)] bg-[var(--card)] transition-colors sticky top-0 z-50">
        <div class="staff-header-inner flex flex-col md:flex-row justify-between items-center gap-4">
            <div class="staff-brand flex items-center gap-4">
                <div
                    class="staff-logo w-10 h-10 bg-rose-500 rounded-xl flex items-center justify-center text-white font-black text-xl shadow-lg shadow-rose-500/20 overflow-hidden">
                    <?= af_brand_logo_html($db_settings, 'w-full h-full rounded-xl') ?>
                </div>
                <div>
                    <h1 class="staff-title font-black text-lg tracking-tight leading-none uppercase">Staff Console</h1>
                    <p class="staff-subtitle text-[8px] text-slate-500 uppercase font-black tracking-widest mt-0.5">
                        <?= af_h($company_tagline) ?>
                    </p>
                </div>
            </div>
            <div class="staff-header-actions flex items-center gap-2">
                <button onclick="location.reload()"
                    class="w-9 h-9 flex items-center justify-center rounded-xl bg-[var(--input)] border border-[var(--border)] text-[var(--secondary)] hover:text-rose-500 transition-all group"
                    title="Refresh Dashboard">
                    <svg class="w-4 h-4 group-hover:rotate-180 transition-transform duration-500" fill="none"
                        stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15">
                        </path>
                    </svg>
                </button>
                <div class="mobile-divider h-6 w-px bg-[var(--border)] mx-1"></div>
                <a href="?logout=1"
                    class="px-4 py-2 bg-[var(--input)] hover:bg-rose-500/10 border border-[var(--border)] hover:border-rose-500/30 text-[var(--secondary)] hover:text-rose-500 rounded-xl text-[9px] font-black uppercase tracking-widest transition-all">
                    Logout
                </a>
            </div>
        </div>
    </header>

    <nav
        class="staff-nav px-4 py-2 border-b border-[var(--border)] bg-[var(--card)] flex flex-wrap items-center justify-between gap-4">
        <!-- Mobile View Switcher Dropdown -->
        <div class="staff-mobile-tab-switcher hidden">
            <select onchange="switchTab(this.value)"
                class="px-3 py-1.5 rounded-lg bg-[var(--input)] border border-[var(--border)] text-[8px] font-black uppercase tracking-widest text-[var(--secondary)] focus:border-rose-500/30 outline-none transition-all cursor-pointer">
                <option value="dashboard" <?= ($status_f == '') ? 'selected' : '' ?>>Dashboard</option>
                <option value="inventory" <?= ($status_f == 'Found') ? 'selected' : '' ?>>Inventory</option>
                <option value="reports" <?= ($status_f == 'Lost') ? 'selected' : '' ?>>Lost Reports</option>
                <option value="pending" <?= ($status_f == 'Pending') ? 'selected' : '' ?>>Pending
                    (<?= (int) $pending_report_count ?>)</option>
                <option value="id_passport" <?= ($status_f == 'ID_Passport') ? 'selected' : '' ?>>ID & Passports</option>
            </select>
        </div>

        <div class="staff-nav-tabs flex flex-wrap items-center gap-2">
            <button onclick="switchTab('dashboard')"
                class="px-3 py-1.5 rounded-lg text-[9px] font-black uppercase tracking-widest transition-all <?= ($status_f == '') ? 'bg-rose-500 text-white shadow-lg shadow-rose-500/20' : 'bg-[var(--input)] text-[var(--secondary)] border border-[var(--border)] hover:text-rose-500' ?>">Dashboard</button>
            <button onclick="switchTab('inventory')"
                class="px-3 py-1.5 rounded-lg text-[9px] font-black uppercase tracking-widest transition-all <?= ($status_f == 'Found') ? 'bg-rose-500 text-white shadow-lg shadow-rose-500/20' : 'bg-[var(--input)] text-[var(--secondary)] border border-[var(--border)] hover:text-rose-500' ?>">Inventory</button>
            <button onclick="switchTab('reports')"
                class="px-3 py-1.5 rounded-lg text-[9px] font-black uppercase tracking-widest transition-all <?= ($status_f == 'Lost') ? 'bg-rose-500 text-white shadow-lg shadow-rose-500/20' : 'bg-[var(--input)] text-[var(--secondary)] border border-[var(--border)] hover:text-rose-500' ?>">Lost
                Reports</button>
            <button onclick="switchTab('pending')"
                class="px-3 py-1.5 rounded-lg text-[9px] font-black uppercase tracking-widest transition-all <?= ($status_f == 'Pending') ? 'bg-rose-500 text-white shadow-lg shadow-rose-500/20' : 'bg-[var(--input)] text-[var(--secondary)] border border-[var(--border)] hover:text-rose-500' ?>">Pending
                <?= (int) $pending_report_count ?></button>
            <button onclick="switchTab('id_passport')"
                class="px-3 py-1.5 rounded-lg text-[9px] font-black uppercase tracking-widest transition-all <?= ($status_f == 'ID_Passport') ? 'bg-rose-500 text-white shadow-lg shadow-rose-500/20' : 'bg-[var(--input)] text-[var(--secondary)] border border-[var(--border)] hover:text-rose-500' ?>">ID
                & Passports</button>
        </div>

        <div class="staff-nav-tools flex items-center gap-3">
            <form method="POST">
                <input type="hidden" name="action" value="toggle_autosync">
                <button type="submit"
                    class="text-[8px] font-black uppercase tracking-widest px-3 py-1.5 rounded-lg border border-[var(--border)] <?= file_exists(__DIR__ . '/.autosync') ? 'bg-emerald-500 text-white border-emerald-500 shadow-lg shadow-emerald-500/20' : 'bg-[var(--input)] text-[var(--secondary)]' ?> transition-all">
                    Sync: <?= file_exists(__DIR__ . '/.autosync') ? 'ON' : 'OFF' ?>
                </button>
            </form>
            <div id="sync-status"
                class="text-[8px] font-black uppercase tracking-widest text-emerald-500 flex items-center gap-1.5">
                <span class="w-1.5 h-1.5 rounded-full bg-emerald-500 animate-pulse"></span>
                <span class="hidden sm:inline">Synced</span>
            </div>
            <div class="mobile-divider h-4 w-px bg-[var(--border)]"></div>
            <?php if ($is_admin): ?>
                <!-- Station Switcher Dropdown -->
                <div class="relative inline-block text-left" id="station-switcher-container">
                    <button onclick="toggleStationMenu()"
                        class="px-3 py-1.5 flex items-center gap-1.5 rounded-lg bg-[var(--input)] border border-[var(--border)] text-[8px] font-black uppercase tracking-widest text-[var(--secondary)] hover:text-rose-500 hover:border-rose-500/30 transition-all">
                        <span id="current-station-label"><?= af_h(af_get_current_station()) ?></span> <span
                            class="text-[6px] opacity-60">▼</span>
                    </button>
                    <div id="station-dropdown"
                        class="absolute right-0 mt-2 w-48 rounded-lg glass border border-[var(--border)] shadow-2xl hidden z-[100] overflow-hidden">
                        <div class="p-2 border-b border-[var(--border)] bg-slate-900/10">
                            <span
                                class="text-[8px] font-black uppercase tracking-widest text-[var(--secondary)] block px-2.5 py-1">Terminal
                                Station</span>
                        </div>
                        <div class="py-1 max-h-60 overflow-y-auto custom-scroll">
                            <?php foreach (af_stations() as $code => $name): ?>
                                <a href="?station=<?= rawurlencode($code) ?><?= ($status_f != '') ? '&status_filter=' . rawurlencode($status_f) : '' ?>"
                                    class="flex items-center justify-between px-3.5 py-2.5 text-[8px] uppercase font-black tracking-widest text-[var(--text)] hover:bg-rose-500/10 hover:text-rose-500 transition-colors <?= af_get_current_station() === $code ? 'text-rose-500 font-extrabold' : '' ?>">
                                    <span class="truncate"><?= af_h($code) ?> - <?= af_h($name) ?></span>
                                    <?php if (af_get_current_station() === $code): ?><span>✓</span><?php endif; ?>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            <?php else: ?>
                <div
                    class="px-3 py-1.5 rounded-lg bg-[var(--input)] border border-[var(--border)] text-[8px] font-black uppercase tracking-widest text-[var(--secondary)]">
                    <?= af_h(af_get_current_station()) ?>
                </div>
            <?php endif; ?>
            <div class="mobile-divider h-4 w-px bg-[var(--border)]"></div>
            <button onclick="toggleTheme()"
                class="w-8 h-8 flex items-center justify-center rounded-lg bg-[var(--input)] border border-[var(--border)] hover:bg-[var(--border)] transition-all"
                title="Toggle Light/Dark Theme">
                <span id="theme-icon" class="flex items-center justify-center"></span>
            </button>
            <a href="index.php" target="_blank"
                class="w-8 h-8 flex items-center justify-center rounded-lg bg-[var(--input)] border border-[var(--border)] hover:bg-[var(--border)] hover:text-rose-500 transition-all text-[var(--secondary)]"
                title="Open Passenger Terminal">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round"
                        d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z">
                    </path>
                </svg>
            </a>
        </div>

        <script>
            function toggleStationMenu() {
                const menu = document.getElementById('station-dropdown');
                if (menu) menu.classList.toggle('hidden');
            }
            window.addEventListener('click', function (e) {
                const container = document.getElementById('station-switcher-container');
                const menu = document.getElementById('station-dropdown');
                if (container && !container.contains(e.target) && menu) {
                    menu.classList.add('hidden');
                }
            });

            function updateIcon() {
                const isDark = document.documentElement.classList.contains('dark');
                document.getElementById('theme-icon').innerHTML = isDark
                    ? `<svg class="w-4 h-4 text-[var(--secondary)]" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364-6.364l-.707.707M6.343 17.657l-.707.707m0-12.728l.707.707m12.728 12.728l.707.707M12 8a4 4 0 100 8 4 4 0 000-8z" /></svg>`
                    : `<svg class="w-4 h-4 text-[var(--secondary)]" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z" /></svg>`;
            }
            const originalToggle = toggleTheme;
            toggleTheme = function () {
                originalToggle();
                updateIcon();
            }
            updateIcon();
        </script>
    </nav>

    <!-- Floating Action Buttons -->
    <div class="staff-fab fixed bottom-20 right-6 md:bottom-24 md:right-10 flex flex-col gap-3 md:gap-4 z-[100]">
        <?php if ($is_admin): ?>
            <button onclick="toggleModal('settings-modal')"
                class="staff-fab-item w-12 h-12 md:w-14 md:h-14 bg-slate-800 text-white rounded-2xl flex items-center justify-center shadow-2xl shadow-slate-900/40 hover:scale-110 transition-all group relative">
                <span class="text-xl md:text-2xl">⚙</span>
                <span
                    class="absolute right-full mr-4 px-3 py-1 bg-slate-900 text-white text-[10px] font-black rounded-lg opacity-0 group-hover:opacity-100 transition-opacity whitespace-nowrap pointer-events-none uppercase tracking-widest">Admin
                    Settings</span>
            </button>
        <?php endif; ?>
        <?php if ($is_admin): ?>
            <button onclick="toggleModal('support-modal')"
                class="staff-fab-item w-12 h-12 md:w-14 md:h-14 bg-emerald-500 text-white rounded-2xl flex items-center justify-center shadow-2xl shadow-emerald-500/30 hover:scale-110 transition-all group relative">
                <span class="text-xl md:text-2xl">?</span>
                <span
                    class="absolute right-full mr-4 px-3 py-1 bg-slate-900 text-white text-[10px] font-black rounded-lg opacity-0 group-hover:opacity-100 transition-opacity whitespace-nowrap pointer-events-none uppercase tracking-widest">Support</span>
            </button>
        <?php endif; ?>
        <?php if ($is_admin): ?>
            <button onclick="toggleModal('airline-modal')"
                class="staff-fab-item w-12 h-12 md:w-14 md:h-14 bg-blue-500 text-white rounded-2xl flex items-center justify-center shadow-2xl shadow-blue-500/40 hover:scale-110 hover:rotate-12 transition-all group relative">
                <span class="text-xl md:text-2xl">✈</span>
                <span
                    class="absolute right-full mr-4 px-3 py-1 bg-slate-900 text-white text-[10px] font-black rounded-lg opacity-0 group-hover:opacity-100 transition-opacity whitespace-nowrap pointer-events-none uppercase tracking-widest">Manage
                    Airlines</span>
            </button>
        <?php endif; ?>
        <button onclick="toggleModal('report-modal')"
            class="staff-fab-item w-14 h-14 md:w-16 md:h-16 bg-rose-500 text-white rounded-3xl flex items-center justify-center shadow-2xl shadow-rose-500/40 hover:scale-110 hover:-rotate-12 transition-all group relative">
            <span class="text-2xl md:text-3xl">⊕</span>
            <span
                class="absolute right-full mr-4 px-3 py-1 bg-slate-900 text-white text-[10px] font-black rounded-lg opacity-0 group-hover:opacity-100 transition-opacity whitespace-nowrap pointer-events-none uppercase tracking-widest">Report
                New Item</span>
        </button>
        <!-- Toggle Collapse Button -->
        <button onclick="toggleFab()" id="fab-toggle-btn"
            class="w-12 h-12 md:w-14 md:h-14 bg-slate-800 hover:bg-slate-700 text-slate-400 hover:text-white rounded-2xl flex items-center justify-center shadow-2xl transition-all group relative self-end">
            <svg id="fab-toggle-svg"
                class="w-5 h-5 text-slate-400 group-hover:text-white transition-transform duration-300" fill="none"
                stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M13 5l7 7-7 7M5 5l7 7-7 7" />
            </svg>
            <span id="fab-toggle-tooltip"
                class="absolute right-full mr-4 px-3 py-1 bg-slate-900 text-white text-[10px] font-black rounded-lg opacity-0 group-hover:opacity-100 transition-opacity whitespace-nowrap pointer-events-none uppercase tracking-widest">Collapse
                Menu</span>
        </button>
    </div>

    <!-- Modals -->
    <?php if ($is_admin): ?>
        <div id="support-modal"
            class="staff-modal-shell fixed inset-0 bg-slate-900/60 backdrop-blur-md z-[200] hidden justify-center">
            <div class="staff-modal-panel glass max-w-xl w-full p-6 relative animate-fade-in custom-scroll">
                <button onclick="toggleModal('support-modal')"
                    class="staff-modal-close absolute top-5 right-5 text-slate-500 hover:text-white text-xl font-bold">×</button>
                <div class="staff-modal-header flex items-center gap-3 mb-5">
                    <div
                        class="staff-modal-icon w-10 h-10 bg-emerald-500/10 text-emerald-500 rounded-xl flex items-center justify-center text-lg font-black">
                        ?</div>
                    <h2 class="staff-modal-title font-black text-xl uppercase tracking-tight">Support</h2>
                </div>
                <div class="space-y-4">
                    <div class="bg-[var(--input)] border border-[var(--border)] rounded-2xl p-4">
                        <p class="text-[9px] font-black uppercase tracking-widest text-slate-500 mb-2">Developer Contact</p>
                        <?php if ($developer_contact_email !== ''): ?>
                            <a href="mailto:<?= af_h($developer_contact_email) ?>"
                                class="text-sm font-bold text-rose-500 hover:text-rose-600 break-all"><?= af_h($developer_contact_email) ?></a>
                        <?php else: ?>
                            <p class="text-sm text-[var(--secondary)]">No developer contact configured.</p>
                        <?php endif; ?>
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div class="bg-[var(--input)] border border-[var(--border)] rounded-2xl p-4">
                            <p class="text-[9px] font-black uppercase tracking-widest text-slate-500 mb-2">Portal</p>
                            <p class="text-sm font-bold"><?= af_h($company_name) ?></p>
                        </div>
                        <div class="bg-[var(--input)] border border-[var(--border)] rounded-2xl p-4">
                            <p class="text-[9px] font-black uppercase tracking-widest text-slate-500 mb-2">Access Level</p>
                            <p class="text-sm font-bold">Admin</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <div id="report-modal"
        class="fixed inset-0 bg-slate-900/60 backdrop-blur-md z-[200] hidden items-center justify-center p-4">
        <div class="glass max-w-lg w-full p-6 rounded-3xl relative animate-fade-in max-h-[92vh] overflow-y-auto">
            <button onclick="toggleModal('report-modal')"
                class="absolute top-6 right-6 text-slate-500 hover:text-white text-xl font-bold">×</button>
            <div class="flex items-center gap-3 mb-4">
                <div
                    class="w-8 h-8 bg-rose-500/10 text-rose-500 rounded-lg flex items-center justify-center text-base font-black">
                    ⊕</div>
                <h2 class="font-black text-lg uppercase tracking-tight text-[var(--text)]">Report New Item</h2>
            </div>
            <form method="POST" enctype="multipart/form-data" class="space-y-3">
                <input type="hidden" name="action" value="add_item">
                <input type="hidden" name="other_info" id="other_info_input">

                <!-- Row 1: Identification & General Info -->
                <div class="grid grid-cols-3 gap-3">
                    <div class="group">
                        <label class="block text-[8px] font-black uppercase tracking-widest text-slate-500 mb-1">Tag
                            Number</label>
                        <input type="text" name="tag_no" required value="<?= $auto_tag ?>"
                            class="w-full bg-[var(--input)] border border-[var(--border)] text-[var(--text)] focus:border-rose-500/50 rounded-lg px-3 py-2 text-xs outline-none transition-all"
                            placeholder="e.g. ID-1154">
                    </div>
                    <div class="group col-span-2">
                        <label class="block text-[8px] font-black uppercase tracking-widest text-slate-500 mb-1">Item
                            Description</label>
                        <input type="text" name="item_description" required
                            class="w-full bg-[var(--input)] border border-[var(--border)] text-[var(--text)] focus:border-rose-500/50 rounded-lg px-3 py-2 text-xs outline-none transition-all"
                            placeholder="e.g. Black iPhone 15 with leather case">
                    </div>
                </div>

                <!-- Row 2: Flight & Location Info -->
                <div class="grid grid-cols-3 gap-3">
                    <div class="group">
                        <label
                            class="block text-[8px] font-black uppercase tracking-widest text-slate-500 mb-1">Airline</label>
                        <select name="airline_select"
                            class="w-full bg-[var(--input)] border border-[var(--border)] text-[var(--text)] focus:border-rose-500/50 rounded-lg px-3 py-2 text-xs outline-none transition-all cursor-pointer"
                            onchange="document.getElementById('other_info_input').value = this.value + ' ' + document.getElementById('flight_num_input').value">
                            <option value="">Select Airline...</option>
                            <?php foreach ($al as $a): ?>
                                <option value="<?= af_h($a['name']) ?>"><?= af_h($a['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="group">
                        <label class="block text-[8px] font-black uppercase tracking-widest text-slate-500 mb-1">Flight
                            Number</label>
                        <input type="text" id="flight_num_input"
                            class="w-full bg-[var(--input)] border border-[var(--border)] text-[var(--text)] focus:border-rose-500/50 rounded-lg px-3 py-2 text-xs outline-none transition-all"
                            placeholder="e.g. LH123"
                            oninput="const sel = document.querySelector('select[name=airline_select]'); document.getElementById('other_info_input').value = sel.value + ' ' + this.value">
                    </div>
                    <div class="group">
                        <label class="block text-[8px] font-black uppercase tracking-widest text-slate-500 mb-1">Seat /
                            Location</label>
                        <input type="text" name="comments"
                            class="w-full bg-[var(--input)] border border-[var(--border)] text-[var(--text)] focus:border-rose-500/50 rounded-lg px-3 py-2 text-xs outline-none transition-all"
                            placeholder="e.g. 24F">
                    </div>
                </div>

                <!-- Row 3: Status, Date & Photo -->
                <div class="grid grid-cols-3 gap-3">
                    <div class="group">
                        <label
                            class="block text-[8px] font-black uppercase tracking-widest text-slate-500 mb-1">Status</label>
                        <select name="status"
                            class="w-full bg-[var(--input)] border border-[var(--border)] text-[var(--text)] focus:border-rose-500/50 rounded-lg px-3 py-2 text-xs outline-none transition-all cursor-pointer">
                            <option value="Found">Found</option>
                            <option value="Lost">Lost</option>
                        </select>
                    </div>
                    <div class="group">
                        <label class="block text-[8px] font-black uppercase tracking-widest text-slate-500 mb-1">Date
                            Added</label>
                        <input type="date" name="created_at"
                            class="w-full bg-[var(--input)] border border-[var(--border)] text-[var(--text)] focus:border-rose-500/50 rounded-lg px-3 py-2 text-xs outline-none transition-all"
                            value="<?= date('Y-m-d') ?>">
                    </div>
                    <div class="group">
                        <label class="block text-[8px] font-black uppercase tracking-widest text-slate-500 mb-1">Attach
                            Photo</label>
                        <input type="file" name="photo" accept="image/jpeg,image/png,image/webp"
                            class="w-full bg-[var(--input)] border border-[var(--border)] text-[var(--text)] rounded-lg px-2 py-1 text-[9px] outline-none file:mr-2 file:py-0.5 file:px-2 file:rounded-md file:border-0 file:text-[9px] file:font-black file:bg-rose-500/10 file:text-rose-500 cursor-pointer">
                    </div>
                </div>

                <!-- Row 4: Passenger Contact Information -->
                <div class="border-t border-[var(--border)] pt-3 mt-1.5">
                    <p class="text-[8px] font-black uppercase tracking-widest text-rose-500 mb-2">Passenger Details
                        (Optional)</p>
                    <div class="grid grid-cols-3 gap-3">
                        <div class="group">
                            <label
                                class="block text-[8px] font-black uppercase tracking-widest text-slate-500 mb-1">Full
                                Name</label>
                            <input type="text" name="pax_name"
                                class="w-full bg-[var(--input)] border border-[var(--border)] text-[var(--text)] focus:border-rose-500/50 rounded-lg px-3 py-2 text-xs outline-none transition-all"
                                placeholder="John Doe">
                        </div>
                        <div class="group">
                            <label
                                class="block text-[8px] font-black uppercase tracking-widest text-slate-500 mb-1">Email
                                Address</label>
                            <input type="email" name="pax_email"
                                class="w-full bg-[var(--input)] border border-[var(--border)] text-[var(--text)] focus:border-rose-500/50 rounded-lg px-3 py-2 text-xs outline-none transition-all"
                                placeholder="john@example.com">
                        </div>
                        <div class="group">
                            <label
                                class="block text-[8px] font-black uppercase tracking-widest text-slate-500 mb-1">Contact
                                Number</label>
                            <input type="text" name="pax_contact_no"
                                class="w-full bg-[var(--input)] border border-[var(--border)] text-[var(--text)] focus:border-rose-500/50 rounded-lg px-3 py-2 text-xs outline-none transition-all"
                                placeholder="+1 234 5678">
                        </div>
                    </div>
                </div>

                <!-- Row 5: Notes & Handover -->
                <div class="border-t border-[var(--border)] pt-3 mt-1.5">
                    <div class="grid grid-cols-2 gap-3">
                        <div class="group">
                            <label
                                class="block text-[8px] font-black uppercase tracking-widest text-slate-500 mb-1">Staff
                                Internal Comments</label>
                            <input type="text" name="user_comments"
                                class="w-full bg-[var(--input)] border border-[var(--border)] text-[var(--text)] focus:border-rose-500/50 rounded-lg px-3 py-2 text-xs outline-none transition-all"
                                placeholder="e.g. Found near seat belt slot">
                        </div>
                        <div class="group">
                            <label
                                class="block text-[8px] font-black uppercase tracking-widest text-slate-500 mb-1">Handover
                                & Delivery Notes</label>
                            <input type="text" name="delivery_info"
                                class="w-full bg-[var(--input)] border border-[var(--border)] text-[var(--text)] focus:border-rose-500/50 rounded-lg px-3 py-2 text-xs outline-none transition-all"
                                placeholder="e.g. Awaiting claimant pickup">
                        </div>
                    </div>
                </div>

                <!-- ID/Passport Toggle Checkbox -->
                <div class="flex items-center gap-2 mt-2">
                    <input type="checkbox" id="is_id_passport" name="is_id_passport"
                        class="w-3.5 h-3.5 rounded text-rose-500 bg-slate-900 border-slate-700 focus:ring-rose-500 cursor-pointer transition-all"
                        onchange="togglePassportTag(this.checked)">

                    <label for="is_id_passport"
                        class="text-[8px] font-black uppercase tracking-widest text-slate-400 hover:text-slate-700 dark:hover:text-white cursor-pointer select-none transition-colors">
                        Identity Document / Passport (PP- Prefix)
                    </label>
                </div>

                <button type="submit"
                    class="w-full bg-rose-500 hover:bg-rose-600 text-white py-2.5 rounded-xl font-black text-[9px] uppercase tracking-[0.25em] mt-3 shadow-lg shadow-rose-500/20 hover:shadow-rose-500/30 transition-all hover:scale-[1.01]">
                    Publish Record
                </button>
            </form>
        </div>
    </div>

    <?php if ($is_admin): ?>
        <div id="airline-modal"
            class="staff-modal-shell fixed inset-0 bg-slate-900/60 backdrop-blur-md z-[200] hidden justify-center">
            <div class="staff-modal-panel glass max-w-xl w-full p-6 relative animate-fade-in custom-scroll">
                <button onclick="toggleModal('airline-modal')"
                    class="staff-modal-close absolute top-5 right-5 text-slate-500 hover:text-white text-xl font-bold">×</button>
                <div class="staff-modal-header flex items-center gap-3 mb-5">
                    <div
                        class="staff-modal-icon w-10 h-10 bg-blue-500/10 text-blue-500 rounded-xl flex items-center justify-center text-lg font-black">
                        ✈</div>
                    <h2 class="staff-modal-title font-black text-xl uppercase tracking-tight">Airline Management</h2>
                </div>
                <form method="POST" class="airline-add-form flex gap-2 mb-5">
                    <input type="hidden" name="action" value="add_airline">
                    <input type="text" name="airline_name" required placeholder="Name (e.g. Emirates)"
                        class="staff-modal-field flex-grow input-dark text-sm">
                    <input type="text" name="airline_code" required placeholder="Code"
                        class="staff-modal-field w-24 input-dark text-sm">
                    <button type="submit"
                        class="bg-blue-500 hover:bg-blue-600 text-white px-5 rounded-xl font-black text-[9px] uppercase tracking-widest transition-all">Add</button>
                </form>
                <div class="airline-list space-y-2 max-h-[360px] overflow-y-auto custom-scroll pr-1">
                    <?php
                    $al = $pdo->query("SELECT * FROM airlines ORDER BY name ASC")->fetchAll();
                    foreach ($al as $a): ?>
                        <div
                            class="airline-list-row flex items-center justify-between bg-[var(--input)] border border-[var(--border)] group hover:border-[var(--secondary)] transition-all">
                            <div class="flex items-center gap-3 min-w-0">
                                <img src="<?= af_h($a['logo']) ?>"
                                    class="w-9 h-9 rounded-lg object-contain bg-white p-1.5 shrink-0"
                                    onerror="this.src='https://ui-avatars.com/api/?name=<?= urlencode($a['name']) ?>'">
                                <div class="min-w-0">
                                    <p class="text-[13px] font-bold tracking-tight"><?= af_h($a['name']) ?></p>
                                    <p class="text-[9px] font-black uppercase text-[var(--secondary)] tracking-widest">
                                        <?= af_h($a['code']) ?>
                                    </p>
                                </div>
                            </div>
                            <form method="POST"
                                onsubmit="event.preventDefault(); const form = this; toast.confirm('Are you sure you want to permanently delete this airline? This will remove its branding from the system.', () => form.submit(), null, { title: 'Confirm Deletion', confirmText: 'Yes, Delete', intent: 'danger' });">
                                <input type="hidden" name="action" value="delete_airline">
                                <input type="hidden" name="airline_id" value="<?= af_h($a['id']) ?>">
                                <button type="submit"
                                    class="w-8 h-8 flex items-center justify-center text-rose-500 hover:bg-rose-500/10 rounded-lg transition-all font-bold">×</button>
                            </form>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($is_admin): ?>
        <div id="settings-modal"
            class="fixed inset-0 bg-slate-900/60 backdrop-blur-md z-[200] hidden items-center justify-center p-4">
            <div class="glass max-w-3xl w-full p-6 rounded-3xl relative animate-fade-in max-h-[92vh] overflow-y-auto">
                <button onclick="toggleModal('settings-modal')"
                    class="absolute top-6 right-6 text-slate-500 hover:text-white text-xl font-bold">×</button>
                <div class="flex items-center gap-3 mb-4">
                    <div
                        class="w-8 h-8 bg-rose-500/10 text-rose-500 rounded-lg flex items-center justify-center text-base font-black">
                        ⚙</div>
                    <h2 class="font-black text-lg uppercase tracking-tight text-[var(--text)]">System & Email Settings</h2>
                </div>
                <button type="button" onclick="toggleSettingsSection('system-settings-body', 'system-settings-chevron')"
                    id="system-settings-toggle-btn"
                    class="w-full flex items-center justify-between gap-3 px-4 py-3 rounded-2xl bg-[var(--input)] border border-[var(--border)] hover:border-rose-500/30 hover:bg-rose-500/5 shadow-sm transition-all group">
                    <div class="flex items-center gap-3 min-w-0">
                        <div
                            class="w-8 h-8 bg-rose-500/10 text-rose-500 rounded-xl flex items-center justify-center text-sm border border-rose-500/10 group-hover:bg-rose-500 group-hover:text-white transition-all">
                            ⚙</div>
                        <div class="text-left">
                            <span class="font-black text-[11px] uppercase tracking-wider text-[var(--text)]">System
                                Configuration</span>
                            <span
                                class="block text-[8px] text-slate-500 uppercase font-black tracking-widest mt-0.5">Branding,
                                accounts, terminal view, and email delivery</span>
                        </div>
                    </div>
                    <span id="system-settings-chevron"
                        class="w-7 h-7 flex items-center justify-center rounded-lg bg-[var(--card)] border border-[var(--border)] text-slate-500 text-xs transition-transform duration-200">▶</span>
                </button>
                <div id="system-settings-body" style="display:none" class="pt-2">
                    <form method="POST" class="space-y-4" enctype="multipart/form-data">
                        <input type="hidden" name="action" value="update_settings">

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <!-- Column 1: System settings -->
                            <div class="space-y-3">
                                <h3
                                    class="text-[9px] font-black uppercase tracking-widest text-rose-500 border-b border-[var(--border)] pb-1 mb-2">
                                    System Config</h3>

                                <div class="grid grid-cols-2 gap-3">
                                    <div class="group">
                                        <label
                                            class="block text-[8px] font-black uppercase tracking-widest text-slate-500 mb-1">Company
                                            Name</label>
                                        <input type="text" name="company_name" required value="<?= af_h($company_name) ?>"
                                            class="w-full input-dark rounded-lg px-3 py-2 text-xs transition-all"
                                            placeholder="Company name">
                                    </div>
                                    <div class="group">
                                        <label
                                            class="block text-[8px] font-black uppercase tracking-widest text-slate-500 mb-1">Short
                                            Name</label>
                                        <input type="text" name="company_short_name" required
                                            value="<?= af_h($company_short_name) ?>"
                                            class="w-full input-dark rounded-lg px-3 py-2 text-xs transition-all"
                                            placeholder="Short name">
                                    </div>
                                </div>

                                <div class="grid grid-cols-2 gap-3">
                                    <div class="group">
                                        <label
                                            class="block text-[8px] font-black uppercase tracking-widest text-slate-500 mb-1">Tagline</label>
                                        <input type="text" name="company_tagline" value="<?= af_h($company_tagline) ?>"
                                            class="w-full input-dark rounded-lg px-3 py-2 text-xs transition-all"
                                            placeholder="Cabin Operations Division">
                                    </div>
                                    <div class="group">
                                        <label
                                            class="block text-[8px] font-black uppercase tracking-widest text-slate-500 mb-1">Initials</label>
                                        <input type="text" name="company_initials" maxlength="4"
                                            value="<?= af_h($company_initials) ?>"
                                            class="w-full input-dark rounded-lg px-3 py-2 text-xs transition-all"
                                            placeholder="AF">
                                    </div>
                                </div>

                                <div class="group">
                                    <label
                                        class="block text-[8px] font-black uppercase tracking-widest text-slate-500 mb-1">Company
                                        Logo</label>
                                    <input type="url" name="company_logo" value="<?= af_h($company_logo) ?>"
                                        class="w-full input-dark rounded-lg px-3 py-2 text-xs transition-all mb-1.5"
                                        placeholder="https://example.com/logo.png">
                                    <input type="file" name="company_logo_upload" accept="image/jpeg,image/png,image/webp"
                                        class="w-full input-dark rounded-lg px-2 py-1 text-[9px] file:mr-2 file:py-0.5 file:px-2 file:rounded-md file:border-0 file:text-[9px] file:font-black file:bg-rose-500/10 file:text-rose-500 cursor-pointer">
                                    <p
                                        class="text-[8px] text-slate-500 mt-1 uppercase font-black tracking-tight leading-relaxed">
                                        Use a logo URL or upload a local image.</p>
                                </div>

                                <div class="group">
                                    <label
                                        class="block text-[8px] font-black uppercase tracking-widest text-slate-500 mb-1">Favicon</label>
                                    <input type="url" name="favicon_url" value="<?= af_h($favicon_url) ?>"
                                        class="w-full input-dark rounded-lg px-3 py-2 text-xs transition-all mb-1.5"
                                        placeholder="https://example.com/favicon.ico">
                                    <input type="file" name="favicon_upload" accept="image/jpeg,image/png,image/webp,.ico"
                                        class="w-full input-dark rounded-lg px-2 py-1 text-[9px] file:mr-2 file:py-0.5 file:px-2 file:rounded-md file:border-0 file:text-[9px] file:font-black file:bg-rose-500/10 file:text-rose-500 cursor-pointer">
                                </div>

                                <div class="grid grid-cols-2 gap-3">
                                    <div class="group">
                                        <label
                                            class="block text-[8px] font-black uppercase tracking-widest text-slate-500 mb-1">Staff
                                            Username</label>
                                        <input type="text" name="staff_username" required
                                            value="<?= af_h($staff_username) ?>"
                                            class="w-full input-dark rounded-lg px-3 py-2 text-xs transition-all"
                                            placeholder="admin">
                                    </div>
                                    <div class="group">
                                        <label
                                            class="block text-[8px] font-black uppercase tracking-widest text-slate-500 mb-1">New
                                            Staff Password</label>
                                        <input type="password" name="staff_password"
                                            class="w-full input-dark rounded-lg px-3 py-2 text-xs transition-all"
                                            placeholder="Leave blank to keep current password">
                                    </div>
                                </div>

                                <div class="group">
                                    <label
                                        class="block text-[8px] font-black uppercase tracking-widest text-slate-500 mb-1">Staff
                                        Email</label>
                                    <input type="email" name="staff_login_email" value="<?= af_h($staff_login_email) ?>"
                                        class="w-full input-dark rounded-lg px-3 py-2 text-xs transition-all"
                                        placeholder="staff@example.com">
                                </div>

                                <div class="grid grid-cols-2 gap-3">
                                    <div class="group">
                                        <label
                                            class="block text-[8px] font-black uppercase tracking-widest text-slate-500 mb-1">Admin
                                            Username</label>
                                        <input type="text" name="admin_username" required
                                            value="<?= af_h($admin_username) ?>"
                                            class="w-full input-dark rounded-lg px-3 py-2 text-xs transition-all"
                                            placeholder="admin">
                                    </div>
                                    <div class="group">
                                        <label
                                            class="block text-[8px] font-black uppercase tracking-widest text-slate-500 mb-1">New
                                            Admin Password</label>
                                        <input type="password" name="admin_password"
                                            class="w-full input-dark rounded-lg px-3 py-2 text-xs transition-all"
                                            placeholder="Leave blank to keep current password">
                                    </div>
                                </div>

                                <div class="group">
                                    <label
                                        class="block text-[8px] font-black uppercase tracking-widest text-slate-500 mb-1">Developer
                                        Contact Email</label>
                                    <input type="email" name="developer_contact_email"
                                        value="<?= af_h($developer_contact_email) ?>"
                                        class="w-full input-dark rounded-lg px-3 py-2 text-xs transition-all"
                                        placeholder="developer@example.com">
                                    <p
                                        class="text-[8px] text-slate-500 mt-1 uppercase font-black tracking-tight leading-relaxed">
                                        Placeholder support contact for release builds. Edit this before handoff.</p>
                                </div>

                                <div class="group">
                                    <label
                                        class="block text-[8px] font-black uppercase tracking-widest text-slate-500 mb-1">Terminal
                                        View Range (Days)</label>
                                    <input type="number" name="passenger_view_days" required
                                        value="<?= $passenger_view_days ?>"
                                        class="w-full input-dark rounded-lg px-3 py-2 text-xs transition-all"
                                        placeholder="30">
                                    <p
                                        class="text-[8px] text-slate-500 mt-1 uppercase font-black tracking-tight leading-relaxed">
                                        Only items found within these days will appear on the passenger terminal.</p>
                                </div>

                                <div class="group">
                                    <label
                                        class="block text-[8px] font-black uppercase tracking-widest text-slate-500 mb-1">Lost
                                        Item Card Layout</label>
                                    <select name="passenger_card_layout"
                                        class="w-full input-dark rounded-lg px-3 py-2 text-xs transition-all cursor-pointer">
                                        <option value="dual" <?= $passenger_card_layout === 'dual' ? 'selected' : '' ?>>Dual
                                            cards
                                            on mobile</option>
                                        <option value="single" <?= $passenger_card_layout === 'single' ? 'selected' : '' ?>>
                                            Single
                                            card on mobile</option>
                                    </select>
                                    <p
                                        class="text-[8px] text-slate-500 mt-1 uppercase font-black tracking-tight leading-relaxed">
                                        Controls the passenger terminal card grid on phones.</p>
                                </div>

                                <div class="group">
                                    <label
                                        class="block text-[8px] font-black uppercase tracking-widest text-slate-500 mb-1">Staff
                                        Notification Email</label>
                                    <input type="email" name="staff_notification_email" required
                                        value="<?= htmlspecialchars($staff_notification_email) ?>"
                                        class="w-full input-dark rounded-lg px-3 py-2 text-xs transition-all"
                                        placeholder="staff@aerofind.online">
                                    <p
                                        class="text-[8px] text-slate-500 mt-1 uppercase font-black tracking-tight leading-relaxed">
                                        Receives claim notifications when passengers request their items.</p>
                                </div>

                                <div class="group">
                                    <label
                                        class="block text-[8px] font-black uppercase tracking-widest text-slate-500 mb-1">Pick-up
                                        Location & Info</label>
                                    <textarea name="pickup_info" required rows="3"
                                        class="w-full input-dark rounded-lg px-3 py-2 text-xs transition-all resize-none"
                                        placeholder="Location details for passenger claim..."><?= htmlspecialchars($pickup_info) ?></textarea>
                                    <p
                                        class="text-[8px] text-slate-500 mt-1 uppercase font-black tracking-tight leading-relaxed">
                                        Sent in HTML claim emails so passengers know where to collect items.</p>
                                </div>
                            </div>

                            <!-- Column 2: SMTP server configuration -->
                            <div class="space-y-3">
                                <div class="flex justify-between items-center border-b border-[var(--border)] pb-1 mb-2">
                                    <h3 class="text-[9px] font-black uppercase tracking-widest text-rose-500">SMTP Mail
                                        Server
                                        (Optional)</h3>
                                    <button type="button" onclick="prefillIonos()"
                                        class="bg-rose-500/10 hover:bg-rose-500/20 text-rose-500 border border-rose-500/10 px-2 py-0.5 rounded text-[8px] font-black uppercase tracking-wider transition-all">
                                        Prefill IONOS SMTP
                                    </button>
                                </div>

                                <div class="group">
                                    <label
                                        class="block text-[8px] font-black uppercase tracking-widest text-slate-500 mb-1">Email
                                        Delivery</label>
                                    <select name="email_delivery_method"
                                        class="w-full input-dark rounded-lg px-3 py-2 text-xs transition-all cursor-pointer">
                                        <option value="php_mail" <?= $email_delivery_method === 'php_mail' ? 'selected' : '' ?>>PHP
                                            mail()</option>
                                        <option value="smtp" <?= $email_delivery_method === 'smtp' ? 'selected' : '' ?>>SMTP
                                        </option>
                                    </select>
                                </div>

                                <div class="grid grid-cols-3 gap-3">
                                    <div class="col-span-2 group">
                                        <label
                                            class="block text-[8px] font-black uppercase tracking-widest text-slate-500 mb-1">SMTP
                                            Host</label>
                                        <input type="text" name="smtp_host" value="<?= htmlspecialchars($smtp_host) ?>"
                                            class="w-full input-dark rounded-lg px-3 py-2 text-xs transition-all"
                                            placeholder="smtp.gmail.com">
                                    </div>
                                    <div class="group">
                                        <label
                                            class="block text-[8px] font-black uppercase tracking-widest text-slate-500 mb-1">Port</label>
                                        <input type="number" name="smtp_port" value="<?= htmlspecialchars($smtp_port) ?>"
                                            class="w-full input-dark rounded-lg px-3 py-2 text-xs transition-all"
                                            placeholder="587">
                                    </div>
                                </div>

                                <div class="group">
                                    <label
                                        class="block text-[8px] font-black uppercase tracking-widest text-slate-500 mb-1">Encryption</label>
                                    <select name="smtp_encryption"
                                        class="w-full input-dark rounded-lg px-3 py-2 text-xs transition-all cursor-pointer">
                                        <option value="none" <?= $smtp_encryption === 'none' ? 'selected' : '' ?>>None
                                            (Standard)
                                        </option>
                                        <option value="ssl" <?= $smtp_encryption === 'ssl' ? 'selected' : '' ?>>SSL</option>
                                        <option value="tls" <?= $smtp_encryption === 'tls' ? 'selected' : '' ?>>TLS (STARTTLS)
                                        </option>
                                    </select>
                                </div>

                                <div class="group">
                                    <label
                                        class="block text-[8px] font-black uppercase tracking-widest text-slate-500 mb-1">SMTP
                                        Username</label>
                                    <input type="text" name="smtp_user" value="<?= htmlspecialchars($smtp_user) ?>"
                                        class="w-full input-dark rounded-lg px-3 py-2 text-xs transition-all"
                                        placeholder="username@gmail.com">
                                </div>

                                <div class="group">
                                    <label
                                        class="block text-[8px] font-black uppercase tracking-widest text-slate-500 mb-1">SMTP
                                        Password</label>
                                    <input type="password" name="smtp_password" value=""
                                        class="w-full input-dark rounded-lg px-3 py-2 text-xs transition-all"
                                        placeholder="Leave blank to keep current password">
                                </div>

                                <div class="group">
                                    <label
                                        class="block text-[8px] font-black uppercase tracking-widest text-slate-500 mb-1">Sender
                                        From Email</label>
                                    <input type="email" name="smtp_from_email"
                                        value="<?= htmlspecialchars($smtp_from_email) ?>"
                                        class="w-full input-dark rounded-lg px-3 py-2 text-xs transition-all"
                                        placeholder="noreply@aerofind.online">
                                </div>
                                <p
                                    class="text-[8px] text-slate-400 dark:text-slate-500 uppercase font-black text-center mt-3">
                                    Leave
                                    SMTP Host blank to default to standard server PHP mail() delivery.</p>
                            </div>
                        </div>

                        <button type="submit"
                            class="w-full bg-rose-500 hover:bg-rose-600 text-white py-2.5 rounded-xl font-black text-[9px] uppercase tracking-[0.25em] mt-2 shadow-lg shadow-rose-500/20 hover:shadow-rose-500/30 transition-all hover:scale-[1.01]">
                            Save System Configuration
                        </button>
                    </form>
                </div>

                <div class="mt-3 space-y-3">
                    <button type="button"
                        onclick="toggleSettingsSection('station-settings-body', 'station-settings-chevron')"
                        id="station-settings-toggle-btn"
                        class="w-full flex items-center justify-between gap-3 px-4 py-3 rounded-2xl bg-[var(--input)] border border-[var(--border)] hover:border-rose-500/30 hover:bg-rose-500/5 shadow-sm transition-all group">
                        <div class="flex items-center gap-3 min-w-0">
                            <div
                                class="w-8 h-8 bg-rose-500/10 text-rose-500 rounded-xl flex items-center justify-center text-sm border border-rose-500/10 group-hover:bg-rose-500 group-hover:text-white transition-all">
                                ⌖</div>
                            <div class="text-left">
                                <span
                                    class="font-black text-[11px] uppercase tracking-wider text-[var(--text)]">Stations</span>
                                <span
                                    class="block text-[8px] text-slate-500 uppercase font-black tracking-widest mt-0.5">Add
                                    stations and assign station-specific credentials</span>
                            </div>
                        </div>
                        <span id="station-settings-chevron"
                            class="w-7 h-7 flex items-center justify-center rounded-lg bg-[var(--card)] border border-[var(--border)] text-slate-500 text-xs transition-transform duration-200">▶</span>
                    </button>
                    <div id="station-settings-body" style="display:none" class="space-y-3 pt-1">
                        <div>
                            <h3 class="text-[9px] font-black uppercase tracking-widest text-rose-500">Station Registry</h3>
                            <p class="text-[8px] text-slate-500 uppercase font-black tracking-tight mt-1">
                                Current station: <?= af_h(af_get_current_station()) ?> uses
                                <?= af_h(basename(af_station_db_file(af_get_current_station()))) ?>
                            </p>
                        </div>

                        <form method="POST"
                            class="grid grid-cols-1 md:grid-cols-[90px_1fr_auto] gap-2 bg-[var(--input)] border border-[var(--border)] rounded-2xl p-3">
                            <input type="hidden" name="action" value="add_station">
                            <input type="text" name="station_code" required maxlength="4" placeholder="MUC"
                                class="input-dark rounded-lg px-3 py-2 text-xs uppercase font-black tracking-widest">
                            <input type="text" name="station_name" required placeholder="Station name"
                                class="input-dark rounded-lg px-3 py-2 text-xs">
                            <button type="submit"
                                class="bg-rose-500 hover:bg-rose-600 text-white px-4 py-2 rounded-lg font-black text-[8px] uppercase tracking-widest transition-all">Add
                                Station</button>
                        </form>

                        <div class="space-y-2 max-h-[420px] overflow-y-auto custom-scroll pr-1">
                            <?php foreach (af_stations() as $station_code => $station_name): ?>
                                <?php
                                $station_settings = af_settings(station_pdo_for_admin($station_code));
                                $station_staff_username = $station_settings['staff_username'] ?? 'admin';
                                $station_admin_username = $station_settings['admin_username'] ?? 'admin';
                                ?>
                                <div class="bg-[var(--input)] border border-[var(--border)] rounded-2xl p-3 space-y-3">
                                    <div class="flex items-center justify-between gap-3">
                                        <div class="min-w-0">
                                            <p class="text-sm font-black text-[var(--text)] uppercase tracking-tight">
                                                <?= af_h($station_code) ?> - <?= af_h($station_name) ?>
                                            </p>
                                            <p class="text-[8px] text-slate-500 uppercase font-black tracking-widest truncate">
                                                <?= af_h(basename(af_station_db_file($station_code))) ?>
                                            </p>
                                        </div>
                                        <?php if (count(af_stations()) > 1): ?>
                                            <form method="POST"
                                                onsubmit="event.preventDefault(); const form = this; toast.confirm('Remove this station from the selector? The database file will be left on disk.', () => form.submit(), null, { title: 'Remove Station', confirmText: 'Remove', intent: 'danger' });">
                                                <input type="hidden" name="action" value="delete_station">
                                                <input type="hidden" name="station_code" value="<?= af_h($station_code) ?>">
                                                <button type="submit"
                                                    class="w-8 h-8 flex items-center justify-center text-rose-500 hover:bg-rose-500/10 rounded-lg transition-all font-bold">×</button>
                                            </form>
                                        <?php endif; ?>
                                    </div>

                                    <form method="POST" class="grid grid-cols-1 md:grid-cols-2 gap-2">
                                        <input type="hidden" name="action" value="update_station_credentials">
                                        <input type="hidden" name="station_code" value="<?= af_h($station_code) ?>">
                                        <div class="md:col-span-2">
                                            <label
                                                class="block text-[8px] font-black uppercase tracking-widest text-slate-500 mb-1">Station
                                                Name</label>
                                            <input type="text" name="station_name" required value="<?= af_h($station_name) ?>"
                                                class="w-full input-dark rounded-lg px-3 py-2 text-xs">
                                        </div>
                                        <div>
                                            <label
                                                class="block text-[8px] font-black uppercase tracking-widest text-slate-500 mb-1">Staff
                                                Username</label>
                                            <input type="text" name="station_staff_username" required
                                                value="<?= af_h($station_staff_username) ?>"
                                                class="w-full input-dark rounded-lg px-3 py-2 text-xs">
                                        </div>
                                        <div>
                                            <label
                                                class="block text-[8px] font-black uppercase tracking-widest text-slate-500 mb-1">New
                                                Staff Password</label>
                                            <input type="password" name="station_staff_password"
                                                class="w-full input-dark rounded-lg px-3 py-2 text-xs"
                                                placeholder="Leave blank to keep current">
                                        </div>
                                        <div>
                                            <label
                                                class="block text-[8px] font-black uppercase tracking-widest text-slate-500 mb-1">Admin
                                                Username</label>
                                            <input type="text" name="station_admin_username" required
                                                value="<?= af_h($station_admin_username) ?>"
                                                class="w-full input-dark rounded-lg px-3 py-2 text-xs">
                                        </div>
                                        <div>
                                            <label
                                                class="block text-[8px] font-black uppercase tracking-widest text-slate-500 mb-1">New
                                                Admin Password</label>
                                            <input type="password" name="station_admin_password"
                                                class="w-full input-dark rounded-lg px-3 py-2 text-xs"
                                                placeholder="Leave blank to keep current">
                                        </div>
                                        <button type="submit"
                                            class="md:col-span-2 bg-[var(--card)] hover:bg-rose-500/10 border border-[var(--border)] hover:border-rose-500/30 text-[var(--text)] px-4 py-2 rounded-lg font-black text-[8px] uppercase tracking-widest transition-all">
                                            Save <?= af_h($station_code) ?> Credentials
                                        </button>
                                    </form>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <!-- Purge Records — standalone form, NOT nested inside the settings form -->
                <div class="mt-3 space-y-0">
                    <!-- Collapsible header -->
                    <button type="button" onclick="togglePurgeSection()" id="purge-toggle-btn"
                        class="w-full flex items-center justify-between gap-3 px-4 py-3 rounded-2xl bg-[var(--input)] border border-rose-500/20 hover:border-rose-500/40 hover:bg-rose-500/5 shadow-sm transition-all group">
                        <div class="flex items-center gap-3 min-w-0">
                            <div
                                class="w-8 h-8 bg-rose-500/10 text-rose-500 rounded-xl flex items-center justify-center text-sm border border-rose-500/10 group-hover:bg-rose-500 group-hover:text-white transition-all">
                                ⚠️</div>
                            <div class="text-left">
                                <span class="font-black text-[11px] uppercase tracking-wider text-[var(--text)]">Dangerous
                                    Zone: Purge Records</span>
                                <span
                                    class="block text-[8px] text-slate-500 uppercase font-black tracking-widest mt-0.5">Preview
                                    and permanently remove aged records</span>
                            </div>
                        </div>
                        <span id="purge-chevron"
                            class="w-7 h-7 flex items-center justify-center rounded-lg bg-[var(--card)] border border-rose-500/20 text-slate-500 text-xs transition-transform duration-200">▶</span>
                    </button>

                    <!-- Collapsible body — collapsed by default -->
                    <div id="purge-body" style="display:none" class="space-y-2.5 pt-1">
                        <form method="POST" id="purge-form" onsubmit="return preparePurgeForm(event)"
                            class="bg-rose-500/5 border border-rose-500/10 rounded-2xl p-3 space-y-2.5">
                            <?= af_csrf_input() ?>
                            <input type="hidden" name="action" value="delete_records_period">
                            <input type="hidden" name="range_type" id="purge-range-type" value="preset">

                            <!-- Method toggle -->
                            <div>
                                <label
                                    class="block text-[8px] font-black uppercase tracking-widest text-slate-400 mb-1">Purge
                                    Selection Method</label>
                                <div class="grid grid-cols-2 gap-1 bg-slate-950/40 p-0.5 rounded-lg border border-white/5">
                                    <button type="button" id="method-preset" onclick="setPurgeMethod('preset')"
                                        class="py-1 px-2 rounded-md text-[8px] font-black uppercase tracking-wider transition-all bg-rose-500 text-white shadow-lg shadow-rose-500/20 border border-rose-500">
                                        Preset Period
                                    </button>
                                    <button type="button" id="method-custom" onclick="setPurgeMethod('custom')"
                                        class="py-1 px-2 rounded-md text-[8px] font-black uppercase tracking-wider transition-all text-slate-400 hover:text-white hover:bg-white/5 border border-transparent">
                                        Custom Dates
                                    </button>
                                </div>
                            </div>

                            <!-- Preset dropdown -->
                            <div id="purge-preset-group">
                                <label
                                    class="block text-[8px] font-black uppercase tracking-widest text-slate-500 mb-1">Select
                                    Preset Range</label>
                                <select id="purge-preset-select" name="preset_period"
                                    class="w-full input-dark rounded-lg px-2.5 py-1.5 text-xs transition-all cursor-pointer">
                                    <optgroup label="Rolling Periods">
                                        <option value="30">Older than 30 Days</option>
                                        <option value="60">Older than 60 Days</option>
                                        <option value="90">Older than 90 Days</option>
                                        <option value="180">Older than 180 Days</option>
                                        <option value="365" selected>Older than 1 Year</option>
                                        <option value="730">Older than 2 Years</option>
                                    </optgroup>
                                    <optgroup label="By Calendar Year (has records)">
                                        <?php if (empty($purge_years)): ?>
                                            <option disabled value="">No records found</option>
                                        <?php else: ?>
                                            <?php foreach ($purge_years as $yr): ?>
                                                <option value="year:<?= (int) $yr ?>"><?= (int) $yr ?></option>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </optgroup>
                                </select>
                            </div>

                            <!-- Custom date inputs — hidden via inline style to avoid Tailwind hidden/grid conflict -->
                            <div id="purge-custom-group" style="display:none" class="grid grid-cols-2 gap-2">
                                <div>
                                    <label
                                        class="block text-[8px] font-black uppercase tracking-widest text-slate-500 mb-1">Start
                                        Date</label>
                                    <input type="date" id="purge-custom-start" name="custom_start"
                                        class="w-full input-dark rounded-lg px-2 py-1 text-xs transition-all"
                                        onchange="fetchPurgePreview()">
                                </div>
                                <div>
                                    <label
                                        class="block text-[8px] font-black uppercase tracking-widest text-slate-500 mb-1">End
                                        Date</label>
                                    <input type="date" id="purge-custom-end" name="custom_end"
                                        class="w-full input-dark rounded-lg px-2 py-1 text-xs transition-all"
                                        onchange="fetchPurgePreview()">
                                </div>
                            </div>

                            <!-- Live preview: shows count + date range before delete -->
                            <div id="purge-preview-box"
                                class="rounded-xl border border-rose-500/20 bg-rose-500/5 px-3 py-2 text-[10px] font-black text-rose-400 hidden">
                                <span id="purge-preview-text"></span>
                            </div>

                            <button type="submit"
                                class="w-full bg-gradient-to-r from-red-600 to-rose-500 hover:from-red-700 hover:to-rose-600 text-white py-2 rounded-xl font-black text-[9px] uppercase tracking-[0.25em] shadow-lg shadow-rose-950/20 transition-all hover:scale-[1.01]">
                                🚨 Purge Selected Records
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- Image Zoom Modal Overlay -->
    <div id="image-zoom-overlay"
        class="fixed inset-0 bg-slate-950/90 backdrop-blur-xl z-[900] hidden items-center justify-center p-4 cursor-pointer"
        onclick="closeZoomedImage()">
        <button class="absolute top-8 right-8 text-slate-400 hover:text-white text-3xl font-bold">×</button>
        <img id="image-zoom-img"
            class="max-w-full max-h-[90vh] object-contain rounded-3xl shadow-2xl transition-all duration-300 border border-white/10"
            src="">
    </div>

    <script>
        function toggleFab() {
            const fab = document.querySelector('.staff-fab');
            const svg = document.getElementById('fab-toggle-svg');
            const tooltip = document.getElementById('fab-toggle-tooltip');
            if (!fab || !svg) return;
            const isCollapsed = fab.classList.toggle('collapsed');
            localStorage.setItem('staff_fab_collapsed', isCollapsed ? 'true' : 'false');
            if (isCollapsed) {
                svg.classList.add('rotate-180');
                if (tooltip) tooltip.textContent = 'Expand Menu';
            } else {
                svg.classList.remove('rotate-180');
                if (tooltip) tooltip.textContent = 'Collapse Menu';
            }
        }

        // Initialize FAB state on load
        document.addEventListener('DOMContentLoaded', () => {
            const isCollapsed = localStorage.getItem('staff_fab_collapsed') === 'true';
            if (isCollapsed) {
                const fab = document.querySelector('.staff-fab');
                const svg = document.getElementById('fab-toggle-svg');
                const tooltip = document.getElementById('fab-toggle-tooltip');
                if (fab) fab.classList.add('collapsed');
                if (svg) svg.classList.add('rotate-180');
                if (tooltip) tooltip.textContent = 'Expand Menu';
            }
        });

        function toggleModal(id) {
            const modal = document.getElementById(id);
            modal.classList.toggle('hidden');
            modal.classList.toggle('flex');
        }

        const defaultIdTag = <?= json_encode($auto_tag) ?>;
        const defaultPassportTag = <?= json_encode($auto_passport_tag) ?>;

        function togglePassportTag(isChecked) {
            const tagInput = document.querySelector('input[name="tag_no"]');
            const checkbox = document.getElementById('is_id_passport');
            if (checkbox) {
                checkbox.checked = isChecked;
            }
            if (tagInput) {
                const currentVal = tagInput.value.trim().toUpperCase();
                if (currentVal === defaultIdTag || currentVal === defaultPassportTag || currentVal === '' || currentVal === 'ID-' || currentVal === 'PP-') {
                    tagInput.value = isChecked ? defaultPassportTag : defaultIdTag;
                } else {
                    if (isChecked && !currentVal.startsWith('PP-')) {
                        tagInput.value = 'PP-' + currentVal.replace(/^ID-?/, '');
                    } else if (!isChecked && currentVal.startsWith('PP-')) {
                        tagInput.value = 'ID-' + currentVal.replace(/^PP-?/, '');
                    }
                }
            }
        }

        // Synchronize manual typing in Tag Number to auto-toggle the ID/Passport checkbox
        document.addEventListener('DOMContentLoaded', () => {
            const tagInput = document.querySelector('input[name="tag_no"]');
            const checkbox = document.getElementById('is_id_passport');
            if (tagInput && checkbox) {
                tagInput.addEventListener('input', () => {
                    const val = tagInput.value.trim().toUpperCase();
                    if (val.startsWith('PP-')) {
                        checkbox.checked = true;
                    } else if (val.startsWith('ID-')) {
                        checkbox.checked = false;
                    }
                });
            }
        });

        function togglePurgeSection() {
            const body = document.getElementById('purge-body');
            const chevron = document.getElementById('purge-chevron');
            const isOpen = body.style.display !== 'none';
            body.style.display = isOpen ? 'none' : 'block';
            chevron.style.transform = isOpen ? '' : 'rotate(90deg)';
        }

        function toggleSettingsSection(bodyId, chevronId) {
            const body = document.getElementById(bodyId);
            const chevron = document.getElementById(chevronId);
            if (!body || !chevron) return;
            const isOpen = body.style.display !== 'none';
            body.style.display = isOpen ? 'none' : 'block';
            chevron.style.transform = isOpen ? '' : 'rotate(90deg)';
        }

        function setPurgeMethod(method) {
            const presetBtn = document.getElementById('method-preset');
            const customBtn = document.getElementById('method-custom');
            const presetGroup = document.getElementById('purge-preset-group');
            const customGroup = document.getElementById('purge-custom-group');
            const hiddenType = document.getElementById('purge-range-type');

            hiddenType.value = method;

            const activeClass = 'py-1 px-2 rounded-md text-[8px] font-black uppercase tracking-wider transition-all bg-rose-500 text-white shadow-lg shadow-rose-500/20 border border-rose-500';
            const inactiveClass = 'py-1 px-2 rounded-md text-[8px] font-black uppercase tracking-wider transition-all text-slate-400 hover:text-white hover:bg-white/5 border border-transparent';

            if (method === 'preset') {
                presetBtn.className = activeClass;
                customBtn.className = inactiveClass;
                presetGroup.style.display = '';
                customGroup.style.display = 'none';
            } else {
                customBtn.className = activeClass;
                presetBtn.className = inactiveClass;
                presetGroup.style.display = 'none';
                customGroup.style.display = '';
            }
            fetchPurgePreview();
        }

        // Attach change listener to the preset select
        document.addEventListener('DOMContentLoaded', function () {
            const sel = document.getElementById('purge-preset-select');
            if (sel) sel.addEventListener('change', fetchPurgePreview);
        });

        let _purgePreviewTimer = null;
        function fetchPurgePreview() {
            clearTimeout(_purgePreviewTimer);
            _purgePreviewTimer = setTimeout(_doPurgePreview, 250);
        }

        function _doPurgePreview() {
            const method = document.getElementById('purge-range-type').value;
            const box = document.getElementById('purge-preview-box');
            const txt = document.getElementById('purge-preview-text');
            if (!box || !txt) return;

            let params = 'purge_preview=1&range_type=' + encodeURIComponent(method);
            if (method === 'preset') {
                const val = document.getElementById('purge-preset-select').value;
                params += '&preset_period=' + encodeURIComponent(val);
            } else {
                const s = document.getElementById('purge-custom-start').value;
                const e = document.getElementById('purge-custom-end').value;
                if (!s || !e) { box.classList.add('hidden'); return; }
                params += '&custom_start=' + encodeURIComponent(s) + '&custom_end=' + encodeURIComponent(e);
            }

            fetch('staff.php?' + params)
                .then(r => r.json())
                .then(data => {
                    box.classList.remove('hidden');
                    if (data.count === 0) {
                        box.className = box.className.replace(/text-rose-\d+/, 'text-slate-400');
                        txt.textContent = '✓ No records found in this range — nothing to purge.';
                    } else {
                        box.className = box.className.replace(/text-slate-\d+/, 'text-rose-400');
                        txt.textContent = `⚠ ${data.count.toLocaleString()} record${data.count !== 1 ? 's' : ''} will be permanently deleted (${data.from} → ${data.to})`;
                    }
                })
                .catch(() => box.classList.add('hidden'));
        }

        function confirmPurge() {
            const method = document.getElementById('purge-range-type').value;
            let msg = '';
            if (method === 'preset') {
                const selectEl = document.getElementById('purge-preset-select');
                const val = selectEl.value;
                const label = selectEl.options[selectEl.selectedIndex].text;
                msg = `Are you absolutely sure you want to permanently delete all records matching: "${label}"? This action cannot be undone!`;
            } else {
                const start = document.getElementById('purge-custom-start').value;
                const end = document.getElementById('purge-custom-end').value;
                if (!start || !end) {
                    alert('Please select both start and end dates.');
                    return false;
                }
                msg = `Are you absolutely sure you want to permanently delete all records between ${start} and ${end}? This action cannot be undone!`;
            }
            return confirm(msg);
        }

        function preparePurgeForm(event) {
            if (!confirmPurge()) {
                event.preventDefault();
                return false;
            }
            return true;
        }

        function zoomImage(src) {
            const overlay = document.getElementById('image-zoom-overlay');
            const img = document.getElementById('image-zoom-img');
            img.src = src;
            overlay.classList.remove('hidden');
            overlay.classList.add('flex');
        }

        function closeZoomedImage() {
            const overlay = document.getElementById('image-zoom-overlay');
            overlay.classList.remove('flex');
            overlay.classList.add('hidden');
        }

        function prefillIonos() {
            document.getElementsByName('email_delivery_method')[0].value = 'smtp';
            document.getElementsByName('smtp_host')[0].value = 'smtp.ionos.com';
            document.getElementsByName('smtp_port')[0].value = '587';
            document.getElementsByName('smtp_encryption')[0].value = 'tls';
            document.getElementsByName('smtp_user')[0].focus();
        }

        function toggleRowExpansion(tr, event) {
            if (window.innerWidth > 1024) return;
            if (event.target.closest('input, select, button, label, form, a, svg, img')) {
                return;
            }
            tr.classList.toggle('is-expanded');
        }

        function saveRow(tagNo) {
            const form = document.getElementById('form-' + tagNo);
            if (!form) return;
            const row = form.closest('tr');
            if (!row) return;

            // Find all input/select elements associated with this form
            const inputs = row.querySelectorAll(`[form="form-${tagNo}"]`);
            inputs.forEach(input => {
                if (input.type === 'file') {
                    // Only move the file input if a file has been selected
                    if (input.files && input.files.length > 0) {
                        form.appendChild(input);
                    }
                } else {
                    let hidden = form.querySelector(`[name="${input.name}"]`);
                    if (!hidden) {
                        hidden = document.createElement('input');
                        hidden.type = 'hidden';
                        hidden.name = input.name;
                        form.appendChild(hidden);
                    }
                    hidden.value = input.value;
                }
            });
            form.submit();
        }

        // Custom premium stacked Toast Manager
        class ToastManager {
            constructor() {
                let container = document.getElementById('global-toast-container');
                if (!container) {
                    container = document.createElement('div');
                    container.id = 'global-toast-container';
                    container.className = 'toast-container';
                    document.body.appendChild(container);
                }
                this.container = container;
            }

            show(message, type = 'success', duration = 4000) {
                const card = document.createElement('div');

                let iconHtml = '';
                let titleText = 'Notification';
                let typeClass = '';
                let progressBg = '';
                let glowShadow = '';

                if (type === 'success') {
                    typeClass = 'border-emerald-500/30 text-emerald-400';
                    progressBg = 'bg-emerald-500';
                    glowShadow = 'shadow-2xl shadow-emerald-500/10';
                    titleText = 'Success';
                    iconHtml = `
                        <div class="w-8 h-8 rounded-full bg-emerald-500/10 flex items-center justify-center text-emerald-400 shrink-0">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="3" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"></path></svg>
                        </div>
                    `;
                } else if (type === 'error') {
                    typeClass = 'border-rose-500/30 text-rose-400';
                    progressBg = 'bg-rose-500';
                    glowShadow = 'shadow-2xl shadow-rose-500/10';
                    titleText = 'Error';
                    iconHtml = `
                        <div class="w-8 h-8 rounded-full bg-rose-500/10 flex items-center justify-center text-rose-400 shrink-0">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="3" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"></path></svg>
                        </div>
                    `;
                } else if (type === 'warning') {
                    typeClass = 'border-amber-500/30 text-amber-400';
                    progressBg = 'bg-amber-500';
                    glowShadow = 'shadow-2xl shadow-amber-500/10';
                    titleText = 'Warning';
                    iconHtml = `
                        <div class="w-8 h-8 rounded-full bg-amber-500/10 flex items-center justify-center text-amber-400 shrink-0">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path></svg>
                        </div>
                    `;
                } else {
                    typeClass = 'border-blue-500/30 text-blue-400';
                    progressBg = 'bg-blue-500';
                    glowShadow = 'shadow-2xl shadow-blue-500/10';
                    titleText = 'Information';
                    iconHtml = `
                        <div class="w-8 h-8 rounded-full bg-blue-500/10 flex items-center justify-center text-blue-400 shrink-0">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                        </div>
                    `;
                }

                card.className = `toast-card ${typeClass} ${glowShadow}`;

                card.innerHTML = `
                    ${iconHtml}
                    <div class="flex flex-col flex-grow min-w-0 pr-4">
                        <span class="text-[10px] font-black uppercase tracking-widest opacity-80 leading-tight">${titleText}</span>
                        <span class="text-xs font-semibold text-slate-100 mt-1 leading-relaxed break-words">${message}</span>
                    </div>
                    <button class="text-slate-400 hover:text-slate-200 transition-colors text-base font-bold leading-none shrink-0 self-center">&times;</button>
                    <div class="toast-progress ${progressBg}" style="animation-duration: ${duration}ms;"></div>
                `;

                const closeBtn = card.querySelector('button');
                closeBtn.onclick = () => this.remove(card);

                this.container.appendChild(card);

                setTimeout(() => card.classList.add('show'), 50);

                const timeoutId = setTimeout(() => this.remove(card), duration);
                card.dataset.timeoutId = timeoutId;
            }

            confirm(message, onConfirm, onCancel = null, options = {}) {
                const card = document.createElement('div');

                const intent = options.intent || 'danger';
                const titleText = options.title || 'Confirm Action';
                const confirmText = options.confirmText || 'Confirm';
                const isDanger = intent === 'danger';
                const accent = isDanger
                    ? {
                        typeClass: 'border-rose-500/30 text-rose-400',
                        glowShadow: 'shadow-2xl shadow-rose-500/20',
                        iconClass: 'bg-rose-500/10 text-rose-400',
                        buttonClass: 'bg-rose-500 hover:bg-rose-600 shadow-rose-500/20'
                    }
                    : {
                        typeClass: 'border-emerald-500/30 text-emerald-400',
                        glowShadow: 'shadow-2xl shadow-emerald-500/20',
                        iconClass: 'bg-emerald-500/10 text-emerald-400',
                        buttonClass: 'bg-emerald-500 hover:bg-emerald-600 shadow-emerald-500/20'
                    };
                let typeClass = accent.typeClass;
                let glowShadow = accent.glowShadow;
                let iconHtml = `
                    <div class="w-8 h-8 rounded-full ${accent.iconClass} flex items-center justify-center shrink-0">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path></svg>
                    </div>
                `;

                card.className = `toast-card ${typeClass} ${glowShadow} !max-w-[450px]`;

                card.innerHTML = `
                    ${iconHtml}
                    <div class="flex flex-col flex-grow min-w-0 pr-4">
                        <span class="text-[10px] font-black uppercase tracking-widest opacity-80 leading-tight">${titleText}</span>
                        <span class="text-xs font-semibold text-slate-100 mt-1 leading-relaxed break-words">${message}</span>
                        <div class="flex items-center gap-2 mt-3">
                            <button class="confirm-btn px-3 py-1.5 ${accent.buttonClass} text-white rounded-lg text-[9px] font-black uppercase tracking-widest transition-all shadow-md hover:scale-[1.03] active:scale-[0.98]">${confirmText}</button>
                            <button class="cancel-btn px-3 py-1.5 bg-slate-200 hover:bg-slate-300 text-slate-700 dark:bg-slate-800 dark:hover:bg-slate-700 dark:text-slate-300 rounded-lg text-[9px] font-black uppercase tracking-widest transition-all hover:scale-[1.03] active:scale-[0.98]">Cancel</button>
                        </div>
                    </div>
                    <button class="close-btn text-slate-400 hover:text-slate-200 transition-colors text-base font-bold leading-none shrink-0 self-start">&times;</button>
                `;

                const confirmBtn = card.querySelector('.confirm-btn');
                const cancelBtn = card.querySelector('.cancel-btn');
                const closeBtn = card.querySelector('.close-btn');

                confirmBtn.onclick = () => {
                    this.remove(card);
                    if (onConfirm) onConfirm();
                };

                cancelBtn.onclick = () => {
                    this.remove(card);
                    if (onCancel) onCancel();
                };

                closeBtn.onclick = () => {
                    this.remove(card);
                    if (onCancel) onCancel();
                };

                this.container.appendChild(card);
                setTimeout(() => card.classList.add('show'), 50);
            }

            remove(card) {
                if (!card.parentNode) return;
                clearTimeout(card.dataset.timeoutId);
                card.classList.remove('show');
                card.classList.add('hide');
                setTimeout(() => {
                    if (card.parentNode) {
                        card.remove();
                    }
                }, 400);
            }
        }

        window.toast = {
            show: (message, type = 'success', duration = 4000) => {
                if (!window.toastManager) {
                    window.toastManager = new ToastManager();
                }
                window.toastManager.show(message, type, duration);
            },
            success: (message, duration = 4000) => window.toast.show(message, 'success', duration),
            error: (message, duration = 4000) => window.toast.show(message, 'error', duration),
            warning: (message, duration = 4000) => window.toast.show(message, 'warning', duration),
            info: (message, duration = 4000) => window.toast.show(message, 'info', duration),
            confirm: (message, onConfirm, onCancel = null, options = {}) => {
                if (!window.toastManager) {
                    window.toastManager = new ToastManager();
                }
                window.toastManager.confirm(message, onConfirm, onCancel, options);
            }
        };
    </script>

    <div class="max-w-7xl mx-auto px-4 pb-12">
        <!-- Quick Stats Grid -->
        <div class="grid grid-cols-2 md:grid-cols-6 gap-3 mb-6">
            <div class="bg-[var(--card)] border border-[var(--border)] p-4 rounded-xl shadow-sm transition-colors">
                <p class="text-[8px] font-black uppercase tracking-widest text-[var(--secondary)] mb-1">Dashboard</p>
                <p class="text-xl font-black text-[var(--text)]"><?= $total ?></p>
            </div>
            <div
                class="bg-[var(--card)] border border-[var(--border)] p-4 rounded-xl shadow-sm border-l-4 border-l-rose-500 transition-colors">
                <p class="text-[8px] font-black uppercase tracking-widest text-rose-500 mb-1">Inventory</p>
                <p class="text-xl font-black text-[var(--text)]"><?= $found ?></p>
            </div>
            <div
                class="bg-[var(--card)] border border-[var(--border)] p-4 rounded-xl shadow-sm border-l-4 border-l-blue-500 transition-colors">
                <p class="text-[8px] font-black uppercase tracking-widest text-blue-500 mb-1">Lost Reports</p>
                <p class="text-xl font-black text-[var(--text)]"><?= $lost ?></p>
            </div>
            <div
                class="bg-[var(--card)] border border-[var(--border)] p-4 rounded-xl shadow-sm border-l-4 border-l-emerald-500 transition-colors">
                <p class="text-[8px] font-black uppercase tracking-widest text-emerald-500 mb-1">Delivered</p>
                <p class="text-xl font-black text-[var(--text)]"><?= $delivered ?></p>
            </div>
            <div
                class="bg-[var(--card)] border border-[var(--border)] p-4 rounded-xl shadow-sm border-l-4 border-l-purple-500 transition-colors">
                <p class="text-[8px] font-black uppercase tracking-widest text-purple-500 mb-1">ID & Passports</p>
                <p class="text-xl font-black text-[var(--text)]"><?= $id_passports ?></p>
            </div>
            <div
                class="bg-[var(--card)] border border-[var(--border)] p-4 rounded-xl shadow-sm border-l-4 border-l-amber-500 transition-colors">
                <p class="text-[8px] font-black uppercase tracking-widest text-amber-500 mb-1">Pending Approval</p>
                <p class="text-xl font-black text-[var(--text)]"><?= (int) $pending_report_count ?></p>
            </div>
        </div>

        <?php if ($status_f === 'Pending' || !empty($pending_reports)): ?>
            <div
                class="bg-[var(--card)] border border-[var(--border)] rounded-xl shadow-sm transition-colors overflow-hidden mb-4">
                <div
                    class="px-4 py-3 bg-amber-500/10 border-b border-[var(--border)] flex items-center justify-between gap-3">
                    <div>
                        <p class="text-[9px] font-black uppercase tracking-widest text-amber-500">Passenger Reports Awaiting
                            Approval</p>
                        <p class="text-[11px] font-bold text-[var(--secondary)]">Approve to add as Lost, or mark as already
                            logged if staff created the record first.</p>
                    </div>
                    <?php if ($status_f !== 'Pending'): ?>
                        <a href="?status_filter=Pending"
                            class="px-3 py-1.5 bg-amber-500 text-white rounded-lg text-[9px] font-black uppercase tracking-widest">Review</a>
                    <?php endif; ?>
                </div>
                <div class="overflow-x-auto">
                    <table class="staff-pending-table w-full text-left table-fixed min-w-[980px]">
                        <thead class="bg-[var(--bg)] border-b border-[var(--border)]">
                            <tr class="text-[8px] font-black uppercase tracking-widest text-[var(--secondary)]">
                                <th class="px-3 py-2 w-[18%]">Reference</th>
                                <th class="px-3 py-2 w-[24%]">Item</th>
                                <th class="px-3 py-2 w-[14%]">Flight</th>
                                <th class="px-3 py-2 w-[18%]">Passenger</th>
                                <th class="px-3 py-2 w-[26%] text-right">Decision</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-[var(--border)]">
                            <?php if (empty($pending_reports)): ?>
                                <tr class="pending-empty-row">
                                    <td colspan="5" class="px-4 py-8 text-center">
                                        <p class="text-[10px] font-black uppercase tracking-widest text-[var(--secondary)]">No
                                            pending passenger reports</p>
                                        <p class="text-[11px] font-bold text-[var(--secondary)] mt-1">New passenger lost reports
                                            will appear here before they are added to Lost Reports.</p>
                                    </td>
                                </tr>
                            <?php endif; ?>
                            <?php foreach ($pending_reports as $report): ?>
                                <?php $pending_photo = $report['photo'] ? 'uploads/cabin_items/' . $report['photo'] : ''; ?>
                                <tr class="pending-report-row hover:bg-amber-500/5 cursor-pointer lg:cursor-default"
                                    onclick="toggleRowExpansion(this, event)">
                                    <td class="px-3 py-2" data-label="Reference">
                                        <p class="text-[10px] font-black text-amber-500 uppercase">
                                            <?= af_h($report['tag_no'] ?: $report['report_ref']) ?>
                                        </p>
                                        <p class="text-[8px] font-bold text-[var(--secondary)] uppercase">
                                            <?= af_h(date('d M Y H:i', strtotime($report['created_at'] ?? 'now'))) ?>
                                        </p>
                                    </td>
                                    <td class="px-3 py-2" data-label="Item">
                                        <div class="flex items-center gap-2">
                                            <?php if ($pending_photo): ?>
                                                <img src="<?= af_h($pending_photo) ?>" onclick="zoomImage(this.src)"
                                                    class="w-9 h-9 rounded-md object-cover border border-[var(--border)] cursor-pointer">
                                            <?php endif; ?>
                                            <p class="text-[10px] font-bold text-[var(--text)] leading-snug">
                                                <span class="pending-mobile-meta text-[7px] font-black text-amber-500 uppercase tracking-widest mb-0.5">
                                                    <?= af_h($report['tag_no'] ?: $report['report_ref']) ?>
                                                </span>
                                                <?= af_h($report['item_description']) ?>
                                                <span class="pending-mobile-meta text-[7px] font-black text-[var(--secondary)] uppercase mt-0.5">
                                                    <?= af_h(date('d M Y H:i', strtotime($report['created_at'] ?? 'now'))) ?>
                                                </span>
                                            </p>
                                        </div>
                                    </td>
                                    <td class="px-3 py-2" data-label="Flight">
                                        <p class="text-[10px] font-black text-[var(--text)] uppercase">
                                            <?= af_h(trim(($report['airline'] ?? '') . ' ' . ($report['flight_number'] ?? ''))) ?>
                                        </p>
                                        <p class="text-[8px] font-bold text-[var(--secondary)] uppercase">
                                            <?= af_h($report['seat_info'] ?? '') ?>
                                        </p>
                                    </td>
                                    <td class="px-3 py-2" data-label="Passenger">
                                        <p class="text-[10px] font-bold text-[var(--text)]"><?= af_h($report['pax_name']) ?></p>
                                        <p class="text-[8px] font-bold text-[var(--secondary)] truncate">
                                            <?= af_h($report['pax_email']) ?>
                                        </p>
                                    </td>
                                    <td class="px-3 py-2" data-label="Decision">
                                        <div class="flex flex-col gap-2 items-end">
                                            <form method="POST"
                                                onsubmit="event.preventDefault(); const form=this; toast.confirm('Approve <?= af_h($report['tag_no'] ?: $report['report_ref']) ?> and add it to Lost reports?',()=>form.submit(),null,{title:'Approve Report',confirmText:'Approve',intent:'success'});"
                                                class="inline-flex justify-end">
                                                <input type="hidden" name="action" value="approve_pending_report">
                                                <input type="hidden" name="report_id" value="<?= (int) $report['id'] ?>">
                                                <button type="submit"
                                                    class="px-3 py-1.5 bg-emerald-600 hover:bg-emerald-700 text-white rounded-lg text-[8px] font-black uppercase tracking-widest">Approve</button>
                                            </form>
                                            <form method="POST"
                                                onsubmit="event.preventDefault(); if (!this.reportValidity()) return; const form=this; toast.confirm('Mark this passenger report as already logged?',()=>form.submit(),null,{title:'Already Logged',confirmText:'Mark Logged',intent:'success'});"
                                                class="flex items-center justify-end gap-1.5">
                                                <input type="hidden" name="action" value="duplicate_pending_report">
                                                <input type="hidden" name="report_id" value="<?= (int) $report['id'] ?>">
                                                <input type="text" name="existing_tag_no" placeholder="Existing ID" required
                                                    class="w-24 bg-[var(--bg)] border border-[var(--border)] rounded-lg px-2 py-1 text-[9px] text-[var(--text)] uppercase outline-none">
                                                <button type="submit"
                                                    class="px-3 py-1.5 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-[8px] font-black uppercase tracking-widest">Logged</button>
                                            </form>
                                            <form method="POST"
                                                onsubmit="event.preventDefault(); if (!this.reportValidity()) return; const form=this; toast.confirm('Reject this pending report?',()=>form.submit(),null,{title:'Reject Report',confirmText:'Reject',intent:'danger'});"
                                                class="flex items-center justify-end gap-1.5">
                                                <input type="hidden" name="action" value="reject_pending_report">
                                                <input type="hidden" name="report_id" value="<?= (int) $report['id'] ?>">
                                                <input type="text" name="staff_notes" placeholder="Reason" required
                                                    class="w-32 bg-[var(--bg)] border border-[var(--border)] rounded-lg px-2 py-1 text-[9px] text-[var(--text)] outline-none">
                                                <button type="submit"
                                                    class="px-3 py-1.5 bg-red-600 hover:bg-red-700 text-white rounded-lg text-[8px] font-black uppercase tracking-widest">Reject</button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>

        <!-- Inventory Control Bar -->
        <?php if ($status_f !== 'Pending'): ?>
            <details
                class="filter-panel bg-[var(--card)] border border-[var(--border)] rounded-xl shadow-sm p-3 mb-4 transition-colors"
                <?= ($search || $start_date || $end_date || $filter_year || $filter_month || $status_f || $airline_filter) ? 'open' : '' ?>>
                <summary class="flex cursor-pointer items-center justify-between gap-3">
                    <div>
                        <p class="text-[9px] font-black uppercase tracking-widest text-[var(--secondary)]">Filters</p>
                        <p class="text-[11px] font-bold text-[var(--text)]">Search, dates, month, and status</p>
                    </div>
                    <span class="filter-chevron text-[var(--secondary)] text-lg leading-none">⌄</span>
                </summary>
                <form method="GET" class="mt-3 flex flex-col lg:flex-row gap-3 items-center">
                    <input type="hidden" name="status_filter" value="<?= htmlspecialchars($status_f) ?>">

                    <div class="relative flex-grow w-full lg:w-auto">
                        <span class="absolute left-4 top-1/2 -translate-y-1/2 text-[var(--secondary)]">🔍</span>
                        <input type="text" name="search" value="<?= htmlspecialchars($search) ?>"
                            placeholder="Search description, tag, or passenger..."
                            class="w-full pl-10 pr-4 py-2 bg-[var(--bg)] border border-[var(--border)] rounded-lg text-xs focus:border-rose-500/50 transition-all outline-none text-[var(--text)]">
                    </div>

                    <!-- Date Range, Year, Month Filter Group -->
                    <div class="flex flex-wrap items-center gap-3 w-full lg:w-auto">
                        <div class="flex items-center gap-1.5">
                            <span
                                class="text-[8px] font-black uppercase tracking-widest text-[var(--secondary)] whitespace-nowrap">From:</span>
                            <input type="<?= $start_date ? 'date' : 'text' ?>" name="start_date"
                                value="<?= htmlspecialchars($start_date) ?>" placeholder="From" data-placeholder="From"
                                class="bg-[var(--bg)] border border-[var(--border)] rounded-lg px-2.5 py-1.5 text-[10px] text-[var(--text)] transition-all outline-none">
                        </div>
                        <div class="flex items-center gap-1.5">
                            <span
                                class="text-[8px] font-black uppercase tracking-widest text-[var(--secondary)] whitespace-nowrap">To:</span>
                            <input type="<?= $end_date ? 'date' : 'text' ?>" name="end_date"
                                value="<?= htmlspecialchars($end_date) ?>" placeholder="To" data-placeholder="To"
                                class="bg-[var(--bg)] border border-[var(--border)] rounded-lg px-2.5 py-1.5 text-[10px] text-[var(--text)] transition-all outline-none">
                        </div>
                        <select name="filter_year"
                            class="bg-[var(--bg)] border border-[var(--border)] rounded-lg px-2.5 py-1.5 text-[10px] text-[var(--text)] transition-all outline-none font-bold">
                            <option value="">Year: All</option>
                            <?php
                            $years = $pdo->query("SELECT DISTINCT strftime('%Y', created_at) as yr FROM items WHERE created_at IS NOT NULL ORDER BY yr DESC")->fetchAll(PDO::FETCH_COLUMN);
                            foreach ($years as $yr):
                                if (!$yr)
                                    continue; ?>
                                <option value="<?= $yr ?>" <?= $filter_year === $yr ? 'selected' : '' ?>><?= $yr ?></option>
                            <?php endforeach; ?>
                        </select>
                        <select name="filter_month"
                            class="bg-[var(--bg)] border border-[var(--border)] rounded-lg px-2.5 py-1.5 text-[10px] text-[var(--text)] transition-all outline-none font-bold">
                            <option value="">Month: All</option>
                            <?php
                            $months = [
                                '01' => 'Jan',
                                '02' => 'Feb',
                                '03' => 'Mar',
                                '04' => 'Apr',
                                '05' => 'May',
                                '06' => 'Jun',
                                '07' => 'Jul',
                                '08' => 'Aug',
                                '09' => 'Sep',
                                '10' => 'Oct',
                                '11' => 'Nov',
                                '12' => 'Dec'
                            ];
                            foreach ($months as $num => $lbl): ?>
                                <option value="<?= $num ?>" <?= $filter_month === $num ? 'selected' : '' ?>><?= $lbl ?></option>
                            <?php endforeach; ?>
                        </select>
                        <select name="airline_filter"
                            class="bg-[var(--bg)] border border-[var(--border)] rounded-lg px-2.5 py-1.5 text-[10px] text-[var(--text)] transition-all outline-none font-bold">
                            <option value="">Airline: All</option>
                            <?php foreach ($al as $airline): ?>
                                <option value="<?= $airline['id'] ?>" <?= (string) $airline_filter === (string) $airline['id'] ? 'selected' : '' ?>><?= af_h($airline['name']) ?> (<?= af_h($airline['code']) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="flex items-center gap-2 w-full lg:w-auto shrink-0">
                        <a href="?status_filter=Found"
                            class="flex-grow lg:flex-none px-4 py-2 rounded-lg text-[9px] font-black uppercase tracking-widest border transition-all <?= ($status_f == 'Found' || $status_f == '') ? 'bg-rose-500 text-white border-rose-500 shadow-lg shadow-rose-500/20' : 'bg-[var(--bg)] border-[var(--border)] text-[var(--secondary)]' ?>">Found</a>
                        <a href="?status_filter=Lost"
                            class="flex-grow lg:flex-none px-4 py-2 rounded-lg text-[9px] font-black uppercase tracking-widest border transition-all <?= $status_f == 'Lost' ? 'bg-blue-500 text-white border-blue-500 shadow-lg shadow-blue-500/20' : 'bg-[var(--bg)] border-[var(--border)] text-[var(--secondary)]' ?>">Lost</a>
                        <a href="?status_filter=Delivered"
                            class="flex-grow lg:flex-none px-4 py-2 rounded-lg text-[9px] font-black uppercase tracking-widest border transition-all <?= $status_f == 'Delivered' ? 'bg-emerald-500 text-white border-emerald-500 shadow-lg shadow-emerald-500/20' : 'bg-[var(--bg)] border-[var(--border)] text-[var(--secondary)]' ?>">Done</a>
                    </div>
                    <button type="submit"
                        class="w-full lg:w-auto px-6 py-2 bg-rose-500 text-white rounded-lg text-[9px] font-black uppercase tracking-widest hover:scale-105 transition-all shrink-0">Filter</button>
                </form>
            </details>

            <!-- Inventory List -->
            <div
                class="bg-[var(--card)] border border-[var(--border)] rounded-xl shadow-sm transition-colors overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="staff-inventory-table w-full text-left table-fixed min-w-[1100px]">
                        <thead class="bg-[var(--bg)] border-b border-[var(--border)]">
                            <tr class="text-[8px] font-black uppercase tracking-widest text-[var(--secondary)]">
                                <th class="px-2 py-1.5 w-[20%]">Item Details</th>
                                <th class="px-2 py-1.5 w-[10%]">Flight</th>
                                <th class="px-2 py-1.5 w-[8%]">Seat</th>
                                <th class="px-2 py-1.5 w-[12%]">Passenger</th>
                                <th class="px-2 py-1.5 w-[18%]">Internal Comments</th>
                                <th class="px-2 py-1.5 w-[17%]">Handover Notes</th>
                                <th class="px-2 py-1.5 w-[8%] text-center">Status</th>
                                <th class="px-2 py-1.5 w-[7%] text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-[var(--border)]">
                            <?php
                            $current_week = null;
                            foreach ($items as $item):
                                $date_time = strtotime($item['created_at'] ?? 'now');
                                $item_week = date('W', $date_time);
                                $item_year = date('Y', $date_time);
                                $week_label = "Week " . $item_week . ", " . $item_year;

                                if ($current_week !== $week_label) {
                                    $current_week = $week_label;
                                    ?>
                                    <tr class="week-row bg-rose-500/10 dark:bg-rose-500/15 border-y border-[var(--border)]">
                                        <td colspan="8"
                                            class="px-3 py-1.5 text-[9px] font-black uppercase tracking-[0.2em] text-rose-500">
                                            🗓️ <?= $week_label ?>
                                        </td>
                                    </tr>
                                    <?php
                                }
                                ?>
                                <?php
                                $is_lost = ($item['status'] === 'Lost');
                                $is_claimed = ($item['status'] === 'Claimed');
                                $is_delivered = ($item['status'] === 'Delivered');
                                $is_disposed = ($item['status'] === 'Disposed');
                                if ($is_lost) {
                                    $row_class = 'status-lost bg-slate-600/15 dark:bg-slate-500/15 hover:bg-slate-500/20 dark:hover:bg-slate-500/20 border-l-4 border-l-slate-500 transition-all duration-300';
                                } elseif ($is_claimed) {
                                    $row_class = 'status-claimed bg-indigo-600/15 dark:bg-indigo-500/15 hover:bg-indigo-500/20 dark:hover:bg-indigo-500/20 border-l-4 border-l-indigo-500 transition-all duration-300';
                                } elseif ($is_delivered) {
                                    $row_class = 'status-delivered bg-emerald-600/20 dark:bg-emerald-500/15 hover:bg-emerald-500/25 dark:hover:bg-emerald-500/20 border-l-4 border-l-emerald-500 transition-all duration-300';
                                } elseif ($is_disposed) {
                                    $row_class = 'status-disposed bg-amber-600/20 dark:bg-amber-500/15 hover:bg-amber-500/25 dark:hover:bg-amber-500/20 border-l-4 border-l-amber-500 transition-all duration-300';
                                } else {
                                    $row_class = 'hover:bg-rose-500/5 border-l-4 border-l-transparent transition-all duration-300';
                                }
                                ?>
                                <tr class="<?= $row_class ?> staff-inventory-row cursor-pointer lg:cursor-default"
                                    onclick="toggleRowExpansion(this, event)">
                                    <td class="px-2 py-1" data-label="Item">
                                        <!-- HTML5 Form linkage for inline updates -->
                                        <form id="form-<?= $item['tag_no'] ?>" method="POST" enctype="multipart/form-data">
                                            <input type="hidden" name="action" value="update_item">
                                            <input type="hidden" name="tag_no" value="<?= $item['tag_no'] ?>">
                                        </form>
                                        <div class="flex items-center gap-2">
                                            <!-- Photo Wrapper with Change Photo support -->
                                            <div
                                                class="item-photo-wrapper flex flex-col items-center gap-0.5 shrink-0 relative">
                                                <?php
                                                $img_path = $item['photo'] ? (strpos($item['photo'], 'http') === 0 ? $item['photo'] : 'uploads/cabin_items/' . $item['photo']) : null;
                                                if ($img_path): ?>
                                                    <img src="<?= $img_path ?>" onclick="zoomImage(this.src)"
                                                        class="w-8 h-8 rounded-md object-cover border border-[var(--border)] shadow-sm cursor-pointer hover:opacity-85 transition-opacity">
                                                <?php else: ?>
                                                    <div
                                                        class="w-8 h-8 rounded-md bg-[var(--bg)] border border-[var(--border)] flex items-center justify-center text-[10px] opacity-50">
                                                        <svg class="text-[var(--secondary)] w-3 h-3"
                                                            xmlns="http://www.w3.org/2000/svg" fill="none" stroke="currentColor"
                                                            viewBox="0 0 24 24" stroke-width="2">
                                                            <path stroke-linecap="round" stroke-linejoin="round"
                                                                d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" />
                                                        </svg>
                                                    </div>
                                                <?php endif; ?>
                                                <label
                                                    class="change-photo-label cursor-pointer text-[6px] font-black uppercase text-rose-500 hover:text-rose-600 transition-all tracking-wider text-center block mt-0.5">
                                                    Change
                                                    <input type="file" name="photo" accept="image/jpeg,image/png,image/webp"
                                                        form="form-<?= $item['tag_no'] ?>" class="hidden"
                                                        onchange="saveRow('<?= $item['tag_no'] ?>')">
                                                </label>
                                            </div>
                                            <div class="flex-grow min-w-0">
                                                <p
                                                    class="text-[7px] font-black text-rose-500 uppercase tracking-widest leading-none flex items-center gap-1.5 flex-wrap">
                                                    <span><?= $item['tag_no'] ?></span>
                                                    <?php if (!empty($item['other_info'])):
                                                        $display_flight = $item['other_info'];
                                                        if (preg_match('/^([A-Z0-9]{2,3}\s*\d{1,4})/i', trim($display_flight), $matches)) {
                                                            $display_flight = $matches[1];
                                                        } else {
                                                            $display_flight = trim(preg_replace('/\s*\(.*?\)/', '', $display_flight));
                                                        }
                                                        ?>
                                                        <span
                                                            class="mobile-flight-badge bg-rose-500/10 text-rose-500 px-1.5 py-0.5 rounded text-[6px] font-black font-mono tracking-tighter uppercase"><?= htmlspecialchars($display_flight) ?></span>
                                                    <?php endif; ?>
                                                </p>
                                                <input type="text" name="item_description"
                                                    value="<?= htmlspecialchars($item['item_description']) ?>"
                                                    form="form-<?= $item['tag_no'] ?>" required
                                                    class="bg-transparent border-b border-transparent focus:border-rose-500/50 hover:bg-slate-500/5 focus:bg-[var(--input)] text-[10px] font-bold text-[var(--text)] px-1 py-0.5 rounded w-full transition-all outline-none mt-0.5">
                                                <!-- Original Date exactly from database -->
                                                <p class="text-[7px] text-[var(--secondary)] uppercase font-black mt-0.5 px-1">
                                                    <?= date('d M Y', strtotime($item['created_at'] ?? 'now')) ?>
                                                </p>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-2 py-1" data-label="Flight">
                                        <input type="text" name="other_info"
                                            value="<?= htmlspecialchars($item['other_info'] ?? '') ?>"
                                            form="form-<?= $item['tag_no'] ?>" placeholder="e.g. BA204"
                                            class="bg-transparent border-b border-transparent focus:border-rose-500/50 hover:bg-slate-500/5 focus:bg-[var(--input)] text-[10px] font-black text-[var(--text)] uppercase px-1 py-0.5 rounded w-full transition-all outline-none">
                                    </td>
                                    <td class="px-2 py-1" data-label="Seat">
                                        <input type="text" name="comments"
                                            value="<?= htmlspecialchars($item['comments'] ?? '') ?>"
                                            form="form-<?= $item['tag_no'] ?>" placeholder="e.g. 24F"
                                            class="bg-transparent border-b border-transparent focus:border-rose-500/50 hover:bg-slate-500/5 focus:bg-[var(--input)] text-[10px] font-black text-[var(--text)] uppercase px-1 py-0.5 rounded w-full transition-all outline-none">
                                    </td>
                                    <td class="px-2 py-1" data-label="Passenger">
                                        <input type="text" name="pax_name"
                                            value="<?= htmlspecialchars($item['pax_name'] ?? '') ?>"
                                            form="form-<?= $item['tag_no'] ?>" placeholder="Pax Name"
                                            class="bg-transparent border-b border-transparent focus:border-rose-500/50 hover:bg-slate-500/5 focus:bg-[var(--input)] text-[10px] font-bold text-[var(--text)] px-1 py-0.5 rounded w-full transition-all outline-none">
                                    </td>
                                    <td class="px-2 py-1" data-label="Internal">
                                        <input type="text" name="user_comments"
                                            value="<?= htmlspecialchars($item['user_comments'] ?? '') ?>"
                                            form="form-<?= $item['tag_no'] ?>" placeholder="Add internal comment..."
                                            class="bg-transparent border-b border-transparent focus:border-rose-500/50 hover:bg-slate-500/5 focus:bg-[var(--input)] text-[10px] text-[var(--text)] px-1 py-0.5 rounded w-full transition-all outline-none">
                                    </td>
                                    <td class="px-2 py-1" data-label="Handover">
                                        <input type="text" name="delivery_info"
                                            value="<?= htmlspecialchars($item['delivery_info'] ?? '') ?>"
                                            form="form-<?= $item['tag_no'] ?>" placeholder="Add handover details..."
                                            class="bg-transparent border-b border-transparent focus:border-rose-500/50 hover:bg-slate-500/5 focus:bg-[var(--input)] text-[10px] text-[var(--text)] px-1 py-0.5 rounded w-full transition-all outline-none">
                                    </td>
                                    <td class="px-2 py-1 text-center" data-label="Status">
                                        <select name="status" form="form-<?= $item['tag_no'] ?>"
                                            onchange="saveRow('<?= $item['tag_no'] ?>')"
                                            class="bg-[var(--bg)] border border-[var(--border)] rounded px-1 py-0.5 text-[8px] font-black uppercase tracking-widest cursor-pointer hover:border-rose-500/50 transition-all 
                                        <?= $item['status'] == 'Found' ? 'text-rose-500' : ($item['status'] == 'Lost' ? 'text-blue-500' : ($item['status'] == 'Claimed' ? 'text-indigo-500' : ($item['status'] == 'Disposed' ? 'text-amber-500' : 'text-emerald-500'))) ?>">
                                            <option value="Found" <?= $item['status'] == 'Found' ? 'selected' : '' ?>>Found
                                            </option>
                                            <option value="Lost" <?= $item['status'] == 'Lost' ? 'selected' : '' ?>>Lost</option>
                                            <option value="Claimed" <?= $item['status'] == 'Claimed' ? 'selected' : '' ?>>Claimed
                                            </option>
                                            <option value="Delivered" <?= $item['status'] == 'Delivered' ? 'selected' : '' ?>>Done
                                            </option>
                                            <option value="Disposed" <?= $item['status'] == 'Disposed' ? 'selected' : '' ?>>
                                                Disposed</option>
                                        </select>
                                    </td>
                                    <td class="px-2 py-1 text-right" data-label="Actions">
                                        <div
                                            class="staff-row-actions <?= $item['status'] === 'Delivered' ? 'is-delivered' : '' ?> flex items-center justify-end gap-1.5">

                                            <form class="inline">
                                                <button type="button" onclick="saveRow('<?= $item['tag_no'] ?>')"
                                                    title="Save Record"
                                                    class="w-6 h-6 flex items-center justify-center bg-rose-500 hover:bg-rose-600 text-white rounded-md hover:scale-105 transition-all shadow-sm shadow-rose-500/10">
                                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.5"
                                                        viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round"
                                                            d="M8 4H6a2 2 0 00-2 2v12a2 2 0 002 2h12a2 2 0 002-2V8l-4-4H8zm0 0v4h6V4M8 20v-6h8v6" />
                                                    </svg>
                                                </button>
                                            </form>

                                            <?php if ($item['status'] !== 'Delivered'): ?>
                                                <form method="POST"
                                                    onsubmit="event.preventDefault(); const form=this; toast.confirm('Mark item <?= af_h($item['tag_no']) ?> as picked up and done?',()=>form.submit(),null,{title:'Confirm Pickup',confirmText:'Yes, Pick Up',intent:'success'});"
                                                    class="inline">
                                                    <input type="hidden" name="action" value="pickup_item">
                                                    <input type="hidden" name="tag_no" value="<?= $item['tag_no'] ?>">
                                                    <button type="submit" title="Picked Up"
                                                        class="w-6 h-6 flex items-center justify-center bg-emerald-600 hover:bg-emerald-700 text-white rounded-md hover:scale-105 transition-all shadow-sm shadow-emerald-600/10">
                                                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor"
                                                            stroke-width="2.5" viewBox="0 0 24 24">
                                                            <path stroke-linecap="round" stroke-linejoin="round"
                                                                d="M5 13l4 4L19 7" />
                                                        </svg>
                                                    </button>
                                                </form>
                                            <?php endif; ?>

                                            <form method="POST"
                                                onsubmit="event.preventDefault(); const form=this; toast.confirm('Are you sure you want to permanently delete item <?= af_h($item['tag_no']) ?>?',()=>form.submit(),null,{title:'Confirm Deletion',confirmText:'Yes, Delete',intent:'danger'});"
                                                class="inline">
                                                <input type="hidden" name="action" value="delete_item">
                                                <input type="hidden" name="tag_no" value="<?= $item['tag_no'] ?>">
                                                <button type="submit" title="Delete Record"
                                                    class="w-6 h-6 flex items-center justify-center bg-red-600 hover:bg-red-700 text-white rounded-md hover:scale-105 transition-all shadow-sm shadow-red-600/10">
                                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor"
                                                        stroke-width="2.5" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round"
                                                            d="M6 18L18 6M6 6l12 12" />
                                                    </svg>
                                                </button>
                                            </form>

                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <div class="px-4 py-3 bg-[var(--bg)] border-t border-[var(--border)] flex justify-between items-center">
                    <div class="flex items-center gap-3">
                        <p class="text-[9px] font-black uppercase tracking-widest text-[var(--secondary)]">
                            <?= $offset + count($items) ?>/<?= $total_matching ?> rows
                        </p>
                        <form method="POST" class="flex items-center">
                            <?= af_csrf_input() ?>
                            <input type="hidden" name="action" value="update_items_per_page">
                            <select name="items_per_page" onchange="this.form.submit()" class="bg-[var(--card)] border border-[var(--border)] rounded-md px-1.5 py-0.5 text-[9px] font-black uppercase text-[var(--secondary)] cursor-pointer hover:border-rose-500/50 transition-all select-none select-compact">
                                <option value="10" <?= $items_per_page == 10 ? 'selected' : '' ?>>10</option>
                                <option value="20" <?= $items_per_page == 20 ? 'selected' : '' ?>>20</option>
                                <option value="50" <?= $items_per_page == 50 ? 'selected' : '' ?>>50</option>
                                <option value="100" <?= $items_per_page == 100 ? 'selected' : '' ?>>100</option>
                                <option value="200" <?= $items_per_page == 200 ? 'selected' : '' ?>>200</option>
                            </select>
                        </form>
                    </div>
                    <div class="flex gap-1.5">
                        <?php if ($page > 1): ?>
                            <a href="?<?= http_build_query(['p' => $page - 1, 'search' => $search, 'status_filter' => $status_f, 'start_date' => $start_date, 'end_date' => $end_date, 'filter_year' => $filter_year, 'filter_month' => $filter_month, 'airline_filter' => $airline_filter]) ?>"
                                class="px-3 py-1.5 bg-[var(--card)] border border-[var(--border)] rounded-lg text-[9px] font-black uppercase text-[var(--secondary)] hover:text-rose-500 transition-all">Prev</a>
                        <?php endif; ?>
                        <span
                            class="px-3 py-1.5 bg-rose-500 text-white rounded-lg text-[9px] font-black uppercase"><?= $page ?></span>
                        <?php if ($page < $total_pages): ?>
                            <a href="?<?= http_build_query(['p' => $page + 1, 'search' => $search, 'status_filter' => $status_f, 'start_date' => $start_date, 'end_date' => $end_date, 'filter_year' => $filter_year, 'filter_month' => $filter_month, 'airline_filter' => $airline_filter]) ?>"
                                class="px-3 py-1.5 bg-[var(--card)] border border-[var(--border)] rounded-lg text-[9px] font-black uppercase text-[var(--secondary)] hover:text-rose-500 transition-all">Next</a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
    <?php if (!empty($success_msg)): ?>
        <script>
            document.addEventListener('DOMContentLoaded', () => {
                toast.success(<?= json_encode($success_msg) ?>);
            });
        </script>
    <?php endif; ?>
    <?php if (!empty($login_error)): ?>
        <script>
            document.addEventListener('DOMContentLoaded', () => {
                toast.error(<?= json_encode($login_error) ?>);
            });
        </script>
    <?php endif; ?>
</body>

</html>
