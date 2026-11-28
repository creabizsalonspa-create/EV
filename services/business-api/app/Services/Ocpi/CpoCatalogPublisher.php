<?php

namespace App\Services\Ocpi;

use App\Models\OcpiProvider;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class CpoCatalogPublisher
{
    private const TABLE = 'ocpi_cpo_catalog';

    private const OPERATIONS = 'ocpi_cpo_publication_operations';

    public function __construct(
        private CpoCatalogContract $contracts,
        private CpoCatalogSource $source,
        private CredentialExchange $credentials,
        private RecordNormalizer $normalizer,
        private PartnerClient $partner,
    ) {}

    private function admin(User $actor): void
    {
        $current = User::whereKey($actor->id)->lockForUpdate()->first();
        abort_unless($current && $current->role === 'admin' && $current->account_status === 'active' && $current->auth_epoch === $actor->auth_epoch
            && hash_equals((string) $current->password, (string) $actor->password), 403);
    }

    private function authority(OcpiProvider $provider): array
    {
        $contract = $this->contracts->approved($provider);
        $link = DB::table('ocpi_credential_links')->where('provider_id', $provider->id)->lockForUpdate()->first();
        $owner = User::whereKey($provider->owner_id)->lockForUpdate()->first();
        abort_unless($contract && $provider->authorized && $provider->version === '2.2.1' && $owner?->role === 'vendor' && $owner->account_status === 'active'
            && $link?->state === 'active' && $link->owner_id === $provider->owner_id && hash_equals($link->configuration_hash, $this->credentials->configurationHash($provider)), 403);

        return [$contract, $link];
    }

    private function bound(OcpiProvider $provider, object $link): Builder
    {
        return DB::table(self::TABLE)->where([
            'provider_id' => $provider->id, 'owner_id' => $provider->owner_id,
            'generation' => $link->generation, 'configuration_hash' => $link->configuration_hash,
            'authorization_revision' => $provider->authorization_revision,
        ]);
    }

    public function show(User $actor, OcpiProvider $provider): array
    {
        return DB::transaction(function () use ($actor, $provider) {
            $provider = OcpiProvider::whereKey($provider->id)->lockForUpdate()->firstOrFail();
            $this->admin($actor);
            if (! $this->contracts->approved($provider)) {
                return ['configured' => false];
            }
            [$contract, $link] = $this->authority($provider);
            $history = DB::table(self::TABLE)->where('provider_id', $provider->id)->where('owner_id', $provider->owner_id)
                ->orderByDesc('published_at')->paginate(25, ['id', 'module', 'source_id', 'withdrawn', 'published_at', 'generation', 'authorization_revision']);
            $history->through(function (object $row) use ($provider, $link) {
                $row->authority_current = $row->generation === $link->generation && $row->authorization_revision === $provider->authorization_revision;
                $row->delivery = DB::table(self::OPERATIONS)->where(['publication_id' => $row->id, 'published_at' => $row->published_at])->value('state');
                $row->source_id = (string) $row->source_id;
                unset($row->generation, $row->authorization_revision);

                return $row;
            });

            $sources = ['locations' => array_map('strval', array_keys($contract['sites'])), 'tariffs' => array_map('strval', $contract['tariffs'])];
            $sessionExports = $this->contracts->sessionExports($provider, $contract);
            if ($sessionExports !== []) {
                $sources['sessions'] = $sources['cdrs'] = array_map('strval', array_keys($sessionExports));
            }

            return [
                'configured' => true,
                'sources' => $sources,
                'history' => $history,
                'sandbox_push' => $this->pushEnabled($provider),
            ];
        }, 3);
    }

    private function decode(object $record): array
    {
        abort_if($record->payload_digest !== null && ! $this->payloadIntact($record), 409, 'Stored publication requires integrity review.');
        $payload = in_array($record->module, CpoCatalogContract::SESSION_MODULES, true)
            ? Crypt::decryptString($record->payload) : $record->payload;

        return json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
    }

    private function payloadIntact(object $row): bool
    {
        return is_string($row->payload_digest) && hash_equals($row->payload_digest, hash('sha256', $row->payload));
    }

    private function deliveryDigest(object $row): string
    {
        return hash('sha256', json_encode([
            (string) $row->id, (string) $row->provider_id, (string) $row->owner_id,
            (string) $row->generation, (string) $row->authorization_revision, $row->configuration_hash,
            $row->module, (string) $row->source_id, $row->published_at, (bool) $row->withdrawn,
            $row->source_hash, hash('sha256', $row->payload),
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * Bind the original publication AND negotiated authority in the durable
     * receipt. No token or private endpoint is stored in plaintext. Legacy
     * payload-only digests cannot prove which receiver was actually contacted.
     */
    private function deliveryAuthorityDigest(object $row, OcpiProvider $provider, object $link): string
    {
        return hash('sha256', json_encode([
            'cpo-delivery-authority-v2', $this->deliveryDigest($row),
            (string) $provider->id, (string) $provider->owner_id, $provider->connection_key,
            $provider->authorization_revision, $provider->version, $provider->authorized,
            $provider->endpoints[$row->module.'_receiver'] ?? null,
            (string) $link->id, (string) $link->provider_id, (string) $link->owner_id,
            $link->generation, $link->state, $link->configuration_hash,
            $link->bootstrap_hash, $link->bootstrap_expires_at, $link->local_token_digest,
            $link->local_token_ciphertext, $link->remote_token_ciphertext,
        ], JSON_THROW_ON_ERROR));
    }

    private function deliveryMatches(object $row, object $operation, OcpiProvider $provider, object $link): bool
    {
        return $this->payloadIntact($row) && is_string($operation->publication_digest)
            && hash_equals($operation->publication_digest, $this->deliveryAuthorityDigest($row, $provider, $link));
    }

    /** Match the exact publication snapshot across a transport boundary. */
    private function samePublication(OcpiProvider $provider, object $link, object $snapshot): bool
    {
        $row = $this->bound($provider, $link)->where('id', $snapshot->id)->lockForUpdate()->first();

        return $row !== null && $this->payloadIntact($row) && $row->module === $snapshot->module
            && $row->source_id === $snapshot->source_id
            && $row->published_at === $snapshot->published_at
            && (bool) $row->withdrawn === (bool) $snapshot->withdrawn
            && hash_equals($snapshot->source_hash, $row->source_hash)
            && hash_equals($snapshot->payload, $row->payload);
    }

    private function sourcePayload(OcpiProvider $provider, array $contract, string $module, int $source): array
    {
        if (in_array($module, CpoCatalogContract::SESSION_MODULES, true)) {
            return app(CpoSessionSource::class)->payload($provider, $contract, $module, $source);
        }

        return $this->source->payload($provider, $contract, $module, $source);
    }

    private function stamp(array $payload, string $timestamp, bool $withdraw): array
    {
        $payload['last_updated'] = $timestamp;
        if (array_key_exists('evses', $payload)) {
            $payload['publish'] = ! $withdraw;
            foreach ($payload['evses'] as &$evse) {
                $evse['status'] = $withdraw ? 'REMOVED' : $evse['status'];
                $evse['last_updated'] = $timestamp;
                foreach ($evse['connectors'] as &$connector) {
                    $connector['last_updated'] = $timestamp;
                    if ($withdraw) {
                        unset($connector['tariff_ids']);
                    }
                }
                unset($connector);
            }
            unset($evse);
        }

        return $payload;
    }

    private function tariffReferences(array $location): array
    {
        $references = [];
        foreach ($location['evses'] ?? [] as $evse) {
            if ($evse['status'] === 'REMOVED') {
                continue;
            }
            foreach ($evse['connectors'] as $connector) {
                array_push($references, ...($connector['tariff_ids'] ?? []));
            }
        }

        return array_unique($references, SORT_STRING);
    }

    private function includeRemovedEvses(array $payload, array $previous): array
    {
        $active = array_column($payload['evses'], 'uid');
        foreach ($previous['evses'] as $evse) {
            if (! in_array($evse['uid'], $active, true)) {
                $evse['status'] = 'REMOVED';
                foreach ($evse['connectors'] as &$connector) {
                    unset($connector['tariff_ids']);
                }
                unset($connector);
                $payload['evses'][] = $evse;
            }
        }

        return $payload;
    }

    public function change(User $actor, OcpiProvider $provider, string $module, int $source, string $operation, ?string $expected): array
    {
        return DB::transaction(function () use ($actor, $provider, $module, $source, $operation, $expected) {
            $provider = OcpiProvider::whereKey($provider->id)->lockForUpdate()->firstOrFail();
            $this->admin($actor);
            [$contract, $link] = $this->authority($provider);
            $key = ['provider_id' => $provider->id, 'module' => $module, 'source_id' => $source];
            $previous = DB::table(self::TABLE)->where($key)->first();
            abort_if($previous && $previous->owner_id !== $provider->owner_id, 409);
            $withdraw = $operation === 'withdraw';
            $sessionRecord = in_array($module, CpoCatalogContract::SESSION_MODULES, true);
            abort_if($sessionRecord && $withdraw, 409, 'Final session records are immutable.');

            abort_if($withdraw && ! $previous, 404);
            abort_if($previous && $module === 'tariffs' && $previous->withdrawn && ! $withdraw, 409, 'Publish a new source tariff version after withdrawal.');
            abort_if($previous && $previous->payload_digest !== null && ! $this->payloadIntact($previous), 409, 'Stored publication requires integrity review.');
            // A historical snapshot without a digest cannot establish a new
            // delivery receipt. A new explicit publication must bind its bytes.
            $priorPayload = $previous ? $this->decode($previous) : null;
            $payload = $withdraw ? $priorPayload : $this->sourcePayload($provider, $contract, $module, $source);
            $role = $this->credentials->localConfiguration($provider)['roles'][0];
            $sameIdentity = $priorPayload && $priorPayload['country_code'] === $role['country_code'] && $priorPayload['party_id'] === $role['party_id'];
            abort_if($withdraw && ! $sameIdentity, 409, 'The earlier publication belongs to a different local CPO identity.');
            $sourceHash = $withdraw ? $previous->source_hash : $this->normalizer->hash($payload);
            $bound = $this->bound($provider, $link);
            if ($sessionRecord) {
                abort_if($previous && $previous->source_hash !== $sourceHash, 409, 'Changed final source requires review.');
                $location = (clone $bound)->where(['module' => 'locations', 'source_id' => (int) $contract['session_exports'][$source]['site_id'], 'withdrawn' => false])->first();
                $evse = $payload['evse_uid'] ?? $payload['cdr_location']['evse_uid'];
                $connector = $payload['connector_id'] ?? $payload['cdr_location']['connector_id'];
                $publishedEvse = $location && $this->payloadIntact($location) ? collect($this->decode($location)['evses'])->firstWhere('uid', $evse) : null;
                abort_unless($publishedEvse && $publishedEvse['status'] !== 'REMOVED' && collect($publishedEvse['connectors'])->contains('id', $connector),
                    409, 'Publish the approved session location and connector first.');
                if ($module === 'cdrs') {
                    $sessionHash = $this->normalizer->hash($this->sourcePayload($provider, $contract, 'sessions', $source));
                    $session = (clone $bound)->where(['module' => 'sessions', 'source_id' => $source, 'source_hash' => $sessionHash])->first();
                    abort_unless($session && $this->payloadIntact($session), 409, 'Publish the matching final session first.');
                }
            }
            if ($previous && $this->payloadIntact($previous) && $previous->source_hash === $sourceHash && (bool) $previous->withdrawn === $withdraw && (clone $bound)->where('id', $previous->id)->exists()) {
                return ['id' => $payload['id'], 'published_at' => $previous->published_at, 'withdrawn' => $withdraw];
            }
            abort_unless($previous?->published_at === $expected, 409, 'Refresh the current publication before changing it.');
            abort_if($previous && DB::table(self::OPERATIONS)->where('publication_id', $previous->id)->whereIn('state', ['dispatching', 'unknown'])->exists(), 409, 'Earlier delivery requires reconciliation.');
            if ($module === 'locations' && ! $withdraw) {
                foreach ($this->tariffReferences($payload) as $tariff) {
                    $publishedTariff = (clone $bound)->where(['module' => 'tariffs', 'source_id' => (int) substr($tariff, 1), 'withdrawn' => false])->first();
                    abort_unless($publishedTariff && $this->payloadIntact($publishedTariff), 409, 'Publish referenced tariffs first.');
                }
                if ($sameIdentity) {
                    $payload = $this->includeRemovedEvses($payload, $priorPayload);
                }
            }
            if ($module === 'tariffs' && $withdraw) {
                foreach ((clone $bound)->where(['module' => 'locations', 'withdrawn' => false])->cursor() as $location) {
                    abort_if(in_array($payload['id'], $this->tariffReferences($this->decode($location)), true), 409, 'Remove the tariff from published locations before withdrawing it.');
                }
            }
            $timestamp = now()->utc()->toISOString();
            if ($previous && $timestamp <= $previous->published_at) {
                $timestamp = CarbonImmutable::parse($previous->published_at)->addMicrosecond()->toISOString();
            }
            $payload = $this->stamp($payload, $timestamp, $withdraw);
            $this->normalizer->normalize($module, $payload);
            $encoded = json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
            abort_if(strlen($encoded) > CpoCatalogContract::MAX_RECORD_BYTES, 409, 'Publication exceeds the maximum record size.');
            $stored = $sessionRecord ? Crypt::encryptString($encoded) : $encoded;
            DB::table(self::TABLE)->updateOrInsert($key, [
                'owner_id' => $provider->owner_id, 'actor_id' => $actor->id, 'generation' => $link->generation,
                'authorization_revision' => $provider->authorization_revision, 'configuration_hash' => $link->configuration_hash,
                'source_hash' => $sourceHash, 'payload' => $stored, 'payload_digest' => hash('sha256', $stored), 'withdrawn' => $withdraw, 'published_at' => $timestamp,
            ]);
            DB::table('audit_events')->insert([
                'actor_id' => $actor->id, 'action' => 'ocpi.cpo_'.$operation,
                'subject' => $provider->id.':'.$module.':'.$source.':'.$timestamp, 'created_at' => now(),
            ]);

            return ['id' => $payload['id'], 'published_at' => $timestamp, 'withdrawn' => $withdraw];
        }, 3);
    }

    public function read(OcpiProvider $provider, object $authenticatedLink, string $module, array $window, ?int $source): array
    {
        return DB::transaction(function () use ($provider, $authenticatedLink, $module, $window, $source) {
            $current = OcpiProvider::whereKey($provider->id)->lockForUpdate()->firstOrFail();
            [, $link] = $this->authority($current);
            abort_unless(in_array($module, $this->contracts->modules($current), true), 404);
            abort_unless($current->authorization_revision === $provider->authorization_revision
                && $this->credentials->sameLink($authenticatedLink, $link), 401);
            $query = $this->bound($current, $link)->where('module', $module);
            if ($module === 'tariffs') {
                $query->where('withdrawn', false);
            }
            if ($source !== null) {
                $row = $query->where('source_id', $source)->first();
                abort_unless($row, 404);

                abort_unless($this->payloadIntact($row), 409, 'Stored publication requires integrity review.');

                return [$this->decode($row), 1];
            }
            if (isset($window['date_from'])) {
                $query->where('published_at', '>=', $window['date_from']);
            }
            if (isset($window['date_to'])) {
                $query->where('published_at', '<', $window['date_to']);
            }
            $count = (clone $query)->count();
            $records = $query->orderBy('published_at')->orderBy('id')->offset($window['offset'])->limit($window['limit'])->get();

            $payloads = [];
            $bytes = 0;
            foreach ($records as $record) {
                $bytes += strlen($record->payload);
                if ($bytes > 1_900_000) {
                    break;
                }
                abort_unless($this->payloadIntact($record), 409, 'Stored publication requires integrity review.');
                $payloads[] = $this->decode($record);
            }

            return [$payloads, $count];
        }, 3);
    }

    /** A generation label alone cannot bind a delivery to its negotiated receiver or credential bytes. */
    private function sameDeliveryAuthority(OcpiProvider $expected, object $expectedLink, OcpiProvider $current, object $currentLink, string $module): bool
    {
        return $expected->id === $current->id
            && $expected->owner_id === $current->owner_id
            && $expected->connection_key === $current->connection_key
            && $expected->authorization_revision === $current->authorization_revision
            && $expected->version === $current->version
            && $expected->authorized === $current->authorized
            && ($expected->endpoints[$module.'_receiver'] ?? null) === ($current->endpoints[$module.'_receiver'] ?? null)
            && $this->credentials->sameLink($expectedLink, $currentLink);
    }

    private function pushEnabled(OcpiProvider $provider): bool
    {
        return app()->environment(['local', 'testing']) && config('ocpi.cpo_push_enabled')
            && config('ocpi.connections.'.$provider->connection_key.'.environment') === 'sandbox';
    }

    public function push(User $actor, OcpiProvider $provider, string $module, int $source, string $publishedAt, string $key): array
    {
        [$provider, $row, $payload, $existing, $claimedLink] = DB::transaction(function () use ($actor, $provider, $module, $source, $publishedAt, $key) {
            $current = OcpiProvider::whereKey($provider->id)->lockForUpdate()->firstOrFail();
            $this->admin($actor);
            [$contract, $link] = $this->authority($current);
            abort_unless($this->pushEnabled($current) && is_string($current->endpoints[$module.'_receiver'] ?? null), 409, 'Approved sandbox receiver is unavailable.');
            $row = $this->bound($current, $link)->where(['module' => $module, 'source_id' => $source, 'published_at' => $publishedAt])->first();
            abort_unless($row, 409, 'Refresh the current publication before sending it.');
            abort_unless($this->payloadIntact($row), 409, 'Publication bytes require integrity review or explicit republication.');
            $payload = $this->decode($row);
            $operation = DB::table(self::OPERATIONS)->where('operation_key', $key)->first();
            abort_if($operation && ($operation->publication_id !== $row->id || $operation->published_at !== $publishedAt), 409);
            $operation ??= DB::table(self::OPERATIONS)->where(['publication_id' => $row->id, 'published_at' => $publishedAt])->first();
            if ($operation) {
                abort_unless($this->deliveryMatches($row, $operation, $current, $link), 409, 'Existing delivery has no matching immutable receipt authority.');

                return [$current, $row, $payload, $operation->state, $link];
            }
            abort_if($module === 'cdrs' && DB::table(self::OPERATIONS)->where('publication_id', $row->id)->exists(),
                409, 'An immutable CDR has already been dispatched to this partner.');
            if (! $row->withdrawn) {
                $fresh = $this->sourcePayload($current, $contract, $module, $source);
                abort_unless(hash_equals($row->source_hash, $this->normalizer->hash($fresh)), 409, 'Canonical catalog source changed. Review and publish again.');
            }
            DB::table(self::OPERATIONS)->insert([
                'publication_id' => $row->id, 'actor_id' => $actor->id, 'operation_key' => $key,
                'published_at' => $publishedAt, 'publication_digest' => $this->deliveryAuthorityDigest($row, $current, $link), 'state' => 'dispatching', 'created_at' => now(), 'updated_at' => now(),
            ]);

            return [$current, $row, $payload, null, $link];
        }, 3);
        if ($existing !== null) {
            return ['delivery' => $existing, 'published_at' => $publishedAt];
        }
        // The claim is committed before I/O. Hold the authority locks across the
        // one attempt, and never retry this transaction after transport.
        $state = DB::transaction(function () use ($actor, $provider, $row, $module, $source, $payload, $publishedAt, $key, $claimedLink) {
            $current = OcpiProvider::whereKey($provider->id)->lockForUpdate()->firstOrFail();
            $claim = DB::table(self::OPERATIONS)->where('operation_key', $key);
            $operation = (clone $claim)->lockForUpdate()->first();
            abort_unless($operation && $operation->publication_id === $row->id
                && $operation->actor_id === $actor->id && $operation->published_at === $publishedAt, 409);
            if ($operation->state !== 'dispatching') {
                return $operation->state;
            }
            // Keep a durable claim when its bytes no longer match; deleting
            // the claim would permit a replacement send of altered content.
            $stored = DB::table(self::TABLE)->where('id', $row->id)->lockForUpdate()->first();
            if (! $stored || ! $this->deliveryMatches($stored, $operation, $provider, $claimedLink)
                || ! hash_equals($this->deliveryDigest($row), $this->deliveryDigest($stored))) {
                $claim->update(['state' => 'unknown', 'updated_at' => now()]);

                return null;
            }
            try {
                $this->admin($actor);
                [$contract, $link] = $this->authority($current);
                abort_unless($this->pushEnabled($current) && is_string($current->endpoints[$module.'_receiver'] ?? null)
                    && $this->sameDeliveryAuthority($provider, $claimedLink, $current, $link, $module)
                    && $this->samePublication($current, $link, $row), 409);
                if (! $row->withdrawn) {
                    $fresh = $this->sourcePayload($current, $contract, $module, $source);
                    abort_unless(hash_equals($row->source_hash, $this->normalizer->hash($fresh)), 409);
                }
            } catch (OcpiException|HttpExceptionInterface) {
                $claim->delete();

                return null;
            }

            try {
                $method = match ($module) {
                    'cdrs' => 'POST',
                    'tariffs' => $row->withdrawn ? 'DELETE' : 'PUT',
                    default => 'PUT',
                };
                $this->partner->cpoCatalogRequest($current, $module, $method, $payload, $row->generation);
                $state = 'acknowledged';
            } catch (OcpiException|HttpExceptionInterface) {
                $state = 'unknown';
            }
            // A successful envelope does not certify changed local evidence.
            // Recheck the actual claim and publication before marking delivery.
            $receiptClaim = (clone $claim)->lockForUpdate()->first();
            if (! $receiptClaim || $receiptClaim->id !== $operation->id
                || $receiptClaim->publication_id !== $row->id || $receiptClaim->actor_id !== $actor->id
                || $receiptClaim->published_at !== $publishedAt || $receiptClaim->state !== 'dispatching') {
                return null;
            }
            try {
                $this->admin($actor);
                $current = OcpiProvider::whereKey($provider->id)->lockForUpdate()->firstOrFail();
                [, $receiptLink] = $this->authority($current);
                abort_unless($this->sameDeliveryAuthority($provider, $claimedLink, $current, $receiptLink, $module)
                    && $this->samePublication($current, $receiptLink, $row)
                    && $this->deliveryMatches($row, $receiptClaim, $current, $receiptLink), 409);
            } catch (OcpiException|HttpExceptionInterface) {
                $state = 'unknown';
            }
            $claim->where('id', $operation->id)->where('state', 'dispatching')->update(['state' => $state, 'updated_at' => now()]);

            return $state;
        });
        abort_unless($state !== null, 409, 'Publication source or authority changed before delivery.');

        return ['delivery' => $state, 'published_at' => $publishedAt];
    }

    public function reconcile(User $actor, OcpiProvider $provider, string $module, int $source, string $publishedAt, ?string $cdrReceipt = null): array
    {
        [$current, $row, $operation, $claimedLink] = DB::transaction(function () use ($actor, $provider, $module, $source, $publishedAt) {
            $current = OcpiProvider::whereKey($provider->id)->lockForUpdate()->firstOrFail();
            $this->admin($actor);
            [, $link] = $this->authority($current);
            abort_unless($this->pushEnabled($current) && is_string($current->endpoints[$module.'_receiver'] ?? null), 409);
            $row = $this->bound($current, $link)->where(['module' => $module, 'source_id' => $source, 'published_at' => $publishedAt])->first();
            abort_unless($row, 409, 'Refresh the current publication before reconciling it.');
            $operation = DB::table(self::OPERATIONS)->where(['publication_id' => $row->id, 'published_at' => $publishedAt])->lockForUpdate()->first();
            abort_unless($operation, 404);
            abort_unless($this->deliveryMatches($row, $operation, $current, $link), 409, 'Original delivery bytes or receiver authority are unavailable or changed.');
            abort_if($operation->state === 'dispatching' && CarbonImmutable::parse($operation->created_at, 'UTC')->greaterThan(now()->subMinute()), 409, 'Delivery is still within its response window.');

            return [$current, $row, $operation, $link];
        }, 3);
        if (in_array($operation->state, ['acknowledged', 'reconciled'], true)) {
            return ['delivery' => $operation->state, 'published_at' => $publishedAt];
        }
        abort_if($module === 'cdrs' && $cdrReceipt === null, 409, 'An uncertain CDR delivery requires a peer-assigned receipt.');
        $payload = $this->decode($row);
        $deleted = $module === 'tariffs' && $row->withdrawn;
        $verified = false;
        try {
            $evidence = $this->partner->cpoCatalogRequest($current, $module, 'GET', $payload, $row->generation, $deleted, $cdrReceipt);
            $verified = $deleted
                ? $evidence['status_code'] === 2001 && array_key_exists('data', $evidence) && $evidence['data'] === null
                : is_array($evidence['data'] ?? null) && hash_equals($this->normalizer->hash($payload, preserveZeroFraction: false), $this->normalizer->hash($evidence['data'], preserveZeroFraction: false));
        } catch (OcpiException|HttpExceptionInterface) {
            // An unavailable or mismatched source is still an unknown delivery.
        }

        return DB::transaction(function () use ($actor, $current, $row, $operation, $verified, $publishedAt, $claimedLink, $module) {
            $provider = OcpiProvider::whereKey($current->id)->lockForUpdate()->firstOrFail();
            $this->admin($actor);
            [, $link] = $this->authority($provider);
            abort_unless($this->sameDeliveryAuthority($current, $claimedLink, $provider, $link, $module)
                && $this->samePublication($provider, $link, $row), 409);
            $currentOperation = DB::table(self::OPERATIONS)->where('id', $operation->id)->lockForUpdate()->first();
            abort_unless($currentOperation && $currentOperation->publication_id === $row->id
                && $currentOperation->operation_key === $operation->operation_key
                && $currentOperation->actor_id === $operation->actor_id
                && $currentOperation->published_at === $publishedAt
                && $this->deliveryMatches($row, $currentOperation, $provider, $link), 409);
            $updated = DB::table(self::OPERATIONS)->where('id', $operation->id)->whereIn('state', ['dispatching', 'unknown'])
                ->update(['state' => $verified ? 'reconciled' : 'unknown', 'updated_at' => now()]);
            if ($verified && $updated === 1) {
                DB::table('audit_events')->insert([
                    'actor_id' => $actor->id, 'action' => 'ocpi.cpo_reconciled',
                    'subject' => (string) $operation->id, 'created_at' => now(),
                ]);
            }

            return ['delivery' => DB::table(self::OPERATIONS)->where('id', $operation->id)->value('state'), 'published_at' => $publishedAt];
        }, 3);
    }
}
