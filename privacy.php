<?php
require_once __DIR__ . '/bootstrap.php';
af_security_headers();
$pdo = af_db();
$settings = af_settings($pdo);
$company_name = $settings['company_name'] ?? 'AeroFind Cabin Recovery';
$company_short_name = $settings['company_short_name'] ?? 'AeroFind';
$favicon_url = af_valid_url_or_path($settings['favicon_url'] ?? '');
$staff_email = filter_var($settings['staff_notification_email'] ?? '', FILTER_VALIDATE_EMAIL) ? $settings['staff_notification_email'] : '';
$developer_email = filter_var($settings['developer_contact_email'] ?? '', FILTER_VALIDATE_EMAIL) ? $settings['developer_contact_email'] : '';
$contact_email = $staff_email ?: $developer_email;
$retention_days = max(1, min(365, (int) ($settings['passenger_view_days'] ?? 30)));
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Privacy Notice | <?= af_h($company_short_name) ?></title>
    <?php if ($favicon_url !== ''): ?>
        <link rel="icon" href="<?= af_h($favicon_url) ?>"><?php endif; ?>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;600;800&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Outfit', sans-serif;
        }
    </style>
</head>

<body class="min-h-screen bg-slate-950 text-slate-100">
    <main class="max-w-3xl mx-auto px-5 py-8 md:py-12">
        <a href="index.php" class="text-[10px] font-black uppercase tracking-widest text-rose-300 hover:text-rose-200">Back to Passenger Terminal</a>
        <header class="mt-6 mb-8">
            <p class="text-[10px] font-black uppercase tracking-[0.3em] text-rose-300 mb-2"><?= af_h($company_short_name) ?></p>
            <h1 class="text-3xl md:text-4xl font-black tracking-tight">Privacy Notice</h1>
            <p class="mt-3 text-sm text-slate-300 leading-6">This notice explains how <?= af_h($company_name) ?> processes personal data when passengers report lost cabin property or submit a claim request.</p>
            <p class="mt-2 text-xs text-slate-500 font-bold uppercase tracking-widest">Last updated: <?= date('d M Y') ?></p>
        </header>

        <section class="space-y-6 text-sm leading-7 text-slate-300">
            <div>
                <h2 class="text-lg font-black text-white mb-2">Controller And Contact</h2>
                <p><?= af_h($company_name) ?> is the controller for personal data submitted through this cabin recovery portal.</p>
                <?php if ($contact_email !== ''): ?>
                    <p class="mt-2">For privacy requests, contact <a class="text-rose-300 hover:text-rose-200 font-bold" href="mailto:<?= af_h($contact_email) ?>"><?= af_h($contact_email) ?></a>.</p>
                <?php else: ?>
                    <p class="mt-2">Use the airline or station contact details provided by staff to make privacy requests.</p>
                <?php endif; ?>
            </div>

            <div>
                <h2 class="text-lg font-black text-white mb-2">Personal Data We Process</h2>
                <p>We process the details you submit in lost-item reports and claim requests, including name, email address, phone number if supplied, airline, flight number, seat or row, item description, item reference, uploaded photos, staff review notes, consent timestamp, and technical submission data such as IP address.</p>
            </div>

            <div>
                <h2 class="text-lg font-black text-white mb-2">Purpose And Legal Basis</h2>
                <p>We use this data to receive and review lost-item reports, match reports with cabin recovery records, contact passengers about reports or claims, prevent duplicate records, and keep an operational audit trail.</p>
                <p class="mt-2">For passenger-submitted lost-item reports, processing is based on your consent. For operational records, claim handling, security, fraud prevention, and audit needs, processing may also be necessary for legitimate interests in managing cabin recovery services and protecting passenger property.</p>
            </div>

            <div>
                <h2 class="text-lg font-black text-white mb-2">Photos And Identity Documents</h2>
                <p>Uploaded photos may contain personal data. Passport and identity-document photos are treated as sensitive operational records in this portal: public passenger cards blur these images by default and staff access should be limited to people who need it for recovery handling.</p>
            </div>

            <div>
                <h2 class="text-lg font-black text-white mb-2">Who Receives The Data</h2>
                <p>Data may be viewed by authorised staff responsible for cabin recovery, customer support, and technical maintenance. Email notifications may be sent through the configured mail provider. We do not sell passenger data.</p>
            </div>

            <div>
                <h2 class="text-lg font-black text-white mb-2">Retention</h2>
                <p>Public passenger cards are limited by the terminal view range, currently <?= (int) $retention_days ?> day(s). Internal records are kept only as long as needed for cabin recovery, dispute handling, security, audit, and legal obligations, then deleted or anonymised according to operational policy.</p>
            </div>

            <div>
                <h2 class="text-lg font-black text-white mb-2">International Transfers</h2>
                <p>If hosting, email, or support providers process data outside the European Economic Area, the controller should use an appropriate GDPR transfer mechanism, such as an adequacy decision or standard contractual clauses.</p>
            </div>

            <div>
                <h2 class="text-lg font-black text-white mb-2">Your Rights</h2>
                <p>Subject to applicable law, you may request access, rectification, erasure, restriction, portability, or object to processing. Where processing is based on consent, you may withdraw consent at any time without affecting processing already carried out before withdrawal. You may also lodge a complaint with your local EU/EEA data protection authority.</p>
            </div>

            <div>
                <h2 class="text-lg font-black text-white mb-2">Security</h2>
                <p>We use access controls, staff authentication, image handling restrictions, and operational safeguards to reduce unauthorised access. No system can guarantee absolute security, so please avoid uploading unnecessary sensitive information.</p>
            </div>
        </section>

        <footer class="mt-10 pt-6 border-t border-white/10 text-xs text-slate-500">
            <p>This page is a practical privacy notice template for the portal and should be reviewed against your organisation, providers, retention policy, and local legal requirements before production use.</p>
        </footer>
    </main>
</body>

</html>
