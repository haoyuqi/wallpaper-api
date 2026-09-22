# Wallpaper API

A minimal Laravel 13 API foundation providing wallpaper metadata services.

## Requirements

- PHP `^8.3`
- Laravel `^13.17`

## Development Environment

All PHP and Composer operations are run inside the Laradock workspace container. No host PHP or Composer installation is required or supported.

From the Laradock directory (`~/Developer/laradock`):

```bash
docker compose exec -T -u laradock -w /var/www/wallpaper-api workspace <command>
```

Example development commands:

```bash
# Validate Composer configuration
docker compose exec -T -u laradock -w /var/www/wallpaper-api workspace composer validate --strict

# Run code style checks
docker compose exec -T -u laradock -w /var/www/wallpaper-api workspace vendor/bin/pint --test

# Run test suite
docker compose exec -T -u laradock -w /var/www/wallpaper-api workspace php artisan test
```

## Health Check

The service exposes the framework-native health check endpoint:

- `GET /up`: Returns HTTP 200 when the application boots successfully.

## Architecture & Scope

This repository provides an API-only service foundation:

- No frontend views, assets, or Node/Mix/Vite runtimes are included.
- No session or default authentication scaffolding is loaded.
- Wallpaper ingestion, database persistence, and public query endpoints are implemented in upcoming milestones.
- The frozen v1 API and ingestion contract is documented in [docs/contracts/v1.md](docs/contracts/v1.md).

## Archived Data

The 321 historical daily wallpaper images under `storage/bing-wallpaper/` remain archived in this repository for historical preservation but are not accessed or modified by the application runtime.
