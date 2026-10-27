<?php

namespace Tests\Unit;

use App\Console\Commands\AutoSyncCiencuadras;
use App\Console\Commands\AutoSyncFincaraiz;
use App\Console\Commands\AutoSyncProppit;
use App\Models\Integration;
use App\Models\PortalCredential;
use App\Models\PropertySyncStatus;
use App\Services\PortalCatalogAuditService;
use App\Services\Portals\CiencuadrasActiveProperties;
use App\Services\Portals\CiencuadrasClient;
use App\Services\Portals\CiencuadrasOperationState;
use App\Services\Portals\FincaraizClient;
use App\Services\Portals\MercadoLibreClient;
use App\Services\Portals\PortalSyncPolicy;
use App\Services\Portals\ProppitClient;
use App\Services\WordPressPropertyRepository;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Collection;
use Mockery;
use ReflectionMethod;
use Tests\TestCase;

class PortalSyncRegressionTest extends TestCase
{
    public function test_all_portals_detect_the_latest_source_change_and_retire_errors(): void
    {
        $public = (object) ['estado' => 'Publico', 'fecha_actualizacion' => '2026-01-01', 'cct_modified' => '2026-10-27'];
        $sync = new PropertySyncStatus(['sync_status' => 'synced', 'external_id' => '123', 'last_synced_at' => '2026-10-20']);
        foreach ([new AutoSyncCiencuadras(), new AutoSyncFincaraiz(), new AutoSyncProppit()] as $command) {
            $decision = (new ReflectionMethod($command, 'decision'))->invoke($command, $public, $sync);
            $this->assertSame('update', is_array($decision) ? $decision[0] : $decision);
            $failed = new PropertySyncStatus(['sync_status' => 'error', 'external_id' => '123']);
            $decision = (new ReflectionMethod($command, 'decision'))->invoke($command, (object) ['estado' => 'Arrendado'], $failed);
            $this->assertSame('pause', is_array($decision) ? $decision[0] : $decision);
        }
    }

    public function test_retries_are_delayed_and_unknown_identity_is_never_republished_after_an_edit(): void
    {
        $row = (object) ['cct_modified' => now()->subMinute()->toDateTimeString()];
        $sync = new PropertySyncStatus(['last_attempt_at' => now()->subDays(2), 'attempts' => 13000,
            'last_error' => 'remote_identity_unknown']);
        $this->assertFalse(PortalSyncPolicy::retryError($row, $sync));
        $sync->last_error = 'No quota';
        $this->assertTrue(PortalSyncPolicy::retryError((object) [], $sync));
        $sync->last_attempt_at = now();
        $this->assertFalse(PortalSyncPolicy::retryError((object) [], $sync));
        $sync->last_attempt_at = now()->subDays(2);
        $sync->last_error = 'Invalid address';
        $this->assertFalse(PortalSyncPolicy::retryError((object) [], $sync));
        $this->assertTrue(PortalSyncPolicy::retryError($row, $sync));
    }

    public function test_old_active_listing_does_not_confirm_pending_or_failed_updates(): void
    {
        $active = ['ok' => true, 'data' => ['message' => [['active' => 'Activo']]]];
        $this->assertSame('pending', CiencuadrasOperationState::resolve(['ok' => true], null, $active, 'A'));
        $this->assertSame('error', CiencuadrasOperationState::resolve(['ok' => true, 'data' => ['status' => 'error']], null, $active, 'A'));
        $this->assertSame('pending', CiencuadrasOperationState::resolve(null, ['ok' => true, 'data' => ['status' => 'success', 'message' => 'Pending to be processed']], $active, 'A'));
        $this->assertSame('synced', CiencuadrasOperationState::resolve(null, ['ok' => true, 'data' => [['message' => ['status' => 'success']]]], $active, 'A'));
        $this->assertSame('pending', CiencuadrasOperationState::resolve(null, null, $active, 'I'));
        $this->assertSame('paused', CiencuadrasOperationState::resolve(null, null, ['withdrawal_confirmed' => true], 'I'));
    }

