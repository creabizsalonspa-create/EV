import 'package:creabiz_ev/api.dart';
import 'package:creabiz_ev/vehicle_model_catalog.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

/// Deterministic widget contract. Actual HTTP transport is tested by api_test
/// and tenant-specific model-link rules by VehicleModelCatalogTest.php.
class _ModelsApi extends EvApi {
  _ModelsApi({this.forAdmin = false}) : super(Uri.parse('https://example.invalid'));
  final bool forAdmin;
  final writes = <Map<String, dynamic>>[];
  int? selectedModel;
  final catalogQueries = <Map<String, String>>[];

  final model = <String, dynamic>{
    'id': 4, 'make': 'Test brand', 'model': 'Demo E', 'variant': null,
    'model_year': 2026, 'connector_type': 'CCS2',
    'source_url': 'https://example.com/catalog/demo-e',
    'published': true, 'revision': 1,
    'provenance': 'ADMIN_CURATED_UNVERIFIED',
  };
  Map<String,dynamic> get secondModel => {
    ...model,
    'id':5,
    'model':'Second compatible',
    'source_url':'https://example.com/catalog/second',
  };

  @override
  Future<dynamic> request(String path, {
    String method = 'GET',
    Map<String, dynamic>? body,
    Map<String, String> query = const {},
  }) async {
    if (path == 'vehicles/12/model' && method == 'GET') {
      return {
        'vehicle_id': 12, 'vehicle_connector_type': 'CCS2',
        'model_id': selectedModel, 'model': selectedModel == null
          ? null : (selectedModel == 5 ? secondModel : model),
        'published': selectedModel != null,
        'does_not_override_connector': true,
      };
    }
    if (path == 'vehicle-models' && method == 'GET') {
      catalogQueries.add(Map<String, String>.from(query));
      // Simulate a server catalog with many unrelated connector types.
      // Without server-side connector filtering the first page is empty.
      final visible = forAdmin ? <Map<String,dynamic>>[]
          : query['connector_type'] == 'CCS2'
              ? <Map<String,dynamic>>[model, secondModel]
              : <Map<String,dynamic>>[];
      return {
        'current_page': 1, 'last_page': 1, 'total': visible.length,
        'data': visible,
      };
    }
    if (path == 'vehicles/12/model' && method == 'PUT') {
      final payload = Map<String, dynamic>.from(body!);
      writes.add(payload);
      selectedModel = payload['model_id'] as int?;
      return {
        'vehicle_id': 12, 'model_id': selectedModel,
        'vehicle_connector_type': 'CCS2',
        'does_not_override_connector': true,
        'model': selectedModel == null ? null : model,
      };
    }
    if (path == 'vehicle-models' && method == 'POST') {
      final payload = Map<String, dynamic>.from(body!);
      writes.add(payload);
      return {...payload, 'id': 7, 'revision': 1};
    }
    throw ApiException(404, 'Unexpected test endpoint: $method $path');
  }
}

void main() {
  testWidgets('customer links a curated compatible model without changing connector',
      (tester) async {
    final api = _ModelsApi();
    try {
      await tester.pumpWidget(MaterialApp(
        home: VehicleModelAssociationView(
          api: api, vehicleId: 12, vehicleConnector: 'CCS2', onExpired: () {},
        ),
      ));
      await tester.pumpAndSettle();
      expect(find.textContaining('Test brand Demo E'), findsWidgets);
      expect(find.textContaining('not manufacturer-verified'), findsOneWidget);
      await tester.ensureVisible(find.byKey(const Key('model-link-save')));
      await tester.tap(find.byKey(const Key('model-link-save')));
      await tester.pumpAndSettle();
      expect(api.catalogQueries.single['connector_type'], 'CCS2');
      expect(api.writes, hasLength(1));
      expect(api.writes.single['model_id'], 4);
      expect(api.writes.single['expected_model_id'], null);
      expect(find.text('Vehicle model association saved.'), findsOneWidget);
    } finally {
      api.close();
    }
  });


  testWidgets('saved compatible link is preselected when filtered models reload',
      (tester) async {
    final api = _ModelsApi()..selectedModel = 5;
    try {
      await tester.pumpWidget(MaterialApp(
        home: VehicleModelAssociationView(
          api: api, vehicleId: 12, vehicleConnector: 'CCS2', onExpired: () {},
        ),
      ));
      await tester.pumpAndSettle();
      expect(api.catalogQueries.single['connector_type'], 'CCS2');
      final picker = tester.widget<DropdownButtonFormField<int>>(
        find.byKey(const ValueKey('model-options-1-5')),
      );
      expect(picker.initialValue, 5);
      expect(find.textContaining('Linked: Test brand Second compatible'), findsOneWidget);
      expect(api.writes, isEmpty);
    } finally {api.close();}
  });

  testWidgets('Admin can create a source-backed unpublished catalog record',
      (tester) async {
    final api = _ModelsApi(forAdmin: true);
    try {
      await tester.pumpWidget(MaterialApp(
        home: AdminVehicleModelCatalogView(api: api, onExpired: () {}),
      ));
      await tester.pumpAndSettle();
      await tester.enterText(find.byKey(const Key('admin-model-make')), 'Test maker');
      await tester.enterText(find.byKey(const Key('admin-model-model')), 'E city');
      await tester.enterText(
        find.byKey(const Key('admin-model-source')),
        'https://example.com/source',
      );
      await tester.scrollUntilVisible(
        find.byKey(const Key('admin-model-save')),
        300,
        scrollable: find.byType(Scrollable).first,
      );
      await tester.tap(find.byKey(const Key('admin-model-save')));
      await tester.pumpAndSettle();
      expect(api.writes, hasLength(1));
      expect(api.writes.single['make'], 'Test maker');
      expect(api.writes.single['model'], 'E city');
      expect(api.writes.single['published'], false);
      expect(api.writes.single['source_url'], 'https://example.com/source');
      await tester.scrollUntilVisible(
        find.byKey(const Key('admin-model-success')),
        -300,
        scrollable: find.byType(Scrollable).first,
      );
      expect(find.text('Catalog record saved.'), findsOneWidget);
    } finally {
      api.close();
    }
  });
}
