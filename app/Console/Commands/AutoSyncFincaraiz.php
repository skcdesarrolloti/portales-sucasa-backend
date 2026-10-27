<?php

namespace App\Console\Commands;

use App\Http\Controllers\Portal\FincaraizController;
use App\Models\Integration;
use App\Models\PortalCredential;
use App\Models\Property;
use App\Models\PropertySyncStatus;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use stdClass;
use Throwable;

class AutoSyncFincaraiz extends Command
{
    protected $signature = 'fincaraiz:auto-sync
        {--limit= : Cantidad máxima de acciones a ejecutar}
        {--scan= : Cantidad máxima de inmuebles WP a revisar}
        {--code=* : Código(s) específicos a procesar}
        {--retry-errors : Reintenta inmuebles en error si siguen públicos}
        {--dry-run : Solo muestra qué haría, sin enviar a Fincaraíz}
        {--force : Ejecuta aunque la automatización esté apagada}';

    protected $description = 'Publica, actualiza, activa, verifica o retira inmuebles automáticamente en Fincaraíz.';

    public function handle(FincaraizController $controller): int
    {
        $integration = Integration::where('slug', 'fincaraiz')->first();
        if (! $integration) {
            $this->warn('No existe la integración Fincaraíz.');

            return self::SUCCESS;
        }

        $credentials = PortalCredential::query()
            ->where('integration_id', $integration->id)
            ->with('user')
            ->latest('updated_at')
            ->get();
        $credential = $this->preferredCredential($credentials);
        $enabled = (bool) data_get($credential?->data, 'auto_sync', config('portals.fincaraiz.auto_sync', false));
        if (! $enabled && ! $this->option('force')) {
            $this->info('Auto-sync de Fincaraíz apagado. Actívalo desde Integraciones → Fincaraíz → Configurar.');

            return self::SUCCESS;
        }

        $limit = max(1, (int) ($this->option('limit')
            ?: data_get($credential?->data, 'auto_sync_limit', config('portals.fincaraiz.auto_sync_limit', 20))));
        $scan = max($limit, (int) ($this->option('scan') ?: config('portals.fincaraiz.auto_sync_scan', 500)));
        $dryRun = (bool) $this->option('dry-run');
        $request = Request::create('/console/fincaraiz/auto-sync', 'POST');
        if ($credential?->user) {
            $request->setUserResolver(fn () => $credential->user);
        }

        $executed = 0;
        $summary = [
            'verify' => 0,
            'publish' => 0,
            'update' => 0,
            'recover' => 0,
            'activate' => 0,
            'pause' => 0,
            'skipped' => 0,
            'error' => 0,
        ];

        // Reserve at least half the run for catalog work, even if old tasks never finish.
        $pendingLimit = max(1, intdiv($limit, 2));
        $pending = PropertySyncStatus::query()
            ->where('integration_id', $integration->id)
            ->where('environment', config('portals.fincaraiz.environment'))
            ->where('portal_variant', 'default')
            ->where('sync_status', 'pending')
            ->with('property')
            ->oldest('last_attempt_at')
            ->when($this->option('code'), fn ($query) => $query->whereHas('property',
                fn ($properties) => $properties->whereIn('code', (array) $this->option('code'))))
            ->limit($pendingLimit)
            ->get();

        foreach ($pending as $sync) {
            if ($executed >= $pendingLimit || ! $sync->property) {
                continue;
            }

            $code = (string) $sync->property->code;
            if ($dryRun) {
                $this->line("{$code}: verify");
                $summary['verify']++;
                $executed++;

                continue;
            }

            if (! $this->invoke($controller, 'verify', $request, $code)) {
                $summary['error']++;
                $executed++;

                continue;
            }

            $summary['verify']++;
            $executed++;
            $fresh = $sync->fresh();
            if ($executed < $pendingLimit
                && $fresh?->sync_status === 'pending'
                && $fresh?->external_id
                && data_get($fresh->last_response, 'action') === 'activate_required') {
                $public = DB::connection('wordpress')->table('wp_jet_cct_inmuebles')
                    ->where('codigo', $code)->where('cct_status', 'publish')
                    ->get(['estado'])->contains(fn ($row) => $this->isPublicStatus($row->estado));
                $action = $public ? 'activate' : 'pause';
                if ($this->invoke($controller, $action, $request, $code)) {
                    $summary[$action]++;
                } else {
                    $summary['error']++;
                }
                $executed++;
            }
        }

        if ($executed < $limit) {
            $executed = $this->processCatalog(
                $controller,
                $request,
                $integration,
                $limit,
                $scan,
                $dryRun,
                $executed,
                $summary
            );
        }

        $this->info("Listo Fincaraíz. Verificar: {$summary['verify']} | Publicar: {$summary['publish']} | Actualizar: {$summary['update']} | Recuperar: {$summary['recover']} | Activar: {$summary['activate']} | Retirar: {$summary['pause']} | Omitidos: {$summary['skipped']} | Errores: {$summary['error']}");

        return $summary['error'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    protected function preferredCredential(Collection $credentials): ?PortalCredential
    {
        return $credentials->first(fn (PortalCredential $item) => (bool) data_get($item->data, 'auto_sync'))
            ?? $credentials->first();
    }

    protected function processCatalog(
        FincaraizController $controller,
        Request $request,
        Integration $integration,
        int $limit,
        int $scan,
        bool $dryRun,
        int $executed,
        array &$summary
    ): int {
        $codes = collect((array) $this->option('code'))
            ->map(fn ($code) => trim((string) $code))
            ->filter()
            ->unique()
            ->values();
        $rowsQuery = DB::connection('wordpress')
            ->table('wp_jet_cct_inmuebles')
            ->select(['codigo', 'estado', 'fecha_actualizacion', 'cct_modified'])
            ->where('cct_status', 'publish');

        $rotationKey = 'fincaraiz.auto-sync-offset.'.config('portals.fincaraiz.environment');
        $scanOffset = 0;
        $totalRows = 0;

        if ($codes->isNotEmpty()) {
            $rowsQuery->whereIn('codigo', $codes->all());
        } else {
            $totalRows = (clone $rowsQuery)->count();
            $scanOffset = $totalRows > 0
                ? ((int) Cache::get($rotationKey, 0)) % $totalRows
                : 0;

            $rowsQuery
                ->orderBy('codigo')
                ->orderByDesc('cct_modified')
                ->offset($scanOffset)
                ->limit($scan);
        }

        $rows = $rowsQuery->get();
        $rowCodes = $rows->pluck('codigo')->map(fn ($code) => trim((string) $code))->filter()->unique();
        $properties = Property::query()->whereIn('code', $rowCodes)->get()->keyBy('code');
        $syncs = PropertySyncStatus::query()
            ->whereIn('property_id', $properties->pluck('id'))
            ->where('integration_id', $integration->id)
            ->where('environment', config('portals.fincaraiz.environment'))
            ->where('portal_variant', 'default')
            ->get()
            ->keyBy('property_id');

        $scanned = 0;
        $seenCodes = [];

        foreach ($rows as $row) {
            if ($executed >= $limit) {
                break;
            }

            $scanned++;

            $code = trim((string) $row->codigo);
            if (! $this->claimCatalogCode($code, $seenCodes)) {
                $summary['skipped']++;

                continue;
            }
            $property = $properties->get($code);
            $sync = $property ? $syncs->get($property->id) : null;
            $action = $this->decision($row, $sync, (bool) $this->option('retry-errors'));
            if (! $action) {
                $summary['skipped']++;

                continue;
            }

            $this->line("{$code}: {$action}");
            if ($dryRun) {
                $summary[$action]++;
                $executed++;

                continue;
            }

            if ($this->invoke($controller, $action, $request, $code)) {
                $summary[$action]++;
            } else {
                $summary['error']++;
            }
            $executed++;
        }

        if ($codes->isEmpty() && $totalRows > 0) {
            $nextOffset = $this->nextScanOffset($scanOffset, $scanned, $totalRows);
            if (! $dryRun) {
                Cache::put($rotationKey, $nextOffset, now()->addDay());
            }
            $cursorMessage = $dryRun
                ? 'simulación; cursor sin cambios'
                : "próxima corrida desde {$nextOffset}";
            $this->line("Escaneo: {$scanned} de {$totalRows} inmuebles; {$cursorMessage}.");
        }

        return $executed;
    }

    protected function nextScanOffset(int $currentOffset, int $scanned, int $totalRows): int
    {
        return $totalRows > 0 ? ($currentOffset + $scanned) % $totalRows : 0;
    }

    protected function claimCatalogCode(string $code, array &$seenCodes): bool
    {
        if ($code === '' || isset($seenCodes[$code])) {
            return false;
        }

        $seenCodes[$code] = true;

        return true;
    }

    protected function decision(stdClass $row, ?PropertySyncStatus $sync, bool $retryErrors = false): ?string
    {
        $isPublic = $this->isPublicStatus($row->estado ?? null);
        $current = $sync?->sync_status;

        if (in_array($current, ['pending', 'syncing'], true)) {
            return null;
        }

        if ($isPublic) {
            if (! $sync || $current === 'not_synced') {
                return 'publish';
            }
            if ($current === 'paused') {
                return $sync->external_id ? 'activate' : 'publish';
            }
            if ($current === 'error') {
                return ($retryErrors || \App\Services\Portals\PortalSyncPolicy::retryError($row, $sync)) ? 'recover' : null;
            }

            $modifiedAt = $this->wpModifiedAt($row);
            $lastSyncedAt = $sync->last_synced_at ?? $sync->updated_at;

            return $current === 'synced' && $modifiedAt && (! $lastSyncedAt || $modifiedAt->gt($lastSyncedAt))
                ? 'update'
                : null;
        }

        return $sync?->external_id && in_array($current, ['synced', 'error'], true) ? 'pause' : null;
    }

    protected function invoke(FincaraizController $controller, string $action, Request $request, string $code): bool
    {
        try {
            $response = $controller->{$action}($request, $code);
            $data = $response->getData(true)['Datos'] ?? [];
            if ($response->getStatusCode() >= 400 || ($data['ok'] ?? true) === false) {
                $this->error("{$code}: ".($data['message'] ?? data_get($data, 'data.error') ?? 'Fincaraíz rechazó la acción.'));

                return false;
            }

            return true;
        } catch (Throwable $exception) {
            $this->error("{$code}: {$exception->getMessage()}");

            return false;
        }
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

    protected function taskId(PropertySyncStatus $sync): ?string
    {
        $taskId = trim((string) (data_get($sync->last_response, 'task_id')
            ?: data_get($sync->last_response, 'portal.task.id')));

        return $taskId !== '' ? $taskId : null;
    }
}
