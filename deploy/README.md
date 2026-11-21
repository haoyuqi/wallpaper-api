# Wallpaper service deployment

This Compose project runs the [wallpaper-web](https://github.com/haoyuqi/wallpaper-web) frontend, the wallpaper API, and exactly one scheduler. The frontend serves `/api` from the API over a private Compose network; the authentication service is deployed separately. This deployment does not download or store image binaries.

## Configure

Copy `.env.example` to `.env` in this directory and keep it private. The same file is the Compose variable file and the container environment file.

1. Set `APP_KEY` and `DB_PASSWORD`. Generate a key with `openssl rand -base64 32`, prefix it with `base64:`, and keep the same key across upgrades and restores.
2. Pin `WEB_VERSION` and `API_VERSION` to published `vX.Y.Z` tags for predictable upgrades and rollback.
3. Set `APP_URL` to the public HTTPS origin and `TRUSTED_PROXIES` to the exact CIDRs of the reverse proxies. Do not trust every address.
4. Leave `WEB_BIND=127.0.0.1`; a public reverse proxy should reach `web` and `api` through the ingress network instead of the published port.

`COMPOSE_PROFILES` selects bundled dependencies:

- `database,redis` starts the bundled PostgreSQL and Redis.
- An empty value uses external services. Set `DB_HOST`, `REDIS_HOST`, and the matching `*_NETWORK_EXTERNAL=true` and `*_NETWORK` settings.
- Select each dependency independently, for example `database` for bundled PostgreSQL with an external Redis.
- Do not enable a bundled profile while its network is external: a local database or cache would join the shared network. Empty `COMPOSE_PROFILES` when `POSTGRES_NETWORK_EXTERNAL` or `REDIS_NETWORK_EXTERNAL` is true.

The `postgres`, `redis`, and `ingress` networks can point at existing Docker networks. Set `POSTGRES_NETWORK_EXTERNAL=true` and `POSTGRES_NETWORK=infra-postgres-private` (and the equivalent Redis and ingress settings) to reuse them. The containers join the ingress network with the stable aliases `wallpaper-web-upstream` and `wallpaper-api-upstream`, so the reverse proxy does not depend on container names or published ports.

## Database migrations

Run the migration once per release before starting the new application version:

```sh
docker compose run --rm --no-deps api php artisan migrate --force
```

Never run a destructive rollback on production. Back up PostgreSQL with `pg_dump` before schema changes and prove the restore in a separate database.

## Start and verify

```sh
docker compose up -d
docker compose ps
```

With the bundled dependencies, `COMPOSE_PROFILES=database,redis` starts PostgreSQL and Redis in the same command. The API and scheduler restart until the database and cache are reachable. To start the dependencies explicitly:

```sh
docker compose --profile database --profile redis up -d
```

Check the frontend and the API end to end:

```sh
curl --fail http://localhost:8080/healthz
curl --fail 'http://localhost:8080/api/v1/wallpapers?date=2026-11-20'
docker compose exec api php -r 'exit(@file_get_contents("http://127.0.0.1:8080/up") === false ? 1 : 0);'
```

`/healthz` checks Nginx; the API `/up` checks that Laravel boots. Neither proves PostgreSQL availability or that yesterday's synchronization succeeded. Inspect `docker compose logs scheduler` and synchronization audit records for freshness.

The scheduler uses the same API image and runs Laravel `schedule:work`; it executes the existing daily UTC schedule. Its file-cache overlap lock lives in the persistent `scheduler_cache` volume. Keep exactly one Compose scheduler instance.

## Shared Redis

The API image includes the `redis` PHP extension. To use a shared Redis instance, set `CACHE_STORE=redis`, `REDIS_HOST`, `REDIS_USERNAME`, `REDIS_PASSWORD`, `REDIS_DB`, `REDIS_CACHE_DB`, `REDIS_PREFIX`, and `CACHE_PREFIX`. Use a dedicated ACL user, database number, and key prefix for this application; never reuse another application's database or prefix. Redis ACLs isolate keys by pattern, not by logical database. If Redis is unavailable, cache-backed request limiting fails closed with HTTP 500.

The default image remains usable without Redis: `CACHE_STORE=file` keeps rate-limit counters in the local file cache, which is sufficient for a single API container.

## Upgrade and rollback

1. Back up the database and record the current image digests.
2. Set the new `WEB_VERSION` and `API_VERSION`, pull, and run the migration step.
3. Recreate the services. Restart `web` after an API-only recreation because its Nginx upstream is resolved at startup:

```sh
docker compose up -d
docker compose restart web
```

The ingress vhosts used by this deployment resolve `wallpaper-api-upstream` dynamically with `resolver 127.0.0.11` and a variable `proxy_pass`, so they follow a recreated API container. If your reverse proxy resolves upstreams only at startup, reload it after recreating the API; in either case reload it after changing its own configuration.

To roll back, restore the previous image tags with the same `APP_KEY` and a schema that remains compatible. If the schema moved forward incompatibly, restore the database backup first. `docker compose down` preserves the named volumes; `down --volumes` deletes PostgreSQL, Redis, and scheduler data and must not be used for routine shutdowns.

## Operational notes

- PostgreSQL data lives in `postgres_data` when bundled; Redis AOF data lives in `redis_data`.
- Arrange TLS, firewall rules, monitoring, and a public reverse proxy separately.
- GHCR package visibility and access must be configured for the intended audience before another host pulls the images.
- The CI check validates this file with external and bundled dependencies; it does not prove a particular provider's network, TLS, or credential setup.
