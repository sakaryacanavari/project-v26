# Project V26

![Status](https://img.shields.io/badge/status-active%20development-brightgreen)
![License](https://img.shields.io/badge/license-MIT-blue)
![Stack](https://img.shields.io/badge/stack-PHP%20%7C%20Slim%20%7C%20Twig%20%7C%20Docker-purple)

Project V26 is a browser-based political strategy and social simulation game built with PHP, Slim and Twig. The project focuses on a public, playable web experience where players manage identity, resources, communication, news and community-driven progression.

> Public build note: this repository is prepared for open source review and contribution. Private production secrets, local database dumps and runtime files must not be committed.

## What Is Project V26?

Project V26 is a web game inspired by nation, economy and community systems. It combines player progression, messaging, newspapers, public shouts, onboarding goals and dashboard-style game management in a single interface.

## Game Goal

Players grow their profile, interact with other players, follow public news, manage resources and take part in the wider game world. The main objective is to create a living social strategy environment where player actions, communication and public content shape the experience.

## Features

- Player dashboard with quick actions and resource status.
- News and newspaper flow with categories and sharing.
- Shouts feed for public player communication.
- Direct messages and privacy-oriented settings.
- User settings for profile, security, theme, language and game experience.
- Onboarding route for new players.
- Admin/system notice support for important game announcements.
- Docker-friendly local development setup.

## UI Preview

### Main Navigation

Main navigation and favorite quick-access actions.

![Project V26 Navigation](docs/images/header-navigation.png)

### News and Onboarding

News feed, category filters and new player route.

![Project V26 News](docs/images/home-news.png)

![Project V26 New Player Path](docs/images/new-player-path.png)

### Community

Public shouts feed and notification/DM center.

![Project V26 Shouts Feed](docs/images/shouts-feed.png)

![Project V26 Notifications and DM](docs/images/notifications-dm.png)

### Market and Economy

Product market and economy modules.

![Project V26 Product Market](docs/images/product-market.png)

### Crate and Upgrade Systems

Crate opening and item upgrade interfaces.

![Project V26 Heavy Weapons Crate](docs/images/heavy-weapons-crate.png)

![Project V26 Item Upgrader](docs/images/item-upgrader.png)

## Tech Stack

- PHP 8.0 runtime image for Docker.
- Slim 3 application structure.
- Twig templates.
- Illuminate Database components.
- Apache with rewrite support.
- MySQL/MariaDB compatible database layer.
- Legacy Grunt/Sass asset tooling.

## Docker Setup

Requirements:

- Docker Desktop
- Git
- Composer, only if you install dependencies outside Docker

Quick local setup:

```bash
git clone <repository-url>
cd project-v26
cp .env.example .env
docker compose up -d --build
```

Then open:

```text
http://localhost:8080
```

The included Compose setup starts:

- `app` on `http://localhost:8080`
- `mysql` exposed locally on port `3307`

Useful development commands:

```bash
docker compose ps
docker compose logs -f app
docker compose logs -f mysql
docker compose exec app composer install
docker compose down
```

If you use local-only Docker overrides, keep secrets and machine-specific settings out of commits.

## Local Setup

Use this path when you are not relying on the provided Compose workflow.

1. Clone the repository and enter the project directory.
2. Copy `.env.example` to `.env`.
3. Fill in local database values.
4. Install PHP dependencies:

```bash
composer install
```

5. Start the app with Docker or your local Apache/PHP setup.
6. Import a development database only from a safe local dump. Do not commit dumps such as `db.sql`.

## Development Status

Project V26 is in active early development. The public repository is prepared for review and contribution, but some systems are still being stabilized or planned.

Before contributing:

- Check [ROADMAP.md](ROADMAP.md) and existing issues first.
- Keep planned features separate from backend-supported features.
- Do not commit fake secrets, local database dumps, runtime files or generated dependencies.
- Prefer small changes that are easy to review.

## Troubleshooting

### Docker does not start

Make sure Docker Desktop is running, then check service status:

```bash
docker compose ps
docker compose logs -f app
```

### Port 8080 is already in use

Change the `app` port mapping in your local Compose override or stop the process using port `8080`. Do not commit machine-specific port changes unless the default changes for everyone.

### Database connection fails

The Compose app container connects to MySQL with `DB_HOST=mysql`, `DB_DATABASE=proje`, `DB_USERNAME=proje` and `DB_PASSWORD=proje`. If you run PHP outside Docker, use your local database host and port instead.

### `.env` is missing

Copy the example file:

```bash
cp .env.example .env
```

Then adjust only local values. Never commit real secrets.

### Composer dependencies are missing

Install dependencies inside the app container:

```bash
docker compose exec app composer install
```

### Cache, logs or runtime files cause issues

Remove only local runtime output that your environment created. Do not commit `logs/`, cache folders, database dumps, `vendor/` or `node_modules/`.

## Project Structure

```text
app/                 Application controllers, services and system helpers
templates/           Twig views
lang/                Translation files
public assets        Images, CSS/JS assets depending on local layout
.docker/             Docker Apache/PHP configuration
Dockerfile           PHP/Apache development image
composer.json        PHP dependencies
package.json         Legacy frontend tooling metadata
```

## Roadmap

See [ROADMAP.md](ROADMAP.md) for the current public roadmap.

Near-term focus:

- Stabilize public onboarding and main dashboard UX.
- Improve message, notification and privacy settings.
- Continue separating real backend-supported settings from planned features.
- Prepare safer development fixtures and setup documentation.

## Contributing

Contributions are welcome when they are small, reviewable and aligned with the current architecture. Before opening a pull request, read [CONTRIBUTING.md](CONTRIBUTING.md).

Recommended contribution style:

- Keep changes scoped.
- Avoid committing secrets, dumps or generated dependencies.
- Prefer existing Slim/Twig patterns.
- Include screenshots for UI changes when possible.

## Security

Please do not open public issues for vulnerabilities. See [SECURITY.md](SECURITY.md) for responsible reporting guidance.

## Content Creation / Public Build

Creators may use the public build for videos, streams, screenshots and educational walkthroughs as long as private server credentials, player private data and unpublished admin-only workflows are not exposed.

When sharing public content:

- Use local/demo data where possible.
- Blur private messages, tokens and admin-only screens.
- Mention that the project is in active development.

## License

This project currently includes an MIT license. See [LICENSE](LICENSE).
