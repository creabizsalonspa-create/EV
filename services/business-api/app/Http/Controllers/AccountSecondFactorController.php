<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Security\AdminTotp;
use App\Services\Identity\AccountSecondFactor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

final class AccountSecondFactorController extends Controller
{
    private function password(Request $request, AccountSecondFactor $service, array $v): User
    {
        $user = $service->account($request->user());
        if (! Hash::check($v['password'], $user->password)) {
            throw ValidationException::withMessages(['password' => 'Unable to verify account credentials.']);
        }

        return $user;
    }

    private function audit(User $user, string $action): void
    {
        DB::table('audit_events')->insert(['actor_id' => $user->id, 'action' => $action, 'subject' => (string) $user->id, 'created_at' => now()]);
    }

    private function currentBrowser(Request $request, User $user): void
    {
        if ($request->hasSession()) {
            Auth::guard('web')->setUser($user);
            $request->session()->regenerate();
            $request->session()->put('account_auth_epoch', (int) $user->auth_epoch);
        }
    }

    public function status(Request $request)
    {
        abort_unless(in_array($request->user()->role, ['customer', 'vendor'], true), 403);
        $row = DB::table('account_second_factors')->where('user_id', $request->user()->id)->first();
        $hashes = $row?->recovery_hashes === null ? [] : json_decode(Crypt::decryptString($row->recovery_hashes), true, 512, JSON_THROW_ON_ERROR);

        return response()->json(['enabled' => $row?->secret !== null, 'recovery_remaining' => count($hashes),
            'pending' => $row?->pending_secret !== null && $row->pending_until > now()->toDateTimeString()
                && (int) $row->pending_epoch === (int) $request->user()->auth_epoch])
            ->header('Cache-Control', 'no-store, private');
    }

    public function prepare(Request $request, AccountSecondFactor $service)
    {
        $v = $request->validate(['password' => 'required|string|max:128']);

        return DB::transaction(function () use ($request, $service, $v) {
            $user = $this->password($request, $service, $v);
            $old = DB::table('account_second_factors')->where('user_id', $user->id)->lockForUpdate()->first();
            abort_if($old?->secret !== null, 409, 'Authenticator is already enabled.');
            $secret = AdminTotp::generateSecret();
            DB::table('account_second_factors')->updateOrInsert(['user_id' => $user->id], [
                'pending_secret' => Crypt::encryptString($secret), 'pending_until' => now()->addMinutes(10),
                'pending_epoch' => $user->auth_epoch, 'created_at' => $old?->created_at ?? now(), 'updated_at' => now(),
            ]);
            $this->audit($user, 'account.second_factor.prepared');

            return response()->json(['secret' => $secret, 'provisioning_uri' => AdminTotp::provisioningUri($secret, $user->email), 'expires_in_seconds' => 600])
                ->header('Cache-Control', 'no-store, private');
        });
    }

    public function enable(Request $request, AccountSecondFactor $service)
    {
        $v = $request->validate(['password' => 'required|string|max:128', 'code' => 'required|string|regex:/^[0-9]{6}$/D']);

        return DB::transaction(function () use ($request, $service, $v) {
            $user = $this->password($request, $service, $v);
            $row = DB::table('account_second_factors')->where('user_id', $user->id)->lockForUpdate()->first();
            abort_unless($row && $row->secret === null && $row->pending_secret !== null
                && $row->pending_until > now()->toDateTimeString() && (int) $row->pending_epoch === (int) $user->auth_epoch, 409, 'Restart authenticator setup.');
            $counter = AdminTotp::matchingStep(Crypt::decryptString($row->pending_secret), $v['code'], now()->timestamp);
            if ($counter === null) {
                throw ValidationException::withMessages(['code' => 'Invalid authenticator code.']);
            }
            $codes = $service->recoveryCodes();
            DB::table('account_second_factors')->where('user_id', $user->id)->update([
                'secret' => $row->pending_secret, 'last_counter' => $counter, 'recovery_hashes' => $service->encryptedHashes($codes),
                'pending_secret' => null, 'pending_until' => null, 'pending_epoch' => null, 'updated_at' => now(),
            ]);
            $service->rotateAuthority($user);
            $this->currentBrowser($request, $user);
            $this->audit($user, 'account.second_factor.enabled');

            return response()->json(['enabled' => true, 'recovery_codes' => $codes, 'reauthentication_required' => ! $request->hasSession()])
                ->header('Cache-Control', 'no-store, private');
        });
    }

    public function change(Request $request, string $action, AccountSecondFactor $service)
    {
        $v = $request->validate(['password' => 'required|string|max:128', 'code' => 'required|string|max:128']);

        return DB::transaction(function () use ($request, $service, $v, $action) {
            $user = $this->password($request, $service, $v);
            $factor = DB::table('account_second_factors')->where('user_id', $user->id)->lockForUpdate()->first();
            abort_unless($factor?->secret !== null, 409, 'Authenticator is not enabled.');
            $service->consume($user, $v['code']);
            $codes = [];
            if ($action === 'disable') {
                DB::table('account_second_factors')->where('user_id', $user->id)->delete();
            } else {
                $codes = $service->recoveryCodes();
                DB::table('account_second_factors')->where('user_id', $user->id)->update(['recovery_hashes' => $service->encryptedHashes($codes), 'updated_at' => now()]);
            }
            $service->rotateAuthority($user);
            $this->currentBrowser($request, $user);
            $this->audit($user, 'account.second_factor.'.$action);

            return response()->json(['enabled' => $action !== 'disable', 'recovery_codes' => $codes,
                'reauthentication_required' => ! $request->hasSession()])->header('Cache-Control', 'no-store, private');
        });
    }
}
