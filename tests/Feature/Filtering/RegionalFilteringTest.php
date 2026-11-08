<?php

declare(strict_types=1);

use App\Modules\Filtering\Application\FilteringFoundation;
use App\Modules\Filtering\Application\MonitorNodeFiltering;
use App\Modules\Filtering\Application\RegionalRoundClassifier;
use App\Modules\Filtering\Application\ScanRegionalFiltering;
use App\Modules\Filtering\Infrastructure\CheckHostRegionalProbe;
use App\Modules\Provisioning\Infrastructure\Remnawave\RemnawaveNodeSummary;
use App\Support\Health\NodeTcpProbe;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Sleep;

uses(RefreshDatabase::class);

const FLT03_NODE = '550e8400-e29b-41d4-a716-446655440030';
const FLT03_INBOUND = '550e8400-e29b-41d4-a716-446655440031';

beforeEach(function (): void {
    $this->travelTo(Carbon::parse('2026-11-07 12:00:00 UTC'));
    config()->set('filtering.regional_monitor_enabled', true);
    config()->set('filtering.regional_alerts_enabled', false);
    config()->set('telegram_notifications.enabled', false);
    config()->set('services.remnawave.url', 'https://flt03.test');
    config()->set('services.remnawave.token', 'fake');
    config()->set('cache.default', 'array');
    $GLOBALS['flt03_failed'] = [];
    $GLOBALS['flt03_pending'] = [];
    $GLOBALS['flt03_delay_results'] = false;
    $GLOBALS['flt03_pending_until_poll'] = 0;
    $GLOBALS['flt03_message_failure'] = false;
    $GLOBALS['flt03_message_unknown'] = false;
    $GLOBALS['flt03_wrong_address'] = false;
    $GLOBALS['flt03_result_http_error'] = false;
    $GLOBALS['flt03_result_poll_count'] = 0;
    $GLOBALS['flt03_rate_limit'] = false;
    $GLOBALS['flt03_control_bad'] = false;
    $GLOBALS['flt03_bad_metadata'] = false;
    $GLOBALS['flt03_metadata_override'] = null;
    $GLOBALS['flt03_requested_nodes'] = [];
    Queue::fake();
    Sleep::fake();
    Http::preventStrayRequests();
    Http::fake(static function (Request $request) {
        $url = $request->url();
        if ($url === 'https://flt03.test/api/nodes') {
            return Http::response(['response' => [[
                'uuid' => FLT03_NODE, 'address' => '8.8.8.8', 'port' => 2222,
                'isConnected' => true, 'isDisabled' => false,
                'configProfile' => ['activeInbounds' => [['uuid' => FLT03_INBOUND, 'port' => 443]]],
            ]]]);
        }
        if ($url === 'https://flt03.test/api/hosts') {
            return Http::response(['response' => [[
                'address' => '8.8.8.8', 'port' => 443, 'isDisabled' => false,
                'inbound' => ['configProfileInboundUuid' => FLT03_INBOUND],
            ]]]);
        }
        if ($GLOBALS['flt03_rate_limit']) {
            return Http::response(['error' => 'rate limited'], 429);
        }
        if ($url === 'https://check-host.net/nodes/hosts') {
            return Http::response(['nodes' => $GLOBALS['flt03_bad_metadata']
                ? [] : ($GLOBALS['flt03_metadata_override'] ?? flt03Meta())]);
        }
        if (str_starts_with($url, 'https://check-host.net/check-tcp?')) {
            $control = str_contains($url, '1.1.1.1%3A443');
            preg_match_all('/[?&]node=([^&]+)/', $url, $matches);
            $GLOBALS['flt03_requested_nodes'] = array_map('rawurldecode', $matches[1]);
            $nodes = [];
            foreach ($GLOBALS['flt03_requested_nodes'] as $name) {
                $nodes[$name] = ['ir', 'Iran', 'Tehran', '1.1.1.1', 'AS123'];
            }

            return Http::response(['ok' => 1, 'request_id' => $control ? 'control123' : 'primary123', 'nodes' => $nodes]);
        }
        if ($url === 'https://check-host.net/check-result/primary123'
            || $url === 'https://check-host.net/check-result/control123') {
            $control = str_ends_with($url, '/control123');
            if (! $control && $GLOBALS['flt03_result_http_error']) {
                return Http::response(['error' => 'unavailable'], 503);
            }
            $poll = $control ? 0 : ++$GLOBALS['flt03_result_poll_count'];
            $result = [];
            foreach ($GLOBALS['flt03_requested_nodes'] as $name) {
                preg_match('/\\Air([1-8])\\.node\\.check-host\\.net\\z/D', $name, $nodeMatch);
                $i = (int) $nodeMatch[1];
                $failed = $control ? $GLOBALS['flt03_control_bad'] : in_array($i, $GLOBALS['flt03_failed'], true);
                $result[$name] = (in_array($i, $GLOBALS['flt03_pending'], true)
                    || ($GLOBALS['flt03_delay_results'] && $poll === 1)
                    || (! $control && $poll <= $GLOBALS['flt03_pending_until_poll']))
                    ? null : ($failed
                        ? ($GLOBALS['flt03_message_failure'] ? [['message' => 'Connection timed out']] : [['error' => 'Timeout']])
                        : ($GLOBALS['flt03_message_unknown'] ? [['message' => 'Still checking']]
                            : [['time' => 0.03, 'address' => $control ? '1.1.1.1'
                                : ($GLOBALS['flt03_wrong_address'] ? '9.9.9.9' : '8.8.8.8')]]));
            }

            return Http::response($result);
        }

        return Http::response(['error' => 'unexpected'], 503);
    });
});

