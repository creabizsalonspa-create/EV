<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\VendorOps\VendorCompany;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Versioned agreement evidence only. Never grants operational or financial authority. */
class VendorAgreementController extends Controller
{
    private function actor(Request $r): User
    {
        $user = User::whereKey($r->user()->id)->lockForUpdate()->firstOrFail();
        abort_unless($user->account_status === 'active' && $user->role === $r->user()->role
            && (int) $user->auth_epoch === (int) $r->user()->auth_epoch
            && hash_equals((string) $user->password, (string) $r->user()->password), 409, 'Account credentials changed; sign in again.');

        return $user;
    }

    private function company(User $actor, VendorCompany $company): VendorCompany
    {
        $company = VendorCompany::whereKey($company->id)->lockForUpdate()->firstOrFail();
        abort_unless($actor->role === 'admin' || ($company->owner_id === $actor->id && in_array($actor->role, ['customer', 'vendor'], true)), 404);

        return $company;
    }

    private function audit(User $actor, string $action, string $subject): void
    {
        DB::table('audit_events')->insert(['actor_id' => $actor->id, 'action' => 'vendor_agreement.'.$action, 'subject' => $subject, 'created_at' => now()]);
    }

    private function reply(array $body, int $status = 200)
    {
        return response()->json($body + ['charging_authority_granted' => false, 'payment_authority_granted' => false, 'legal_acceptance_verified' => false], $status)->header('Cache-Control', 'private, no-store');
    }

    private function present(?object $version): ?array
    {
        return $version ? Arr::only((array) $version, ['id', 'country', 'revision', 'title_en', 'title_ar', 'body_en', 'body_ar', 'publication_reference', 'sha256', 'created_at']) : null;
    }

    public function show(Request $r, VendorCompany $company)
    {
        $r->validate(['page' => 'sometimes|integer|min:1|max:100000']);

        return DB::transaction(function () use ($r, $company) {
            $actor = $this->actor($r);
            $company = $this->company($actor, $company);
            $channel = DB::table('vendor_agreement_channels')->where('country', $company->country)->first();
            $version = $channel?->version_id ? DB::table('vendor_agreement_versions')->where('id', $channel->version_id)->first() : null;
            $receipt = $version ? DB::table('vendor_agreement_acceptances')->where('company_id', $company->id)->where('actor_id', $company->owner_id)->where('version_id', $version->id)->first(['id', 'version_id', 'version_sha256', 'created_at']) : null;

            return $this->reply(['country' => $company->country, 'revision' => $channel->revision ?? 0, 'version' => $this->present($version),
                'acceptance' => $receipt, 'can_accept' => $actor->id === $company->owner_id && $version !== null && $receipt === null,
                'can_publish' => $actor->role === 'admin', 'history' => DB::table('vendor_agreement_acceptances as a')->join('vendor_agreement_versions as v', 'v.id', '=', 'a.version_id')->where('a.company_id', $company->id)->orderByDesc('a.created_at')->orderByDesc('a.id')->paginate(25, ['a.id', 'a.version_id', 'a.version_sha256', 'a.created_at', 'v.revision', 'v.country', 'v.title_en', 'v.title_ar'])->toArray()]);
        });
    }

