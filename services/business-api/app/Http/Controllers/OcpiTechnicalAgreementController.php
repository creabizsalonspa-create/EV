<?php

namespace App\Http\Controllers;

use App\Http\Middleware\AdminSecondFactorGate;
use App\Models\OcpiProvider;
use App\Services\Ocpi\AdminCredentialApproval;
use App\Services\Ocpi\OcpiException;
use App\Services\Ocpi\TechnicalAgreement;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class OcpiTechnicalAgreementController extends Controller
{
    private function admin(Request $r): void
    {
        $current = app(AdminCredentialApproval::class)->current($r->user(), DB::transactionLevel() > 0);
        abort_if(AdminSecondFactorGate::required() && ! AdminSecondFactorGate::verified($r, $current), 403);
    }

    public function show(Request $r, OcpiProvider $provider, TechnicalAgreement $service)
    {
        $this->admin($r);
        abort_unless((int) $provider->id > 0 && (int) $provider->id <= 9007199254740991, 404);
        $v = $r->validate(['page' => 'sometimes|integer|min:1|max:100000']);

        return DB::transaction(function () use ($r, $provider, $service) {
            $p = OcpiProvider::whereKey($provider->id)->lockForUpdate()->firstOrFail();
            $this->admin($r);
            abort_unless((int) $provider->id > 0 && (int) $provider->id <= 9007199254740991, 404);
            $head = DB::table('ocpi_technical_agreement_heads')->where('provider_id', $p->id)->first();
            $identity = null;
            try {
                $identity = $service->identity($p);
            } catch (OcpiException|HttpExceptionInterface) {
            }
            $fingerprint = null;
            try {
                $fingerprint = $service->fingerprint($p);
            } catch (OcpiException|HttpExceptionInterface) {
            }
            $page = DB::table('ocpi_technical_agreements')->where('provider_id', $p->id)->orderByDesc('revision')->paginate(10);
            $page->getCollection()->transform(function ($a) use ($head, $fingerprint) {
                abort_unless((int) $a->id > 0 && (int) $a->id <= 9007199254740991, 409, 'Retained agreement identity requires review.');
                $text = Crypt::decryptString($a->payload_ciphertext);
                abort_unless(hash_equals($a->payload_hash, hash('sha256', $text)), 409, 'Agreement history requires integrity review.');
                $payload = json_decode($text, true, flags: JSON_THROW_ON_ERROR);
                $events = DB::table('ocpi_technical_agreement_events')->where('agreement_id', $a->id)->orderBy('id')->get();
                $state = 'proposed';
                foreach ($events as $event) {
                    $state = $event->action;
                }

                return ['id' => $a->id, 'revision' => $a->revision, 'request_key' => $a->request_key, 'configuration_current' => $fingerprint !== null && hash_equals($a->configuration_hash, $fingerprint), 'reference' => $payload['reference'],
                    'evidence_sha256' => $payload['evidence_sha256'], 'terms_en' => $payload['terms_en'], 'terms_ar' => $payload['terms_ar'],
                    'allowed_modules' => $payload['allowed_modules'], 'valid_from' => $payload['valid_from'], 'valid_until' => $payload['valid_until'],
                    'state' => $state, 'current' => $head?->active_agreement_id === $a->id,
                    'created_at' => $a->created_at,
                    'decisions' => $events->map(fn ($e) => ['action' => $e->action, 'request_key' => $e->request_key, 'reason' => Crypt::decryptString($e->reason_ciphertext), 'created_at' => $e->created_at])->all()];
            });

            // Hashes and bilateral party labels only: no endpoints, tokens or server aliases.
            return response()->json(['provider_id' => $p->id, 'revision' => $head?->revision ?? 0,
                'required' => $service->required($p), 'configured_identity' => $identity === null ? null : array_intersect_key($identity, array_flip(['peer_country', 'peer_party', 'peer_role', 'local_country', 'local_party', 'local_role', 'version'])),
                'active_agreement_id' => $service->active($p)?->id,
                'agreements' => $page->toArray(), 'external_requests_made' => false,
                'partner_acceptance_verified' => false, 'commands_enabled' => false, 'billing_eligible' => false])->header('Cache-Control', 'private, no-store');
        });
    }

    public function propose(Request $r, OcpiProvider $provider, TechnicalAgreement $service)
    {
        $this->admin($r);
        abort_unless((int) $provider->id > 0 && (int) $provider->id <= 9007199254740991, 404);
        $v = $r->validate(['request_key' => 'required|uuid', 'expected_revision' => 'required|integer|min:0|max:2147483647',
            'reference' => 'required|string|min:3|max:120', 'evidence_sha256' => 'required|regex:/^[a-f0-9]{64}$/D',
            'terms_en' => 'required|string|min:20|max:6000', 'terms_ar' => 'required|string|min:20|max:6000',
            'allowed_modules' => 'required|array|min:1|max:4', 'allowed_modules.*' => 'required|string|distinct|in:locations,tariffs,sessions,cdrs',
            'valid_from' => 'required|date_format:Y-m-d\TH:i:s\Z',
            'valid_until' => 'required|date_format:Y-m-d\TH:i:s\Z|after:valid_from|after:now']);
        $v['expected_revision'] = (int) $v['expected_revision'];
        try {
            $id = $service->propose($provider, $r->user(), $v);
        } catch (OcpiException) {
            abort(409, 'Approved managed OCPI identity is not configured.');
        }

        return response()->json(['agreement_id' => $id, 'external_requests_made' => false, 'provider_activated' => false], 201)->header('Cache-Control', 'private, no-store');
    }

    public function decide(Request $r, OcpiProvider $provider, string $agreement, TechnicalAgreement $service)
    {
        $this->admin($r);
        abort_unless((int) $provider->id > 0 && (int) $provider->id <= 9007199254740991, 404);
        abort_unless((float) $agreement <= 9007199254740991, 404);
        $v = $r->validate(['request_key' => 'required|uuid', 'expected_revision' => 'required|integer|min:1|max:2147483647',
            'action' => 'required|in:approved,rejected,revoked', 'reason' => 'required|string|min:10|max:2000']);
        $v['expected_revision'] = (int) $v['expected_revision'];
        try {
            $id = $service->decide($provider, $r->user(), (int) $agreement, $v);
        } catch (OcpiException) {
            abort(409, 'Current managed OCPI identity must be reviewed again.');
        }

        return response()->json(['agreement_id' => $id, 'external_requests_made' => false, 'provider_activated' => false, 'credentials_require_separate_review' => in_array($v['action'], ['approved', 'revoked'], true)])->header('Cache-Control', 'private, no-store');
    }
}
