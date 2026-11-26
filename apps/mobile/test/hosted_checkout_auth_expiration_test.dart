import 'package:creabiz_ev/api.dart';
import 'package:creabiz_ev/hosted_checkout.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'vendor_meter_auth_expiration_test.dart'
    show BufferedTransportApi, GatedVault;

class InitializedCheckoutRejection extends BufferedTransportApi {
  InitializedCheckoutRejection(super.code);
  @override
  Future<dynamic> request(
    String path, {
    String method = 'GET',
    Map<String, dynamic>? body,
    Map<String, String> query = const {},
  }) {
    if (path == 'me') return Future.value({'id': 1, 'role': 'customer'});
    return super.request(path, method: method, body: body, query: query);
  }
}

Widget checkout(EvApi api, VoidCallback expired) => MaterialApp(
  home: HostedCheckoutView(api: api, onExpired: expired),
);
void main() {
  for (final code in [401, 419]) {
    testWidgets(
      'actual checkout $code expires own session after credential invalidation',
      (t) async {
        t.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
        final api = BufferedTransportApi(code);
        addTearDown(api.close);
        var expired = 0;
        await t.pumpWidget(checkout(api, () => expired++));
        await t.runAsync(api.deliver);
        await t.pump();
        expect(expired, 1);
        expect(find.textContaining('PRIVATE'), findsNothing);
      },
    );
  }
  testWidgets(
    'real rejection of initialized checkout history expires its owner',
    (t) async {
      t.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
      final api = InitializedCheckoutRejection(401);
      addTearDown(api.close);
      var expired = 0;
      await t.pumpWidget(checkout(api, () => expired++));
      await t.pump();
      await t.runAsync(api.deliver);
      await t.pump();
      expect(expired, 1);
    },
  );
  testWidgets(
    'failed secure deletion still expires current checkout truthfully',
    (t) async {
      t.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
      final api = (await t.runAsync(() async {
        final vault = GatedVault()
          ..hold = false
          ..fail = true;
        return BufferedTransportApi(401, vault: vault);
      }))!;
      addTearDown(api.close);
      var expired = 0;
      await t.pumpWidget(checkout(api, () => expired++));
      await t.runAsync(api.deliver);
      await t.pump();
      expect(expired, 1);
    },
  );
  testWidgets(
    'old checkout rejection does not expire newer credential transition',
    (t) async {
      t.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
      late GatedVault vault;
      final api = (await t.runAsync(() async {
        vault = GatedVault();
        return BufferedTransportApi(401, vault: vault);
      }))!;
      addTearDown(api.close);
      var expired = 0;
      await t.pumpWidget(checkout(api, () => expired++));
      await t.runAsync(() async {
        final delivery = api.deliver();
        await vault.started.future;
        final newer = api.forgetSession();
        vault.hold = false;
        vault.release.complete();
        await Future.wait([delivery, newer]);
      });
      await t.pump();
      expect(expired, 0);
    },
  );
  testWidgets('background invalidates old checkout rejection callback', (
    t,
  ) async {
    t.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
    final api = BufferedTransportApi(401);
    addTearDown(api.close);
    var expired = 0;
    await t.pumpWidget(checkout(api, () => expired++));
    t.binding.handleAppLifecycleStateChanged(AppLifecycleState.inactive);
    await t.pump();
    await t.runAsync(api.deliver);
    await t.pump();
    expect(expired, 0);
    await t.pumpWidget(const SizedBox());
    t.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
  });
}
