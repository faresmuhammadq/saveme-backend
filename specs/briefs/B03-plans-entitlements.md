# B3 · Plans & entitlements

**Call with**: `/speckit-specify B3` · **Source**: the Phase B brief set, recorded 2026-09-25

## Description

A data-driven plan catalog and the service that turns subscriptions into effective capabilities. Tables: `plans` (`code`, `name` i18n, `period_type` enum monthly/yearly/lifetime/custom, `period_days` nullable, `is_default`, `is_active`, `sort_order`), `plan_features` (`plan_id`, `feature_key`, `value_type` bool/int, `value`), `plan_prices` (`plan_id`, `provider`, `provider_product_id`, `currency`, `amount_minor`, `display_price`), `subscriptions` (`user_id`, `plan_id`, `provider` google_play/instapay/manual, `provider_ref`, `status` trialing/active/in_grace/on_hold/paused/canceled/expired, `started_at`, `current_period_end`, `auto_renew`, `source_meta` json). `EntitlementService::for(User)` → an immutable `Entitlements` object with `can(feature)` and `quota(feature)`; `EntitlementService::forWallet(Wallet)` resolves via the owner. Feature keys for MVP: `sharing.enabled` (bool), `sharing.max_members_per_wallet` (int), plus reserved keys for later (`export.enabled`, `reports.advanced`, `wallets.max`). Endpoints: `GET /plans` (public catalog with localized prices), `GET /me/entitlements`, `GET /me/subscription`. Admin operations (manual grant, revoke, extend) are exposed in spec 12.

## Considerations

Free is a real plan row (`is_default`), not the absence of a subscription — every user always has exactly one *effective* subscription; when several exist (Play + InstaPay overlap), the one with the latest `current_period_end` among active statuses wins, and the rule must be written down. Entitlements are cached per user with invalidation on subscription change. The Free tier limits only sharing; encode that in the seeder, not in code. Downgrade behaviour when a Pro owner expires with more members than Free allows: existing members become read-only rather than removed — specify this explicitly, it is the most visible edge case. Lifetime plans have null `current_period_end`. Nothing in the codebase compares `plan.code === 'pro'`.

## Reconcile at specify time

The brief predates decisions ratified since. These collide with it; the ratified side wins unless the spec deliberately supersedes it through a decision record or an amendment.

| Brief says | Already decided | Resolution needed |
|---|---|---|
| "Nothing compares plan.code" | The P-09 architecture gate targets the type `App\Modules\Billing\Domain\PlanCode`, which only Billing's domain, infrastructure and `Application\Entitlements` may use | Model the plan code as that type, or the gate protects nothing |
| `display_price` and `amount_minor` | Money is integer minor units plus currency (P-03); a preformatted price string is presentation | Decide whether `display_price` is stored or derived per locale; never a decimal |
| Admin grant/revoke/extend "exposed in spec 12" | Admin is Livewire, not Filament (P-18); screens build on the dashboard foundation, D1 | Ship the domain commands here; the screens land in B12 |
