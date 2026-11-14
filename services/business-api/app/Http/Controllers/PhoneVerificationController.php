<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Identity\PhoneVerificationProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class PhoneVerificationController extends Controller
{
    private function account(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User && in_array($actor->role, ['customer', 'vendor'], true), 403);
        $account = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
        abort_unless($account->account_status === 'active' && $account->role === $actor->role
            && (int) $account->auth_epoch === (int) $actor->auth_epoch, 403);

        return $account;
    }

    private function password(User $account, string $password): void
    {
        if (! Hash::check($password, $account->password)) {
            throw ValidationException::withMessages(['password' => 'Unable to verify account credentials.']);
        }
    }

    private function audit(User $account, string $action): void
    {
        DB::table('audit_events')->insert(['actor_id' => $account->id, 'action' => $action, 'subject' => (string) $account->id, 'created_at' => now()]);
    }

    private function reply(object $challenge)
    {
        return response()->json(['id' => $challenge->id, 'status' => $challenge->status,
            'expires_at' => $challenge->expires_at, 'attempts_remaining' => max(0, 5 - $challenge->attempts)], 202)->header('Cache-Control', 'no-store, private');
    }

    public function show(Request $request, PhoneVerificationProvider $provider)
    {
        abort_unless(in_array($request->user()->role, ['customer', 'vendor'], true), 403);
        $account = $request->user()->fresh();
        $phone = $account->phone_e164;
        $pending = DB::table('account_phone_challenges')->where('user_id', $account->id)
            ->where('auth_epoch', $account->auth_epoch)->whereIn('status', ['pending', 'uncertain'])
            ->where('expires_at', '>', now())->whereNotNull('phone_ciphertext')->orderByDesc('created_at')->first();

        return response()->json(['available' => $provider->enabled(), 'verified' => $phone !== null && $account->phone_verified_at !== null,
            'phone_mask' => $phone === null ? null : '••••'.substr($phone, -4),
            'verified_at' => $account->phone_verified_at, 'login_factor' => false,
            'challenge' => $pending ? ['id' => $pending->id, 'status' => $pending->status,
                'expires_at' => $pending->expires_at, 'attempts_remaining' => max(0, 5 - $pending->attempts)] : null])
            ->header('Cache-Control', 'no-store, private');
    }

    public function start(Request $request, PhoneVerificationProvider $provider)
    {
        abort_unless($provider->enabled(), 503, 'Phone verification delivery is unavailable.');
        $v = $request->validate(['phone' => ['required', 'string', 'regex:/^\+[1-9][0-9]{7,14}$/D'],
            'password' => 'required|string|max:128', 'consent' => 'required|accepted',
            'locale' => 'required|in:en,ar', 'idempotency_key' => 'required|string|min:16|max:100']);

        return DB::transaction(function () use ($request, $provider, $v) {
            $account = $this->account($request);
            $this->password($account, $v['password']);
            $phoneHash = hash_hmac('sha256', $v['phone'], config('app.key'));
            $old = DB::table('account_phone_challenges')->where('user_id', $account->id)->where('idempotency_key', $v['idempotency_key'])->first();
            if ($old) {
                abort_unless(hash_equals($old->phone_hash, $phoneHash), 409, 'Request identity changed.');

                return $this->reply($old);
            }
            $recent = DB::table('account_phone_challenges')->where('user_id', $account->id);
            abort_if((clone $recent)->where('created_at', '>', now()->subMinute())->exists()
                || (clone $recent)->where('created_at', '>', now()->subHour())->count() >= 5, 429, 'Wait before requesting another verification.');
            abort_if(RateLimiter::hit('phone-verification:'.$phoneHash, 3600) > 3, 429, 'Phone delivery request limit reached.');
            $recent->whereIn('status', ['pending', 'uncertain'])->update(['status' => 'superseded', 'phone_ciphertext' => null, 'updated_at' => now()]);
            $id = (string) Str::uuid();
            DB::table('account_phone_challenges')->insert(['id' => $id, 'user_id' => $account->id,
                'idempotency_key' => $v['idempotency_key'], 'phone_hash' => $phoneHash,
                'phone_ciphertext' => Crypt::encryptString($v['phone']), 'auth_epoch' => $account->auth_epoch,
                'status' => 'uncertain', 'expires_at' => now()->addMinutes(10), 'created_at' => now(), 'updated_at' => now()]);
            // Bounded provider call under the account lock serializes replacements,
            // revocation and confirmation. Uncertain delivery is never auto-retried.
            try {
                $sid = $provider->start($v['phone'], $v['locale']);
                DB::table('account_phone_challenges')->where('id', $id)->update(['provider_sid' => $sid, 'status' => 'pending']);
            } catch (\Throwable) {
                // Do not log provider bodies, phone, credentials or OTPs.
            }
            $this->audit($account, 'account.phone_verification.requested');

            return $this->reply(DB::table('account_phone_challenges')->where('id', $id)->first());
        });
    }

    public function confirm(Request $request, string $challenge, PhoneVerificationProvider $provider)
    {
        abort_unless($provider->enabled(), 503, 'Phone verification delivery is unavailable.');
        $v = $request->validate(['code' => ['required', 'string', 'regex:/^[0-9]{4,10}$/D']]);

        return DB::transaction(function () use ($request, $provider, $challenge, $v) {
            $account = $this->account($request);
            $row = DB::table('account_phone_challenges')->where('id', $challenge)->where('user_id', $account->id)->lockForUpdate()->first();
            abort_unless($row, 404);
            abort_unless($row->status === 'pending' && $row->attempts < 5
                && $row->expires_at > now()->toDateTimeString() && (int) $row->auth_epoch === (int) $account->auth_epoch, 409, 'Verification is no longer active.');
            $attempts = $row->attempts + 1;
            DB::table('account_phone_challenges')->where('id', $row->id)->update(['attempts' => $attempts, 'updated_at' => now()]);
            $approved = false;
            $uncertain = false;
            try {
                $phone = Crypt::decryptString($row->phone_ciphertext);
                $approved = $provider->check($row->provider_sid, $phone, $v['code']);
            } catch (\Throwable) {
                $uncertain = true;
            }
            $expired = $row->expires_at <= now()->toDateTimeString();
            $approved = $approved && ! $expired;
            $status = $expired ? 'expired' : ($approved ? 'approved' : ($attempts >= 5 ? 'locked' : 'pending'));
            DB::table('account_phone_challenges')->where('id', $row->id)->update(['status' => $status,
                'phone_ciphertext' => in_array($status, ['approved', 'locked', 'expired'], true) ? null : $row->phone_ciphertext]);
            if ($approved) {
                $account->forceFill(['phone_e164' => $phone, 'phone_verified_at' => now()])->save();
                $this->audit($account, 'account.phone_verified');
            }

            return response()->json(['verified' => $approved, 'status' => $status,
                'check_uncertain' => $uncertain, 'attempts_remaining' => max(0, 5 - $attempts)])
                ->header('Cache-Control', 'no-store, private');
        });
    }

    public function remove(Request $request)
    {
        $v = $request->validate(['password' => 'required|string|max:128']);

        return DB::transaction(function () use ($request, $v) {
            $account = $this->account($request);
            $this->password($account, $v['password']);
            $account->forceFill(['phone_e164' => null, 'phone_verified_at' => null])->save();
            DB::table('account_phone_challenges')->where('user_id', $account->id)->update(['status' => 'removed', 'phone_ciphertext' => null, 'updated_at' => now()]);
            $this->audit($account, 'account.phone_removed');

            return response()->noContent()->header('Cache-Control', 'no-store, private');
        });
    }
}
