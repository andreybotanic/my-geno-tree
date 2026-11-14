# Install MyGenoTree locally

Use Docker with Compose v2, Git, a Bash shell and OpenSSL. On Windows, run
the shell commands in WSL 2 with Docker Desktop integration enabled. Keep the
checkout in the Linux filesystem. The first install downloads images, locked
dependencies and the document embedding model.

Clone the repository and change into its root directory. Run the remaining
commands from that directory. The default installation uses ports 443, 5433,
7081 and 1455. For a second checkout on the same machine, select a unique
Compose project name and unused ports **before running any Compose commands**:

```bash
# Only for a second installation
export COMPOSE_PROJECT_NAME=mygenotree-copy
export HTTPS_PORT=8443 POSTGRES_PORT=15433 REDIRECT_PORT=17081 OAUTH_CALLBACK_PORT=11455
export APP_ORIGIN=https://my-geno-tree.localhost:$HTTPS_PORT
```

## 1. Create local credentials

Run once in a fresh checkout. This creates a private `.env` and refuses to
overwrite an existing file. Keep it with the volumes for this installation.
On a second installation, the file also saves the selected ports and project name.

```bash
(
  set -euC
  umask 077
  {
    for key in APP_SECRET POSTGRES_PASSWORD RABBITMQ_PASSWORD MERCURE_JWT_SECRET CLI_PROXY_API_KEY; do
      value=$(openssl rand -hex 32)
      printf '%s=%s\n' "$key" "$value"
    done
    printf 'LOCAL_UID=%s\nLOCAL_GID=%s\n' "$(id -u)" "$(id -g)"
    for key in COMPOSE_PROJECT_NAME HTTPS_PORT POSTGRES_PORT REDIRECT_PORT OAUTH_CALLBACK_PORT APP_ORIGIN; do
      if [[ -n ${!key:-} ]]; then printf '%s=%s\n' "$key" "${!key}"; fi
    done
  } > .env
)
```

## 2. Install dependencies and initialize storage

```bash
docker compose build
docker compose run --rm backend composer install --no-interaction
docker compose run --rm --no-deps frontend npm ci
docker compose run --rm backend php bin/console league:oauth2-server:generate-keypair --skip-if-exists --no-interaction
docker compose run --rm backend sh -ec '
  umask 077
  test -s config/jwt/encryption.key || openssl rand -hex 32 > config/jwt/encryption.key
  chown root:www-data config/jwt/private.pem config/jwt/encryption.key
  chmod 640 config/jwt/private.pem config/jwt/encryption.key
'
docker compose run --rm backend php bin/console doctrine:migrations:migrate --no-interaction
docker compose up -d qdrant rabbitmq ollama
docker compose run --rm backend php bin/console ai:store:setup documents
docker compose up -d
```

The final command waits for Ollama to download `qwen3-embedding:0.6b` before
starting the document worker. On subsequent starts, run `docker compose up -d`
in the same checkout.

## 3. Trust local HTTPS and open the application

Export the public root certificate created by this installation:

```bash
docker compose cp proxy:/data/caddy/pki/authorities/local/root.crt ./mygenotree-local-ca.crt
```

Import that file as a trusted root in the browser or operating system that will
open the application. On Windows, run the following in PowerShell from the
checkout directory:

```powershell
Import-Certificate -FilePath .\mygenotree-local-ca.crt -CertStoreLocation Cert:\CurrentUser\Root
```

To check the installation from the shell:

```bash
curl --fail --cacert mygenotree-local-ca.crt "${APP_ORIGIN:-https://my-geno-tree.localhost}/api/v1/health"
```

Expect `{"status":"ok"}`. Open <https://my-geno-tree.localhost> (use the
selected HTTPS port for a second installation) and register your account.

For optional integrations, follow the setup steps for [map providers](docs/maps.md),
[AI chat sign-in](docs/chat.md#cliproxyapi-in-docker-compose) or [voice recognition](docs/voice.md).
