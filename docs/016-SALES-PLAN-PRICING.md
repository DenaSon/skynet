# F11-B1.2 — Managed Plan Pricing

**Scope:** Root-managed prices and a read-only, buyer-bound plan quote. **No checkout/provider integration**.

## Tariff configuration

- Each plan has one `sales_plan_prices` record, initially `draft`, with independent `revision` and `configured_plan_revision`. Active prices become unavailable when a plan is changed, until a new rule is configured and published.
- A fixed price (`method=fixed`) is allowed only for a fixed preset and must be strictly positive; base/per-GB/per-day components are zero. A builder requires `method=formula`.
- A formula uses **integer rials only**: `base_price_rials + trafficGb × per_gb_rials + durationDays × per_day_rials`. Time/unlimited plan: `trafficGb=null`, `per_gb_rials=0`. Volume-only: `per_day_rials=0`. Hybrid may charge both.
- Price components and public totals are nonnegative and capped at 1,000,000,000,000 rials per quote. The configured reseller discount is 0–50% and is applied **once**. It does not combine with legacy F09's 5% reseller discount. Discount is half-up nearest rial: `intdiv(public * percent + 50, 100)`.
- `minimum_total_rials` is a mandatory positive pricing floor. Quotes for a builder selection below this floor are rejected explicitly, not silently modified. The maximum allowable configuration must clear the floor after the highest applicable reseller discount; the actual choice is rechecked.
- Nothing is seeded with guessed prices; all rules must be entered via internal Super Admin Application Actions.

## Root management

`ManagePlanPricing::configure(rootId, planId, expectedPlanRevision, expectedPriceRevision, PlanPricingDefinition)` uses SQL plan/price locks and optimistic revision checks. First creation expects price revision zero; subsequent updates require exact current revision and change the price to draft.

`requestChange(rootId, planId, expectedPlanRevision, expectedPriceRevision, 'activate'|'deactivate')` issues a cryptographically random three-minute root-bound single-use token. `confirmChange(rootId, token)` reacquires locks, reauthorizes the linked and allowlisted Super Admin and rejects stale plan or tariff revisions. All price changes are audited in `sales_plan_price_audits`. No public write endpoint or Telegram price editor is created yet.

## Quote and checkout safety

`CalculatePlanQuote::handle(buyerId, planId, days, ?gb)` checks fresh Identity role, active product/plan, audience, pricing availability and configured plan revision. Presets require exact GB/day (null GB for unlimited); builders must be reseller-only and within their plan bounds. It returns a **readonly `PlanPriceQuote`** containing buyer identity, plan ID/slug/name, plan and price revisions, duration, optional traffic, price method, public price, discount and total.

`RevalidatePlanQuote::handle(buyerId, expectedQuote)` recomputes and verifies **every quote field**; any plan edit, pricing change, privilege change, forged buyer or stale total fails closed. Future checkout must call this under its own order-creation transaction and snapshot confirmed plan/price/provider-binding version.

**Out of scope:** No B1.2 method creates Sales orders, touches Billing, exposes a Nutgram/HTTP purchase, dispatches Outbox or calls Remnawave. F09 quote + order remains unchanged. Next: F11-B1.3 validated plan-to-Internal-Squad binding and explicitly gated order integration; then role-specific Nutgram UX.

## Validation and release gates

Pest regression tests cover fixed and formula prices, unlimited plans, role separation, stale revisions, root confirmation replay/expiry/revocation, floor/rate overflow, and no legacy/financial/provider mutation. Run PHP 8.4/8.5 tests, PHPStan and Pint. Staging MySQL/PostgreSQL concurrency, bank/BotFather/Remnawave E2E remain outstanding.
