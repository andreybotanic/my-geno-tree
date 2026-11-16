# Development

The configured Symfony AI agent invokes native PHP tools directly.

Backend/API tests cover ownership, session isolation, persisted confirmations,
Stop/provider failures, stale attempts, ETag chains and conflicts, atomic actions,
MCP elicitation and token accounting through the configured agent. Fixtures verify
domain integration; browser scenarios exercise the live model separately.

Run all application tooling inside its service container:

```bash
docker compose exec backend composer test
docker compose exec frontend npm test
```

Run the code-quality checks with:

```bash
docker compose exec backend composer quality
docker compose exec frontend npm run quality
```

Enable the versioned pre-commit hook once after cloning the repository:

```bash
git config core.hooksPath .githooks
```

The hook runs the backend, frontend and voice gateway quality commands through Docker Compose
and blocks the commit when any formatter, static-analysis, lint, or type check
fails. The Compose services must be running before committing.

## Demo fixtures

The optional fixtures add fictional trees to the local account `demo`. Set `DEMO_PASSWORD` (at least eight characters) in the ignored `backend/.env.local` before creating that account. Loading the fixtures again preserves an existing account’s password.

To add the demo account and tree to an existing local database without
removing current data, run:

```bash
docker compose exec backend php bin/console doctrine:fixtures:load --append --no-interaction
```

Do not run `doctrine:fixtures:load` without `--append` against a database that
contains data you need to keep: Doctrine purges it by default.

## Existing installations

Keep existing PostgreSQL and RabbitMQ volumes paired with their current passwords. When upgrading an older checkout, add `POSTGRES_PASSWORD` and `RABBITMQ_PASSWORD` to the ignored root `.env` using the passwords currently configured in those services. A changed environment variable alone does not rotate an existing database or broker account. Generate a fresh `APP_SECRET` with `openssl rand -hex 32` and add it to the same file. Keep the existing Mercure, CLI proxy and OAuth keys. Recreate the services with `docker compose up -d` after editing the file.

## Local HTTPS

If ProjectHost or another local proxy manages HTTPS, use the address it provides.
For the bundled Caddy proxy, the default address is <https://my-geno-tree.localhost>.
To trust its local CA, export the public certificate and import it into your browser
or operating system:

```sh
docker compose cp proxy:/data/caddy/pki/authorities/local/root.crt ./mygenotree-local-ca.crt
```

On Windows, run this in PowerShell from the checkout directory:

```powershell
Import-Certificate -FilePath .\mygenotree-local-ca.crt -CertStoreLocation Cert:\CurrentUser\Root
```

## Running a second checkout

Choose an unused Compose project name and host ports. Export them before following
the installation guide, then append the same settings to the new checkout's root
`.env` so later commands select the same containers and volumes.

```sh
export COMPOSE_PROJECT_NAME=mygenotree-copy
export HTTPS_PORT=8443 POSTGRES_PORT=15433 REDIRECT_PORT=17081 OAUTH_CALLBACK_PORT=11455
export APP_ORIGIN=https://my-geno-tree.localhost:$HTTPS_PORT
```
