<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Spatie\Health\Models\HealthCheckResultHistoryItem;
use Spatie\Health\ResultStores\EloquentHealthResultStore;

uses(RefreshDatabase::class);

it('registers the Spatie health CLI and persists history using its own table', function (): void {
    expect(Artisan::has('health:check'))->toBeTrue()
        ->and(Artisan::has('health:list'))->toBeTrue()
        ->and(Schema::hasTable('health_check_result_history_items'))->toBeTrue()
        ->and(config('health.result_stores.'.EloquentHealthResultStore::class.'.model'))
        ->toBe(HealthCheckResultHistoryItem::class);
});

it('keeps automatic monitoring and all Spatie notifications off until rollout', function (): void {
    expect(config('denagate_health.enabled'))->toBeFalse()
        ->and(config('health.notifications.enabled'))->toBeFalse();
});

it('does not register a public Spatie health results endpoint', function (): void {
    $uris = array_map(
        static fn ($route): string => $route->uri(),
        Route::getRoutes()->getRoutes(),
    );

    expect($uris)->not->toContain('oh-dear-health-check-results');
});
