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
      expect(reward['available_stock'], 1);
      final detail = await api.request(
        'commercial/loyalty/redemptions/${f['redemption_id']}',
      );
      expect(detail['redemption']['status'], 'pending');
      expect(detail['reward']['id'], f['reward_id']);
      expect(detail['redemption'].containsKey('request_key'), false);
      expect((detail['events'] as List).isNotEmpty, true);
      await api.signOut();
      await api.signIn(f['foreign'], f['password']);
      expect(
        (await api.request('commercial/loyalty/account'))['balance_points'],
        0,
      );
      expect((await api.request('commercial/loyalty/redemptions'))['total'], 0);
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
