<?php

namespace App\Http\Controllers;

use App\Models\ChargerCommand;
use App\Models\User;
use App\Security\AdminTransactionAuthority;
use App\Services\Charging\CommandSecretFields;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class AdminCommandSecretStorageController extends Controller
{
    public function index(Request $request)
    {
        return DB::transaction(function () use ($request) {
            $snapshot = $request->user();
            abort_unless($snapshot instanceof User, 403);
            AdminTransactionAuthority::requireCurrent($snapshot);
            $v = $request->validate(['after' => 'sometimes|integer|between:0,9007199254740991', 'limit' => 'sometimes|integer|between:1,100']);
            $after = (int) ($v['after'] ?? 0);
            $limit = (int) ($v['limit'] ?? 25);
            // Bound text at the SQL boundary before model hydration. substr /
            // CAST AS TEXT are supported by both canonical PostgreSQL/SQLite.
            $rows = ChargerCommand::where('id', '>', $after)->orderBy('id')->limit($limit + 1)
                ->select(['id', 'actor_id', 'station_id', 'action', 'idempotency_key', 'payload_hash'])
                ->selectRaw('substr(CAST(payload AS TEXT), 1, 65537) AS payload, substr(CAST(confirmation AS TEXT), 1, 65537) AS confirmation')->get();
            $hasMore = $rows->count() > $limit;
            $rows = $rows->take($limit);
            $counts = array_fill_keys(['encrypted_rows', 'legacy_rows', 'unreadable_rows', 'unknown_shape_rows', 'no_known_secret_rows', 'encrypted_fields', 'legacy_fields'], 0);
            foreach ($rows as $row) {
                try {
                    $attributes = $row->getAttributes();
                    $payload = CommandSecretFields::inspect($attributes['payload'] ?? null, $attributes, 'payload');
                    $confirmation = CommandSecretFields::inspect($attributes['confirmation'] ?? null, $attributes, 'confirmation');
                    if (! $payload['shape_known']) {
                        $counts['unknown_shape_rows']++;

                        continue;
                    }
                    $encrypted = $payload['encrypted_fields'] + $confirmation['encrypted_fields'];
                    $legacy = $payload['legacy_fields'] + $confirmation['legacy_fields'];
                    $counts[$legacy > 0 ? 'legacy_rows' : ($encrypted > 0 ? 'encrypted_rows' : 'no_known_secret_rows')]++;
                    $counts['encrypted_fields'] += $encrypted;
                    $counts['legacy_fields'] += $legacy;
                } catch (DecryptException) {
                    $counts['unreadable_rows']++;
                }
            }

            return response()->json([
                'scope' => 'bounded_command_secret_storage', 'as_of' => now()->toISOString(),
                'after_id' => $after, 'limit' => $limit, 'max_field_bytes' => 65536,
                'rows_examined' => $rows->count(), ...$counts,
                'has_more' => $hasMore, 'next_after_id' => $hasMore ? (int) $rows->last()->id : 0,
                'whole_table_verified' => false, 'mutation_performed' => false,
                'commands_dispatched' => 0, 'network_requests' => 0, 'production_acceptance' => false,
            ])->header('Cache-Control', 'no-store, private');
        });
    }
}
