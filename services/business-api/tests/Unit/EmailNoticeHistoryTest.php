<?php

namespace Tests\Unit;

use App\Services\Notifications\EmailNoticeHistory;
use PHPUnit\Framework\TestCase;

class EmailNoticeHistoryTest extends TestCase
{
    public function test_projection_preserves_known_states_and_redacts_corrupt_evidence(): void
    {
        $row = ['id' => '12345678-1234-4234-8234-123456789abc', 'state' => 'queued',
            'failure_code' => null, 'evidence' => 'SIMULATED_EMAIL_HANDOFF',
            'created_at' => '2026-11-28 04:00:00', 'claimed_at' => null, 'completed_at' => null];
        foreach ([
            [],
            ['state' => 'claimed', 'claimed_at' => '2026-11-28 04:00:01+00'],
            ['state' => 'handed_off', 'claimed_at' => '2026-11-28 05:00:01+01:00', 'completed_at' => '2026-11-28 04:00:02Z'],
            ['state' => 'cancelled', 'failure_code' => 'user_opted_out', 'completed_at' => '2026-11-28 04:00:02'],
            ['state' => 'outcome_unknown', 'failure_code' => 'interrupted_handoff_unknown', 'completed_at' => '2026-11-28 04:20:02'],
        ] as $changes) {
            $input = array_replace($row, $changes);
            $this->assertSame($input, EmailNoticeHistory::project((object) $input));
        }
        foreach ([
            ['id' => 'PRIVATE-ID'], ['state' => 'delivered'], ['evidence' => 'PRIVATE-EVIDENCE'],
            ['failure_code' => 'PRIVATE-ERROR'], ['created_at' => '2026-02-30 04:00:00'],
            ['created_at' => 'PRIVATE-CLOCK'], ['state' => 'handed_off'],
            ['claimed_at' => 'PRIVATE-CLOCK'], ['completed_at' => '2026-11-28 04:00:02'],
            ['state' => 'claimed'],
            ['state' => 'claimed', 'claimed_at' => '2026-11-28 04:00:01+01:00'],
            ['state' => 'handed_off', 'claimed_at' => '2026-11-28 04:00:03', 'completed_at' => '2026-11-28 04:00:02'],
            ['state' => 'cancelled', 'failure_code' => 'user_opted_out', 'completed_at' => '2026-11-28 03:59:59'],
        ] as $changes) {
            $projected = EmailNoticeHistory::project((object) array_replace($row, $changes));
            $this->assertSame('review_required', $projected['state']);
            $this->assertNull($projected['evidence']);
            $this->assertNull($projected['failure_code']);
            $this->assertStringNotContainsString('PRIVATE-', json_encode($projected, JSON_THROW_ON_ERROR));
        }
    }
}
