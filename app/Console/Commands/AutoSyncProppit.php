<?php

namespace App\Console\Commands;

use App\Models\Integration;
use App\Models\Property;
use App\Models\PropertySyncStatus;
use App\Services\Portals\ProppitClient;
use App\Services\Portals\ProppitPropertyMapper;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use stdClass;
use Throwable;

class AutoSyncProppit extends Command
{
    protected $signature = 'proppit:auto-sync
        {--limit= : Cantidad máxima de acciones a ejecutar}
        {--scan= : Cantidad máxima de inmuebles WP a revisar}
        {--code=* : Código(s) específicos a procesar}
        {--retry-errors : Reintenta inmuebles en error si siguen públicos en WordPress}
        {--dry-run : Solo muestra qué haría, sin enviar a Proppit}
        {--force : Ejecuta aunque PROPPIT_AUTO_SYNC esté apagado}';

    protected $description = 'Publica, actualiza o despublica automáticamente inmuebles en Proppit según la tabla WP.';

    public function handle(ProppitClient $client, ProppitPropertyMapper $mapper): int
    {
        if (! config('portals.proppit.auto_sync') && ! $this->option('force')) {
            $this->info('Auto-sync de Proppit apagado. Actívalo con PROPPIT_AUTO_SYNC=true.');
            return self::SUCCESS;
        }

        $integration = Integration::where('slug', 'proppit')->first();
        if (! $integration) {
            $this->warn('No existe la integración proppit.');
            return self::SUCCESS;
        }

        $limit = max(1, (int) ($this->option('limit') ?: config('portals.proppit.auto_sync_limit', 20)));
        $scan = max($limit, (int) ($this->option('scan') ?: config('portals.proppit.auto_sync_scan', 500)));
        $dryRun = (bool) $this->option('dry-run');

        $token = null;
        if (! $dryRun) {
            $login = $client->token();
            $token = $login['data']['token'] ?? null;
            if (! ($login['ok'] ?? false) || ! $token) {
                $this->error('No fue posible iniciar sesión en Proppit. Revisa PROPPIT_CLIENT_ID y PROPPIT_CLIENT_SECRET.');
                return self::FAILURE;
            }
        }

        $codes = collect((array) $this->option('code'))
            ->map(fn ($code) => trim((string) $code))
            ->filter()
            ->unique()
            ->values();

        $rowsQuery = DB::connection('wordpress')
            ->table('wp_jet_cct_inmuebles')
            ->select(['codigo', 'estado', 'fecha_actualizacion', 'cct_modified'])
            ->where('cct_status', 'publish');

        $rotationKey = 'proppit.auto-sync-offset.production';
        $totalRows = 0;
        $scanOffset = 0;
        if ($codes->isNotEmpty()) {
            $rowsQuery->whereIn('codigo', $codes->all());
        } else {
            $totalRows = (clone $rowsQuery)->count();
            $scanOffset = $totalRows ? (int) Cache::get($rotationKey, 0) % $totalRows : 0;
            $rowsQuery->orderBy('codigo')->orderByDesc('cct_modified')->offset($scanOffset)->limit($scan);
        }

        $rows = $rowsQuery->get();
        $rowCodes = $rows
            ->pluck('codigo')
            ->map(fn ($code) => (string) $code)
            ->filter()
            ->unique()
            ->values();

        $properties = Property::query()
            ->whereIn('code', $rowCodes->all())
            ->get()
            ->keyBy(fn (Property $property) => (string) $property->code);

        $syncs = PropertySyncStatus::query()
            ->whereIn('property_id', $properties->pluck('id')->all())
            ->where('integration_id', $integration->id)
            ->where('environment', 'production')
            ->get()
            ->keyBy('property_id');

        $executed = 0;
        $summary = ['publish' => 0, 'update' => 0, 'pause' => 0, 'skipped' => 0, 'error' => 0];

        $scanned = 0;
        $seenCodes = [];
        foreach ($rows as $row) {
            if ($executed >= $limit) {
                break;
            }

            $scanned++;
            $code = trim((string) $row->codigo);
            if ($code === '' || isset($seenCodes[$code])) {
                $summary['skipped']++;
                continue;
            }

            $seenCodes[$code] = true;
            $property = $properties->get($code);
            $sync = $property ? $syncs->get($property->id) : null;
            $decision = $this->decision($row, $sync, (bool) $this->option('retry-errors'));

            if (! $decision) {
                $summary['skipped']++;
                continue;
            }

            $action = $decision;
            $this->line("{$code}: {$action}");

            if ($dryRun) {
                $summary[$action]++;
                $executed++;
                continue;
            }

            try {
                if ($action === 'pause') {
                    if (! $property || ! $sync?->external_id) {
                        $summary['skipped']++;
                        continue;
                    }

                    $result = $client->deleteAd((string) $sync->external_id, $token);
                    $syncStatus = ($result['ok'] ?? false) ? 'paused' : 'error';
                    $this->saveStatus(
                        $property->id,
                        $integration->id,
                        $syncStatus,
                        (string) $sync->external_id,
                        ['auto_action' => $action, 'response' => $result],
                        $syncStatus === 'error' ? $this->errorMessage($result) : null
                    );
                    $summary[$syncStatus === 'error' ? 'error' : $action]++;
                    $executed++;
                    continue;
                }

                $mapped = $mapper->fromCode($code);
                $property = $mapped['property'];

                if ($mapped['errors']) {
                    $this->saveStatus(
                        $property->id,
                        $integration->id,
                        'error',
                        $mapped['payload']['referenceId'],
                        ['auto_action' => $action, 'validation_errors' => $mapped['errors'], 'source' => $mapped['source']],
                        implode(' ', $mapped['errors'])
                    );
                    $summary['error']++;
                    $executed++;
                    continue;
                }

                if ($action === 'publish') {
                    $mapped['payload'] = $mapper->boostOnPublish($mapped['payload']);
                }

                $referenceId = (string) $mapped['payload']['referenceId'];
                if ($sync?->sync_status === 'error') {
                    $existing = $client->getAd($sync->external_id ?: $referenceId, $token);
                    if (! ($existing['ok'] ?? false) && (int) ($existing['status'] ?? 0) !== 404) {
                        $this->saveStatus($property->id, $integration->id, 'error', $sync->external_id ?: $referenceId,
                            ['auto_action' => 'verify', 'response' => $existing], $this->errorMessage($existing));
                        $summary['error']++;
                        $executed++;
                        continue;
                    }
                    $action = ($existing['ok'] ?? false) ? 'update' : 'publish';
                }
                $result = $action === 'publish'
                    ? $client->createAd($mapped['payload'], $token)
                    : $client->updateAd($sync?->external_id ?: $referenceId, $mapped['payload'], $token);
                $syncStatus = ($result['ok'] ?? false) ? 'synced' : 'error';

                $this->saveStatus(
                    $property->id,
                    $integration->id,
                    $syncStatus,
                    $referenceId,
                    ['auto_action' => $action, 'response' => $result],
                    $syncStatus === 'error' ? $this->errorMessage($result) : null
                );

                if ($syncStatus === 'synced') {
                    $property->update(['status' => 'active', 'published_at' => $property->published_at ?: now()]);
                }

                $summary[$syncStatus === 'error' ? 'error' : $action]++;
                $executed++;
            } catch (Throwable $exception) {
                $executed++;
                $summary['error']++;
                $this->error("{$code}: {$exception->getMessage()}");
            }
        }

        $this->info("Listo Proppit. Publicar: {$summary['publish']} | Actualizar: {$summary['update']} | Despublicar: {$summary['pause']} | Omitidos: {$summary['skipped']} | Errores: {$summary['error']}");

        if (! $dryRun && $codes->isEmpty() && $totalRows > 0) {
            Cache::put($rotationKey, ($scanOffset + $scanned) % $totalRows, now()->addDay());
        }

        return $summary['error'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    protected function decision(stdClass $row, ?PropertySyncStatus $sync, bool $retryErrors = false): ?string
    {
        $isPublic = $this->isPublicStatus($row->estado);
        $current = $sync?->sync_status;

        if (in_array($current, ['pending', 'syncing'], true)) {
            return null;
        }

        if ($isPublic) {
            if (! $sync || in_array($current, ['paused', 'not_synced'], true)) {
                return 'publish';
            }

            if ($current === 'error') {
                return ($retryErrors || \App\Services\Portals\PortalSyncPolicy::retryError($row, $sync)) ? ($sync->external_id ? 'update' : 'publish') : null;
            }

            $modifiedAt = $this->wpModifiedAt($row);
            $lastSyncedAt = $sync->last_synced_at ?? $sync->updated_at;
            if ($current === 'synced' && (! $lastSyncedAt || ($modifiedAt && $modifiedAt->gt($lastSyncedAt)))) {
                return 'update';
            }

            return null;
        }

        return in_array($current, ['synced', 'error'], true) ? 'pause' : null;
    }

    protected function isPublicStatus(?string $status): bool
    {
        $status = Str::ascii(strtolower(trim((string) $status)));

        return in_array($status, ['publico', 'publicado'], true);
    }

    protected function wpModifiedAt(stdClass $row): ?Carbon
    {
        return \App\Services\Portals\PortalSyncPolicy::modifiedAt($row);
    }

    protected function saveStatus(
        int $propertyId,
        int $integrationId,
        string $syncStatus,
        string $externalId,
        array $response,
        ?string $error
    ): void {
        $status = PropertySyncStatus::firstOrNew([
            'property_id' => $propertyId,
            'integration_id' => $integrationId,
            'environment' => 'production',
        ]);

        $status->fill([
            'sync_status' => $syncStatus,
            'external_id' => $externalId,
            'external_url' => $syncStatus === 'paused' ? null : $this->externalUrl($externalId),
            'last_response' => $response,
            'last_error' => $error,
            'last_attempt_at' => now(),
            'last_synced_at' => in_array($syncStatus, ['synced', 'paused'], true) ? now() : $status->last_synced_at,
            'attempts' => ((int) $status->attempts) + 1,
        ]);
        $status->save();
    }

    protected function externalUrl(string $externalId): ?string
    {
        $base = config('portals.proppit.public_url');

        return $base ? rtrim($base, '/').'/'.rawurlencode($externalId) : null;
    }

    protected function errorMessage(array $response): string
    {
        return substr(json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 0, 2000);
    }
}
