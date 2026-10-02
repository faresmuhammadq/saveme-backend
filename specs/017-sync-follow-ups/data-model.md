# Data Model: Sync Follow-ups (spec 017)

No table, column, index or migration. Every value below already exists; this feature reads or re-stamps it.

## Values on the wire (Sync, derived)

| Value | Source | Where it appears |
|---|---|---|
| **Scope Head** (`change_seq` of a `WalletHead`) | `wallets.change_seq` (the wallet scope counter, ADR-0008 rule 2) | `GET /sync/wallets/heads` |
| **Purge Horizon** (`purged_change_seq` of a `WalletHead`) | `wallets.purged_change_seq` (spec 013) | `GET /sync/wallets/heads` |
| **Sync Position** (`meta.sync_position`) | `FeedPage::nextPosition` — the position the page's `sync_cursor` signs | every page of both feeds |

`WalletHead` (Wallets Application DTO, `App\Modules\Wallets\Application\Sync\WalletHead`): `walletId: string`, `head: int`, `purged: int`.

Selection: `wallet_members` rows with `user_id = caller AND deleted_at IS NULL`, joined to `wallets` with `deleted_at IS NULL`, ordered by `wallet_id`, keyset after the cursor's last `wallet_id`.

## Wallet Member re-stamp (Wallets & Ledger, existing entity)

| Column | On a display-name re-stamp |
|---|---|
| `change_seq` | next value of the wallet's counter, under the wallet row lock |
| `version` | unchanged |
| `updated_at` | unchanged |
| `member_change_seq` | unchanged (no user-row lock) |
| `deleted_at`, `role` | unchanged; only live memberships are re-stamped |

The wallet row takes the scope's final stamp (`WalletScopeWrites`), so the wallet record is re-sent too; its `version` does not move.

Lifecycle: unchanged (`active` → `revoked`). A re-stamp is not a state transition.

## Trigger

Identity `ProfileUpdated` (existing, after commit) with `display_name` in `changedFields` → Wallets queued listener → job `RestampMemberNames(userId, afterWalletId)`; at most 50 wallets per run, continuation for the rest, one wallet lock per unit of work, wallets in ascending id.
