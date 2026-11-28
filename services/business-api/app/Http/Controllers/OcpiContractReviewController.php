<?php

namespace App\Http\Controllers;

use App\Models\OcpiProvider;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

final class OcpiContractReviewController extends Controller
{
    private const TABLE = 'ocpi_partner_contract_reviews';

    /**
     * The recorded Admin review is a software audit of a human-provided
     * reference and document digest. It is NOT a signature, live partner
     * enrollment, roaming agreement, legal approval or payment authority.
     */
    private function actor(Request $request): User
    {
        $snapshot = $request->user();
        abort_unless($snapshot instanceof User
            && in_array($snapshot->role, ['admin', 'vendor'], true), 404);
        $current = User::whereKey($snapshot->id)->lockForUpdate()->firstOrFail();
        abort_unless($current->account_status === 'active'
            && in_array($current->role, ['admin', 'vendor'], true), 403);
        abort_unless($current->role === $snapshot->role
            && (int) $current->auth_epoch === (int) $snapshot->auth_epoch
            && hash_equals((string) $current->password, (string) $snapshot->password),
            409, 'Account credentials changed. Sign in again.');

        return $current;
    }

    /** Lock the identity first, original owner second, then current provider. */
    private function provider(OcpiProvider $snapshot, User $actor): array
    {
        $owner = (int) $actor->id === (int) $snapshot->owner_id
            ? $actor : User::whereKey($snapshot->owner_id)->lockForUpdate()->first();
        abort_unless($owner, 409, 'Provider owner no longer exists.');
        $current = OcpiProvider::whereKey($snapshot->id)->lockForUpdate()->firstOrFail();
        abort_unless((int) $owner->id === (int) $current->owner_id
            && $snapshot->connection_key === $current->connection_key, 409,
            'Provider owner or connection changed. Reload the current contract review.');
        abort_unless($actor->role === 'admin' || (int) $actor->id === (int) $current->owner_id, 404);

        return [$owner, $current];
    }

    private static function last(OcpiProvider $provider): ?object
    {
        return DB::table(self::TABLE)->where('provider_id', $provider->id)
            ->orderByDesc('revision')->first();
    }

