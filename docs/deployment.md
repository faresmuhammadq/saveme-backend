# Deployment

Staging and production run as stacks in the homelab on the owner's machine (`/srv/homelab`, helper `lab`).
CI and image builds run on the self-hosted Forgejo at `https://git.lab.internal` (`fares/saveme-backend`,
remote `forge`), not on GitHub Actions: `.forgejo/workflows/pipeline.yml` runs the quality gate on every push and,
for `staging` and `main`, pushes the image to the forge registry. The homelab host pulls and deploys it.
The full lab conventions are in `/srv/homelab/README.md`.

| Piece | Where |
| --- | --- |
| Image | forge registry: `git.lab.internal/fares/saveme-backend:<sha12>` (+ moving `:staging`, `:production`) |
| Staging | `https://saveme-staging.lab.internal`, stack `saveme-staging`, image built from forge `staging` with `Dockerfile.staging` |
| Production | `https://saveme-production.lab.internal`, stack `saveme-production`, image built from forge `main` with `Dockerfile` |
| Infra (shared) | stack `saveme-infra`: PostgreSQL 16 (`postgres`, not published) + Mailpit (`https://saveme-mail.lab.internal`, lab login) |
| Host files | `/srv/homelab/saveme-{infra,staging,production}/` (`compose.yaml`, `.env`, `data/`) |

TLS terminates at the lab Traefik (`*.lab.internal`, lab CA). The app trusts its `X-Forwarded-*` headers
(`trustProxies(at: '*')` in `bootstrap/app.php`), so `API_REQUIRE_TLS=true` in both envs. Each env has its own
database and role (`saveme_staging`, `saveme_production`) in the shared Postgres.

## Build and deploy

```bash
git push forge staging                  # pipeline: ci, then image :<sha12> + :staging (only if ci passes)
                                        # the host's `lab timer saveme-staging` pulls and deploys it within 5 minutes,
lab deploy saveme-staging               # or deploy now: pull, migrate --force (one-shot), recreate if the image changed

git push forge main                     # pipeline: ci, then image :<sha12> + :production
lab deploy saveme-production            # production is always deployed by hand
```

Any branch, tag or SHA: run the `pipeline` workflow by hand (Actions → pipeline → Run workflow) on that ref and pick
the env; it pushes `:<sha12>` and moves the env's tag.

The host needs `docker login git.lab.internal -u fares` once, with a token that can read packages. The pipeline
needs the `CI_TOKEN` secret (write:package). To build on the host again instead, restore `compose.yaml.bak` and rename
`build.env.local` to `build.env` in the env's homelab folder; `lab build saveme-<env> [ref]` then works as before.

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
