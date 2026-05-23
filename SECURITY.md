# Security Policy

## Supported Version

The private `main` branch is the supported development line.

## Reporting a Vulnerability

Report security issues privately to the repository owner instead of opening a public issue.

Include:

- Affected file or workflow
- Steps to reproduce
- Expected and actual behavior
- Any known exposure of passenger data, staff credentials, uploaded files, or email logs

## Data Handling Notes

This repository must not contain production SQLite databases, uploaded passenger files, generated email logs, credentials, or release bundles. The `.gitignore` file is configured to exclude those artifacts.
