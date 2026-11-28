import 'local_fixture_origin.dart';
import 'dart:convert';
import 'dart:io';
import 'package:flutter_test/flutter_test.dart';
import 'package:crypto/crypto.dart';
import 'package:creabiz_ev/refund_requests.dart';
import 'package:creabiz_ev/api.dart';
import 'package:creabiz_ev/hosted_checkout.dart';

void main() {
  final path = Platform.environment['EV_NATIVE_HOSTED_FIXTURE'];
  test(
    'actual Laravel native hosted charging/subscription source lifecycle replay recovery and tenant controls',
    () async {
      final f = jsonDecode(await File(path!).readAsString());
      expect(f['fixture_only'], true);
      final api = EvApi(localFixtureOrigin());
      addTearDown(api.close);
      final customer = await api.signIn(f['customer'], f['password']);
      final original = await api.request('finance/invoices/${f['invoice']}');
      expect(original['amount_due_minor'], 1050);
      expect(original['paid_minor'], 0);
      final body = <String, dynamic>{
        'scope': 'charging',
        'invoice_id': f['invoice'],
        'idempotency_key': 'native-hosted-${f['invoice']}',
        'consent': true,
      };
      final first = HostedCheckoutRecord(
        (await api.request(
          'commercial/hosted-checkouts',
          method: 'POST',
          body: body,
        ))['checkout'],
      );
      expect(first.source['evidence'], 'MOCK_HOSTED');
      expect(first.source['amount_minor'], 1050);
      expect(first.status, 'open');
      final replay = HostedCheckoutRecord(
        (await api.request(
          'commercial/hosted-checkouts',
          method: 'POST',
          body: body,
        ))['checkout'],
      );
      expect(replay.id, first.id);
      await expectLater(
        api.request(
          'commercial/checkouts',
          method: 'POST',
          body: {
            'invoice_id': f['invoice'],
            'amount_minor': 1050,
            'idempotency_key': 'forbidden-replacement',
          },
        ),
        throwsA(isA<ApiException>().having((e) => e.status, 'status', 409)),
      );
      await expectLater(
        api.request(
          'commercial/hosted-checkouts',
          method: 'POST',
          body: {...body, 'amount_minor': 1},
        ),
        throwsA(isA<ApiException>().having((e) => e.status, 'status', 422)),
      );
      final auth = HostedCheckoutRecord(
        (await api.request(
          'commercial/hosted-checkouts/${first.id}/simulate',
          method: 'POST',
          body: {'outcome': 'authorized', 'consent': true},
        ))['checkout'],
      );
      expect(auth.status, 'authorized');
      expect(
        (await api.request('finance/invoices/${f['invoice']}'))['paid_minor'],
        0,
      );
      final paid = HostedCheckoutRecord(
        (await api.request(
          'commercial/hosted-checkouts/${first.id}/capture',
          method: 'POST',
          body: {},
        ))['checkout'],
      );
      expect(paid.status, 'captured');
      await api.request(
        'commercial/hosted-checkouts/${first.id}/capture',
        method: 'POST',
        body: {},
      );
      final finalInvoice = await api.request(
        'finance/invoices/${f['invoice']}',
      );
      expect(finalInvoice['paid_minor'], 1050);
      expect(finalInvoice['amount_due_minor'], 0);
      expect(finalInvoice['ledger_balanced'], true);
      // Continue from the exact hosted-captured invoice, not a separately
      // pre-paid fixture. These are guarded local/mock financial operations.
      final invoiceId = f['invoice'] as int;
      final customerId = customer.id;
      final refundPath = 'finance/invoices/$invoiceId/refund-requests';
      final refundBody = <String,dynamic>{
        'amount_minor': 100,
        'reason': 'Synthetic refund after hosted capture',
        'idempotency_key': 'native-hosted-refund-$invoiceId',
      };
      final refund = await api.request(refundPath, method:'POST',body:refundBody);
      expect(refundRequestAcknowledged(refund,refundBody,owner:customerId,invoice:invoiceId),true);
      expect(refund['status'],'pending');
      expect((await api.request(refundPath,method:'POST',body:refundBody))['id'],refund['id']);
      await expectLater(api.request(refundPath,method:'POST',body:{...refundBody,'amount_minor':101}),
        throwsA(isA<ApiException>().having((e)=>e.status,'status',409)));
      expect((await api.request('finance/invoices/$invoiceId'))['refunded_minor'],0);
      final disputeBody = <String,dynamic>{
        'invoice_id': invoiceId, 'kind':'dispute', 'amount_minor':200,
        'reason':'Synthetic private dispute after hosted capture',
        'idempotency_key':'native-hosted-case-$invoiceId',
      };
      final dispute=await api.request('commercial/cases',method:'POST',body:disputeBody);
      final caseId=dispute['id'] as int;
      expect(dispute['invoice_id'],invoiceId);
      expect(dispute['user_id'],customerId);
      expect(dispute['status'],'open');
      expect(dispute.containsKey('request_key'),false);
      expect((await api.request('commercial/cases',method:'POST',body:disputeBody))['id'],caseId);
      await expectLater(api.request('commercial/cases',method:'POST',body:{...disputeBody,'amount_minor':201}),
        throwsA(isA<ApiException>().having((e)=>e.status,'status',409)));
      final evidenceBytes=base64Decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aV2sAAAAASUVORK5CYII=');
      final evidence=await api.uploadCaseEvidence(caseId,kind:'receipt',
        bytes:evidenceBytes,mime:'image/png',key:'hosted-case-evidence-$invoiceId');
      expect(evidence['case_id'],caseId);
      expect(evidence['sha256'],sha256.convert(evidenceBytes).toString());
      expect(evidence['provider_submitted'],false);
      expect(evidence['evidence_status'],'PRIVATE_REVIEW_ONLY');
      final evidencePath='commercial/cases/$caseId/evidence';
      final downloadPath="$evidencePath/${evidence['id']}";
      final afterCase=await api.request('finance/invoices/$invoiceId');
      expect(afterCase['paid_minor'],1050);
      expect(afterCase['refunded_minor'],0);
      expect(afterCase['ledger_balanced'],true);
      final subBody = <String, dynamic>{
        'scope': 'subscription',
        'invoice_id': f['subscription_invoice'],
        'subscription_id': f['subscription'],
        'idempotency_key': 'native-sub-${f['subscription']}',
        'consent': true,
      };
      final sub = HostedCheckoutRecord(
        (await api.request(
          'commercial/hosted-checkouts',
          method: 'POST',
          body: subBody,
        ))['checkout'],
      );
      final closed = HostedCheckoutRecord(
        (await api.request(
          'commercial/hosted-checkouts/${sub.id}/simulate',
          method: 'POST',
          body: {'outcome': 'abandoned', 'consent': true},
        ))['checkout'],
      );
      expect(closed.status, 'cancelled');
      expect(closed.permits('retry'), true);
      final old = HostedCheckoutRecord(
        (await api.request(
          'commercial/hosted-checkouts',
          method: 'POST',
          body: subBody,
        ))['checkout'],
      );
      expect(old.id, sub.id);
      expect(old.status, 'cancelled');
      final newSub = HostedCheckoutRecord(
        (await api.request(
          'commercial/hosted-checkouts',
          method: 'POST',
          body: {
            ...subBody,
            'idempotency_key': 'native-confirmed-next-${f['subscription']}',
          },
        ))['checkout'],
      );
      expect(newSub.id, isNot(sub.id));
      await api.request(
        'commercial/hosted-checkouts/${newSub.id}/simulate',
        method: 'POST',
        body: {'outcome': 'authorized', 'consent': true},
      );
      expect(
        HostedCheckoutRecord(
          (await api.request(
            'commercial/hosted-checkouts/${newSub.id}/capture',
            method: 'POST',
            body: {},
          ))['checkout'],
        ).status,
        'captured',
      );
      final subscription = await api.request(
        'commercial/subscriptions/${f['subscription']}',
      );
      expect(subscription['entitlements_active'], true);
      final reference = first.source['reference'] as String;
      await api.signOut();
      await api.signIn(f['vendor'], f['password']);
      final vendor = HostedCheckoutRecord(
        (await api.request(
          'commercial/hosted-checkouts/${first.id}',
        ))['checkout'],
      );
      expect(vendor.source['owner'], false);
      expect(vendor.source['allowed_actions'], isEmpty);
      expect(vendor.hosted, isNull);
      await expectLater(
        api.request(
          'commercial/hosted-checkouts/${first.id}/capture',
          method: 'POST',
          body: {},
        ),
        throwsA(isA<ApiException>().having((e) => e.status, 'status', 404)),
      );
      final cases=await api.request('commercial/cases');
      expect((cases['data'] as List).any((r)=>r['id']==caseId),true);
      expect((await api.request(evidencePath))['total'],1);
      final privateFile=await api.downloadDocument(downloadPath);
      expect(privateFile.mime,'image/png');
      expect(privateFile.bytes,orderedEquals(evidenceBytes));
      await expectLater(api.request('commercial/cases/$caseId/resolve',
        method:'POST',body:{'outcome':'approved','resolution':'Unauthorized vendor dispute review'}),
        throwsA(isA<ApiException>().having((e)=>e.status,'status',403)));
      final reviewPath="finance/refund-requests/${refund['id']}/review";
      final reviewBody={'decision':'approve','note':'Synthetic authorized vendor refund'};
      final reviewed=await api.request(reviewPath,method:'POST',body:reviewBody);
      expect(reviewed['status'],'approved');
      expect(reviewed['refund_id'],isA<int>());
      expect((await api.request(reviewPath,method:'POST',body:reviewBody))['refund_id'],reviewed['refund_id']);
      await expectLater(api.request(reviewPath,method:'POST',body:{'decision':'reject','note':'Changed decision'}),
        throwsA(isA<ApiException>().having((e)=>e.status,'status',409)));
      final afterRefund=await api.request('finance/invoices/$invoiceId');
      expect(afterRefund['paid_minor'],1050);
      expect(afterRefund['refunded_minor'],100);
      expect(afterRefund['net_collected_minor'],950);
      expect((afterRefund['refunds'] as List).length,1);
      expect(afterRefund['ledger_balanced'],true);
      expect(afterRefund['real_payment_processed'],false);
      expect((await api.request(evidencePath))['can_upload'],true);
      final ownVendor = HostedCheckoutRecord(
        (await api.request(
          'commercial/hosted-checkouts',
          method: 'POST',
          body: {
            'scope': 'subscription',
            'subscription_id': f['vendor_subscription'],
            'invoice_id': f['vendor_subscription_invoice'],
            'consent': true,
            'idempotency_key':
                'native-vendor-package-${f['vendor_subscription']}',
          },
        ))['checkout'],
      );
      expect(ownVendor.source['owner'], true);
      await api.request(
        'commercial/hosted-checkouts/${ownVendor.id}/simulate',
        method: 'POST',
        body: {'consent': true, 'outcome': 'authorized'},
      );
      final vendorCaptured = HostedCheckoutRecord(
        (await api.request(
          'commercial/hosted-checkouts/${ownVendor.id}/capture',
          method: 'POST',
          body: {},
        ))['checkout'],
      );
      expect(vendorCaptured.status, 'captured');
      expect(
        (await api.request(
          'commercial/subscriptions/${f['vendor_subscription']}',
        ))['entitlements_active'],
        true,
      );
      await api.signOut();
      await api.signIn(f['admin'], f['password']);
      // A separate authorized reviewer can resolve this exact case without
      // implying a provider submission or a second refund. The existing
      // hosted suite has its own login window (four identities, limit ten).
      final resolutionPath = 'commercial/cases/$caseId/resolve';
      final resolutionBody = {
        'outcome': 'rejected',
        'resolution': 'Synthetic review: no additional refund authorized',
      };
      final resolved = await api.request(
        resolutionPath, method: 'POST', body: resolutionBody,
      );
      expect(resolved['id'], caseId);
      expect(resolved['invoice_id'], invoiceId);
      expect(resolved['status'], 'rejected');
      expect(resolved['refund_id'], isNull);
      expect(resolved['real_money_moved'], false);
      final resolvedReplay = await api.request(
        resolutionPath, method: 'POST', body: resolutionBody,
      );
      expect(resolvedReplay['id'], caseId);
      expect(resolvedReplay['status'], 'rejected');
      await expectLater(
        api.request(resolutionPath, method: 'POST', body: {
          ...resolutionBody, 'outcome': 'approved',
        }),
        throwsA(isA<ApiException>().having((e) => e.status, 'status', 409)),
      );
      final closedEvidence = await api.request(evidencePath);
      expect(closedEvidence['total'], 1);
      expect(closedEvidence['can_upload'], false);
      await expectLater(
        api.uploadCaseEvidence(
          caseId, kind: 'receipt', bytes: evidenceBytes, mime: 'image/png',
          key: 'closed-case-evidence-$invoiceId',
        ),
        throwsA(isA<ApiException>().having((e) => e.status, 'status', 409)),
      );
      final retained = await api.downloadDocument(downloadPath);
      expect(retained.bytes, orderedEquals(evidenceBytes));
      final closedInvoice = await api.request('finance/invoices/$invoiceId');
      expect(closedInvoice['paid_minor'], 1050);
      expect(closedInvoice['refunded_minor'], 100);
      expect(closedInvoice['net_collected_minor'], 950);
      expect((closedInvoice['refunds'] as List).length, 1);
      expect(closedInvoice['ledger_balanced'], true);
      expect(closedInvoice['real_payment_processed'], false);
      await api.signOut();
      await api.signIn(f['foreign'], f['password']);
      expect((await api.request('commercial/hosted-checkouts'))['total'], 0);
      expect((await api.request('commercial/cases'))['total'],0);
      expect((await api.request('finance/refund-requests'))['total'],0);
      for(final read in [evidencePath,'finance/invoices/$invoiceId']){
        await expectLater(api.request(read),throwsA(isA<ApiException>().having((e)=>e.status,'status',404)));
      }
      await expectLater(api.downloadDocument(downloadPath),
        throwsA(isA<ApiException>().having((e)=>e.status,'status',404)));
      await expectLater(
        api.request('commercial/hosted-checkouts/${first.id}'),
        throwsA(isA<ApiException>().having((e) => e.status, 'status', 404)),
      );
      await expectLater(
        api.request('commercial/hosted-checkouts/by-reference/$reference'),
        throwsA(isA<ApiException>().having((e) => e.status, 'status', 404)),
      );
    },
    skip: path == null
        ? 'Requires isolated local/mock Laravel hosted fixture.'
        : false,
  );
}
