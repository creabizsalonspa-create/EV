<?php

use App\Services\Commercial\CorporateBilling;
use Illuminate\Support\Facades\DB;

return static function (): array {
    $f = (require __DIR__.'/corporate-billing.php')(true, 10000);
    $f['statement'] = app(CorporateBilling::class)->issue($f['a']->id, $f['owner']->id, ['period' => now()->format('Y-m'), 'claim_ids' => array_map(fn ($c) => $c->id, $f['claims']), 'idempotency_key' => 'hosted-statement']);
    $f['lines'] = DB::table('corporate_statement_lines')->where('statement_id', $f['statement']->id)->orderBy('invoice_id')->get();

    return $f;
};
