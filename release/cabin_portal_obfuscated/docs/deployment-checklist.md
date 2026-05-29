# Deployment Checklist

## Before Deployment

- [ ] Confirm `.gitignore` excludes database, upload, and secret files while allowing the obfuscated release package under `release/`.
- [ ] Run PHP syntax checks.
- [ ] Run Python syntax checks.
- [ ] Verify `stations.json` contains the correct station list.
- [ ] Confirm `config.local.php` exists on the server, is not committed, and contains a strong `api_secret`.
- [ ] Use `AF_API_SECRET` only if overriding the server-only `config.local.php` secret for direct API integrations.
- [ ] Fill admin settings for legal company name, address, representative, contact email, register/VAT details, and privacy contact.
- [ ] Confirm airline contracts define whether the ground handler is processor, controller, or joint controller for each workflow.
- [ ] Confirm retention policy values for active records, closed records, and sensitive photos match airline/customer agreements.
- [ ] Confirm SMTP or PHP mail settings for the target server.
- [ ] Confirm server write permissions for SQLite and uploads.

## Server Hardening

- [ ] Keep `uploads/.htaccess` deployed.
- [ ] Confirm directory listing is disabled.
- [ ] Confirm direct access to SQLite files is blocked.
- [ ] Confirm `bootstrap.php` cannot be requested directly.
- [ ] Confirm `config.local.php` cannot be requested directly.
- [ ] Use HTTPS in production.

## After Deployment

- [ ] Open passenger terminal.
- [ ] Open staff dashboard.
- [ ] Submit a test lost report.
- [ ] Add a test found item.
- [ ] Send a test email.
- [ ] Confirm uploaded files are visible only through intended app flows.
- [ ] Open `privacy.php` and `impressum.php` and confirm there are no placeholder warnings.
- [ ] Confirm audit log entries are created for add/update/pickup/delete/purge workflows.
