<?php

namespace App\Http\Controllers;

use App\Models\Vehicle;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class VehicleModelCatalogController extends Controller
{
    private const CONNECTORS = ['CCS2', 'Type2', 'CHAdeMO'];

    private function privateReply($data, int $status = 200)
    {
        return response()->json($data, $status)->header('Cache-Control', 'private, no-store')
            ->header('Referrer-Policy', 'no-referrer');
    }

    private function admin(Request $request): void
    {
        abort_unless($request->user()->role === 'admin', 403);
    }

    private function record(object $row): array
    {
        return [
            'id' => (int) $row->id,
            'make' => $row->make,
            'model' => $row->model,
            'variant' => $row->variant,
            'model_year' => $row->model_year === null ? null : (int) $row->model_year,
            'connector_type' => $row->connector_type,
            'source_url' => $row->source_url,
            'published' => (bool) $row->published,
            'revision' => (int) $row->revision,
            'provenance' => 'ADMIN_CURATED_UNVERIFIED',
        ];
    }

    private function input(Request $request, bool $updating = false): array
    {
        $validated = $request->validate([
            'make' => 'required|string|min:2|max:80',
            'model' => 'required|string|min:1|max:100',
            'variant' => 'present|nullable|string|max:100',
            'model_year' => 'present|nullable|integer|between:1990,2100',
            'connector_type' => 'required|in:CCS2,Type2,CHAdeMO',
            'source_url' => 'required|url|starts_with:https://|max:500',
            'published' => 'required|boolean',
            'revision' => $updating ? 'required|integer|min:1|max:2147483646'
                : 'prohibited',
        ]);
        foreach (['make', 'model', 'variant', 'source_url'] as $field) {
            $validated[$field] = isset($validated[$field]) ? trim($validated[$field]) : null;
        }
        if ($validated['make'] === '' || $validated['model'] === '' ||
            $validated['source_url'] === '' ||
            ($validated['variant'] !== null && $validated['variant'] === '')) {
            throw ValidationException::withMessages(['make' => 'Provide nonempty catalog fields.']);
        }
        // Model identity is not inferred from partial matches, and duplicates
        // cannot be created by capitalization or whitespace differences.
        $parts = [$validated['make'], $validated['model'], $validated['variant'] ?? '',
            (string) ($validated['model_year'] ?? '')];
        $validated['identity_hash'] = hash('sha256', json_encode(
            array_map(fn ($v) => mb_strtolower(preg_replace('/\s+/u', ' ', $v)), $parts),
            JSON_THROW_ON_ERROR));

        return $validated;
    }

    public function index(Request $request)
    {
        $v = $request->validate([
            'q' => 'sometimes|string|max:100',
            'page' => 'sometimes|integer|min:1|max:100000',
            'include_archived' => 'sometimes|boolean',
            'connector_type' => 'sometimes|in:CCS2,Type2,CHAdeMO',
        ]);
        $isAdmin = $request->user()->role === 'admin';
        abort_if(($v['include_archived'] ?? false) && ! $isAdmin, 403);
        $q = DB::table('vehicle_model_catalog');
        if (! $isAdmin || ! ($v['include_archived'] ?? false)) {
            $q->where('published', true);
        }
        if (isset($v['connector_type'])) {
            $q->where('connector_type', $v['connector_type']);
        }
        if (! empty($v['q'])) {
            $term = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], trim($v['q']));
            $q->where(function ($query) use ($term) {
                $query->where('make', 'like', '%'.$term.'%')
                    ->orWhere('model', 'like', '%'.$term.'%')
                    ->orWhere('variant', 'like', '%'.$term.'%');
            });
        }

        return $this->privateReply($q->orderBy('make')->orderBy('model')
            ->orderBy('id')->paginate(25)->through(fn ($row) => $this->record($row)));
    }

    public function create(Request $request)
    {
        $this->admin($request);
        $v = $this->input($request);

        return DB::transaction(function () use ($request, $v) {
            $prior = DB::table('vehicle_model_catalog')->where('identity_hash', $v['identity_hash'])->first();
            abort_if($prior !== null, 409, 'Vehicle model already exists.');
            $inserted = DB::table('vehicle_model_catalog')->insertOrIgnore(
                $v + ['revision' => 1, 'curator_id' => $request->user()->id,
                    'created_at' => now(), 'updated_at' => now()]
            );
            abort_unless($inserted === 1, 409, 'Vehicle model already exists.');
            $id = (int) DB::table('vehicle_model_catalog')
                ->where('identity_hash', $v['identity_hash'])->value('id');
            $this->audit($request->user()->id, 'vehicle_model.created', $id);

            return $this->privateReply(
                $this->record(DB::table('vehicle_model_catalog')->where('id', $id)->firstOrFail()), 201);
        }, 3);
    }

    public function update(Request $request, int $model)
    {
        $this->admin($request);
        $v = $this->input($request, true);

        return DB::transaction(function () use ($request, $v, $model) {
            $row = DB::table('vehicle_model_catalog')->where('id', $model)->lockForUpdate()->first();
            abort_unless($row, 404);
            abort_unless((int) $row->revision === (int) $v['revision'], 409,
                'Catalog record changed; refresh before saving.');
            abort_if(DB::table('vehicle_model_catalog')->where('identity_hash', $v['identity_hash'])
                ->where('id', '!=', $model)->exists(), 409, 'Vehicle model already exists.');
            // Once a customer links this row, its vehicle identity and connector
            // cannot silently become a different car. Editors may update source
            // references or archive the record, but must create a replacement
            // catalog entry for changed specs.
            $hasLinkedVehicles = DB::table('vehicles')->where('vehicle_model_id', $model)->exists();
            if ($hasLinkedVehicles) {
                $immutable = ['make', 'model', 'variant', 'model_year', 'connector_type'];
                foreach ($immutable as $key) {
                    $old = $key === 'model_year'
                        ? ($row->$key === null ? null : (int) $row->$key)
                        : $row->$key;
                    abort_if($old !== $v[$key], 409,
                        'Linked model identity is immutable. Archive and create a new catalog entry.');
                }
            }
            unset($v['revision']);
            DB::table('vehicle_model_catalog')->where('id', $model)->update($v + [
                'revision' => $row->revision + 1, 'curator_id' => $request->user()->id,
                'updated_at' => now(),
            ]);
            $this->audit($request->user()->id, 'vehicle_model.updated', $model);

            return $this->privateReply(
                $this->record(DB::table('vehicle_model_catalog')->where('id', $model)->firstOrFail()));
        }, 3);
    }

    public function vehicle(Request $request, Vehicle $vehicle)
    {
        $this->customerOwner($request, $vehicle);
        $linked = $vehicle->vehicle_model_id === null ? null :
            DB::table('vehicle_model_catalog')->find($vehicle->vehicle_model_id);

        return $this->privateReply([
            'vehicle_id' => $vehicle->id,
            'vehicle_connector_type' => $vehicle->connector_type,
            'model_id' => $linked?->id,
            'model' => $linked ? $this->record($linked) : null,
            'published' => (bool) ($linked?->published ?? false),
            'does_not_override_connector' => true,
        ]);
    }

    public function associate(Request $request, Vehicle $vehicle)
    {
        $this->customerOwner($request, $vehicle);
        $v = $request->validate([
            'model_id' => 'present|nullable|integer|min:1',
            'expected_model_id' => 'present|nullable|integer|min:1',
        ]);

        return DB::transaction(function () use ($request, $vehicle, $v) {
            $row = Vehicle::whereKey($vehicle->id)->lockForUpdate()->firstOrFail();
            $this->customerOwner($request, $row);
            abort_unless((int) ($row->vehicle_model_id ?? 0) === (int) ($v['expected_model_id'] ?? 0),
                409, 'Vehicle model changed; refresh before saving.');
            if ($v['model_id'] !== null) {
                $model = DB::table('vehicle_model_catalog')->where('id', $v['model_id'])->first();
                abort_unless($model && $model->published, 422, 'Choose an active catalog model.');
                abort_unless($model->connector_type === $row->connector_type, 422,
                    'Catalog connector is incompatible with the vehicle.');
            }
            $row->vehicle_model_id = $v['model_id'];
            $row->save();
            $this->audit($request->user()->id, 'vehicle.model_associated', $row->id);
            $model = $row->vehicle_model_id === null ? null :
                DB::table('vehicle_model_catalog')->find($row->vehicle_model_id);

            return $this->privateReply([
                'vehicle_id' => $row->id, 'model_id' => $row->vehicle_model_id,
                'model' => $model ? $this->record($model) : null,
                'vehicle_connector_type' => $row->connector_type,
                'does_not_override_connector' => true,
            ]);
        }, 3);
    }

    private function customerOwner(Request $request, Vehicle $vehicle): void
    {
        abort_unless($request->user()->role === 'customer' &&
            (int) $vehicle->user_id === (int) $request->user()->id, 404);
    }

    private function audit(int $actor, string $action, int $id): void
    {
        DB::table('audit_events')->insert([
            'actor_id' => $actor, 'action' => $action,
            'subject' => (string) $id, 'created_at' => now(),
        ]);
    }
}
