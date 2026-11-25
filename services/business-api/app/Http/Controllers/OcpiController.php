<?php

namespace App\Http\Controllers;

use App\Models\OcpiProvider;
use App\Models\OcpiRecord;
use App\Models\User;
use App\Security\CurrentFinanceReader;
use App\Services\Charging\MaintenanceActor;
use App\Services\Ocpi\CdrReconciler;
use App\Services\Ocpi\ContractReviewGate;
use App\Services\Ocpi\OcpiException;
use App\Services\Ocpi\PartnerBillingExport;
use App\Services\Ocpi\PartnerBillingReconciliation;
use App\Services\Ocpi\PartnerClient;
use App\Services\Ocpi\RecordSynchronizer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OcpiController extends Controller
{
    /** Admin mutations must not trust a request whose original authority was revoked. */
    private function currentAdminForMutation(Request $request): User
    {
        $snapshot = $request->user();
        abort_unless($snapshot instanceof User && $snapshot->role === 'admin', 403);
        $actor = User::whereKey($snapshot->id)->lockForUpdate()->firstOrFail();
        abort_unless($actor->role === 'admin' && $actor->account_status === 'active', 403);
        abort_unless((int) $actor->auth_epoch === (int) $snapshot->auth_epoch
            && hash_equals((string) $actor->password, (string) $snapshot->password),
            409, 'Account credentials changed. Sign in again.');

        return $actor;
    }

    /**
     * Opt-in onboarding policy: a manual internal evidence review is a
     * prerequisite for creating an active OCPI operator, never proof of a
     * legally signed agreement or permission to process real charge/payment.
     */
    private function requireInternalContractReview(OcpiProvider $provider, User $owner): void
    {
        abort_unless(app(ContractReviewGate::class)->current($provider, $owner),
            409, 'Current provider owner requires an unexpired internal contract evidence review.');
    }

    private function owner(Request $r, OcpiProvider $provider): void
    {
        $snapshot = $r->user();
        // Keep unprivileged roles and other vendors non-enumerating.
        abort_unless($snapshot instanceof User
            && in_array($snapshot->role, ['vendor', 'admin'], true), 404);
        $actor = User::whereKey($snapshot->id)->firstOrFail();
        abort_unless($actor->account_status === 'active'
            && in_array($actor->role, ['vendor', 'admin'], true), 403);
        abort_unless($actor->role === $snapshot->role
            && (int) $actor->auth_epoch === (int) $snapshot->auth_epoch
            && hash_equals((string) $actor->password, (string) $snapshot->password),
            409, 'Account credentials changed. Sign in again.');

        // The route model may predate a provider transfer or revocation.
        // The mutating sync/negotiation and private finance transactions make
        // their own final serialized authority checks after this preflight.
        $current = OcpiProvider::whereKey($provider->id)->firstOrFail();
        abort_unless((int) $current->owner_id === (int) $provider->owner_id, 409,
            'Provider ownership changed. Reload this provider.');
        abort_unless($actor->role === 'admin' || (int) $current->owner_id === (int) $actor->id, 404);
    }

    /**
     * Partner CDR financial reports contain canonical private invoice and
     * credit-note lineage. Call only inside the response snapshot transaction:
     * actor lock first, then current provider lock. A stale route model or
     * cached Vendor/Admin credential cannot authorize another owner's ledger.
     */
    private function billingProvider(Request $r, OcpiProvider $route): OcpiProvider
    {
        $actor = app(CurrentFinanceReader::class)->requireCurrent($r);
        // Retain legacy non-enumerating 404 for customers/foreign peers.
        abort_unless(in_array($actor->role, ['vendor', 'admin'], true), 404);
        $provider = OcpiProvider::whereKey($route->id)->lockForUpdate()->firstOrFail();
        abort_unless($actor->role === 'admin'
            || (int) $provider->owner_id === (int) $actor->id, 404);

        return $provider;
    }

    private function audit(Request $r, string $action, int $id): void
    {
        DB::table('audit_events')->insert(['actor_id' => $r->user()->id, 'action' => $action, 'subject' => (string) $id, 'created_at' => now()]);
    }

    public function providers(Request $r)
    {
        // A stale authenticated request must not enumerate private partner
        // accounts after a role, password, epoch or status change.
        return DB::transaction(function () use ($r) {
            $snapshot = $r->user();
            abort_unless($snapshot instanceof User, 401);
            $actor = User::whereKey($snapshot->id)->lockForUpdate()->firstOrFail();
            abort_unless($actor->account_status === 'active'
                && in_array($actor->role, ['vendor', 'admin'], true), 403);
            abort_unless($snapshot->role === $actor->role
                && (int) $snapshot->auth_epoch === (int) $actor->auth_epoch
                && hash_equals((string) $snapshot->password, (string) $actor->password),
                409, 'Account credentials changed. Sign in again.');

            $page = OcpiProvider::when($actor->role !== 'admin',
                fn ($q) => $q->where('owner_id', $actor->id))
                ->orderByDesc('id')->paginate(25);

            return response()->json($page)->header('Cache-Control', 'private, no-store');
        }, 3);
    }

    public function create(Request $r, PartnerClient $client)
    {
        abort_unless($r->user()?->role === 'admin', 403);

        return DB::transaction(function () use ($r, $client) {
            // Current Admin authority is required even to validate a private
            // owner identity or expose configured connection availability.
            $this->currentAdminForMutation($r);
            $v = $r->validate(['owner_id' => 'required|integer|exists:users,id', 'name' => 'required|string|max:150', 'connection_key' => 'required|string|alpha_dash|max:80|unique:ocpi_providers', 'authorized' => 'required|boolean']);
            abort_unless(is_array(config('ocpi.connections.'.$v['connection_key'])), 422, 'Connection must first be provisioned in secure server configuration.');
            // Bind a new provider only to a currently eligible vendor owner.
            // Serialize with account suspension/role changes before reserving a connection key.
            $owner = User::whereKey($v['owner_id'])->lockForUpdate()->first();
            abort_unless($owner && $owner->role === 'vendor' && $owner->account_status === 'active',
                422, 'Provider owner must be an active vendor.');
            // Under an enabled review policy, onboarding begins pending.
            // No review can be recorded for a provider that does not yet exist.
            abort_if($v['authorized'] && config('ocpi.contract_review_required') === true,
                409, 'Register the provider pending; review contract evidence before authorization.');
            $provider = OcpiProvider::create($v);
            if ($provider->authorized) {
                try {
                    $client->connection($provider, true);
                } catch (OcpiException $e) {
                    abort(422, $e->getMessage());
                }
            }
            $this->audit($r, 'ocpi.provider_created', $provider->id);

            return response()->json($provider, 201);
        });
    }

    public function authorization(Request $r, OcpiProvider $provider, PartnerClient $client)
    {
        abort_unless($r->user()?->role === 'admin', 403);

        return DB::transaction(function () use ($r, $provider, $client) {
            $this->currentAdminForMutation($r);
            $v = $r->validate(['authorized' => 'required|boolean']);
            // For activation, acquire owner before provider, as all OCPI
            // source reads do. A transferred provider must be reloaded and
            // re-reviewed, never authorized under the previous owner lock.
            $owner = null;
            if ($v['authorized']) {
                $owner = User::whereKey($provider->owner_id)->lockForUpdate()->first();
                abort_unless($owner && $owner->role === 'vendor' && $owner->account_status === 'active',
                    422, 'Provider owner must be an active vendor.');
            }
            $provider = OcpiProvider::whereKey($provider->id)->lockForUpdate()->firstOrFail();
            if ($v['authorized']) {
                abort_unless((int) $provider->owner_id === (int) $owner->id, 409,
                    'Provider ownership changed; review the current owner before authorizing.');
                $this->requireInternalContractReview($provider, $owner);
            }
            $exhausted = $provider->authorization_revision >= PHP_INT_MAX;
            // Revocation must remain available at the terminal generation.
            // Reauthorization is permanently blocked, so that generation cannot recur.
            abort_if($v['authorized'] && $exhausted, 409, 'Operator authorization revision is exhausted.');
            $provider->authorized = $v['authorized'];
            if ($provider->authorized) {
                try {
                    $client->connection($provider, true);
                } catch (OcpiException $e) {
                    abort(422, $e->getMessage());
                }
            }
            $provider->fill(['version' => null, 'endpoints' => null, 'negotiated_at' => null, 'authorization_revision' => $exhausted ? PHP_INT_MAX : (int) $provider->authorization_revision + 1])->save();
            DB::table('ocpi_credential_operations')->where('provider_id', $provider->id)->where('action', 'prepare_incoming')->where('state', 'awaiting_peer')->update(['state' => 'revoked', 'completed_at' => now(), 'updated_at' => now()]);
            DB::table('ocpi_credential_links')->where('provider_id', $provider->id)->update(['state' => 'revoked', 'local_token_digest' => null, 'updated_at' => now()]);
            $this->audit($r, $provider->authorized ? 'ocpi.provider_authorized' : 'ocpi.provider_revoked', $provider->id);

            return response()->json($provider);
        });
    }

    public function negotiate(Request $r, OcpiProvider $provider, PartnerClient $client)
    {
        $this->owner($r, $provider);
        abort_unless(User::whereKey($provider->owner_id)->where('role', 'vendor')->where('account_status', 'active')->exists(),
            409, 'Partner owner is not active.');
        try {
            $endpoints = $client->negotiate($provider);
        } catch (OcpiException $e) {
            abort(409, $e->getMessage());
        }$this->audit($r, 'ocpi.version_negotiated', $provider->id);

        return response()->json(['version' => '2.2.1', 'readable_modules' => array_values(array_intersect(PartnerClient::MODULES, array_keys($endpoints))), 'commands_enabled' => false, 'evidence' => 'CONTRACT_UNVERIFIED']);
    }

    public function sync(Request $r, OcpiProvider $provider, string $module, PartnerClient $client, RecordSynchronizer $sync)
    {
        $this->owner($r, $provider);
        abort_unless(in_array($module, PartnerClient::MODULES, true), 404);
        // Suspended operators cannot refresh a public catalog. Administrators
        // must retain the ability to reconcile already-owned session/CDR debt.
        $activeOwner = User::whereKey($provider->owner_id)->where('role', 'vendor')
            ->where('account_status', 'active')->exists();
        abort_unless($activeOwner || ($r->user()->role === 'admin' && in_array($module, ['sessions', 'cdrs'], true)),
            409, 'Partner owner is not active for catalog synchronization.');
        try {
            $actor = clone $r->user();
            $consent = function () use ($actor, $provider, $module): bool {
                app(MaintenanceActor::class)->check($actor);
                $current = OcpiProvider::findOrFail($provider->id);
                if ($actor->role !== 'admin' && ($actor->role !== 'vendor' || $current->owner_id !== $actor->id)) {
                    return false;
                }

                return ($actor->role === 'admin' && in_array($module, ['sessions', 'cdrs'], true))
                    || User::whereKey($current->owner_id)->where('role', 'vendor')->where('account_status', 'active')->exists();
            };
            $counts = $client->synchronize($provider, $module, $sync, $consent);
        } catch (OcpiException $e) {
            abort(409, $e->getMessage());
        }
        $this->audit($r, 'ocpi.'.$module.'_synchronized', $provider->id);

        return response()->json(['counts' => $counts, 'evidence' => 'PARTNER_REPORTED_UNVERIFIED', 'remote_control' => false, 'billing_eligible' => false]);
    }

    public function billingReconciliation(Request $r, OcpiProvider $provider, PartnerBillingReconciliation $service)
    {
        return DB::transaction(function () use ($r, $provider, $service) {
            $current = $this->billingProvider($r, $provider);
            // Validate after ownership to avoid revealing that foreign CDR
            // financial sources exist through a different 422 response.
            $filters = $r->validate(['page' => 'sometimes|integer|min:1|max:100000', 'currency' => 'sometimes|regex:/^[A-Z]{3}$/D', 'country_code' => 'sometimes|regex:/^[A-Z]{2}$/D', 'party_id' => 'sometimes|regex:/^[A-Z0-9]{3}$/D', 'kind' => 'sometimes|in:all,charges,credits']);

            return response()->json($service->page($current, $filters))
                ->header('Cache-Control', 'private, no-store');
        }, 3);
    }

    public function billingExport(Request $r, OcpiProvider $provider, PartnerBillingReconciliation $service, PartnerBillingExport $export, string $format)
    {
        abort_unless(in_array($format, ['csv', 'pdf'], true), 404);
        try {
            [$rows, $filters] = DB::transaction(function () use ($r, $provider, $service) {
                $current = $this->billingProvider($r, $provider);
                $filters = $r->validate(['currency' => 'sometimes|regex:/^[A-Z]{3}$/D', 'country_code' => 'sometimes|regex:/^[A-Z]{2}$/D', 'party_id' => 'sometimes|regex:/^[A-Z0-9]{3}$/D', 'kind' => 'sometimes|in:all,charges,credits', 'locale' => 'sometimes|in:en,ar', 'page' => 'prohibited']);

                return [$service->exportRows($current, $filters), $filters];
            }, 3);
        } catch (ValidationException $error) {
            // A direct browser download has no Accept: JSON header. Keep
            // invalid private financial exports an explicit HTTP 422,
            // never an HTML redirect with a misleading success page.
            return response()->json([
                'message' => 'Invalid private partner financial export filters.',
                'errors' => $error->errors(),
            ], 422)->header('Cache-Control', 'private, no-store');
        }
        $body = $format === 'csv' ? $export->csv($rows) : $export->pdf($rows, $provider->id, $filters);

        return response($body)->header('Content-Type', $format === 'csv' ? 'text/csv; charset=UTF-8' : 'application/pdf')
            ->header('Content-Disposition', 'attachment; filename="partner-billing.'.$format.'"')
            ->header('Cache-Control', 'private, no-store')->header('X-Content-Type-Options', 'nosniff');
    }

    public function records(Request $r, OcpiProvider $provider, string $module, CdrReconciler $reconciler)
    {
        abort_unless(in_array($module, PartnerClient::MODULES, true), 404);

        return DB::transaction(function () use ($r, $provider, $module, $reconciler) {
            // A request may retain the previous actor and provider model while
            // a concurrent role/epoch change or provider reassignment commits.
            // Lock in the same actor-then-provider order as catalog observations
            // and never return another vendor's private source records.
            $snapshot = $r->user();
            $actor = User::whereKey($snapshot->id)->lockForUpdate()->firstOrFail();
            abort_unless($actor->account_status === 'active'
                && in_array($actor->role, ['vendor', 'admin'], true), 403);
            abort_unless($actor->role === $snapshot->role
                && (int) $actor->auth_epoch === (int) $snapshot->auth_epoch
                && hash_equals((string) $actor->password, (string) $snapshot->password), 409,
                'Account credentials changed. Sign in again.');
            $current = OcpiProvider::whereKey($provider->id)->lockForUpdate()->firstOrFail();
            abort_unless($actor->role === 'admin' || $current->owner_id === $actor->id, 404);

            $page = OcpiRecord::where('ocpi_provider_id', $current->id)
                ->where('module', $module)->orderByDesc('id')->paginate(25);
            $retired = [];
            if ($module === 'tariffs' && $page->count() > 0) {
                $retired = DB::table('ocpi_catalog_retirements')->where(function ($q) use ($page) {
                    foreach ($page as $record) {
                        $q->orWhere(fn ($pair) => $pair->where('record_id', $record->id)
                            ->where('content_hash', $record->content_hash));
                    }
                })->pluck('record_id')->all();
            }

            return response()->json($page->through(function ($record) use ($current, $module, $reconciler, $retired) {
                return $record->toArray() + ($module === 'cdrs'
                    ? ['reconciliation' => $reconciler->inspect($current, $record)] : [])
                    + ($module === 'tariffs'
                        ? ['catalog_retired' => in_array($record->id, $retired, true)] : []);
            }))->header('Cache-Control', 'private, no-store');
        }, 3);
    }

    public function locations(Request $request)
    {
        $v = $request->validate(['q' => 'nullable|string|max:80', 'country' => 'nullable|string|regex:/^[A-Z]{3}$/D', 'connector_standard' => 'nullable|string|max:64']);
        $text = trim($v['q'] ?? '');

        return OcpiRecord::where('module', 'locations')->where('normalized->publish', true)->whereIn('ocpi_provider_id', app(ContractReviewGate::class)->eligiblePublic(OcpiProvider::query()->where('authorized', true))->select('id'))
            ->when($text !== '', fn ($q) => $q->where(fn ($q) => $q->whereLike('normalized->name', '%'.$text.'%')->orWhereLike('normalized->city', '%'.$text.'%')->orWhereLike('normalized->address', '%'.$text.'%')))
            ->when(! empty($v['country']), fn ($q) => $q->where('normalized->country', $v['country']))
            ->when(! empty($v['connector_standard']), fn ($q) => $q->whereJsonContains('normalized->connector_standards', $v['connector_standard']))
            ->orderByDesc('id')->paginate(25)->withQueryString()->through(fn ($r) => ['id' => $r->id, 'provider_id' => $r->ocpi_provider_id, 'location' => $r->normalized, 'received_at' => $r->received_at, 'evidence' => 'PARTNER_REPORTED_UNVERIFIED', 'availability' => 'unknown', 'remote_control' => false, 'reservable' => false]);
    }
}