/** @return array<string, mixed> */
function flt03Meta(): array
{
    $nodes = [];
    for ($i = 1; $i <= 8; $i++) {
        $nodes["ir{$i}.node.check-host.net"] = [
            'asn' => 'AS'.(1000 + $i), 'ip' => '1.1.1.1',
            'location' => ['ir', 'Iran', $i <= 4 ? 'Tehran' : 'Shiraz'],
        ];
    }

    return $nodes;
}

function flt03Scan(int $minutes = 0): void
{
    if ($minutes > 0) {
        Carbon::setTestNow(now()->addMinutes($minutes));
    }
    app(ScanRegionalFiltering::class)->scan();
}

it('is gated off in deployment defaults and uses a nonoverlapping ten-minute background scheduler', function (): void {
    $default = require base_path('config/filtering.php');
    expect($default['regional_monitor_enabled'])->toBeFalse()
        ->and($default['regional_alerts_enabled'])->toBeFalse();
    config()->set('filtering.regional_monitor_enabled', false);
    expect(app(ScanRegionalFiltering::class)->scan())->toBe(0);
    Http::assertNothingSent();
    Artisan::call('list', ['--raw' => true]);
    expect(Artisan::output())->toContain('filtering:regional:scan');
    $event = collect(Schedule::events())->first(
        static fn ($event): bool => str_contains((string) $event->command, 'filtering:regional:scan'),
    );
    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('*/10 * * * *')
        ->and($event->withoutOverlapping)->toBeTrue()
        ->and($event->runInBackground)->toBeTrue();
});

it('uses only current verified RW direct targets and the existing read-only client', function (): void {
    expect(app(NodeTcpProbe::class)->targets(true))->toBe([
        FLT03_NODE => ['ip' => '8.8.8.8', 'port' => 443],
    ]);
    Http::assertSentCount(2);
    Http::assertSent(static fn (Request $r): bool => $r->method() === 'GET');
});

it('normalizes successful, failed and pending probes and preserves ASN diversity', function (): void {
    $GLOBALS['flt03_failed'] = [1, 2];
    $GLOBALS['flt03_pending'] = [3];
    $provider = app(CheckHostRegionalProbe::class);
    $nodes = $provider->iranNodes();
    expect($nodes)->toHaveCount(8);
    $result = $provider->tcp('8.8.8.8', 443, $nodes);
    expect($result)->toMatchArray([
        'completed_probes' => 7, 'failed_probes' => 2, 'pending_probes' => 1,
        'asn_count' => 7, 'failed_asn_count' => 2,
    ]);
    expect(RegionalRoundClassifier::classify($result))->toBe('possible');
    Http::assertSent(static fn (Request $r): bool => str_contains($r->url(), 'host=8.8.8.8%3A443')
        && substr_count($r->url(), '&node=') === 8);
});

