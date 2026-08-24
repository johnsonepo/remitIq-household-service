# Deployment Guide

How to deploy RemitIQ Household Service to production.

---

## 1. Prerequisites

- A server (Linux) with Docker and Docker Compose installed
- A domain pointing at the server (optional, for TLS later)
- Access to the GitHub repo with permission to add secrets

---

## 2. First-time server setup

**2.1. Clone the repo on the server**

```bash
git clone https://github.com/<your-org>/remitiq-household-service.git
cd remitiq-household-service
```

**2.2. Create the production env file**

```bash
cp .env.production.example .env
```

> **Why `.env`, not `.env.production`?** Docker Compose auto-loads a file literally named `.env` in the project directory to fill in every `${VARIABLE}` placeholder in `docker-compose.yml` — it won't look for any other filename unless you pass `--env-file` on every command. Laravel itself never reads this file inside the container at all; its config comes entirely from the real environment variables Docker injects via the `environment:` block for each `-prod` service. Since this server only ever runs the `-prod` services, there's no conflict with the dev setup to worry about.

Open `.env` and fill in every blank value (DB password, Redis password, `APP_KEY`, mail credentials, notification service key, etc.). Generate `APP_KEY` with:

```bash
docker run --rm -v $(pwd):/app -w /app composer:2 php artisan key:generate --show
```

Copy the output into `APP_KEY=` in `.env`.

**2.3. Generate JWT keys**

```bash
mkdir -p storage/app/keys
openssl genrsa -out storage/app/keys/jwt-private.pem 2048
openssl rsa -in storage/app/keys/jwt-private.pem -pubout -out storage/app/keys/jwt-public.pem
```

**2.4. Log in to the container registry**

```bash
docker login ghcr.io -u <your-github-username>
```
(Use a GitHub Personal Access Token with `read:packages` scope as the password.)

---

## 3. Choose how you'll deploy

### 3a. Automatic (via GitHub Actions)

Push to `main` and GitHub builds + deploys for you. Requires secrets set in the repo: **Settings → Secrets and variables → Actions**:

| Secret | Value |
|---|---|
| `PRODUCTION_HOST` | Server IP or hostname |
| `PRODUCTION_USER` | SSH username |
| `PRODUCTION_SSH_KEY` | Private SSH key for that user |
| `PRODUCTION_SSH_PORT` | SSH port (optional, defaults to 22) |
| `PRODUCTION_APP_PATH` | Absolute path to the repo on the server, e.g. `/home/deploy/remitiq-household-service` |
| `GHCR_USERNAME` | GitHub username the server uses to pull images |
| `GHCR_TOKEN` | GitHub PAT with `read:packages` scope |

If you use this method, skip 3b and go to Section 4.

### 3b. Manual only (no GitHub Actions, no GHCR)

Build the image directly on the server instead — nothing needs pushing anywhere, and no GitHub secrets are required.

```bash
docker build --target production -t ghcr.io/your-org/remitiq-household-service:local .
```

(The `ghcr.io/your-org/...` name doesn't need to be real or reachable — Docker only contacts a registry if the image isn't already present locally. Since you just built it locally, it'll use that copy.)

In your `.env` (created in step 2.2), set:

```
IMAGE_NAME=your-org/remitiq-household-service
IMAGE_TAG=local
```

Leave `REGISTRY` out of `.env` entirely so it falls back to its default (`ghcr.io`), matching the tag you just built.

Whenever you deploy an update, `git pull` the latest code and re-run the `docker build` command above before restarting the services in Section 4.

---


## 4. Deploy

### Automatic (normal case)

Push to `main`. GitHub Actions will:
1. Run tests, Pint, PHPStan
2. Build and push the Docker image
3. SSH into the server and deploy it

Watch progress under the **Actions** tab in GitHub.

### Manual (first deploy, if CI is down, or if using method 3b)

If you're using **3a**, pull the image built by CI:

```bash
cd /path/to/remitiq-household-service

docker compose pull household-service-prod queue-prod scheduler-prod nginx-prod
```

If you're using **3b**, skip the `pull` — you already built the image locally in step 3b.

Then, either way:

```bash
docker compose up -d household-service-prod

docker compose exec -T household-service-prod php artisan migrate --force
docker compose exec -T household-service-prod php artisan optimize:clear
docker compose exec -T household-service-prod php artisan optimize

docker compose up -d queue-prod
docker compose up -d scheduler-prod
docker compose up -d nginx-prod
```

---

## 5. Verify it worked

```bash
docker compose ps
```

All of `household-service-prod`, `queue-prod`, `scheduler-prod`, `nginx-prod`, `household-db`, `redis` should show `Up` (and `healthy` where a healthcheck exists).

```bash
curl http://localhost/api/v1
```

Should return a JSON response with `"status": "ok"`.

---

## 6. Common operations

**View logs**
```bash
docker compose logs -f household-service-prod
docker compose logs -f queue-prod
docker compose logs -f scheduler-prod
```

**Restart a service**
```bash
docker compose restart household-service-prod
```

**Run an artisan command**
```bash
docker compose exec household-service-prod php artisan <command>
```

**Roll back to a previous image**

Find a previous image tag (commit SHA) under the repo's **Packages** tab on GitHub, then:

```bash
IMAGE_TAG=<previous-sha> docker compose pull household-service-prod queue-prod scheduler-prod
IMAGE_TAG=<previous-sha> docker compose up -d household-service-prod queue-prod scheduler-prod
```

**Stop everything**
```bash
docker compose down
```
(Data in `household-postgres-data` and `redis-data` volumes is preserved.)

---

## Notes

- TLS/HTTPS is not configured yet — `nginx-prod` currently serves plain HTTP on ports 80/443. See `docker/nginx/prod.conf` for where to add a certificate.
- `household-db` and `redis` run as containers on the same server as the app. If you move to a managed database or Redis instance later, just update `DB_HOST` / `REDIS_HOST` in `.env`.
- Laravel does not read `.env` from inside the container — its config comes from real environment variables that Docker Compose injects into each `-prod` service. The `.env` file on the server only exists to fill in `${VARIABLE}` placeholders in `docker-compose.yml`. If a config value isn't showing up, check the `environment:` block for that service in `docker-compose.yml` first.
