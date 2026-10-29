# Deployment

Staging and production run as Docker stacks on one host (the owner's machine, LAN address `192.168.1.14`).
GitHub-hosted runners build the image and push it to GHCR; a self-hosted runner on the host deploys it.

| Piece | Where |
| --- | --- |
| Image | `ghcr.io/faresmuhammad/saveme-backend:<sha>` (+ `:staging`, `:production`) |
| Staging | `http://192.168.1.14:8091`, stack `saveme-staging`, deploys on every push to the `staging` branch |
| Production | `http://192.168.1.14:8092`, stack `saveme-production`, built on every push to `main`, deployed by running the `production` workflow on `main` |
| Infra (shared) | PostgreSQL 16 (`saveme-postgres`, not published) + Mailpit (UI `http://192.168.1.14:8026`), stack `saveme-infra` |
| Host files | `/srv/saveme/{infra,staging,production,bin}` |

Each env gets its own database and role (`saveme_staging`, `saveme_production`) in the shared Postgres.

## Layout in the repo

```
Dockerfile                    vendor → assets → php-fpm + nginx + supervisor (queue worker, scheduler)
docker/entrypoint.sh          storage:link, optimize, supervisord; or runs the given command as www-data
docker/{php,nginx,supervisor} runtime config
docker/deploy/                baked into the image at /opt/deploy
  deploy-gate.sh              copies the deploy bundle out of the image, seeds/reconciles .env, deploys
  bin/saveme                  chores CLI installed on the host
  infra/                      postgres + mailpit compose
  staging/, production/       app compose + <env>.env.example
.github/workflows/staging.yml     push to staging → build → deploy staging
.github/workflows/production.yml  push to main → build; manual run on main → build → deploy production
```

## One-time host setup

1. **GitHub repo.** Create `faresmuhammad/saveme-backend`, then:
   ```bash
   git remote add origin git@github.com:faresmuhammad/saveme-backend.git
   git push -u origin main
   git push origin main:staging
   ```
   In *Settings → Environments* create `staging` and `production` (add yourself as required reviewer on
   `production` if the plan allows it; the manual dispatch is the gate otherwise).

2. **Host directory.**
   ```bash
   sudo mkdir -p /srv/saveme && sudo chown "$USER": /srv/saveme
   ```

3. **Self-hosted runner.** *Settings → Actions → Runners → New self-hosted runner → Linux x64*, then on this
   machine (as your user, which must be in the `docker` group: `groups | grep docker`):
   ```bash
   mkdir -p ~/actions-runner && cd ~/actions-runner
   # download + extract the tarball exactly as the GitHub page shows
   ./config.sh --url https://github.com/faresmuhammad/saveme-backend --token <TOKEN> \
       --name saveme-host --labels saveme --unattended
   sudo ./svc.sh install "$USER"
   sudo ./svc.sh start
   ```
   The workflows target `runs-on: [self-hosted, linux, saveme]`.

4. **Firewall** (Fedora / firewalld) so the phone can reach the stacks:
   ```bash
   sudo firewall-cmd --permanent --add-port=8091/tcp --add-port=8092/tcp --add-port=8026/tcp
   sudo firewall-cmd --reload
   ```

5. **Fixed LAN IP.** Reserve `192.168.1.14` for this machine in the router's DHCP settings. If the address
   changes, edit `APP_URL`, `IDENTITY_VERIFY_URL`, `IDENTITY_RESET_URL`, `SHARING_INVITATION_URL` and
   `BILLING_INSTAPAY_RENEW_URL` in `/srv/saveme/<env>/.env`, then `saveme up <env>`.

6. **GHCR package visibility.** The first build creates the package. Keep it private; the deploy job logs in
   with the workflow's `GITHUB_TOKEN`. Under the package's *Settings → Manage Actions access*, make sure the
   `saveme-backend` repository has read access.

## What a deploy does

`deploy-gate.sh deploy <env>` (run by the runner with `IMAGE`, `IMAGE_TAG`, `REGISTRY_USER`, `REGISTRY_TOKEN`):

1. Logs in to GHCR, pulls `<image>:<sha>`, copies `/opt/deploy` out of it into `/srv/saveme`.
2. First deploy: creates `/srv/saveme/<env>/.env` from `<env>.env.example` (mode 600). Later deploys only
   append keys new to the example (or rename keys flagged `# renamed-from: OLD`); values are never overwritten.
3. Generates anything empty: `APP_KEY`, `DB_PASSWORD`, `POSTGRES_PASSWORD`, every keyring
   (`IDENTITY_KEYS`, `ADMIN_KEYS`, `BILLING_KEYS`, `NOTIFICATIONS_KEYS`, `BILLING_PLAY_ACCOUNT_KEYS` as
   `{"k1": …}` with the matching `*_ACTIVE_KEY=k1`) and every lookup/code key. Existing keys are never rotated.
4. Installs the `saveme` command (`/usr/local/bin` or `~/.local/bin`).
5. `saveme deploy <env> <sha>`: starts infra if down or changed, creates the env's DB role + database, runs
   `migrate --force` in a one-off container, `up -d`, waits for `/up` to report healthy, keeps the 3 newest images.

**Back up `/srv/saveme/*/.env`.** The keyrings seal stored data; losing them makes sealed columns unreadable.

## `saveme` cheatsheet

```bash
saveme status staging
saveme logs staging                    # app, nginx, queue worker, scheduler
saveme artisan staging migrate:status
saveme bash production
saveme up staging | saveme down staging
saveme infra status | saveme infra logs postgres
bash /srv/saveme/bin/deploy-gate.sh version production
```

Redeploy an older build by hand: `saveme deploy staging <sha>` (the image must exist in GHCR or locally).

## Settings worth knowing

- `API_REQUIRE_TLS=false` in both envs: the stacks are LAN-only over plain HTTP. Without it every `/api/v1`
  call returns `400 INSECURE_TRANSPORT`. Flip it to `true` once a TLS proxy sits in front.
- `BILLING_PLAY_DRIVER=disabled`, `NOTIFICATIONS_PUSH_DRIVER=disabled`: `fake` and `log` refuse to boot
  outside local. Switch to `google` / `fcm` with their credentials when ready.
- Mail goes to the shared Mailpit; both envs' mails appear in one inbox at `:8026`.
- `APP_ENV=production` turns on `DB::prohibitDestructiveCommands` and the strict password rule.

## Connecting the Android app (same Wi-Fi)

1. **Check reachability from the phone.** Open `http://192.168.1.14:8091/up` (staging) or `:8092/up`
   (production) in the phone's browser. A green "Application up" page means network + firewall are fine.
   If it times out: firewall ports (step 4), phone on guest Wi-Fi / AP isolation, or wrong IP (`ip -4 addr`).

2. **Quick path — dev flavor pointed at a stack.** The dev flavor already allows cleartext HTTP
   (`androidApp/src/dev/res/xml/network_security_config.xml`) and reads its host from `local.properties`.
   In `saveme-kmp/local.properties`:
   ```properties
   devApiHost=192.168.1.14:8091   # staging; use :8092 for production
   ```
   Then `./gradlew :androidApp:installDevDebug` with the phone connected (USB or wireless adb).
   The client adds `http://` and `/api/v1/` itself; e-mail links (`/verify-email`, `/reset-password`) open the
   app because the dev flavor's link host comes from the same value.

3. **Proper path — staging/prod flavors on the LAN** (KMP change, not done yet). Today those flavors hardcode
   `https://staging.api.example` / `https://api.example`. To use them against this host:
   - In `androidApp/build.gradle.kts`, read `stagingApiHost` / `prodApiHost` the same way `devApiHost` is read,
     and use `"http://$stagingApiHost"` for `API_BASE_URL` plus host/port for `LINK_HOST`/`LINK_PORT` and the
     manifest placeholders.
   - Copy `src/dev/AndroidManifest.xml` and `src/dev/res/xml/network_security_config.xml` into `src/staging/`
     and `src/prod/` so cleartext is allowed (better: a `<domain-config>` limited to `192.168.1.14`).
   - `local.properties`: `stagingApiHost=192.168.1.14:8091`, `prodApiHost=192.168.1.14:8092`;
     install with `./gradlew :androidApp:installStagingDebug`.
   Revert the cleartext allowance once the backend is behind real TLS.

4. **Sign-up flow.** Register in the app, open Mailpit at `http://192.168.1.14:8026` (from the phone or the
   laptop), and tap the verification link on the phone.

5. **Version header.** The API refuses clients older than `API_MIN_ANDROID_VERSION` (1.0.0) with 426; the app
   sends `versionName` (`1.0.0`), so nothing to change unless you raise the minimum.
