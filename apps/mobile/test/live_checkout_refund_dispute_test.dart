import 'dart:convert';
import 'dart:io';
import 'package:crypto/crypto.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:creabiz_ev/api.dart';
import 'package:creabiz_ev/refund_requests.dart';

void main() {
  final path = Platform.environment['EV_NATIVE_COMMERCIAL_FIXTURE'];
  test('connected native mock capture, refund review and private dispute evidence preserve source authority', () async {
    final f = jsonDecode(await File(path!).readAsString()) as Map<String,dynamic>;
    expect(f['fixture_only'], true);
    final api = EvApi(Uri.parse('http://127.0.0.1:8000'));
    addTearDown(api.close);
    final invoice = f['invoice_id'] as int;
    final owner = f['driver_id'] as int;
    final invoicePath = 'finance/invoices/$invoice';
    const root = 'commercial';
    Future<dynamic> post(String p, Map<String,dynamic> body) =>
      api.request(p, method:'POST', body:body);
    Future<void> denied(Future<dynamic> call, int status) =>
      expectLater(call, throwsA(isA<ApiException>().having((e)=>e.status,'status',status)));
    Future<void> login(String role) async {
      await api.signIn(f[role] as String, f['password'] as String);
    }
    await login('driver');
    final original=await api.request(invoicePath);
    expect(original['total_minor'],1050);
    expect(original['refunded_minor'],0);
    // Mock-token setup ONLY: the native production-facing flow is hosted.
    final create={'invoice_id':invoice,'amount_minor':1050,'idempotency_key':'native-capture-$invoice'};
    final checkout=await post('$root/checkouts',create);
    expect(checkout['evidence'],'MOCK_PROVIDER');
    expect(checkout['real_money_moved'],false);
    final id=checkout['id'] as int;
    expect((await post('$root/checkouts',create))['id'],id);
    final charge='$root/checkouts/$id';
    await denied(post('$charge/capture',{'idempotency_key':'capture-$invoice'}),409);
    expect((await post('$charge/authorize',{'idempotency_key':'authorize-$invoice','token':'mock_success'}))['status'],'authorized');
    final capture={'idempotency_key':'capture-$invoice'};
    expect((await post('$charge/capture',capture))['status'],'captured');
    expect((await post('$charge/capture',capture))['id'],id);
    expect((await api.request(invoicePath))['net_collected_minor'],1050);

    final refund={'amount_minor':100,'reason':'Synthetic native refund review','idempotency_key':'refund-$invoice'};
    final refundPath='$invoicePath/refund-requests';
    final pending=await post(refundPath,refund);
    expect(refundRequestAcknowledged(pending,refund,owner:owner,invoice:invoice),true);
    expect(pending['status'],'pending');
    expect((await post(refundPath,refund))['id'],pending['id']);
    await denied(post(refundPath,{...refund,'amount_minor':101}),409);
    expect((await api.request(invoicePath))['refunded_minor'],0);

    final body={'invoice_id':invoice,'kind':'dispute','amount_minor':200,
      'reason':'Synthetic native private dispute','idempotency_key':'case-$invoice'};
    final row=await post('$root/cases',body);
    final caseId=row['id'] as int;
    expect(row['invoice_id'],invoice);
    expect(row['user_id'],owner);
    expect(row['status'],'open');
    expect(row.containsKey('request_key'),false);
    expect((await post('$root/cases',body))['id'],caseId);
    await denied(post('$root/cases',{...body,'amount_minor':201}),409);
    final evidencePath='$root/cases/$caseId/evidence';
    final bytes=base64Decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aV2sAAAAASUVORK5CYII=');
    Future<dynamic> upload(String key) => api.uploadCaseEvidence(caseId,
      kind:'receipt',bytes:bytes,mime:'image/png',key:key);
    final uploaded=await upload('evidence-$invoice');
    expect(uploaded['case_id'],caseId);
    expect(uploaded['sha256'],sha256.convert(bytes).toString());
    expect(uploaded['provider_submitted'],false);
    expect(uploaded['evidence_status'],'PRIVATE_REVIEW_ONLY');
    expect((await upload('evidence-$invoice'))['id'],uploaded['id']);
    final documentPath="$evidencePath/${uploaded['id']}";
    final file=await api.downloadDocument(documentPath);
    expect(file.mime,'image/png');
    expect(file.bytes,orderedEquals(bytes));

    await api.signOut();
    await login('owner');
    await denied(api.request(invoicePath),404);
    await denied(api.request(evidencePath),404);
    await denied(api.downloadDocument(documentPath),404);
    await denied(post('$root/cases',body),404);
    await denied(post(refundPath,refund),404);

    await api.signOut();
    await login('vendor');
    final history=await api.request('$root/cases');
    expect((history['data'] as List).any((r)=>r['id']==caseId),true);
    expect((await api.request(evidencePath))['total'],1);
    expect((await api.downloadDocument(documentPath)).bytes,orderedEquals(bytes));
    await denied(post('$root/cases/$caseId/resolve',{'outcome':'approved','resolution':'Synthetic review'}),403);
    final reviewPath="finance/refund-requests/${pending['id']}/review";
    final decision={'decision':'approve','note':'Synthetic vendor review'};
    final reviewed=await post(reviewPath,decision);
    expect(reviewed['status'],'approved');
    expect((await post(reviewPath,decision))['refund_id'],reviewed['refund_id']);
    await denied(post(reviewPath,{'decision':'reject','note':'Changed review'}),409);
    final after=await api.request(invoicePath);
    expect(after['refunded_minor'],100);
    expect(after['net_collected_minor'],950);
    expect((after['refunds'] as List).length,1);
    await api.signOut();
    await login('driver');
    final visible=await api.request('finance/refund-requests');
    final own=(visible['data'] as List).singleWhere((r)=>r['id']==pending['id']);
    expect(own['status'],'approved');
    expect(own.containsKey('request_receipt'),false);
    expect((await api.request(evidencePath))['can_upload'],true);
    expect((await api.request(invoicePath))['refunded_minor'],100);
  }, skip:path==null ? 'Run guarded node tests/native-commercial-parity.cjs fixture' : false);
}
