<?php

namespace Tests\Feature;

use App\Http\Controllers\AdminLicensedCoverageController;
use App\Models\User;
use App\Services\Discovery\LicensedCoverageHistory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

final class AdminLicensedCoverageTest extends TestCase
{
    use RefreshDatabase;

    private const AREAS = [
        ['label' => 'private-benchmark-label', 'latitude' => 25.2, 'longitude' => 55.3, 'radius_km' => 10, 'country' => 'AE'],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Http::preventStrayRequests();
        config([
            'discovery.ocm_enabled' => true,
            'discovery.ocm_terms_accepted' => true,
            'discovery.ocm_api_key' => 'private-coverage-fixture-key',
            'discovery.ocm_coverage_enabled' => true,
            'discovery.ocm_coverage_areas' => self::AREAS,
        ]);
        $this->travelTo(Carbon::parse('2026-11-28T06:15:00Z'));
    }

    public function test_only_current_admin_reads_private_coverage_history(): void
    {
        $url = '/admin/licensed-coverage';
        $this->getJson($url)->assertUnauthorized();
        foreach (['vendor', 'customer'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]))->get($url)->assertForbidden();
        }
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->get($url)->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertSee('No validated hourly observations.')
            ->assertSee('href="/admin-licensed-coverage.css"', false)
            ->assertDontSee('<style>', false);
        $this->get('/admin/licensed-coverage.css')->assertOk();
        $request = Request::create($url);
        $request->setUserResolver(fn () => $admin);
        DB::table('users')->where('id', $admin->id)->increment('auth_epoch');
        try {
            app(AdminLicensedCoverageController::class)->show($request, app(LicensedCoverageHistory::class));
            $this->fail('Stale admin gained access.');
        } catch (HttpException $error) {
            $this->assertSame(409, $error->getStatusCode());
        }
        Http::assertNothingSent();
    }

    public function test_approved_empty_sample_and_unverified_hours_are_distinct_and_private_in_both_languages(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $url = '/admin/licensed-coverage';
        Http::fake(['api.openchargemap.io/*' => Http::response([], 200, ['Content-Type' => 'application/json'])]);
        $input = self::AREAS[0];
        unset($input['label']);
        $this->postJson('/api/discovery/open-charge-map/search', ['consent' => true] + $input)->assertOk();
        $history = app(LicensedCoverageHistory::class);
        $this->assertSame('captured', $history->capture()['state']);
        $scope = hash('sha256', json_encode([self::AREAS, hash('sha256', 'private-coverage-fixture-key')], JSON_THROW_ON_ERROR));
        Cache::put('ocm-coverage-history:v1:'.$scope.':2026112805', ['private' => 'CORRUPT-PRIVATE'], 3600);
        foreach (['en', 'ar'] as $locale) {
            $admin->forceFill(['locale' => $locale])->save();
            $this->actingAs($admin)->get($url)->assertOk()
                ->assertSee('lang="'.$locale.'"', false)
                ->assertSee('data-testid="observed-hours">1', false)
                ->assertSee('data-testid="unverified-hours">1', false)
                ->assertSee('data-testid="missing-hours">166', false)
                ->assertSee('2026-11-28T06:00:00')
                ->assertDontSee('CORRUPT-PRIVATE')
                ->assertDontSee('private-benchmark-label')
                ->assertDontSee('private-coverage-fixture-key')
                ->assertDontSee('25.2')
                ->assertDontSee('55.3');
        }
        config(['discovery.ocm_terms_accepted' => false]);
        $admin->forceFill(['locale' => 'en'])->save();
        $this->actingAs($admin)->get($url)->assertOk()
            ->assertSee('Monitoring is not configured or approved.')
            ->assertDontSee('2026-11-28T06:00:00');
        Http::assertSentCount(1);
    }
}
