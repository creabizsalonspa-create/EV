import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:creabiz_ev/api.dart';
import 'package:creabiz_ev/charger_resets.dart';
import 'package:creabiz_ev/charger_firmware.dart';
import 'charger_resets_test.dart' as reset;
import 'charger_firmware_test.dart' as firmware;

void main() {
  for (final fw in [false, true]) {
    testWidgets(
      'maintenance confirmation cannot survive account revocation ($fw)',
      (tester) async {
        final resetApi = reset.ResetApi(), firmwareApi = firmware.FirmwareApi();
        final EvApi api = fw ? firmwareApi : resetApi;
        await tester.pumpWidget(
          MaterialApp(
            home: fw
                ? ChargerFirmwareView(
                    api: api,
                    companyId: 1,
                    chargerId: 2,
                    manufacturer: 'Synthetic',
                    model: 'Fixture',
                    onExpired: () {},
                  )
                : ChargerResetsView(
                    api: api,
                    companyId: 1,
                    chargerId: 2,
                    onExpired: () {},
                  ),
          ),
        );
        await tester.pumpAndSettle();
        final request = find.text(
          fw ? 'Request signed firmware' : 'Request safe reset',
        );
        await tester.ensureVisible(request);
        await tester.tap(request);
        await tester.pumpAndSettle();
        await tester.enterText(
          find.byType(TextFormField),
          'Private maintenance approval note',
        );
        final retained = tester
            .widget<FilledButton>(find.widgetWithText(FilledButton, 'Confirm'))
            .onPressed!;
        resetApi.active = false;
        firmwareApi.active = false;
        retained();
        await tester.pumpAndSettle();
        expect(resetApi.attempts, isEmpty);
        expect(firmwareApi.attempts, isEmpty);
      },
    );
    testWidgets(
      'background clears maintenance note and invalidates retained confirmation ($fw)',
      (tester) async {
        final resetApi = reset.ResetApi(), firmwareApi = firmware.FirmwareApi();
        final EvApi api = fw ? firmwareApi : resetApi;
        await tester.pumpWidget(
          MaterialApp(
            home: fw
                ? ChargerFirmwareView(
                    api: api,
                    companyId: 1,
                    chargerId: 2,
                    manufacturer: 'Synthetic',
                    model: 'Fixture',
                    onExpired: () {},
                  )
                : ChargerResetsView(
                    api: api,
                    companyId: 1,
                    chargerId: 2,
                    onExpired: () {},
                  ),
          ),
        );
        await tester.pumpAndSettle();
        final request = find.text(
          fw ? 'Request signed firmware' : 'Request safe reset',
        );
        await tester.ensureVisible(request);
        await tester.tap(request);
        await tester.pumpAndSettle();
        await tester.enterText(
          find.byType(TextFormField),
          'Private maintenance approval note',
        );
        final retained = tester
            .widget<FilledButton>(find.widgetWithText(FilledButton, 'Confirm'))
            .onPressed!;
        tester.binding.handleAppLifecycleStateChanged(
          AppLifecycleState.inactive,
        );
        await tester.pumpAndSettle();
        expect(
          tester
              .widget<TextFormField>(find.byType(TextFormField))
              .controller!
              .text,
          isEmpty,
        );
        retained();
        await tester.pumpAndSettle();
        expect(resetApi.attempts, isEmpty);
        expect(firmwareApi.attempts, isEmpty);
        tester.binding.handleAppLifecycleStateChanged(
          AppLifecycleState.resumed,
        );
        await tester.pumpAndSettle();
      },
    );
    testWidgets('changed charger invalidates open maintenance consent ($fw)', (
      tester,
    ) async {
      final resetApi = reset.ResetApi(), firmwareApi = firmware.FirmwareApi();
      final EvApi api = fw ? firmwareApi : resetApi;
      final target = ValueNotifier(2);
      addTearDown(target.dispose);
      await tester.pumpWidget(
        MaterialApp(
          home: ValueListenableBuilder<int>(
            valueListenable: target,
            builder: (_, id, child) => fw
                ? ChargerFirmwareView(
                    api: api,
                    companyId: 1,
                    chargerId: id,
                    manufacturer: 'Synthetic',
                    model: 'Fixture',
                    onExpired: () {},
                  )
                : ChargerResetsView(
                    api: api,
                    companyId: 1,
                    chargerId: id,
                    onExpired: () {},
                  ),
          ),
        ),
      );
      await tester.pumpAndSettle();
      final request = find.text(
        fw ? 'Request signed firmware' : 'Request safe reset',
      );
      await tester.ensureVisible(request);
      await tester.tap(request);
      await tester.pumpAndSettle();
      await tester.enterText(
        find.byType(TextFormField),
        'Private maintenance approval note',
      );
      final retained = tester
          .widget<FilledButton>(find.widgetWithText(FilledButton, 'Confirm'))
          .onPressed!;
      target.value = 3;
      await tester.pumpAndSettle();
      retained();
      await tester.pumpAndSettle();
      expect(resetApi.attempts, isEmpty);
      expect(firmwareApi.attempts, isEmpty);
    });
  }
}
