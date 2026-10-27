<?php

namespace App\Console\Commands;

use App\Models\Integration;
use App\Models\PropertySyncStatus;
use App\Services\Portals\CiencuadrasActiveProperties;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ReconcileCiencuadrasActiveProperties extends Command
{
    protected $signature = 'ciencuadras:reconcile-active
        {--grace=30 : Minutos de espera antes de considerar no confirmada una publicación}
        {--dry-run : Muestra los cambios sin guardarlos}';

    protected $description = 'Reconcilia los estados locales con los inmuebles activos reportados por Ciencuadras.';

    public function handle(CiencuadrasActiveProperties $activeProperties): int
    {
        $integration = Integration::where('slug', 'ciencuadras')->first();
        if (! $integration) {
            $this->warn('No existe la integración ciencuadras.');
            return self::SUCCESS;
        }

        $portalCodes = $activeProperties->codes(fresh: true);
        if ($portalCodes === null) {
            $this->error('No fue posible consultar los inmuebles activos de Ciencuadras.');
            return self::FAILURE;
        }
        $activeBySourceCode = $portalCodes
            ->groupBy(fn (string $portalCode) => $activeProperties->sourceCode($portalCode))
            ->map(fn ($codes) => $codes->first(
                fn (string $portalCode) => ! $activeProperties->isLegacyCode($portalCode)
            ) ?: $codes->first());

        $statuses = PropertySyncStatus::query()
            ->with('property')
            ->where('integration_id', $integration->id)
            ->where('environment', config('portals.ciencuadras.environment'))
            ->get()
            ->filter(fn (PropertySyncStatus $status) => $status->property !== null)
            ->values();

        $sourceCodes = $statuses
            ->pluck('property.code')
            ->map(fn ($code) => (string) $code)
            ->filter()
            ->unique()
            ->values();

        $wpStates = DB::connection('wordpress')
            ->table('wp_jet_cct_inmuebles')
            ->whereIn('codigo', $sourceCodes->all())
            ->select(['codigo', 'estado', 'cct_status'])
            ->get()
            ->keyBy(fn ($row) => (string) $row->codigo);

        $graceMinutes = max(1, (int) $this->option('grace'));
        $dryRun = (bool) $this->option('dry-run');
        $summary = [
            'active' => 0,
            'pending' => 0,
            'not_synced' => 0,
            'paused' => 0,
            'error' => 0,
            'unchanged' => 0,
        ];

        foreach ($statuses as $status) {
            $code = (string) $status->property->code;
            $portalCode = $activeBySourceCode->get($code);
            $wpRow = $wpStates->get($code);
            $isPublic = $this->isPublicInWordPress($wpRow);
            $recentlySent = $status->last_attempt_at
                && $status->last_attempt_at->gte(now()->subMinutes($graceMinutes));

            if (in_array($status->sync_status, ['pending', 'syncing', 'error'], true)) {
                $summary['unchanged']++;
                continue;
            }

            if ($portalCode) {
                $nextStatus = 'synced';
                $summary['active']++;
            } elseif ($status->sync_status === 'error') {
                $nextStatus = 'error';
                $summary['error']++;
            } elseif ($isPublic && $recentlySent) {
                $nextStatus = 'pending';
                $summary['pending']++;
            } elseif ($isPublic) {
                $nextStatus = 'not_synced';
                $summary['not_synced']++;
            } else {
                // Absence from a global list cannot confirm withdrawal of every variant.
                $summary['unchanged']++;
                continue;
            }

            if ($status->sync_status === $nextStatus && (! $portalCode || $status->external_id === $portalCode)) {
                $summary['unchanged']++;
                continue;
            }

            $this->line("{$code}: {$status->sync_status} -> {$nextStatus}");

            if ($dryRun) {
                continue;
            }

            $status->fill([
                'sync_status' => $nextStatus,
                'external_id' => $portalCode ?: $status->external_id,
                'external_url' => $nextStatus === 'synced' ? $status->external_url : null,
                'last_response' => array_merge($status->last_response ?? [], [
                    'portal_check' => [
                        'active' => $portalCode !== null,
                        'portal_code' => $portalCode,
                        'checked_at' => now()->toISOString(),
                    ],
                ]),
                'last_error' => match (true) {
                    $nextStatus === 'not_synced' => 'Ciencuadras no reporta este inmueble como activo. Requiere revisión manual; no se enviará otra publicación automáticamente.',
                    default => null,
                },
                // Inventory presence is not confirmation that new property data was applied.
                'last_synced_at' => $status->last_synced_at,
            ]);
            $status->save();
        }

        $this->info(
            "Publicados en portal: {$portalCodes->count()} | Confirmados: {$summary['active']} | "
            . "En espera: {$summary['pending']} | Revisión manual: {$summary['not_synced']} | "
            . "Despublicados: {$summary['paused']} | Errores: {$summary['error']} | "
            . "Sin cambios: {$summary['unchanged']}"
        );

        return self::SUCCESS;
    }

    protected function isPublicInWordPress($row): bool
    {
        if (! $row || $row->cct_status !== 'publish') {
            return false;
        }

        $status = Str::ascii(strtolower(trim((string) $row->estado)));

        return in_array($status, ['publico', 'publicado'], true);
    }
}
