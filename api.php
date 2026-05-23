<?php
require_once __DIR__ . '/bootstrap.php';
af_security_headers('json');
$pdo = af_db();

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

function next_report_ref(PDO $pdo)
{
    do {
        $ref = 'LR-' . date('Ymd-His') . '-' . strtoupper(bin2hex(random_bytes(2)));
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM pending_reports WHERE report_ref = ?");
        $stmt->execute([$ref]);
    } while ((int) $stmt->fetchColumn() > 0);

    return $ref;
}

function append_unique_note($existing_note, $note)
{
    $existing_note = trim((string) $existing_note);
    $note = trim((string) $note);
    if ($note === '') {
        return $existing_note;
    }
    if ($existing_note !== '' && preg_match('/(^|\R)' . preg_quote($note, '/') . '(\R|$)/', $existing_note)) {
        return $existing_note;
    }
    return trim($existing_note . ($existing_note !== '' ? "\n" : '') . $note);
}

// Check if POST data exceeded max allowed size
if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_POST) && empty($_FILES) && isset($_SERVER['CONTENT_LENGTH']) && (int)$_SERVER['CONTENT_LENGTH'] > 0) {
    echo json_encode([
        'success' => false,
        'error' => 'The uploaded file or post data is too large. Please upload a smaller image.'
    ]);
    exit;
}

$raw_input = file_get_contents('php://input');
$json_input = json_decode($raw_input, true) ?: [];

// Get action from any source, prioritizing POST, then JSON, then GET
$action = $_POST['action'] ?? $json_input['action'] ?? $_GET['action'] ?? '';
$action = trim($action);

// Fallback to default if empty and not a POST request
if ($action === '') {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        echo json_encode([
            'success' => false,
            'error' => 'Missing action parameter in POST request.'
        ]);
        exit;
    }
    $action = 'getPublicFoundItems';
}

$input = array_merge($_POST, $json_input, $_GET);

function mail_result($sent, $method, $error = '')
{
    return [
        'sent' => (bool) $sent,
        'method' => $method,
        'error' => $error
    ];
}

function email_plain_text($body_html)
{
    $text = preg_replace('/<\s*br\s*\/?>/i', "\n", $body_html);
    $text = preg_replace('/<\/\s*(p|div|h[1-6]|tr|table)\s*>/i', "\n", $text);
    $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace("/[ \t]+/", ' ', $text);
    $text = preg_replace("/\n{3,}/", "\n\n", $text);
    return trim($text);
}

function build_email_message($to, $subject, $body_html, $from, $include_envelope_headers = false)
{
    $safe_subject = str_replace(["\r", "\n"], ' ', $subject);
    $domain = substr(strrchr($from, "@"), 1) ?: 'aerofind.online';
    $boundary = 'af_' . bin2hex(random_bytes(12));
    $message_id = sprintf('<%s.%s@%s>', time(), bin2hex(random_bytes(6)), $domain);
    $plain_text = email_plain_text($body_html);

    $headers = [];
    if ($include_envelope_headers) {
        $headers[] = "To: <$to>";
        $headers[] = "Subject: $safe_subject";
    }
    $headers[] = "From: AeroFind Cabin Recovery <$from>";
    $headers[] = "Reply-To: $from";
    $headers[] = "Date: " . date(DATE_RFC2822);
    $headers[] = "Message-ID: $message_id";
    $headers[] = "MIME-Version: 1.0";
    $headers[] = "Content-Type: multipart/alternative; boundary=\"$boundary\"";
    $headers[] = "X-Mailer: AeroFind Cabin Portal";

    $body = "--$boundary\r\n";
    $body .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $body .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
    $body .= $plain_text . "\r\n\r\n";
    $body .= "--$boundary\r\n";
    $body .= "Content-Type: text/html; charset=UTF-8\r\n";
    $body .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
    $body .= $body_html . "\r\n\r\n";
    $body .= "--$boundary--";

    return [
        'subject' => $safe_subject,
        'headers' => implode("\r\n", $headers),
        'body' => $body
    ];
}

