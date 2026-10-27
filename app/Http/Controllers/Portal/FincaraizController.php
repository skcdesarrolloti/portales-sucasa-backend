<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Integration;
use App\Models\PortalCredential;
use App\Models\Property;
use App\Models\PropertySyncStatus;
use App\Services\Portals\FincaraizClient;
use App\Services\Portals\FincaraizListingReconciler;
use App\Services\Portals\FincaraizListingRetirer;
use App\Services\Portals\FincaraizPropertyMapper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class FincaraizController extends Controller
{
    public function __construct(
        protected FincaraizClient $fr,
        protected FincaraizPropertyMapper $mapper,
        protected ?FincaraizListingReconciler $reconciler = null,
        protected ?FincaraizListingRetirer $retirer = null
    ) {}

    public function status(Request $request): JsonResponse
    {
        $settings = $this->settings($request);

        return response()->json(['Datos' => [
            'configured' => ! empty($settings['api_key']),
            'client_configured' => ! empty($settings['client_id']),
            'environment' => config('portals.fincaraiz.environment'),
            'api_url' => config('portals.fincaraiz.api_url'),
            'client_id' => $settings['client_id'] ?? null,
            'client_agent' => $settings['client_agent'] ?? null,
            'contact_email' => $settings['contact_email'] ?? null,
            'contact_phone' => $settings['contact_phone'] ?? null,
            'contact_whatsapp' => $settings['contact_whatsapp'] ?? null,
            'show_exact_address' => (bool) ($settings['show_exact_address'] ?? false),
            'dual_offer' => 'rent',
            'auto_sync' => (bool) ($settings['auto_sync'] ?? false),
            'auto_sync_limit' => (int) ($settings['auto_sync_limit'] ?? 20),
            'api_key_source' => $this->userCredential($request)?->access_token ? 'panel' : 'environment',
            'webhook_configured' => ! empty(config('portals.fincaraiz.webhook_id'))
                && ! empty(config('portals.fincaraiz.webhook_verify_token')),
        ]]);
    }

    public function saveSettings(Request $request): JsonResponse
    {
        $data = $request->validate([
            'api_key' => ['nullable', 'string', 'max:4096'],
            'client_id' => ['nullable', 'uuid'],
            'client_agent' => ['nullable', 'integer', 'min:1'],
            'contact_email' => ['nullable', 'email', 'max:255'],
            'contact_phone' => ['nullable', 'string', 'max:40'],
            'contact_whatsapp' => ['nullable', 'string', 'max:40'],
            'show_exact_address' => ['required', 'boolean'],
            'dual_offer' => ['required', 'in:sale,rent'],
            'auto_sync' => ['sometimes', 'boolean'],
            'auto_sync_limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $existing = $this->userCredential($request);
        $apiKey = trim((string) ($data['api_key'] ?? ''));
        abort_unless($apiKey !== '' || $existing?->access_token || config('portals.fincaraiz.api_key'), 422, 'Ingresa la API key de Fincaraíz.');

        $credential = $existing ?? new PortalCredential;
        $existingData = is_array($existing?->data) ? $existing->data : [];
        $settingsData = array_replace($existingData, Arr::except($data, ['api_key']));
        if (trim((string) ($existingData['client_id'] ?? '')) !== trim((string) ($settingsData['client_id'] ?? ''))) {
            unset($settingsData['fincaraiz_catalog_snapshot']);
        }
        $credential->fill([
            'user_id' => $request->user()->id,
            'integration_id' => $this->integration()->id,
            'account_key' => 'user:'.$request->user()->id,
            'data' => $settingsData,
        ]);
        if ($apiKey !== '') {
            $credential->access_token = $apiKey;
        }
        $credential->save();

        return $this->status($request);
    }

    public function clientInfo(Request $request): JsonResponse
    {
        $settings = $this->settings($request);
        $apiKey = $this->apiKey($settings);
        $clients = $this->fr->getClientInfo($apiKey);
        $clients = $this->configuredClientOnly($clients, $settings['client_id'] ?? null);
        $agents = null;
        if ($clients['ok'] && ! empty($settings['client_id'])) {
            $agents = $this->fr->getAgents((string) $settings['client_id'], $apiKey);
        }

        return response()->json(['Datos' => [
            'ok' => $clients['ok'] && ($agents === null || $agents['ok']),
            'environment' => config('portals.fincaraiz.environment'),
            'clients' => $clients,
            'agents' => $agents,
        ]]);
    }

    /**
     * GET /client returns every account associated with the integrator API key.
     * Each panel installation must expose only the account selected for its own
     * database, otherwise quotas from unrelated clients are easy to confuse.
     */
    protected function configuredClientOnly(array $result, mixed $configuredClientId): array
    {
        $clientId = strtolower(trim((string) $configuredClientId));
        if ($clientId === '' || ! ($result['ok'] ?? false) || ! is_array($result['data'] ?? null)) {
            return $result;
        }

        $clients = collect($result['data'])
            ->filter(fn ($client) => is_array($client)
                && hash_equals($clientId, strtolower(trim((string) ($client['id'] ?? '')))))
            ->values()
            ->all();

        if ($clients === []) {
            return [
                'ok' => false,
                'status' => 404,
                'data' => [
                    'detail' => 'El Client ID configurado no pertenece a los clientes autorizados para esta API key.',
                ],
            ];
        }

        $result['data'] = $clients;

        return $result;
    }

    public function locations(Request $request): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'min:2', 'max:120']]);
        $result = $this->fr->findLocations($data['name'], $this->apiKey($this->settings($request)));

        return response()->json(['Datos' => $result]);
    }

    public function listings(Request $request): JsonResponse
    {
        $settings = $this->settings($request);
        $clientId = $this->clientId($settings);
        $result = $this->fr->listListings(
            $this->apiKey($settings),
            $clientId,
            (int) $request->query('page', 1),
            (int) $request->query('page_size', 20),
            $request->query('search'),
            (string) $request->query('ordering', '-created')
        );

        if ($result['ok'] ?? false) {
            $rows = data_get($result, 'data.results', []);
            $ids = collect($rows)->pluck('id')->filter()->values();
            $references = PropertySyncStatus::query()
                ->where('integration_id', $this->integration()->id)
                ->where('environment', config('portals.fincaraiz.environment'))
                ->where('portal_variant', 'default')
                ->whereIn('external_id', $ids)
                ->with('property:id,code')
                ->get()
                ->keyBy('external_id');

            data_set($result, 'data.results', collect($rows)->map(function (array $listing) use ($references) {
                $reference = $references->get($listing['id'] ?? null);

                return $listing + [
                    'locally_referenced' => (bool) $reference,
                    'local_code' => $reference?->property?->code,
                ];
            })->values()->all());
        }

        return response()->json(['Datos' => $result]);
    }

    public function reconcile(Request $request): JsonResponse
    {
        $data = $request->validate([
            'limit' => ['sometimes', 'integer', 'min:1', 'max:25'],
            'offset' => ['sometimes', 'integer', 'min:0', 'max:100000'],
            'dry_run' => ['sometimes', 'boolean'],
            'confirmed' => ['required_if:dry_run,false', 'boolean'],
            'preview_token' => ['required_if:dry_run,false', 'nullable', 'uuid'],
        ]);
        $dryRun = (bool) ($data['dry_run'] ?? true);
        if (! $dryRun) {
            abort_unless(($data['confirmed'] ?? false) === true, 422, 'Confirma que deseas guardar las referencias locales.');
        }

        $settings = $this->settings($request);
        $this->apiKey($settings);
        $this->clientId($settings);
        $reconciler = $this->reconciler ?? app(FincaraizListingReconciler::class);

        if ($dryRun) {
            $result = $reconciler->reconcile(
                $settings,
                (int) ($data['limit'] ?? 10),
                true,
                (int) ($data['offset'] ?? 0)
            );
            $token = (string) Str::uuid();
            $expiresAt = now()->addMinutes(max(1, (int) config('portals.fincaraiz.reconcile_preview_minutes', 10)));
            Cache::put($this->reconcilePreviewKey($token), [
                'user_id' => $request->user()->id,
                'environment' => (string) config('portals.fincaraiz.environment', 'qa'),
                'client_id' => trim((string) $settings['client_id']),
                'items' => collect($result['items'] ?? [])
                    ->where('state', 'ready')
                    ->values()
                    ->all(),
            ], $expiresAt);
            $result['preview_token'] = $token;
            $result['preview_expires_at'] = $expiresAt->toIso8601String();

            return response()->json(['Datos' => $result]);
        }

        $token = (string) $data['preview_token'];
        $cacheKey = $this->reconcilePreviewKey($token);
        $preview = Cache::get($cacheKey);
        abort_unless(is_array($preview), 422, 'El análisis venció. Analiza nuevamente los inmuebles.');
        abort_unless((int) ($preview['user_id'] ?? 0) === (int) $request->user()->id, 403, 'Este análisis pertenece a otro usuario.');
        abort_unless(
            ($preview['environment'] ?? null) === (string) config('portals.fincaraiz.environment', 'qa')
                && hash_equals((string) ($preview['client_id'] ?? ''), trim((string) $settings['client_id'])),
            422,
            'La configuración de Fincaraíz cambió. Analiza nuevamente los inmuebles.'
        );
        Cache::forget($cacheKey);

        return response()->json(['Datos' => $reconciler->applyPreview($preview['items'] ?? [])]);
    }

    protected function reconcilePreviewKey(string $token): string
    {
        return 'fincaraiz:reconcile-preview:'.$token;
    }

    public function retire(Request $request): JsonResponse
    {
        $data = $request->validate([
            'dry_run' => ['sometimes', 'boolean'],
            'confirmed' => ['required_if:dry_run,false', 'boolean'],
            'preview_token' => ['required_if:dry_run,false', 'nullable', 'uuid'],
            'mode' => ['sometimes', 'in:standard,unresolved'],
            'listings' => ['required_if:dry_run,true', 'array', 'max:1000'],
            'listings.*.code' => ['required_with:listings', 'string', 'max:120'],
            'listings.*.fr_property_id' => ['required_with:listings', 'string', 'max:120'],
        ]);
        $dryRun = (bool) ($data['dry_run'] ?? true);
        $settings = $this->settings($request);
        $this->apiKey($settings);
        $this->clientId($settings);
        $retirer = $this->retirer ?? app(FincaraizListingRetirer::class);

        if ($dryRun) {
            $result = $retirer->preview($settings, $data['listings'] ?? []);
            if (! ($result['ok'] ?? false)) {
                return response()->json(['Datos' => $result], 422);
            }

            $token = (string) Str::uuid();
            $expiresAt = now()->addMinutes(max(1, (int) config('portals.fincaraiz.reconcile_preview_minutes', 10)));
            Cache::put($this->retirePreviewKey($token), [
                'user_id' => $request->user()->id,
                'environment' => (string) config('portals.fincaraiz.environment', 'qa'),
                'client_id' => trim((string) $settings['client_id']),
                'items' => collect($result['items'] ?? [])
                    ->filter(fn (array $item) => in_array(($item['state'] ?? null), ['ready', 'ready_to_link'], true)
                        || ! empty($item['listing_ids']))
                    ->concat($result['unreferenced_items'] ?? [])
                    ->values()
                    ->all(),
            ], $expiresAt);
            $result['preview_token'] = $token;
            $result['preview_expires_at'] = $expiresAt->toIso8601String();

            return response()->json(['Datos' => $result]);
        }

        abort_unless(($data['confirmed'] ?? false) === true, 422, 'Confirma que deseas desactivar los avisos de Fincaraíz.');
        $token = (string) $data['preview_token'];
        $cacheKey = $this->retirePreviewKey($token);
        $preview = Cache::pull($cacheKey);
        abort_unless(is_array($preview), 422, 'El análisis venció o ya fue utilizado. Carga nuevamente el archivo.');
        abort_unless((int) ($preview['user_id'] ?? 0) === (int) $request->user()->id, 403, 'Este análisis pertenece a otro usuario.');
        abort_unless(
            ($preview['environment'] ?? null) === (string) config('portals.fincaraiz.environment', 'qa')
                && hash_equals((string) ($preview['client_id'] ?? ''), trim((string) $settings['client_id'])),
            422,
            'La configuración de Fincaraíz cambió. Carga nuevamente el archivo.'
        );

        $mode = (string) ($data['mode'] ?? 'standard');

        return response()->json(['Datos' => $mode === 'unresolved'
            ? $retirer->applyUnresolved($settings, $preview['items'] ?? [])
            : $retirer->apply($settings, $preview['items'] ?? [])]);
    }

    protected function retirePreviewKey(string $token): string
    {
        return 'fincaraiz:retire-preview:'.$token;
    }

    public function payload(Request $request, string $code): JsonResponse
    {
        $mapped = $this->mapper->map($code, $this->settings($request));

        return response()->json(['Datos' => Arr::except($mapped, ['property'])]);
    }

    public function saveLocation(Request $request, string $code): JsonResponse
    {
        $location = $request->validate([
            'location_id' => ['required', 'uuid'],
            'name' => ['required', 'string', 'max:200'],
            'location_type' => ['nullable', 'string', 'max:40'],
            'country' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', 'string', 'max:100'],
            'city' => ['nullable', 'string', 'max:100'],
        ]);
        $mapped = $this->mapper->saveLocationMapping($code, $location, $this->settings($request));

        return response()->json(['Datos' => Arr::except($mapped, ['property'])]);
    }

    public function publish(Request $request, string $code): JsonResponse
    {
        $settings = $this->settings($request);
        $mapped = $this->mapper->map($code, $settings);
        if ($mapped['errors'] !== []) {
            return response()->json(['Datos' => $this->mappingError($mapped)], 422);
        }

        $result = $this->fr->createListing($mapped['payload'], $this->apiKey($settings));
        $this->storeQueuedResult($mapped['property'], $result, 'publish');

        return response()->json(['Datos' => $this->operationResult($result, $mapped)]);
    }

    public function update(Request $request, string $code): JsonResponse
    {
        $settings = $this->settings($request);
        $mapped = $this->mapper->map($code, $settings);
        $sync = $this->syncStatus($mapped['property']);
        abort_unless($sync?->external_id, 400, 'No hay listing_id confirmado para actualizar en Fincaraíz.');
        if ($mapped['errors'] !== []) {
            return response()->json(['Datos' => $this->mappingError($mapped)], 422);
        }

        $result = $this->fr->updateListing(
            $sync->external_id,
            $mapped['payload'],
            $this->apiKey($settings)
        );
        $this->storeQueuedResult($mapped['property'], $result, 'update');

        return response()->json(['Datos' => $this->operationResult($result, $mapped)]);
    }

    public function recover(Request $request, string $code): JsonResponse
    {
        $property = Property::where('code', $code)->firstOrFail();
        $sync = $this->syncStatus($property);

        if (! $sync?->external_id) {
            abort_if(str_contains((string) $sync?->last_error, 'remote_identity_unknown'), 409,
                'La tarea venció sin identificar el anuncio. Concilia el inventario antes de volver a publicar.');
            return $this->withRecoveryAction($this->publish($request, $code), 'publish');
        }

        $settings = $this->settings($request);
        $listing = $this->fr->getListing($this->apiKey($settings), $sync->external_id, $this->clientId($settings));

        if (! $listing['ok']) {
            if ((int) ($listing['status'] ?? 0) === 404) {
                return $this->withRecoveryAction($this->publish($request, $code), 'publish');
            }

            $sync->update([
                'sync_status' => 'error',
                'last_response' => array_merge($sync->last_response ?? [], [
                    'action' => 'recover',
                    'listing_verification' => $listing['data'] ?? null,
                ]),
                'last_error' => $this->errorMessage($listing),
                'last_attempt_at' => now(),
                'attempts' => $sync->attempts + 1,
            ]);

            return response()->json(['Datos' => $listing + [
                'sync_status' => 'error',
                'external_id' => $sync->external_id,
                'recovery_action' => 'verify',
            ]], $this->responseStatus($listing));
        }

        $remote = $this->listingRow($listing['data'] ?? []);
        $remoteStatus = $this->listingStatus($remote);
        $response = $this->update($request, $code);

        if ($this->responseAccepted($response)) {
            $fresh = $this->syncStatus($property);
            $lastResponse = $fresh?->last_response ?? [];
            $lastResponse['remote_status_before_retry'] = $remoteStatus;
            $lastResponse['fr_property_id'] = $this->listingPropertyId($remote)
                ?: data_get($lastResponse, 'fr_property_id');
            if (! $this->isActiveListingStatus($remoteStatus)) {
                $lastResponse['activate_after_update'] = true;
            }
            $fresh?->update(['last_response' => $lastResponse]);
        }

        return $this->withRecoveryAction(
            $response,
            $this->isActiveListingStatus($remoteStatus) ? 'update' : 'update_activate'
        );
    }

    public function pause(Request $request, string $code): JsonResponse
    {
        return $this->changeStatus($request, $code, 'DISABLED', 'pause');
    }

    public function activate(Request $request, string $code): JsonResponse
    {
        return $this->changeStatus($request, $code, 'ACTIVE', 'activate');
    }

    public function verify(Request $request, string $code): JsonResponse
    {
        $property = Property::where('code', $code)->firstOrFail();
        $sync = $this->syncStatus($property);
        abort_unless($sync, 400, 'No existe una sincronización de Fincaraíz para verificar.');
        $taskId = trim((string) ($request->input('task_id') ?: $this->storedTaskId($sync)));

        $settings = $this->settings($request);
        $apiKey = $this->apiKey($settings);

        $result = $taskId ? $this->fr->getTask($taskId, $apiKey)
            : ['ok' => false, 'status' => 404, 'data' => ['message' => 'Task id not found']];
        if ((int) ($result['status'] ?? 0) === 404
            || str_contains(strtolower(json_encode($result['data'] ?? [])), 'task id not found')) {
            return $this->resolveExpiredTask($sync, $apiKey, $this->clientId($settings), $result);
        }
        if ($result['ok']) {
            $this->applyTaskResult($property, $result['data'], $sync);
        } else {
            $sync->update([
                'last_response' => $this->storedResponse($sync, $result['data'] ?? [], $taskId),
                'last_error' => $this->errorMessage($result),
                'last_attempt_at' => now(),
                'attempts' => $sync->attempts + 1,
            ]);
        }

        $fresh = $sync->fresh();
        $listingVerification = null;
        if ($result['ok'] && $fresh?->sync_status === 'synced' && $fresh->external_id) {
            $listingVerification = $this->fr->getListing($apiKey, $fresh->external_id, $this->clientId($settings));
            $this->applyListingVerification($fresh, $listingVerification);
            $fresh = $fresh->fresh();
        }

        return response()->json(['Datos' => $result + [
            'sync_status' => $fresh->sync_status,
            'external_id' => $fresh->external_id,
            'external_url' => $fresh->external_url,
            'action' => data_get($fresh->last_response, 'action'),
            'requires_activation' => (bool) data_get($fresh->last_response, 'requires_activation', false),
            'listing_verification' => $listingVerification,
        ]]);
    }

    protected function resolveExpiredTask(PropertySyncStatus $sync, string $apiKey, string $clientId, array $taskResult): JsonResponse
    {
        $listing = $sync->external_id ? $this->fr->getListing($apiKey, $sync->external_id, $clientId) : null;
        $response = $sync->last_response ?? [];
        $action = $response['action'] ?? 'publish';
        $response['expired_task'] = $taskResult;
        $response['listing_verification'] = $listing['data'] ?? null;
        $remote = $this->listingRow($listing['data'] ?? []);
        $remoteStatus = $this->listingStatus($remote);
        $active = ($listing['ok'] ?? false) && $this->isActiveListingStatus($remoteStatus);
        $confirmedInactive = ($listing['ok'] ?? false) && in_array(strtoupper((string) $remoteStatus), ['1', 'DISABLED', 'INACTIVE'], true);
        $paused = $action === 'pause' && ($confirmedInactive || (int) ($listing['status'] ?? 0) === 404);
        // Presence proves a creation/activation, but never proves an expired update was applied.
        $published = $active && in_array($action, ['publish', 'activate', 'activate_required'], true);
        $state = $paused ? 'paused' : ($published ? 'synced' : 'error');
        $error = $state !== 'error' ? null : (! $sync->external_id
            ? 'remote_identity_unknown: tarea vencida sin listing_id. Conciliar antes de publicar de nuevo.'
            : 'task_expired: la tarea venció; se debe recuperar la operación sobre el anuncio existente.');
        $sync->update([
            'sync_status' => $state,
            'last_response' => $response,
            'last_error' => $error,
            'last_attempt_at' => now(),
            'attempts' => $sync->attempts + 1,
            'external_url' => $paused ? null : ($published ? $this->listingPublicUrl($this->listingPropertyId($remote)) : $sync->external_url),
            'last_synced_at' => $state === 'error' ? $sync->last_synced_at : now(),
        ]);

        return response()->json(['Datos' => ['ok' => $state !== 'error', 'sync_status' => $state,
            'external_id' => $sync->external_id, 'message' => $error, 'action' => $action]], $state === 'error' ? 409 : 200);
    }

    public function subscribeWebhook(Request $request): JsonResponse
    {
        $settings = $this->settings($request);
        $webhookId = trim((string) config('portals.fincaraiz.webhook_id'));
        $target = trim((string) config('portals.fincaraiz.webhook_url'));
        abort_unless($webhookId && $target, 400, 'Configura FINCARAIZ_WEBHOOK_ID y FINCARAIZ_WEBHOOK_URL.');
        $result = $this->fr->subscribeWebhook(
            $webhookId,
            $target,
            $this->apiKey($settings),
            $settings['client_id'] ?? null
        );

        return response()->json(['Datos' => $result]);
    }

    public function webhook(Request $request): JsonResponse
    {
        $expectedId = trim((string) config('portals.fincaraiz.webhook_id'));
        $expectedToken = trim((string) config('portals.fincaraiz.webhook_verify_token'));
        if ($expectedId === '' || $expectedToken === '') {
            return response()->json(['Datos' => ['ok' => false, 'error' => 'Webhook no configurado.']], 503);
        }

        $hubId = $this->normalizedHeader($request, 'hub-id');
        $verifyToken = $this->normalizedHeader($request, 'verify-token');
        if (! hash_equals($expectedId, $hubId) || ! hash_equals($expectedToken, $verifyToken)) {
            return response()->json(['Datos' => ['ok' => false, 'error' => 'Webhook no autorizado.']], 401);
        }

        $payload = $request->json()->all();
        $contents = data_get($payload, 'task.content', []);
        $processed = 0;
        foreach (is_array($contents) ? $contents : [] as $content) {
            $code = trim((string) ($content['external_code'] ?? ''));
            $property = $code !== '' ? Property::where('code', $code)->first() : null;
            if (! $property) {
                continue;
            }
            $this->applyTaskResult($property, array_replace_recursive($payload, [
                'task' => ['content' => [$content]],
            ]), $this->syncStatus($property), true);
            $processed++;
        }

        return response()->json(['Datos' => ['ok' => true, 'processed' => $processed]]);
    }

    protected function changeStatus(Request $request, string $code, string $status, string $action): JsonResponse
    {
        $property = Property::where('code', $code)->firstOrFail();
        $sync = $this->syncStatus($property);
        abort_unless($sync?->external_id, 400, 'No hay listing_id confirmado en Fincaraíz.');
        $settings = $this->settings($request);
        $result = $this->fr->changeStatus(
            $sync->external_id,
            $status,
            $this->clientId($settings),
            $this->apiKey($settings)
        );
        $this->storeQueuedResult($property, $result, $action);

        return response()->json(['Datos' => $result + ['sync_status' => $result['ok'] ? 'pending' : 'error']]);
    }

    protected function storeQueuedResult(Property $property, array $result, string $action): PropertySyncStatus
    {
        $taskId = $this->taskId($result['data'] ?? []);
        $ok = $result['ok'] && $taskId !== null;
        $sync = $this->syncStatus($property);

        return $property->syncStatuses()->updateOrCreate(
            [
                'integration_id' => $this->integration()->id,
                'environment' => config('portals.fincaraiz.environment'),
                'portal_variant' => 'default',
            ],
            [
                'sync_status' => $ok ? 'pending' : 'error',
                'external_id' => $sync?->external_id,
                'last_response' => [
                    'action' => $action,
                    'task_id' => $taskId,
                    'portal' => $result['data'] ?? null,
                ],
                'last_error' => $ok ? null : ($taskId === null && $result['ok']
                    ? 'Fincaraíz no devolvió task.id.'
                    : $this->errorMessage($result)),
                'last_attempt_at' => now(),
                'attempts' => ($sync?->attempts ?? 0) + 1,
            ]
        );
    }

    protected function applyTaskResult(
        Property $property,
        array $data,
        ?PropertySyncStatus $sync = null,
        bool $requireRemoteConfirmation = false
    ): PropertySyncStatus {
        $sync ??= $this->syncStatus($property);
        $action = (string) data_get($sync?->last_response, 'action', 'publish');
        $task = $data['task'] ?? [];
        $taskStatus = strtoupper((string) ($task['status'] ?? ''));
        $content = collect($task['content'] ?? [])->first(
            fn (array $item) => (string) ($item['external_code'] ?? '') === (string) $property->code
        ) ?? collect($task['content'] ?? [])->first() ?? [];
        $contentStatus = strtoupper((string) ($content['status'] ?? ''));
        $terminalStatus = $contentStatus ?: $taskStatus;
        $listingId = $content['listing_id'] ?? $sync?->external_id;
        $taskId = $task['id'] ?? $this->storedTaskId($sync);
        $activateAfterUpdate = (bool) data_get($sync?->last_response, 'activate_after_update', false);
        $success = in_array($terminalStatus, ['COMPLETED'], true)
            || ($terminalStatus === 'FORWARDED' && ! empty($listingId));
        $requiresActivation = $success && (
            in_array($action, ['publish', 'activate_required'], true)
            || ($action === 'update' && $activateAfterUpdate)
        );

        $syncStatus = match (true) {
            in_array($taskStatus, ['ERROR'], true) || in_array($contentStatus, ['ERROR'], true) => 'error',
            ! $success => 'pending',
            $action === 'pause' => 'paused',
            $requiresActivation => 'pending',
            $requireRemoteConfirmation => 'pending',
            default => 'synced',
        };
        $lastError = $syncStatus === 'error'
            ? $this->taskErrorMessage($data)
            : null;
        $frPropertyId = $content['fr_property_id']
            ?? $content['frPropertyId']
            ?? data_get($sync?->last_response, 'fr_property_id');

        return $property->syncStatuses()->updateOrCreate(
            [
                'integration_id' => $this->integration()->id,
                'environment' => config('portals.fincaraiz.environment'),
                'portal_variant' => 'default',
            ],
            [
                'sync_status' => $syncStatus,
                'external_id' => $listingId,
                'external_url' => $syncStatus === 'synced'
                    ? $this->listingPublicUrl($frPropertyId)
                    : ($syncStatus === 'paused' ? null : $sync?->external_url),
                'last_response' => [
                    'action' => $requiresActivation ? 'activate_required' : $action,
                    'task_id' => $taskId,
                    'requires_activation' => $requiresActivation,
                    'fr_property_id' => $frPropertyId,
                    'portal' => $data,
                ],
                'last_error' => $lastError,
                'last_synced_at' => $syncStatus === 'synced' || $syncStatus === 'paused' ? now() : $sync?->last_synced_at,
                'last_attempt_at' => now(),
                'attempts' => ($sync?->attempts ?? 0) + 1,
            ]
        );
    }

    protected function applyListingVerification(PropertySyncStatus $sync, array $result): PropertySyncStatus
    {
        $lastResponse = $sync->last_response ?? [];
        $lastResponse['listing_verification'] = $result['data'] ?? null;

        if (! $result['ok']) {
            $status = (int) ($result['status'] ?? 0);
            $lastResponse['requires_activation'] = false;

            $sync->update([
                'sync_status' => 'pending',
                'last_response' => $lastResponse,
                'last_error' => $status === 404
                    ? 'Fincaraíz todavía no encuentra el aviso después de procesar la tarea.'
                    : 'No fue posible confirmar el estado final del aviso en Fincaraíz.',
                'last_attempt_at' => now(),
            ]);

            return $sync->fresh();
        }

        $listing = $this->listingRow($result['data'] ?? []);
        $status = $this->listingStatus($listing);
        $frPropertyId = $this->listingPropertyId($listing)
            ?: data_get($lastResponse, 'fr_property_id');
        $lastResponse['fr_property_id'] = $frPropertyId;
        $lastResponse['remote_status'] = $status;

        if ($this->isActiveListingStatus($status)) {
            $lastResponse['requires_activation'] = false;
            $sync->update([
                'sync_status' => 'synced',
                'external_url' => $this->listingPublicUrl($frPropertyId),
                'last_response' => $lastResponse,
                'last_error' => null,
                'last_synced_at' => now(),
                'last_attempt_at' => now(),
            ]);

            return $sync->fresh();
        }

        $action = (string) data_get($lastResponse, 'action', 'update');
        $requiresActivation = ! in_array($action, ['activate', 'activate_required'], true);
        $lastResponse['action'] = $requiresActivation ? 'activate_required' : $action;
        $lastResponse['requires_activation'] = $requiresActivation;

        $sync->update([
            'sync_status' => 'pending',
            'external_url' => null,
            'last_response' => $lastResponse,
            'last_error' => 'El aviso existe en Fincaraíz, pero todavía no está activo.',
            'last_attempt_at' => now(),
        ]);

        return $sync->fresh();
    }

    protected function listingRow(array $data): array
    {
        $row = data_get($data, 'listing')
            ?? data_get($data, 'result')
            ?? data_get($data, 'results.0')
            ?? $data;

        if (is_array($row) && array_is_list($row)) {
            $row = $row[0] ?? [];
        }

        return is_array($row) ? $row : [];
    }

    protected function listingStatus(array $listing): mixed
    {
        return $listing['status'] ?? $listing['listing_status'] ?? $listing['listingStatus'] ?? null;
    }

    protected function listingPropertyId(array $listing): string
    {
        return trim((string) ($listing['fr_property_id'] ?? $listing['frPropertyId'] ?? ''));
    }

    protected function isActiveListingStatus(mixed $status): bool
    {
        if (is_numeric($status)) {
            return (int) $status === 4;
        }

        return in_array(strtoupper(trim((string) $status)), ['ACTIVE', 'ACTIVO', 'PUBLISHED', 'PUBLICADO'], true);
    }

    protected function listingPublicUrl(mixed $frPropertyId): ?string
    {
        $id = trim((string) $frPropertyId);
        if ($id === '') {
            return null;
        }

        return rtrim((string) config('portals.fincaraiz.page_url', 'https://www.fincaraiz.com.co'), '/')
            .'/detalle/'.rawurlencode($id);
    }

    protected function withRecoveryAction(JsonResponse $response, string $action): JsonResponse
    {
        $payload = $response->getData(true);
        $payload['Datos']['recovery_action'] = $action;
        $response->setData($payload);

        return $response;
    }

    protected function responseAccepted(JsonResponse $response): bool
    {
        $data = $response->getData(true)['Datos'] ?? [];

        return $response->getStatusCode() < 400 && ($data['ok'] ?? true) !== false;
    }

    protected function responseStatus(array $result): int
    {
        $status = (int) ($result['status'] ?? 0);

        return $status >= 400 && $status <= 599 ? $status : 502;
    }

    protected function settings(Request $request): array
    {
        $credential = $this->userCredential($request);
        $data = $credential?->data ?? [];
        if ($credential?->access_token) {
            $data['api_key'] = $credential->access_token;
        }

        return array_merge([
            'api_key' => config('portals.fincaraiz.api_key'),
            'client_id' => config('portals.fincaraiz.client_id'),
            'client_agent' => config('portals.fincaraiz.client_agent'),
            'contact_email' => config('portals.fincaraiz.contact_email'),
            'contact_phone' => config('portals.fincaraiz.contact_phone'),
            'contact_whatsapp' => config('portals.fincaraiz.contact_whatsapp'),
            'show_exact_address' => config('portals.fincaraiz.show_exact_address', false),
            'dual_offer' => config('portals.fincaraiz.dual_offer', 'rent'),
            'auto_sync' => config('portals.fincaraiz.auto_sync', false),
            'auto_sync_limit' => config('portals.fincaraiz.auto_sync_limit', 20),
        ], array_filter($data, fn ($value) => $value !== null && $value !== ''), [
            'dual_offer' => 'rent',
        ]);
    }

    protected function userCredential(Request $request): ?PortalCredential
    {
        return $request->user() ? PortalCredential::where([
            'user_id' => $request->user()->id,
            'integration_id' => $this->integration()->id,
        ])->first() : null;
    }

    protected function apiKey(array $settings): string
    {
        $key = trim((string) ($settings['api_key'] ?? ''));
        abort_unless($key, 400, 'No se ha configurado FINCARAIZ_API_KEY.');

        return $key;
    }

    protected function clientId(array $settings): string
    {
        $clientId = trim((string) ($settings['client_id'] ?? ''));
        abort_unless($clientId, 400, 'No se ha configurado FINCARAIZ_CLIENT_ID.');

        return $clientId;
    }

    protected function integration(): Integration
    {
        return Integration::where('slug', 'fincaraiz')->firstOrFail();
    }

    protected function syncStatus(Property $property): ?PropertySyncStatus
    {
        return $property->syncStatuses()
            ->where('integration_id', $this->integration()->id)
            ->where('environment', config('portals.fincaraiz.environment'))
            ->where('portal_variant', 'default')
            ->first();
    }

    protected function taskId(array $data): ?string
    {
        $id = data_get($data, 'task.id');

        return is_string($id) && trim($id) !== '' ? trim($id) : null;
    }

    protected function storedTaskId(?PropertySyncStatus $sync): ?string
    {
        $id = data_get($sync?->last_response, 'task_id')
            ?: data_get($sync?->last_response, 'portal.task.id');

        return is_string($id) && trim($id) !== '' ? trim($id) : null;
    }

    protected function storedResponse(PropertySyncStatus $sync, array $portal, string $taskId): array
    {
        return [
            'action' => data_get($sync->last_response, 'action'),
            'task_id' => $taskId,
            'portal' => $portal,
        ];
    }

    protected function operationResult(array $result, array $mapped): array
    {
        return $result + [
            'sync_status' => $result['ok'] && $this->taskId($result['data'] ?? []) ? 'pending' : 'error',
            'warnings' => $mapped['warnings'],
            'source' => $mapped['source'],
        ];
    }

    protected function mappingError(array $mapped): array
    {
        return [
            'ok' => false,
            'status' => 422,
            'errors' => $mapped['errors'],
            'warnings' => $mapped['warnings'],
            'payload' => $mapped['payload'],
            'source' => $mapped['source'],
        ];
    }

    protected function errorMessage(array $result): string
    {
        $data = $result['data'] ?? [];

        return (string) ($data['detail'] ?? $data['error'] ?? data_get($data, 'body.detail')
            ?? data_get($data, 'body.error') ?? 'Fincaraíz rechazó la solicitud.');
    }

    protected function taskErrorMessage(array $data): string
    {
        $messages = data_get($data, 'task.messages');
        if (is_array($messages) && $messages !== []) {
            return json_encode($messages, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return 'La tarea de Fincaraíz terminó con error.';
    }

    protected function normalizedHeader(Request $request, string $expected): string
    {
        foreach ($request->headers->all() as $name => $values) {
            $normalized = str_replace(['.', '_'], '-', strtolower($name));
            if ($normalized === $expected) {
                return trim((string) ($values[0] ?? ''));
            }
        }

        return '';
    }
}
