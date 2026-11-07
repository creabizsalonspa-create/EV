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
            'description_en' => 'present|nullable|string|max:500',
            'description_ar' => 'present|nullable|string|max:500',
            'access_en' => 'present|nullable|string|max:500',
            'access_ar' => 'present|nullable|string|max:500',
            'weekly_hours' => 'required|array:mon,tue,wed,thu,fri,sat,sun|size:7',
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

        return DB::transaction(function () use ($request, $station, $v) {
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
                'revision' => ($current->revision ?? 0) + 1,
                'updated_at' => now(),
            ];
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
