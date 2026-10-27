<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Integration;
use App\Models\PortalCredential;
use App\Models\Property;
use App\Models\PropertySyncStatus;
use App\Services\Portals\CiencuadrasActiveProperties;
use App\Services\Portals\CiencuadrasClient;
use App\Services\Portals\CiencuadrasPropertyMapper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CiencuadrasController extends Controller
{
    public function __construct(
        protected CiencuadrasClient $cc,
        protected CiencuadrasPropertyMapper $mapper,
        protected CiencuadrasActiveProperties $activeProperties,
    ) {}

    public function login(Request $request): JsonResponse
    {
        $credential = $this->credential($request, forceRefresh: true);

        return response()->json(['Datos' => [
            'ok' => true,
            'environment' => config('portals.ciencuadras.environment'),
            'api_url' => config('portals.ciencuadras.api_url'),
            'expires_at' => $credential->access_token_expires_at?->toIso8601String(),
        ]]);
    }

    public function publish(Request $request, string $code): JsonResponse
    {
        return $this->send($request, $code, action: 'publish', status: 'A');
    }

    public function update(Request $request, string $code): JsonResponse
    {
        return $this->send($request, $code, action: 'update', status: 'A');
    }

    public function pause(Request $request, string $code): JsonResponse
    {
        return $this->send($request, $code, action: 'pause', status: 'I');
    }

    public function delete(Request $request, string $code): JsonResponse
    {
        return $this->send($request, $code, action: 'delete', status: 'D');
    }

    public function consult(Request $request, string $code): JsonResponse
    {
        $cred = $this->credential($request);
        $integration = $this->integration();
        $environment = config('portals.ciencuadras.environment');
        $externalCode = $this->mapper->lookupCode($code);
        $property = Property::where('code', $code)->first();
        $status = $property ? PropertySyncStatus::where([
            'property_id' => $property->id,
            'integration_id' => $integration->id,
            'environment' => $environment,
        ])->first() : null;

        $lastResponse = $status?->last_response ?? [];
        $idRequest = $this->cc->extractIdRequest($lastResponse);
        $targetStatus = $this->responseValue($lastResponse, 'target_status');
        $targetAction = $this->responseValue($lastResponse, 'target_action');
        $statusResult = $idRequest
            ? $this->cc->consultStatus(['idRequest' => $idRequest], $cred)
            : null;
        $portalState = $this->activeProperties->inspectSourceCodes([$code], $cred)->get($code);
        $consult = $this->activeInventoryResult($portalState, $targetStatus)
            ?? $this->consultPropertyWithFallback(
                $this->consultCodeForStatus($status, $externalCode),
                $cred,
                $targetStatus
            );
        $consultCode = $consult['code'];
        $propertyResult = $consult['result'];

        $response = $this->verificationResponse($idRequest, $targetAction, $targetStatus, $statusResult['data'] ?? null, $propertyResult['data'] ?? null);
        $syncStatus = $this->verifiedSyncState($statusResult, $propertyResult, $status?->sync_status, $targetStatus);
        $webUrl = $this->propertyWebUrl($code);

        if ($property) {
            $this->saveStatus(
                $property->id,
                $syncStatus,
                $consultCode,
                $response,
                $syncStatus === 'error' ? $this->errorMessage($response) : null,
                fallbackUrl: $status?->external_url,
                incrementAttempt: false
            );
        }

        return response()->json(['Datos' => [
            'ok' => $propertyResult['ok'] || ($statusResult['ok'] ?? false),
            'environment' => $environment,
            'external_code' => $consultCode,
            'id_request' => $idRequest,
            'action' => $targetAction,
            'target_status' => $targetStatus,
            'sync_status' => $syncStatus,
            'public_url' => $this->publicUrlForStatus($syncStatus, $targetStatus, $response),
            'web_url' => $webUrl,
            'response' => $response,
        ]]);
    }

    public function payload(string $code): JsonResponse
    {
        $mapped = $this->mapper->fromCode($code);

        return response()->json(['Datos' => [
            'environment' => config('portals.ciencuadras.environment'),
            'source' => $mapped['source'],
            'errors' => $mapped['errors'],
            'payload' => $mapped['payload'],
        ]]);
    }

    public function bulk(Request $request): JsonResponse
    {
        $data = $request->validate([
            'action' => ['required', 'in:publish,update,pause'],
            'codes' => ['required', 'array', 'min:1', 'max:20'],
            'codes.*' => ['required', 'string', 'distinct', 'max:32'],
        ]);

        $action = $data['action'];
        $targetStatus = $action === 'pause' ? 'I' : 'A';
        $credential = $this->credential($request);
        $inspectedCodes = in_array($action, ['publish', 'update', 'pause'], true)
            ? $this->activeProperties->inspectSourceCodes($data['codes'], $credential)
            : collect();

        $payloads = [];
        $properties = [];
        $rejected = [];
        $skipped = [];

        foreach ($data['codes'] as $value) {
            $code = trim((string) $value);

            if ($action === 'publish') {
                $portalState = $inspectedCodes->get($code);
                if (($portalState['state'] ?? 'unavailable') === 'unavailable') {
                    $rejected[] = [
                        'code' => $code,
                        'message' => 'No fue posible verificar el inventario de Ciencuadras.',
                    ];

                    continue;
                }

                if (($portalState['state'] ?? null) === 'active') {
                    $skipped[] = [
                        'code' => $code,
                        'message' => 'El inmueble ya está activo en Ciencuadras; usa Actualizar.',
                    ];

                    continue;
                }

                $localProperty = Property::where('code', $code)->first();
                $localStatus = $localProperty ? PropertySyncStatus::where([
                    'property_id' => $localProperty->id,
                    'integration_id' => $this->integration()->id,
                    'environment' => config('portals.ciencuadras.environment'),
                ])->first() : null;
                $localResponse = $localStatus?->last_response ?? [];

                if ($localStatus
                    && in_array($localStatus->sync_status, ['pending', 'syncing', 'synced'], true)
                    && $this->responseValue($localResponse, 'target_action') === 'publish'
                    && $this->cc->extractIdRequest($localResponse)) {
                    $skipped[] = [
                        'code' => $code,
                        'message' => 'La publicación ya fue enviada; se conserva la solicitud original.',
                    ];

                    continue;
                }
            }

            $inspection = $inspectedCodes->get($code);
            if (in_array($action, ['update', 'pause'], true)
                && ($inspection['state'] ?? 'unavailable') !== 'active') {
                $notice = [
                    'code' => $code,
                    'message' => ($inspection['state'] ?? null) === 'unavailable'
                        ? 'No fue posible verificar este inmueble en Ciencuadras.'
                        : 'Ciencuadras no lo reporta activo; no se modificó.',
                ];
                if (($inspection['state'] ?? null) === 'unavailable') {
                    $rejected[] = $notice;
                } else {
                    $skipped[] = $notice;
                }

                continue;
            }

            try {
                $mapped = $this->mapper->fromCode($code, $targetStatus);
                if ($action !== 'publish') {
                    $mapped['payload']['propertyCode'] = $this->payloadCodeForExistingListing(
                        $mapped['property']->id,
                        (string) $mapped['payload']['propertyCode'],
                        $targetStatus,
                        $credential
                    );
                }

                if ($mapped['errors']) {
                    $rejected[] = [
                        'code' => $code,
                        'message' => implode(' ', $mapped['errors']),
                    ];

                    continue;
                }

                $payloads[] = $mapped['payload'];
                $properties[] = [
                    'code' => $code,
                    'property' => $mapped['property'],
                    'external_code' => $this->consultCodeFromPayload(
                        (string) $mapped['payload']['propertyCode']
                    ),
                ];
            } catch (\Throwable $exception) {
                $rejected[] = [
                    'code' => $code,
                    'message' => $exception->getMessage(),
                ];
            }
        }

        if ($payloads === []) {
            return response()->json(['Datos' => [
                'ok' => false,
                'accepted' => 0,
                'rejected' => $rejected,
                'skipped' => $skipped,
            ]], $rejected === [] ? 200 : 422);
        }

        $result = $action === 'publish'
            ? $this->cc->insertProperty($payloads, $credential)
            : $this->cc->updateProperty($payloads, $credential);
        $idRequest = ($result['ok'] ?? false)
            ? $this->cc->extractIdRequest($result['data'] ?? [])
            : null;
        $accepted = (bool) ($result['ok'] ?? false) && $idRequest;
        $syncStatus = $accepted ? 'pending' : 'error';
        $response = [
            'target_action' => $action,
            'target_status' => $targetStatus,
            'batch' => true,
            'codes' => collect($properties)->pluck('code')->values()->all(),
            'request' => $result['data'] ?? null,
        ];
        $items = [];
        if (! $accepted) {
            $portalMessage = $this->errorMessage($response);
            foreach ($properties as $item) {
                $rejected[] = [
                    'code' => $item['code'],
                    'message' => $portalMessage,
                ];
            }
        }

        foreach ($properties as $item) {
            $this->saveStatus(
                $item['property']->id,
                $syncStatus,
                $item['external_code'],
                $response,
                $accepted ? null : $this->errorMessage($response)
            );
            $items[] = [
                'code' => $item['code'],
                'external_code' => $item['external_code'],
                'sync_status' => $syncStatus,
                'id_request' => $idRequest,
            ];
        }

        return response()->json(['Datos' => [
            'ok' => $accepted,
            'portal' => 'ciencuadras',
            'action' => $action,
            'environment' => config('portals.ciencuadras.environment'),
            'accepted' => $accepted ? count($items) : 0,
            'rejected' => $rejected,
            'skipped' => $skipped,
            'id_request' => $idRequest,
            'items' => $items,
            'response' => $result['data'] ?? null,
        ]], $accepted ? 202 : 422);
    }

    protected function send(Request $request, string $code, string $action, string $status): JsonResponse
    {
        $cred = $this->credential($request);
        $existingProperty = Property::where('code', $code)->first();
        $existingStatus = $existingProperty ? PropertySyncStatus::where([
            'property_id' => $existingProperty->id,
            'integration_id' => $this->integration()->id,
            'environment' => config('portals.ciencuadras.environment'),
        ])->first() : null;
        $existingResponse = $existingStatus?->last_response ?? [];
        $existingIdRequest = $this->cc->extractIdRequest($existingResponse);
        $existingAction = $this->responseValue($existingResponse, 'target_action');

        if ($existingIdRequest
            && in_array($existingStatus->sync_status, ['pending', 'syncing'], true)
            && $existingAction === $action) {
            return $this->reusedPendingResponse(
                $code,
                $action,
                $status,
                $existingStatus,
                $existingResponse,
                $existingIdRequest
            );
        }

        if ($action === 'publish') {
            if ($existingIdRequest
                && $existingStatus?->sync_status === 'synced'
                && $existingAction === 'publish'
                && ! $this->responseHasError($this->responseValue($existingResponse, 'status_check'))) {
                return $this->reusedPendingResponse(
                    $code,
                    $action,
                    $status,
                    $existingStatus,
                    $existingResponse,
                    $existingIdRequest
                );
            }

            $portalState = $this->activeProperties->inspectSourceCodes([$code], $cred)->get($code);
            abort_if(
                ($portalState['state'] ?? 'unavailable') === 'unavailable',
                503,
                'No fue posible verificar el inventario de Ciencuadras.'
            );
            abort_if(
                ($portalState['state'] ?? null) === 'active',
                409,
                'Este inmueble ya está activo en Ciencuadras. Usa Actualizar; no se enviará otra publicación.'
            );
        }

        $mapped = $this->mapper->fromCode($code, $status);
        $property = $mapped['property'];
        $payloadPropertyCode = (string) $mapped['payload']['propertyCode'];
        if ($action !== 'publish') {
            $mapped['payload']['propertyCode'] = $this->payloadCodeForExistingListing(
                $property->id,
                $payloadPropertyCode,
                $status,
                $cred
            );
        }

        if ($mapped['errors']) {
            $this->saveStatus($property->id, 'error', $mapped['payload']['propertyCode'], [
                'validation_errors' => $mapped['errors'],
                'source' => $mapped['source'],
            ], implode(' ', $mapped['errors']));

            return response()->json(['Datos' => [
                'ok' => false,
                'environment' => config('portals.ciencuadras.environment'),
                'errors' => $mapped['errors'],
                'source' => $mapped['source'],
            ]], 422);
        }

        $result = $action === 'publish'
            ? $this->cc->insertProperty($mapped['payload'], $cred)
            : $this->cc->updateProperty($mapped['payload'], $cred);

        $idRequest = $result['ok'] ? $this->cc->extractIdRequest($result['data'] ?? []) : null;
        $statusResult = null;
        if ($idRequest) {
            $statusResult = $this->cc->consultStatus(['idRequest' => $idRequest], $cred);
        }
        $reportedCode = $this->extractPropertyCode($statusResult['data'] ?? null);
        $consultCode = $reportedCode
            ? $this->consultCodeFromPayload($reportedCode)
            : $this->consultCodeFromPayload((string) $mapped['payload']['propertyCode']);
        $consult = $this->consultPropertyWithFallback($consultCode, $cred, $status);
        $consultCode = $consult['code'];
        $propertyResult = $consult['result'];

        $response = [
            'target_action' => $action,
            'target_status' => $status,
            'request' => $result['data'],
            'status_check' => $statusResult['data'] ?? null,
            'property_check' => $propertyResult['data'] ?? null,
        ];
        $syncStatus = $this->syncState($result, $statusResult, $status, $propertyResult);
        $webUrl = $this->propertyWebUrl($code);
        $this->saveStatus(
            $property->id,
            $syncStatus,
            $consultCode,
            $response,
            $syncStatus === 'error' ? $this->errorMessage($response) : null
        );

        if ($syncStatus === 'synced') {
            $property->update(['status' => 'active', 'published_at' => now()]);
        }

        return response()->json(['Datos' => [
            'ok' => $result['ok'] && $syncStatus !== 'error',
            'environment' => config('portals.ciencuadras.environment'),
            'external_code' => $consultCode,
            'action' => $action,
            'target_status' => $status,
            'sync_status' => $syncStatus,
            'id_request' => $idRequest,
            'public_url' => $this->publicUrlForStatus($syncStatus, $status, $response),
            'web_url' => $webUrl,
            'response' => $response,
        ]]);
    }

    protected function reusedPendingResponse(
        string $code,
        string $action,
        string $targetStatus,
        PropertySyncStatus $status,
        array $response,
        string $idRequest
    ): JsonResponse {
        return response()->json(['Datos' => [
            'ok' => true,
            'environment' => config('portals.ciencuadras.environment'),
            'external_code' => $status->external_id,
            'action' => $action,
            'target_status' => $targetStatus,
            'sync_status' => $status->sync_status,
            'id_request' => $idRequest,
            'public_url' => $status->external_url,
            'web_url' => $this->propertyWebUrl($code),
            'response' => $response,
            'message' => 'Esta acción ya fue enviada. Se conserva la solicitud original sin crear otro intento.',
            'reused_request' => true,
        ]]);
    }

    protected function activeInventoryResult(?array $portalState, ?string $targetStatus): ?array
    {
        if (in_array($targetStatus, ['I', 'D'], true)
            || ($portalState['state'] ?? null) !== 'active'
            || empty($portalState['portal_code'])) {
            return null;
        }

        $portalCode = (string) $portalState['portal_code'];

        return [
            'code' => $portalCode,
            'result' => [
                'ok' => true,
                'data' => [
                    'message' => [[
                        'propertyCode' => $portalCode,
                        'active' => 'Activo',
                        'status' => '0',
                    ]],
                    'status' => 'success',
                    'statusCode' => 100,
                ],
            ],
        ];
    }

    protected function credential(Request $request, bool $forceRefresh = false): PortalCredential
    {
        $username = config('portals.ciencuadras.username');
        $password = config('portals.ciencuadras.password');
        abort_if(! $username || ! $password, 422, 'Configura CIENCUADRAS_USERNAME y CIENCUADRAS_PASSWORD en .env.');

        $integration = $this->integration();
        $environment = config('portals.ciencuadras.environment');
        $credential = PortalCredential::where('user_id', $request->user()->id)
            ->where('integration_id', $this->integration()->id)
            ->first();

        if (! $forceRefresh && $credential && ! $credential->isExpired() && ($credential->data['environment'] ?? null) === $environment) {
            return $credential;
        }

        $result = $this->cc->login([
            'username' => $username,
            'password' => $password,
        ]);
        $token = $result['ok'] ? $this->cc->extractToken($result['data'] ?? []) : null;
        abort_if(! $token, 422, 'No fue posible iniciar sesión en Ciencuadras. Revisa credenciales y endpoint.');

        return PortalCredential::updateOrCreate(
            ['user_id' => $request->user()->id, 'integration_id' => $integration->id],
            [
                'access_token' => $token,
                'access_token_expires_at' => now()->addMinutes(55),
                'data' => [
                    'username' => $username,
                    'environment' => $environment,
                    'api_url' => config('portals.ciencuadras.api_url'),
                ],
            ]
        );
    }

    protected function integration(): Integration
    {
        return Integration::where('slug', 'ciencuadras')->firstOrFail();
    }

    protected function saveStatus(
        int $propertyId,
        string $syncStatus,
        string $externalId,
        array $response,
        ?string $error,
        ?string $fallbackUrl = null,
        bool $incrementAttempt = true
    ): void
    {
        $status = PropertySyncStatus::firstOrNew([
            'property_id' => $propertyId,
            'integration_id' => $this->integration()->id,
            'environment' => config('portals.ciencuadras.environment'),
        ]);

        $status->fill([
            'sync_status' => $syncStatus,
            'external_id' => $externalId,
            'external_url' => $this->publicUrlForStatus($syncStatus, $response['target_status'] ?? null, $response, $fallbackUrl ?: $status->external_url),
            'last_response' => $response,
            'last_error' => $error,
            'last_attempt_at' => now(),
            'last_synced_at' => in_array($syncStatus, ['synced', 'paused'], true)
                && ($incrementAttempt || $syncStatus === 'paused' || ! empty($response['idRequest']))
                    ? now() : $status->last_synced_at,
            'attempts' => ((int) $status->attempts) + ($incrementAttempt ? 1 : 0),
        ]);
        $status->save();
    }

    protected function syncState(array $result, ?array $statusResult, string $targetStatus, ?array $propertyResult = null): string
    {
        return \App\Services\Portals\CiencuadrasOperationState::resolve($result, $statusResult, $propertyResult, $targetStatus);
    }

    protected function verifiedSyncState(?array $statusResult, array $propertyResult, ?string $currentStatus, ?string $targetStatus = null): string
    {
        return \App\Services\Portals\CiencuadrasOperationState::resolve(null, $statusResult, $propertyResult,
            $targetStatus ?? ($currentStatus === 'paused' ? 'I' : null));
    }

    protected function consultCodeForStatus(?PropertySyncStatus $status, string $default): string
    {
        $existingCode = $this->extractPropertyCode($status?->last_response ?? null) ?: $default;

        return $this->consultCodeFromPayload($existingCode);
    }

    protected function payloadCodeForExistingListing(int $propertyId, string $default, string $targetStatus = 'A', ?PortalCredential $credential = null): string
    {
        $status = PropertySyncStatus::where([
            'property_id' => $propertyId,
            'integration_id' => $this->integration()->id,
            'environment' => config('portals.ciencuadras.environment'),
        ])->first();
        $existingCode = $status?->external_id ?: $this->extractPropertyCode($status?->last_response ?? null);

        if ($credential) {
            $resolvedCode = $this->activePayloadCodeForExistingListing($existingCode ?: $default, $credential, $targetStatus)
                ?: $this->activePayloadCodeForExistingListing($default, $credential, $targetStatus);

            if ($resolvedCode) {
                return $resolvedCode;
            }
        }

        if ($existingCode) {
            return $this->payloadCodeFromConsultCode($existingCode);
        }

        return $this->mapper->portalPropertyCode($default);
    }

    protected function activePayloadCodeForExistingListing(string $code, PortalCredential $credential, string $targetStatus): ?string
    {
        $isInactiveTarget = in_array($targetStatus, ['I', 'D'], true);
        $reportedActiveCode = $this->activeProperties->reportedActiveCodeForSource(
            $code,
            $credential
        );

        if ($reportedActiveCode) {
            return $this->payloadCodeFromConsultCode($reportedActiveCode);
        }

        foreach ($this->consultCodeCandidates($code) as $candidate) {
            $result = $this->cc->consultProperty($candidate, $credential);
            $data = $result['data'] ?? null;

            if ($this->responseHasActive($data)
                || ($isInactiveTarget && $this->responseHasInactive($data))) {
                return $this->payloadCodeFromConsultCode($this->extractPropertyCode($data) ?: $candidate);
            }
        }

        return null;
    }

    protected function payloadCodeFromConsultCode(string $consultCode): string
    {
        $prefix = (string) config('portals.ciencuadras.property_code_prefix', '22130-');
        $code = trim($consultCode);

        if ($prefix !== '' && str_starts_with(strtolower($code), strtolower($prefix))) {
            return substr($code, strlen($prefix));
        }

        return $code;
    }

    protected function consultCodeFromPayload(string $payloadCode): string
    {
        $prefix = (string) config('portals.ciencuadras.property_code_prefix', '22130-');
        $code = trim($payloadCode);

        if ($prefix !== '' && str_starts_with(strtolower($code), strtolower($prefix))) {
            return $code;
        }

        if (preg_match('/^P+\d+$/i', $code) === 1) {
            return $prefix.$code;
        }

        return $this->mapper->lookupCode($code);
    }

    protected function consultPropertyWithFallback(string $code, PortalCredential $cred, ?string $targetStatus = null): array
    {
        if (in_array($targetStatus, ['I', 'D'], true)) {
            return ($this->activeProperties)->consultForWithdrawal($code, $cred);
        }

        $first = null;
        $isInactiveTarget = in_array($targetStatus, ['I', 'D'], true);

        foreach ($this->consultCodeCandidates($code) as $candidate) {
            $result = $this->cc->consultProperty($candidate, $cred);
            $first ??= ['code' => $candidate, 'result' => $result];
            $data = $result['data'] ?? null;

            if ($this->responseHasActive($data)
                || ($isInactiveTarget && ($this->responseHasInactive($data) || $this->responseHasNotFound($data)))) {
                return ['code' => $candidate, 'result' => $result];
            }

            if (! $this->responseHasInactive($data)
                && ! $this->responseHasNotFound($data)
                && ($result['ok'] ?? false)) {
                return ['code' => $candidate, 'result' => $result];
            }
        }

        return $first ?? ['code' => $this->mapper->lookupCode($code), 'result' => ['ok' => false, 'data' => null]];
    }

    protected function consultCodeCandidates(string $code): array
    {
        return array_values(array_unique([
            ...(str_contains($code, '-P') ? [$code] : []),
            $this->mapper->lookupCode($code),
            $this->mapper->legacyLookupCode($code),
        ]));
    }

    protected function extractPropertyCode($data): ?string
    {
        if (! is_array($data)) {
            return null;
        }

        foreach ($data as $key => $value) {
            if (strtolower((string) $key) === 'propertycode' && is_scalar($value)) {
                return (string) $value;
            }

            if (is_array($value) && $found = $this->extractPropertyCode($value)) {
                return $found;
            }
        }

        return null;
    }

    protected function responseIsPending($data): bool
    {
        return str_contains(strtolower(json_encode($data ?? [])), 'pending');
    }

    protected function responseHasSuccess($data): bool
    {
        $json = strtolower(json_encode($data ?? []));

        return str_contains($json, '"status":"success"')
            || str_contains($json, 'statuscode":100')
            || str_contains($json, 'procesado')
            || str_contains($json, 'éxito')
            || str_contains($json, 'exito');
    }

    protected function responseHasActive($data): bool
    {
        $json = strtolower(json_encode($data ?? []));

        return ! $this->responseHasInactive($data)
            && (str_contains($json, '"active":"activo"')
                || str_contains($json, '"status":"0"'));
    }

    protected function responseHasError($data): bool
    {
        $json = strtolower(json_encode($data ?? []));

        return str_contains($json, '"status":"error"')
            || str_contains($json, 'error')
            || str_contains($json, 'fall');
    }

    protected function responseHasNotFound($data): bool
    {
        $json = strtolower(json_encode($data ?? []));

        return str_contains($json, 'no existe')
            || str_contains($json, 'not found')
            || str_contains($json, 'no tiene inmuebles');
    }

    protected function responseHasInactive($data): bool
    {
        $json = strtolower(json_encode($data ?? []));

        return str_contains($json, 'eliminado')
            || str_contains($json, 'inactivo')
            || str_contains($json, '"active":"eliminado"')
            || str_contains($json, '"status":"8"');
    }

    protected function extractPublicUrl(array $response): ?string
    {
        foreach ($response as $value) {
            if (is_scalar($value)) {
                $text = str_replace('\\/', '/', (string) $value);
                if (preg_match('/https?:\/\/(?:pre\.)?ciencuadras\.com\/inmueble\/[^\s"]*/i', $text, $matches)) {
                    return rtrim($matches[0], '\\');
                }
            }

            if (is_array($value) && $found = $this->extractPublicUrl($value)) {
                return $found;
            }
        }

        return null;
    }

    protected function publicUrlForStatus(string $syncStatus, ?string $targetStatus, array $response, ?string $fallbackUrl = null): ?string
    {
        if (in_array($syncStatus, ['paused', 'not_synced'], true) || in_array($targetStatus, ['I', 'D'], true)) {
            return null;
        }

        return $this->extractPublicUrl($response) ?: $fallbackUrl;
    }

    protected function propertyWebUrl(string $code): string
    {
        return 'https://sucasainmobiliaria.com.co/inmuebles/inmueble-'.rawurlencode($code);
    }

    protected function verificationResponse(?string $idRequest, mixed $targetAction, mixed $targetStatus, mixed $statusData, mixed $propertyData): array
    {
        return [
            'idRequest' => $idRequest,
            'target_action' => is_scalar($targetAction) ? (string) $targetAction : null,
            'target_status' => is_scalar($targetStatus) ? (string) $targetStatus : null,
            'status_check' => $statusData,
            'property_check' => $propertyData,
            'checked_at' => now()->toISOString(),
        ];
    }

    protected function responseValue(array $response, string $key): mixed
    {
        if (array_key_exists($key, $response)) {
            return $response[$key];
        }

        $previous = $response['previous'] ?? null;

        return is_array($previous) ? $this->responseValue($previous, $key) : null;
    }

    protected function errorMessage(array $response): string
    {
        return substr(json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE), 0, 2000);
    }
}
