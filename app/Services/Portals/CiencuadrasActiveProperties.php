<?php

namespace App\Services\Portals;

use App\Models\PortalCredential;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class CiencuadrasActiveProperties
{
    public function __construct(protected CiencuadrasClient $client) {}

    public function codes(bool $fresh = false): ?Collection
    {
        return $this->inventory($fresh)
            ?->pluck('propertyCode')
            ->map(fn ($code) => trim((string) $code))
            ->filter()
            ->unique()
            ->values();
    }

    public function activeCodes(bool $fresh = false): ?Collection
    {
        return $this->inventory($fresh)
            ?->filter(fn (array $property) => $this->isActiveInventoryProperty($property))
            ->pluck('propertyCode')
            ->map(fn ($code) => trim((string) $code))
            ->filter()
            ->unique()
            ->values();
    }

    public function activeSourceCodes(bool $fresh = false): ?Collection
    {
        return $this->activeCodes($fresh)
            ?->reject(fn (string $code) => $this->isLegacyCode($code))
            ->map(fn (string $code) => $this->sourceCode($code))
            ->filter()
            ->unique()
            ->values();
    }

    public function inspectSourceCodes(array|Collection $sourceCodes, PortalCredential $credential): Collection
    {
        $prefix = (string) config('portals.ciencuadras.property_code_prefix', '22130-');
        $sourceCodes = collect($sourceCodes)
            ->map(fn ($code) => $this->sourceCode((string) $code))
            ->filter()
            ->unique();
        $reportedActiveCodes = $this->reportedActiveCodes($credential);
        $portalCodes = $sourceCodes
            ->flatMap(fn (string $code) => [$prefix.$code, $prefix.'P'.$code, $prefix.'PP'.$code])
            ->unique()
            ->values();
        $results = $this->client->consultProperties($portalCodes->all(), $credential);

        return $sourceCodes->mapWithKeys(function (string $sourceCode) use ($prefix, $results, $reportedActiveCodes) {
            $candidates = [
                $prefix.$sourceCode,
                $prefix.'P'.$sourceCode,
                $prefix.'PP'.$sourceCode,
            ];
            $candidates = array_unique(array_merge($candidates, ($reportedActiveCodes ?? collect())
                ->filter(fn ($code) => $this->sourceCode($code) === $sourceCode)->all()));
            $reportedCode = $this->firstReportedCode($candidates, $reportedActiveCodes);

            if ($reportedCode) {
                return [$sourceCode => [
                    'state' => 'active',
                    'portal_code' => $reportedCode,
                    'property' => ['propertyCode' => $reportedCode],
                    'response' => ['source' => 'consult-all-properties'],
                ]];
            }

            $state = $this->bestStateFromResults($candidates, $results);
            if ($reportedActiveCodes === null && $state['state'] !== 'active') {
                $state['state'] = 'unavailable';
            }

            return [$sourceCode => $state];
        });
    }

    public function reportedActiveCodeForSource(
        string $sourceCode,
        PortalCredential $credential
    ): ?string {
        $prefix = (string) config('portals.ciencuadras.property_code_prefix', '22130-');
        $sourceCode = $this->sourceCode($sourceCode);

        return $this->firstReportedCode([
            $prefix.$sourceCode,
            $prefix.'P'.$sourceCode,
            $prefix.'PP'.$sourceCode,
        ], $this->reportedActiveCodes($credential));
    }

    public function inventory(bool $fresh = false): ?Collection
    {
        $environment = config('portals.ciencuadras.environment');
        $oldCacheKey = 'ciencuadras.properties-inventory.'.$environment;
        $cacheKey = 'ciencuadras.clean-properties-inventory.'.$environment;

        Cache::forget($oldCacheKey);

        if ($fresh) {
            Cache::forget($cacheKey);
        }

        return Cache::remember($cacheKey, now()->addMinute(), function () {
            $login = $this->client->login();
            $token = ($login['ok'] ?? false)
                ? $this->client->extractToken($login['data'] ?? [])
                : null;

            if (! $token) {
                return null;
            }

            $result = $this->client->consultAllProperties(
                new PortalCredential(['access_token' => $token])
            );

            if (! ($result['ok'] ?? false)) {
                return null;
            }

            return $this->inventoryRows($result);
        });
    }

    protected function isActiveInventoryProperty(array $property): bool
    {
        $active = strtolower(trim((string) ($property['active'] ?? '')));
        $status = trim((string) ($property['status'] ?? ''));

        if (in_array($status, ['5', '8'], true)
            || str_contains($active, 'inactivo')
            || str_contains($active, 'eliminado')) {
            return false;
        }

        return $active === 'activo' || in_array($status, ['0', '4'], true);
    }

    protected function stateFromResponse(array $response): array
    {
        $message = $response['message'] ?? null;
        $property = is_array($message)
            ? (array_is_list($message) ? ($message[0] ?? null) : $message)
            : null;

        if (! $property) {
            return [
                'state' => is_string($message) && (str_contains(strtolower($message), 'no existe')
                    || str_contains(strtolower($message), 'not found')
                    || str_contains(strtolower($message), 'no tiene inmuebles')) ? 'missing' : 'unknown',
                'property' => null,
                'response' => $response,
            ];
        }

        $label = strtolower((string) ($property['active'] ?? ''));
        $inactive = in_array((string) ($property['status'] ?? ''), ['5', '8'], true)
            || str_contains($label, 'inactivo') || str_contains($label, 'eliminado');
        return [
            'state' => $this->isActiveInventoryProperty($property) ? 'active' : ($inactive ? 'inactive' : 'unknown'),
            'property' => $property,
            'response' => $response,
        ];
    }

    protected function bestStateFromResults(array $portalCodes, array $results): array
    {
        $fallback = null;

        foreach ($portalCodes as $portalCode) {
            $result = $results[$portalCode] ?? null;
            if (! ($result['ok'] ?? false)) {
                $fallback = [
                    'state' => 'unavailable',
                    'portal_code' => $portalCode,
                    'property' => null,
                    'response' => $result['data'] ?? null,
                ];

                continue;
            }

            $state = $this->stateFromResponse($result['data'] ?? []);
            $state['portal_code'] = $portalCode;

            if ($state['state'] === 'active') {
                return $state;
            }

            if ($fallback === null || in_array($state['state'], ['unknown', 'unavailable'], true)
                || ($state['state'] === 'inactive' && $fallback['state'] === 'missing')) {
                $fallback = $state;
            }
        }

        return $fallback ?? [
            'state' => 'missing',
            'portal_code' => null,
            'property' => null,
            'response' => null,
        ];
    }

    protected function reportedActiveCodes(PortalCredential $credential): ?Collection
    {
        $result = $this->client->consultAllProperties($credential);
        if (! ($result['ok'] ?? false)) {
            return null;
        }

        return $this->inventoryRows($result)
            ?->values()
            ->pluck('propertyCode')
            ->map(fn ($code) => trim((string) $code))
            ->filter()
            ->unique()
            ->values();
    }

    protected function firstReportedCode(array $candidates, ?Collection $reportedCodes): ?string
    {
        if ($reportedCodes === null) {
            return null;
        }

        $byLowercase = $reportedCodes->mapWithKeys(
            fn (string $code) => [strtolower($code) => $code]
        );

        foreach ($candidates as $candidate) {
            if ($reported = $byLowercase->get(strtolower($candidate))) {
                return $reported;
            }
        }

        return null;
    }

    protected function inventoryRows(array $result): ?Collection
    {
        $rows = data_get($result, 'data.message');
        if (! ($result['ok'] ?? false) || ! is_array($rows) || ! array_is_list($rows)) {
            return null;
        }
        foreach ($rows as $row) {
            if (! is_array($row) || empty($row['propertyCode'])) {
                return null;
            }
        }

        return collect($rows);
    }

    public function sourceCode(string $portalCode): string
    {
        $prefix = preg_quote((string) config('portals.ciencuadras.property_code_prefix', '22130-'), '/');

        return preg_replace('/^'.$prefix.'P*/i', '', trim($portalCode)) ?? trim($portalCode);
    }

    public function consultForWithdrawal(string $code, PortalCredential $credential): array
    {
        $source = $this->sourceCode($code);
        $prefix = (string) config('portals.ciencuadras.property_code_prefix', '22130-');
        $inventory = $this->client->consultAllProperties($credential);
        $rows = $this->inventoryRows($inventory);
        $inventoryKnown = $rows !== null;
        $references = ($rows ?? collect())
            ->filter(fn ($row) => is_array($row) && $this->sourceCode((string) ($row['propertyCode'] ?? '')) === $source)
            ->pluck('propertyCode')->values();
        $candidates = collect([$code, $prefix.$source, $prefix.'P'.$source, $prefix.'PP'.$source])
            ->filter(fn ($candidate) => str_starts_with($candidate, $prefix))
            ->merge($references)->unique()->values();
        $confirmed = $inventoryKnown && $references->isEmpty();
        $checks = [];
        foreach ($candidates as $candidate) {
            $result = $this->client->consultProperty($candidate, $credential);
            $state = $this->stateFromResponse($result['data'] ?? []);
            $checks[$candidate] = ['ok' => $result['ok'] ?? false, 'state' => $state['state']];
            $confirmed = $confirmed && ($result['ok'] ?? false)
                && in_array($state['state'], ['missing', 'inactive'], true);
        }

        return ['code' => str_starts_with($code, $prefix) ? $code : $prefix.$source, 'result' => [
            'ok' => $inventoryKnown,
            'withdrawal_confirmed' => $confirmed,
            'data' => ['inventory_references' => $references->all(), 'checks' => $checks],
        ]];
    }

    public function cleanCodes(bool $fresh = false): ?Collection
    {
        return $this->codes($fresh)
            ?->reject(fn (string $code) => $this->isLegacyCode($code))
            ->values();
    }

    public function sourceCodes(bool $fresh = false): ?Collection
    {
        return $this->codes($fresh)
            ?->map(fn (string $code) => $this->sourceCode($code))
            ->filter()
            ->unique()
            ->values();
    }

    public function legacyCodeForSource(string $sourceCode): string
    {
        $prefix = (string) config('portals.ciencuadras.property_code_prefix', '22130-');

        return $prefix.'P'.$this->sourceCode($sourceCode);
    }

    public function inspectLegacyCode(
        string $legacyCode,
        ?PortalCredential $credential = null,
        bool $fresh = false
    ): ?array {
        $credential ??= $this->credential();
        if (! $credential) {
            return null;
        }

        $result = $this->client->consultProperty($legacyCode, $credential);
        if (! ($result['ok'] ?? false)) {
            return null;
        }

        $message = $result['data']['message'] ?? null;
        $property = is_array($message)
            && array_is_list($message)
            && isset($message[0])
            && is_array($message[0])
                ? $message[0]
                : null;

        if (! $property) {
            return [
                'state' => 'missing',
                'property' => null,
                'response' => $result['data'] ?? null,
            ];
        }

        $activeLabel = strtolower(trim((string) ($property['active'] ?? '')));
        $status = trim((string) ($property['status'] ?? ''));
        $inactive = in_array($status, ['5', '8'], true)
            || str_contains($activeLabel, 'inactivo')
            || str_contains($activeLabel, 'eliminado');

        return [
            'state' => $inactive ? 'inactive' : ($activeLabel === 'activo' ? 'active' : 'unknown'),
            'property' => $property,
            'response' => $result['data'] ?? null,
        ];
    }

    public function isLegacyCode(string $portalCode): bool
    {
        $prefix = preg_quote((string) config('portals.ciencuadras.property_code_prefix', '22130-'), '/');

        return preg_match('/^'.$prefix.'P/i', trim($portalCode)) === 1;
    }

    protected function credential(): ?PortalCredential
    {
        $login = $this->client->login();
        $token = ($login['ok'] ?? false)
            ? $this->client->extractToken($login['data'] ?? [])
            : null;

        return $token ? new PortalCredential(['access_token' => $token]) : null;
    }
}
