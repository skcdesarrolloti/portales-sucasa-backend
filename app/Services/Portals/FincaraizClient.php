<?php

namespace App\Services\Portals;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Pool;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Psr\Http\Message\ResponseInterface;
use Throwable;

class FincaraizClient
{
    public function __construct(protected Client $http) {}

    public function getClientInfo(string $apiKey): array
    {
        return $this->getClients($apiKey);
    }

    public function getClients(string $apiKey): array
    {
        return $this->request('GET', '/client', $apiKey);
    }

    public function getClient(string $clientId, string $apiKey): array
    {
        return $this->request('GET', '/client/'.rawurlencode($clientId), $apiKey);
    }

    public function getAgents(string $clientId, string $apiKey): array
    {
        return $this->request('GET', '/client/'.rawurlencode($clientId).'/agent', $apiKey);
    }

    public function findLocations(string $name, string $apiKey): array
    {
        return $this->request('GET', '/location/'.rawurlencode($name), $apiKey);
    }

    public function listListings(
        string $apiKey,
        string $clientId,
        int $page = 1,
        int $pageSize = 20,
        ?string $search = null,
        string $ordering = '-created'
    ): array {
        return $this->request('GET', '/listing', $apiKey, null, array_filter([
            'page' => max(1, $page),
            'page_size' => min(100, max(1, $pageSize)),
            'search' => $search,
            'ordering' => $ordering,
        ], fn ($value) => $value !== null && $value !== ''), [
            'Cookie' => $clientId,
        ]);
    }

    public function listListingsMany(
        string $apiKey,
        string $clientId,
        array $searches,
        int $pageSize = 10,
        string $ordering = '-created',
        int $concurrency = 4
    ): array {
        $searches = collect($searches)
            ->map(fn ($search) => trim((string) $search))
            ->filter()
            ->unique()
            ->values()
            ->all();

        $requests = function () use ($apiKey, $clientId, $searches, $pageSize, $ordering) {
            foreach ($searches as $search) {
                yield $search => fn () => $this->http->requestAsync(
                    'GET',
                    $this->url('/listing'),
                    $this->requestOptions($apiKey, null, [
                        'page' => 1,
                        'page_size' => min(100, max(1, $pageSize)),
                        'search' => $search,
                        'ordering' => $ordering,
                    ], ['Cookie' => $clientId], true)
                );
            }
        };

        $results = [];
        $pool = new Pool($this->http, $requests(), [
            'concurrency' => min(8, max(1, $concurrency)),
            'fulfilled' => function (ResponseInterface $response, string $search) use (&$results): void {
                $results[$search] = $this->responseResult($response);
            },
            'rejected' => function (Throwable $exception, string $search) use (&$results): void {
                $results[$search] = $this->exceptionResult($exception, '/listing');
            },
        ]);
        $pool->promise()->wait();

        return collect($searches)
            ->mapWithKeys(fn (string $search) => [$search => $results[$search] ?? [
                'ok' => false,
                'status' => 502,
                'data' => ['error' => 'La consulta no devolvió respuesta.'],
            ]])
            ->all();
    }

    public function getListing(string $apiKey, string $listingId, string $clientId): array
    {
        return $this->request('GET', '/listing/'.rawurlencode($listingId), $apiKey, null, [], [
            'Cookie' => $clientId,
        ]);
    }

    public function createListing(array $payload, string $apiKey): array
    {
        return $this->request('POST', '/listing', $apiKey, $this->batch($payload));
    }

    public function updateListing(string $listingId, array $payload, string $apiKey): array
    {
        $items = $this->batch($payload);
        $items = array_map(
            fn (array $item) => ['listing_id' => $listingId] + $item,
            $items
        );

        return $this->request('PATCH', '/listing', $apiKey, $items);
    }

    public function changeStatus(string $listingId, string $status, string $clientId, string $apiKey): array
    {
        return $this->request('PATCH', '/listing/status', $apiKey, [[
            'listing_id' => $listingId,
            'client_id' => $clientId,
            'status' => strtoupper($status),
        ]]);
    }

