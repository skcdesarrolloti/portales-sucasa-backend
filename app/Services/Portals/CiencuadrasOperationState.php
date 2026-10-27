<?php

namespace App\Services\Portals;

class CiencuadrasOperationState
{
    public static function resolve(?array $submission, ?array $task, ?array $property, ?string $target): string
    {
        if ($submission !== null && (! ($submission['ok'] ?? false) || self::hasStatus($submission['data'] ?? [], 'error'))) {
            return 'error';
        }
        if ($task !== null && (! ($task['ok'] ?? false) || self::hasStatus($task['data'] ?? [], 'error'))) {
            return 'error';
        }
        if (in_array($target, ['I', 'D'], true)) {
            return ($property['withdrawal_confirmed'] ?? false) === true ? 'paused' : 'pending';
        }
        if ($task && str_contains(strtolower(json_encode($task['data'] ?? [])), 'pending')) {
            return 'pending';
        }
        if ($task && self::hasStatus($task['data'] ?? [], 'success')) {
            return 'synced';
        }
        // An old active advertisement does not prove a submitted change was applied.
        if ($submission !== null || $task !== null || $target === 'A') {
            return 'pending';
        }
        if (! ($property['ok'] ?? false)) {
            return 'error';
        }
        $items = data_get($property, 'data.message', []);
        $items = is_array($items) && ! array_is_list($items) ? [$items] : $items;
        foreach (is_array($items) ? $items : [] as $item) {
            if (is_array($item) && strtolower((string) ($item['active'] ?? '')) === 'activo') {
                return 'synced';
            }
        }

        return 'pending';
    }

    private static function hasStatus(mixed $data, string $wanted): bool
    {
        if (! is_array($data)) {
            return false;
        }
        if (strtolower((string) ($data['status'] ?? '')) === $wanted) {
            return true;
        }
        foreach ($data as $value) {
            if (is_array($value) && self::hasStatus($value, $wanted)) {
                return true;
            }
        }

        return false;
    }
}
