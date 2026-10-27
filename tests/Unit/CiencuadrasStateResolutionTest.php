<?php

namespace Tests\Unit;

use App\Console\Commands\AutoSyncCiencuadras;
use App\Console\Commands\VerifyPendingCiencuadras;
use App\Http\Controllers\Portal\CiencuadrasController;
use App\Models\PropertySyncStatus;
use App\Models\PortalCredential;
use App\Services\Portals\CiencuadrasActiveProperties;
use App\Services\Portals\CiencuadrasClient;
use App\Services\Portals\CiencuadrasPropertyMapper;
use Mockery;
use ReflectionMethod;
use Tests\TestCase;

class CiencuadrasStateResolutionTest extends TestCase
{
    public function test_manual_action_preserves_timeout_error_despite_old_active_property(): void
    {
        $method = new ReflectionMethod(CiencuadrasController::class, 'syncState');
        $controller = new CiencuadrasController(
            Mockery::mock(CiencuadrasClient::class),
            Mockery::mock(CiencuadrasPropertyMapper::class),
            Mockery::mock(CiencuadrasActiveProperties::class),
        );

        $this->assertSame('error', $method->invoke(
            $controller,
            ['ok' => false, 'data' => ['error' => 'Idle timeout reached for update-property']],
            ['ok' => true, 'data' => ['status' => 'error', 'message' => 'Idle timeout reached']],
            'A',
            ['ok' => true, 'data' => ['message' => ['active' => 'Activo', 'status' => '0']]]
        ));
    }

    public function test_pending_verification_preserves_task_error_despite_old_active_property(): void
    {
        $method = new ReflectionMethod(VerifyPendingCiencuadras::class, 'syncState');

        $this->assertSame('error', $method->invoke(
            new VerifyPendingCiencuadras(),
            ['ok' => true, 'data' => ['status' => 'error', 'message' => 'Idle timeout reached']],
            ['ok' => true, 'data' => ['message' => ['active' => 'Activo', 'status' => '0']]],
            'pending',
            'A'
        ));
    }

    public function test_pending_verification_normalizes_active_inventory_listing(): void
    {
        $method = new ReflectionMethod(VerifyPendingCiencuadras::class, 'activeInventoryResult');

        $result = $method->invoke(new VerifyPendingCiencuadras(), [
            'state' => 'active',
            'portal_code' => '22130-P103104',
        ], 'A');

        $this->assertSame('22130-P103104', $result['code']);
        $this->assertSame('Activo', $result['result']['data']['message'][0]['active']);
        $this->assertSame('0', $result['result']['data']['message'][0]['status']);
    }

    public function test_auto_sync_preserves_timeout_error_despite_old_active_property(): void
    {
        $method = new ReflectionMethod(AutoSyncCiencuadras::class, 'syncState');

        $this->assertSame('error', $method->invoke(
            new AutoSyncCiencuadras(),
            ['ok' => false, 'data' => ['error' => 'Idle timeout reached for update-property']],
            ['ok' => true, 'data' => ['status' => 'error', 'message' => 'Idle timeout reached']],
            'A',
            ['ok' => true, 'data' => ['message' => ['active' => 'Activo', 'status' => '0']]]
        ));
    }

    public function test_manual_verification_falls_back_to_legacy_p_code(): void
    {
        config(['portals.ciencuadras.property_code_prefix' => '22130-']);

        $client = Mockery::mock(CiencuadrasClient::class);
        $client->shouldReceive('consultProperty')
            ->once()
            ->with('22130-103104', Mockery::type(PortalCredential::class))
            ->andReturn(['ok' => true, 'data' => [
                'message' => 'El inmueble que esta buscando no existe',
                'status' => 'error',
                'statusCode' => 126,
            ]]);
        $client->shouldReceive('consultProperty')
            ->once()
            ->with('22130-P103104', Mockery::type(PortalCredential::class))
            ->andReturn(['ok' => true, 'data' => [
                'message' => [['propertyCode' => '22130-P103104', 'active' => 'Activo', 'status' => '0']],
            ]]);

        $controller = new CiencuadrasController(
            $client,
            app(CiencuadrasPropertyMapper::class),
            Mockery::mock(CiencuadrasActiveProperties::class),
        );
        $method = new ReflectionMethod(CiencuadrasController::class, 'consultPropertyWithFallback');

        $result = $method->invoke($controller, '103104', new PortalCredential(['access_token' => 'token']));

        $this->assertSame('22130-P103104', $result['code']);
        $this->assertTrue($result['result']['ok']);
    }

