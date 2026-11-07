import 'dart:convert';
import 'dart:io';
import 'package:creabiz_ev/api.dart';
import 'package:creabiz_ev/vehicle_model_catalog.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  testWidgets('customer links a curated model without overwriting vehicle connector',
      (tester) async {
    final server = await HttpServer.bind(InternetAddress.loopbackIPv4, 0);
    final writes = <Map<String, dynamic>>[];
    final subscription = server.listen((request) async {
      request.response.headers.contentType = ContentType.json;
      final path = request.uri.path;
      if (path == '/api/vehicles/12/model' && request.method == 'GET') {
        request.response.write(jsonEncode({
          'vehicle_id': 12, 'vehicle_connector_type': 'CCS2',
          'model_id': null, 'model': null, 'published': false,
          'does_not_override_connector': true,
        }));
      } else if (path == '/api/vehicle-models' && request.method == 'GET') {
        request.response.write(jsonEncode({
          'current_page':1,'last_page':1,'total':1,
          'data':[{
            'id':4,'make':'Test brand','model':'Demo E','variant':null,
            'model_year':2026,'connector_type':'CCS2',
            'source_url':'https://example.com/catalog/demo-e',
            'published':true,'revision':1,
            'provenance':'ADMIN_CURATED_UNVERIFIED',
          }],
        }));
      } else if (path == '/api/vehicles/12/model' && request.method == 'PUT') {
        final body = jsonDecode(await utf8.decoder.bind(request).join()) as Map<String, dynamic>;
        writes.add(body);
        request.response.write(jsonEncode({
          'vehicle_id':12,'model_id':body['model_id'],
          'vehicle_connector_type':'CCS2','does_not_override_connector':true,
          'model':{'id':4,'make':'Test brand','model':'Demo E',
            'variant':null,'model_year':2026,'connector_type':'CCS2','published':true},
        }));
      } else {
        request.response.statusCode = 404;
        request.response.write('{}');
      }
      await request.response.close();
    });
    final api = EvApi(Uri.parse('http://127.0.0.1:' + server.port.toString()));
    try {
      await tester.pumpWidget(MaterialApp(home: VehicleModelAssociationView(
        api:api,vehicleId:12,vehicleConnector:'CCS2',onExpired:(){},
      )));
      await tester.pumpAndSettle();
      expect(find.textContaining('Test brand Demo E'), findsWidgets);
      expect(find.textContaining('not manufacturer-verified'), findsOneWidget);
      await tester.ensureVisible(find.byKey(const Key('model-link-save')));
      await tester.tap(find.byKey(const Key('model-link-save')));
      await tester.pumpAndSettle();
      expect(writes,hasLength(1));
      expect(writes.single['model_id'],4);
      expect(writes.single['expected_model_id'],null);
      expect(find.text('Vehicle model association saved.'),findsOneWidget);
    } finally {
      api.close();await subscription.cancel();await server.close(force:true);
    }
  });

  testWidgets('Admin can create a sourced but unpublished catalog record',
      (tester) async {
    final server = await HttpServer.bind(InternetAddress.loopbackIPv4, 0);
    final writes = <Map<String,dynamic>>[];
    final subscription = server.listen((request) async {
      request.response.headers.contentType=ContentType.json;
      if(request.uri.path=='/api/vehicle-models' && request.method=='GET') {
        request.response.write(jsonEncode({
          'current_page':1,'last_page':1,'total':0,'data':[],
        }));
      } else if(request.uri.path=='/api/vehicle-models' && request.method=='POST') {
        final body = jsonDecode(await utf8.decoder.bind(request).join()) as Map<String,dynamic>;
        writes.add(body);
        request.response.statusCode=201;
        request.response.write(jsonEncode({...body,'id':7,'revision':1}));
      } else {
        request.response.statusCode=404;
        request.response.write('{}');
      }
      await request.response.close();
    });
    final api=EvApi(Uri.parse('http://127.0.0.1:'+server.port.toString()));
    try {
      await tester.pumpWidget(MaterialApp(home:AdminVehicleModelCatalogView(
        api:api,onExpired:(){},
      )));
      await tester.pumpAndSettle();
      await tester.enterText(find.byKey(const Key('admin-model-make')),'Test maker');
      await tester.enterText(find.byKey(const Key('admin-model-model')),'E city');
      await tester.enterText(find.byKey(const Key('admin-model-source')),'https://example.com/source');
      await tester.ensureVisible(find.byKey(const Key('admin-model-save')));
      await tester.tap(find.byKey(const Key('admin-model-save')));
      await tester.pumpAndSettle();
      expect(writes,hasLength(1));
      expect(writes.single['make'],'Test maker');
      expect(writes.single['model'],'E city');
      expect(writes.single['published'],false);
      expect(writes.single['source_url'],'https://example.com/source');
      expect(find.text('Catalog record saved.'),findsOneWidget);
    } finally {
      api.close();await subscription.cancel();await server.close(force:true);
    }
  });
}
