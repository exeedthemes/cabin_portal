<?php
require_once __DIR__ . '/bootstrap.php';
af_security_headers();
$pdo = af_db();
$settings = af_settings($pdo);
$company_name = $settings['company_name'] ?? 'AeroFind Cabin Recovery';
$company_short_name = $settings['company_short_name'] ?? 'AeroFind';
$favicon_url = af_valid_url_or_path($settings['favicon_url'] ?? '');
$legal_name = trim((string) ($settings['legal_company_name'] ?? '')) ?: $company_name;
$legal_form = trim((string) ($settings['legal_form'] ?? ''));
$legal_representative = trim((string) ($settings['legal_representative'] ?? ''));
$legal_street_address = trim((string) ($settings['legal_street_address'] ?? ''));
$legal_postal_city = trim((string) ($settings['legal_postal_city'] ?? ''));
$legal_country = trim((string) ($settings['legal_country'] ?? 'Germany'));
$legal_email = filter_var($settings['legal_email'] ?? '', FILTER_VALIDATE_EMAIL) ? $settings['legal_email'] : '';
$legal_phone = trim((string) ($settings['legal_phone'] ?? ''));
$legal_register = trim((string) ($settings['legal_register'] ?? ''));
$legal_vat_id = trim((string) ($settings['legal_vat_id'] ?? ''));
$privacy_email = filter_var($settings['privacy_contact_email'] ?? '', FILTER_VALIDATE_EMAIL) ? $settings['privacy_contact_email'] : '';
$fallback_email = filter_var($settings['staff_notification_email'] ?? '', FILTER_VALIDATE_EMAIL) ? $settings['staff_notification_email'] : '';
$contact_email = $legal_email ?: ($privacy_email ?: $fallback_email);
function af_impressum_value(string $value, string $placeholder): string {
    return $value !== '' ? af_h($value) : '<span class="text-amber-300 font-bold">' . af_h($placeholder) . '</span>';
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Impressum | <?= af_h($company_short_name) ?></title>
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
            <h1 class="text-3xl md:text-4xl font-black tracking-tight">Impressum</h1>
            <p class="mt-3 text-sm text-slate-300 leading-6">Provider information for this cabin lost-and-found portal.</p>
        </header>

        <section class="space-y-6 text-sm leading-7 text-slate-300">
            <div>
                <h2 class="text-lg font-black text-white mb-2">Service Provider</h2>
                <p><?= af_impressum_value($legal_name, 'Legal company name not configured') ?><?= $legal_form !== '' ? ' ' . af_h($legal_form) : '' ?></p>
                <p><?= af_impressum_value($legal_street_address, 'Street address not configured') ?></p>
                <p><?= af_impressum_value(trim($legal_postal_city . ' ' . $legal_country), 'Postal address not configured') ?></p>
            </div>

            <div>
                <h2 class="text-lg font-black text-white mb-2">Represented By</h2>
                <p><?= af_impressum_value($legal_representative, 'Representative not configured') ?></p>
            </div>

            <div>
                <h2 class="text-lg font-black text-white mb-2">Contact</h2>
                <?php if ($contact_email !== ''): ?>
                    <p>Email: <a class="text-rose-300 hover:text-rose-200 font-bold" href="mailto:<?= af_h($contact_email) ?>"><?= af_h($contact_email) ?></a></p>
                <?php else: ?>
                    <p><span class="text-amber-300 font-bold">Contact email not configured</span></p>
                <?php endif; ?>
                <?php if ($legal_phone !== ''): ?>
                    <p>Phone: <?= af_h($legal_phone) ?></p>
                <?php endif; ?>
            </div>

            <div>
                <h2 class="text-lg font-black text-white mb-2">Register And Tax Information</h2>
                <p>Register: <?= af_impressum_value($legal_register, 'Register details not configured') ?></p>
                <p>VAT ID: <?= af_impressum_value($legal_vat_id, 'VAT ID not configured') ?></p>
            </div>

            <div>
                <h2 class="text-lg font-black text-white mb-2">Operational Role</h2>
                <p>This portal supports cabin lost-and-found handling by a ground handling provider for airline customers. Airline-specific responsibilities and data-processing roles are governed by the applicable service agreement and data-processing agreement.</p>
            </div>
        </section>

        <footer class="mt-10 pt-6 border-t border-white/10 text-xs text-slate-500 flex flex-wrap gap-3">
            <a href="privacy.php" class="hover:text-rose-300">Privacy Notice</a>
            <a href="index.php" class="hover:text-rose-300">Passenger Terminal</a>
        </footer>
    </main>
</body>

</html>
