# Operations

## Routine Checks

- Confirm the site can create and update the SQLite database.
- Confirm upload directories are writable but do not execute PHP.
- Confirm staff notification and passenger email delivery.
- Back up SQLite databases outside this repository.
- Review `uploads/emails/` during email troubleshooting, then clean it when no longer needed.

## Release Build

Run:

```bash
php build_release.php
```

The release builder creates:

- `release/cabin_portal_obfuscated/`
- `release/cabin_portal_obfuscated.zip`

The release output is ignored by Git.

## Backup Guidance

Back up these runtime files outside Git:

- `cabin_db.sqlite`
- `cabin_db_CODE.sqlite`
- `uploads/cabin_items/`
- `uploads/branding/`

Never commit those files to this repository.
