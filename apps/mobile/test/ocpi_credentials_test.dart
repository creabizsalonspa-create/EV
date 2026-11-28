import 'dart:async';
import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:creabiz_ev/api.dart';
import 'package:creabiz_ev/ocpi_credentials.dart';
import 'test_support.dart';

class CredentialsApi extends FixtureApi {
  int writes = 0;
  bool active = true;
  Completer<dynamic>? delayed;
  @override
  bool Function() sessionFence() =>
      () => active;
  bool lost = false,
      expired = false,
      incoming = false,
      forgedCapability = false;
  bool forgedSandboxPolicy = false;
  bool forgedTokenReadiness = false;
  Map<String, dynamic>? intent;
  @override
  Future<dynamic> request(
    String path, {
    String method = 'GET',
    Map<String, dynamic>? body,
    Map<String, String> query = const {},
  }) async {
    expect(path, 'ocpi/providers/7/credentials');
    if (method == 'POST') {
      intent = body;
      writes++;
      lost = true;
      throw const ApiException(503, 'PRIVATE TOKEN');
    }
    if (delayed != null) return delayed!.future;
    if (expired) throw const ApiException(401, 'PRIVATE TOKEN');
    return {
      'configured': true,
      'configured_receiver_modules': [
        'cdrs',
        'locations',
        'sessions',
        'tariffs',
      ],
      'active_receiver_modules': lost || incoming
          ? []
          : ['locations', 'tariffs'],
      'state': lost
          ? 'outcome_unknown'
          : (incoming ? 'not_exchanged' : 'active'),
      'generation': 1,
      'available_actions': lost
          ? []
          : (incoming ? ['prepare_incoming'] : ['rotate', 'unregister']),
      'charging_commands_enabled': false,
      'billing_eligible': false,
      'capability_matrix': {
        'evidence': 'NEGOTIATED_AND_CONFIGURED_SOFTWARE_ONLY',
        'peer_role': 'CPO',
        'credentials_current': !lost && !incoming,
        'live_roaming_verified': false,
        'physical_availability_verified': false,
        'partner_conformance_verified': false,
        'payment_authority': false,
        'sandbox_command_policy_ready': false,
        'sandbox_reservation_policy_ready': forgedSandboxPolicy,
        'sandbox_token_sender_configured': !forgedTokenReadiness,
        'sandbox_token_sender_request_ready':
            forgedTokenReadiness || (!lost && !incoming),
        'modules': [
          for (final module in ['locations', 'tariffs', 'sessions', 'cdrs'])
            {
              'module': module,
              'partner_sender_negotiated': module == 'locations' && !lost,
              'local_receiver_configured': module != 'tariffs',
              'local_receiver_active': module != 'tariffs' && !lost,
              'local_receiver_request_ready':
                  module == 'locations' && !lost && !incoming,
              'local_sender_configured': false,
              'local_sender_active': forgedCapability && module == 'locations',
              'sandbox_delivery_software_enabled': false,
            },
        ],
      },
      'operations': {
        'current_page': 1,
        'last_page': 1,
        'total': lost ? 1 : 0,
        'data': lost
            ? [
                {
                  'id': 8,
                  'action': 'rotate',
                  'state': 'outcome_unknown',
                  'attempted_at': '2026-11-05T01:00:00Z',
                },
              ]
            : [],
      },
    };
  }
}

