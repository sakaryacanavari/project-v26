# Contributing

Thanks for considering a contribution to Project V26. This project is a PHP/Slim/Twig web game, so the best contributions are small, focused and easy to review.

## Before You Start

- Open an issue or discussion for large changes.
- Keep pull requests focused on one topic.
- Do not include real `.env`, `conf.php`, database dumps or runtime files.
- Do not commit `vendor/`, `node_modules/`, logs, cache or build output.

## Development Flow

1. Fork or branch from the current main development branch.
2. Copy `.env.example` to `.env` and use local-only values.
3. Install dependencies only in your local environment.
4. Make the smallest change that solves the problem.
5. Test the touched area manually.
6. Open a pull request with a short summary and screenshots for UI changes.

## Code Style

- Follow the existing Slim controller and Twig template patterns.
- Avoid broad refactors in feature or bugfix PRs.
- Prefer theme tokens and existing CSS conventions for UI changes.
- Do not add mock UI for features without backend support.

## Pull Request Checklist

- [ ] The change is scoped and reviewable.
- [ ] No secrets or local dumps are included.
- [ ] Generated dependencies are not committed.
- [ ] UI changes include screenshots when relevant.
- [ ] Any new setting is actually persisted or clearly marked as planned.

## Reporting Bugs

Include:

- What happened.
- What you expected.
- Steps to reproduce.
- Browser and local environment details.
- Screenshot or short recording when useful.
