# F09-A — Product Catalog & Pricing Operations

**Scope:** F09-A operator catalog and quotes, F09-B Telegram builder and F09-C pending-payment order registration. **No charge/Remnawave integration yet.**

## Configure product and rates

Run in your trusted deployment terminal, not in Telegram or HTTP. Operator must provide **real** selling prices; migrations intentionally seed none.

```bash
php artisan migrate
php artisan sales:product:set vpn-access "اشتراک VPN" --min-gb=1 --max-gb=100
php artisan sales:rate:set vpn-access 15 <base_toman> <per_gb_toman>
php artisan sales:rate:set vpn-access 30 <base_toman> <per_gb_toman>
php artisan sales:rate:set vpn-access 60 <base_toman> <per_gb_toman>
php artisan sales:rate:set vpn-access 90 <base_toman> <per_gb_toman>
```

Replace `<base_toman>` and `<per_gb_toman>` with **non-negative integer toman amounts**, with at least one positive. The operator-provided rates are converted to **integer rials** and stored once. Calling the same command again updates the existing record (unique product slug and product/duration).

To suspend a product or one duration rate:

```bash
php artisan sales:product:set vpn-access "اشتراک VPN" --inactive
php artisan sales:rate:set vpn-access 15 <base_toman> <per_gb_toman> --inactive
```

Re-running without `--inactive` reactivates. Disabling one rate hides it from customer offers and rejects quotes for that duration. No guessed prices are bundled.

## Read/quote contract

- `ListProducts::handle(int $buyerId)` returns active available products, allowed volume range and active duration tariffs.
- `CalculateQuote::handle(int $buyerId, string $slug, int $days, int $gb)` returns immutable `PriceQuote` including full public price, discount and final price.
- Active non-resellers pay the public rate. Active accounts with `reseller` role pay **95%** (nearest-rial half-up discount); set `config/sales.php` discount to 0 for identical direct/wholesale prices.
- Prices always use integer **rials**. UI later converts to displayed **toman** and must choose a consistent rounding policy when a rial total is not divisible by ten.
- A quote is *not a locked price*. F09-C must revalidate product, volume, duration, role and rate before persisting an immutable order snapshot.
- Do not feed Remnawave credentials, user UUIDs or node IDs into catalog records. F10 will handle fulfillment details.

## Release verification

Run `php artisan test`, `vendor/bin/phpstan analyse --no-progress` and `vendor/bin/pint --test`. Verify public and reseller pricing on the first Telegram buying screen, valid volumes including custom inputs, disabled rates and private-chat access. F09-B previews stay read-only until the explicit F09-C **ثبت سفارش** button is clicked. This persists an unpaid order snapshot only. Live Telegram/payment/provider E2E remains pending.

## F11-B1.1 plan catalog (internal-only)

The legacy F09 `sales:product:set` and `sales:rate:set` commands remain for existing quote/checkout. New plans are managed exclusively with root-only Application Actions and are **not priced or orderable**. Use `ManagePlans` to create draft, update by expected revision and request/confirm activation, deactivation, archival or deletion. All root mutations are audited; confirmations expire in three minutes. `ListVisiblePlans` is only a scoped listing. The Telegram UI for managing plans will be built in B1.4. See `docs/015-SALES-PLAN-CATALOG.md`.


## Telegram root management (F11-CATALOG-01)

The private /products command and Super Admin /admin -> 📚 محصولات پایه now manage base products and legacy 15/30/60/90-day F09 rates. They do NOT manage fixed/formula F11 plan tariffs (continue using /plans). Forms:
- New product: vpn-extra | اشتراک ویژه | 1 | 100
- Edit existing product: اشتراک به‌روز | 1 | 100 (slug immutable)
- Legacy rate for chosen duration: 50000 | 2000 (baseToman | perGBToman)

Create/edit are previewed and confirmed with owner-scoped single-use 3-minute tokens. New products, new rates, and updated rates are inactive until separately enabled. All operations are independently root-authorized, audited (sales_catalog_management_audits), and protected against concurrent changes. No deletion, finance or provider writes. The old Artisan sales:product:set and sales:rate:set remain compatible; their edits invalidate an in-flight Telegram preview via fresh state comparisons. Existing production records are preserved by the additive audit-only migration.
