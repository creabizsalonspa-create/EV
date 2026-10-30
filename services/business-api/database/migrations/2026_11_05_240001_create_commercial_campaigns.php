<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commercial_campaigns', function (Blueprint $t) {
            $t->id();
            $t->foreignId('vendor_id')->constrained('users');
            $t->foreignId('invoice_id')->constrained('commercial_subscription_invoices');
            $t->foreignId('station_id')->constrained('stations');
            $t->string('title', 120);
            $t->string('title_ar', 120);
            $t->string('description', 500);
            $t->string('description_ar', 500);
            $t->string('country', 2);
            $t->string('region', 100)->nullable();
            $t->string('placement', 32);
            $t->unsignedInteger('budget_units');
            $t->timestamp('starts_at');
            $t->timestamp('ends_at');
            $t->string('request_key', 64)->unique();
            $t->string('fingerprint', 64);
            $t->string('status', 24)->default('draft');
            $t->timestamp('created_at');
            $t->timestamp('updated_at');
            $t->index(['invoice_id', 'status']);
            $t->index(['status', 'country', 'placement', 'starts_at']);
        });
        Schema::create('commercial_campaign_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('campaign_id')->constrained('commercial_campaigns');
            $t->foreignId('actor_id')->constrained('users');
            $t->string('action', 32);
            $t->string('reason', 500);
            $t->timestamp('created_at');
        });
        Schema::create('commercial_campaign_deliveries', function (Blueprint $t) {
            $t->id();
            $t->foreignId('campaign_id')->constrained('commercial_campaigns');
            $t->string('event_id', 100)->unique();
            $t->string('fingerprint', 64);
            $t->foreignId('parent_delivery_id')->nullable()->unique()->constrained('commercial_campaign_deliveries');
            $t->string('kind', 16);
            $t->timestamp('occurred_at');
            $t->timestamp('created_at');
            $t->index(['campaign_id', 'kind']);
        });
        $columns = ['vendor_id', 'invoice_id', 'station_id', 'title', 'title_ar', 'description', 'description_ar', 'country', 'region', 'placement', 'budget_units', 'starts_at', 'ends_at', 'request_key', 'fingerprint', 'created_at'];
        if (DB::getDriverName() === 'sqlite') {
            $different = implode(' OR ', array_map(fn ($c) => "NEW.{$c} IS NOT OLD.{$c}", $columns));
            DB::unprepared("CREATE TRIGGER campaign_terms_immutable BEFORE UPDATE ON commercial_campaigns WHEN {$different} BEGIN SELECT RAISE(ABORT, 'Campaign terms are immutable'); END");
            DB::unprepared("CREATE TRIGGER campaign_no_delete BEFORE DELETE ON commercial_campaigns BEGIN SELECT RAISE(ABORT, 'Campaign records are immutable'); END");
        } elseif (DB::getDriverName() === 'pgsql') {
            $new = implode(', ', array_map(fn ($c) => 'NEW.'.$c, $columns));
            $old = implode(', ', array_map(fn ($c) => 'OLD.'.$c, $columns));
            DB::unprepared("CREATE OR REPLACE FUNCTION reject_campaign_terms() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN IF ROW({$new}) IS DISTINCT FROM ROW({$old}) THEN RAISE EXCEPTION 'Campaign terms are immutable'; END IF; RETURN NEW; END $$");
            DB::unprepared('CREATE TRIGGER campaign_terms_immutable BEFORE UPDATE ON commercial_campaigns FOR EACH ROW EXECUTE FUNCTION reject_campaign_terms()');
            DB::unprepared('CREATE TRIGGER campaign_no_delete BEFORE DELETE ON commercial_campaigns FOR EACH ROW EXECUTE FUNCTION finance_reject_mutation()');
        } else {
            throw new RuntimeException('Campaign integrity requires PostgreSQL or SQLite.');
        }
        foreach (['commercial_campaign_events', 'commercial_campaign_deliveries'] as $table) {
            if (DB::getDriverName() === 'sqlite') {
                foreach (['UPDATE', 'DELETE'] as $event) {
                    DB::unprepared("CREATE TRIGGER {$table}_no_".strtolower($event)." BEFORE {$event} ON {$table} BEGIN SELECT RAISE(ABORT, 'Campaign audit is immutable'); END");
                }
            } else {
                DB::unprepared("CREATE TRIGGER {$table}_immutable BEFORE UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION finance_reject_mutation()");
            }
        }
    }

    public function down(): void
    {
        $tables = ['commercial_campaign_deliveries', 'commercial_campaign_events', 'commercial_campaigns'];
        if (! app()->environment('testing')) {
            foreach ($tables as $table) {
                if (DB::table($table)->exists()) {
                    throw new RuntimeException('Preserve campaign billing and audit history before downgrade.');
                }
            }
        }
        foreach ($tables as $table) {
            Schema::dropIfExists($table);
        }
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS reject_campaign_terms()');
        }
    }
};
