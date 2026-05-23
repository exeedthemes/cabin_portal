# AeroFind Cabin Recovery Portal - Release Notes

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
