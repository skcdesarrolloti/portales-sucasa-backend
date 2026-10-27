<?php

namespace App\Services\Portals;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Pool;
use Illuminate\Support\Facades\Log;
use Psr\Http\Message\ResponseInterface;
use Throwable;

class ProppitClient
{
    public function __construct(protected Client $http) {}

    public function token(?array $credentials = null): array
    {
        $credentials ??= [
            'user' => config('portals.proppit.user'),
            'password' => config('portals.proppit.password'),
        ];

        return $this->request('POST', '/token', [
            'json' => $credentials,
            'auth' => false,
        ]);
    }

    public function createAd(array $payload, string $token): array
    {
        return $this->request('POST', $this->countryPath('/ads'), [
            'json' => $payload,
            'token' => $token,
        ]);
    }

    public function updateAd(string $referenceId, array $payload, string $token): array
    {
        return $this->request('PUT', $this->countryPath('/ads/' . rawurlencode($referenceId)), [
            'json' => $payload,
            'token' => $token,
        ]);
    }

    public function getAd(string $referenceId, string $token): array
    {
        return $this->request('GET', $this->countryPath('/ads/' . rawurlencode($referenceId)), [
            'query' => ['externalId' => config('portals.proppit.publisher_external_id')],
            'token' => $token,
        ]);
    }

    public function getAds(array $referenceIds, string $token, int $concurrency = 8): array
    {
        $referenceIds = collect($referenceIds)
            ->map(fn ($referenceId) => trim((string) $referenceId))
            ->filter()
            ->unique()
            ->values()
            ->all();
        $publisherId = config('portals.proppit.publisher_external_id');
        $requests = function () use ($referenceIds, $token, $publisherId) {
            foreach ($referenceIds as $referenceId) {
                yield $referenceId => fn () => $this->http->requestAsync(
                    'GET',
                    rtrim(config('portals.proppit.api_url'), '/')
                        .$this->countryPath('/ads/'.rawurlencode($referenceId)),
                    [
                        'headers' => [
                            'Accept' => 'application/json',
                            'Content-Type' => 'application/json',
                            'Authorization' => 'Bearer '.$token,
                        ],
                        'query' => ['externalId' => $publisherId],
                        'timeout' => 45,
                    ]
                );
            }
        };
        $results = [];
        $pool = new Pool($this->http, $requests(), [
            'concurrency' => min(12, max(1, $concurrency)),
            'fulfilled' => function (ResponseInterface $response, string $referenceId) use (&$results): void {
                $body = (string) $response->getBody();
                $results[$referenceId] = [
                    'ok' => true,
                    'status' => $response->getStatusCode(),
                    'data' => $body !== '' ? json_decode($body, true) : ['ok' => true],
                ];
            },
            'rejected' => function (Throwable $exception, string $referenceId) use (&$results): void {
                $response = method_exists($exception, 'getResponse') ? $exception->getResponse() : null;
                $body = $response ? (string) $response->getBody() : null;
                $results[$referenceId] = [
                    'ok' => false,
                    'status' => $response?->getStatusCode(),
                    'data' => [
                        'error' => $exception->getMessage(),
                        'body' => $body ? (json_decode($body, true) ?? $body) : null,
                    ],
                ];
            },
        ]);
        $pool->promise()->wait();

        return collect($referenceIds)
            ->mapWithKeys(fn (string $referenceId) => [$referenceId => $results[$referenceId] ?? [
                'ok' => false,
                'status' => 502,
                'data' => ['error' => 'La consulta no devolvió respuesta.'],
            ]])
            ->all();
    }

    public function deleteAd(string $referenceId, string $token): array
    {
        return $this->request('DELETE', $this->countryPath('/ads/' . rawurlencode($referenceId)), [
            'query' => ['externalId' => config('portals.proppit.publisher_external_id')],
            'token' => $token,
        ]);
    }

    public function propertyTypes(string $token): array
    {
        return $this->request('GET', $this->countryPath('/property-types'), [
            'token' => $token,
        ]);
    }

    public function getPublisher(string $externalId, string $token): array
    {
        return $this->request('GET', $this->countryPath('/publishers/' . rawurlencode($externalId)), [
            'token' => $token,
        ]);
    }

    public function createPublisher(array $payload, string $token): array
    {
        return $this->request('POST', $this->countryPath('/publishers'), [
            'json' => $payload,
            'token' => $token,
        ]);
    }

    protected function countryPath(string $suffix): string
    {
        return '/proppit/' . rawurlencode(strtoupper((string) config('portals.proppit.country', 'CO'))) . $suffix;
    }

    protected function request(string $method, string $path, array $options = []): array
    {
        $headers = [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ];

        if (($options['auth'] ?? true) !== false) {
            $headers['Authorization'] = 'Bearer ' . ($options['token'] ?? '');
        }

        try {
            $requestOptions = [
                'headers' => $headers,
                'query' => $options['query'] ?? [],
                'timeout' => 45,
            ];
            if (array_key_exists('json', $options)) {
                $requestOptions['json'] = $options['json'];
            }

            $response = $this->http->request($method, rtrim(config('portals.proppit.api_url'), '/') . $path, $requestOptions);

            $body = (string) $response->getBody();

            return [
                'ok' => true,
                'status' => $response->getStatusCode(),
                'data' => $body !== '' ? json_decode($body, true) : ['ok' => true],
            ];
        } catch (RequestException $e) {
            Log::warning('Proppit request failed', ['path' => $path, 'err' => $e->getMessage()]);

            return ['ok' => false, 'status' => $e->getResponse()?->getStatusCode(), 'data' => $this->errorData($e)];
        } catch (GuzzleException $e) {
            Log::warning('Proppit request failed', ['path' => $path, 'err' => $e->getMessage()]);

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
