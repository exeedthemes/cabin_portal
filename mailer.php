<?php

function af_mail_result($sent, $method, $error = ''): array
{
    return [
        'sent' => (bool) $sent,
        'method' => $method,
        'error' => $error
    ];
}

function af_mail_plain_text($body_html): string
{
    $text = preg_replace('/<\s*br\s*\/?>/i', "\n", $body_html);
    $text = preg_replace('/<\/\s*(p|div|h[1-6]|tr|table)\s*>/i', "\n", $text);
    $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace("/[ \t]+/", ' ', $text);
    $text = preg_replace("/\n{3,}/", "\n\n", $text);
    return trim($text);
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

function af_build_php_mail_message($to, $subject, $body_html, $from, $include_envelope_headers = false): array
{
    $safe_subject = str_replace(["\r", "\n"], ' ', (string) $subject);
    $domain = substr(strrchr($from, "@"), 1) ?: 'aerofind.online';
    $boundary = 'af_' . bin2hex(random_bytes(12));
    $message_id = sprintf('<%s.%s@%s>', time(), bin2hex(random_bytes(6)), $domain);

    $headers = [];
    if ($include_envelope_headers) {
        $headers[] = "To: <$to>";
        $headers[] = "Subject: $safe_subject";
    }
    $headers = array_merge($headers, [
        "From: AeroFind Cabin Recovery <$from>",
        "Reply-To: $from",
        "Return-Path: $from",
        "Date: " . date(DATE_RFC2822),
        "Message-ID: $message_id",
        "MIME-Version: 1.0",
        "Content-Type: multipart/alternative; boundary=\"$boundary\"",
        "X-Mailer: AeroFind Cabin Portal"
    ]);

    $body = "--$boundary\r\n";
    $body .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $body .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
    $body .= af_mail_plain_text($body_html) . "\r\n\r\n";
    $body .= "--$boundary\r\n";
    $body .= "Content-Type: text/html; charset=UTF-8\r\n";
    $body .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
    $body .= $body_html . "\r\n\r\n";
    $body .= "--$boundary--";

    return [
        'subject' => $safe_subject,
        'headers_crlf' => implode("\r\n", $headers),
        'headers_lf' => implode("\n", $headers),
        'body' => $body
    ];
}

function af_send_php_mail($to, $subject, $body_html, $from): array
{
    $to = trim((string) $to);
    $from = trim((string) $from);
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return af_mail_result(false, 'php_mail', "Invalid recipient email address: $to");
    }

    $safe_from = filter_var($from, FILTER_VALIDATE_EMAIL) ? $from : 'noreply@aerofind.online';
    $message = af_build_php_mail_message($to, $subject, $body_html, $safe_from);
    $param_variants = ['-f' . $safe_from, '-f' . escapeshellarg($safe_from), null];
    $sent = false;

    foreach ([$message['headers_crlf'], $message['headers_lf']] as $headers) {
        foreach ($param_variants as $params) {
            $sent = $params === null
                ? @mail($to, $message['subject'], $message['body'], $headers)
                : @mail($to, $message['subject'], $message['body'], $headers, $params);
            if ($sent) {
                break 2;
            }
        }
    }

    $mail_dir = __DIR__ . '/uploads/emails';
    if (!is_dir($mail_dir)) {
        @mkdir($mail_dir, 0755, true);
    }
    $safe_to = preg_replace('/[^A-Za-z0-9_-]/', '_', $to);
    $safe_subject = preg_replace('/[^A-Za-z0-9_-]/', '_', substr((string) $subject, 0, 32));
    $mail_filename = $mail_dir . '/' . time() . '_' . $safe_to . '_' . $safe_subject . '.eml';
    @file_put_contents($mail_filename, "To: $to\nSubject: {$message['subject']}\n{$message['headers_lf']}\n\n{$message['body']}");

    return af_mail_result($sent, 'php_mail', $sent ? '' : 'PHP mail() did not confirm delivery. Check cPanel Email Deliverability and verify the sender mailbox exists for aerofind.online.');
}

