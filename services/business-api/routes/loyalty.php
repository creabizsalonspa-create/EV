<?php

use App\Http\Controllers\LoyaltyController as L;
use Illuminate\Support\Facades\Route;

Route::get('/commercial/loyalty/account', [L::class, 'account']);
Route::get('/commercial/loyalty/ledger', [L::class, 'ledger']);
Route::get('/commercial/loyalty/policies', [L::class, 'policies']);
Route::post('/commercial/loyalty/policies', [L::class, 'publishPolicy']);
Route::get('/commercial/loyalty/rewards', [L::class, 'rewards']);
Route::post('/commercial/loyalty/rewards', [L::class, 'publishReward']);
Route::post('/commercial/loyalty/invoices/{invoice}/earn', [L::class, 'earn'])->where('invoice', '[0-9]{1,18}');
Route::post('/commercial/loyalty/reconcile', [L::class, 'reconcile']);
Route::post('/commercial/loyalty/rewards/{reward}/redeem', [L::class, 'redeem'])->where('reward', '[0-9]{1,18}');
Route::get('/commercial/loyalty/redemptions', [L::class, 'redemptions']);
Route::get('/commercial/loyalty/redemptions/{redemption}', [L::class, 'show'])->where('redemption', '[0-9]{1,18}');
Route::post('/commercial/loyalty/redemptions/{redemption}/{action}', [L::class, 'transition'])->where('redemption', '[0-9]{1,18}')->whereIn('action', ['fulfill', 'reject']);

Route::get('/commercial/coupons', [L::class, 'coupons']);
Route::post('/commercial/coupons', [L::class, 'publishCoupon']);
Route::post('/commercial/coupons/{coupon}/revoke', [L::class, 'revokeCoupon'])->where('coupon', '[0-9]{1,18}');
Route::post('/commercial/coupons/claim', [L::class, 'claimCoupon']);
Route::get('/commercial/coupons/claims', [L::class, 'couponClaims']);
Route::post('/commercial/coupons/claim-receipt', [L::class, 'findCouponReceipt']);
Route::get('/commercial/coupons/eligible', [L::class, 'discoverCoupons']);
Route::post('/commercial/coupons/eligible/{coupon}/claim', [L::class, 'claimListedCoupon'])->where('coupon', '[0-9]{1,18}');

Route::post('/commercial/coupons/publication-receipt', [L::class, 'findCouponPublication']);

Route::post('/commercial/coupons/claim-intents/cancel', [L::class, 'cancelCouponIntent']);

require __DIR__.'/referrals.php';

Route::get('/commercial/noncash/status', [L::class, 'noncashStatus']);
Route::get('/commercial/partner-rewards/offers', [L::class, 'partnerRewardOffers']);
Route::post('/commercial/partner-rewards/offers/{review}/claim', [L::class, 'claimPartnerReward'])->where('review', '[0-9]{1,18}');
Route::get('/commercial/partner-rewards/claims', [L::class, 'partnerRewardClaims']);
Route::post('/commercial/partner-rewards/claim-receipt', [L::class, 'findPartnerRewardReceipt']);