    public function publish(Request $r, VendorCompany $company)
    {
        abort_unless($r->user()->role === 'admin', 403);
        $v = $r->validate(['password' => 'required|string|max:128', 'expected_revision' => 'required|integer|min:0|max:2147483646',
            'title_en' => 'required|string|max:200', 'title_ar' => 'required|string|max:200', 'body_en' => 'required|string|min:20|max:32000', 'body_ar' => 'required|string|min:20|max:32000',
            'publication_reference' => 'required|string|max:100', 'approved_for_publication' => 'required|accepted']);

        return DB::transaction(function () use ($r, $company, $v) {
            $actor = $this->actor($r);
            abort_unless($actor->role === 'admin', 403);
            if (! Hash::check($v['password'], $actor->password)) {
                throw ValidationException::withMessages(['password' => 'Current password is required.']);
            }
            $company = $this->company($actor, $company);
            DB::table('vendor_agreement_channels')->insertOrIgnore(['country' => $company->country, 'revision' => 0]);
            $channel = DB::table('vendor_agreement_channels')->where('country', $company->country)->lockForUpdate()->first();
            // A retry of the exact published document recovers its receipt; no duplicate version.
            $revision = (int) $v['expected_revision'] + 1;
            $content = ['country' => $company->country, 'revision' => $revision];
            foreach (['title_en', 'title_ar', 'body_en', 'body_ar', 'publication_reference'] as $field) {
                $content[$field] = $v[$field];
            }
            $hash = hash('sha256', json_encode($content, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            if ($channel->revision !== (int) $v['expected_revision']) {
                $existing = DB::table('vendor_agreement_versions')->where('id', $channel->version_id)->first();
                abort_unless($existing && $channel->revision === $revision && hash_equals($existing->sha256, $hash), 409, 'A newer agreement was published. Refresh and review it.');

                return $this->reply(['version_id' => $existing->id, 'revision' => $revision, 'sha256' => $hash]);
            }
            $id = (string) Str::uuid();
            DB::table('vendor_agreement_versions')->insert($content + ['id' => $id, 'sha256' => $hash, 'published_by' => $actor->id, 'created_at' => now()]);
            DB::table('vendor_agreement_channels')->where('country', $company->country)->update(['version_id' => $id, 'revision' => $revision]);
            $this->audit($actor, 'published', $id);

            return $this->reply(['version_id' => $id, 'revision' => $revision, 'sha256' => $hash], 201);
        }, 3);
    }

    public function accept(Request $r, VendorCompany $company)
    {
        $v = $r->validate(['version_id' => 'required|uuid', 'sha256' => 'required|string|regex:/^[a-f0-9]{64}$/D', 'accepted' => 'required|accepted']);

        return DB::transaction(function () use ($r, $company, $v) {
            $actor = $this->actor($r);
            $company = $this->company($actor, $company);
            abort_unless($company->owner_id === $actor->id && in_array($actor->role, ['customer', 'vendor'], true), 403);
            $channel = DB::table('vendor_agreement_channels')->where('country', $company->country)->lockForUpdate()->first();
            $version = DB::table('vendor_agreement_versions')->where('id', $v['version_id'])->where('country', $company->country)->first();
            abort_unless($channel && $channel->version_id === $v['version_id'] && $version && hash_equals($version->sha256, $v['sha256']), 409, 'Agreement changed. Refresh and explicitly review the current version.');
            $existing = DB::table('vendor_agreement_acceptances')->where('company_id', $company->id)->where('actor_id', $actor->id)->where('version_id', $version->id)->first();
            if ($existing) {
                return $this->reply(['id' => $existing->id, 'version_id' => $version->id, 'sha256' => $version->sha256, 'accepted_at' => $existing->created_at]);
            }
            $id = (string) Str::uuid();
            DB::table('vendor_agreement_acceptances')->insert(['id' => $id, 'company_id' => $company->id, 'actor_id' => $actor->id, 'version_id' => $version->id, 'version_sha256' => $version->sha256,
                'company_snapshot_encrypted' => Crypt::encryptString(json_encode(['legal_name' => $company->legal_name, 'country' => $company->country, 'owner_id' => $company->owner_id], JSON_THROW_ON_ERROR)), 'created_at' => now()]);
            $this->audit($actor, 'accepted', $id);

            return $this->reply(['id' => $id, 'version_id' => $version->id, 'sha256' => $version->sha256, 'accepted_at' => now()->toISOString()], 201);
        }, 3);
    }

    public function withdraw(Request $r, VendorCompany $company)
    {
        abort_unless($r->user()->role === 'admin', 403);
        $v = $r->validate(['password' => 'required|string|max:128', 'expected_revision' => 'required|integer|min:1|max:2147483646', 'version_id' => 'required|uuid', 'confirmed' => 'required|accepted']);

        return DB::transaction(function () use ($r, $company, $v) {
            $actor = $this->actor($r);
            abort_unless($actor->role === 'admin', 403);
            if (! Hash::check($v['password'], $actor->password)) {
                throw ValidationException::withMessages(['password' => 'Current password is required.']);
            }
            $company = $this->company($actor, $company);
            $channel = DB::table('vendor_agreement_channels')->where('country', $company->country)->lockForUpdate()->first();
            $version = DB::table('vendor_agreement_versions')->where('id', $v['version_id'])->where('country', $company->country)->where('revision', $v['expected_revision'])->first();
            abort_unless($channel && $version, 409);
            if ($channel->version_id === null && $channel->revision === (int) $v['expected_revision'] + 1) {
                return $this->reply(['withdrawn' => true, 'revision' => $channel->revision]);
            }
            abort_unless($channel->version_id === $version->id && $channel->revision === (int) $v['expected_revision'], 409, 'Agreement changed. Refresh before withdrawing it.');
            DB::table('vendor_agreement_channels')->where('country', $company->country)->update(['version_id' => null, 'revision' => $channel->revision + 1]);
            $this->audit($actor, 'withdrawn', $version->id);

            return $this->reply(['withdrawn' => true, 'revision' => $channel->revision + 1]);
        }, 3);
    }

    public function receipt(Request $r, VendorCompany $company, string $receipt)
    {
        return DB::transaction(function () use ($r, $company, $receipt) {
            $company = $this->company($this->actor($r), $company);
            $record = DB::table('vendor_agreement_acceptances')->where('company_id', $company->id)->where('id', $receipt)->first(['id', 'company_id', 'actor_id', 'version_id', 'version_sha256', 'created_at']);
            abort_unless($record, 404);

            return $this->reply(['receipt' => $record, 'version' => $this->present(DB::table('vendor_agreement_versions')->where('id', $record->version_id)->first())]);
        });
    }
}