function af_send_configured_email($to, $subject, $body_html, array $settings): array
{
    $to = trim((string) $to);
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return af_mail_result(false, 'none', "Invalid recipient email address: $to");
    }

    $method = $settings['email_delivery_method'] ?? 'php_mail';
    $from = $settings['smtp_from_email'] ?? 'noreply@aerofind.online';
    if ($method !== 'smtp' || empty($settings['smtp_host'])) {
        return af_send_php_mail($to, $subject, $body_html, $from);
    }

    $host = trim((string) $settings['smtp_host']);
    $port = (int) ($settings['smtp_port'] ?? '587');
    $user = trim((string) ($settings['smtp_user'] ?? ''));
    $pass = (string) ($settings['smtp_password'] ?? '');
    $encryption = $settings['smtp_encryption'] ?? 'tls';

    if (!filter_var($from, FILTER_VALIDATE_EMAIL)) {
        return af_mail_result(false, 'smtp', "Invalid sender email address: $from");
    }

    try {
        $socket_host = $encryption === 'ssl' ? 'ssl://' . $host : $host;
        $socket = @fsockopen($socket_host, $port, $errno, $errstr, 8);
        if (!$socket) {
            return af_mail_result(false, 'smtp', "Could not connect to SMTP server $host:$port ($errno: $errstr)");
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

        $server_name = $_SERVER['SERVER_NAME'] ?? 'localhost';
        $expect($socket, 220, 'SMTP banner');
        fwrite($socket, "EHLO " . ($server_name ?: 'localhost') . "\r\n");
        $expect($socket, 250, 'EHLO');

        if ($encryption === 'tls') {
            fwrite($socket, "STARTTLS\r\n");
            $expect($socket, 220, 'STARTTLS');
            if (!@stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new Exception('STARTTLS failed');
            }
            fwrite($socket, "EHLO " . ($server_name ?: 'localhost') . "\r\n");
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

        fwrite($socket, "MAIL FROM: <$from>\r\n");
        $expect($socket, 250, 'MAIL FROM');
        fwrite($socket, "RCPT TO: <$to>\r\n");
        $expect($socket, [250, 251], 'RCPT TO');
        fwrite($socket, "DATA\r\n");
        $expect($socket, 354, 'DATA');

        $message = af_build_php_mail_message($to, $subject, $body_html, $from, true);
        fwrite($socket, $message['headers_crlf'] . "\r\n\r\n" . preg_replace('/^\./m', '..', $message['body']) . "\r\n.\r\n");
        $expect($socket, 250, 'Message delivery');
        fwrite($socket, "QUIT\r\n");
        fclose($socket);

        return af_mail_result(true, 'smtp');
    } catch (Exception $e) {
        if (isset($socket) && is_resource($socket)) {
            fclose($socket);
        }
        return af_mail_result(false, 'smtp', $e->getMessage());
    }
}

function af_mail_flight_display(PDO $pdo, string $flight_info): string
{
    $flight_info = trim($flight_info);
    if ($flight_info === '') {
        return '';
    }

    $stmt = $pdo->query("SELECT name, code FROM airlines ORDER BY LENGTH(code) DESC");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $airline) {
        $name = trim((string) ($airline['name'] ?? ''));
        $code = strtoupper(trim((string) ($airline['code'] ?? '')));
        if ($name === '' || $code === '') {
            continue;
        }

        if (stripos($flight_info, $name) === 0) {
            return $flight_info;
        }

        if (preg_match('/^' . preg_quote($code, '/') . '\b\s*(.*)$/i', $flight_info, $matches)) {
            $remaining = trim((string) ($matches[1] ?? ''));
            return trim($name . ($remaining !== '' ? ' ' . $remaining : ''));
        }

        if (preg_match('/^' . preg_quote($code, '/') . '(?=\d)/i', $flight_info)) {
            return trim($name . ' ' . $flight_info);
        }
    }

    return $flight_info;
}

function af_email_package(string $subject, string $html): array
{
    return ['subject' => $subject, 'html' => $html];
}