    public function test_processed_request_is_synced_while_public_page_is_indexed(): void
    {
        $method = new ReflectionMethod(CiencuadrasController::class, 'verifiedSyncState');
        $controller = new CiencuadrasController(
            Mockery::mock(CiencuadrasClient::class),
            Mockery::mock(CiencuadrasPropertyMapper::class),
            Mockery::mock(CiencuadrasActiveProperties::class),
        );

        $this->assertSame('synced', $method->invoke(
            $controller,
            ['ok' => true, 'data' => [[
                'propertyCode' => '22130-103104',
                'message' => [
                    'status' => 'success',
                    'statusCode' => 100,
                    'propertyDetailUrl' => 'https://ciencuadras.com/inmueble/casa-en-arriendo-en-bayunca-cartagena-3821766',
                ],
            ]]],
            ['ok' => true, 'data' => [
                'message' => [['propertyCode' => '22130-P103104', 'active' => 'Eliminado', 'status' => '8']],
                'status' => 'success',
                'statusCode' => 100,
            ]],
            'pending',
            'A'
        ));
    }

    public function test_active_verification_ignores_deleted_legacy_fallback(): void
    {
        config(['portals.ciencuadras.property_code_prefix' => '22130-']);

        $client = Mockery::mock(CiencuadrasClient::class);
        $client->shouldReceive('consultProperty')
            ->once()
            ->with('22130-103104', Mockery::type(PortalCredential::class))
            ->andReturn(['ok' => true, 'data' => [
                'message' => 'El inmueble que esta buscando no existe',
                'status' => 'error',
                'statusCode' => 126,
            ]]);
        $client->shouldReceive('consultProperty')
            ->once()
            ->with('22130-P103104', Mockery::type(PortalCredential::class))
            ->andReturn(['ok' => true, 'data' => [
                'message' => [['propertyCode' => '22130-P103104', 'active' => 'Eliminado', 'status' => '8']],
            ]]);

        $controller = new CiencuadrasController(
            $client,
            app(CiencuadrasPropertyMapper::class),
            Mockery::mock(CiencuadrasActiveProperties::class),
        );
        $method = new ReflectionMethod(CiencuadrasController::class, 'consultPropertyWithFallback');

        $result = $method->invoke($controller, '103104', new PortalCredential(['access_token' => 'token']), 'A');

        $this->assertSame('22130-103104', $result['code']);
    }

    public function test_auto_sync_publishes_properties_without_history_or_missing_from_portal(): void
    {
        $method = new ReflectionMethod(AutoSyncCiencuadras::class, 'decision');
        $row = (object) ['estado' => 'Publicado', 'fecha_actualizacion' => null, 'cct_modified' => null];
        $command = new AutoSyncCiencuadras();

        $this->assertSame(['publish', 'A'], $method->invoke($command, $row, null, false));
        $this->assertNull($method->invoke(
            $command,
            $row,
            new PropertySyncStatus(['sync_status' => 'pending', 'attempts' => 1]),
            false
        ));
        $this->assertNull($method->invoke(
            $command,
            $row,
            new PropertySyncStatus(['sync_status' => 'error', 'attempts' => 1]),
            false
        ));
        $this->assertSame(['publish', 'A'], $method->invoke(
            $command,
            $row,
            new PropertySyncStatus(['sync_status' => 'not_synced', 'attempts' => 30]),
            false
        ));
        $this->assertSame(['update', 'A'], $method->invoke(
            $command,
            $row,
            new PropertySyncStatus(['sync_status' => 'error', 'attempts' => 1]),
            true
        ));
    }
}
