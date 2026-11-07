<?php

namespace App\Services\VendorOps;

use App\Models\VendorOps\VendorCharger;
use App\Models\VendorOps\VendorCompany;
use App\Models\VendorOps\VendorConnector;
use App\Models\VendorOps\VendorKycDocument;
use App\Models\VendorOps\VendorSite;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/** Optional stable creation receipts; canonical models and current scope remain authoritative. */
class VendorCreation
{
    public function perform(Request $r, string $scope, callable $create)
    {
        $v = $r->validate(['idempotency_key' => 'sometimes|required|uuid']);
        if (! isset($v['idempotency_key'])) {
            return $create();
        }
        $kind = explode(':', $scope)[0];
        $fields = match ($kind) {
            'company' => ['legal_name', 'country', 'trade_license_number', 'license_expires_on'],
            'site' => ['name', 'country', 'timezone', 'address', 'latitude', 'longitude', 'station_id'],
            'charger' => ['asset_reference', 'manufacturer', 'model', 'serial_number', 'ocpp_protocol', 'source'],
            'connector' => ['connector_number', 'connector_type', 'max_power_kw'],
            'document' => ['kind'],
        };
        $body = $r->only($fields);
        ksort($body);
        if ($kind === 'document') {
            $file = $r->file('document');
            $body['sha256'] = $file instanceof UploadedFile && $file->isValid() ? hash_file('sha256', $file->getRealPath()) : null;
        }
        $keys = array_values(array_filter(array_merge([config('app.key')], config('app.previous_keys', [])), fn ($key) => is_string($key) && strlen($key) >= 32));
        abort_unless(count($keys) > 0, 503, 'Configure application encryption keys before creation.');
        $payload = json_encode([$scope, $body], JSON_THROW_ON_ERROR);
        $hashes = array_map(fn ($key) => hash_hmac('sha256', $payload, $key), $keys);
        $hash = $hashes[0];
        $key = hash('sha256', $r->user()->id.':'.strtolower($v['idempotency_key']));

        return DB::transaction(function () use ($r, $scope, $kind, $hash, $hashes, $key, $create) {
            // A competing insert waits for commit/rollback; no additional User/company
            // lock order is introduced around existing inventory transactions.
            DB::table('vendor_creation_receipts')->insertOrIgnore(['user_id' => $r->user()->id, 'request_key' => $key, 'scope' => $scope, 'fingerprint' => $hash, 'created_at' => now()]);
            $old = DB::table('vendor_creation_receipts')->where('request_key', $key)->lockForUpdate()->firstOrFail();
            abort_unless($old->user_id === $r->user()->id && $old->scope === $scope && collect($hashes)->contains(fn ($candidate) => hash_equals($old->fingerprint, $candidate)), 409, 'Creation key payload changed; inspect the existing resource.');
            if ($old->entity_id !== null) {
                $class = match ($kind) {
                    'company' => VendorCompany::class,'site' => VendorSite::class,'charger' => VendorCharger::class,'connector' => VendorConnector::class,'document' => VendorKycDocument::class
                };
                $model = $class::find($old->entity_id);
                abort_unless($model, 409, 'The recorded resource requires review; no replacement was created.');
                $extra = in_array($kind, ['charger', 'connector'], true) ? ['availability' => 'unknown', 'remote_control_enabled' => false] : [];

                return response()->json($model->toArray() + $extra, 201)->header('Idempotency-Replayed', 'true');
            }
            $result = $create();
            $id = $result->getData(true)['id'];
            DB::table('vendor_creation_receipts')->where('id', $old->id)->update(['entity_id' => $id]);

            return $result;
        }, 3);
    }
}