function send_php_mail($to, $subject, $body_html, $from)
{
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return mail_result(false, 'php_mail', "Invalid recipient email address: $to");
    }
    $safe_from = filter_var($from, FILTER_VALIDATE_EMAIL) ? $from : 'noreply@aerofind.online';
    $message = build_email_message($to, $subject, $body_html, $safe_from);
    
    // Normalize headers for the MTA (Unix/macOS systems require \n line endings rather than \r\n to prevent doubling)
    $headers = str_replace("\r\n", "\n", $message['headers']);
    
    $params = "-f$safe_from";
    $sent = @mail($to, $message['subject'], $message['body'], $headers, $params);
    if (!$sent) {
        $sent = @mail($to, $message['subject'], $message['body'], $headers);
    }
    
    // Log a local copy of the email in the uploads directory for easy developer access and local verification
    $mail_dir = __DIR__ . '/uploads/emails';
    if (!is_dir($mail_dir)) {
        @mkdir($mail_dir, 0755, true);
    }
    $safe_to = preg_replace('/[^A-Za-z0-9_-]/', '_', $to);
    $safe_subject = preg_replace('/[^A-Za-z0-9_-]/', '_', substr($subject, 0, 20));
    $mail_filename = $mail_dir . '/' . time() . '_' . $safe_to . '_' . $safe_subject . '.eml';
    $eml_content = "To: $to\nSubject: {$message['subject']}\n$headers\n\n{$message['body']}";
    @file_put_contents($mail_filename, $eml_content);

    return mail_result($sent, 'php_mail', $sent ? '' : 'PHP mail() did not confirm delivery. Check the server sendmail/mail transfer agent configuration.');
}

function send_smtp_email($to, $subject, $body_html, $settings)
{
    $host = trim($settings['smtp_host'] ?? '');
    $method = $settings['email_delivery_method'] ?? 'php_mail';
    $port = (int) ($settings['smtp_port'] ?? '587');
    $user = trim($settings['smtp_user'] ?? '');
    $pass = $settings['smtp_password'] ?? '';
    $from = $settings['smtp_from_email'] ?? 'noreply@aerofind.online';
    $encryption = $settings['smtp_encryption'] ?? 'tls';

    if ($method !== 'smtp' || empty($host)) {
        return send_php_mail($to, $subject, $body_html, $from);
    }

    try {
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return mail_result(false, 'smtp', "Invalid recipient email address: $to");
        }
        if (!filter_var($from, FILTER_VALIDATE_EMAIL)) {
            return mail_result(false, 'smtp', "Invalid sender email address: $from");
        }

        $socket_host = $host;
        if ($encryption === 'ssl') {
            $socket_host = 'ssl://' . $host;
        }

        $socket = @fsockopen($socket_host, $port, $errno, $errstr, 8);
        if (!$socket) {
            return mail_result(false, 'smtp', "Could not connect to SMTP server $host:$port ($errno: $errstr)");
        }
        stream_set_timeout($socket, 10);

        $read_resp = function ($socket) {
            $data = '';
            while ($str = fgets($socket, 515)) {
                $data .= $str;
                if (substr($str, 3, 1) == ' ')
                    break;
            }
            return $data;
        };

        $expect = function ($socket, $codes, $step) use ($read_resp) {
            $response = $read_resp($socket);
            foreach ((array) $codes as $code) {
                if (strpos($response, (string) $code) === 0) {
                    return $response;
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
                fclose($socket);
                throw new Exception("STARTTLS failed");
            }
            fwrite($socket, "EHLO " . ($_SERVER['SERVER_NAME'] ?: 'localhost') . "\r\n");
            $expect($socket, 250, 'EHLO after STARTTLS');
        }

        if (!empty($user) && !empty($pass)) {
            fwrite($socket, "AUTH LOGIN\r\n");
            $expect($socket, 334, 'AUTH LOGIN');
            fwrite($socket, base64_encode($user) . "\r\n");
            $expect($socket, 334, 'SMTP username');
            fwrite($socket, base64_encode($pass) . "\r\n");
            $expect($socket, 235, 'SMTP authentication');
        } elseif (!empty($user) || !empty($pass)) {
            throw new Exception('SMTP username and password must both be set.');
        }

        fwrite($socket, "MAIL FROM: <$from>\r\n");
        $expect($socket, 250, 'MAIL FROM');

        fwrite($socket, "RCPT TO: <$to>\r\n");
        $expect($socket, [250, 251], 'RCPT TO');

        fwrite($socket, "DATA\r\n");
        $expect($socket, 354, 'DATA');

        $message = build_email_message($to, $subject, $body_html, $from, true);
        $msg = $message['headers'] . "\r\n\r\n";
        $msg .= preg_replace('/^\./m', '..', $message['body']);
        $msg .= "\r\n.\r\n";

        fwrite($socket, $msg);
        $expect($socket, 250, 'Message delivery');

        fwrite($socket, "QUIT\r\n");
        fclose($socket);

        return mail_result(true, 'smtp');
    } catch (Exception $e) {
        if (isset($socket) && is_resource($socket)) {
            fclose($socket);
        }
        return mail_result(false, 'smtp', $e->getMessage());
    }
}

