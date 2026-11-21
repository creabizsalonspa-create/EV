// Opt-in local synthetic Laravel HTTP integration; never a real account.
import 'dart:convert';
import 'dart:io';
import 'package:creabiz_ev/account_recovery.dart';
import 'package:creabiz_ev/api.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  final path = Platform.environment['EV_NATIVE_RECOVERY_FIXTURE'];
  test(
    'actual Laravel reset revokes old browser session and token is single-use',
    () async {
      final f =
          jsonDecode(await File(path!).readAsString()) as Map<String, dynamic>;
      expect(f['fixture_only'], isTrue);
      final base = Uri.parse(f['base']);
      expect(base.scheme, 'http');
      expect(['localhost', '127.0.0.1'], contains(base.host));
      final original = EvApi(base), recovery = EvApi(base), fresh = EvApi(base);
      addTearDown(original.close);
      addTearDown(recovery.close);
      addTearDown(fresh.close);
      await original.signIn(f['email'], f['password']);
      await recovery.csrf();
      final link = base
          .resolve('/reset-password/${f['token']}')
          .replace(queryParameters: {'email': f['email']})
          .toString();
      final parsed = RecoveryLink.parse(link, base)!;
      final body = <String, dynamic>{
        'email': parsed.email,
        'token': parsed.token,
        'password': f['new_password'],
        'password_confirmation': f['new_password'],
      };
      await recovery.request('reset-password', method: 'POST', body: body);
      await expectLater(
        original.request('me'),
        throwsA(
          isA<ApiException>().having((e) => e.status, 'old session', 401),
        ),
      );
      await expectLater(
        recovery.request('reset-password', method: 'POST', body: body),
        throwsA(isA<ApiException>().having((e) => e.status, 'single use', 422)),
      );
      final account = await fresh.signIn(f['email'], f['new_password']);
      expect(account.role, f['role']);
      await fresh.signOut();
      body.clear();
      f.clear();
    },
    skip: path == null
        ? 'Requires owned local synthetic recovery fixture.'
        : false,
  );
}