function af_lost_report_staff_email(array $settings, array $details): array
{
    $company_short_name = $settings['company_short_name'] ?? 'AeroFind';
    $tag = (string) ($details['tag'] ?? '');
    $match_rows = $details['match_rows'] ?? [];
    return af_email_package("LOST REPORT NEEDS APPROVAL - [$tag]", af_render_email($settings, [
        'title' => 'Lost report awaiting approval',
        'preheader' => "Review pending lost report $tag",
        'eyebrow' => 'Staff action required',
        'paragraphs' => [
            "A passenger submitted a lost item report in $company_short_name Cabin Portal. It has not been added to inventory yet."
        ],
        'rows' => [
            ['label' => 'Reserved reference', 'value' => $tag, 'highlight' => true],
            ['label' => 'Item', 'value' => $details['item_description'] ?? ''],
            ['label' => 'Passenger', 'value' => $details['pax_name'] ?? ''],
            ['label' => 'Email', 'value' => $details['pax_email'] ?? ''],
            ['label' => 'Flight', 'value' => $details['flight_details'] ?? '']
        ],
        'sections' => [
            [
                'title' => 'Possible existing records',
                'html' => af_email_data_table(['Reference', 'Item', 'Status'], $match_rows, 'No obvious existing records found.')
            ]
        ],
        'footer' => 'Review this report in the staff portal before creating or linking an inventory record.'
    ]));
}

function af_lost_report_received_email(array $settings, array $details): array
{
    $company_name = $settings['company_name'] ?? 'AeroFind Cabin Recovery';
    $tag = (string) ($details['tag'] ?? '');
    return af_email_package("$company_name - Lost Report Received [$tag]", af_render_email($settings, [
        'title' => 'Lost report received',
        'preheader' => "Your cabin recovery report reference is $tag",
        'eyebrow' => 'Report submitted',
        'paragraphs' => [
            'Dear ' . (($details['pax_name'] ?? '') ?: 'Passenger') . ',',
            'Your report has been submitted for staff review. It will be added to our cabin recovery records after staff approval, or linked to an existing record if the item is already logged.'
        ],
        'rows' => [
            ['label' => 'Reference', 'value' => $tag, 'highlight' => true],
            ['label' => 'Item', 'value' => $details['item_description'] ?? ''],
            ['label' => 'Flight', 'value' => $details['flight_details'] ?? '']
        ],
        'note_title' => 'Next step',
        'note' => 'Staff will contact you if more information is needed or if a matching item is found.'
    ]));
}

function af_claim_confirmation_email(array $settings, array $details): array
{
    $company_name = $settings['company_name'] ?? 'AeroFind Cabin Recovery';
    $company_short_name = $settings['company_short_name'] ?? 'AeroFind';
    $tag = (string) ($details['tag'] ?? '');
    return af_email_package("$company_name - Claim Request Received [$tag]", af_render_email($settings, [
        'title' => 'Claim request received',
        'preheader' => "Your claim request for $tag has been registered.",
        'eyebrow' => 'Claim registered',
        'paragraphs' => [
            'Dear ' . (($details['pax_name'] ?? '') ?: 'Passenger') . ',',
            'Your claim request has been registered in our cabin recovery system. Staff will verify ownership before any item is released.'
        ],
        'rows' => [
            ['label' => 'Reference code', 'value' => $tag, 'highlight' => true],
            ['label' => 'Item description', 'value' => $details['item_description'] ?? ''],
            ['label' => 'Flight details', 'value' => $details['flight_details'] ?? '']
        ],
        'note_title' => 'Next step',
        'note' => 'Please wait for staff confirmation before arranging collection. You may be asked to provide additional ownership details.',
        'footer' => "$company_short_name Cabin Portal will use this claim request to support staff review."
    ]));
}

function af_claim_staff_email(array $settings, array $details): array
{
    $company_short_name = $settings['company_short_name'] ?? 'AeroFind';
    $tag = (string) ($details['tag'] ?? '');
    return af_email_package("NEW ITEM CLAIM SUBMISSION - [$tag]", af_render_email($settings, [
        'title' => 'Cabin property claim submitted',
        'preheader' => "Passenger claim registered for $tag.",
        'eyebrow' => 'New claim',
        'paragraphs' => [
            "A passenger claim request has been registered for item reference $tag."
        ],
        'rows' => [
            ['label' => 'Reference', 'value' => $tag, 'highlight' => true],
            ['label' => 'Passenger', 'value' => $details['pax_name'] ?? ''],
            ['label' => 'Email', 'value' => $details['pax_email'] ?? ''],
            ['label' => 'Phone', 'value' => $details['pax_contact'] ?? ''],
            ['label' => 'Seat/flight details', 'value' => $details['seat_info'] ?? ''],
            ['label' => 'Item description', 'value' => $details['item_description'] ?? '']
        ],
        'footer' => "This is an automated system notification from $company_short_name Cabin Portal."
    ]));
}

