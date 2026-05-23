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
|-- api.php                # JSON API actions
|-- bootstrap.php          # Shared database, settings, session, and security helpers
|-- privacy.php            # Privacy notice
|-- build_release.php      # Local release package builder
|-- import_excel.py        # Spreadsheet import helper
|-- stations.json          # Station code/name map
|-- uploads/.htaccess      # Upload directory execution protection
|-- docs/                  # Setup, operations, and architecture notes
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
- Keep `.env`, SQLite files, uploads, release bundles, and email logs out of source control.

## Development Checks

Run syntax checks locally before pushing:

```bash
find . -path './release' -prune -o -path './uploads' -prune -o -name '*.php' -print0 | xargs -0 -n1 php -l
python3 -m py_compile import_excel.py
php build_release.php
```

`php build_release.php` requires a local `cabin_db.sqlite` because the release builder copies a clean database schema/settings snapshot into the release bundle.

## Documentation

- [Architecture](docs/architecture.md)
- [Setup](docs/setup.md)
- [Operations](docs/operations.md)
- [Deployment Checklist](docs/deployment-checklist.md)

## License

This project is licensed under the MIT License. See [LICENSE](LICENSE).
