import 'dart:convert';
import 'dart:io';
import 'package:creabiz_ev/api.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  final path = Platform.environment['EV_NATIVE_REFERRALS_FIXTURE'];

  test(
    'connected native referrals preserve consent, qualification and account isolation',
    () async {
      final fixture =
          jsonDecode(await File(path!).readAsString()) as Map<String, dynamic>;
      expect(fixture['fixture_only'], true);
      final users = fixture['users'] as Map<String, dynamic>;
      final rewards = fixture['rewards'] as Map<String, dynamic>;
      final origin = Uri.parse('http://127.0.0.1:8000');

      Future<EvApi> login(String role) async {
        final api = EvApi(origin);
        addTearDown(api.close);
        await api.signIn(users[role] as String, fixture['password'] as String);
        return api;
      }

      final admin = await login('admin');
      final now = DateTime.now().toUtc();
      final published = await admin.request(
        'commercial/referral-programs',
        method: 'POST',
        body: {
          'name': 'Synthetic native referral',
          'name_ar': 'إحالة تجريبية',
          'terms':
              'Accept these terms before charging. Eligible rewards require manual noncash fulfillment.',
          'terms_ar':
              'اقبل هذه الشروط قبل الشحن، وتتطلب المكافآت المؤهلة تسليماً يدوياً غير نقدي.',
          'currency': 'AED',
          'minor_units': 2,
          'minimum_minor': 100,
          'referrer_reward_id': rewards['referrer'],
          'member_reward_id': rewards['member'],
          'claim_limit': 4,
          'per_referrer_limit': 2,
          'starts_at': now
              .subtract(const Duration(minutes: 1))
              .toIso8601String(),
          'ends_at': now.add(const Duration(hours: 1)).toIso8601String(),
          'idempotency_key': operationKey(),
        },
      );
      final programme = published['program'];
      final inviter = await login('referrer');
      final issued = await inviter.request(
        'commercial/referrals/codes',
        method: 'POST',
        body: {
          'program_id': programme['id'],
          'idempotency_key': operationKey(),
        },
      );
      final member = await login('member');
      expect(
        (await member.request('commercial/referrals/account'))['referral'],
        isNull,
      );
      final accept = {
        'code': issued['code'],
        'terms_version': programme['terms_version'],
        'consent': true,
        'idempotency_key': operationKey(),
      };
      final receipt = await member.request(
        'commercial/referrals/accept',
        method: 'POST',
        body: accept,
      );
      final accepted = receipt['referral'];
      expect(accepted['status'], 'accepted');
      expect(accepted['cash_value'], false);
      final account = await member.request('commercial/referrals/account');
      expect(account['referral']['id'], accepted['id']);
      expect(
        account['referral']['request_binding'],
        accepted['request_binding'],
      );
      expect(
        (await inviter.request('commercial/referrals/account'))['referral'],
        isNull,
      );

      final invoice = await Process.run('../../scripts/php', [
        'tests/Fixtures/referral-admin-browser.php',
        'invoice',
        fixture['run'] as String,
        'synthetic-only',
      ]);
      expect(invoice.exitCode, 0, reason: 'Synthetic invoice fixture failed');
      final source =
          jsonDecode(invoice.stdout as String) as Map<String, dynamic>;
      expect(source['fixture_only'], true);
      final qualified = await member.request(
        'commercial/referrals/qualify',
        method: 'POST',
        body: {
          'invoice_id': source['invoice_id'],
          'idempotency_key': operationKey(),
        },
      );
      expect(qualified['referral']['status'], 'qualified');
      for (final api in [member, inviter]) {
        final history = await api.request('commercial/referrals/rewards');
        final reward = (history['data'] as List).single as Map;
        expect(reward['status'], 'pending');
        expect(reward['points_cost'], 0);
        expect(reward['cash_value'], false);
        expect(reward['delivery_verified'], false);
        expect(reward.containsKey('invoice_id'), false);
      }
    },
    skip: path == null,
  );
}
