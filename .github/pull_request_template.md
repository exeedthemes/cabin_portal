## Summary

Provide a clear and concise description of the changes proposed in this Pull Request.

---

## Verification Checklist

Please verify that your branch complies with the following staging checks:

- [ ] **Syntax Validation**: Run all PHP syntax checks successfully (`php -l`).
- [ ] **Python Compilation**: Checked the spreadsheet data importer tools (`python3 -m py_compile`).
- [ ] **Manual UI Testing**: Rendered and validated visual UI changes on desktop and mobile viewports where relevant.
- [ ] **Git Hygiene Safeguard**: Double-checked `git status` to ensure absolutely no local SQLite files (`.sqlite`), generated backups (`cabin_db_backup_*.sqlite`), transactional logs (`uploads/emails/`), or private secrets (`config.local.php`, including inside `release/`) are staged.

---

## Operational & Security Notes

*Describe any technical and operational dependencies affected by this update:*
- **Database/Schema**: Does this branch introduce new tables, database schemas, or query migrations?
- **Email Delivery**: Does this modify outbound SMTP configurations or PHP mail templates?
- **Security & Authorization**: Any impact on CSRF tokens, session handshakes, or administrative gates?
