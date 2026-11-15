<?php

namespace Tests\Support;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;

final class OperationalHealthProbe implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $actorId, public string $privatePayload = 'PRIVATE-HEALTH-JOB-PAYLOAD') {}

    public function handle(): void
    {
        DB::table('audit_events')->insert(['actor_id' => $this->actorId, 'action' => 'health.synthetic_job_processed', 'subject' => 'synthetic-only', 'created_at' => now()]);
    }
}
