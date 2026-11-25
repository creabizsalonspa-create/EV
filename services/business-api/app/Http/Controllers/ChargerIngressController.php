<?php

namespace App\Http\Controllers;

use App\Models\Charging\AdmissionProfile;
use App\Models\User;
use App\Models\VendorOps\VendorCharger;
use App\Models\VendorOps\VendorCompany;
use App\Services\Charging\AdmissionIdentity;
use App\Services\Charging\ChargerCertificates;
use App\Services\VendorOps\VendorAccess;
use Illuminate\Cache\DatabaseStore;
use Illuminate\Cache\RedisStore;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\IpUtils;

class ChargerIngressController extends Controller
{
    public function authenticate(Request $r)
    {
        abort_unless(config('csms.ingress_enabled'), 503);
        abort_unless($r->isSecure() || (config('csms.ingress_allow_local_http') && app()->environment(['local', 'testing']) && IpUtils::checkIp($r->ip(), ['127.0.0.0/8', '172.16.0.0/12', '10.0.0.0/8', '192.168.0.0/16', '::1'])), 503);
        abort_if(strlen($r->getContent()) > 2048, 413);
        $key = (string) config('csms.ingress_secret');
        abort_unless(strlen($key) >= 32, 503);
        if (app()->environment('production')) {
            abort_if(! (Cache::getStore() instanceof DatabaseStore) && ! (Cache::getStore() instanceof RedisStore), 503);
        }
        $ts = $r->header('X-EV-Ingress-Time', '');
        $nonce = $r->header('X-EV-Ingress-Nonce', '');
        $signature = $r->header('X-EV-Ingress-Signature', '');
        $authorization = $r->header('Authorization', '');
        abort_unless(ctype_digit($ts) && abs(now()->timestamp - (int) $ts) <= 60 && preg_match('/^[a-f0-9-]{36}$/', $nonce) && strlen($signature) === 64 && hash_equals(hash_hmac('sha256', $ts."\n".$nonce."\n".$authorization."\n".$r->getContent(), $key), $signature), 401);
        abort_unless(Cache::add('charger-ingress:'.hash('sha256', $nonce), true, 125), 401);
        $v = $r->validate(['identity' => 'required|string|max:100', 'protocol' => 'required|in:1.6,2.0.1', 'tls' => 'required|accepted', 'certificate_sha256' => ['nullable', 'regex:/^[a-f0-9]{64}$/']]);
        $hint = AdmissionProfile::where('identity', $v['identity'])->first();
        $chargerHint = $hint ? VendorCharger::find($hint->charger_id) : null;
        $decoded = str_starts_with($authorization, 'Basic ') ? base64_decode(substr($authorization, 6), true) : false;
        $parts = $decoded === false ? [] : explode(':', $decoded, 2);
        abort_unless($hint && $chargerHint && count($parts) === 2 && strlen($parts[1]) === 40, 401);

        // Serialize admission with certificate enrollment/revocation and
        // credential rotation. The initial lookup is only a lock-order hint.
        DB::transaction(function () use ($hint, $chargerHint, $v, $parts, $key, $r, $ts) {
            $company = VendorCompany::whereKey($chargerHint->company_id)->lockForUpdate()->first();
            abort_unless($company, 401);
            $owner = User::whereKey($company->owner_id)->lockForUpdate()->first();
            $charger = VendorCharger::whereKey($chargerHint->id)->lockForUpdate()->first();
            $p = AdmissionProfile::whereKey($hint->id)->lockForUpdate()->first();
            abort_unless($p && $charger && $p->charger_id === $charger->id
                && $charger->company_id === $company->id && $p->identity === $v['identity']
                && hash_equals($p->identity, $parts[0]) && $p->enabled
                && $p->revoked_at === null && $p->expires_at?->isFuture()
                && $p->protocol === $v['protocol']
                && hash_equals($p->credential_digest ?? '', hash('sha256', $parts[1])), 401);
            abort_if($p->certificate_sha256 !== null && ! hash_equals($p->certificate_sha256, $v['certificate_sha256'] ?? ''), 401);
            abort_unless($charger->ocpp_protocol === $p->protocol && $company->status === 'approved'
                && app(VendorAccess::class)->licenseValid($company)
                && $owner && $owner->role === 'vendor' && $owner->account_status === 'active', 401);
            abort_unless(app(AdmissionIdentity::class)->compatible($company, $charger, $p->identity, $p->protocol, app()->environment('production')), 401);
            try {
                app(ChargerCertificates::class)->usable($p);
            } catch (\Throwable) {
                abort(401);
            }
            // A signed request can wait for row locks or certificate validation.
            // Recheck the signed clock and credential deadline at the last
            // authorization boundary; a formerly valid receipt is not current.
            abort_unless(abs(now()->timestamp - (int) $ts) <= 60
                && $p->expires_at?->isFuture(), 401);
            abort_unless(config('csms.ingress_enabled')
                && hash_equals($key, (string) config('csms.ingress_secret'))
                && ($r->isSecure() || (config('csms.ingress_allow_local_http')
                    && app()->environment(['local', 'testing'])
                    && IpUtils::checkIp($r->ip(), ['127.0.0.0/8', '172.16.0.0/12', '10.0.0.0/8', '192.168.0.0/16', '::1']))), 503);
        }, 3);

        // Handshake admission is not source registration, live status or command authority.
        return response('', 204)->header('Cache-Control', 'no-store');
    }
}
