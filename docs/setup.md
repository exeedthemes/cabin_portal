# Setup

## Local Setup

1. Install PHP 8.1 or newer with SQLite and Zip support.
2. Put the project behind Apache or another PHP-capable web server.
3. Confirm the web server can write to:
   - The project root for local SQLite files
   - `uploads/` for photos and email logs
4. Open `index.php` to initialize the passenger terminal.
5. Open `staff.php` to configure staff-facing settings.

## Apache Notes

The root `.htaccess` file:

- Disables directory listings
- Provides extensionless PHP routing
- Blocks direct access to database/config-like files
- Blocks direct access to `bootstrap.php`
- Adds common browser security headers

The tracked `uploads/.htaccess` file disables PHP execution in the upload directory.

## Station Setup

Stations are configured in `stations.json`:

```json
{
  "MUC": "Munich"
}
```

Each station uses its own SQLite file. `MUC` uses `cabin_db.sqlite`; other station codes use `cabin_db_CODE.sqlite`.

## Email Setup

The app can use PHP `mail()` or SMTP settings stored through the staff interface. Local copies of attempted emails may be written to `uploads/emails/` for troubleshooting. These files are ignored by Git.
