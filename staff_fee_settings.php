<?php $fee_policy = af_fee_policy($db_settings); ?>
    <details class="fee-settings"><summary>Billing visibility &amp; fee rules</summary>
        <form method="POST">
            <?= af_csrf_input() ?><input type="hidden" name="action" value="save_fee_policy">
            <label for="billing-visibility">Billing tools in the staff workspace</label>
            <select id="billing-visibility" name="billing_visibility"><option value="hidden" <?= !$fee_policy['visible'] ? 'selected' : '' ?>>Hidden</option><option value="visible" <?= $fee_policy['visible'] ? 'selected' : '' ?>>Visible</option></select>
            <p>Only administrators control visibility. Hiding billing removes its buttons and panels from the staff workspace and keeps your saved rates.</p>
            <label for="fee-currency">Currency</label>
            <select id="fee-currency" name="currency"><?php foreach (['EUR','GBP','USD','CHF'] as $currency): ?><option <?= $fee_policy['currency'] === $currency ? 'selected' : '' ?>><?= af_h($currency) ?></option><?php endforeach; ?></select>
            <p>Blank amounts mean unconfigured. Enter 0 explicitly for a free service. Rates apply to this station.</p>
            <label for="fee-category-search">Find a category</label>
            <input type="search" id="fee-category-search" placeholder="Search categories…" oninput="filterFeeCategories(this.value)">
            <div class="fee-rates-scroll"><table class="fee-rates"><thead><tr><th>Category</th><th>Handling</th><th>Storage fee</th><th>Billing period</th><th>Free days</th></tr></thead><tbody>
            <?php foreach (af_fee_categories() as $key => $label): $rate = $fee_policy['rates'][$key]; ?>
                <tr><th scope="row"><?= af_h($label) ?></th>
                    <?php foreach (['handling' => 'handling_cents', 'daily' => 'daily_cents'] as $field => $value): ?>
                    <td><input type="number" step="0.01" min="0" max="999999.99" name="<?= $field ?>[<?= $key ?>]" aria-label="<?= af_h($label . ' ' . $field) ?>" value="<?= $rate[$value] === null ? '' : number_format($rate[$value] / 100, 2, '.', '') ?>" placeholder="Not set"></td>
                    <?php endforeach; ?>
                    <td><select name="period_days[<?= $key ?>]" aria-label="<?= af_h($label) ?> billing period"><?php foreach ([1 => 'Daily', 7 => 'Weekly', 30 => 'Every 30 days'] as $days => $period_label): ?><option value="<?= $days ?>" <?= $rate['period_days'] === $days ? 'selected' : '' ?>><?= af_h($period_label) ?></option><?php endforeach; ?></select></td>
                    <td><input type="number" min="0" max="3650" step="1" name="free_days[<?= $key ?>]" aria-label="<?= af_h($label) ?> free days" value="<?= (int) $rate['free_days'] ?>" required></td>
                </tr>
            <?php endforeach; ?>
            </tbody></table></div>
            <button type="submit" class="review-submit">Save station fee rules</button>
        </form>
    </details>
