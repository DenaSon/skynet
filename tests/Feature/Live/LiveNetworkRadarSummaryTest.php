<?php

declare(strict_types=1);

use App\Interfaces\Web\Live\Dashboard;
use App\Modules\Identity\Infrastructure\Models\TelegramAccount;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Support\Network\ReadIranNetworkSummary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->withoutVite();
    $this->travelTo(Carbon::parse('2026-11-15 10:00:00 UTC'));
    config()->set('denagate_live.enabled', true);
    config()->set('services.cloudflare_radar.token', 'safe-radar-test-token');
    config()->set('cache.default', 'array');
    Cache::flush();
    Role::findOrCreate('reseller', 'web');
    Role::findOrCreate('member', 'web');
    Http::preventStrayRequests();
});

/** @return array<string,mixed> */
function radarLiveTraffic(string $status): array
{
    return ['success' => true, 'result' => ['trafficAnomalies' => $status === 'NONE' ? [] : [[
        'status' => $status,
        'startDate' => '2026-11-15T08:00:00Z',
        'endDate' => '2026-11-15T09:00:00Z',
    ]]]];
}

/** @return array<string,mixed> */
function radarLiveSeries(string $lastUpdated = '2026-11-15T09:00:00Z'): array
{
    return ['success' => true, 'result' => [
        'meta' => ['lastUpdated' => $lastUpdated, 'normalization' => 'MIN0_MAX'],
        'serie_0' => [
            'timestamps' => ['2026-11-15T09:00:00Z'],
            'values' => ['73'],
        ],
    ]];
}

/** @return array<string,mixed> */
function radarLiveOutages(bool $withOutage = false): array
{
    return ['success' => true, 'result' => ['annotations' => $withOutage ? [[
        'eventType' => 'OUTAGE',
        'asns' => [44244],
        'startDate' => '2026-11-15T07:00:00Z',
        'endDate' => '2026-11-15T09:00:00Z',
    ]] : []]];
}

function radarLiveOwner(): User
{
    $owner = User::factory()->create();
    $owner->assignRole('reseller');
    TelegramAccount::query()->create(['user_id' => $owner->id, 'telegram_user_id' => 879999]);

    return $owner;
}

it('shows two network cards after sales reporting, fetching Radar only from the lazy action', function (): void {
    $this->actingAs(radarLiveOwner());
    Http::fake(function (Request $request) {
        $url = $request->url();
        if (str_contains($url, '/annotations/outages')) {
            return Http::response(radarLiveOutages());
        }
        if (str_contains($url, '/netflows/timeseries')) {
            return Http::response(radarLiveSeries());
        }
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $status = match ((string) ($query['asn'] ?? 'IR')) {
            '197207' => 'UNVERIFIED',
            '57218' => 'TP',
            default => 'NONE',
        };

        return Http::response(radarLiveTraffic($status));
    });

    $initialJournalCount = DB::table('billing_journals')->count();
    $component = Livewire::test(Dashboard::class)
        ->assertDontSee('وضعیت اینترنت ایران')
        ->call('showSection', 'reports')
        ->assertSee('مجموع خرید و تمدید از دناگیت')
        ->assertSee('وضعیت اینترنت ایران')
        ->assertSee('در حال دریافت اطلاعات شبکه')
        ->assertSee('گزارش فروش')
        ->assertSee('خریدهای جدید');
    Http::assertNothingSent();

    $component->call('loadNetworkStatus')
        ->assertSee('سالم')
        ->assertSee('احتمال اختلال')
        ->assertSee('اختلال شبکه')
        ->assertDontSee('Cloudflare Radar')
        ->assertDontSee('AS197207')
        ->assertDontSee('MIN0_MAX')
        ->assertDontSee('safe-radar-test-token')
        ->call('selectReportDays', 7)
        ->assertSet('reportDays', 7)
        ->assertSee('گزارش فروش');

    Http::assertSentCount(9);
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/traffic_anomalies')
        && str_contains($request->url(), 'asn=197207')
        && str_contains($request->url(), 'dateRange=1d'));
    expect(DB::table('billing_journals')->count())->toBe($initialJournalCount);
});