it('waits for delayed Iran results and stops after all eight TCP responses are valid', function (): void {
    $GLOBALS['flt03_delay_results'] = true;
    flt03Scan();

    $result = DB::table('filtering_checks')->where('module', 'regional')->first();
    $evidence = json_decode($result->evidence ?? '{}', true);
    expect($result->outcome)->toBe('healthy')
        ->and($evidence['completed_probes'])->toBe(8)
        ->and($evidence['pending_probes'])->toBe(0)
        ->and($GLOBALS['flt03_result_poll_count'])->toBe(2);
});

it('keeps polling bounded delayed results beyond the old four-second window', function (): void {
    $GLOBALS['flt03_pending_until_poll'] = 5;
    $provider = app(CheckHostRegionalProbe::class);
    $nodes = $provider->iranNodes();
    $result = $provider->tcp('8.8.8.8', 443, $nodes);

    expect($result['completed_probes'])->toBe(8)
        ->and($result['pending_probes'])->toBe(0)
        ->and($GLOBALS['flt03_result_poll_count'])->toBe(6);
});

it('skips near-zero Check-Host result timeouts instead of passing them to Guzzle', function (): void {
    $timeout = new ReflectionMethod(CheckHostRegionalProbe::class, 'resultPollTimeout');

    expect($timeout->invoke(null, -1))->toBeNull()
        ->and($timeout->invoke(null, 0))->toBeNull()
        ->and($timeout->invoke(null, 999_999))->toBeNull()
        ->and($timeout->invoke(null, 99_999_999))->toBeNull()
        ->and($timeout->invoke(null, 100_000_000))->toBe(0.1)
        ->and($timeout->invoke(null, 1_500_000_000))->toBe(1.5)
        ->and($timeout->invoke(null, 9_000_000_000))->toBe(6.0);
});

it('retains completed TCP evidence when one regional result stays pending', function (): void {
    $GLOBALS['flt03_pending'] = [8];
    $GLOBALS['flt03_failed'] = [1];
    Log::spy();

    $provider = app(CheckHostRegionalProbe::class);
    $result = $provider->tcp('8.8.8.8', 443, $provider->iranNodes());

    expect($result['completed_probes'])->toBe(7)
        ->and($result['failed_probes'])->toBe(1)
        ->and($result['successful_probes'])->toBe(6)
        ->and($result['pending_probes'])->toBe(1)
        ->and($GLOBALS['flt03_result_poll_count'])->toBe(9);
});

it('preserves pending on exhausted polling budget without inventing TCP failures', function (): void {
    $GLOBALS['flt03_pending'] = range(1, 8);
    Log::spy();
    $provider = app(CheckHostRegionalProbe::class);
    $result = $provider->tcp('8.8.8.8', 443, $provider->iranNodes());

    expect($result['completed_probes'])->toBe(0)
        ->and($result['failed_probes'])->toBe(0)
        ->and($result['pending_probes'])->toBe(8)
        ->and($GLOBALS['flt03_result_poll_count'])->toBe(9);
    Log::shouldHaveReceived('notice')->once()->withArgs(
        static fn (string $message, array $context): bool => $message === 'Filtering Check-Host TCP results incomplete'
            && $context['pending'] === 8 && $context['polls'] === 9
            && $context['progress'][0] === ['completed' => 0, 'pending' => 8, 'invalid' => 0],
    );
});