    public function test_withdrawal_requires_absence_from_inventory_and_every_variant(): void
    {
        config(['portals.ciencuadras.property_code_prefix' => '22130-']);
        foreach ([
            [['propertyCode' => '22130-PP123']],
            [],
        ] as $inventory) {
            $client = Mockery::mock(CiencuadrasClient::class);
            $client->shouldReceive('consultAllProperties')->once()->andReturn(['ok' => true, 'data' => ['message' => $inventory]]);
            $client->shouldReceive('consultProperty')->withArgs(fn ($code, $cred) => in_array($code, ['22130-123', '22130-P123', '22130-PP123']))
                ->times(3)->andReturn(['ok' => true, 'data' => ['message' => 'El inmueble no existe']]);
            $result = (new CiencuadrasActiveProperties($client))->consultForWithdrawal('22130-P123', new PortalCredential());
            $this->assertSame($inventory === [], $result['result']['withdrawal_confirmed']);
        }
    }

    public function test_withdrawal_does_not_treat_a_malformed_response_as_missing(): void
    {
        config(['portals.ciencuadras.property_code_prefix' => '22130-']);
        $client = Mockery::mock(CiencuadrasClient::class);
        $client->shouldReceive('consultAllProperties')->andReturn(['ok' => true, 'data' => ['message' => []]]);
        $client->shouldReceive('consultProperty')->times(3)->andReturn(['ok' => true, 'data' => []]);
        $result = (new CiencuadrasActiveProperties($client))->consultForWithdrawal('123', new PortalCredential());
        $this->assertFalse($result['result']['withdrawal_confirmed']);
    }

    public function test_failed_variant_lookup_cannot_be_treated_as_a_missing_property(): void
    {
        config(['portals.ciencuadras.property_code_prefix' => '22130-']);
        $client = Mockery::mock(CiencuadrasClient::class);
        $client->shouldReceive('consultAllProperties')->andReturn(['ok' => true, 'data' => ['message' => []]]);
        $client->shouldReceive('consultProperties')->andReturn([
            '22130-123' => ['ok' => true, 'data' => ['message' => 'El inmueble no existe']],
            '22130-P123' => ['ok' => false, 'data' => ['error' => 'timeout']],
            '22130-PP123' => ['ok' => true, 'data' => ['message' => 'El inmueble no existe']],
        ]);
        $state = (new CiencuadrasActiveProperties($client))->inspectSourceCodes(['123'], new PortalCredential())->get('123');
        $this->assertSame('unavailable', $state['state']);
    }

    public function test_http_500_is_a_failure_even_when_guzzle_does_not_throw(): void
    {
        config(['portals.ciencuadras.api_url' => 'https://portal.test']);
        $http = new Client(['handler' => HandlerStack::create(new MockHandler([
            new Response(500, [], '{"message":"failure"}'),
            new Response(503, [], '{}'),
            new Response(502, [], '{}'),
        ])), 'http_errors' => false]);
        $client = new CiencuadrasClient($http);
        $this->assertFalse($client->updateProperty(['propertyCode' => '123'], new PortalCredential())['ok']);
        $this->assertFalse($client->login(['username' => 'test', 'password' => 'test'])['ok']);
        $this->assertFalse($client->consultProperties(['123'], new PortalCredential())['123']['ok']);
    }

    public function test_catalog_counts_raw_duplicates_and_normalizes_repeated_p_prefixes(): void
    {
        config(['portals.ciencuadras.property_code_prefix' => '22130-']);
        $inventory = Mockery::mock(CiencuadrasActiveProperties::class)->makePartial();
        $inventory->shouldReceive('inventory')->once()->with(true)->andReturn(collect([
            ['propertyCode' => '22130-123'], ['propertyCode' => '22130-P123'],
            ['propertyCode' => '22130-PP123'], ['propertyCode' => '22130-PP123'],
            ['propertyCode' => '22130-P456'],
        ]));
        $service = new class(Mockery::mock(WordPressPropertyRepository::class), $inventory,
            Mockery::mock(FincaraizClient::class), Mockery::mock(MercadoLibreClient::class), Mockery::mock(ProppitClient::class)) extends PortalCatalogAuditService {
            protected function registryCodes(Integration $integration, ?string $environment): Collection { return collect(); }
        };
        $result = (new ReflectionMethod($service, 'auditCiencuadras'))->invoke($service,
            new Integration(['slug' => 'ciencuadras']), collect(['123', '789']));
        $this->assertSame(5, $result['remote_rows']);
        $this->assertSame(2, $result['remote_active']);
        $this->assertSame(3, $result['duplicate_rows']);
        $this->assertSame(['456'], $result['details']['extra']);
        $this->assertSame(['789'], $result['details']['missing']);
    }
}
