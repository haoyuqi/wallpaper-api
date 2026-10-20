#!/usr/bin/env bash
set -euo pipefail

# Run only against a disposable CI container and database, never production.
container_name=${1:?Pass the disposable container name}
smoke_url=http://127.0.0.1:8080
smoke_dir=$(mktemp -d)
trap 'rm -f "$smoke_dir/body.json" "$smoke_dir/headers"; rmdir "$smoke_dir"' EXIT

test "$(docker exec "$container_name" id -u)" != 0
docker exec "$container_name" test ! -e .env
docker exec "$container_name" test ! -d storage/bing-wallpaper
docker exec "$container_name" test ! -e vendor/bin/phpunit
docker exec "$container_name" test ! -w bootstrap/app.php
docker exec "$container_name" test -w bootstrap/cache
docker exec "$container_name" php artisan migrate --force --no-interaction
docker exec "$container_name" php artisan config:cache
docker exec "$container_name" php artisan route:cache
docker exec "$container_name" php artisan schedule:list

for attempt in {1..30}; do
    if curl --fail --silent --output /dev/null "$smoke_url/up"; then
        break
    fi
    sleep 1
done
curl --fail --silent --output /dev/null "$smoke_url/up"

curl --fail --silent "$smoke_url/api/v1/wallpapers?from=0001-01-01&to=9999-12-31" > "$smoke_dir/body.json"
docker exec -i "$container_name" php -r '
$body = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
if ($body !== ["data" => [], "meta" => ["query" => ["date" => null, "from" => "0001-01-01", "to" => "9999-12-31"], "count" => 0]]) {
    exit(1);
}
' < "$smoke_dir/body.json"

test "$(curl --silent --output "$smoke_dir/body.json" --write-out '%{http_code}' "$smoke_url/api/v1/wallpapers?date=invalid")" = 422
docker exec -i "$container_name" php -r '
$body = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
exit(($body["error"]["code"] ?? null) === "VALIDATION_ERROR" ? 0 : 1);
' < "$smoke_dir/body.json"

# Confirm real server responses also preserve CORS and rate-limit headers.
curl --fail --silent --dump-header "$smoke_dir/headers" --output /dev/null \
    --header 'Origin: https://gallery.example' "$smoke_url/api/v1/wallpapers?date=2026-10-01"
grep -qi '^Access-Control-Allow-Origin: \*' "$smoke_dir/headers"
grep -qi '^X-RateLimit-Limit: 60' "$smoke_dir/headers"

# A database failure must remain safe through the production web server.
docker exec "$container_name" php artisan migrate:rollback --force --no-interaction
test "$(curl --silent --output "$smoke_dir/body.json" --write-out '%{http_code}' "$smoke_url/api/v1/wallpapers?date=2026-10-01")" = 500
docker exec -i "$container_name" php -r '
$body = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
exit($body === ["error" => ["code" => "INTERNAL_ERROR", "message" => "An internal error occurred."]] ? 0 : 1);
' < "$smoke_dir/body.json"
docker exec "$container_name" php artisan migrate --force --no-interaction
printf 'Production container smoke checks passed.\n'
