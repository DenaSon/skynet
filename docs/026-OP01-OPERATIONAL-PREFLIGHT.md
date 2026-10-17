# OP-01 — ParsaGate operational preflight (read-only)

**Scope approved 2026-10-17:** Verify the prerequisites for the next Admin Plan–Squad and paid-order provisioning stages. **No code feature, database migration, real payment, BotFather mutation, Provider write, environment toggle or public activation is authorized by OP-01.**

**Source baseline:** `master` at `9f83d0202b7514d2b94d84d817d27718eeb80045` (sales readiness UX, PR #84). This runbook uses the existing read-only `telegram:ops:status` and `remnawave:inspect` commands and the existing Laravel Query Builder. Source review / CI are not live Production acceptance.

## Safety and evidence rules

1. Run in the deployed ParsaGate Laravel application container, not the separate NVL Core or Remnawave server. Record a UTC timestamp and deployed commit SHA by your deployment's normal read-only metadata; never assume a successful `master` CI run means the image is deployed.
2. Use **read-only** commands below. Do **not** run `php artisan migrate`, `migrate:rollback`, `migrate:fresh`, `db:wipe`, queue dispatch, `sales:fulfillment:run`, `setWebhook`, a bank review, or a Remnawave POST while collecting OP-01 evidence.
3. Share only redacted PASS/BLOCKED output. Never share Telegram/Remnawave tokens or secrets, raw bot/update payloads, receipt data, bank references, user identities, configuration URLs or subscription credentials.
4. An outdated observation is **not** current acceptance evidence. Earlier operator-reported Sales private smoke (17 processed updates / 1 reachable contact), one pending SB-03 migration, and earlier Remnawave inventory (3 instances, **0 Nodes**, **0 Hosts**, 1 Squad, 1 Profile) need to be rechecked.
5. Keep the current configured values unchanged. In particular, `REMNAWAVE_WRITES_ENABLED` must remain `false`, and do not turn on `REMNAWAVE_SQUAD_BINDINGS_LIVE_VERIFIED`, bank review, notifications, wallet settlement, topups or public signup as part of this preflight.

## A. Production schema and snapshot

```bash
cd /var/www
php artisan migrate:status
php artisan route:list --path=api/telegram
```

**PASS** for the schema gate requires `Ran` for both `2026_10_16_000001_create_sales_plan_internal_squad_bindings` and `2026_10_17_000001_add_managed_plan_order_snapshot`, and the existing Admin/Sales webhook POST routes. Inspect the *entire* migration list for newly pending migrations; do not apply any.

Independent, harmless schema shape check:

```bash
php artisan tinker --execute='
dump([
    "plan_bindings_table" => Schema::hasTable("sales_plan_internal_squad_bindings"),
    "snapshot_plan_slug" => Schema::hasColumn("sales_orders", "plan_slug"),
    "snapshot_plan_kind" => Schema::hasColumn("sales_orders", "plan_kind"),
    "snapshot_plan_revision" => Schema::hasColumn("sales_orders", "plan_revision"),
    "snapshot_pricing_revision" => Schema::hasColumn("sales_orders", "pricing_revision"),
    "snapshot_internal_squad_uuid" => Schema::hasColumn("sales_orders", "internal_squad_uuid"),
]);
'
```

**BLOCKED** if migration is pending or any required column/table is absent. Schema changes require a separate, backed-up Production migration approval; **do not fix the gate during OP-01**.

## B. Safe effective configuration (not raw .env)

```bash
php artisan tinker --execute='
dump([
    "sales_runtime_enabled" => config("telegram.sales.enabled") === true,
    "admin_token_present" => filled(config("nutgram.token")),
    "sales_token_present" => filled(config("telegram.sales.token")),
    "bot_tokens_distinct" => config("nutgram.token") !== config("telegram.sales.token"),
    "webhook_secrets_distinct" => config("telegram.webhook_secret") !== config("telegram.sales.webhook_secret"),
    "public_signup_off" => config("telegram.public_signup_enabled") !== true,
    "notifications_off" => config("telegram_notifications.enabled") !== true,
    "manual_bank_review_off" => config("sales.manual_bank_review_enabled") !== true,
    "wallet_settlement_off" => config("sales.wallet_settlement_enabled") !== true,
    "topups_off" => config("billing.topups_enabled") !== true,
    "squad_binding_gate_off" => config("services.remnawave.squad_bindings_live_verified") !== true,
    "provider_writes_off" => config("services.remnawave.writes_enabled") !== true,
]);
'
```

A `true` `*_off` value means **the safety restriction is ON** (safe), **not** that the selling or provisioning workflow is accepted. An absent token cannot be inferred from different-token check alone; inspect presence separately. The stored secret strings must remain private. `sales_runtime_enabled=true` is acceptable for the previously approved **private** Sales Bot smoke, not permission for public signup.

## C. Existing products, plans, pricing and Squad evidence

Run **only when A confirms all required schema**. Prints IDs and state, not UUIDs, prices, user data or stored credentials:

```bash
php artisan tinker --execute='
$rows = DB::table("sales_plans as plan")
    ->join("sales_products as product", "product.id", "=", "plan.product_id")
    ->leftJoin("sales_plan_prices as price", "price.plan_id", "=", "plan.id")
    ->leftJoin("sales_plan_internal_squad_bindings as binding", "binding.plan_id", "=", "plan.id")
    ->select(
        "plan.id", "plan.status as plan_status", "plan.revision",
        "product.is_active as product_active", "price.status as price_status",
        "price.configured_plan_revision",
        "binding.plan_revision as binding_revision",
        "binding.verified_at"
    )->orderBy("plan.id")->limit(30)->get();

dump([
    "total_plans" => DB::table("sales_plans")->count(),
    "listed_first_30" => $rows->map(fn ($p) => [
        "plan_id" => $p->id,
        "product_active" => (bool) $p->product_active,
        "plan_active" => $p->plan_status === "active",
        "price_active" => $p->price_status === "active",
        "price_revision_current" => $p->configured_plan_revision !== null
            && (int) $p->configured_plan_revision === (int) $p->revision,
        "squad_binding_present" => $p->binding_revision !== null,
        "squad_revision_current" => $p->binding_revision !== null
            && (int) $p->binding_revision === (int) $p->revision,
        "squad_marked_verified" => $p->verified_at !== null,
    ])->all(),
]);
'
```

**PASS** for *local plan/price readiness* only when at least one intended sellable plan has active product, active plan, active price and matching price revision. A binding row with `verified_at` is **not** proof of live Remnawave Squad membership. **BLOCKED** for *checkout readiness* unless independent remote Squad UUID/membership evidence exists and the deliberately separate `REMNAWAVE_SQUAD_BINDINGS_LIVE_VERIFIED` gate has been approved for a later controlled phase. Never set `verified_at` or that flag based on this SQL output.

## D. Live Remnawave inventory (GET only)

```bash
php artisan remnawave:inspect
```

The existing inspector calls Remnawave read-only health and inventory endpoints, displaying only counts. **PASS** for API connectivity requires a successful response. Record Node count, connected-and-enabled Node count, Host count, Internal Squad count and Config Profile count.

**BLOCKED** for genuine VPN connection tests while no usable connected Node or Host is present. A nonzero Squad/Profile count is not a validated Plan–Squad UUID/membership mapping. Never create Nodes/Hosts or flip provider write gates in this phase.

## E. Admin and Sales webhooks (GET-only Telegram API)

The following inspects **webhook metadata only**, does not register/change webhooks, and never prints tokens, secrets or the actual URLs:

```bash
php artisan tinker --execute='
$base = rtrim((string) config("app.url"), "/");
$bots = [
    "admin" => [
        "token" => config("nutgram.token"),
        "allowed_urls" => [
            $base."/api/telegram/webhook",
            $base."/api/telegram/admin/webhook",
        ],
    ],
    "sales" => [
        "token" => config("telegram.sales.token"),
        "allowed_urls" => [$base."/api/telegram/sales/webhook"],
    ],
];

foreach ($bots as $key => $bot) {
    try {
        if (! is_string($bot["token"]) || $bot["token"] === "") {
            dump([$key => ["token_configured" => false]]);
            continue;
        }
        $result = Illuminate\Support\Facades\Http::timeout(10)
            ->get("https://api.telegram.org/bot".$bot["token"]."/getWebhookInfo");
        $webhook = $result->json("result", []);
        dump([$key => [
            "api_ok" => $result->json("ok") === true,
            "expected_webhook_url" => in_array($webhook["url"] ?? null, $bot["allowed_urls"], true),
            "pending_updates" => $webhook["pending_update_count"] ?? null,
            "provider_reports_error" => isset($webhook["last_error_message"]),
        ]]);
    } catch (Throwable) {
        dump([$key => ["webhook_check_failed" => true]]);
    }
}
'
```

**PASS** for webhook configuration requires API response success, expected route, and no outstanding Telegram error. This is **configuration evidence**, not proof that an actual user received a reply. For delivery evidence use separately approved non-financial, private `/start` → catalog → back smoke and inspect aggregate counters before/after.

## F. Database queue, Scheduler and operational counts

```bash
php artisan telegram:ops:status
php artisan schedule:list
ps -eo pid,user,args | grep '[q]ueue:work'
```

**PASS** for *local observable health* when operational command exits successfully, no new stale leases/failed jobs or growing queue backlog are observed, and a supervised queue worker and expected schedule entries are present. An empty queue is **not** evidence that a worker has executed a job, and `schedule:list` is **not** proof the scheduler is running. Verify Runflare worker/Cron actual execution separately; do not dispatch jobs just to satisfy OP-01.

The `telegram:ops:status` failure totals are **cumulative historical counts**. Compare two snapshots to identify new failures; do not classify historical 79 Admin errors as a current incident without evidence.

## G. Evidence ledger and decision

Record the outcome against each acceptance item after **new** operator output; avoid copying old CI/probe claims into the live evidence column.

| Gate | Pass condition | Current evidence on 2026-10-17 | Decision |
| --- | --- | --- | --- |
| Source, tests and standalone commands | Merged PR #84 with PHP 8.4/8.5/MySQL green; existing read-only commands verified in source | Reviewed on `master` | **PASS (source only)** |
| Deployed schema and SB-03 order snapshot | Both migrations `Ran`, snapshot fields exist | Prior operator output reported the 2026-10-17 snapshot migration `Pending` | **BLOCKED (fresh check needed)** |
| Existing plan and published pricing | At least one intended active product/plan/price with current revision | Historical product/plan rows exist; current state unverified | **BLOCKED (fresh check needed)** |
| Plan–Squad verified for real panel | Valid remotely confirmed Squad binding and current revision; operator approval for separate gate | Previous flag OFF, no proof of mapped remote membership | **BLOCKED** |
| Provider GET and inventory | Real API read succeeds | Earlier read-only probe passed; counts are stale | **BLOCKED (fresh check needed)** |
| Usable VPN service | Connected/enabled Node and Host verified, successful staged client connection | Earlier 0 Nodes and 0 Hosts | **BLOCKED** |
| Two configured webhooks and private navigation | Correct metadata + non-financial live Telegram smoke | Sales private `/start`/menus previously worked; current status unverified | **BLOCKED (fresh check needed)** |
| Queue/Scheduler runtime | Observed supervised worker and actual Cron/job execution, no new failures | Earlier commands provided counts, not an observed new job | **BLOCKED (fresh check needed)** |
| Financial/provider operational authorization | Independent approval + staging E2E, no ambiguous writes | All gates intentionally OFF; E2E not accepted | **BLOCKED for activation; PASS for default-deny safety if B passes** |

**OP-01 source/documentation closure:** A reviewed, merged runbook with a complete safe checklist and evidence boundaries. **OP-01 live operational closure:** Requires the operator to run A–F and provide sanitized outputs, with unresolved blockers explicitly recorded. Neither this PR nor its CI can close that separate live acceptance.

### Handoff to OP-02 / OP-03

- **OP-02** (separately approved development): root-only, private-chat Plan → existing Internal Squad selection/verification using existing `sales_plan_internal_squad_bindings` and `CheckPlanSquadReadiness`. Must first validate real panel Squad UUID/membership without exposing transport secrets; do not auto-verify from count or fabricate a binding.
- **OP-03** (separately approved development): root-confirmed paid-order fulfillment UI built on the existing `FulfillPaidPlanOrder` / durable outbox; queue scheduling, terminal `needs_review` and GET-only reconciliation with no blind retry. No implementation/activation of OP-03 is authorized by OP-01.
- **Final E2E** is a separate operator-approved, isolated staging test: actual account and usable Node/Host, provider/user ownership, real link and connection, payment verification only under approved controlled conditions. Public activation remains OFF until accepted.

**Rollback:** Documentation-only revert, with no DB/provider/Telegram effect. Do not roll back a real customer migration or manually modify financial/provider state.
