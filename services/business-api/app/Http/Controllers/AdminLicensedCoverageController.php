<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Discovery\LicensedCoverageHistory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class AdminLicensedCoverageController extends Controller
{
    public function show(Request $request, LicensedCoverageHistory $history)
    {
        $snapshot = $request->user();
        abort_unless($snapshot instanceof User && $snapshot->role === 'admin', 403);
        [$report, $locale] = DB::transaction(function () use ($snapshot, $history) {
            $actor = User::whereKey($snapshot->id)->lockForUpdate()->firstOrFail();
            abort_unless($actor->role === 'admin' && $actor->account_status === 'active', 403);
            abort_unless((int) $actor->auth_epoch === (int) $snapshot->auth_epoch
                && hash_equals((string) $actor->password, (string) $snapshot->password), 409, 'Account credentials changed; authenticate again.');

            return [$history->report(), $actor->locale === 'ar' ? 'ar' : 'en'];
        }, 3);

        return response()->view('admin-licensed-coverage', ['report' => $report, 'locale' => $locale])
            ->header('Cache-Control', 'private, no-store')
            ->header('Referrer-Policy', 'no-referrer');
    }
}
