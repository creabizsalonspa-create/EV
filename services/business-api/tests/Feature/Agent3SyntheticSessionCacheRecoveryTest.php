<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;
use Tests\TestCase;

class Agent3SyntheticSessionCacheRecoveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_database_backed_session_handler_and_cache_roundtrip_without_real_users(): void
    {
        config([
            'cache.default' => 'database',
            'session.driver' => 'database',
        ]);
        $id = 'agent3syntheticrecovery000000000000000001';
        $cache = Cache::store('database');
        $handler = Session::driver('database')->getHandler();

        $this->assertTrue($cache->put('agent3:recovery:cache', 'cached-before-snapshot', 7200));
        $this->assertSame('cached-before-snapshot', $cache->get('agent3:recovery:cache'));
        $this->assertTrue($handler->write($id, 'session-before-snapshot'));
        $this->assertSame('session-before-snapshot', $handler->read($id));
        $this->assertDatabaseCount('sessions', 1);

        $cache->put('agent3:recovery:cache', 'cached-after-snapshot', 7200);
        $handler->write($id, 'session-after-snapshot');
        $this->assertSame('cached-after-snapshot', $cache->get('agent3:recovery:cache'));
        $this->assertSame('session-after-snapshot', $handler->read($id));
        $this->assertDatabaseCount('sessions', 1);
        $this->assertDatabaseCount('charger_commands', 0);
        $this->assertDatabaseCount('finance_invoices', 0);
    }
}
