<?php

namespace App\Services\Portals;

use App\Models\PropertySyncStatus;
use Carbon\Carbon;
use Throwable;

class PortalSyncPolicy
{
    public static function modifiedAt(object $row): ?Carbon
    {
        $dates = [];
        foreach ([$row->fecha_actualizacion ?? null, $row->cct_modified ?? null] as $value) {
            if (! $value) {
                continue;
            }
            try {
                $dates[] = is_numeric($value) ? Carbon::createFromTimestamp((int) $value) : Carbon::parse($value);
            } catch (Throwable) {
                // A malformed source date must not hide the other valid date.
            }
        }

        return collect($dates)->sortByDesc(fn (Carbon $date) => $date->getTimestamp())->first();
    }

    public static function retryError(object $row, PropertySyncStatus $sync): bool
    {
        $attempt = $sync->last_attempt_at;
        if (! $attempt) {
            return false;
        }
        $error = strtolower((string) $sync->last_error);
        // Identity ambiguity requires review, never an automatic new publication.
        if (str_contains($error, 'remote_identity_unknown') || str_contains($error, 'referencias ambiguas')) {
            return false;
        }
        $modified = self::modifiedAt($row);
        if ($modified && $modified->gt($attempt)) {
            return $attempt->lte(now()->subMinutes(5));
        }
        $transient = collect(['timeout', 'timed out', '429', '500', '502', '503', '504',
            'no quota', 'no_quota', 'task_expired', 'sellandrentnotsupported'])
            ->contains(fn ($text) => str_contains($error, $text));
        $minutes = min(1440, 30 * (2 ** min(6, max(0, (int) $sync->attempts - 1))));

        return $transient && $attempt->lte(now()->subMinutes($minutes));
    }
}
