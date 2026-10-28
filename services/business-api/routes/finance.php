<?php

use App\Http\Controllers\FinanceController as F;
use Illuminate\Support\Facades\Route;

// Load under the parent application's authenticated/throttled api or api/v1 prefix.
Route::get('/finance/stations', [F::class, 'stations']);
Route::get('/stations/{station}/tariffs', [F::class, 'tariffs']);
Route::post('/stations/{station}/tariffs', [F::class, 'createTariff']);
Route::get('/finance/invoices', [F::class, 'invoices']);
Route::post('/finance/sessions/{session}/invoice', [F::class, 'issue']);
Route::get('/finance/invoices/{invoice}', [F::class, 'show']);
Route::post('/finance/invoices/{invoice}/simulate-payment', [F::class, 'payment']);
Route::post('/finance/invoices/{invoice}/simulate-refund', [F::class, 'refund']);
Route::get('/finance/invoices/{invoice}/journal', [F::class, 'journal']);

require __DIR__.'/commercial_finance.php';

require __DIR__.'/payments.php';
require __DIR__.'/subscriptions.php';
require __DIR__.'/settlements.php';

require __DIR__.'/subscription_gateway.php';

require __DIR__.'/subscription_credits.php';

require __DIR__.'/recurring_payments.php';

require __DIR__.'/payouts.php';
require __DIR__.'/financial_documents.php';
require __DIR__.'/corporate_billing.php';
require __DIR__.'/external_disputes.php';
require __DIR__.'/loyalty.php';