try {
    if ($action === 'getPublicFoundItems') {
        $flight = trim($input['flight_number'] ?? '');
        $date = trim($input['flight_date'] ?? '');
        $category = trim($input['item_category'] ?? '');

        // Get visibility setting
        $days_stmt = $pdo->query("SELECT value FROM settings WHERE key = 'passenger_view_days'");
        $days = max(1, min(365, (int) ($days_stmt->fetchColumn() ?: 30)));
        $layout_stmt = $pdo->query("SELECT value FROM settings WHERE key = 'passenger_card_layout'");
        $card_layout = $layout_stmt->fetchColumn() ?: 'dual';
        if (!in_array($card_layout, ['single', 'dual'], true)) {
            $card_layout = 'dual';
        }

        $query = "SELECT * FROM items WHERE status IN ('Found', 'Lost') AND created_at >= date('now', :days_range)";
        $params = [];
        $params[':days_range'] = '-' . $days . ' days';

        if ($flight !== '') {
            $query .= " AND (tag_no LIKE :flight OR other_info LIKE :flight)";
            $params[':flight'] = '%' . $flight . '%';
        }
        if ($date !== '') {
            $query .= " AND other_info LIKE :date";
            $params[':date'] = '%' . $date . '%';
        }
        if ($category !== '') {
            $query .= " AND item_description LIKE :cat";
            $params[':cat'] = '%' . $category . '%';
        }

        $query .= " ORDER BY CASE WHEN tag_no GLOB 'ID-[0-9]*' THEN 0 ELSE 1 END ASC, CAST(substr(tag_no, 4) AS INTEGER) DESC, created_at DESC LIMIT 500";

        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $airlines = $pdo->query("SELECT * FROM airlines")->fetchAll(PDO::FETCH_ASSOC);

        $grouped = [];
        $fallback_airline = null;
        foreach ($airlines as $al) {
            if ($al['code'] === 'AF' || stripos($al['name'], 'AeroFind') !== false) {
                $fallback_airline = $al;
                break;
            }
        }
        if (!$fallback_airline) {
            $fallback_airline = ['name' => 'AeroFind Cabin', 'logo' => 'https://ui-avatars.com/api/?name=AF&background=f43f5e&color=fff', 'code' => 'AF'];
        }

        foreach ($items as $item) {
            $found_airline = null;
            $other_info = trim($item['other_info'] ?? '');
            $tag_no = trim($item['tag_no'] ?? '');
            foreach ($airlines as $al) {
                $code = trim($al['code'] ?? '');
                $name = trim($al['name'] ?? '');
                if ($code !== '') {
                    $matched = (stripos($tag_no, $code) === 0) || (stripos($other_info, "Airline: " . $code) !== false) || (stripos($other_info, $code . " ") !== false);
                    if (!$matched) {
                        for ($i = 0; $i <= 9; $i++)
                            if (stripos($other_info, $code . $i) !== false) {
                                $matched = true;
                                break;
                            }
                    }
                    if ($matched) {
                        $found_airline = $al;
                        break;
                    }
                }
                if ($name !== '' && stripos($other_info, $name) !== false) {
                    $found_airline = $al;
                    break;
                }
            }
            $group_airline = $found_airline ?: $fallback_airline;
            $group_name = $group_airline['name'];
            if (!isset($grouped[$group_name])) {
                $grouped[$group_name] = ['airline' => $group_airline, 'items' => []];
            }
            $grouped[$group_name]['items'][] = [
                'id' => $item['tag_no'],
                'reference_code' => $item['tag_no'],
                'flight_number' => $item['other_info'],
                'item_category' => $item['item_description'],
                'item_description' => $item['item_description'],
                'photo' => $item['photo'] ?? '',
                'is_identity_document' => stripos($item['tag_no'] ?? '', 'PP-') === 0,
                'status' => $item['status'],
                'created_at' => $item['created_at']
            ];
        }
        uksort($grouped, function ($a, $b) {
            if (stripos($a, 'AeroFind') !== false)
                return 1;
            if (stripos($b, 'AeroFind') !== false)
                return -1;
            return strcasecmp($a, $b);
        });
        $public_settings = af_settings($pdo);
        echo json_encode([
            'success' => true,
            'grouped' => $grouped,
            'settings' => [
                'passenger_card_layout' => $card_layout,
                'passenger_view_days' => $days,
                'company_name' => $public_settings['company_name'] ?? 'AeroFind Cabin Recovery',
                'company_short_name' => $public_settings['company_short_name'] ?? 'AeroFind',
                'company_initials' => $public_settings['company_initials'] ?? 'AF',
                'company_logo' => af_valid_url_or_path($public_settings['company_logo'] ?? ''),
                'favicon_url' => af_valid_url_or_path($public_settings['favicon_url'] ?? '')
            ]
        ]);
        exit;
    }

    if ($action === 'reportLost') {
        $tag = next_item_tag($pdo);
        $report_ref = $tag;
        $airline_name = trim($_POST['airline'] ?? '');
        $flight_number = trim($_POST['flight_number'] ?? '');
        $item_description = trim($_POST['item_description'] ?? '');
        $pax_name = trim($_POST['pax_name'] ?? 'Anonymous');
        $pax_email = $_POST['contact_email'] ?? $_POST['pax_email'] ?? '';
        $pax_email = trim($pax_email);
        $pax_phone = trim($_POST['pax_phone'] ?? $_POST['pax_contact_no'] ?? '');
        $seat_info = trim($_POST['seat_info'] ?? $_POST['comments'] ?? '');
        $privacy_consent = (string) ($_POST['privacy_consent'] ?? '');

        if ($airline_name === '' || $flight_number === '' || $item_description === '' || $pax_name === '' || $pax_email === '') {
            echo json_encode(['success' => false, 'error' => 'Missing required report details']);
            exit;
        }
        if ($privacy_consent !== '1') {
            echo json_encode(['success' => false, 'error' => 'Privacy consent is required before submitting a lost item report']);
            exit;
        }
        if ($pax_email !== '' && !filter_var($pax_email, FILTER_VALIDATE_EMAIL)) {
            echo json_encode(['success' => false, 'error' => 'Invalid contact email address']);
            exit;
        }

        $photo_name = basename(af_store_uploaded_image('photo', 'PAX_' . $tag));

        $consent_ip = substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64);
        $stmt = $pdo->prepare("INSERT INTO pending_reports (report_ref, tag_no, airline, flight_number, item_description, pax_name, pax_email, pax_contact_no, seat_info, photo, status, privacy_consent_at, privacy_consent_ip, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending', datetime('now'), ?, datetime('now'))");
        $stmt->execute([
            $report_ref,
            $tag,
            $airline_name,
            $flight_number,
            $item_description,
            $pax_name,
            $pax_email,
            $pax_phone,
            $seat_info,
            $photo_name,
            $consent_ip
        ]);

        $settings = af_settings($pdo);
        $staff_email = $settings['staff_notification_email'] ?? '';
        $company_name = $settings['company_name'] ?? 'AeroFind Cabin Recovery';
        $company_short_name = $settings['company_short_name'] ?? 'AeroFind';
        $possible_stmt = $pdo->prepare("
            SELECT tag_no, item_description, other_info, status
            FROM items
            WHERE (
                item_description LIKE :desc
                OR other_info LIKE :flight
                OR pax_email = :email
            )
            ORDER BY created_at DESC
            LIMIT 5
        ");
        $desc_probe = '%' . substr($item_description, 0, 24) . '%';
        $possible_stmt->execute([
            ':desc' => $desc_probe,
            ':flight' => '%' . $flight_number . '%',
            ':email' => $pax_email
        ]);
        $possible_matches = $possible_stmt->fetchAll(PDO::FETCH_ASSOC);

        $staff_mail = mail_result(false, 'none', 'Staff notification email is not configured.');
        if (filter_var($staff_email, FILTER_VALIDATE_EMAIL)) {
            $match_rows = [];
            foreach ($possible_matches as $match) {
                $match_rows[] = [
                    $match['tag_no'] ?? '',
                    $match['item_description'] ?? '',
                    $match['status'] ?? ''
                ];
            }
            $staff_subject = "LOST REPORT NEEDS APPROVAL - [$tag]";
            $staff_html = af_render_email($settings, [
                'title' => 'Lost report awaiting approval',
                'preheader' => "Review pending lost report $tag",
                'eyebrow' => 'Staff action required',
                'paragraphs' => [
                    "A passenger submitted a lost item report in $company_short_name Cabin Portal. It has not been added to inventory yet."
                ],
                'rows' => [
                    ['label' => 'Reserved reference', 'value' => $tag, 'highlight' => true],
                    ['label' => 'Item', 'value' => $item_description],
                    ['label' => 'Passenger', 'value' => $pax_name],
                    ['label' => 'Email', 'value' => $pax_email],
                    ['label' => 'Flight', 'value' => trim("$airline_name $flight_number")]
                ],
                'sections' => [
                    [
                        'title' => 'Possible existing records',
                        'html' => af_email_data_table(['Reference', 'Item', 'Status'], $match_rows, 'No obvious existing records found.')
                    ]
                ],
                'footer' => 'Review this report in the staff portal before creating or linking an inventory record.'
            ]);
            $staff_mail = send_smtp_email($staff_email, $staff_subject, $staff_html, $settings);
            if (!$staff_mail['sent']) {
                error_log("AeroFind lost report mail warning: " . $staff_mail['error']);
            }
        } else {
            error_log("AeroFind lost report mail warning: invalid staff_notification_email [$staff_email]");
        }

        $pax_subject = "$company_name - Lost Report Received [$tag]";
        $pax_html = af_render_email($settings, [
            'title' => 'Lost report received',
            'preheader' => "Your cabin recovery report reference is $tag",
            'eyebrow' => 'Report submitted',
            'paragraphs' => [
                "Dear $pax_name,",
                'Your report has been submitted for staff review. It will be added to our cabin recovery records after staff approval, or linked to an existing record if the item is already logged.'
            ],
            'rows' => [
                ['label' => 'Reference', 'value' => $tag, 'highlight' => true],
                ['label' => 'Item', 'value' => $item_description],
                ['label' => 'Flight', 'value' => trim("$airline_name $flight_number")]
            ],
            'note_title' => 'Next step',
            'note' => 'Staff will contact you if more information is needed or if a matching item is found.'
        ]);
        $pax_mail = send_smtp_email($pax_email, $pax_subject, $pax_html, $settings);
        if (!$pax_mail['sent']) {
            error_log("AeroFind lost report passenger mail warning: " . $pax_mail['error']);
        }

        $email_warning = '';
        if (!$pax_mail['sent'] || !$staff_mail['sent']) {
            $failed = [];
            if (!$pax_mail['sent']) {
                $failed[] = 'passenger email: ' . ($pax_mail['error'] ?: 'delivery was not confirmed');
            }
            if (!$staff_mail['sent']) {
                $failed[] = 'staff email: ' . ($staff_mail['error'] ?: 'delivery was not confirmed');
            }
            $email_warning = 'Report saved, but ' . implode('; ', $failed);
        }

        echo json_encode([
            'success' => true,
            'tag' => $tag,
            'tag_no' => $tag,
            'id' => $tag,
            'reference_code' => $tag,
            'report_ref' => $report_ref,
            'pending' => true,
            'email_sent' => ($pax_mail['sent'] && $staff_mail['sent']),
            'passenger_email_sent' => $pax_mail['sent'],
            'staff_email_sent' => $staff_mail['sent'],
            'email_warning' => $email_warning,
            'email_method' => $pax_mail['method'] ?: $staff_mail['method']
        ]);
        exit;
    }

    if ($action === 'getAirlines') {
        $airlines = $pdo->query("SELECT * FROM airlines ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'airlines' => $airlines]);
        exit;
    }

    if ($action === 'claimItem') {
        $tag = normalize_tag($input['tag_no'] ?? '');
        $pax_name = trim($input['pax_name'] ?? '');
        $pax_email = trim($input['pax_email'] ?? '');
        $pax_contact = trim($input['pax_contact'] ?? '');
        $seat_info = trim($input['seat_info'] ?? '');

        if ($tag === '' || $pax_name === '' || $pax_email === '') {
            echo json_encode(['success' => false, 'error' => 'Missing passenger claim details']);
            exit;
        }
        if (!filter_var($pax_email, FILTER_VALIDATE_EMAIL)) {
            echo json_encode(['success' => false, 'error' => 'Invalid passenger email address']);
            exit;
        }

        // Fetch item details
        $stmt = $pdo->prepare("SELECT * FROM items WHERE tag_no = ?");
        $stmt->execute([$tag]);
        $item = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$item) {
            echo json_encode(['success' => false, 'error' => 'Item reference not found']);
            exit;
        }

        $updated_internal_note = append_unique_note($item['user_comments'] ?? '', 'Passenger claim request submitted');

        // Update item status and details
        $update_stmt = $pdo->prepare("UPDATE items SET pax_name = ?, pax_email = ?, pax_contact_no = ?, comments = ?, status = 'Claimed', user_comments = ? WHERE tag_no = ?");
        $update_stmt->execute([$pax_name, $pax_email, $pax_contact, $seat_info, $updated_internal_note, $tag]);

        // Get mail settings
        $settings_stmt = $pdo->query("SELECT key, value FROM settings");
        $settings = [];
        while ($row = $settings_stmt->fetch(PDO::FETCH_ASSOC)) {
            $settings[$row['key']] = $row['value'];
        }

        $company_name = $settings['company_name'] ?? 'AeroFind Cabin Recovery';
        $company_short_name = $settings['company_short_name'] ?? 'AeroFind';
        $company_initials = $settings['company_initials'] ?? 'AF';
        $pickup_info = $settings['pickup_info'] ?? 'Main Terminal, Cabin Recovery Lost & Found Desk. Please bring a valid ID and the reference code.';
        $staff_email = $settings['staff_notification_email'] ?? 'staff@aerofind.online';
        $from_email = $settings['smtp_from_email'] ?? 'noreply@aerofind.online';

        // 1. Send email to Passenger
        $pax_subject = "$company_name - Claim Confirmation for [$tag]";
        $pax_html = af_render_email($settings, [
            'title' => 'Claim details confirmed',
            'preheader' => "Your claim request for $tag has been registered.",
            'eyebrow' => 'Claim registered',
            'paragraphs' => [
                "Dear $pax_name,",
                'Your claim request has been registered in our cabin recovery system. Keep this reference available when collecting your item.'
            ],
            'rows' => [
                ['label' => 'Reference code', 'value' => $tag, 'highlight' => true],
                ['label' => 'Item description', 'value' => $item['item_description'] ?? ''],
                ['label' => 'Flight details', 'value' => $item['other_info'] ?? '']
            ],
            'note_title' => 'Pickup location',
            'note' => $pickup_info,
            'footer' => "$company_short_name Cabin Portal will use this claim record to support staff handover."
        ]);

        $pax_mail = send_smtp_email($pax_email, $pax_subject, $pax_html, $settings);

        // 2. Send email to Staff
        $staff_subject = "NEW ITEM CLAIM SUBMISSION - [$tag]";
        $staff_html = af_render_email($settings, [
            'title' => 'Cabin property claim submitted',
            'preheader' => "Passenger claim registered for $tag.",
            'eyebrow' => 'New claim',
            'paragraphs' => [
                "A passenger claim request has been registered for item reference $tag."
            ],
            'rows' => [
                ['label' => 'Reference', 'value' => $tag, 'highlight' => true],
                ['label' => 'Passenger', 'value' => $pax_name],
                ['label' => 'Email', 'value' => $pax_email],
                ['label' => 'Phone', 'value' => $pax_contact],
                ['label' => 'Seat/flight details', 'value' => $seat_info],
                ['label' => 'Item description', 'value' => $item['item_description'] ?? '']
            ],
            'footer' => "This is an automated system notification from $company_short_name Cabin Portal."
        ]);

        $staff_mail = send_smtp_email($staff_email, $staff_subject, $staff_html, $settings);
        $pax_sent = $pax_mail['sent'];
        $staff_sent = $staff_mail['sent'];
        if (!$pax_sent || !$staff_sent) {
            error_log("AeroFind mail warning: passenger_sent=" . ($pax_sent ? '1' : '0') . " staff_sent=" . ($staff_sent ? '1' : '0') . " passenger_method=" . $pax_mail['method'] . " staff_method=" . $staff_mail['method'] . " passenger_error=" . $pax_mail['error'] . " staff_error=" . $staff_mail['error']);
        }

        $email_warning = '';
        if (!$pax_sent || !$staff_sent) {
            $failed = [];
            if (!$pax_sent) {
                $failed[] = 'passenger email: ' . ($pax_mail['error'] ?: 'delivery was not confirmed');
            }
            if (!$staff_sent) {
                $failed[] = 'staff email: ' . ($staff_mail['error'] ?: 'delivery was not confirmed');
            }
            $method_label = ($pax_mail['method'] === 'smtp' || $staff_mail['method'] === 'smtp') ? 'SMTP' : 'PHP mail()';
            $email_warning = 'Claim saved, but ' . $method_label . ' did not confirm delivery (' . implode('; ', $failed) . '). Check mail settings.';
        }

        echo json_encode([
            'success' => true,
            'email_sent' => ($pax_sent && $staff_sent),
            'email_warning' => $email_warning
        ]);
        exit;
    }

    if ($action === 'trackItem') {
        $tag = normalize_tag($input['tag_no'] ?? '');
        $stmt = $pdo->prepare("SELECT * FROM items WHERE tag_no = ?");
        $stmt->execute([$tag]);
        $item = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($item) {
            echo json_encode([
                'success' => true,
                'item' => [
                    'tag_no' => $item['tag_no'] ?? '',
                    'item_description' => $item['item_description'] ?? '',
                    'other_info' => $item['other_info'] ?? '',
                    'status' => $item['status'] ?? '',
                    'created_at' => $item['created_at'] ?? '',
                    'photo' => $item['photo'] ?? '',
                ]
            ]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Item not found']);
        }
        exit;
    }

    echo json_encode([
        'success' => false,
        'error' => 'Invalid action.'
    ]);
} catch (Exception $e) {
    error_log('Cabin API error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Request could not be completed.']);
}