it('recognizes explicit TCP connection messages but never interprets ambiguous messages or mismatched addresses as failure', function (): void {
    $provider = app(CheckHostRegionalProbe::class);
    $nodes = $provider->iranNodes();

    $GLOBALS['flt03_failed'] = [1, 2];
    $GLOBALS['flt03_message_failure'] = true;
    $result = $provider->tcp('8.8.8.8', 443, $nodes);
    expect($result['completed_probes'])->toBe(8)
        ->and($result['failed_probes'])->toBe(2);

    $GLOBALS['flt03_failed'] = [];
    $GLOBALS['flt03_message_failure'] = false;
    $GLOBALS['flt03_message_unknown'] = true;
    $result = $provider->tcp('8.8.8.8', 443, $nodes);
    expect($result['completed_probes'])->toBe(0)
        ->and($result['failed_probes'])->toBe(0)
        ->and($result['pending_probes'])->toBe(8);

    $GLOBALS['flt03_message_unknown'] = false;
    $GLOBALS['flt03_wrong_address'] = true;
    $result = $provider->tcp('8.8.8.8', 443, $nodes);
    expect($result['completed_probes'])->toBe(0)
        ->and($result['failed_probes'])->toBe(0)
        ->and($result['pending_probes'])->toBe(8);
});

it('logs sanitized provider transport failures and never treats HTTP 503 as TCP failure', function (): void {
    $GLOBALS['flt03_result_http_error'] = true;
    Log::spy();
    flt03Scan();

    $row = DB::table('filtering_checks')->where('module', 'regional')->first();
    expect($row->outcome)->toBe('unknown')
        ->and(json_decode($row->evidence ?? '{}', true))->toBe([]);
    Log::shouldHaveReceived('warning')->once()->withArgs(
        static fn (string $message, array $context): bool => $message === 'Filtering Check-Host request unavailable'
            && $context['phase'] === 'result' && $context['http_status'] === 503
            && ! array_key_exists('url', $context) && ! array_key_exists('ip', $context),
    );
});

it('enforces completed-probe, ASN, city and failure quorum thresholds', function (): void {
    $base = ['completed_probes' => 8, 'failed_probes' => 0, 'asn_count' => 8,
        'city_count' => 2, 'failed_asn_count' => 0, 'failed_city_count' => 0];
    expect(RegionalRoundClassifier::classify($base))->toBe('clear')
        ->and(RegionalRoundClassifier::classify([...$base, 'failed_probes' => 2, 'failed_asn_count' => 2]))->toBe('possible')
        ->and(RegionalRoundClassifier::classify([...$base, 'failed_probes' => 4, 'failed_asn_count' => 4, 'failed_city_count' => 2]))->toBe('possible')
        ->and(RegionalRoundClassifier::classify([...$base, 'failed_probes' => 5, 'failed_asn_count' => 5, 'failed_city_count' => 2]))->toBe('likely')
        ->and(RegionalRoundClassifier::classify([...$base, 'failed_probes' => 5, 'asn_count' => 3]))->toBe('unknown');
});

it('opens one composite incident and escalates only after two likely rounds with a healthy independent control', function (): void {
    $GLOBALS['flt03_failed'] = [1, 2];
    flt03Scan();
    $first = app(FilteringFoundation::class)->read(FLT03_NODE);
    expect($first['state'])->toBe('suspicious');
    $GLOBALS['flt03_failed'] = [1, 2, 3, 5, 6];
    flt03Scan(10);
    expect(app(FilteringFoundation::class)->read(FLT03_NODE)['state'])->toBe('suspicious');
    flt03Scan(10);
    expect(app(FilteringFoundation::class)->read(FLT03_NODE)['state'])->toBe('disrupted')
        ->and(app(FilteringFoundation::class)->read(FLT03_NODE)['incident_id'])->toBe($first['incident_id']);
    expect(DB::table('filtering_incidents')->count())->toBe(1);
    Http::assertSent(static fn (Request $r): bool => str_contains($r->url(), 'host=1.1.1.1%3A443'));
});

