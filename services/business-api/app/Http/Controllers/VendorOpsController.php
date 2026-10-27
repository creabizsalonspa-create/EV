<?php

namespace App\Http\Controllers;

use App\Models\Station;
use App\Models\User;
use App\Models\VendorOps\VendorCharger;
use App\Models\VendorOps\VendorCompany;
use App\Models\VendorOps\VendorCompanyReview;
use App\Models\VendorOps\VendorCompanyStaff;
use App\Models\VendorOps\VendorConnector;
use App\Models\VendorOps\VendorKycDocument;
use App\Models\VendorOps\VendorSite;
use App\Services\VendorOps\VendorAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class VendorOpsController extends Controller
{
    public function __construct(private VendorAccess $access) {}

    public function companies(Request $r)
    {
        return VendorCompany::when($r->user()->role !== 'admin', fn ($q) => $q->where('owner_id', $r->user()->id)->orWhereIn('id', VendorCompanyStaff::where('user_id', $r->user()->id)->where('status', 'active')->select('company_id')))->orderByDesc('id')->paginate(25);
    }

    public function createCompany(Request $r)
    {
        abort_unless(in_array($r->user()->role, ['customer', 'vendor']), 403);
        $v = $r->validate(['legal_name' => 'required|string|max:150', 'country' => 'required|string|size:2|exists:regions,country', 'trade_license_number' => 'required|string|max:100', 'license_expires_on' => 'required|date_format:Y-m-d|after:today']);

        return DB::transaction(function () use ($r, $v) {
            $c = VendorCompany::create($v + ['owner_id' => $r->user()->id]);
            $this->access->audit($r->user(), 'company.created', $c->id);

            return response()->json($c, 201);
        });
    }

    public function company(Request $r, VendorCompany $company)
    {
        $this->access->require($r->user(), $company);

        return response()->json($company->toArray() + ['kyc_workflow_only' => true, 'physical_control_enabled' => false]);
    }

    public function updateCompany(Request $r, VendorCompany $company)
    {
        $this->access->owner($r->user(), $company);
        $v = $r->validate(['legal_name' => 'sometimes|required|string|max:150', 'country' => 'sometimes|required|string|size:2|exists:regions,country', 'trade_license_number' => 'sometimes|required|string|max:100', 'license_expires_on' => 'sometimes|required|date_format:Y-m-d|after:today']);

        return DB::transaction(function () use ($r, $company, $v) {
            $c = VendorCompany::whereKey($company->id)->lockForUpdate()->firstOrFail();
            abort_unless(in_array($c->status, ['draft', 'changes_requested', 'rejected']), 409, 'Submitted or approved company details cannot change.');
            $c->update($v);
            $this->access->audit($r->user(), 'company.updated', $c->id);

            return $c;
        });
    }

    public function reviews(Request $r, VendorCompany $company)
    {
        abort_unless($r->user()->role === 'admin' || $company->owner_id === $r->user()->id, 404);

        return VendorCompanyReview::where('company_id', $company->id)->orderBy('id')->paginate(25);
    }

    private function operationalWrite(Request $r, VendorCompany $company): VendorCompany
    {
        $company = VendorCompany::whereKey($company->id)->lockForUpdate()->firstOrFail();
        $this->access->require($r->user(), $company, 'inventory.write');
        $this->access->operational($company);

        return $company;
    }

    public function documents(Request $r, VendorCompany $company)
    {
        abort_unless($r->user()->role === 'admin' || $company->owner_id === $r->user()->id, 404);

        return VendorKycDocument::where('company_id', $company->id)->paginate(25);
    }

    public function upload(Request $r, VendorCompany $company)
    {
        $this->access->owner($r->user(), $company);
        abort_unless(in_array($company->status, ['draft', 'changes_requested', 'rejected']), 409, 'Documents cannot change while submitted or approved.');
        $v = $r->validate(['kind' => 'required|in:trade_license,identity,agreement', 'document' => 'required|file|mimetypes:application/pdf,image/jpeg,image/png|max:5120']);
        $file = $r->file('document');
        $path = 'vendor-kyc/'.Str::uuid().'.enc';
        if (! Storage::disk('local')->put($path, Crypt::encryptString($file->getContent()))) {
            throw new \RuntimeException('Unable to store KYC evidence.');
        }
        try {
            return DB::transaction(function () use ($r, $company, $v, $file, $path) {
                $c = VendorCompany::whereKey($company->id)->lockForUpdate()->firstOrFail();
                abort_unless(in_array($c->status, ['draft', 'changes_requested', 'rejected']), 409);
                $d = VendorKycDocument::create(['company_id' => $c->id, 'uploaded_by' => $r->user()->id, 'kind' => $v['kind'], 'storage_path' => $path, 'mime_type' => $file->getMimeType(), 'size_bytes' => $file->getSize(), 'sha256' => hash_file('sha256', $file->getRealPath())]);
                $this->access->audit($r->user(), 'document.uploaded', $d->id);

                return response()->json($d, 201);
            });
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($path);
            throw $e;
        }
    }

    public function download(Request $r, VendorCompany $company, VendorKycDocument $document)
    {
        abort_unless(($r->user()->role === 'admin' || $company->owner_id === $r->user()->id) && $document->company_id === $company->id, 404);
        $extension = match ($document->mime_type) {
            'image/png' => 'png','image/jpeg' => 'jpg',default => 'pdf'
        };

        return response(Crypt::decryptString(Storage::disk('local')->get($document->getRawOriginal('storage_path'))), 200, ['Content-Type' => $document->mime_type, 'Content-Disposition' => 'attachment; filename="kyc-document-'.$document->id.'.'.$extension.'"', 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'no-store, private']);
    }

    public function submit(Request $r, VendorCompany $company)
    {
        $this->access->owner($r->user(), $company);

        return DB::transaction(function () use ($r, $company) {
            $c = VendorCompany::whereKey($company->id)->lockForUpdate()->firstOrFail();
            if ($c->status === 'submitted') {
                return response()->json($c);
            }abort_unless(in_array($c->status, ['draft', 'changes_requested', 'rejected']), 409);
            abort_unless($c->license_expires_on->endOfDay()->isFuture(), 422, 'Trade license has expired.');
            abort_unless(VendorKycDocument::where('company_id', $c->id)->where('kind', 'trade_license')->exists(), 422, 'Upload a trade-license document before submitting.');
            $c->update(['status' => 'submitted', 'review_reason' => null]);
            $this->access->audit($r->user(), 'company.submitted', $c->id);

            return response()->json($c);
        });
    }

    public function review(Request $r, VendorCompany $company)
    {
        abort_unless($r->user()->role === 'admin', 403);
        $v = $r->validate(['status' => 'required|in:approved,rejected,changes_requested,suspended', 'reason' => 'required|string|max:2000']);

        return DB::transaction(function () use ($r, $company, $v) {
            $c = VendorCompany::whereKey($company->id)->lockForUpdate()->firstOrFail();
            $allowed = ['draft' => [], 'submitted' => ['approved', 'rejected', 'changes_requested'], 'changes_requested' => [], 'rejected' => [], 'approved' => ['suspended'], 'suspended' => ['approved']];
            abort_unless(in_array($v['status'], $allowed[$c->status]), 409, 'Invalid review transition.');
            if ($v['status'] === 'approved') {
                abort_unless($c->license_expires_on->endOfDay()->isFuture(), 422, 'Trade license has expired.');
            }VendorCompanyReview::create(['company_id' => $c->id, 'reviewer_id' => $r->user()->id, 'from_status' => $c->status, 'to_status' => $v['status'], 'reason' => $v['reason'], 'created_at' => now()]);
            $c->update(['status' => $v['status'], 'review_reason' => $v['reason'], 'reviewed_by' => $r->user()->id, 'reviewed_at' => now()]);
            if ($v['status'] === 'approved') {
                User::whereKey($c->owner_id)->where('role', 'customer')->update(['role' => 'vendor']);
            }$this->access->audit($r->user(), 'company.'.$v['status'], $c->id);

            return response()->json($c->toArray() + ['kyc_workflow_only' => true, 'physical_control_enabled' => false]);
        });
    }

    public function staff(Request $r, VendorCompany $company)
    {
        $this->access->owner($r->user(), $company);

        return VendorCompanyStaff::where('company_id', $company->id)->paginate(25);
    }

    public function invite(Request $r, VendorCompany $company)
    {
        $this->access->owner($r->user(), $company);
        $v = $r->validate(['email' => 'required|email', 'permissions' => 'required|array|min:1', 'permissions.*' => ['required', Rule::in(VendorAccess::PERMISSIONS)]]);
        $u = User::where('email', $v['email'])->first();
        if ($u && $u->id !== $company->owner_id) {
            DB::transaction(function () use ($r, $company, $u, $v) {
                $existing = VendorCompanyStaff::where('company_id', $company->id)->where('user_id', $u->id)->lockForUpdate()->first();
                if (! $existing) {
                    VendorCompanyStaff::create(['company_id' => $company->id, 'user_id' => $u->id, 'permissions' => array_values(array_unique($v['permissions']))]);
                } elseif ($existing->status === 'revoked') {
                    $existing->update(['status' => 'invited', 'permissions' => array_values(array_unique($v['permissions']))]);
                }$this->access->audit($r->user(), 'staff.invited', $company->id);
            });
        }

        return response()->json(['message' => 'Invitation recorded if the account is eligible.'], 202);
    }

    public function invitations(Request $r)
    {
        return VendorCompanyStaff::where('user_id', $r->user()->id)->where('status', 'invited')->paginate(25);
    }

    public function accept(Request $r, VendorCompanyStaff $membership)
    {
        abort_unless($membership->user_id === $r->user()->id, 404);

        return DB::transaction(function () use ($r, $membership) {
            $m = VendorCompanyStaff::whereKey($membership->id)->lockForUpdate()->firstOrFail();
            abort_unless(in_array($m->status, ['invited', 'active']), 409);
            $m->update(['status' => 'active']);
            $this->access->audit($r->user(), 'staff.accepted', $m->id);

            return $m;
        });
    }

    public function revoke(Request $r, VendorCompany $company, VendorCompanyStaff $membership)
    {
        $this->access->owner($r->user(), $company);
        abort_unless($membership->company_id === $company->id, 404);

        return DB::transaction(function () use ($r, $membership) {
            $m = VendorCompanyStaff::whereKey($membership->id)->lockForUpdate()->firstOrFail();
            $m->update(['status' => 'revoked']);
            $this->access->audit($r->user(), 'staff.revoked', $m->id);

            return response()->noContent();
        });
    }

    public function sites(Request $r, VendorCompany $company)
    {
        $this->access->require($r->user(), $company, 'inventory.read');

        return VendorSite::where('company_id', $company->id)->paginate(25);
    }

    public function createSite(Request $r, VendorCompany $company)
    {
        $this->access->require($r->user(), $company, 'inventory.write');
        $this->access->operational($company);
        $v = $r->validate(['name' => 'required|string|max:150', 'country' => 'required|string|size:2|exists:regions,country', 'timezone' => 'required|timezone', 'address' => 'required|string|max:300', 'latitude' => 'required|numeric|between:-90,90', 'longitude' => 'required|numeric|between:-180,180', 'station_id' => 'nullable|integer|unique:vendor_sites,station_id']);
        if (isset($v['station_id'])) {
            abort_unless(Station::whereKey($v['station_id'])->where('vendor_id', $company->owner_id)->where('country', $v['country'])->exists(), 404);
        }

        return DB::transaction(function () use ($r, $company, $v) {
            $company = $this->operationalWrite($r, $company);
            $s = VendorSite::create($v + ['company_id' => $company->id]);
            $this->access->audit($r->user(), 'site.created', $s->id);

            return response()->json($s, 201);
        });
    }

    public function chargers(Request $r, VendorCompany $company, VendorSite $site)
    {
        $this->access->require($r->user(), $company, 'inventory.read');
        abort_unless($site->company_id === $company->id, 404);

        return VendorCharger::where('site_id', $site->id)->paginate(25);
    }

    public function createCharger(Request $r, VendorCompany $company, VendorSite $site)
    {
        $this->access->require($r->user(), $company, 'inventory.write');
        $this->access->operational($company);
        abort_unless($site->company_id === $company->id, 404);
        $v = $r->validate(['asset_reference' => ['required', 'string', 'max:80', Rule::unique('vendor_chargers')->where('company_id', $company->id)], 'manufacturer' => 'required|string|max:100', 'model' => 'required|string|max:100', 'serial_number' => 'nullable|string|max:100', 'ocpp_protocol' => 'nullable|in:1.6,2.0.1', 'source' => 'required|in:discovery,simulator,direct_unverified,partner_unverified']);

        return DB::transaction(function () use ($r, $company, $site, $v) {
            $company = $this->operationalWrite($r, $company);
            $c = VendorCharger::create($v + ['company_id' => $company->id, 'site_id' => $site->id]);
            $this->access->audit($r->user(), 'charger.created', $c->id);

            return response()->json($c->toArray() + ['availability' => 'unknown', 'remote_control_enabled' => false], 201);
        });
    }

    public function connectors(Request $r, VendorCompany $company, VendorCharger $charger)
    {
        $this->access->require($r->user(), $company, 'inventory.read');
        abort_unless($charger->company_id === $company->id, 404);

        return VendorConnector::where('charger_id', $charger->id)->paginate(25)->through(fn ($c) => $c->toArray() + ['availability' => 'unknown', 'remote_control_enabled' => false]);
    }

    public function createConnector(Request $r, VendorCompany $company, VendorCharger $charger)
    {
        $this->access->require($r->user(), $company, 'inventory.write');
        $this->access->operational($company);
        abort_unless($charger->company_id === $company->id, 404);
        $v = $r->validate(['connector_number' => ['required', 'integer', 'between:1,100', Rule::unique('vendor_connectors')->where('charger_id', $charger->id)], 'connector_type' => 'required|in:CCS2,Type2,CHAdeMO', 'max_power_kw' => 'required|integer|between:1,1000']);

        return DB::transaction(function () use ($r, $company, $charger, $v) {
            $this->operationalWrite($r, $company);
            $c = VendorConnector::create($v + ['charger_id' => $charger->id]);
            $this->access->audit($r->user(), 'connector.created', $c->id);

            return response()->json($c->toArray() + ['availability' => 'unknown', 'remote_control_enabled' => false], 201);
        });
    }

    public function maintenance(Request $r, VendorCompany $company, VendorCharger $charger, VendorConnector $connector)
    {
        $this->access->require($r->user(), $company, 'inventory.write');
        abort_unless($charger->company_id === $company->id && $connector->charger_id === $charger->id, 404);
        $v = $r->validate(['maintenance_blocked' => 'required|boolean', 'maintenance_reason' => 'required|string|max:1000']);

        return DB::transaction(function () use ($r, $company, $connector, $v) {
            $company = VendorCompany::whereKey($company->id)->lockForUpdate()->firstOrFail();
            $this->access->require($r->user(), $company, 'inventory.write');
            if (! $v['maintenance_blocked']) {
                $this->access->operational($company);
            }
            $connector->update($v);
            $this->access->audit($r->user(), 'connector.maintenance', $connector->id);

            return response()->json($connector->toArray() + ['availability' => 'unknown', 'remote_control_enabled' => false]);
        });
    }
}
