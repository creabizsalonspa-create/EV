<?php

namespace Tests\Support;

use App\Models\OcpiProvider;
use App\Models\User;
use App\Services\Ocpi\TechnicalAgreement;
use Illuminate\Support\Str;

trait CreatesOcpiTechnicalAgreement
{
    /** Real policy precondition for independent production protocol tests, never a bypass. */
    private function approveTechnicalAgreement(OcpiProvider $provider, array $modules = ['locations']): int
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $service = app(TechnicalAgreement::class);
        $id = $service->propose($provider, $admin, ['request_key' => (string) Str::uuid(), 'expected_revision' => 0,
            'reference' => 'SYNTHETIC-PROTOCOL-POLICY', 'evidence_sha256' => hash('sha256', 'synthetic policy fixture, no live approval'),
            'terms_en' => 'Synthetic technical policy for an independent production protocol contract test.',
            'terms_ar' => 'سياسة تقنية اصطناعية لاختبار عقد البروتوكول فقط دون شريك فعلي.',
            'allowed_modules' => $modules, 'valid_from' => now()->subMinute()->utc()->format('Y-m-d\TH:i:s\Z'),
            'valid_until' => now()->addDay()->utc()->format('Y-m-d\TH:i:s\Z')]);
        $service->decide($provider, $admin, $id, ['request_key' => (string) Str::uuid(), 'expected_revision' => 1,
            'action' => 'approved', 'reason' => 'Synthetic recorded evidence permits the independent protocol test to reach its own guard.']);
        $this->assertFalse($provider->fresh()->authorized);

        return $id;
    }
}