function af_pending_report_approved_email(array $settings, array $details): array
{
    $company_name = $settings['company_name'] ?? 'AeroFind Cabin Recovery';
    $tag = (string) ($details['tag'] ?? '');
    return af_email_package("$company_name - Lost Report Approved [$tag]", af_render_email($settings, [
        'title' => 'Lost report approved',
        'preheader' => "Your report has been added as $tag.",
        'eyebrow' => 'Report reviewed',
        'paragraphs' => [
            'Dear ' . (($details['pax_name'] ?? '') ?: 'Passenger') . ',',
            'Your lost item report has been reviewed and added to our cabin recovery records.'
        ],
        'rows' => [
            ['label' => 'Item reference', 'value' => $tag, 'highlight' => true],
            ['label' => 'Report reference', 'value' => $details['report_ref'] ?? ''],
            ['label' => 'Item', 'value' => $details['item_description'] ?? '']
        ],
        'note_title' => 'What happens next',
        'note' => 'Staff will contact you if a matching item is recovered.'
    ]));
}

function af_pending_report_duplicate_email(array $settings, array $details): array
{
    $company_name = $settings['company_name'] ?? 'AeroFind Cabin Recovery';
    $existing_tag = (string) ($details['existing_tag'] ?? '');
    return af_email_package("$company_name - Lost Report Already Logged [$existing_tag]", af_render_email($settings, [
        'title' => 'Lost report reviewed',
        'preheader' => 'Your report appears to match an existing recovery record.',
        'eyebrow' => 'Report reviewed',
        'paragraphs' => [
            'Dear ' . (($details['pax_name'] ?? '') ?: 'Passenger') . ',',
            'Staff reviewed your report and found that this item appears to already be logged in our cabin recovery system.'
        ],
        'rows' => [
            ['label' => 'Existing reference', 'value' => $existing_tag, 'highlight' => true]
        ],
        'note_title' => 'Next step',
        'note' => 'Staff will continue using the existing record and contact you if there is an update.'
    ]));
}

function af_pending_report_rejected_email(array $settings, array $details): array
{
    $company_name = $settings['company_name'] ?? 'AeroFind Cabin Recovery';
    $report_ref = (string) ($details['report_ref'] ?? '');
    $staff_notes = (string) ($details['staff_notes'] ?? '');
    return af_email_package("$company_name - Lost Report Not Added [$report_ref]", af_render_email($settings, [
        'title' => 'Lost report reviewed',
        'preheader' => 'Your lost item report was reviewed by staff.',
        'eyebrow' => 'Report reviewed',
        'paragraphs' => [
            'Dear ' . (($details['pax_name'] ?? '') ?: 'Passenger') . ',',
            'Staff reviewed your lost item report and did not add a new cabin recovery record at this time.'
        ],
        'note_title' => $staff_notes !== '' ? 'Staff note' : '',
        'note' => $staff_notes
    ]));
}

function af_lost_status_email(array $settings, array $details): array
{
    $company_name = $settings['company_name'] ?? 'AeroFind Cabin Recovery';
    $tag = (string) ($details['tag'] ?? '');
    return af_email_package("$company_name - Lost Item Status Update for [$tag]", af_render_email($settings, [
        'title' => 'Lost item status update',
        'preheader' => "Status update for item $tag.",
        'eyebrow' => 'Status update',
        'paragraphs' => [
            'Dear ' . (($details['pax_name'] ?? '') ?: 'Passenger') . ',',
            'We are sorry to inform you that your item has been marked as lost in our cabin recovery system because it has not been recovered.'
        ],
        'rows' => [
            ['label' => 'Reference', 'value' => $tag, 'highlight' => true],
            ['label' => 'Item', 'value' => $details['item_description'] ?? '']
        ],
        'note_title' => 'Record status',
        'note' => 'If the item is later recovered, our team can update the record and contact you again.'
    ]));
}

