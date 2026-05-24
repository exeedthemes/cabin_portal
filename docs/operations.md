# Operations

## Routine Checks

- Confirm the site can create and update the active SQLite database files.
- Confirm upload directories are writable but do not execute PHP (`uploads/.htaccess` is properly active).
- Confirm staff notification and passenger email delivery via modular `mailer.php` (check `uploads/emails/` for raw outbound logs during debugging).
- Monitor automated backup lock-files (`uploads/.last_backup_check`).

## Release Build

Run the following builder command to generate fully optimized and compiled builds:

```bash
php build_release.php
```

The release builder compiles:
- `release/cabin_portal_obfuscated/`
- `release/cabin_portal_obfuscated.zip`

Both are automatically ignored by Git.

## Backup & Restoration Guidelines

### 1. Automated Database Backups
- The application automatically takes incremental, station-isolated backups of each airport station database into a file named `cabin_db_backup_CODE.sqlite` (e.g., `cabin_db_backup_FRA.sqlite`).
- **Trigger**: Backups are run asynchronously during incoming HTTP requests to prevent background job daemon overhead.
- **Rate-Limiting**: Controlled via the file lock `uploads/.last_backup_check` to run at most **once per hour**, ensuring zero performance overhead.
- **Interval**: Configured per-station via the `database_backup_interval_days` setting in the System Settings panel (default: `7` days, set to `0` to disable).

### 2. Manual Backup & Restore Operations
- **Manual Backups**: Administrators can trigger real-time, station-scoped backups from the staff console.
- **Station Restoration**: Administrators can restore the active database of a station using the local restore checkpoint file (`cabin_db_backup_CODE.sqlite`).
- **Important**: These backup files reside locally under the project directory. They are fully ignored by Git and should be archived off-site periodically.
- Never commit any SQLite files (`.sqlite`) or active lock-files to the repository.

