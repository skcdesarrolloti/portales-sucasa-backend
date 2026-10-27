<?php

namespace App\Services\Portals;

use App\Models\PortalCredential;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Promise\Utils;
use Illuminate\Support\Facades\Log;

class CiencuadrasClient
{
    public function __construct(protected Client $http) {}

    public function login(?array $credentials = null): array
    {
        $credentials ??= [
            'username' => config('portals.ciencuadras.username'),
            'password' => config('portals.ciencuadras.password'),
        ];

        try {
            $response = $this->http->post(config('portals.ciencuadras.api_url').'/login', [
                'json' => $credentials,
                'headers' => ['Accept' => 'application/json'],
                'timeout' => 30,
            ]);

            return ['ok' => $response->getStatusCode() >= 200 && $response->getStatusCode() < 300, 'status' => $response->getStatusCode(), 'data' => json_decode((string) $response->getBody(), true) ?? []];
        } catch (RequestException $e) {
            return ['ok' => false, 'data' => $this->errorData($e)];
        } catch (GuzzleException $e) {
            return ['ok' => false, 'data' => ['error' => $e->getMessage()]];
        }
    }

    public function insertProperty(array $payload, PortalCredential $cred): array
    {
        return $this->request('POST', '/api/insert', $this->propertyBatch($payload, rejectLegacyActive: true), $cred);
    }

    public function consultProperty(string $propertyCode, PortalCredential $cred): array
    {
        return $this->request('POST', '/api/consult-property', ['propertyCode' => $propertyCode], $cred);
    }

    public function consultProperties(array $propertyCodes, PortalCredential $cred): array
    {
        $promises = [];

        foreach (array_values(array_unique($propertyCodes)) as $propertyCode) {
            $promises[$propertyCode] = $this->http->requestAsync(
                'POST',
                config('portals.ciencuadras.api_url').'/api/consult-property',
                [
                    'json' => ['propertyCode' => $propertyCode],
                    'headers' => [
                        'Authorization' => 'Bearer '.$cred->access_token,
                        'Accept' => 'application/json',
                        'Content-Type' => 'application/json',
                    ],
                    'timeout' => 45,
                ]
            );
        }

        return collect(Utils::settle($promises)->wait())
            ->map(function (array $settled) {
                if ($settled['state'] === 'fulfilled') {
                    return [
                        'ok' => $settled['value']->getStatusCode() >= 200 && $settled['value']->getStatusCode() < 300,
                        'data' => json_decode((string) $settled['value']->getBody(), true),
                    ];
                }

                $reason = $settled['reason'];

                return [
                    'ok' => false,
                    'data' => $reason instanceof RequestException
                        ? $this->errorData($reason)
                        : ['error' => $reason->getMessage()],
                ];
            })
            ->all();
    }

    public function consultStatus(array $payload, PortalCredential $cred): array
    {
        return $this->request('POST', '/api/consult-status', $payload, $cred);
    }

    public function consultAllProperties(PortalCredential $cred): array
    {
        return $this->request('POST', '/api/consult-all-properties', [], $cred);
    }

    public function updateProperty(array $payload, PortalCredential $cred): array
    {
        return $this->request('POST', '/api/update', $this->propertyBatch($payload, rejectLegacyActive: false), $cred);
    }

    public function extractToken(array $data): ?string
    {
        return $data['token']
            ?? $data['access_token']
            ?? $data['data']['token']
            ?? $data['Datos']['token']
            ?? null;
    }

    public function extractIdRequest(array $data): ?string
    {
        foreach ($data as $key => $value) {
            if (strtolower((string) $key) === 'idrequest' && is_scalar($value)) {
                return (string) $value;
            }
            if (is_array($value) && $found = $this->extractIdRequest($value)) {
                return $found;
            }
        }

        return null;
    }

    protected function propertyBatch(array $payload, bool $rejectLegacyActive = true): array
    {
        $batch = array_is_list($payload) ? $payload : [$payload];

        foreach ($batch as $property) {
            $code = trim((string) ($property['propertyCode'] ?? ''));
            $status = strtoupper(trim((string) ($property['status'] ?? 'A')));

            if ($rejectLegacyActive && $status === 'A' && preg_match('/(?:^|-)P+\d+$/i', $code)) {
                throw new \InvalidArgumentException(
                    "Se bloqueó el envío del código legado {$code}. Las publicaciones activas deben usar el código limpio."
                );
            }
        }

        return $batch;
    }

    protected function request(string $method, string $path, array $body, PortalCredential $cred): array
    {
        try {
            $response = $this->http->request($method, config('portals.ciencuadras.api_url').$path, [
                'json' => $body,
                'headers' => [
                    'Authorization' => 'Bearer '.$cred->access_token,
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                ],
                'timeout' => 45,
            ]);

            return ['ok' => $response->getStatusCode() >= 200 && $response->getStatusCode() < 300, 'status' => $response->getStatusCode(), 'data' => json_decode((string) $response->getBody(), true) ?? []];
        } catch (RequestException $e) {
            Log::warning('CC request failed', ['path' => $path, 'err' => $e->getMessage()]);

            return ['ok' => false, 'data' => $this->errorData($e)];
        } catch (GuzzleException $e) {
            Log::warning('CC request failed', ['path' => $path, 'err' => $e->getMessage()]);

            return ['ok' => false, 'data' => ['error' => $e->getMessage()]];
        }
    }

    protected function errorData(RequestException $e): array
    {
        $body = $e->getResponse() ? (string) $e->getResponse()->getBody() : null;
        $json = $body ? json_decode($body, true) : null;

        return [
            'error' => $e->getMessage(),
            'status' => $e->getResponse()?->getStatusCode(),
            'body' => $json ?? $body,
        ];
    }
}
