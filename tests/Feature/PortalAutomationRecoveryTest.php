<?php

namespace Tests\Feature;

use App\Http\Controllers\Portal\FincaraizController;
use App\Models\Integration;
use App\Models\Property;
use App\Models\PropertySyncStatus;
use App\Services\Portals\ProppitClient;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PortalAutomationRecoveryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.connections.wordpress' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        DB::purge('wordpress');
        Schema::connection('wordpress')->create('wp_jet_cct_inmuebles', function (Blueprint $table) {
            $table->string('codigo'); $table->string('estado'); $table->string('cct_status');
            $table->string('fecha_actualizacion')->nullable(); $table->string('cct_modified')->nullable();
        });
        Schema::create('integrations', function (Blueprint $table) {
            $table->id(); $table->string('name'); $table->string('slug'); $table->timestamps();
        });
        Schema::create('properties', function (Blueprint $table) {
            $table->id(); $table->string('code'); $table->string('title'); $table->timestamps(); $table->softDeletes();
        });
        Schema::create('property_sync_statuses', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('property_id'); $table->unsignedBigInteger('integration_id');
            $table->string('environment')->default('production'); $table->string('portal_variant')->default('default');
            $table->string('sync_status'); $table->string('external_id')->nullable(); $table->string('external_url')->nullable();
            $table->json('last_response')->nullable(); $table->text('last_error')->nullable();
            $table->timestamp('last_synced_at')->nullable(); $table->timestamp('last_attempt_at')->nullable();
            $table->integer('attempts')->default(0); $table->timestamps();
        });
        Schema::create('portal_credentials', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('integration_id'); $table->timestamps();
        });
    }

    public function test_proppit_rotates_until_older_properties_are_retired_and_dry_run_does_not_move_cursor(): void
    {
        config(['portals.proppit.auto_sync' => true]);
        $integration = Integration::create(['name' => 'Proppit', 'slug' => 'proppit']);
        for ($i = 1; $i <= 5; $i++) {
            $property = Property::create(['code' => (string) $i, 'title' => 'Casa']);
            DB::connection('wordpress')->table('wp_jet_cct_inmuebles')->insert([
                'codigo' => (string) $i, 'estado' => 'Arrendado', 'cct_status' => 'publish',
                'cct_modified' => '2026-01-0'.$i,
            ]);
            PropertySyncStatus::create(['property_id' => $property->id, 'integration_id' => $integration->id,
                'sync_status' => 'synced', 'external_id' => (string) $i]);
        }
        $client = $this->createMock(ProppitClient::class);
        $client->expects($this->exactly(3))->method('token')->willReturn(['ok' => true, 'data' => ['token' => 'test']]);
        $client->expects($this->exactly(5))->method('deleteAd')->willReturn(['ok' => true]);
        $this->app->instance(ProppitClient::class, $client);
        Cache::put('proppit.auto-sync-offset.production', 0);
        $this->artisan('proppit:auto-sync', ['--limit' => 2, '--scan' => 2, '--dry-run' => true])->assertSuccessful();
        $this->assertSame(0, Cache::get('proppit.auto-sync-offset.production'));
        for ($i = 0; $i < 3; $i++) {
            $this->artisan('proppit:auto-sync', ['--limit' => 2, '--scan' => 2])->assertSuccessful();
        }
        $this->assertSame(5, PropertySyncStatus::where('sync_status', 'paused')->count());
    }

    public function test_fincaraiz_pending_queue_cannot_consume_the_whole_run(): void
    {
        config(['portals.fincaraiz.auto_sync' => true, 'portals.fincaraiz.environment' => 'production']);
        $integration = Integration::create(['name' => 'Fincaraíz', 'slug' => 'fincaraiz']);
        for ($i = 1; $i <= 24; $i++) {
            $property = Property::create(['code' => 'pending-'.$i, 'title' => 'Casa']);
            PropertySyncStatus::create(['property_id' => $property->id, 'integration_id' => $integration->id,
                'sync_status' => 'pending', 'last_response' => ['task_id' => 'old-'.$i]]);
        }
        for ($i = 1; $i <= 20; $i++) {
            DB::connection('wordpress')->table('wp_jet_cct_inmuebles')->insert([
                'codigo' => (string) $i, 'estado' => 'Publico', 'cct_status' => 'publish',
            ]);
        }
        $controller = $this->createMock(FincaraizController::class);
        $controller->expects($this->exactly(10))->method('verify')->willReturn(response()->json(['Datos' => ['ok' => true]]));
        $controller->expects($this->exactly(10))->method('publish')->willReturn(response()->json(['Datos' => ['ok' => true]]));
        $this->app->instance(FincaraizController::class, $controller);
        $this->artisan('fincaraiz:auto-sync', ['--limit' => 20, '--scan' => 100])->assertSuccessful();
    }
}
