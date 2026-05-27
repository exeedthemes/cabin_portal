# AeroFind Cabin Portal

AeroFind Cabin Portal is a lightweight PHP application for cabin lost-and-found workflows. It provides a public passenger terminal, a staff dashboard, airline/station configuration, photo uploads, email notifications, and SQLite-backed item/report tracking.

The repository intentionally does not include production databases, uploaded passenger files, release bundles, or local email logs.

## Features

- Passenger-facing lost item search and report submission
- Staff dashboard for found items, claims, pending reports, airlines, branding, and settings
- Multi-station database resolution through `stations.json`, query parameters, session, cookie, or `X-Station`
- Automatic SQLite schema provisioning on first run
- Configurable SMTP or PHP `mail()` delivery
- Local release builder for obfuscated deployable bundles
- Apache hardening through `.htaccess` files

## Repository Layout

```text
.
|-- index.php              # Passenger terminal
|-- staff.php              # Staff dashboard and admin workflows
|-- api.php                # Secret-protected JSON API actions
|-- public_api.php         # Narrow passenger proxy with CSRF protection
|-- bootstrap.php          # Shared database, settings, session, and security helpers
|-- privacy.php            # Privacy notice
|-- build_release.php      # Local release package builder
|-- import_excel.py        # Spreadsheet import helper
|-- stations.json          # Station code/name map
|-- uploads/.htaccess      # Upload directory execution protection
|-- docs/                  # Application documentation folder (all markdown docs)
`-- .github/               # CI and GitHub collaboration templates
```

## Requirements

- PHP 8.1 or newer
- PHP extensions: `pdo_sqlite`, `sqlite3`, `zip`
- Apache with `mod_rewrite` recommended
- Python 3.10 or newer for `import_excel.py`

## Quick Start

1. Clone the repository.
2. Serve the folder with PHP/Apache.
3. Make sure the web server can write to the project directory for SQLite creation and to `uploads/` for runtime files.
4. Open `index.php` for the passenger terminal or `staff.php` for staff workflows.

On first access, the app creates the required SQLite database locally. Database files are ignored by Git.

## Configuration

- Edit `stations.json` to add or rename supported stations.
- Use staff settings in the app to configure branding, SMTP, notification emails, and airline records.
- `build_release.php` creates `config.local.php` with a strong server-only API secret if one does not already exist.
- Direct `api.php` calls require the secret. Send it as `Authorization: Bearer <secret>` or `X-API-Secret: <secret>` from trusted server-side code.
- The passenger terminal uses `public_api.php`, which only exposes the passenger actions and requires same-site CSRF tokens for writes.
- Keep `.env`, SQLite files, uploads, release bundles, and email logs out of source control.

## Development Checks

Run syntax checks locally before pushing:

```bash
find . -path './release' -prune -o -path './uploads' -prune -o -name '*.php' -print0 | xargs -0 -n1 php -l
python3 -m py_compile import_excel.py
php build_release.php
```

`php build_release.php` creates a code-only release bundle. Runtime SQLite databases, uploads, email logs, and local backups are not included; the first staff visit provisions the database and asks for an admin account.

## Documentation

- [Architecture](architecture.md)
- [Setup](setup.md)
- [Operations](operations.md)
- [Deployment Checklist](deployment-checklist.md)
- [Git Workflow & Automated Deployment](git.md)
- [Release Notes](RELEASE_NOTES.md)

## License

This project is licensed under the MIT License. See [LICENSE](LICENSE).