it('keeps incomplete provider failures neutral without breaking the sales report', function (): void {
    $this->actingAs(radarLiveOwner());
    Http::fake(function (Request $request) {
        if (str_contains($request->url(), '/annotations/outages')
            || str_contains($request->url(), 'asn=197207')) {
            return Http::response(['success' => false], 503);
        }

        return Http::response(str_contains($request->url(), '/netflows/timeseries')
            ? radarLiveSeries()
            : radarLiveTraffic('NONE'));
    });

    Livewire::test(Dashboard::class)
        ->call('showSection', 'reports')
        ->call('loadNetworkStatus')
        ->assertSee('اطلاعات کافی نیست')
        ->assertSee('گزارش فروش')
        ->assertSee('اطلاعات کافی نیست');
    Http::assertSentCount(9);
});

it('uses actual recent outage data for ISP status and ignores old or malformed anomaly timestamps', function (): void {
    Http::fake(function (Request $request) {
        if (str_contains($request->url(), '/annotations/outages')) {
            return Http::response(radarLiveOutages(true));
        }
        if (str_contains($request->url(), '/netflows/timeseries')) {
            return Http::response(radarLiveSeries());
        }
        if (str_contains($request->url(), 'asn=197207')) {
            return Http::response(['success' => true, 'result' => ['trafficAnomalies' => [[
                'status' => 'VERIFIED',
                'startDate' => '2026-11-11T00:00:00Z',
                'endDate' => '2026-11-12T00:00:00Z',
            ]]]]);
        }
        if (str_contains($request->url(), 'asn=57218')) {
            return Http::response(['success' => true, 'result' => ['trafficAnomalies' => [[
                'status' => 'VERIFIED',
                'startDate' => 'not-a-timestamp',
            ]]]]);
        }

        return Http::response(radarLiveTraffic('NONE'));
    });

    $snapshot = app(ReadIranNetworkSummary::class)->handle();
    expect($snapshot['country']['code'])->toBe('confirmed')
        ->and($snapshot['operators'][0]['code'])->toBe('healthy')
        ->and($snapshot['operators'][1]['code'])->toBe('confirmed')
        ->and($snapshot['operators'][2]['code'])->toBe('unknown');
});

it('denies a non-reseller even if the network action is invoked directly', function (): void {
    $owner = radarLiveOwner();
    $this->actingAs($owner);
    Http::fake();

    $session = Livewire::test(Dashboard::class)->call('showSection', 'reports');
    $owner->syncRoles(['member']);
    $session->call('loadNetworkStatus')->assertForbidden();
    Http::assertNothingSent();
});

it('does not reveal any Radar data when not on reports and rechecks on later report entry', function (): void {
    $this->actingAs(radarLiveOwner());
    Http::fake([
        'https://api.cloudflare.com/*' => Http::response(radarLiveTraffic('NONE')),
    ]);

    Livewire::test(Dashboard::class)
        ->call('loadNetworkStatus')
        ->assertSet('networkStatusLoaded', false)
        ->call('showSection', 'reports')
        ->assertSee('وضعیت اینترنت ایران')
        ->call('showSection', 'services')
        ->assertDontSee('وضعیت اینترنت ایران');
    Http::assertNothingSent();
});

it('never paints a green status when Cloudflare traffic timestamps are stale or malformed', function (): void {
    Http::fake(function (Request $request) {
        if (str_contains($request->url(), '/annotations/outages')) {
            return Http::response(radarLiveOutages());
        }
        if (str_contains($request->url(), '/netflows/timeseries')) {
            return Http::response(radarLiveSeries('2026-11-14T01:00:00Z'));
        }

        return Http::response(radarLiveTraffic('NONE'));
    });

    $snapshot = app(ReadIranNetworkSummary::class)->handle();
    expect($snapshot['country']['code'])->toBe('unknown')
        ->and($snapshot['operators'][0]['code'])->toBe('unknown')
        ->and($snapshot['operators'][1]['code'])->toBe('unknown')
        ->and($snapshot['operators'][2]['code'])->toBe('unknown');
});

it('keeps suspect anomalies orange even when the traffic freshness signal is unavailable', function (): void {
    Http::fake(function (Request $request) {
        if (str_contains($request->url(), '/annotations/outages')) {
            return Http::response(radarLiveOutages());
        }
        if (str_contains($request->url(), '/netflows/timeseries')) {
            return Http::response(['success' => false], 503);
        }

        return Http::response(radarLiveTraffic('UNVERIFIED'));
    });

    $snapshot = app(ReadIranNetworkSummary::class)->handle();
    expect($snapshot['operators'][0]['code'])->toBe('suspected')
        ->and($snapshot['country']['code'])->toBe('suspected');
});
