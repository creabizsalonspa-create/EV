import 'dart:convert';
import 'dart:io';
import 'package:creabiz_ev/api.dart';
import 'package:creabiz_ev/subscription_preview.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  final path = Platform.environment['EV_NATIVE_SUBSCRIPTION_PREVIEW_FIXTURE'];
  test(
    'actual native cookie preview preserves receipts and enforces separate Admin bearer denial',
    () async {
      final f = jsonDecode(await File(path!).readAsString());
      expect(f['fixture_only'], true);
      expect(f['real_payment_processed'], false);
      final base = Uri.parse(f['base']);
      expect(base.scheme, 'http');
      expect(['127.0.0.1', 'localhost'], contains(base.host));
      final clients = <EvApi>[];
      addTearDown(() {
        for (final api in clients) {
          api.close();
        }
      });
      Future<EvApi> login(String name) async {
        final api = EvApi(base);
        clients.add(api);
        await api.signIn(f[name], f['password']);
        return api;
      }

      final owner = await login('owner'), id = f['subscription'] as int;
      final route = 'commercial/subscriptions/$id';
      final before = await owner.request(route);
      expect(before['entitlements_active'], true);
      expect(before['ledger_balanced'], true);
      expect((before['invoices'] as List).length, 1);
      expect((before['receipts'] as List).length, 1);
      final events = await owner.request('$route/events');
      for (final cadence in ['monthly', 'annual']) {
        final target = f[cadence] as int;
        final raw = await owner.request(
          '$route/change-preview',
          query: {'plan_id': '$target'},
        );
        final preview = SubscriptionChangePreview.parse(
          raw,
          subscriptionId: id,
          planId: target,
        );
        expect(
          preview.values['next_cycle_tax_minor'],
          cadence == 'monthly' ? 150 : 300,
        );
        expect(
          preview.values['next_cycle_total_minor'],
          cadence == 'monthly' ? 3149 : 6299,
        );
        expect(preview.values['amount_due_now_minor'], 0);
        expect(preview.values['cycles_comparable'], cadence == 'monthly');
        expect(
          preview.values['nominal_plan_list_difference_minor'],
          cadence == 'monthly' ? 2100 : null,
        );
      }
      final after = await owner.request(route);
      expect(jsonEncode(after), jsonEncode(before));
      expect(
        jsonEncode((await owner.request('$route/events'))['data']),
        jsonEncode(events['data']),
      );
      final foreign = await login('foreign');
      await expectLater(
        foreign.request(
          '$route/change-preview',
          query: {'plan_id': '${f['monthly']}'},
        ),
        throwsA(isA<ApiException>().having((e) => e.status, 'status', 404)),
      );
      // The canonical EvApi uses cookie sessions. An authorized web Admin
      // cookie is distinct from a forbidden Admin personal-access token.
      // Exercise that negative bearer boundary separately, never switch the
      // application's transport or weaken legitimate web Admin permissions.
      final http = HttpClient()
        ..connectionTimeout = const Duration(seconds: 15);
      try {
        final request = await http.getUrl(
          base.replace(
            path: '/api/v1/$route/change-preview',
            queryParameters: {'plan_id': '${f['monthly']}'},
          ),
        );
        request.followRedirects = false;
        request.headers.set(
          HttpHeaders.authorizationHeader,
          'Bearer ${f['admin_token']}',
        );
        final response = await request.close().timeout(
          const Duration(seconds: 25),
        );
        expect(response.statusCode, 403);
        await response.drain<void>();
      } finally {
        http.close(force: true);
      }
    },
    skip: path == null
        ? 'Requires owned local Laravel; run tests/native-subscription-preview.cjs.'
        : false,
  );
}
