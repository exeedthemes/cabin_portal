<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/mailer.php';
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

function airline_code_for_saved_report(PDO $pdo, $airline_value)
{
    $airline_value = trim((string) $airline_value);
    if ($airline_value === '') {
        return '';
    }

    $stmt = $pdo->prepare("SELECT code FROM airlines WHERE UPPER(code) = UPPER(?) OR UPPER(name) = UPPER(?) LIMIT 1");
    $stmt->execute([$airline_value, $airline_value]);
    $code = trim((string) $stmt->fetchColumn());

    return $code !== '' ? strtoupper($code) : strtoupper($airline_value);
}

function public_flight_label($value): string
{
    $value = strtoupper(trim((string) $value));
    if (preg_match('/\b([A-Z]{2,3})\s*-?\s*(\d{1,4}[A-Z]?)\b/', $value, $matches)) {
        return $matches[1] . ' ' . $matches[2];
    }
    return '';
}

function api_secret_value(): string
{
    return af_api_secret();
}

function request_api_secret(): string
{
    $header_secret = $_SERVER['HTTP_X_API_SECRET'] ?? '';
    if (is_string($header_secret) && trim($header_secret) !== '') {
        return trim($header_secret);
    }

    $authorization = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if (is_string($authorization) && preg_match('/^Bearer\s+(.+)$/i', $authorization, $matches)) {
        return trim($matches[1]);
    }

    return '';
}

function require_api_secret(): void
{
    $configured_secret = api_secret_value();
    if ($configured_secret === '') {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'API action is not enabled.']);
        exit;
    }

    $provided_secret = request_api_secret();
    if ($provided_secret === '' || !hash_equals($configured_secret, $provided_secret)) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Invalid API credentials.']);
        exit;
    }
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

$rate_limits = [
    'reportLost' => [5, 900],
    'claimItem' => [8, 900],
    'trackItem' => [30, 900],
    'getPublicFoundItems' => [120, 900],
    'getAirlines' => [120, 900],
];
if (isset($rate_limits[$action])) {
    [$max_attempts, $window_seconds] = $rate_limits[$action];
    if (!af_rate_limit('api_' . $action, $max_attempts, $window_seconds)) {
        http_response_code(429);
        echo json_encode(['success' => false, 'error' => 'Too many requests. Please wait before trying again.']);
        exit;
    }
}

$secret_protected_actions = [
    'getPublicFoundItems',
    'reportLost',
    'getAirlines',
    'claimItem',
    'trackItem',
];
if (in_array($action, $secret_protected_actions, true) && !defined('AF_INTERNAL_API_CALL')) {
    require_api_secret();
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
            $is_identity_document = stripos($item['tag_no'] ?? '', 'PP-') === 0;
            $grouped[$group_name]['items'][] = [
                'id' => $item['tag_no'],
                'reference_code' => $item['tag_no'],
                'flight_number' => public_flight_label($item['other_info'] ?? ''),
                'item_category' => $item['item_description'],
                'item_description' => $item['item_description'],
                'photo' => $is_identity_document ? '' : ($item['photo'] ?? ''),
                'is_identity_document' => $is_identity_document,
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
        $airline_name = airline_code_for_saved_report($pdo, $_POST['airline'] ?? '');
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
        $flight_details = af_mail_flight_display($pdo, trim("$airline_name $flight_number"));

        $staff_mail = af_mail_result(false, 'none', 'Staff notification email is not configured.');
        if (filter_var($staff_email, FILTER_VALIDATE_EMAIL)) {
            $match_rows = [];
            foreach ($possible_matches as $match) {
                $match_rows[] = [
                    $match['tag_no'] ?? '',
                    $match['item_description'] ?? '',
                    $match['status'] ?? ''
                ];
            }
            $staff_email_content = af_lost_report_staff_email($settings, [
                'tag' => $tag,
                'item_description' => $item_description,
                'pax_name' => $pax_name,
                'pax_email' => $pax_email,
                'flight_details' => $flight_details,
                'match_rows' => $match_rows
            ]);
            $staff_mail = af_send_configured_email($staff_email, $staff_email_content['subject'], $staff_email_content['html'], $settings);
            if (!$staff_mail['sent']) {
                error_log("AeroFind lost report mail warning: " . $staff_mail['error']);
            }
        } else {
            error_log("AeroFind lost report mail warning: invalid staff_notification_email [$staff_email]");
        }

        $pax_email_content = af_lost_report_received_email($settings, [
            'tag' => $tag,
            'pax_name' => $pax_name,
            'item_description' => $item_description,
            'flight_details' => $flight_details
        ]);
        $pax_mail = af_send_configured_email($pax_email, $pax_email_content['subject'], $pax_email_content['html'], $settings);
        if (!$pax_mail['sent']) {
            error_log("AeroFind lost report passenger mail warning: " . $pax_mail['error']);
        }

        $email_warning = '';
        if (!$pax_mail['sent'] || !$staff_mail['sent']) {
            $email_warning = 'Report saved, but email delivery could not be confirmed. Staff can still review the report.';
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
        if (($item['status'] ?? '') !== 'Found') {
            echo json_encode(['success' => false, 'error' => 'This item is not currently available for public claim.']);
            exit;
        }

        $updated_internal_note = append_unique_note($item['user_comments'] ?? '', 'Passenger claim request submitted');

        $update_stmt = $pdo->prepare("UPDATE items SET user_comments = ? WHERE tag_no = ?");
        $update_stmt->execute([$updated_internal_note, $tag]);

        // Get station-specific mail and pickup settings.
        $settings = af_settings($pdo);
        $station_code = af_get_current_station();
        $stations = af_stations();
        $station_name = $stations[$station_code] ?? $station_code;

        $pickup_info = af_pickup_info($settings, $station_code, $station_name);
        $staff_email = $settings['staff_notification_email'] ?? 'staff@aerofind.online';

        // 1. Send email to Passenger
        $pax_email_content = af_claim_confirmation_email($settings, [
            'tag' => $tag,
            'pax_name' => $pax_name,
            'item_description' => $item['item_description'] ?? '',
            'flight_details' => af_mail_flight_display($pdo, $item['other_info'] ?? ''),
            'pickup_info' => $pickup_info
        ]);

        $pax_mail = af_send_configured_email($pax_email, $pax_email_content['subject'], $pax_email_content['html'], $settings);

        // 2. Send email to Staff
        $staff_email_content = af_claim_staff_email($settings, [
            'tag' => $tag,
            'pax_name' => $pax_name,
            'pax_email' => $pax_email,
            'pax_contact' => $pax_contact,
            'seat_info' => $seat_info,
            'item_description' => $item['item_description'] ?? ''
        ]);

        $staff_mail = af_send_configured_email($staff_email, $staff_email_content['subject'], $staff_email_content['html'], $settings);
        $pax_sent = $pax_mail['sent'];
        $staff_sent = $staff_mail['sent'];
        if (!$pax_sent || !$staff_sent) {
            error_log("AeroFind mail warning: passenger_sent=" . ($pax_sent ? '1' : '0') . " staff_sent=" . ($staff_sent ? '1' : '0') . " passenger_method=" . $pax_mail['method'] . " staff_method=" . $staff_mail['method'] . " passenger_error=" . $pax_mail['error'] . " staff_error=" . $staff_mail['error']);
        }

        $email_warning = '';
        if (!$pax_sent || !$staff_sent) {
            $email_warning = 'Claim request saved, but email delivery could not be confirmed. Staff can still review the request.';
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
                    'status' => $item['status'] ?? '',
                    'created_at' => $item['created_at'] ?? '',
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
