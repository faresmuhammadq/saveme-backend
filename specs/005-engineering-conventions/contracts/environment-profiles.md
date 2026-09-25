# Contract: Environment Profiles

Normative. Four profiles, stated by difference. Staging and production are documented rows; no files exist for them until a deployment target is chosen.

| Setting | Local | Pipeline | Staging | Production |
|---|---|---|---|---|
| `APP_ENV` | `local` | `testing` | `staging` | `production` |
| `APP_DEBUG` | `true` | `true` | `false` | `false` |
| `APP_URL` | `http://saveme-backend.test` | unused | target host | target host |
| `DB_CONNECTION` | `pgsql` | `pgsql` | `pgsql` | `pgsql` |
| `DB_HOST` / credentials | filled by `dev project env` | job env | secret store | secret store |
| `DB_DATABASE` | per worktree | `saveme_backend_main_test` | per environment | per environment |
| `CACHE_STORE` | `redis` (shared stack) or `database` | `array` | `redis` | `redis` |
| `QUEUE_CONNECTION` | `database` | `sync` | `redis` or `database` | `redis` or `database` |
| `MAIL_MAILER` | `smtp` → Mailpit | `array` | provider | provider |
| `LOG_LEVEL` | `debug` | `debug` | `info` | `info` |
| `SESSION_ENCRYPT` | `false` | n/a | `true` | `true` |
| Secrets live in | untracked `.env` | job env / repo secrets | the deployment's secret store | the deployment's secret store |

## May never differ

- PHP version: 8.5.
- Database engine and major version: PostgreSQL 16.
- Money representation: integer minor units plus ISO 4217.
- Identifier format: UUIDv7.
- Instants stored in UTC.
- Log format: structured JSON outside local.
- The never-log list.

A setting read by the application that is missing from this table is a defect in this document.
