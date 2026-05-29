# AeroFind Cabin Recovery Portal - Release Notes

## [1.8.6] - 2026-05-29

- **Cabin Portal Release Source**: Update checks now use `exeedthemes/cabin_portal` releases only.
- **Deploy Script Updates**: The generated release now includes `deploy.php`, and the installer preserves live `DEPLOY_TOKEN` and `GITHUB_PAT` values while updating the deploy script.
- **Release Package Integrity**: The obfuscated release zip remains free of `config.local.php` and includes runtime upload directories.

## [1.8.5] - 2026-05-28
### Professional Iconography & Update Success CLI Logs
- **Professional Iconography**: Replaced all decorative emojis (such as rockets `🚀` and bolts `⚡`) in the Platform Update interface, update notification banners, and installation terminal modals with premium, high-fidelity SVG vector icons (e.g., Cloud Sync and Bolt SVGs).
- **Update Success CLI Logs**: Integrated visual success logs and CLI output templates inside the deployment terminal and release notes to verify system updates.
  ```bash
  [Success] Simulation Deployment successful! Your site is fully updated to v1.8.5.
  ```

## [1.7.0] - 2026-05-24
### 🚀 Automated Multi-Station Database Backups & Isolated Restoration
- **Automated Multi-Station Backups**: Built a dynamic, per-station automatic SQLite backup utility. The background runner evaluates and replicates each station's active database independently into localized backup files (e.g., `cabin_db_backup_FRA.sqlite`), ensuring absolute data isolation across airport terminals.
- **Configurable Backup Intervals**: Introduced a new system setting, `database_backup_interval_days` (defaulting to 7 days, set to 0 to disable), which can be managed directly by administrators in the System Configuration panel.
- **Rate-Limited Background Execution**: Registered the automated backup hook within `bootstrap.php` to trigger asynchronously on web requests. The execution check is rate-limited via a lock-file system to run at most once per hour, ensuring zero performance impact on regular page loads.
- **Station-Aware Manual Backups & Restore**: Upgraded manual operations in the staff dashboard—such as Excel auto-sync backups and the manual database restore action—to correctly reference the active station's backup file rather than a single hardcoded master file.

## [1.6.0] - 2026-05-24
### 🚀 Multi-Station Architecture & Enterprise Security Hardening
- **Narrow Proxy API (`public_api.php`)**: Implemented a security-hardened proxy layer for all public/passenger interactions. The new passenger portal route restricts accessible actions exclusively to public-safe calls and enforces strict same-site CSRF validation, mitigating API exposure risks.
- **Server-Only API Access Protection**: Hardened direct `api.php` endpoints with a strong, auto-generated server-to-server API secret (`config.local.php`). External systems must authenticate via `Authorization: Bearer` or `X-API-Secret` headers.
- **Multi-Station & Courier Isolation**: Enhanced backend queries (`dbGetBDOCouriers`, staff dashboard filters) to strictly partition and isolate data dynamically per-station. Station-scoped staff and supervisors are locked into their active stations, preventing cross-station leakage.
- **Clean Code & Modular Mailer (`mailer.php`)**: Extracted and centralized all transactional email composition and transport components (supporting PHP `mail()` and direct socket SMTP TLS/SSL) into a dedicated module, optimizing application boot performance and cleaner isolation.
- **Dynamic Range Pagination**: Replaced the static relative row-count pagination footer with an intuitive, dynamic range indicator (e.g., displaying "21-30 / 145 rows" instead of "10/145 rows"), providing staff with absolute viewport visibility.
- **Harden Password Requirements**: Implemented strict, enterprise-ready password validation rules during initial admin provisioning requiring a minimum length of 12 characters.
- **Configuration & Directory Security**: Hardened `.htaccess` directives to fully restrict direct web access to `.env`, `config.local.php`, `bootstrap.php`, and sensitive local database or backup SQLite files.

## [1.5.1] - 2026-05-22
### 🚀 Visual Polish & Portal Cleanup
- **Purged Globe Emojis**: Removed the last colorful `🌐` emojis from both the passenger portal and the staff portal settings modals for a cleaner, modern interface.
- **Premium Switcher Vector Styling**: Implemented a beautiful, highly polished SVG Map Pin location vector icon within the passenger and staff station switcher buttons. This solves a mobile responsive layout issue where the switcher button rendered completely blank on small viewports due to hidden station labels.
- **Optimized Night Mode & Icon Syncing**: Hardened `toggleTheme()` and the dynamic `updateIcon()` logic in both portals to prevent visual flicker during page loads and ensure immediate, seamless theme state updates across all viewport sizes.

## [1.5.0] - 2026-05-18
### 🚀 New Features & Enhancements
- **Disposed Status Support**: Added a brand new "Disposed" status value to track discarded, scrapped, or destroyed items. Disposed rows are rendered with a beautiful glassmorphic amber/gold highlight and a matching amber badge.
- **Improved Excel Sync Parsing**: The Python-based Excel importer (`import_excel.py`) now dynamically parses both **Delivered** and **Disposed** item statuses from the Comments and Delivery Info columns in the Excel spreadsheet.
- **Robust Table Form Structure**: Solved the classic HTML browser table hoisting bug where hidden update/delete `<form>` elements were declared directly inside `<tbody>` as siblings of `<tr>`. Moving forms inside the first table data `<td>` element ensures all form inputs and button linkages submit flawlessly and securely without any browser parsing anomalies.
- **High-Density Dashboard Metrics**: The top of the staff console displays real-time statistics counters for all major item categories (Dashboard, Inventory, Lost Reports, Delivered).
- **Responsive Navigation Controls**: Integrated highly intuitive tabs ("Dashboard", "Inventory", "Lost Reports") which query and filter records dynamically on the fly.
- **Prefill IONOS SMTP Integration**: Streamlined setting up notifications with preconfigured IONOS host and port credentials.
- **Developer Contact Placeholder**: Added a hardcoded editable developer contact email placeholder for release handoff and support visibility.
- **Admin-Only Support & Settings**: Added an admin-only Support tab and restricted company/system settings edits to admin sessions.

### 🛠️ Bug Fixes & Technical Optimizations
- **Fixed Delete Error**: Resolved form target binding failures during delete requests by rectifying the HTML form hoisting layout issue.
- **Database Synchronization**: Successfully ran the importer and synced all **1,342 items** from the master Excel sheets into the SQLite database.
- **Image Zoom Overlay Close Behaviors**: Optimized backdrop controllers so clicking outside or hitting the close button instantly exits the full-screen visual modal.

---
*Developed with ❤️ by AeroFind Advanced Agentic Coding Team*
