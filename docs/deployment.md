# Deployment

Staging and production run as stacks in the homelab on the owner's machine (`/srv/homelab`, helper `lab`).
Nothing is built or deployed by GitHub Actions: the image is built on the machine from a **committed** git ref
of this clone and deployed by hand. The full lab conventions are in `/srv/homelab/README.md`.

| Piece | Where |
| --- | --- |
| Image | local only: `saveme-backend:<sha12>` (+ moving `:staging`, `:production`) |
| Staging | `https://saveme-staging.lab.internal`, stack `saveme-staging`, built from `origin/staging` with `Dockerfile.staging` |
| Production | `https://saveme-production.lab.internal`, stack `saveme-production`, built from `origin/main` with `Dockerfile` |
| Infra (shared) | stack `saveme-infra`: PostgreSQL 16 (`postgres`, not published) + Mailpit (`https://saveme-mail.lab.internal`, lab login) |
| Host files | `/srv/homelab/saveme-{infra,staging,production}/` (`compose.yaml`, `build.env`, `.env`, `data/`) |

TLS terminates at the lab Traefik (`*.lab.internal`, lab CA). The app trusts its `X-Forwarded-*` headers
(`trustProxies(at: '*')` in `bootstrap/app.php`), so `API_REQUIRE_TLS=true` in both envs. Each env has its own
database and role (`saveme_staging`, `saveme_production`) in the shared Postgres.

## Build and deploy

```bash
git push origin staging                 # the ref must be committed and fetched; uncommitted work never ships
lab build saveme-staging                # git fetch; git archive origin/staging | docker build
lab deploy saveme-staging               # migrate --force (one-shot container), then recreate the app if the image changed

lab build saveme-production             # origin/main
lab deploy saveme-production

lab build saveme-staging feat/x         # any branch, tag or SHA
```

**Roll back:** `IMAGE_TAG=<sha12>` in `/srv/homelab/saveme-<env>/.env`, then `lab deploy saveme-<env>`.
Remove the line to follow the moving tag again.

## Chores

```bash
lab logs saveme-staging                 # app, nginx, queue worker, scheduler
lab dc saveme-staging exec -u www-data saveme-staging php artisan migrate:status
lab dc saveme-production exec saveme-production sh
lab dc saveme-infra exec postgres psql -U postgres
lab up | down saveme-staging
```

## Settings worth knowing

- **Back up `/srv/homelab/saveme-*/.env`.** `APP_KEY` and the keyrings (`IDENTITY_KEYS`, `ADMIN_KEYS`,
  `BILLING_KEYS`, `NOTIFICATIONS_KEYS`, `BILLING_PLAY_ACCOUNT_KEYS`, each `{"k1": …}` with `*_ACTIVE_KEY=k1`)
  seal stored data; losing them makes sealed columns unreadable. Never rotate them casually.
- A new key in the app's config must also be added to the env's live `.env` (start from
  `saveme-<env>/.env.example` in the homelab).
- `DB_PASSWORD` in each env's `.env` must equal `STAGING_DB_PASSWORD` / `PRODUCTION_DB_PASSWORD` in
  `saveme-infra/.env`.
- `BILLING_PLAY_DRIVER=disabled`, `NOTIFICATIONS_PUSH_DRIVER=disabled`: `fake` and `log` refuse to boot
  outside local. Switch to `google` / `fcm` with their credentials when ready.
- `APP_ENV=production` turns on `DB::prohibitDestructiveCommands` and the strict password rule.

`docker/deploy/` (the old runner-driven deploy gate and `saveme` CLI) is still baked into the image but unused.

## Connecting the Android app (same Wi-Fi)

1. **Device setup (once).** The phone uses the lab's Pi-hole for DNS and has the lab CA
   (`/srv/homelab/ca/rootCA.pem`) installed — see the homelab README. Check by opening
   `https://saveme-staging.lab.internal/up` in the phone's browser: "Application up" without a certificate
   warning means DNS, TLS and network are fine.

2. **Point a flavor at a stack.** In `saveme-kmp/local.properties`, set the host to
   `saveme-staging.lab.internal` (or `saveme-production.lab.internal`) and use `https://`. Android apps don't
   trust user-installed CAs by default: the flavor's `network_security_config.xml` needs
   `<trust-anchors><certificates src="user"/></trust-anchors>` (debug builds only), or bundle `rootCA.pem` as a
   raw resource limited to the `lab.internal` domain.

3. **Sign-up flow.** Register in the app, open Mailpit at `https://saveme-mail.lab.internal`, and tap the
   verification link on the phone.

4. **Version header.** The API refuses clients older than `API_MIN_ANDROID_VERSION` (1.0.0) with 426.
