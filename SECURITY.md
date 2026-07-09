# Security Policy

## Reporting a Vulnerability

Please do not report security vulnerabilities in public issues.

Use a private channel with the maintainers and include:

- A clear description of the issue.
- Steps to reproduce.
- Impact assessment.
- Affected route, controller, template or configuration file.
- Any safe proof-of-concept details.

If no private security contact is configured for the public repository yet, open a minimal public issue asking for a security contact without disclosing technical details.

## Scope

Security-sensitive areas include:

- Authentication and session handling.
- Direct messages and private player data.
- Admin routes and moderation actions.
- CSRF handling.
- Database credentials and environment files.
- File uploads, avatars and external URLs.

## Secrets

Never commit:

- `.env` or `.env.*` except `.env.example`.
- `conf.php` or local config files with real credentials.
- Database dumps such as `db.sql`.
- API tokens, mail credentials or webhook URLs.
- Runtime storage, cache, logs or temporary files.

## Supported Versions

This public build is in active development. Security fixes should target the current main development branch unless maintainers state otherwise.
