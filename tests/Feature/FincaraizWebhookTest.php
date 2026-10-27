<?php

namespace Tests\Feature;

use App\Http\Controllers\Portal\FincaraizController;
use App\Models\Integration;
use App\Models\Property;
use App\Models\PropertySyncStatus;
use App\Services\Portals\FincaraizClient;
use App\Services\Portals\FincaraizPropertyMapper;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class FincaraizWebhookTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('portals.fincaraiz.environment', 'qa');
        config()->set('portals.fincaraiz.api_key', 'qa-secret-key');
        config()->set('portals.fincaraiz.client_id', 'df03d199-be5c-4c5c-98f6-849361cb7fae');
        config()->set('portals.fincaraiz.page_url', 'https://www.fincaraiz.com.co');
        config()->set('portals.fincaraiz.webhook_id', 'hub-qa');
        config()->set('portals.fincaraiz.webhook_verify_token', 'verify-secret');
        $this->createTables();
    }

    public function test_webhook_confirms_listing_id_and_leaves_new_listing_ready_for_activation(): void
    {
        $integration = Integration::create(['name' => 'Fincaraíz', 'slug' => 'fincaraiz']);
        $property = Property::create(['code' => '53824', 'title' => 'Apartamento']);
        PropertySyncStatus::create([
            'property_id' => $property->id,
            'integration_id' => $integration->id,
            'environment' => 'qa',
            'portal_variant' => 'default',
            'sync_status' => 'pending',
            'last_response' => ['action' => 'publish', 'task_id' => 'task-1'],
        ]);
        $request = Request::create('/api/portals/fincaraiz/webhook', 'POST', [], [], [], [], json_encode([
            'task' => [
                'id' => 'task-1',
                'status' => 'COMPLETED',
                'content' => [[
                    'status' => 'COMPLETED',
                    'external_code' => '53824',
                    'listing_id' => '07bcf513-d39a-42ff-8370-f42d39cd9494',
                    'fr_property_id' => 10002073,
                ]],
            ],
        ]));
        $request->headers->set('Content-Type', 'application/json');
        $request->headers->set('HUB.ID', 'hub-qa');
        $request->headers->set('VERIFY-TOKEN', 'verify-secret');

        $controller = $this->controller();
        $response = $controller->webhook($request);
        $controller->webhook($request);

        $sync = PropertySyncStatus::first();
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(1, $response->getData(true)['Datos']['processed']);
        $this->assertSame('pending', $sync->sync_status);
        $this->assertSame('07bcf513-d39a-42ff-8370-f42d39cd9494', $sync->external_id);
        $this->assertSame('activate_required', $sync->last_response['action']);
        $this->assertTrue($sync->last_response['requires_activation']);
    }

    public function test_webhook_rejects_an_invalid_verify_token(): void
    {
        Integration::create(['name' => 'Fincaraíz', 'slug' => 'fincaraiz']);
        $request = Request::create('/api/portals/fincaraiz/webhook', 'POST');
        $request->headers->set('HUB.ID', 'hub-qa');
        $request->headers->set('VERIFY-TOKEN', 'wrong');

        $response = $this->controller()->webhook($request);

        $this->assertSame(401, $response->getStatusCode());
    }

    public function test_recovery_updates_then_requests_activation_for_a_disabled_listing(): void
    {
        $integration = Integration::create(['name' => 'Fincaraíz', 'slug' => 'fincaraiz']);
        $property = Property::create(['code' => '96957', 'title' => 'Apartamento']);
        PropertySyncStatus::create([
            'property_id' => $property->id,
            'integration_id' => $integration->id,
            'environment' => 'qa',
            'portal_variant' => 'default',
            'sync_status' => 'error',
            'external_id' => 'f92707a7-9f28-48c2-9937-9d81580da98e',
            'last_response' => ['action' => 'activate'],
        ]);

        $client = $this->createMock(FincaraizClient::class);
        $client->expects($this->once())->method('getListing')->willReturn([
            'ok' => true,
            'status' => 200,
            'data' => ['id' => 'f92707a7-9f28-48c2-9937-9d81580da98e', 'status' => 1, 'frPropertyId' => 194071131],
        ]);
        $client->expects($this->once())->method('updateListing')->willReturn([
            'ok' => true,
            'status' => 202,
            'data' => ['task' => ['id' => 'task-update-disabled']],
        ]);
        $mapper = $this->createMock(FincaraizPropertyMapper::class);
        $mapper->expects($this->once())->method('map')->willReturn([
            'property' => $property,
            'payload' => ['external_code' => '96957'],
            'errors' => [],
            'warnings' => [],
            'source' => [],
        ]);

        $response = (new FincaraizController($client, $mapper))->recover(new Request, '96957');
        $sync = PropertySyncStatus::first();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('update_activate', $response->getData(true)['Datos']['recovery_action']);
        $this->assertSame('pending', $sync->sync_status);
        $this->assertTrue($sync->last_response['activate_after_update']);
        $this->assertSame('194071131', $sync->last_response['fr_property_id']);
    }

    public function test_completed_update_is_not_marked_published_while_listing_is_disabled(): void
    {
        $integration = Integration::create(['name' => 'Fincaraíz', 'slug' => 'fincaraiz']);
        $property = Property::create(['code' => '97256', 'title' => 'Apartamento']);
        PropertySyncStatus::create([
            'property_id' => $property->id,
            'integration_id' => $integration->id,
            'environment' => 'qa',
            'portal_variant' => 'default',
            'sync_status' => 'pending',
            'external_id' => 'e0f671ee-8943-4df7-916b-a6fe5cf2b440',
            'last_response' => ['action' => 'update', 'task_id' => 'task-update'],
        ]);

        $client = $this->createMock(FincaraizClient::class);
        $client->expects($this->once())->method('getTask')->willReturn([
            'ok' => true,
            'status' => 200,
            'data' => ['task' => [
                'id' => 'task-update',
                'status' => 'COMPLETED',
                'content' => [[
                    'status' => 'COMPLETED',
                    'external_code' => '97256',
                    'listing_id' => 'e0f671ee-8943-4df7-916b-a6fe5cf2b440',
                    'fr_property_id' => 194071133,
                ]],
            ]],
        ]);
        $client->expects($this->once())->method('getListing')->willReturn([
            'ok' => true,
            'status' => 200,
            'data' => ['id' => 'e0f671ee-8943-4df7-916b-a6fe5cf2b440', 'status' => 1, 'frPropertyId' => 194071133],
        ]);

        $response = $this->controller($client)->verify(new Request, '97256');
        $sync = PropertySyncStatus::first();

        $this->assertSame('pending', $response->getData(true)['Datos']['sync_status']);
        $this->assertTrue($response->getData(true)['Datos']['requires_activation']);
        $this->assertSame('pending', $sync->sync_status);
        $this->assertSame('activate_required', $sync->last_response['action']);
        $this->assertNull($sync->external_url);
    }

    public function test_completed_update_is_published_only_after_listing_is_confirmed_active(): void
    {
        $integration = Integration::create(['name' => 'Fincaraíz', 'slug' => 'fincaraiz']);
        $property = Property::create(['code' => '97950', 'title' => 'Apartamento']);
        PropertySyncStatus::create([
            'property_id' => $property->id,
            'integration_id' => $integration->id,
            'environment' => 'qa',
            'portal_variant' => 'default',
            'sync_status' => 'pending',
            'external_id' => '96042c20-52b9-4ccf-a85d-6bcc43572c46',
            'last_response' => ['action' => 'update', 'task_id' => 'task-active'],
        ]);

        $client = $this->createMock(FincaraizClient::class);
        $client->expects($this->once())->method('getTask')->willReturn([
            'ok' => true,
            'status' => 200,
            'data' => ['task' => [
                'id' => 'task-active',
                'status' => 'COMPLETED',
                'content' => [[
                    'status' => 'COMPLETED',
                    'external_code' => '97950',
                    'listing_id' => '96042c20-52b9-4ccf-a85d-6bcc43572c46',
                    'fr_property_id' => 194071137,
                ]],
            ]],
        ]);
        $client->expects($this->once())->method('getListing')->willReturn([
            'ok' => true,
            'status' => 200,
            'data' => ['id' => '96042c20-52b9-4ccf-a85d-6bcc43572c46', 'status' => 4, 'frPropertyId' => 194071137],
        ]);

        $response = $this->controller($client)->verify(new Request, '97950');
        $sync = PropertySyncStatus::first();

        $this->assertSame('synced', $response->getData(true)['Datos']['sync_status']);
        $this->assertSame('https://www.fincaraiz.com.co/detalle/194071137', $sync->external_url);
        $this->assertNull($sync->last_error);
    }

    public function test_expired_update_is_not_confirmed_by_an_old_active_listing(): void
    {
        $integration = Integration::create(['name' => 'Fincaraíz', 'slug' => 'fincaraiz']);
        $property = Property::create(['code' => '123', 'title' => 'Casa']);
        PropertySyncStatus::create([
            'property_id' => $property->id, 'integration_id' => $integration->id,
            'environment' => 'qa', 'sync_status' => 'pending', 'external_id' => 'listing-123',
            'last_synced_at' => '2026-01-01 00:00:00',
            'last_response' => ['action' => 'update', 'task_id' => 'expired'],
        ]);
        $client = $this->createMock(FincaraizClient::class);
        $client->expects($this->once())->method('getTask')->willReturn([
            'ok' => false, 'status' => 404, 'data' => ['detail' => 'Task id not found'],
        ]);
        $client->expects($this->once())->method('getListing')->willReturn([
            'ok' => true, 'data' => ['id' => 'listing-123', 'status' => 4],
        ]);
        $response = $this->controller($client)->verify(Request::create('/verify', 'POST'), '123');
        $sync = PropertySyncStatus::first();
        $this->assertSame(409, $response->getStatusCode());
        $this->assertSame('error', $sync->sync_status);
        $this->assertStringContainsString('task_expired', $sync->last_error);
        $this->assertSame('2026-01-01', $sync->last_synced_at->toDateString());
    }

    public function test_expired_task_without_listing_id_requires_reconciliation_before_recovery(): void
    {
        $integration = Integration::create(['name' => 'Fincaraíz', 'slug' => 'fincaraiz']);
        $property = Property::create(['code' => '123', 'title' => 'Casa']);
        PropertySyncStatus::create([
            'property_id' => $property->id, 'integration_id' => $integration->id,
            'environment' => 'qa', 'sync_status' => 'pending',
            'last_response' => ['action' => 'publish', 'task_id' => 'expired'],
        ]);
        $client = $this->createMock(FincaraizClient::class);
        $client->expects($this->once())->method('getTask')->willReturn([
            'ok' => false, 'status' => 404, 'data' => ['detail' => 'Task id not found'],
        ]);
        $client->expects($this->never())->method('getListing');
        $client->expects($this->never())->method('createListing');
        $controller = $this->controller($client);
        $controller->verify(Request::create('/verify', 'POST'), '123');
        $sync = PropertySyncStatus::first();
        $this->assertSame('error', $sync->sync_status);
        $this->assertStringContainsString('remote_identity_unknown', $sync->last_error);
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $controller->recover(Request::create('/recover', 'POST'), '123');
    }

    private function controller(?FincaraizClient $client = null): FincaraizController
    {
        return new FincaraizController(
            $client ?? new class extends FincaraizClient
            {
                public function __construct() {}
            },
            new FincaraizPropertyMapper
        );
    }

    private function createTables(): void
    {
        Schema::dropIfExists('property_sync_statuses');
        Schema::dropIfExists('properties');
        Schema::dropIfExists('integrations');

        Schema::create('integrations', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->timestamps();
        });
        Schema::create('properties', function (Blueprint $table): void {
            $table->id();
            $table->string('code')->unique();
            $table->string('title');
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('property_sync_statuses', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('property_id');
            $table->unsignedBigInteger('integration_id');
            $table->string('environment')->default('production');
            $table->string('portal_variant')->default('default');
            $table->string('sync_status')->default('not_synced');
            $table->string('external_id')->nullable();
            $table->string('external_url')->nullable();
            $table->json('last_response')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamp('last_attempt_at')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamps();
        });
    }
}
