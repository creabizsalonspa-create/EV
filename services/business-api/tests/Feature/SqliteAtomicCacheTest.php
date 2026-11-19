<?php

namespace Tests\Feature;

use Illuminate\Cache\DatabaseStore;
use Illuminate\Cache\RateLimiter;
use Illuminate\Cache\Repository;
use Illuminate\Support\Facades\DB;
use PDOException;
use Tests\TestCase;

/** Two real SQLite connections, isolated from every application/user database. */
class SqliteAtomicCacheTest extends TestCase
{
    public function test_cache_transactions_reserve_the_writer_before_reading_and_preserve_existing_limits(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'ev-sqlite-cache-review-');
        $names = ['sqlite_cache_review_left', 'sqlite_cache_review_right'];
        foreach ($names as $name) {
            config(['database.connections.'.$name => array_replace(config('database.connections.sqlite'), [
                'url' => null, 'database' => $path, 'busy_timeout' => 0,
            ])]);
        }
        $this->beforeApplicationDestroyed(function () use ($names, $path) {
            foreach ($names as $name) {
                $connection = DB::connection($name);
                while ($connection->transactionLevel() > 0) {
                    $connection->rollBack();
                }
                DB::purge($name);
            }
            @unlink($path);
        });
        $left = DB::connection($names[0]);
        $right = DB::connection($names[1]);
        $left->statement('CREATE TABLE cache (key TEXT PRIMARY KEY, value TEXT NOT NULL, expiration INTEGER NOT NULL)');
        $expires = time() + 60;
        $left->table('cache')->insert(['key' => 'shared-budget', 'value' => serialize(7), 'expiration' => $expires]);
        $firstStore = new DatabaseStore($left, 'cache');
        $secondStore = new DatabaseStore($right, 'cache');
        $left->beginTransaction();
        $blocked = false;
        try {
            $right->beginTransaction();
        } catch (PDOException $error) {
            $blocked = (int) ($error->errorInfo[1] ?? 0) === 5;
        }
        $this->assertTrue($blocked, 'Another writer must be excluded before a cache transaction takes its read snapshot.');
        $this->assertSame(8, $firstStore->increment('shared-budget'));
        $left->commit();
        $right->beginTransaction();
        $this->assertSame(9, $secondStore->increment('shared-budget'));
        $right->commit();
        $this->assertSame($expires, (int) $left->table('cache')->where('key', 'shared-budget')->value('expiration'));
        $limiter = new RateLimiter(new Repository($firstStore));
        $this->assertFalse($limiter->tooManyAttempts('shared-budget', 10));
        $this->assertSame(10, $limiter->hit('shared-budget', 60));
        $this->assertTrue($limiter->tooManyAttempts('shared-budget', 10));
        $this->assertGreaterThan(0, $limiter->availableIn('shared-budget'));
    }
}
