<?php $fee_policy = af_fee_policy($db_settings); ?>
<?php if ($fee_policy['visible']): ?>
<dialog id="fee-estimator" class="fee-dialog">
    <form method="dialog"><button class="review-close" aria-label="Close fee estimate">×</button></form>
    <h2>Handover estimate</h2>
    <p>One-time handling fee by item category, plus storage billed daily, weekly or every 30 days after any free days. This is an estimate; it does not record a payment.</p>
    <?php if ($fee_policy['visible']): ?>
    <form id="fee-estimate-form">
        <label for="fee-reference">Found item reference</label>
        <input id="fee-reference" name="tag_no" placeholder="ID-0001" required>
        <label for="fee-category">Item category</label>
        <select id="fee-category" name="category"><option value="">Detect from item</option>
            <?php foreach (af_fee_categories() as $key => $label): ?><option value="<?= af_h($key) ?>"><?= af_h($label) ?></option><?php endforeach; ?>
        </select>
        <label for="fee-handover-date">Estimated handover date</label>
        <input type="date" id="fee-handover-date" name="handover_date" required>
        <button type="submit" class="review-submit">Calculate estimate</button>
    </form>
    <div id="fee-result" role="status" aria-live="polite"></div>
    <p class="fee-rule">Storage uses calendar days since the item was recorded. Same-day collection has 0 storage days. Each started billing period is charged in full after free days. The handover date minus the recorded date determines elapsed days.</p>
    <?php else: ?><p>Handover fee estimates are switched off for this station.</p><?php endif; ?>
