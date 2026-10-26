<?php
require_once __DIR__ . '/categories.php';
/** Fee estimates use integer cents and calendar days, never floating-point totals. */
function af_fee_categories(): array {
    return array_map(fn($category) => $category['label'], af_item_categories());
}
function af_fee_category(string $description): string {
    return af_detect_item_category($description);
}
function af_fee_cents(string $value): ?int {
    $value = trim($value);
    if ($value === '') return null;
    if (!preg_match('/^\d{1,6}(?:\.\d{1,2})?$/D', $value)) throw new InvalidArgumentException('Enter non-negative amounts with up to two decimal places.');
    [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
    return ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');
}
function af_fee_policy(array $settings): array {
    $policy = json_decode($settings['handover_fee_policy'] ?? '', true);
    if (!is_array($policy)) $policy = [];
    $policy['currency'] = $policy['currency'] ?? 'EUR';
    $policy['visible'] = ($policy['visible'] ?? false) === true;
    foreach (af_fee_categories() as $key => $label) {
        // Preserve configured legacy fees when introducing more specific categories.
        $legacy = af_item_categories()[$key]['legacy_fee'];
        $policy['rates'][$key] = $policy['rates'][$key] ?? $policy['rates'][$legacy] ?? ['handling_cents' => null, 'daily_cents' => null, 'free_days' => 0, 'period_days' => 1];
        $policy['rates'][$key]['period_days'] = $policy['rates'][$key]['period_days'] ?? 1;
    }
    return $policy;
}
function af_fee_policy_from_input(array $input, array $existing = []): array {
    $currency = strtoupper(trim((string) ($input['currency'] ?? $existing['currency'] ?? '')));
    if (!in_array($currency, ['EUR', 'GBP', 'USD', 'CHF'], true)) throw new InvalidArgumentException('Select a supported currency.');
    $policy = ['visible' => ($input['billing_visibility'] ?? '') === 'visible', 'currency' => $currency, 'rates' => []];
    foreach (af_fee_categories() as $key => $label) {
        $free = (string) ($input['free_days'][$key] ?? $existing['rates'][$key]['free_days'] ?? '0');
        if (!preg_match('/^\d{1,4}$/D', $free) || (int) $free > 3650) throw new InvalidArgumentException('Free storage days must be between 0 and 3650.');
        $period = (int) ($input['period_days'][$key] ?? $existing['rates'][$key]['period_days'] ?? 1);
        if (!in_array($period, [1, 7, 30], true)) throw new InvalidArgumentException('Choose daily, weekly or 30-day storage charges.');
        $policy['rates'][$key] = [
            'handling_cents' => af_fee_cents((string) ($input['handling'][$key] ?? (isset($existing['rates'][$key]['handling_cents']) ? number_format($existing['rates'][$key]['handling_cents'] / 100, 2, '.', '') : ''))),
            'daily_cents' => af_fee_cents((string) ($input['daily'][$key] ?? (isset($existing['rates'][$key]['daily_cents']) ? number_format($existing['rates'][$key]['daily_cents'] / 100, 2, '.', '') : ''))),
            'period_days' => $period, 'free_days' => (int) $free
        ];
    }
    return $policy;
}
function af_fee_date(string $value): DateTimeImmutable {
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('UTC'));
    if (!$date || $date->format('Y-m-d') !== $value) throw new InvalidArgumentException('Enter a valid date.');
    return $date;
}
function af_estimate_fee(array $policy, string $category, string $recorded, string $handover): array {
    if (empty($policy['visible'])) throw new InvalidArgumentException('Billing tools are hidden for this station.');
    if (!array_key_exists($category, af_fee_categories())) throw new InvalidArgumentException('Select an item category.');
    $start = af_fee_date($recorded); $end = af_fee_date($handover);
    if ($end < $start) throw new InvalidArgumentException('Handover cannot be before the item was recorded.');
    $rate = $policy['rates'][$category] ?? [];
    if (!isset($rate['handling_cents'], $rate['daily_cents'])) throw new InvalidArgumentException('Rates are not configured for this category. Ask an administrator to set them.');
    $elapsed = (int) $start->diff($end)->days;
    $billable = max(0, $elapsed - (int) $rate['free_days']);
    $period = (int) ($rate['period_days'] ?? 1);
    $periods = (int) ceil($billable / $period);
    return ['currency' => $policy['currency'], 'category' => af_fee_categories()[$category],
        'recorded_date' => $recorded, 'handover_date' => $handover,
        'elapsed_days' => $elapsed, 'free_days' => (int) $rate['free_days'], 'billable_days' => $billable,
        'period_days' => $period, 'billable_periods' => $periods,
        'handling_cents' => (int) $rate['handling_cents'], 'daily_cents' => (int) $rate['daily_cents'],
        'storage_cents' => $periods * $rate['daily_cents'],
        'total_cents' => $rate['handling_cents'] + ($periods * $rate['daily_cents'])];
}
