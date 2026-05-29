# 🛫 AeroFind Cabin Recovery Portal

> **Lightweight, secure, and station-isolated cabin lost-and-found management platform.**

[![PHP Version](https://img.shields.io/badge/PHP-%3E%3D%208.1-777bb4?style=flat-square&logo=php)](https://www.php.net/)
[![Database](https://img.shields.io/badge/Database-SQLite-003b57?style=flat-square&logo=sqlite)](https://www.sqlite.org/)
[![WebServer](https://img.shields.io/badge/Web%20Server-Apache-d22128?style=flat-square&logo=apache)](https://httpd.apache.org/)
[![License](https://img.shields.io/badge/License-MIT-blue?style=flat-square)](LICENSE)

AeroFind Cabin Portal is an optimized PHP web application designed to manage cabin-specific lost-and-found workflows. It facilitates a passenger-facing search and claims system, an comprehensive staff operations dashboard, modular e-mail notification handling, and automatic database provisioning with station-specific SQLite databases.

---

## ✨ Core Capabilities

- 🧑‍💻 **Passenger Hub**: Clean, intuitive search interface for passengers to query found items or log lost properties with direct claim submission workflows.
- 📊 **Staff Dashboard**: A high-density dashboard for administrators and ground operators managing found items, claims, pending reports, airlines, and system parameters.
- 🗺️ **Station Isolation**: Fully localized, directory-resolving station context provisioning determined dynamically via request cookies, HTTP headers, query parameters, or `stations.json`.
- ⚙️ **Schema Auto-Provisioning**: Automated database creation and schema migration on the initial request, maintaining localizedSQLite instances per-airport code.
- ✉️ **Modular Mail Transport**: Fully separated, socket-supported SMTP (supporting SSL/TLS) or PHP `mail()` wrappers for seamless passenger updates and internal alerts.
- 🔒 **Enterprise Hardening**: Strict same-site CSRF validation, server-only API token gates (`config.local.php`), password minimum lengths, and extensive Apache `.htaccess` directory rules to protect files.
- 📦 **Automated Deployments**: A secure, GitHub-integrated live updater script (`deploy.php`) providing dry-run simulations and automated production syncs.

---

## 📂 Repository Layout

```text
.
├── index.php              # Public Passenger Hub & Claims Entry
├── staff.php              # Internal Staff Operations & Settings Console
├── api.php                # Token-Gated Core JSON API Gateway
├── public_api.php         # Same-Site CSRF Restricted Public Proxy
├── bootstrap.php          # Database resolution, settings, and session bootloader
├── mailer.php             # Branded mail generation & SMTP/Socket transport engine
├── privacy.php            # Compliance privacy notifications
├── impressum.php          # Compliance legal notice
├── build_release.php      # Local release packer & base64/gzip compiler
├── import_excel.py        # Python spreadsheet data migration script
├── stations.json          # Active airport station mappings
├── uploads/               # Attachment directory (fully blocked from script execution)
└── docs/                  # Unified project documentation suite (Setup, Git, Ops)
```

---

## 🚀 Quick Start Guide

To get a local development copy up and running, follow these steps:

### 1. System Requirements
Before setting up the project, make sure you have:
- **PHP 8.1** or newer
- PHP Extensions: `pdo_sqlite`, `sqlite3`, `zip`
- **Apache** with `mod_rewrite` enabled
- **Python 3.10+** (if executing the bulk spreadsheet importer `import_excel.py`)

### 2. Standard Installation

1. **Clone the repository**:
   ```bash
   git clone https://github.com/exeedthemes/cabin_portal.git
   ```
2. **Configure your Web Server**: Place the project folder inside your web server root (e.g. `htdocs` or `/var/www/html`).
3. **Set Permissions**: Ensure the web server has write access to the project root directory (for SQLite database generation) and the `uploads/` directory (for attachment storage).
4. **Initialize**: Open the site in your browser. Navigating to `index.php` or `staff.php` automatically provisions the target station's SQLite database.

> [!NOTE]
> Database creation occurs dynamically per-station on first run. All databases are securely locked and fully ignored from Git.

---

## ⚙️ Configuration & Variables

AeroFind features a secure cascading configuration architecture:

- **Station Settings**: Modify [stations.json](file:///Users/mandinu/Downloads/Projects/aerofind_v1.3.3_release/cabin_portal/stations.json) to add, remove, or modify active airport station identifiers:
  ```json
  {
    "MUC": "Munich Airport",
    "FRA": "Frankfurt Airport"
  }
  ```
- **API Protection**: Direct requests to `api.php` require a cryptographically secure token. The build script automatically provisions a strong key in `config.local.php`. Provide this token via the `Authorization: Bearer <secret>` or `X-API-Secret: <secret>` headers.
- **Transactional Emails**: Manage SMTP parameters (hosts, ports, encryption standards, authentication credentials) directly through the System Settings panel on the Staff Dashboard.

> [!WARNING]
> Keep `config.local.php`, local `.env` files, active database files (`.sqlite`), and generated backup archives out of source control.

---

## 🔍 Quality Verification

Run these validation processes locally before staging or pushing code updates:

```bash
# 1. Verify syntax and identify potential parser bugs across all PHP files
find . -path './release' -prune -o -path './uploads' -prune -o -name '*.php' -print0 | xargs -0 -n1 php -l

# 2. Pre-compile and validate the Python spreadsheet data importer
python3 -m py_compile import_excel.py

# 3. Compile and build the production-ready distribution package
php build_release.php
```

> [!TIP]
> The release builder (`build_release.php`) automatically creates a fully optimized, base64-encoded, and compressed production package located at `release/cabin_portal_obfuscated/` and a deployable ZIP.

---

## 📚 Unified Documentation Folder

Refer to these guides for comprehensive architecture, operations, and deployment procedures:

| Document | Purpose |
| :--- | :--- |
| 🗺️ **[System Architecture](architecture.md)** | Technical layout, mermaid sequence flowcharts, security boundaries, and data-resolution procedures. |
| ⚙️ **[Installation & Setup](setup.md)** | Detailed steps for server configurations, directory security rules, station SQLite mappings, and SMTP structures. |
| 🛡️ **[Deployment Checklist](deployment-checklist.md)** | Mandatory pre-deployment checklist, validation routines, server hardening, and post-deployment checks. |
| 🛠️ **[Operations Guide](operations.md)** | Maintenance routines, rate-limited automatic backups, and manual database backup & restoration instructions. |
| ⛓️ **[Git Workflow & Auto-Deploy](git.md)** | Detailed branching strategy, staging rules, CI workflow guides, and configuring the secure Git-based deployer (`deploy.php`). |
| 📜 **[Release Notes](RELEASE_NOTES.md)** | Full history of version releases, features, improvements, and system updates. |

---

## 📝 License

This project is licensed under the MIT License. See [LICENSE](LICENSE) for full details.
