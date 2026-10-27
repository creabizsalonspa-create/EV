<?php

namespace App\Http\Controllers;

use App\Models\Charging\AdmissionProfile;
use App\Models\VendorOps\VendorCharger;
use App\Models\VendorOps\VendorCompany;
use App\Services\VendorOps\VendorAccess;
use Illuminate\Cache\DatabaseStore;
use Illuminate\Cache\RedisStore;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
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
        abort_unless(ctype_digit($ts) && abs(time() - (int) $ts) <= 60 && preg_match('/^[a-f0-9-]{36}$/', $nonce) && strlen($signature) === 64 && hash_equals(hash_hmac('sha256', $ts."\n".$nonce."\n".$authorization."\n".$r->getContent(), $key), $signature), 401);
        abort_unless(Cache::add('charger-ingress:'.hash('sha256', $nonce), true, 125), 401);
        $v = $r->validate(['identity' => 'required|string|max:100', 'protocol' => 'required|in:1.6,2.0.1', 'tls' => 'required|accepted', 'certificate_sha256' => ['nullable', 'regex:/^[a-f0-9]{64}$/']]);
        $p = AdmissionProfile::where('identity', $v['identity'])->first();
        $decoded = str_starts_with($authorization, 'Basic ') ? base64_decode(substr($authorization, 6), true) : false;
        $parts = $decoded === false ? [] : explode(':', $decoded, 2);
        abort_unless($p && count($parts) === 2 && hash_equals($p->identity, $parts[0]) && $p->enabled && $p->revoked_at === null && $p->expires_at?->isFuture() && $p->protocol === $v['protocol'] && strlen($parts[1]) === 40 && hash_equals($p->credential_digest ?? '', hash('sha256', $parts[1])), 401);
        abort_if($p->certificate_sha256 !== null && ! hash_equals($p->certificate_sha256, $v['certificate_sha256'] ?? ''), 401);
        $charger = VendorCharger::findOrFail($p->charger_id);
        $company = VendorCompany::findOrFail($charger->company_id);
        abort_unless($charger->ocpp_protocol === $p->protocol && $company->status === 'approved' && app(VendorAccess::class)->licenseValid($company), 401);

        // Handshake admission is not source registration, live status or command authority.
        return response('', 204)->header('Cache-Control', 'no-store');
    }
}