function af_found_status_email(array $settings, array $details): array
{
    $company_name = $settings['company_name'] ?? 'AeroFind Cabin Recovery';
    $tag = (string) ($details['tag'] ?? '');
    return af_email_package("$company_name - Your Item Has Been Found [$tag]", af_render_email($settings, [
        'title' => 'Your item has been found',
        'preheader' => "Your item $tag has been found and is ready for collection.",
        'eyebrow' => 'Item found',
        'paragraphs' => [
            'Dear ' . (($details['pax_name'] ?? '') ?: 'Passenger') . ',',
            'Good news: your reported item has been found and is now available for collection from the station location.'
        ],
        'rows' => [
            ['label' => 'Reference', 'value' => $tag, 'highlight' => true],
            ['label' => 'Item', 'value' => $details['item_description'] ?? ''],
            ['label' => 'Flight/details', 'value' => $details['flight_details'] ?? ''],
            ['label' => 'Station', 'value' => $details['station'] ?? '']
        ],
        'note_title' => 'Collection location',
        'note' => $details['collection_location'] ?? ''
    ]));
}

function af_pickup_completed_email(array $settings, array $details): array
{
    $company_name = $settings['company_name'] ?? 'AeroFind Cabin Recovery';
    $tag = (string) ($details['tag'] ?? '');
    return af_email_package("$company_name - Pickup Completed for [$tag]", af_render_email($settings, [
        'title' => 'Pickup completed',
        'preheader' => "Pickup completed for item $tag.",
        'eyebrow' => 'Handover complete',
        'paragraphs' => [
            'Dear ' . (($details['pax_name'] ?? '') ?: 'Passenger') . ',',
            "This confirms that your item pickup has been completed by $company_name."
        ],
        'rows' => [
            ['label' => 'Reference', 'value' => $tag, 'highlight' => true],
            ['label' => 'Item', 'value' => $details['item_description'] ?? ''],
            ['label' => 'Flight/details', 'value' => $details['flight_details'] ?? ''],
            ['label' => 'Completed', 'value' => $details['completed_at'] ?? '']
        ],
        'note_title' => 'Thank you',
        'note' => "Thank you for using $company_name."
    ]));
}

function af_staff_logged_found_item_email(array $settings, array $details): array
{
    $company_name = $settings['company_name'] ?? 'AeroFind Cabin Recovery';
    $tag = (string) ($details['tag'] ?? '');
    $html = af_render_email($settings, [
        'title' => 'We believe we found your item',
        'preheader' => 'We believe your item has been found and is ready for collection.',
        'eyebrow' => 'Item found',
        'paragraphs' => [
            'Dear ' . (($details['pax_name'] ?? '') ?: 'Passenger') . ',',
            'A staff member has logged an item in our cabin recovery records that appears to match your report.',
            'If this is your item, please come to the station collection location and bring a valid ID along with the reference below.'
        ],
        'rows' => [
            ['label' => 'Reference', 'value' => $tag, 'highlight' => true],
            ['label' => 'Item', 'value' => $details['item_description'] ?? ''],
            ['label' => 'Flight/details', 'value' => $details['flight_details'] ?? ''],
            ['label' => 'Station', 'value' => $details['station'] ?? '']
        ],
        'note_title' => 'Collection location',
        'note' => $details['collection_location'] ?? ''
    ]);

    return [
        'subject' => "$company_name - We Believe We Found Your Item [$tag]",
        'html' => $html
    ];
}

function af_staff_item_activity_email(array $settings, array $details): array
{
    $company_name = $settings['company_name'] ?? 'AeroFind Cabin Recovery';
    $tag = (string) ($details['tag'] ?? '');
    $action = trim((string) ($details['action'] ?? 'Item updated'));
    return af_email_package("$company_name - $action [$tag]", af_render_email($settings, [
        'title' => $action,
        'preheader' => "$action for item $tag.",
        'eyebrow' => 'Inventory update',
        'paragraphs' => [
            'An inventory record was updated in the staff portal.'
        ],
        'rows' => [
            ['label' => 'Reference', 'value' => $tag, 'highlight' => true],
            ['label' => 'Item', 'value' => $details['item_description'] ?? ''],
            ['label' => 'Station', 'value' => $details['station'] ?? ''],
            ['label' => 'Changed by', 'value' => $details['actor'] ?? ''],
            ['label' => 'Status', 'value' => $details['status'] ?? ''],
            ['label' => 'Previous status', 'value' => $details['previous_status'] ?? '']
        ],
        'note_title' => trim((string) ($details['note'] ?? '')) !== '' ? 'Note' : '',
        'note' => $details['note'] ?? ''
    ]));
}
