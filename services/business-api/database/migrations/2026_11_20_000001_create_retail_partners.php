<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('retail_partners', function (Blueprint $t) {
            $t->id();
            $t->string('reference', 80)->unique();
            $t->foreignId('created_by')->constrained('users');
            $t->timestamp('created_at');
        });
        Schema::create('retail_partner_versions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('partner_id')->constrained('retail_partners');
            $t->unsignedInteger('version');
            $t->string('legal_name', 200);
            $t->string('display_name', 150);
            $t->string('display_name_ar', 150);
            $t->string('country', 2);
            $t->string('category', 30);
            $t->string('website', 500)->nullable();
            $t->text('terms');
            $t->timestamp('effective_from');
            $t->timestamp('expires_at');
            $t->foreignId('created_by')->constrained('users');
            $t->timestamp('created_at');
            $t->unique(['partner_id', 'version']);
        });
        Schema::create('retail_partner_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('partner_id')->constrained('retail_partners');
            $t->foreignId('version_id')->constrained('retail_partner_versions');
            $t->string('action', 20);
            $t->string('reason', 500);
            $t->foreignId('actor_id')->constrained('users');
            $t->unsignedInteger('revision');
            $t->string('request_key', 64)->unique();
            $t->string('fingerprint', 64);
            $t->timestamp('created_at');
            $t->unique(['partner_id', 'revision']);
        });
        foreach ($this->tables() as $table) {
            if (DB::getDriverName() === 'sqlite') {
                foreach (['UPDATE', 'DELETE'] as $event) {
                    DB::unprepared("CREATE TRIGGER {$table}_no_".strtolower($event)." BEFORE {$event} ON {$table} BEGIN SELECT RAISE(ABORT, 'Retail partner history is immutable'); END");
                }
            } elseif (DB::getDriverName() === 'pgsql') {
                DB::unprepared("CREATE TRIGGER {$table}_immutable BEFORE UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION finance_reject_mutation()");
            } else {
                throw new RuntimeException('Retail partners require SQLite or PostgreSQL.');
            }
        }
    }

    private function tables(): array
    {
        return ['retail_partners', 'retail_partner_versions', 'retail_partner_events'];
    }

    public function down(): void
    {
        foreach (array_reverse($this->tables()) as $table) {
            if (! app()->environment('testing') && DB::table($table)->exists()) {
                throw new RuntimeException('Refusing to discard retail partner history.');
            }
            Schema::dropIfExists($table);
        }
    }
};