    public function changeStatusesMany(
        array $listingIds,
        string $status,
        string $clientId,
        string $apiKey,
        int $concurrency = 4
    ): array {
        $listingIds = collect($listingIds)
            ->map(fn ($listingId) => trim((string) $listingId))
            ->filter()
            ->unique()
            ->values()
            ->all();

        $requests = function () use ($listingIds, $status, $clientId, $apiKey) {
            foreach ($listingIds as $listingId) {
                yield $listingId => fn () => $this->http->requestAsync(
                    'PATCH',
                    $this->url('/listing/status'),
                    $this->requestOptions($apiKey, [[
                        'listing_id' => $listingId,
                        'client_id' => $clientId,
                        'status' => strtoupper($status),
                    ]])
                );
            }
        };

        $results = [];
        $pool = new Pool($this->http, $requests(), [
            'concurrency' => min(8, max(1, $concurrency)),
            'fulfilled' => function (ResponseInterface $response, string $listingId) use (&$results): void {
                $results[$listingId] = $this->responseResult($response);
            },
            'rejected' => function (Throwable $exception, string $listingId) use (&$results): void {
                $results[$listingId] = $this->exceptionResult($exception, '/listing/status');
            },
        ]);
        $pool->promise()->wait();

        return collect($listingIds)
            ->mapWithKeys(fn (string $listingId) => [$listingId => $results[$listingId] ?? [
                'ok' => false,
                'status' => 502,
                'data' => ['error' => 'La actualización no devolvió respuesta.'],
            ]])
            ->all();
    }

    public function validateListings(string $clientId, array $identifiers, string $apiKey): array
    {
        return $this->request('POST', '/validate-listing', $apiKey, ['client_id' => $clientId] + $identifiers);
    }

    public function getTask(string $taskId, string $apiKey): array
    {
        return $this->request('GET', '/task/'.rawurlencode($taskId), $apiKey);
    }

    public function subscribeWebhook(string $webhookId, string $target, string $apiKey, ?string $clientId = null): array
    {
        return $this->request(
            'POST',
            '/webhook/'.rawurlencode($webhookId).'/subscribe',
            $apiKey,
            array_filter(['target' => $target, 'client_id' => $clientId])
        );
    }

    public function unsubscribeWebhook(string $webhookId, string $apiKey): array
    {
        return $this->request('POST', '/webhook/'.rawurlencode($webhookId).'/unsubscribe', $apiKey);
    }

    protected function request(
        string $method,
        string $path,
        string $apiKey,
        ?array $body = null,
        array $query = [],
        array $headers = []
    ): array {
        try {
            $response = $this->http->request(
                $method,
                $this->url($path),
                $this->requestOptions($apiKey, $body, $query, $headers, strtoupper($method) === 'GET')
            );

            return $this->responseResult($response);
        } catch (GuzzleException $e) {
            return $this->exceptionResult($e, $path);
        }
    }

    protected function requestOptions(
        string $apiKey,
        ?array $body = null,
        array $query = [],
        array $headers = [],
        bool $cacheBust = false
    ): array {
        if ($cacheBust) {
            $query[$this->cacheBusterName()] = (string) Str::uuid();
        }

        $options = [
            'query' => $query,
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'apikey' => $apiKey,
                ...$headers,
            ],
            'timeout' => (float) config('portals.fincaraiz.timeout', 30),
        ];
        if ($body !== null) {
            $options['json'] = $body;
        }

        return $options;
    }

    protected function responseResult(ResponseInterface $response): array
    {
        $status = $response->getStatusCode();
        $raw = (string) $response->getBody();
        $decoded = $raw === '' ? [] : json_decode($raw, true);

        return [
            'ok' => $status >= 200 && $status < 300,
            'status' => $status,
            'data' => json_last_error() === JSON_ERROR_NONE ? $decoded : ['raw' => $raw],
        ];
    }

    protected function exceptionResult(Throwable $exception, string $path): array
    {
        Log::warning('Fincaraiz request failed', [
            'environment' => config('portals.fincaraiz.environment'),
            'path' => $path,
            'error' => $exception->getMessage(),
        ]);

        return [
            'ok' => false,
            'status' => method_exists($exception, 'getResponse') && $exception->getResponse()
                ? $exception->getResponse()->getStatusCode()
                : 502,
            'data' => ['error' => $exception->getMessage()],
        ];
    }

    protected function url(string $path): string
    {
        return rtrim((string) config('portals.fincaraiz.api_url'), '/').$path;
    }

    protected function batch(array $payload): array
    {
        return array_is_list($payload) ? $payload : [$payload];
    }

    protected function cacheBusterName(): string
    {
        $name = trim((string) config('portals.fincaraiz.cache_buster_name', 'sucasa-cache'));

        return $name !== '' ? $name : 'sucasa-cache';
    }
}