    private static function validUntil(mixed $value, CarbonImmutable $checkedAt): bool
    {
        return is_string($value)
            && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $value, $parts) === 1
            && checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])
            && $value >= $checkedAt->toDateString()
            && $value <= $checkedAt->addYears(3)->toDateString();
    }

    private static function effective(?object $review, User $owner, OcpiProvider $provider): bool
    {
        return $review && (int) $review->owner_id === (int) $owner->id
            && $review->connection_key === $provider->connection_key
            && $review->decision === 'review'
            && $owner->role === 'vendor' && $owner->account_status === 'active'
            && self::validUntil($review->valid_until, CarbonImmutable::now('UTC'));
    }

    private static function status(?object $review, User $owner, OcpiProvider $provider): array
    {
        $currentOwner = $review && (int) $review->owner_id === (int) $owner->id
            && $review->connection_key === $provider->connection_key;

        return [
            'state' => $currentOwner ? ($review->decision === 'review' ? 'internally_reviewed' : 'revoked') : 'missing',
            'revision' => (int) ($review->revision ?? 0),
            'review_valid_for_current_owner' => self::effective($review, $owner, $provider),
            'valid_until' => $currentOwner && $review->decision === 'review' ? $review->valid_until : null,
            'source' => 'INTERNAL_MANUAL_DOCUMENT_DIGEST_ONLY',
            'operational_enforcement_available' => false,
            'external_partner_acceptance_verified' => false,
            'contract_legally_executed_verified' => false,
            'live_roaming_enabled' => false,
            'payment_authority' => false,
            'automatic_partner_requests' => 0,
        ];
    }

    public function show(Request $request, OcpiProvider $provider)
    {
        $payload = DB::transaction(function () use ($request, $provider) {
            $actor = $this->actor($request);
            [$owner, $current] = $this->provider($provider, $actor);

            return self::status(self::last($current), $owner, $current);
        }, 3);

        return response()->json($payload)->header('Cache-Control', 'private, no-store');
    }

    /** Scoped, bounded review history; encrypted notes stay Admin-only. */
    public function history(Request $request, OcpiProvider $provider)
    {
        $payload = DB::transaction(function () use ($request, $provider) {
            $actor = $this->actor($request);
            [$owner, $current] = $this->provider($provider, $actor);
            $request->validate(['page' => 'sometimes|integer|min:1|max:100000']);
            $rows = DB::table(self::TABLE)->where('provider_id', $current->id)
                ->where('owner_id', $owner->id)->where('connection_key', $current->connection_key)
                ->orderByDesc('revision')->paginate(25, [
                    'revision', 'decision', 'reference_id', 'evidence_sha256',
                    'valid_until', 'reason_ciphertext', 'created_at',
                ]);
            $rows->through(function (object $row) use ($actor) {
                $result = [
                    'revision' => (int) $row->revision,
                    'decision' => $row->decision,
                    'valid_until' => $row->decision === 'review' ? $row->valid_until : null,
                    'recorded_at' => $row->created_at,
                ];
                if ($actor->role === 'admin') {
                    try {
                        $result['reason'] = Crypt::decryptString($row->reason_ciphertext);
                    } catch (\Throwable) {
                        abort(409, 'Contract review audit note requires recovery.');
                    }
                    $result['reference_id'] = $row->reference_id;
                    $result['evidence_sha256'] = $row->evidence_sha256;
                }

                return $result;
            });

            return [
                'scope' => 'private_ocpi_contract_review_history',
                'operational_enforcement_available' => false,
                'current_revision' => (int) (self::last($current)->revision ?? 0),
                'history' => $rows,
                'external_partner_acceptance_verified' => false,
                'contract_legally_executed_verified' => false,
                'live_roaming_enabled' => false,
                'payment_authority' => false,
            ];
        }, 3);

        return response()->json($payload)->header('Cache-Control', 'private, no-store');
    }

    public function decide(Request $request, OcpiProvider $provider)
    {
        abort_unless($request->user()?->role === 'admin', 403);

        [$payload, $created] = DB::transaction(function () use ($request, $provider) {
            $actor = $this->actor($request);
            abort_unless($actor->role === 'admin', 403);
            [$owner, $current] = $this->provider($provider, $actor);

            $v = $request->validate([
                'decision' => 'required|in:review,revoke',
                'reference_id' => ['required_if:decision,review', 'prohibited_unless:decision,review',
                    'string', 'regex:/^[A-Za-z0-9][A-Za-z0-9_.-]{5,119}$/D'],
                'evidence_sha256' => ['required_if:decision,review', 'prohibited_unless:decision,review',
                    'string', 'regex:/^[a-f0-9]{64}$/D'],
                'valid_until' => 'required_if:decision,review|prohibited_unless:decision,review|date_format:Y-m-d',
                'expected_revision' => 'required|integer|min:0|max:2147483646',
                'idempotency_key' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{16,100}$/D'],
                'reason' => 'required|string|min:15|max:1000',
            ]);
            $hash = hash('sha256', json_encode([
                $v['decision'], $v['reference_id'] ?? null, $v['evidence_sha256'] ?? null,
                $v['valid_until'] ?? null, (int) $v['expected_revision'], $v['reason'],
            ], JSON_THROW_ON_ERROR));
            $prior = DB::table(self::TABLE)->where('provider_id', $current->id)
                ->where('idempotency_key', $v['idempotency_key'])->first();
            $last = self::last($current);
            if ($prior) {
                abort_unless((int) $prior->owner_id === (int) $owner->id
                    && $prior->connection_key === $current->connection_key
                    && (int) $prior->reviewer_id === (int) $actor->id
                    && hash_equals($prior->payload_hash, $hash), 409,
                    'Contract review idempotency identity is already bound.');

                return [self::status($last, $owner, $current) + ['idempotent_replay' => true], false];
            }
            abort_unless((int) ($last->revision ?? 0) === (int) $v['expected_revision'], 409,
                'Contract review revision changed. Reload before submitting.');
            abort_unless($last === null || $last->revision < 2147483647, 409,
                'Contract review revision exhausted.');

            if ($v['decision'] === 'review') {
                abort_unless($owner->role === 'vendor' && $owner->account_status === 'active',
                    409, 'Only active vendor ownership can receive a new review.');
                $until = CarbonImmutable::parse($v['valid_until'], 'UTC');
                abort_unless($until->greaterThanOrEqualTo(CarbonImmutable::now('UTC')->startOfDay())
                    && $until->lessThanOrEqualTo(CarbonImmutable::now('UTC')->addYears(3)->endOfDay()),
                    422, 'Review validity must be within the next three years.');
            } else {
                abort_unless($last && (int) $last->owner_id === (int) $owner->id
                    && $last->connection_key === $current->connection_key
                    && $last->decision === 'review', 409,
                    'Only a current internal review can be revoked.');
                // This candidate records evidence only. Operational authority is
                // unchanged until a separately accepted enforcement integration.
            }

            $revision = (int) ($last->revision ?? 0) + 1;
            DB::table(self::TABLE)->insert([
                'provider_id' => $current->id,
                'owner_id' => $owner->id,
                'reviewer_id' => $actor->id,
                'connection_key' => $current->connection_key,
                'revision' => $revision,
                'decision' => $v['decision'],
                'reference_id' => $v['reference_id'] ?? null,
                'evidence_sha256' => $v['evidence_sha256'] ?? null,
                'valid_until' => $v['valid_until'] ?? null,
                'idempotency_key' => $v['idempotency_key'],
                'payload_hash' => $hash,
                'reason_ciphertext' => Crypt::encryptString($v['reason']),
                'created_at' => now(),
            ]);
            DB::table('audit_events')->insert([
                'actor_id' => $actor->id,
                'action' => 'ocpi.contract_'.$v['decision'],
                'subject' => (string) $current->id.':'.$revision,
                'created_at' => now(),
            ]);
            $fresh = self::last($current);

            return [self::status($fresh, $owner, $current) + ['idempotent_replay' => false], true];
        }, 3);

        return response()->json($payload, $created ? 201 : 200)
            ->header('Cache-Control', 'private, no-store');
    }
}
