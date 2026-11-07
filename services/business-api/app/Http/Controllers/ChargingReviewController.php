<?php

namespace App\Http\Controllers;

use App\Models\ChargingSession;
use App\Models\Station;
use App\Models\User;
use App\Services\Charging\CustomerReviews;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ChargingReviewController extends Controller
{
    private function reply(mixed $data)
    {
        return response()->json($data)->header('Cache-Control', 'private, no-store');
    }

    private function actor(Request $r): User
    {
        return User::findOrFail($r->user()->id);
    }

    private function page(Request $r): void
    {
        $r->validate(['page' => 'sometimes|integer|min:1|max:100000']);
    }

    private function content(Request $r, bool $version = false): array
    {
        return $r->validate(['request_key' => 'required|uuid', 'expected_version' => ($version ? 'required' : 'prohibited').'|integer|min:1', 'rating' => 'required|integer|between:1,5', 'body' => ['required', 'string', 'min:3', 'max:2000', 'not_regex:/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/u'], 'consent' => 'required|accepted', 'terms_version' => 'required|in:'.CustomerReviews::TERMS]);
    }

    public function catalog(Request $r, Station $station, CustomerReviews $service)
    {
        $this->page($r);
        $query = DB::table('charging_reviews')->where('station_id', $station->id)->where('vendor_id', $station->vendor_id)->where('state', 'published')->when($station->source !== 'simulator', fn ($q) => $q->whereRaw('1=0'));
        // Never combine simulator feedback into physical/live ratings.
        $groups = [];
        foreach (['SIMULATED_SOURCE_OBSERVED', 'SIMULATED_APP_ONLY'] as $evidence) {
            $ids = (clone $query)->where('evidence', $evidence)->select('id', 'revision');
            $sums = DB::table('charging_review_events as e')->joinSub($ids, 'r', fn ($j) => $j->on('r.id', '=', 'e.review_id')->on('r.revision', '=', 'e.revision'))->whereIn('e.action', ['create', 'revise'])->selectRaw('COUNT(*) as count, COALESCE(SUM(e.rating),0) as rating_sum')->first();
            $groups[] = ['evidence' => $evidence, 'count' => (int) $sums->count, 'rating_sum' => (int) $sums->rating_sum];
        }

        return $this->reply((clone $query)->orderByDesc('id')->paginate(20)->through(fn ($row) => $service->present($row))->toArray() + ['groups' => $groups, 'physical_charging_verified' => false, 'station_name' => $station->name]);
    }

    public function mine(Request $r, CustomerReviews $service)
    {
        $this->page($r);
        $actor = $this->actor($r);

        return $this->reply(DB::table('charging_reviews')->where('user_id', $actor->id)->orderByDesc('id')->paginate(20)->through(fn ($row) => $service->present($row, true))->toArray() + ['terms_version' => CustomerReviews::TERMS]);
    }

    public function eligible(Request $r, CustomerReviews $service)
    {
        $this->page($r);
        $actor = $this->actor($r);
        abort_unless($actor->role === 'customer', 403);
        $rows = ChargingSession::query()->join('reservations as r', 'r.id', '=', 'charging_sessions.reservation_id')->where('r.user_id', $actor->id)->where('charging_sessions.status', 'stopped')->select('charging_sessions.*')->orderByDesc('charging_sessions.id')->paginate(20)->through(function ($row) use ($actor, $service) {
            try {
                $proof = $service->evidence($row, $actor);
                $review = DB::table('charging_reviews')->where('user_id', $actor->id)->where('station_id', $proof['station']->id)->first();

                return ['session_id' => $row->id, 'station_name' => $proof['station']->name, 'station_id' => $proof['station']->id, 'evidence' => $proof['evidence'], 'can_review' => $review === null, 'existing_review_id' => $review?->id, 'physical_charging_verified' => false];
            } catch (HttpException) {
                return ['session_id' => $row->id, 'can_review' => false, 'evidence' => 'INELIGIBLE', 'physical_charging_verified' => false];
            }
        });

        return $this->reply($rows->toArray() + ['terms_version' => CustomerReviews::TERMS]);
    }

    public function create(Request $r, ChargingSession $session, CustomerReviews $service)
    {
        return $this->reply($service->present($service->mutate($this->actor($r), 'create', $this->content($r), $session), true));
    }

    public function revise(Request $r, int $review, CustomerReviews $service)
    {
        return $this->reply($service->present($service->mutate($this->actor($r), 'revise', $this->content($r, true), id: $review), true));
    }

    public function withdraw(Request $r, int $review, CustomerReviews $service)
    {
        $v = $r->validate(['request_key' => 'required|uuid', 'expected_version' => 'required|integer|min:1']);

        return $this->reply($service->present($service->mutate($this->actor($r), 'withdraw', $v, id: $review), true));
    }

    public function history(Request $r, int $review)
    {
        $this->page($r);
        $actor = $this->actor($r);
        $row = DB::table('charging_reviews')->find($review);
        abort_unless($row && ($row->user_id === $actor->id || $actor->role === 'admin'), 404);

        return $this->reply(DB::table('charging_review_events')->where('review_id', $review)->orderByDesc('id')->paginate(20)->through(fn ($e) => ['action' => $e->action, 'version' => $e->version, 'revision' => $e->revision, 'rating' => $e->rating, 'body' => $e->body_ciphertext ? Crypt::decryptString($e->body_ciphertext) : null, 'reason' => $e->reason_ciphertext ? Crypt::decryptString($e->reason_ciphertext) : null, 'created_at' => $e->created_at]));
    }

    public function operations(Request $r, CustomerReviews $service)
    {
        $this->page($r);
        $actor = $this->actor($r);
        abort_unless(in_array($actor->role, ['vendor', 'admin'], true), 403);
        $r->validate(['state' => 'sometimes|in:pending,published,rejected,hidden,withdrawn']);

        return $this->reply(DB::table('charging_reviews')->when($actor->role === 'vendor', fn ($q) => $q->where('vendor_id', $actor->id)->where('state', 'published')->whereIn('station_id', Station::where('vendor_id', $actor->id)->select('id')))->when($r->filled('state'), fn ($q) => $q->where('state', $r->input('state')))->orderByDesc('id')->paginate(20)->through(fn ($row) => $service->present($row, $actor->role === 'admin'))->toArray() + ['can_moderate' => $actor->role === 'admin']);
    }

    public function moderate(Request $r, int $review, CustomerReviews $service)
    {
        abort_unless($this->actor($r)->role === 'admin', 403);
        $v = $r->validate(['request_key' => 'required|uuid', 'expected_version' => 'required|integer|min:1', 'action' => 'required|in:publish,reject,hide', 'reason' => 'required|string|min:5|max:500']);

        return $this->reply($service->present($service->mutate($this->actor($r), $v['action'], $v, id: $review), true));
    }
}
