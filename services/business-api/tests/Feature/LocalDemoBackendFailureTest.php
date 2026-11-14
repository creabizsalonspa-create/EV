<?php

namespace Tests\Feature;

use Tests\Support\LocalDemoBackendFailure;
use Tests\TestCase;

class LocalDemoBackendFailureTest extends TestCase
{
    public function test_recent_diagnostic_never_discloses_error_sql_or_private_data(): void
    {
        $log = '[2026-11-14 12:00:00] local.ERROR: SQLSTATE[23505] PRIVATE-TOKEN'."\n".'#0 '.app_path('Services/Charging/SessionBridge.php').'(90): PRIVATE-BODY';
        $log .= "\n".'[2026-11-14 12:01:00] local.ERROR: SQLSTATE[HY000] database is locked PRIVATE-PASSWORD'."\n".'#0 '.app_path('Services/Charging/SourceStatusDurations.php').'(20): PRIVATE-QUERY';
        $result = LocalDemoBackendFailure::classify($log);
        $this->assertSame('sqlite_locked', $result['exception_kind']);
        $this->assertSame('Services/Charging/SourceStatusDurations.php', $result['application_file']);
        $this->assertSame(20, $result['application_line']);
        $this->assertStringNotContainsString('PRIVATE', json_encode($result));
        $this->assertSame('RECENT_UNATTRIBUTED_LOCAL_ERROR', $result['correlation']);
    }

    public function test_arbitrary_messages_and_non_application_paths_stay_unavailable(): void
    {
        $result = LocalDemoBackendFailure::classify('PRIVATE-TOKEN /tmp/PRIVATE-PASSWORD.php(1)');
        $this->assertSame('unavailable', $result['exception_kind']);
        $this->assertNull($result['application_file']);
        $this->assertNull($result['application_line']);
        $this->assertStringNotContainsString('PRIVATE', json_encode($result));
    }
}
