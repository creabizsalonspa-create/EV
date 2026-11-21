import 'dart:convert';
import 'dart:io';
import 'package:creabiz_ev/api.dart';
import 'package:creabiz_ev/coupons.dart';
import 'package:flutter_test/flutter_test.dart';
import 'local_fixture_origin.dart';

void main() {
  final path = Platform.environment['EV_NATIVE_COUPONS_FIXTURE'];
  test(
    'actual Laravel native coupon claim receipt and durable cancellation stay noncash and source-bound',
    () async {
      final f =
          jsonDecode(await File(path!).readAsString()) as Map<String, dynamic>;
      expect(f['fixture_only'], isTrue);
      final api = EvApi(localFixtureOrigin());
      addTearDown(api.close);
      final owner = await api.signIn(f['customer'], f['password']);
      expect(owner.role, 'customer');
      final key = operationKey(),
          body = <String, dynamic>{'code': f['code'], 'idempotency_key': key};
      final expected = couponRequestBinding(f['code'], owner.id, key);
      final result = await api.request(
        'commercial/coupons/claim',
        method: 'POST',
        body: body,
      );
      final receipt = CouponClaimRecord.parse(
        result['claim'],
        expectedBinding: expected,
      );
      expect(receipt.status, 'pending');
      final replay = await api.request(
        'commercial/coupons/claim',
        method: 'POST',
        body: body,
      );
      expect(
        CouponClaimRecord.parse(replay['claim'], expectedBinding: expected).id,
        receipt.id,
      );
      final lookup = await api.request(
        'commercial/coupons/claim-receipt',
        method: 'POST',
        body: {'idempotency_key': key},
      );
      expect(
        CouponClaimRecord.parse(lookup['claim'], expectedBinding: expected).id,
        receipt.id,
      );
      final accepted = await api.request(
        'commercial/coupons/claim-intents/cancel',
        method: 'POST',
        body: body,
      );
      expect(accepted['state'], 'accepted');
      expect(accepted['request_binding'], expected);
      expect(
        CouponClaimRecord.parse(
          accepted['claim'],
          expectedBinding: expected,
        ).id,
        receipt.id,
      );
      final cancelledKey = operationKey(),
          cancelledBody = <String, dynamic>{
            'code': f['code'],
            'idempotency_key': cancelledKey,
          };
      final cancelled = await api.request(
        'commercial/coupons/claim-intents/cancel',
        method: 'POST',
        body: cancelledBody,
      );
      expect(cancelled['state'], 'cancelled');
      expect(cancelled['claim'], isNull);
      expect(
        cancelled['request_binding'],
        couponRequestBinding(f['code'], owner.id, cancelledKey),
      );
      await expectLater(
        api.request(
          'commercial/coupons/claim',
          method: 'POST',
          body: cancelledBody,
        ),
        throwsA(
          isA<ApiException>().having(
            (e) => e.status,
            'permanent cancellation fence',
            409,
          ),
        ),
      );
      final history = await api.request(
        'commercial/coupons/claims',
        query: {'page': '1'},
      );
      expect(history['total'], 1);
      expect(CouponClaimRecord.parse(history['data'].single).id, receipt.id);
      body.clear();
      cancelledBody.clear();
      f.clear();
    },
    skip: path == null
        ? 'Requires owned local mock coupon fixture; run tests/native-coupons.cjs.'
        : false,
  );
}
