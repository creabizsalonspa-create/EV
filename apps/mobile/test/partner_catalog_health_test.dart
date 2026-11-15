import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:creabiz_ev/ocpi_sync_plans.dart';

void main() {
  final report = <String,dynamic>{'state':'attention','checked_at':'2026-11-15T12:00:00.000000Z','max_age_seconds':86400,'published_count':6,'within_policy_count':1,'stale_count':3,'unknown_age_count':2,'future_clock_count':2,'evidence':'PARTNER_CATALOG_TIMESTAMPS_ONLY','physical_availability_verified':false,'remote_control':false,'billing_authority':false};
  for (final ar in [false, true]) {
    testWidgets('Catalog-age card shows bounded counts and rejects authority claims $ar', (tester) async {
      Future<void> display(dynamic value) => tester.pumpWidget(MaterialApp(home:Scaffold(body:SingleChildScrollView(child:ocpiCatalogHealthCard(value, ar)))));
      await display({...report,'payload':'DO-NOT-DISPLAY'});
      expect(find.textContaining(ar ? 'ضمن سياسة العمر 1' : 'within age policy 1'), findsOneWidget);
      expect(find.textContaining(ar ? 'ساعات مستقبلية 2' : 'future clocks 2'), findsOneWidget);
      expect(find.textContaining('DO-NOT-DISPLAY'), findsNothing);
      for (final invalid in [null,{...report,'physical_availability_verified':true},{...report,'remote_control':true},{...report,'billing_authority':true},{...report,'published_count':0},{...report,'future_clock_count':3},{...report,'max_age_seconds':0},{...report,'state':'within_policy'}]) {
        await display(invalid);
        expect(find.textContaining(ar ? 'عمر الكتالوج غير متاح' : 'Catalog age unavailable'), findsOneWidget);
        expect(find.textContaining(ar ? 'منشور 0' : 'Published 0'), findsNothing);
      }
      await display({...report,'state':'empty','published_count':0,'within_policy_count':0,'stale_count':0,'unknown_age_count':0,'future_clock_count':0});
      expect(find.textContaining(ar ? 'منشور 0' : 'Published 0'), findsOneWidget);
    });
  }
}
