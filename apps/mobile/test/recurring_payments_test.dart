import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:creabiz_ev/api.dart';
import 'package:creabiz_ev/recurring_payments.dart';
import 'test_support.dart';

class MandatesApi extends FixtureApi {
  bool revoked = false, lost = false, denied = false;
  final mutations = <Map<String, dynamic>>[];
  @override
  Future<dynamic> request(
    String path, {
    String method = 'GET',
    Map<String, dynamic>? body,
    Map<String, String> query = const {},
  }) async {
    calls.add('$method $path');
    if (denied) throw const ApiException(401, 'private provider diagnostic');
    if (method != 'GET') {
      mutations.add({'path': path, 'method': method, ...?body});
      if (lost) throw const ApiException(0, 'private provider diagnostic');
      if (method == 'DELETE') revoked = true;
      return {
        'id': 8,
        'status': 'pending',
        'hosted_url': path.endsWith('payment-enrollments')
            ? 'https://checkout.stripe.com/c/pay/test'
            : null,
      };
    }
    if (path.endsWith('payment-methods'))
      return {
        'methods': {
          'data': [
            {
              'id': 7,
              'brand': 'visa',
              'last_four': '4242',
              'exp_month': 12,
              'exp_year': 2030,
              'status': revoked ? 'revoked' : 'active',
              'version': revoked ? 2 : 1,
              'detach_status': 'pending',
              'consent_expires_at': '2030-01-01',
            },
          ],
          'current_page': 1,
          'last_page': 2,
        },
        'default_method_id': 7,
        'version': 1,
        'consent_version': 'recurring-v1',
        'hosted_available': true,
        'collection_available': true,
      };
    return {
      'data': [
        {'id': 8, 'status': 'pending', 'expires_at': '2030-01-01'},
      ],
      'current_page': 1,
      'last_page': 1,
    };
  }
}

Widget host(
  MandatesApi api, {
  bool arabic = false,
  VoidCallback? expired,
  Future<bool> Function(Uri)? opener,
}) => MaterialApp(
  locale: Locale(arabic ? 'ar' : 'en'),
  supportedLocales: const [Locale('en'), Locale('ar')],
  localizationsDelegates: GlobalMaterialLocalizations.delegates,
  home: RecurringPaymentsView(
    api: api,
    onExpired: expired ?? () {},
    openProvider: opener,
  ),
);
void main() {
  test('provider URLs reject alternate hosts, credentials and non HTTPS', () {
    expect(
      trustedPaymentUrl(Uri.parse('https://checkout.stripe.com/c/pay/test')),
      isTrue,
    );
    for (final s in [
      'http://checkout.stripe.com',
      'https://checkout.stripe.com.evil.test',
      'https://user@hooks.stripe.com',
      'https://hooks.stripe.com:8443',
    ])
      expect(trustedPaymentUrl(Uri.parse(s)), isFalse);
    expect(recurringEn.keys.toSet(), recurringAr.keys.toSet());
  });
  testWidgets(
    'masked methods revoke with expected version and refresh without replay',
    (tester) async {
      final api = MandatesApi();
      await tester.pumpWidget(host(api));
      await tester.pumpAndSettle();
      expect(find.text('visa •••• 4242'), findsOneWidget);
      await tester.ensureVisible(find.text('Revoke authorization'));
      await tester.tap(find.text('Revoke authorization'));
      await tester.pumpAndSettle();
      expect(api.mutations.single['expected_version'], 1);
      expect(api.mutations.single['method'], 'DELETE');
      await tester.ensureVisible(find.text('Refresh status'));
      await tester.tap(find.text('Refresh status'));
      await tester.pumpAndSettle();
      expect(api.mutations.length, 1);
      expect(find.text('Remove from provider'), findsOneWidget);
    },
  );
  testWidgets(
    'explicit consent launches only trusted hosted flow and opens enrollment status',
    (tester) async {
      final api = MandatesApi();
      Uri? opened;
      await tester.pumpWidget(
        host(
          api,
          opener: (u) async {
            opened = u;
            return true;
          },
        ),
      );
      await tester.pumpAndSettle();
      final add = find.widgetWithText(FilledButton, 'Add with hosted provider');
      expect(tester.widget<FilledButton>(add).onPressed, isNull);
      await tester.tap(find.byType(Checkbox));
      await tester.pumpAndSettle();
      await tester.tap(add);
      await tester.pumpAndSettle();
      expect(api.mutations.single['consent'], true);
      expect(api.mutations.single.containsKey('payment_method'), isFalse);
      await tester.tap(find.text('Continue securely with provider'));
      await tester.pumpAndSettle();
      expect(opened?.host, 'checkout.stripe.com');
      expect(find.text('#8 · pending'), findsOneWidget);
    },
  );
  testWidgets(
    'lost mutation is not replayed by refresh and expired session clears private rows',
    (tester) async {
      final api = MandatesApi();
      int expired = 0;
      await tester.pumpWidget(host(api, expired: () => expired++));
      await tester.pumpAndSettle();
      api.lost = true;
      await tester.ensureVisible(find.text('Revoke authorization'));
      await tester.tap(find.text('Revoke authorization'));
      await tester.pumpAndSettle();
      expect(find.textContaining('private provider'), findsNothing);
      expect(find.text(recurringEn['unknown']!), findsOneWidget);
      api.denied = true;
      await tester.ensureVisible(find.text('Refresh status'));
      await tester.tap(find.text('Refresh status'));
      await tester.pumpAndSettle();
      expect(expired, 1);
      expect(find.text('visa •••• 4242'), findsNothing);
      expect(api.mutations.length, 1);
    },
  );
  testWidgets('Arabic uses RTL with equivalent saved authorization controls', (
    tester,
  ) async {
    await tester.pumpWidget(host(MandatesApi(), arabic: true));
    await tester.pumpAndSettle();
    expect(find.text(recurringAr['title']!), findsOneWidget);
    expect(
      Directionality.of(tester.element(find.text('visa •••• 4242'))),
      TextDirection.rtl,
    );
    expect(find.text('إلغاء التفويض'), findsOneWidget);
  });
}
