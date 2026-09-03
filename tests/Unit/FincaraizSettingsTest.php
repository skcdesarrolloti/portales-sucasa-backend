<?php

namespace Tests\Unit;

use App\Http\Controllers\Portal\FincaraizController;
use App\Models\PortalCredential;
use App\Models\User;
use App\Services\Portals\FincaraizClient;
use App\Services\Portals\FincaraizPropertyMapper;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class FincaraizSettingsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('portal_credentials');
        Schema::dropIfExists('integrations');
        Schema::create('integrations', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
        });
        Schema::create('portal_credentials', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('integration_id');
            $table->string('account_key')->nullable();
            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->timestamp('access_token_expires_at')->nullable();
            $table->json('data')->nullable();
            $table->timestamps();
        });
        DB::table('integrations')->insert(['id' => 1, 'slug' => 'fincaraiz']);
    }

    public function test_panel_settings_encrypt_the_api_key_and_return_only_safe_fields(): void
    {
        $user = new User;
        $user->id = 15;
        $user->exists = true;
        $request = Request::create('/api/portals/fincaraiz/settings', 'PATCH', [
            'api_key' => 'qa-secret-key',
            'client_id' => 'df03d199-be5c-4c5c-98f6-849361cb7fae',
            'client_agent' => 1234,
            'contact_email' => 'asesor@example.com',
            'contact_phone' => '3001234567',
            'contact_whatsapp' => '3001234567',
            'show_exact_address' => false,
            'dual_offer' => 'rent',
        ]);
        $request->setUserResolver(fn () => $user);

        $controller = new FincaraizController(
            $this->createMock(FincaraizClient::class),
            $this->createMock(FincaraizPropertyMapper::class)
        );
        $response = $controller->saveSettings($request);
        $credential = PortalCredential::firstOrFail();
        $data = $response->getData(true)['Datos'];

        $this->assertSame('qa-secret-key', $credential->access_token);
        $this->assertNotSame('qa-secret-key', DB::table('portal_credentials')->value('access_token'));
        $this->assertSame('panel', $data['api_key_source']);
        $this->assertTrue($data['configured']);
        $this->assertArrayNotHasKey('api_key', $data);
        $this->assertSame(1234, $data['client_agent']);
        $this->assertSame('rent', $data['dual_offer']);
    }

    public function test_saving_the_same_client_preserves_its_catalog_snapshot(): void
    {
        $clientId = 'df03d199-be5c-4c5c-98f6-849361cb7fae';
        PortalCredential::create([
            'user_id' => 15,
            'integration_id' => 1,
            'account_key' => 'user:15',
            'access_token' => 'production-key',
            'data' => [
                'client_id' => $clientId,
                'fincaraiz_catalog_snapshot' => ['codes' => ['100', '200']],
            ],
        ]);
        $user = new User;
        $user->id = 15;
        $user->exists = true;
        $request = Request::create('/api/portals/fincaraiz/settings', 'PATCH', [
            'client_id' => $clientId,
            'client_agent' => 1234,
            'contact_email' => 'asesor@example.com',
            'contact_phone' => '3001234567',
            'contact_whatsapp' => '3001234567',
            'show_exact_address' => false,
            'dual_offer' => 'rent',
        ]);
        $request->setUserResolver(fn () => $user);

        $controller = new FincaraizController(
            $this->createMock(FincaraizClient::class),
            $this->createMock(FincaraizPropertyMapper::class)
        );
        $controller->saveSettings($request);

        $this->assertSame(
            ['codes' => ['100', '200']],
            PortalCredential::firstOrFail()->data['fincaraiz_catalog_snapshot']
        );
    }

    public function test_client_info_only_exposes_the_client_configured_for_this_installation(): void
    {
        $clientId = '2cc4b423-621c-47d5-a798-041ef44bb9ec';
        PortalCredential::create([
            'user_id' => 15,
            'integration_id' => 1,
            'account_key' => 'user:15',
            'access_token' => 'production-key',
            'data' => ['client_id' => $clientId],
        ]);
        $user = new User;
        $user->id = 15;
        $user->exists = true;
        $request = Request::create('/api/portals/fincaraiz/client', 'GET');
        $request->setUserResolver(fn () => $user);

        $client = $this->createMock(FincaraizClient::class);
        $client->expects($this->once())
            ->method('getClientInfo')
            ->with('production-key')
            ->willReturn([
                'ok' => true,
                'status' => 200,
                'data' => [
                    ['id' => $clientId, 'name' => 'Cliente de esta base', 'used_quota' => 510],
                    ['id' => '31aa55cb-4f04-41f8-8e30-5f0c99005591', 'name' => 'Cliente de la otra base', 'used_quota' => 80],
                ],
            ]);
        $client->expects($this->once())
            ->method('getAgents')
            ->with($clientId, 'production-key')
            ->willReturn(['ok' => true, 'status' => 200, 'data' => []]);

        $controller = new FincaraizController(
            $client,
            $this->createMock(FincaraizPropertyMapper::class)
        );
        $data = $controller->clientInfo($request)->getData(true)['Datos'];

        $this->assertTrue($data['ok']);
        $this->assertCount(1, $data['clients']['data']);
        $this->assertSame($clientId, $data['clients']['data'][0]['id']);
        $this->assertSame('Cliente de esta base', $data['clients']['data'][0]['name']);
    }

    public function test_client_info_reports_a_mismatched_client_and_does_not_request_agents(): void
    {
        $configuredClientId = '2cc4b423-621c-47d5-a798-041ef44bb9ec';
        PortalCredential::create([
            'user_id' => 15,
            'integration_id' => 1,
            'account_key' => 'user:15',
            'access_token' => 'production-key',
            'data' => ['client_id' => $configuredClientId],
        ]);
        $user = new User;
        $user->id = 15;
        $user->exists = true;
        $request = Request::create('/api/portals/fincaraiz/client', 'GET');
        $request->setUserResolver(fn () => $user);

        $client = $this->createMock(FincaraizClient::class);
        $client->expects($this->once())
            ->method('getClientInfo')
            ->willReturn([
                'ok' => true,
                'status' => 200,
                'data' => [[
                    'id' => '31aa55cb-4f04-41f8-8e30-5f0c99005591',
                    'name' => 'Cliente de la otra base',
                ]],
            ]);
        $client->expects($this->never())->method('getAgents');

        $controller = new FincaraizController(
            $client,
            $this->createMock(FincaraizPropertyMapper::class)
        );
        $data = $controller->clientInfo($request)->getData(true)['Datos'];

        $this->assertFalse($data['ok']);
        $this->assertSame(404, $data['clients']['status']);
        $this->assertStringContainsString('no pertenece', $data['clients']['data']['detail']);
    }
}
