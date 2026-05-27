# Git Workflow & Automated Deployment

This guide outlines the Git workflow, repository hygiene, and the GitHub-integrated automated deployment process for the AeroFind Cabin Portal.

---

## 1. Branching Strategy & Contribution Workflow

To maintain absolute codebase stability, team members must adhere to the following workflow:

1. **Branching**: Always create a short-lived feature or bugfix branch from the latest `main` branch.
   ```bash
   git checkout main
   git pull origin main
   git checkout -b feat/your-feature-name
   ```
2. **Development & Linting**: Keep changes atomic. Run local quality assurance checks (listed in [Local Development Checks](#4-local-development-checks)) before committing.
3. **PR Strategy**: Open a Pull Request (PR) targeting `main`. In your PR description:
   - Provide a clear summary of changes.
   - Include visual screenshots/videos for UI-related modifications.
   - Note any schema modifications or database behaviors.
   - Attach a risk note if the PR affects security-sensitive areas (e.g., authentication, database writes, file uploads).

---

## 2. Repository Git Hygiene

### Excluded Artifacts (`.gitignore`)
The codebase operates under a zero-leak policy for local, temporary, or production-sensitive data. The following files are **fully ignored** by Git and must never be committed:
- **Active Databases**: Local SQLite files (`cabin_db.sqlite`, `cabin_db_*.sqlite`).
- **Backups**: Dynamic station backup databases (`cabin_db_backup_*.sqlite`).
- **Temporary Uploads**: Photos, digital evidence, and test documents (`uploads/cabin_items/*`, `uploads/branding/*`).
- **Transactional Logs**: Raw outbound mail logs (`uploads/emails/`).
- **Distribution Packages**: Compiled obfuscated releases (`release/`).
- **Secrets**: Environment configuration overrides (`config.local.php`).

### Staging Rules
Before using `git commit`, run `git status` to verify that no ignored assets, SQLite databases, or local secrets are staged.

---

## 3. Automated Git-Based Deployment (`deploy.php`)

AeroFind features an **Automated Secure PHP Deployer** that pulls updates directly from the GitHub repository, bypassing FTP/SSH configurations.

### How it Works
1. **GitHub Release Hooks / Dashboard Trigger**: The deployment script (`deploy.php`) is fetched securely when triggered from the Staff Dashboard's Platform Update pane.
2. **Secure Token Authorization**: Access is gated via `DEPLOY_TOKEN`. The request must specify a valid token parameter:
   ```text
   https://your-domain.com/deploy.php?token=your_secure_deploy_token
   ```
3. **GitHub API Query**: The script queries the GitHub API to fetch the latest ZIP archive of the `main` branch:
   - **Public Repository URL**: `https://github.com/exeedthemes/cabin_portal/archive/refs/heads/main.zip`
   - **Private Repository URL**: `https://api.github.com/repos/exeedthemes/cabin_portal/zipball/main`
4. **Extraction & Update**: The ZIP archive is securely downloaded, verified, extracted via `ZipArchive` (or a system fallback `unzip`), and applied directly to overwrite the live files.
5. **Configuration Protection**: During update replication, **`db_config.php` and `config.local.php` are explicitly retained** to ensure active database configurations and API secrets are never overwritten.

### Configuring `deploy.php`
Open `deploy.php` and configure the following parameters:
- **`DEPLOY_TOKEN`**: A cryptographically secure, unique deployment password.
- **`GITHUB_PAT`**: If your repository is private, generate a classic or fine-grained GitHub Personal Access Token with read-only `repo` permissions and paste it here. Leave empty for public repositories.

### Dry-Run Update Simulation
To simulate an update without mutating live code, append `&simulate=1` or configure simulation mode:
```text
https://your-domain.com/deploy.php?token=your_secure_deploy_token&simulate=1
```
This performs a full dry-run logging download speed, extraction viability, and signature checks.

---

## 4. Local Development Checks

Always run these verification commands locally before committing or pushing changes:

```bash
# 1. Check all PHP files for syntax or compiler errors
find . -path './release' -prune -o -path './uploads' -prune -o -name '*.php' -print0 | xargs -0 -n1 php -l

# 2. Compile and verify Python utilities
python3 -m py_compile import_excel.py

# 3. Test release package generation
php build_release.php
```

---

## 5. Continuous Integration (CI)

A GitHub Actions workflow is defined under `.github/workflows/ci.yml`. On every PR and push to `main`, the runner:
- Provisions PHP 8.2 environments.
- Scans files for structural syntax checks.
- Validates Python spreadsheet-importer compatibility.
