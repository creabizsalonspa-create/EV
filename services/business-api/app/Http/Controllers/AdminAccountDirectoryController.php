<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\VendorOps\VendorCompany;
use Illuminate\Http\Request;

class AdminAccountDirectoryController extends Controller
{
    /**
     * Bounded, read-only account and business-review directory.
     *
     * Do not serialize full User or VendorCompany models: those contain
     * credentials, recovery data, KYC material and other private fields.
     */
    public function index(Request $request)
    {
        abort_unless($request->user()->role === 'admin', 403);

        $filters = $request->validate([
            'q' => 'sometimes|required|string|max:100',
            'role' => 'sometimes|required|in:customer,vendor,admin',
            'verified' => 'sometimes|required|in:yes,no',
            'page' => 'sometimes|integer|min:1|max:100000',
        ]);

        $query = User::query()->select([
            'id', 'name', 'email', 'role', 'locale', 'email_verified_at', 'created_at',
        ]);

        if (isset($filters['q'])) {
            $term = trim($filters['q']);
            $query->where(function ($q) use ($term) {
                $q->where('name', 'like', '%'.$term.'%')
                    ->orWhere('email', 'like', '%'.$term.'%');
            });
        }

        if (isset($filters['role'])) {
            $query->where('role', $filters['role']);
        }

        if (isset($filters['verified'])) {
            if ($filters['verified'] === 'yes') {
                $query->whereNotNull('email_verified_at');
            } else {
                $query->whereNull('email_verified_at');
            }
        }

        $page = $query->orderByDesc('id')->paginate(25)->appends($filters);
        $ids = $page->getCollection()->pluck('id');
        $companies = VendorCompany::query()
            ->whereIn('owner_id', $ids)
            ->get(['id', 'owner_id', 'legal_name', 'country', 'status', 'reviewed_at'])
            ->groupBy('owner_id');

        return $page->through(function (User $user) use ($companies) {
            return [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'locale' => $user->locale,
                'email_verified' => $user->email_verified_at !== null,
                'created_at' => $user->created_at,
                'companies' => ($companies->get($user->id) ?? collect())->map(fn ($company) => [
                    'id' => $company->id,
                    'legal_name' => $company->legal_name,
                    'country' => $company->country,
                    'status' => $company->status,
                    'reviewed_at' => $company->reviewed_at,
                ])->values(),
            ];
        });
    }
}
