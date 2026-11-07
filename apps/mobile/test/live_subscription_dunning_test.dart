import 'dart:convert';
import 'dart:io';
import 'package:flutter_test/flutter_test.dart';
import 'package:creabiz_ev/api.dart';

void main() {
  final path = Platform.environment['EV_NATIVE_SUBSCRIPTION_DUNNING_FIXTURE'];
  test(
    'native actual Laravel overdue notices are scoped with authoritative status and no collection',
    () async {
      final f = jsonDecode(await File(path!).readAsString());
      expect(f['fixture_only'], true);
      final api = EvApi(Uri.parse('http://127.0.0.1:8000'));
      addTearDown(api.close);
      await api.signIn(f['owner'], f['password']);
      final result = await api.request('commercial/subscription-notices');
      final row = (result['data'] as List).single;
      expect(row['number'], f['number']);
      expect(row['stage'], 3);
      expect(row['attention'], 'payment_due');
      expect(row['collection_attempted'], false);
      expect(row['delivery'], 'IN_APP_ONLY');
      expect(row['total_minor'], 525);
      await api.signOut();
      await api.signIn(f['other'], f['password']);
      expect(
        (await api.request('commercial/subscription-notices'))['total'],
        0,
      );
    },
    skip: path == null
        ? 'Run tests/native-subscription-dunning.cjs against isolated local Laravel'
        : false,
  );
}
