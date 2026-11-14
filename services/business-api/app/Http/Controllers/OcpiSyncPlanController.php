<?php

namespace App\Http\Controllers;

use App\Models\OcpiProvider;
use App\Services\Ocpi\OcpiException;
use App\Services\Ocpi\ScheduledSync;
use Illuminate\Http\Request;

class OcpiSyncPlanController extends Controller
{
    public function show(Request $r, OcpiProvider $provider, ScheduledSync $service)
    {
        $r->validate(['page' => 'sometimes|integer|min:1|max:100000']);

        return response()->json($service->show($r->user(), $provider, (int) $r->input('page', 1)))->header('Cache-Control', 'private, no-store');
    }

    public function update(Request $r, OcpiProvider $provider, string $module, ScheduledSync $service)
    {
        $v = $r->validate(['enabled' => 'required|boolean', 'confirmed' => 'sometimes|boolean', 'interval_minutes' => 'required|integer|in:'.implode(',', ScheduledSync::INTERVALS),
            'expected_revision' => ['required', 'string', 'regex:/^(0|[1-9][0-9]{0,18})$/D'], 'url' => 'prohibited', 'token' => 'prohibited', 'actor_id' => 'prohibited']);
        abort_if($r->boolean('enabled') && ! $r->boolean('confirmed'), 422, 'Explicit polling consent is required.');
        abort_unless((string) (int) $v['expected_revision'] === $v['expected_revision'], 422);
        try {
            $data = $service->update($r->user(), $provider, $module, $r->boolean('enabled'), (int) $v['interval_minutes'], (int) $v['expected_revision']);
        } catch (OcpiException) {
            abort(409, 'Approved partner connection requires review.');
        }

        return response()->json($data)->header('Cache-Control', 'private, no-store');
    }
}
