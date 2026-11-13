<?php

namespace App\Http\Controllers;

use App\Models\Station;
use App\Services\Discovery\StationInformation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class StationInformationController extends Controller
{
    public function show(Station $station)
    {
        return response()->json(app(StationInformation::class)->forStation($station->id))
            ->header('Cache-Control', 'no-store');
    }

    public function save(Request $request, Station $station)
    {
        abort_unless($request->user()->role === 'vendor' && (int) $station->vendor_id === (int) $request->user()->id, 404);

        $rules = [
            'revision' => 'required|integer|min:0|max:2147483646',
            'operator_name' => 'sometimes|nullable|string|max:100',
            'address_en' => 'sometimes|nullable|string|max:300',
            'address_ar' => 'sometimes|nullable|string|max:300',
            'description_en' => 'present|nullable|string|max:500',
            'description_ar' => 'present|nullable|string|max:500',
            'access_en' => 'present|nullable|string|max:500',
            'access_ar' => 'present|nullable|string|max:500',
            // Optional for older clients: omitting all notice keys preserves a
            // newer client's notice rather than deleting it on unrelated edits.
            'notice_en' => 'sometimes|nullable|string|max:300',
            'notice_ar' => 'sometimes|nullable|string|max:300',
            'notice_until' => 'sometimes|nullable|date_format:Y-m-d',
            'weekly_hours' => 'required|array:mon,tue,wed,thu,fri,sat,sun|size:7',
            'enforce_operating_hours' => 'sometimes|boolean',
            'closed_dates' => 'present|array|max:50',
            'closed_dates.*' => 'required|date_format:Y-m-d|distinct',
            'amenities' => 'present|array|max:8',
            'amenities.*' => 'required|in:restroom,wifi,cafe,parking,lighting,shelter,shop,security|distinct',
            'accessibility' => 'present|array|max:4',
            'accessibility.*' => 'required|in:step_free,accessible_parking,accessible_restroom,wide_bays|distinct',
        ];
        foreach (StationInformation::DAYS as $day) {
            $rules['weekly_hours.'.$day] = 'required|array:open,close';
            $rules['weekly_hours.'.$day.'.open'] = 'present|nullable|date_format:H:i';
            $rules['weekly_hours.'.$day.'.close'] = 'present|nullable|date_format:H:i';
        }
        $v = $request->validate($rules);
        $errors = [];
        foreach (StationInformation::DAYS as $day) {
            $hours = $v['weekly_hours'][$day];
            $open = $hours['open'];
            $close = $hours['close'];
            if (($open === null) !== ($close === null)) {
                $errors['weekly_hours.'.$day] = 'Provide both opening and closing times or mark the day closed.';
            }
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
        // Reject invalid calendar dates (e.g. February 30), not just their textual shape.
        foreach ($v['closed_dates'] as $date) {
            [$year, $month, $day] = array_map('intval', explode('-', $date));
            if (! checkdate($month, $day, $year)) {
                throw ValidationException::withMessages(['closed_dates' => 'Invalid holiday date.']);
            }
        }

        $noticeChanged = array_key_exists('notice_en', $v)
            || array_key_exists('notice_ar', $v)
            || array_key_exists('notice_until', $v);
        if ($noticeChanged) {
            foreach (['notice_en', 'notice_ar', 'notice_until'] as $key) {
                if (! array_key_exists($key, $v)) {
                    throw ValidationException::withMessages([$key => 'Submit all notice fields together.']);
                }
            }
            $hasText = trim((string) ($v['notice_en'] ?? '')) !== ''
                || trim((string) ($v['notice_ar'] ?? '')) !== '';
            if ($hasText !== ($v['notice_until'] !== null)) {
                throw ValidationException::withMessages([
                    'notice_until' => 'A dated expiry is required with a notice; clear the expiry when clearing the notice.',
                ]);
            }
            if ($v['notice_until'] !== null) {
                [$year, $month, $day] = array_map('intval', explode('-', $v['notice_until']));
                if (! checkdate($month, $day, $year)) {
                    throw ValidationException::withMessages(['notice_until' => 'Invalid UTC expiry date.']);
                }
            }
        }

        return DB::transaction(function () use ($request, $station, $v, $noticeChanged) {
            // Serializes first-time creates as well as concurrent revisions.
            $owned = Station::whereKey($station->id)->lockForUpdate()->firstOrFail();
            abort_unless((int) $owned->vendor_id === (int) $request->user()->id && $request->user()->role === 'vendor', 404);
            $current = DB::table('station_information')->where('station_id', $owned->id)->lockForUpdate()->first();
            abort_unless((int) ($current->revision ?? 0) === (int) $v['revision'], 409, 'Station information changed; refresh before saving.');
            $fields = [
                'description_en' => $v['description_en'],
                'description_ar' => $v['description_ar'],
                'access_en' => $v['access_en'],
                'access_ar' => $v['access_ar'],
                'weekly_hours' => json_encode($v['weekly_hours'], JSON_THROW_ON_ERROR),
                'closed_dates' => json_encode(array_values($v['closed_dates']), JSON_THROW_ON_ERROR),
                'amenities' => json_encode(array_values($v['amenities']), JSON_THROW_ON_ERROR),
                'accessibility' => json_encode(array_values($v['accessibility']), JSON_THROW_ON_ERROR),
                'enforce_operating_hours' => $v['enforce_operating_hours'] ?? ($current->enforce_operating_hours ?? false),
                'revision' => ($current->revision ?? 0) + 1,
                'updated_at' => now(),
            ];
            foreach (['operator_name', 'address_en', 'address_ar'] as $key) {
                $fields[$key] = array_key_exists($key, $v) ? $v[$key] : ($current->$key ?? null);
            }
            if ($noticeChanged) {
                $fields['notice_en'] = $v['notice_en'];
                $fields['notice_ar'] = $v['notice_ar'];
                $fields['notice_until'] = $v['notice_until'];
            } elseif (! $current) {
                $fields['notice_en'] = null;
                $fields['notice_ar'] = null;
                $fields['notice_until'] = null;
            }
            if ($current) {
                DB::table('station_information')->where('station_id', $owned->id)->update($fields);
            } else {
                DB::table('station_information')->insert($fields + ['station_id' => $owned->id, 'created_at' => now()]);
            }
            DB::table('audit_events')->insert([
                'actor_id' => $request->user()->id,
                'action' => 'station.information.updated',
                'subject' => (string) $owned->id,
                'created_at' => now(),
            ]);

            return response()->json(app(StationInformation::class)->forStation($owned->id))
                ->header('Cache-Control', 'no-store');
        });
    }
}
