# F09-A — Product Catalog & Pricing Operations

**Scope:** F09-A operator catalog and quotes, plus F09-B read-only Telegram buying preview. **No order/charge/Remnawave integration yet.**

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

Run `php artisan test`, `vendor/bin/phpstan analyse --no-progress` and `vendor/bin/pint --test`. Verify public and reseller pricing on the first Telegram buying screen, valid volumes including custom inputs, disabled rates and private-chat access. **F09-B is a read-only preview**; real Telegram/payment/provider E2E and order creation are not performed yet.
