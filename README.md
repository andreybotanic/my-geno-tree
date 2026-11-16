# Install MyGenoTree

From the root of a fresh checkout, use a shell with OpenSSL and Docker Compose v2.
Create the local credentials once:

```sh
(
  set -eC
  umask 077
  exec > .env
  for key in APP_SECRET POSTGRES_PASSWORD RABBITMQ_PASSWORD MERCURE_JWT_SECRET CLI_PROXY_API_KEY; do
    value=$(openssl rand -hex 32)
    printf '%s=%s\n' "$key" "$value"
  done
  printf 'LOCAL_UID=%s\nLOCAL_GID=%s\n' "$(id -u)" "$(id -g)"
)
```

Install dependencies, apply the database migrations and start the project:

```sh
docker compose run --rm backend composer install --no-interaction
docker compose run --rm frontend npm ci
docker compose run --rm backend php bin/console doctrine:migrations:migrate --no-interaction
docker compose up -d
```
