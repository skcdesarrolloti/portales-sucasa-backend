<?php

namespace Tests\Unit;

use App\Services\Portals\ProppitClient;
use App\Services\Portals\ProppitPropertyMapper;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Tests\TestCase;

class ProppitClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('portals.proppit.api_url', 'https://real-time.proppit.test/api/v2');
        config()->set('portals.proppit.country', 'CO');
    }

    public function test_reference_id_preserves_the_original_property_code_without_prefixes(): void
    {
        $mapper = new ProppitPropertyMapper;

        $this->assertSame('53824', $mapper->referenceId(' 53824 '));
        $this->assertSame('SC-53824', $mapper->referenceId('SC-53824'));
    }

    public function test_payload_uses_global_contact_approximate_location_and_proppit_feature_flags(): void
    {
        config()->set('portals.proppit.publisher_external_id', 'sucasa');
        config()->set('portals.proppit.default_contact_name', 'Contacto Global');
        config()->set('portals.proppit.default_contact_email', 'global@example.test');
        config()->set('portals.proppit.default_contact_phone', '+573001112233');
        config()->set('portals.proppit.location_visibility', 'approximate');
        config()->set('portals.proppit.boosted_weekly_limit', 0);

        $payload = (new TestableProppitPropertyMapper)->payloadForTest((object) [
            'codigo' => '53824',
            'descripcion' => 'Casa amplia con piscina, gimnasio, parqueadero y colegios cerca.',
            'datos_adicionales' => '',
            'punto_referencia' => '',
            'area_construida' => 120,
            'area_terreno' => 160,
            'foto_portada' => '',
            'galeria' => '',
            'video' => 'https://www.youtube.com/watch?v=abc123xyz',
            'tipo_negocio' => 'Venta',
            'precio_venta' => 450000000,
            'precio_arriendo' => '',
            'tipo_inmueble' => 'Casa',
            'estrato' => 4,
            'ciudad' => 'Cartagena',
            'barrio' => 'Manga',
            'latitud' => '10.4',
            'longitud' => '-75.5',
            'direccion_fisica' => 'Direccion exacta',
            'direccion' => '',
            'precio_admin' => '',
            'copropiedad' => '',
            'area_privada' => 100,
            'habitaciones' => 3,
            'banos' => 2,
            'parqueaderos' => 1,
            'amoblado' => '',
            'proppit_promocionado' => 'si',
            'promocion_premium' => '',
            'interiores' => 'Cocina integral, aire acondicionado',
            'exteriores' => 'Piscina, terraza, balcon',
            'alrededores' => 'Cerca de centros comerciales, colegios y parques',
            'zonas_sociales' => 'Gimnasio',
            'servicios_publicos' => 'Agua, gas natural',
            'luz' => 'si',
            'agua' => 'si',
            'gas' => 'si',
            'vigilancia' => 'si',
            'parqueadero' => 'si',
        ]);

        $this->assertSame([
            'name' => 'Contacto Global',
            'email' => 'global@example.test',
            'phone' => '+573001112233',
            'whatsapp' => '+573001112233',
        ], $payload['contact']);
        $this->assertSame('approximate', $payload['property']['location']['visibility']);
        $this->assertTrue($payload['isBoosted']);
        $this->assertSame([
            ['url' => 'https://www.youtube.com/watch?v=abc123xyz'],
        ], $payload['multimedia']['videos']);
        $this->assertContains('swimming pool', $payload['amenities']);
        $this->assertContains('gym', $payload['amenities']);
        $this->assertContains('shopping mall', $payload['property']['location']['nearbyLocations']);
        $this->assertContains('school', $payload['property']['location']['nearbyLocations']);
    }

    public function test_publish_payload_is_boosted_immediately_when_promo_slots_are_enabled(): void
    {
        config()->set('portals.proppit.boost_new_listings', true);
        config()->set('portals.proppit.boosted_weekly_limit', 1);

        $payload = (new ProppitPropertyMapper)->boostOnPublish([
            'referenceId' => '103025',
            'isBoosted' => false,
        ]);

        $this->assertTrue($payload['isBoosted']);
    }

    public function test_sale_and_rent_properties_are_sent_to_proppit_as_rent_only(): void
    {
        config()->set('portals.proppit.publisher_external_id', 'sucasa');
        config()->set('portals.proppit.default_contact_name', 'Contacto Global');
        config()->set('portals.proppit.default_contact_email', 'global@example.test');
        config()->set('portals.proppit.default_contact_phone', '+573001112233');
        config()->set('portals.proppit.location_visibility', 'approximate');
        config()->set('portals.proppit.boosted_weekly_limit', 0);

        $payload = (new TestableProppitPropertyMapper)->payloadForTest((object) [
            'codigo' => '53825',
            'descripcion' => 'Apartamento amplio publicado como arriendo venta para Proppit.',
            'datos_adicionales' => '',
            'punto_referencia' => '',
            'area_construida' => 90,
            'area_terreno' => '',
            'foto_portada' => '',
            'galeria' => '',
            'video' => '',
            'tipo_negocio' => 'Arriendo/Venta',
            'precio_venta' => 320000000,
            'precio_arriendo' => 2500000,
            'tipo_inmueble' => 'Apartamento',
            'estrato' => 4,
            'ciudad' => 'Cartagena',
            'barrio' => 'Manga',
            'latitud' => '10.4',
            'longitud' => '-75.5',
            'direccion_fisica' => 'Direccion exacta',
            'direccion' => '',
            'precio_admin' => '',
            'copropiedad' => '',
            'area_privada' => 80,
            'habitaciones' => 2,
            'banos' => 2,
            'parqueaderos' => 1,
            'amoblado' => '',
            'proppit_promocionado' => '',
            'promocion_premium' => '',
            'interiores' => '',
            'exteriores' => '',
            'alrededores' => '',
            'zonas_sociales' => '',
            'servicios_publicos' => '',
            'luz' => 'si',
            'agua' => 'si',
            'gas' => 'si',
            'vigilancia' => '',
            'parqueadero' => 'si',
        ]);

        $this->assertSame([
            ['type' => 'rent', 'price' => ['value' => 2500000.0, 'currency' => 'COP']],
        ], $payload['operations']);
    }

    public function test_get_publisher_returns_its_activation_state(): void
    {
        $client = $this->clientWith([
            new Response(200, [], json_encode([
                'id' => 'sucasa',
                'publishingEnabled' => true,
            ])),
        ]);

        $result = $client->getPublisher('sucasa', 'token');

        $this->assertTrue($result['ok']);
        $this->assertSame(200, $result['status']);
        $this->assertTrue($result['data']['publishingEnabled']);
    }

    public function test_get_publisher_preserves_proppit_request_id_on_404(): void
    {
        $client = $this->clientWith([
            new Response(404, [], json_encode([
                'status' => 404,
                'requestId' => 'request-123',
                'error' => 'Publisher not found',
            ])),
        ]);

        $result = $client->getPublisher('missing', 'token');

        $this->assertFalse($result['ok']);
        $this->assertSame(404, $result['data']['status']);
        $this->assertSame('Publisher not found', $result['data']['body']['error']);
        $this->assertSame('request-123', $result['data']['body']['requestId']);
    }

    public function test_create_publisher_posts_the_expected_payload(): void
    {
        $client = $this->clientWith([
            new Response(201, [], json_encode([
                'id' => 'sucasa',
                'name' => 'Su Casa Inmobiliaria',
                'publishingEnabled' => false,
            ])),
        ]);

        $result = $client->createPublisher([
            'id' => 'sucasa',
            'name' => 'Su Casa Inmobiliaria',
        ], 'token');

        $this->assertTrue($result['ok']);
        $this->assertSame(201, $result['status']);
        $this->assertSame('sucasa', $result['data']['id']);
        $this->assertFalse($result['data']['publishingEnabled']);
    }

    public function test_single_ad_lookup_preserves_http_status_for_recovery_decisions(): void
    {
        $client = $this->clientWith([
            new Response(404, [], '{"error":"Ad not found"}'),
            new Response(401, [], '{"error":"Unauthorized"}'),
            new Response(500, [], '{"error":"Server error"}'),
        ]);

        foreach ([404, 401, 500] as $status) {
            $result = $client->getAd('missing', 'token');
            $this->assertFalse($result['ok']);
            $this->assertSame($status, $result['status']);
            $this->assertSame($status, $result['data']['status']);
        }
    }

    public function test_withdrawal_accepts_only_an_explicit_missing_ad_as_already_absent(): void
    {
        $client = $this->clientWith([
            new Response(404, [], '{"error":"Ad not found","requestId":"absent-123"}'),
            new Response(404, [], '{"error":"Publisher not found"}'),
            new Response(404, [], '<html>Not Found</html>'),
            new Response(401, [], '{"error":"Unauthorized"}'),
        ]);

        $absent = $client->deleteAd('100', 'token');
        $this->assertTrue($absent['ok']);
        $this->assertTrue($absent['already_absent']);
        $this->assertSame(404, $absent['status']);
        $this->assertSame('absent-123', $absent['data']['body']['requestId']);
        foreach (['200', '300', '400'] as $reference) {
            $this->assertFalse($client->deleteAd($reference, 'token')['ok']);
        }
    }

    public function test_get_ads_verifies_multiple_known_references(): void
    {
        config()->set('portals.proppit.publisher_external_id', 'sucasa');
        $client = $this->clientWith([
            new Response(200, [], '{"referenceId":"100","status":"active"}'),
            new Response(404, [], '{"error":"Ad not found"}'),
        ]);

        $results = $client->getAds(['100', '200'], 'token', 2);

        $this->assertTrue($results['100']['ok']);
        $this->assertFalse($results['200']['ok']);
        $this->assertSame(404, $results['200']['status']);
    }

    private function clientWith(array $responses): ProppitClient
    {
        $handler = new MockHandler($responses);
        $stack = HandlerStack::create($handler);

        return new ProppitClient(new Client([
            'handler' => $stack,
            'http_errors' => true,
        ]));
    }
}

class TestableProppitPropertyMapper extends ProppitPropertyMapper
{
    public function payloadForTest(object $row): array
    {
        return $this->payload($row);
    }

    protected function media(\stdClass $row): array
    {
        return ['https://example.test/photo.jpg'];
    }

    protected function postalCode(\stdClass $row): ?string
    {
        return '130001';
    }
}