it('requires two eligible clear rounds for recovery and does not recover on pending or API failure', function (): void {
    $GLOBALS['flt03_failed'] = [1, 2];
    flt03Scan();
    $old = app(FilteringFoundation::class)->read(FLT03_NODE)['incident_id'];
    $GLOBALS['flt03_pending'] = [1, 2, 3, 4, 5];
    $GLOBALS['flt03_failed'] = [];
    flt03Scan(10);
    expect(app(FilteringFoundation::class)->read(FLT03_NODE)['incident_id'])->toBe($old);
    $GLOBALS['flt03_pending'] = [];
    flt03Scan(10);
    expect(app(FilteringFoundation::class)->read(FLT03_NODE)['incident_id'])->toBe($old);
    flt03Scan(10);
    expect(app(FilteringFoundation::class)->read(FLT03_NODE)['incident_id'])->toBeNull()
        ->and(DB::table('filtering_incidents')->where('state', 'resolved')->count())->toBe(1);
});

it('fails closed for rate limits, insufficient coverage and invalid metadata', function (): void {
    $GLOBALS['flt03_rate_limit'] = true;
    flt03Scan();
    expect(app(FilteringFoundation::class)->read(FLT03_NODE)['state'])->toBe('unknown');
    $GLOBALS['flt03_rate_limit'] = false;
    $GLOBALS['flt03_bad_metadata'] = true;
    flt03Scan(10);
    expect(DB::table('filtering_incidents')->count())->toBe(0);
});

it('backs off all regional provider requests after a 429 response', function (): void {
    $GLOBALS['flt03_rate_limit'] = true;
    expect(fn () => app(CheckHostRegionalProbe::class)->iranNodes())->toThrow(RuntimeException::class);
    Http::assertSentCount(1);

    $GLOBALS['flt03_rate_limit'] = false;
    expect(fn () => app(CheckHostRegionalProbe::class)->iranNodes())->toThrow(RuntimeException::class);
    Http::assertSentCount(1);

    $this->travel(16)->minutes();
    expect(app(CheckHostRegionalProbe::class)->iranNodes())->toHaveCount(8);
    Http::assertSentCount(2);
});

it('queues a guarded regional confirmation when a Node signal joins an existing composite incident', function (): void {
    config()->set('filtering.node_monitor_enabled', true);
    app(FilteringFoundation::class)->record(FLT03_NODE, 'regional', 'suspicious', now(), [
        'causes' => ['regional_possible'],
    ]);
    $make = static fn (int $users): RemnawaveNodeSummary => new RemnawaveNodeSummary(
        uuid: FLT03_NODE, name: 'Node', connected: true, disabled: false,
        usersOnline: $users, rxBytesPerSec: 50, txBytesPerSec: 50,
    );
    $monitor = app(MonitorNodeFiltering::class);
    $this->travel(1)->minutes();
    $monitor->observe($make(2), CarbonImmutable::instance(now())->startOfMinute());
    $this->travel(1)->minutes();
    $monitor->observe($make(0), CarbonImmutable::instance(now())->startOfMinute());
    $this->travel(1)->minutes();
    $monitor->observe($make(0), CarbonImmutable::instance(now())->startOfMinute());

    expect(app(FilteringFoundation::class)->read(FLT03_NODE)['affected_modules'])->toHaveKeys(['regional', 'node'])
        ->and(Cache::get('filtering:regional:extra:node:'.FLT03_NODE))->toBeTrue();
});

it('rejects private and reserved destinations before any TCP provider request', function (): void {
    $provider = app(CheckHostRegionalProbe::class);
    $nodes = $provider->iranNodes();
    expect(fn () => $provider->tcp('127.0.0.1', 443, $nodes))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $provider->tcp('169.254.169.254', 80, $nodes))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $provider->tcp('8.8.8.8', 0, $nodes))->toThrow(InvalidArgumentException::class);
    Http::assertSentCount(1);
});

it('does not promote repeated POSSIBLE or failed external control to LIKELY', function (): void {
    $GLOBALS['flt03_failed'] = [1, 2, 3, 5];
    flt03Scan();
    flt03Scan(10);
    expect(app(FilteringFoundation::class)->read(FLT03_NODE)['state'])->toBe('suspicious');
    $GLOBALS['flt03_failed'] = [1, 2, 3, 5, 6];
    flt03Scan(10);
    $GLOBALS['flt03_control_bad'] = true;
    flt03Scan(10);
    expect(app(FilteringFoundation::class)->read(FLT03_NODE)['state'])->toBe('suspicious');
});