void main() {
  for (final ar in [false, true]) {
    testWidgets(
      'source-only OCPI capabilities are shown without charging claims ($ar)',
      (tester) async {
        final api = CredentialsApi();
        await tester.pumpWidget(
          MaterialApp(
            locale: Locale(ar ? 'ar' : 'en'),
            supportedLocales: const [Locale('en'), Locale('ar')],
            localizationsDelegates: const [
              GlobalMaterialLocalizations.delegate,
              GlobalWidgetsLocalizations.delegate,
              GlobalCupertinoLocalizations.delegate,
            ],
            home: OcpiCredentialsView(
              api: api,
              providerId: 7,
              onExpired: () {},
            ),
          ),
        );
        await tester.pumpAndSettle();
        expect(
          find.textContaining(
            ar
                ? 'وحدات المصدر المهيأة'
                : 'Configured/negotiated source modules only',
          ),
          findsOneWidget,
        );
        expect(
          find.textContaining(ar ? 'قراءة الشريك نعم' : 'Partner read yes'),
          findsOneWidget,
        );
        expect(
          find.textContaining(
            ar ? 'سياسة أوامر الاختبار: لا' : 'Sandbox command policy: no',
          ),
          findsOneWidget,
        );
        expect(
          find.textContaining(
            ar
                ? 'مرسل رموز الاختبار جاهز للطلبات: نعم'
                : 'Sandbox token sender ready: yes',
          ),
          findsOneWidget,
        );
        expect(
          find.textContaining(
            ar ? 'استقبال الطلبات جاهز نعم' : 'Receive requests ready yes',
          ),
          findsOneWidget,
        );
        expect(find.textContaining('SYNTHETIC-PEER-BOOTSTRAP'), findsNothing);
        expect(api.writes, 0);
      },
    );
  }

  testWidgets('reservation policy without command policy fails closed', (
    tester,
  ) async {
    final api = CredentialsApi();
    await tester.pumpWidget(
      MaterialApp(
        home: OcpiCredentialsView(api: api, providerId: 7, onExpired: () {}),
      ),
    );
    await tester.pumpAndSettle();
    expect(find.textContaining('Sandbox command policy: no'), findsOneWidget);
    api.forgedSandboxPolicy = true;
    await tester.tap(find.text('Refresh'));
    await tester.pumpAndSettle();
    expect(find.text('Partner credentials unavailable.'), findsOneWidget);
    expect(find.text('Rotate partner credentials'), findsNothing);
    expect(api.writes, 0);
  });

  testWidgets('token sender readiness without its contract fails closed', (
    tester,
  ) async {
    final api = CredentialsApi()..forgedTokenReadiness = true;
    await tester.pumpWidget(
      MaterialApp(
        home: OcpiCredentialsView(api: api, providerId: 7, onExpired: () {}),
      ),
    );
    await tester.pumpAndSettle();

    expect(find.text('Partner credentials unavailable.'), findsOneWidget);
    expect(find.text('Rotate partner credentials'), findsNothing);
    expect(api.writes, 0);
  });

  testWidgets(
    'forged module authority fails closed without retaining prior credential history',
    (tester) async {
      final api = CredentialsApi();
      await tester.pumpWidget(
        MaterialApp(
          home: OcpiCredentialsView(api: api, providerId: 7, onExpired: () {}),
        ),
      );
      await tester.pumpAndSettle();
      expect(find.text('Credentials active'), findsOneWidget);
      api.forgedCapability = true;
      await tester.tap(find.text('Refresh'));
      await tester.pumpAndSettle();
      expect(find.text('Partner credentials unavailable.'), findsOneWidget);
      expect(find.text('Credentials active'), findsNothing);
      expect(find.text('Rotate partner credentials'), findsNothing);
      expect(api.writes, 0);
    },
  );

  testWidgets(
    'retained credential approval cannot dispatch under a different authenticated account',
    (tester) async {
      final api = CredentialsApi();
      await tester.pumpWidget(
        MaterialApp(
          home: OcpiCredentialsView(api: api, providerId: 7, onExpired: () {}),
        ),
      );
      await tester.pumpAndSettle();
      final action = find.text('Rotate partner credentials');
      await tester.scrollUntilVisible(action, 180);
      await tester.tap(action);
      await tester.pumpAndSettle();
      await tester.enterText(
        find.byType(TextField),
        'Approved old account context',
      );
      await tester.pump();
      api.active = false;
      await tester.tap(find.text('Confirm'));
      await tester.pumpAndSettle();
      expect(api.writes, 0);
    },
  );
  testWidgets(
    'background clears approval notes and cannot revive an old credential decision',
    (tester) async {
      final api = CredentialsApi();
      await tester.pumpWidget(
        MaterialApp(
          home: OcpiCredentialsView(api: api, providerId: 7, onExpired: () {}),
        ),
      );
      await tester.pumpAndSettle();
      final action = find.text('Rotate partner credentials');
      await tester.scrollUntilVisible(action, 180);
      await tester.tap(action);
      await tester.pumpAndSettle();
      await tester.enterText(find.byType(TextField), 'PRIVATE APPROVAL NOTE');
      tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.inactive);
      await tester.pump();
      expect(find.text('PRIVATE APPROVAL NOTE'), findsNothing);
      tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
      await tester.pumpAndSettle();
      await tester.enterText(
        find.byType(TextField),
        'A new note cannot revive the old decision',
      );
      await tester.pump();
      expect(
        tester
            .widget<TextButton>(find.widgetWithText(TextButton, 'Confirm'))
            .onPressed,
        isNull,
      );
      expect(api.writes, 0);
    },
  );

  testWidgets(
    'configured catalog modules do not imply active token reception after uncertainty',
    (tester) async {
      final api = CredentialsApi();
      await tester.pumpWidget(
        MaterialApp(
          home: OcpiCredentialsView(api: api, providerId: 7, onExpired: () {}),
        ),
      );
      await tester.pumpAndSettle();
      expect(
        find.text(
          'Configured partner reception: cdrs, locations, sessions, tariffs',
        ),
        findsOneWidget,
      );
      expect(
        find.text('Active credential reception: locations, tariffs'),
        findsOneWidget,
      );
      api.lost = true;
      await tester.tap(find.text('Refresh'));
      await tester.pumpAndSettle();
      expect(
        find.text('Active credential reception: locations, tariffs'),
        findsNothing,
      );
      expect(api.writes, 0);
    },
  );

  testWidgets(
    'incoming enrollment action uses the approved backend workflow without exposing a bootstrap token',
    (tester) async {
      final api = CredentialsApi()..incoming = true;
      await tester.pumpWidget(
        MaterialApp(
          home: OcpiCredentialsView(api: api, providerId: 7, onExpired: () {}),
        ),
      );
      await tester.pumpAndSettle();
      final action = find.text('Prepare incoming enrollment');
      await tester.scrollUntilVisible(action, 180);
      await tester.tap(action);
      await tester.pumpAndSettle();
      await tester.enterText(
        find.byType(TextField),
        'Approved incoming synthetic partner',
      );
      await tester.pumpAndSettle();
      await tester.tap(find.text('Confirm'));
      await tester.pumpAndSettle();
      expect(api.intent!['action'], 'prepare_incoming');
      expect(api.writes, 1);
      expect(find.textContaining('PRIVATE TOKEN'), findsNothing);
      expect(find.text('Prepare incoming enrollment'), findsNothing);
    },
  );

  testWidgets(
    'credentials uncertainty cannot replay and current source history gates the next action',
    (tester) async {
      final api = CredentialsApi();
      await tester.pumpWidget(
        MaterialApp(
          home: OcpiCredentialsView(api: api, providerId: 7, onExpired: () {}),
        ),
      );
      await tester.pumpAndSettle();
      final action = find.text('Rotate partner credentials');
      await tester.scrollUntilVisible(action, 180);
      await tester.tap(action);
      await tester.pumpAndSettle();
      await tester.enterText(
        find.byType(TextField),
        'Approved synthetic rotation',
      );
      await tester.pumpAndSettle();
      await tester.tap(find.text('Confirm'));
      await tester.pumpAndSettle();
      expect(api.writes, 1);
      expect(api.intent!['action'], 'rotate');
      expect(
        api.intent!['idempotency_key'],
        matches(RegExp(r'^[a-f0-9-]{36}$')),
      );
      await tester.scrollUntilVisible(
        find.textContaining('Outcome uncertain.'),
        -180,
      );
      expect(find.textContaining('Outcome uncertain.'), findsOneWidget);
      expect(find.textContaining('PRIVATE TOKEN'), findsNothing);
      expect(find.text('Rotate partner credentials'), findsNothing);
      await tester.tap(find.text('Refresh'));
      await tester.pumpAndSettle();
      expect(find.text('Exchange outcome uncertain'), findsOneWidget);
      expect(find.text('Rotate partner credentials'), findsNothing);
      expect(find.text('Unregister partner credentials'), findsNothing);
      expect(api.writes, 1);
    },
  );
  testWidgets('expired credentials access clears private operation history', (
    tester,
  ) async {
    final api = CredentialsApi()..lost = true;
    var expired = 0;
    await tester.pumpWidget(
      MaterialApp(
        home: OcpiCredentialsView(
          api: api,
          providerId: 7,
          onExpired: () => expired++,
        ),
      ),
    );
    await tester.pumpAndSettle();
    final record = find.textContaining('#8');
    await tester.scrollUntilVisible(record, 180);
    expect(record, findsOneWidget);
    api.expired = true;
    final refresh = find.text('Refresh');
    await tester.scrollUntilVisible(refresh, -180);
    await tester.tap(refresh);
    await tester.pumpAndSettle();
    expect(find.textContaining('#8'), findsNothing);
    expect(expired, 1);
    expect(find.textContaining('PRIVATE TOKEN'), findsNothing);
  });
}
