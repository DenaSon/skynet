<?php

namespace App\Modules\Sales\Application;

use App\Modules\Identity\Application\ResolveSalesBuyer;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CreatePendingOrder
{
    public function handle(int $buyerId, PriceQuote $expected, string $idempotencyKey): OrderDetails
    {
        if (preg_match('/^[a-f0-9]{32}$/D', $idempotencyKey) !== 1) {
            throw ValidationException::withMessages(['key' => 'Invalid checkout identifier.']);
        }

        return DB::transaction(function () use ($buyerId, $expected, $idempotencyKey): OrderDetails {
            app(ResolveSalesBuyer::class)->isReseller($buyerId);

            $reader = app(OrderReader::class);
            $existing = $reader->byKey($buyerId, $idempotencyKey);

            if ($existing !== null) {
                if ($existing->buyerUserId !== $buyerId || ! $this->matches($existing, $expected)) {
                    throw ValidationException::withMessages(['order' => 'Checkout identifier already used.']);
                }

                return $existing;
            }

            // Every checkout reauthorizes and reprices against active catalog and current role.
            $fresh = app(CalculateQuote::class)->handle(
                $buyerId, $expected->productSlug, $expected->durationDays, $expected->trafficGb,
            );

            if (! $this->sameQuote($fresh, $expected)) {
                throw ValidationException::withMessages(['quote' => 'The rate or buyer role changed. Review the new price.']);
            }

            DB::table('sales_orders')->insertOrIgnore([
                'buyer_user_id' => $buyerId,
                'idempotency_key' => $idempotencyKey,
                'product_slug' => $fresh->productSlug,
                'product_name' => $fresh->productName,
                'duration_days' => $fresh->durationDays,
                'traffic_gb' => $fresh->trafficGb,
                'public_price_rials' => $fresh->publicPriceRials,
                'discount_percent' => $fresh->discountPercent,
                'discount_rials' => $fresh->discountRials,
                'total_price_rials' => $fresh->totalPriceRials,
                'status' => 'pending_payment',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $record = $reader->byKey($buyerId, $idempotencyKey);

            if ($record === null || $record->buyerUserId !== $buyerId || ! $this->matches($record, $expected)) {
                throw ValidationException::withMessages(['order' => 'Checkout identifier already used.']);
            }

            return $record;
        });
    }

    private function sameQuote(PriceQuote $actual, PriceQuote $expected): bool
    {
        return $actual->productSlug === $expected->productSlug
            && $actual->productName === $expected->productName
            && $actual->durationDays === $expected->durationDays
            && $actual->trafficGb === $expected->trafficGb
            && $actual->publicPriceRials === $expected->publicPriceRials
            && $actual->discountPercent === $expected->discountPercent
            && $actual->discountRials === $expected->discountRials
            && $actual->totalPriceRials === $expected->totalPriceRials;
    }

    private function matches(OrderDetails $order, PriceQuote $quote): bool
    {
        return $order->productSlug === $quote->productSlug
            && $order->productName === $quote->productName
            && $order->durationDays === $quote->durationDays
            && $order->trafficGb === $quote->trafficGb
            && $order->publicPriceRials === $quote->publicPriceRials
            && $order->discountPercent === $quote->discountPercent
            && $order->discountRials === $quote->discountRials
            && $order->totalPriceRials === $quote->totalPriceRials;
    }
}