</dialog>
<?php endif; ?>
<style>
    /* Styles apply only to the new billing controls; the existing UI is untouched. */
    .fee-dialog { width:calc(100% - 32px); background:var(--card); color:var(--text); border:1px solid var(--border); }
    .fee-dialog::backdrop { background:rgba(15,23,42,.6); }
    .fee-dialog h2 { font-weight:800; }
    .fee-dialog p { color:var(--secondary); }
    .fee-dialog label { display:block; margin:14px 0 8px; }
    .fee-dialog .review-close { float:right; font-size:20px; }
    .fee-dialog .review-submit, .fee-settings .review-submit { width:100%; margin-top:14px; color:white; }
    .fee-launch-icon { display:none; }
    .staff-header-actions .fee-launch { font-size:9px; font-weight:800; text-transform:uppercase; letter-spacing:1px; white-space:nowrap; }
    @media(max-width:640px) { .fee-launch-label { display:none; } .fee-launch-icon { display:inline; font-size:20px; } .staff-header-actions .fee-launch { padding:0!important; width:32px!important; min-width:32px; height:32px; } }

    .fee-dialog { max-width:700px; max-height:90dvh; overflow:auto; }
    .fee-dialog input:not([type="hidden"]):not([type="checkbox"]), .fee-dialog select, .fee-settings input:not([type="hidden"]), .fee-settings select { width:100%; padding:10px; border-radius:8px; background:var(--input); border:1px solid var(--border); color:var(--text); font-size:14px; }
    .fee-dialog .fee-rule { font-size:12px; margin-top:18px; }
    #fee-result { margin-top:18px; }
    .fee-breakdown { padding:16px; background:var(--input); border-radius:12px; }
    .fee-breakdown div { display:flex; justify-content:space-between; gap:16px; padding:6px 0; font-size:14px; }
    .fee-breakdown .fee-total { border-top:1px solid var(--border); margin-top:8px; font-size:20px; font-weight:800; }
    .fee-settings { border-top:1px solid var(--border); margin-top:20px; padding-top:16px; }
    .fee-settings { background:var(--input); padding:16px; border:1px solid var(--border); border-radius:14px; margin-bottom:16px; }
    .fee-settings label { display:block; margin:12px 0 8px; font-size:14px; font-weight:700; }
    .fee-settings summary { cursor:pointer; font-weight:700; }
    .fee-settings p { font-size:12px; margin:12px 0; }
    .fee-rates-scroll { overflow:auto; max-height:320px; border:1px solid var(--border); border-radius:8px; }
    .fee-rates thead { position:sticky; top:0; background:var(--card); z-index:1; }
    .fee-rates { width:100%; min-width:590px; border-collapse:collapse; font-size:12px; }
    .fee-rates th { text-align:left; padding:8px 5px; }
    .fee-rates td { padding:5px; width:18%; }
    /* Match the compact forms and rose accents used by the existing staff console. */
    .fee-dialog { padding:24px; border-radius:16px; max-width:560px; }
    .fee-dialog h2 { font-size:18px; text-transform:uppercase; letter-spacing:-.4px; margin-bottom:10px; }
    .fee-dialog p { font-size:11px; line-height:1.6; margin-bottom:14px; }
    .fee-dialog label, .fee-settings label { font-size:9px; font-weight:800; text-transform:uppercase; letter-spacing:.8px; color:var(--secondary); }
    .fee-dialog input:not([type="hidden"]), .fee-dialog select, .fee-settings input:not([type="hidden"]), .fee-settings select { font-size:11px; padding:8px 10px; border-radius:8px; }
    .fee-settings { padding:14px; background:var(--card); border-radius:12px; }
    .fee-settings summary { font-size:10px; font-weight:800; text-transform:uppercase; letter-spacing:1px; }
    .fee-settings p { font-size:10px; color:var(--secondary); }
    .fee-rates { font-size:10px; }
    .fee-rates td { border-top:1px solid var(--border); }
    .fee-rates th { font-weight:800; }
    .fee-dialog .review-submit, .fee-settings .review-submit { padding:10px 14px; font-size:10px; font-weight:800; text-transform:uppercase; letter-spacing:.8px; background:#f43f5e; border-radius:8px; }
    .fee-dialog .review-submit:hover, .fee-settings .review-submit:hover { background:#e11d48; }
    .fee-breakdown div { font-size:11px; }
    .fee-breakdown .fee-total { font-size:16px; }
    .fee-dialog input::placeholder, .fee-settings input::placeholder { color:var(--secondary); opacity:1; }
</style>
<script>
function filterFeeCategories(query) {
    const search = query.trim().toLowerCase();
    document.querySelectorAll('.fee-rates tbody tr').forEach(row => {
        row.hidden = !row.querySelector('th').textContent.toLowerCase().includes(search);
    });
}

function openFeeEstimator(tag = '') {
    const dialog = document.getElementById('fee-estimator');
    const form = document.getElementById('fee-estimate-form');
    if (!dialog || !form) return;
    form.reset();
    document.getElementById('fee-reference').value = tag;
    const now = new Date();
    document.getElementById('fee-handover-date').value = `${now.getFullYear()}-${String(now.getMonth()+1).padStart(2,'0')}-${String(now.getDate()).padStart(2,'0')}`;
    document.getElementById('fee-result').textContent = '';
    dialog.showModal();
}
document.getElementById('fee-estimate-form')?.addEventListener('submit', async event => {
    event.preventDefault();
    const output = document.getElementById('fee-result');
    const button = event.target.querySelector('button');
    const data = new FormData(event.target);
    data.set('action','estimate_fee'); data.set('csrf_token',window.CABIN_CSRF_TOKEN);
    button.disabled = true; output.textContent = 'Calculating…';
    try {
        const response = await fetch('staff.php', {method:'POST', body:data, signal:AbortSignal.timeout(15000)});
        const result = await response.json();
        if (!response.ok || !result.success) throw new Error(result.error || 'Could not calculate the estimate.');
        const estimate = result.estimate;
        output.replaceChildren();
        const box = document.createElement('div'); box.className = 'fee-breakdown';
        const money = cents => new Intl.NumberFormat(undefined,{style:'currency',currency:estimate.currency}).format(cents/100);
        const rows = [
            ['Category', estimate.category], ['Recorded on', estimate.recorded_date],
            ['Handover date', estimate.handover_date], ['Elapsed calendar days',estimate.elapsed_days],
            ['Free storage days',estimate.free_days], ['Handling',money(estimate.handling_cents)],
            [`Storage: ${estimate.billable_periods} × ${estimate.period_days}-day period at ${money(estimate.daily_cents)}`,money(estimate.storage_cents)],
            ['Estimated total',money(estimate.total_cents)]
        ];
        for (const [label,value] of rows) {
            const row = document.createElement('div');
            if(label === 'Estimated total') row.className = 'fee-total';
            const name = document.createElement('span'); name.textContent=label;
            const amount=document.createElement('span'); amount.textContent=value;
            row.append(name,amount);box.append(row);
        }
        output.append(box);
    } catch(error) { output.textContent=error.message; }
    finally { button.disabled=false; }
});
</script>
