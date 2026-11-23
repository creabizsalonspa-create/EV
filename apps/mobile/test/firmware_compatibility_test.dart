import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:creabiz_ev/charger_firmware.dart';
import 'charger_firmware_test.dart' as firmware;

void main() {
  for (final ar in [false, true]) {
    testWidgets(
      'signed source compatibility is shown without claiming physical installation ($ar)',
      (tester) async {
        final api = firmware.FirmwareApi()..policy = true;
        await tester.pumpWidget(
          MaterialApp(
            locale: Locale(ar ? 'ar' : 'en'),
            supportedLocales: const [Locale('en'), Locale('ar')],
            localizationsDelegates: GlobalMaterialLocalizations.delegates,
            home: ChargerFirmwareView(
              api: api,
              companyId: 1,
              chargerId: 2,
              manufacturer: 'Synthetic',
              model: 'Fixture',
              onExpired: () {},
            ),
          ),
        );
        await tester.pumpAndSettle();
        expect(
          find.text(
            ar
                ? 'سياسة توافق موقعة معتمدة'
                : 'Approved signed compatibility policy',
          ),
          findsOneWidget,
        );
        expect(find.textContaining('sim-fw-v1'), findsOneWidget);
        expect(
          find.textContaining(
            ar
                ? 'لا يثبت ذلك التثبيت الفعلي'
                : 'does not verify physical installation',
          ),
          findsOneWidget,
        );
        expect(api.attempts, isEmpty);
      },
    );
  }
  for (final ar in [false, true]) {
    testWidgets(
      'operator reads the source-bound firmware matrix explicitly without dispatch ($ar)',
      (tester) async {
        final api = firmware.FirmwareApi()..policy = true;
        await tester.pumpWidget(
          MaterialApp(
            locale: Locale(ar ? 'ar' : 'en'),
            supportedLocales: const [Locale('en'), Locale('ar')],
            localizationsDelegates: GlobalMaterialLocalizations.delegates,
            home: ChargerFirmwareView(
              api: api,
              companyId: 1,
              chargerId: 2,
              manufacturer: 'Synthetic',
              model: 'Fixture',
              onExpired: () {},
            ),
          ),
        );
        await tester.pumpAndSettle();
        expect(api.compatibilityReads, 0);
        final label = ar
            ? 'فحص توافق البرنامج الثابت مع المصدر المباشر'
            : 'Check live source firmware compatibility';
        final check = find.text(label);
        await tester.ensureVisible(check);
        await tester.tap(check);
        await tester.pumpAndSettle();
        expect(api.compatibilityReads, 1);
        expect(
          find.text(ar ? 'لقطة مصدر موثقة' : 'Authenticated source snapshot'),
          findsOneWidget,
        );
        expect(
          find.textContaining(ar ? 'تطابق مصدر موقع' : 'Signed source match'),
          findsOneWidget,
        );
        expect(api.attempts, isEmpty);
      },
    );
  }

  testWidgets(
    'historical model-only releases are not labelled as signed upgrade paths',
    (tester) async {
      final api = firmware.FirmwareApi();
      await tester.pumpWidget(
        MaterialApp(
          home: ChargerFirmwareView(
            api: api,
            companyId: 1,
            chargerId: 2,
            manufacturer: 'Synthetic',
            model: 'Fixture',
            onExpired: () {},
          ),
        ),
      );
      await tester.pumpAndSettle();
      expect(
        find.text('Legacy model check; no declared signed upgrade path'),
        findsOneWidget,
      );
      expect(find.text('Approved signed compatibility policy'), findsNothing);
      expect(api.attempts, isEmpty);
    },
  );
  testWidgets(
    'proven application block offers explicit cancellation without firmware resend',
    (tester) async {
      final api = firmware.FirmwareApi()..blocked = true;
      await tester.pumpWidget(
        MaterialApp(
          home: ChargerFirmwareView(
            api: api,
            companyId: 1,
            chargerId: 2,
            manufacturer: 'Synthetic',
            model: 'Fixture',
            onExpired: () {},
          ),
        ),
      );
      await tester.pumpAndSettle();
      final cancel = find.text('Cancel firmware before dispatch');
      // The source-compatibility inspection adds a disclosure above the
      // operations. Scroll the list before tapping the lower review action:
      // otherwise Flutter's 600px test viewport can miss the button.
      await tester.drag(find.byType(ListView), const Offset(0, -240));
      await tester.pumpAndSettle();
      await tester.ensureVisible(cancel);
      await tester.pumpAndSettle();
      await tester.tap(cancel);
      await tester.pumpAndSettle();
      await tester.enterText(
        find.byType(TextFormField),
        'Reviewed positive proof that firmware command was not sent',
      );
      await tester.tap(find.text('Confirm'));
      await tester.pumpAndSettle();
      expect(api.cancellations, [
        {'note': 'Reviewed positive proof that firmware command was not sent'},
      ]);
      expect(api.attempts, isEmpty);
    },
  );
}
