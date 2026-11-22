import 'dart:convert';
import 'dart:io';
import 'package:flutter_test/flutter_test.dart';
import 'package:creabiz_ev/api.dart';

void main() {
  final path = Platform.environment['EV_NATIVE_LOYALTY_FIXTURE'];
  test(
    'native actual Laravel points reward history and private owner scope use canonical finance evidence',
    () async {
      final f =
          jsonDecode(await File(path!).readAsString()) as Map<String, dynamic>;
      expect(f['fixture_only'], true);
      final api = EvApi(Uri.parse('http://127.0.0.1:8000'));
      addTearDown(api.close);
      await api.signIn(f['customer'], f['password']);
      final account = await api.request('commercial/loyalty/account');
      expect(account['cash_value'], false);
      expect(account['transferable'], false);
      expect(account['unit'], 'NON_MONETARY_POINTS');
      expect(account['balance_points'], 70);
      expect(account['spendable_points'], 70);
      expect(account['tier_by_currency']['AED']['tier'], 'Gold');
      final ledger = await api.request('commercial/loyalty/ledger');
      expect((ledger['data'] as List).length, 2);
      expect((ledger['data'] as List).map((r) => r['points']).toList(), [
        -30,
        100,
      ]);
      final rewards = await api.request('commercial/loyalty/rewards');
      final reward = (rewards['data'] as List).singleWhere(
        (r) => r['id'] == f['reward_id'],
      );
      expect(reward['cash_value'], false);
      expect(reward['fulfillment'], 'MANUAL_NON_MONETARY');
      expect(reward['available_stock'], 2);
      final detail = await api.request(
        'commercial/loyalty/redemptions/${f['redemption_id']}',
      );
      expect(detail['redemption']['status'], 'pending');
      expect(detail['reward']['id'], f['reward_id']);
      expect(detail['redemption'].containsKey('request_key'), false);
      expect((detail['events'] as List).isNotEmpty, true);
      final admin = EvApi(Uri.parse('http://127.0.0.1:8000'));
      addTearDown(admin.close);
      await admin.signIn(f['admin'], f['password']);
      Future<int> publish(bool listed) async {
        final coupon = await admin.request(
          'commercial/coupons',
          method: 'POST',
          body: {
            'code': f[listed ? 'coupon_listed_code' : 'coupon_private_code'],
            'listed': listed,
            'reward_id': f['reward_id'],
            'starts_at': f['coupon_from'],
            'ends_at': f['coupon_until'],
            'claim_limit': 1,
            'per_user_limit': 1,
            'idempotency_key': operationKey(),
          },
        );
        return coupon['id'] as int;
      }

      final listed = await publish(true);
      final privateCoupon = await publish(false);
      final offers = await api.request('commercial/coupons/eligible');
      final offerIds = (offers['data'] as List).map((row) => row['id']);
      expect(offerIds, contains(listed));
      expect(offerIds, isNot(contains(privateCoupon)));
      final listedOffer = (offers['data'] as List).singleWhere(
        (row) => row['id'] == listed,
      );
      expect(listedOffer['reward_name_ar'], reward['name_ar']);
      final publicReceipt = (await api.request(
        'commercial/coupons/eligible/$listed/claim',
        method: 'POST',
        body: {'idempotency_key': operationKey()},
      ))['claim'];
      expect(publicReceipt['status'], 'pending');
      expect(publicReceipt['cash_value'], false);
      final privateKey = operationKey();
      Future<dynamic> claimPrivate() async => (await api.request(
        'commercial/coupons/claim',
        method: 'POST',
        body: {'code': f['coupon_private_code'], 'idempotency_key': privateKey},
      ))['claim'];

      final privateReceipt = await claimPrivate();
      expect(privateReceipt['coupon_id'], privateCoupon);
      expect(privateReceipt['points_cost'], 0);
      await admin.request(
        'commercial/coupons/$privateCoupon/revoke',
        method: 'POST',
        body: {'reason': 'Synthetic native coupon revocation'},
      );
      expect((await claimPrivate())['id'], privateReceipt['id']);
      await admin.request(
        'commercial/loyalty/redemptions/${privateReceipt['redemption_id']}/fulfill',
        method: 'POST',
        body: {
          'reason': 'Synthetic native coupon reward decision',
          'idempotency_key': operationKey(),
        },
      );
      Future<dynamic> recover() => api.request(
        'commercial/coupons/claim-receipt',
        method: 'POST',
        body: {'idempotency_key': privateKey},
      );
      final recovered = (await recover())['claim'];
      expect(recovered['id'], privateReceipt['id']);
      expect(recovered['status'], 'fulfilled');
      expect((await api.request('commercial/coupons/claims'))['total'], 2);
      expect(
        (await api.request('commercial/loyalty/account'))['balance_points'],
        70,
      );
      await api.signOut();
      await api.signIn(f['foreign'], f['password']);
      expect(
        (await api.request('commercial/loyalty/account'))['balance_points'],
        0,
      );
      expect((await api.request('commercial/loyalty/redemptions'))['total'], 0);
      expect((await api.request('commercial/coupons/claims'))['total'], 0);
      expect((await recover())['claim'], isNull);
      await expectLater(
        api.request('commercial/loyalty/redemptions/${f['redemption_id']}'),
        throwsA(isA<ApiException>().having((e) => e.status, 'status', 404)),
      );
      await api.signOut();
      await api.signIn(f['vendor'], f['password']);
      expect(
        (await api.request('commercial/loyalty/account'))['balance_points'],
        0,
      );
      await expectLater(
        api.request('commercial/loyalty/redemptions/${f['redemption_id']}'),
        throwsA(isA<ApiException>().having((e) => e.status, 'status', 404)),
      );
    },
    skip: path == null
        ? 'Run node tests/native-loyalty.cjs against isolated local mock fixtures'
        : false,
  );
}
