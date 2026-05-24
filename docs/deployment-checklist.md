# Deployment Checklist

## Before Deployment

- [ ] Confirm `.gitignore` excludes database, upload, release, and secret files.
- [ ] Run PHP syntax checks.
- [ ] Run Python syntax checks.
- [ ] Verify `stations.json` contains the correct station list.
- [ ] Confirm `config.local.php` exists on the server, is not committed, and contains a strong `api_secret`.
- [ ] Use `AF_API_SECRET` only if overriding the server-only `config.local.php` secret for direct API integrations.
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
