import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:creabiz_ev/api.dart';
import 'package:creabiz_ev/maintenance_windows.dart';
import 'test_support.dart';

class MaintenanceApi extends FixtureApi {
  int creates=0;
  Map<String,dynamic>? original;
  @override
  Future<dynamic> request(String path, {String method='GET', Map<String,dynamic>? body, Map<String,String> query=const {}}) async {
    expect(path,'vendor-ops/companies/1/chargers/2/connectors/3/maintenance-windows');
    if(method=='POST') {
      creates++;
      if(original==null) {
        original=Map<String,dynamic>.from(body!);
        throw const ApiException(503,'PRIVATE LOST RESPONSE');
      }
      expect(body,original);
      return {'id':7};
    }
    expect(method,'GET');
    return {'current_page':1,'last_page':1,'total':original==null?0:1,'data':[
      if(original!=null){'id':7,'starts_at':original!['starts_at'],'ends_at':original!['ends_at'],'reason':original!['reason'],'cancelled_at':null},
    ]};
  }
}

void main() {
  testWidgets('uncertain scheduling freezes the original intent and only retries explicitly', (tester) async {
    final api=MaintenanceApi();
    await tester.pumpWidget(MaterialApp(home:MaintenanceWindowsView(api:api,companyId:1,chargerId:2,connectorId:3,canManage:true,operational:true,onExpired:(){})));
    await tester.pumpAndSettle();
    final fields=find.byType(TextFormField);
    await tester.enterText(fields.at(0),'2027-01-15T09:00:00Z');
    await tester.enterText(fields.at(1),'2027-01-15T10:00:00Z');
    await tester.enterText(fields.at(2),'Planned connector inspection');
    await tester.ensureVisible(find.text('Schedule maintenance'));
    await tester.tap(find.text('Schedule maintenance'));
    await tester.pumpAndSettle();
    await tester.pump(const Duration(seconds:30));
    expect(api.creates,1);
    expect(find.textContaining('PRIVATE LOST RESPONSE'),findsNothing);
    expect(tester.widget<TextFormField>(fields.first).enabled,false);
    await tester.ensureVisible(find.text('Retry saved request'));
    await tester.tap(find.text('Retry saved request'));
    await tester.pumpAndSettle();
    expect(api.creates,2);
    await tester.scrollUntilVisible(find.text('Planned connector inspection'),150,scrollable:find.byType(Scrollable).first);
    expect(find.text('Planned connector inspection'),findsOneWidget);
  });
}
