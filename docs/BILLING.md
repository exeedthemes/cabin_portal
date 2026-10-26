# Optional billing estimates and categories

The existing portal UI and workflows are preserved. The additions are limited to billing controls under Admin Settings → System Configuration, a handover estimate button, per-item estimate actions, and shared classification used by billing.

Administrators open Admin Settings → System Configuration → Billing visibility & fee rules and choose Hidden or Visible. Hiding billing preserves configured rates. Set a one-time handling fee, recurring storage fee (daily, weekly or every 30 days), and free storage days for each category. Blank amounts remain unconfigured; enter 0 for a free service.

Staff can estimate collection costs for Found or Claimed items, choose a handover date, and correct the suggested category. Storage uses elapsed calendar days since the recorded date. Each started billing period after free days is charged in full. These are estimates, not invoices or payments.

Thirty common item categories support English and common German descriptions. Specific compounds such as laptop bag and phone charger are recognized, and the first mentioned item takes priority over its contents. Category search makes the rate list easier to use. Existing configured general-item and electronics rates are retained when introducing specific categories.

Checks: `php tests/fees.php` and `php tests/categories.php`.
