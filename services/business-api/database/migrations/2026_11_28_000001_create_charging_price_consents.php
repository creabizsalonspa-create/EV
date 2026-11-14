<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('charging_price_consents', function (Blueprint $t) {
            $t->id();
            $t->foreignId('reservation_id')->constrained()->restrictOnDelete();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->unsignedBigInteger('auth_epoch');
            $t->char('token_digest', 64)->unique();
            $t->char('price_fingerprint', 64);
            $t->text('estimate');
            $t->timestamp('accepted_at');
            $t->timestamp('valid_until');
            $t->index(['reservation_id', 'user_id', 'valid_until'], 'charging_price_consent_lookup');
        });
        Schema::table('finance_session_quotes', fn (Blueprint $t) => $t->foreignId('price_consent_id')->nullable()->constrained('charging_price_consents')->restrictOnDelete());
        if (DB::getDriverName() === 'sqlite') {
            foreach (['UPDATE', 'DELETE'] as $event) {
                DB::unprepared('CREATE TRIGGER charging_price_consents_no_'.strtolower($event)." BEFORE {$event} ON charging_price_consents BEGIN SELECT RAISE(ABORT, 'Price consent receipts are immutable'); END");
            }
        } elseif (DB::getDriverName() === 'pgsql') {
            DB::unprepared('CREATE TRIGGER charging_price_consents_immutable BEFORE UPDATE OR DELETE ON charging_price_consents FOR EACH ROW EXECUTE FUNCTION finance_reject_mutation()');
        }
    }

    public function down(): void
    {
        if (DB::table('charging_price_consents')->exists() || (DB::getDriverName() === 'sqlite' && DB::table('finance_session_quotes')->exists())) {
            throw new RuntimeException('Preserve accepted pricing and canonical quote history; use a forward migration.');
        }
        Schema::table('finance_session_quotes', fn (Blueprint $t) => $t->dropConstrainedForeignId('price_consent_id'));
        Schema::dropIfExists('charging_price_consents');
    }
};
