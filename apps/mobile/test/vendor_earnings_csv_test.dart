import 'dart:convert';
import 'dart:typed_data';

import 'package:creabiz_ev/api.dart';
import 'package:creabiz_ev/vendor_earnings_csv.dart';
import 'package:flutter_test/flutter_test.dart';

Map<String, dynamic> row({String invoice = 'Issued, "quoted" source'}) => {
  'invoice_id': 7,
  'invoice_number': invoice,
  'currency': 'KWD',
  'minor_units': 3,
  'subtotal_minor': 1005,
  'tax_minor': 0,
  'commission_minor': 100,
  'vendor_allocated_minor': 905,
  'paid_minor': 1005,
  'refunded_minor': 0,
  'vendor_refunded_minor': 0,
  'unpaid_credited_minor': 0,
  'net_collected_minor': 1005,
  'amount_due_minor': 0,
  'pending_reserved_minor': 0,
  'completed_settlement_minor': 0,
  'available_minor': -5,
  'evidence': 'PARTNER_SANDBOX_SOURCE',
};

Map<String, dynamic> source({int vendor = 3, int page = 1}) => {
  'vendor_id': vendor,
  'currency': 'KWD',
  'current_page': page,
  'evidence': 'SIMULATED_CANONICAL_SETTLEMENT',
  'external_psp_or_bank_verified': false,
  'real_payout_enabled': false,
};

String csvCell(String value) {
  if (value.contains(',') || value.contains('"') ||
      value.contains('\r') || value.contains('\n')) {
    return '"${value.replaceAll('"', '""')}"';
  }
  return value;
}

String csv(List<Map<String, dynamic>> records) {
  final lines = [
    VendorEarningsCsv.headers.join(','),
    for (final item in records)
      VendorEarningsCsv.headers.map((field) {
        final value = field == 'invoice_number'
            ? VendorEarningsCsv.literalNumber(item[field] as String)
            : '${item[field]}';
        return csvCell(value);
      }).join(','),
  ];
  return '${lines.join('\n')}\n';
}

PrivateDocument document(String body, {String mime = 'text/csv'}) =>
    PrivateDocument(Uint8List.fromList(utf8.encode(body)), mime);

String verify(
  PrivateDocument doc, {
  Map<String, dynamic>? provided,
  List<Map<String, dynamic>>? rows,
  int vendor = 3,
  int page = 1,
}) => VendorEarningsCsv.verifiedText(
  doc,
  vendorId: vendor,
  currency: 'KWD',
  page: page,
  source: provided ?? source(),
  rows: rows ?? [row()],
);

void main() {
  test('exact original quote/comma identifiers and negative settlement source',
      () {
    final content = csv([row()]);
    expect(verify(document(content)), content);
    expect(content, contains('"Issued, ""quoted"" source"'));
    expect(content, contains(',-5,PARTNER_SANDBOX_SOURCE'));
  });

  test('CSV-origin formulas remain literals, never executable user interface',
      () {
    for (final dangerous in [
      '=HYPERLINK("https://untrusted.example","Open")',
      ' +SUM(1,2)', '\t=IMPORTXML(1)', '-cmd', '@DDE',
    ]) {
      final invoice = row(invoice: dangerous);
      final text = csv([invoice]);
      expect(
        verify(document(text), rows: [invoice]),
        text,
      );
      expect(text, contains("'"));
    }
    expect(VendorEarningsCsv.literalNumber('INV-SAFE-2026'),
        'INV-SAFE-2026');
  });

  test('rejects wrong tenant, page, currency, MIME, empty or oversized',
      () {
    final valid = document(csv([row()]));
    for (final bad in [
      source(vendor: 4),
      source(page: 2),
      {...source(), 'currency': 'AED'},
      {...source(), 'real_payout_enabled': true},
      {...source(), 'external_psp_or_bank_verified': true},
    ]) {
      expect(() => verify(valid, provided: bad), throwsFormatException);
    }
    expect(() => verify(valid, vendor: 4), throwsFormatException);
    expect(() => verify(valid, page: 2), throwsFormatException);
    expect(() => verify(document('', mime: 'text/csv')),
        throwsFormatException);
    expect(() => verify(document(csv([row()]), mime: 'application/pdf')),
        throwsFormatException);
    expect(() => verify(document(List.filled(70000, 'X').join())), throwsFormatException);
  });

  test('rejects changed amounts, truncated rows, extra rows and column reorder',
      () {
    final valid = csv([row()]);
    expect(
      () => verify(document(valid.replaceFirst(',1005,0,100,905,',
          ',1006,0,100,905,'))),
      throwsFormatException,
    );
    expect(
      () => verify(document(valid.replaceFirst('invoice_id,invoice_number,',
          'invoice_number,invoice_id,'))),
      throwsFormatException,
    );
    expect(() => verify(document(csv([row(), row()]))),
        throwsFormatException);
    expect(
      () => verify(document(valid.replaceFirst(',-5,PARTNER_SANDBOX_SOURCE',
          ',PARTNER_SANDBOX_SOURCE'))),
      throwsFormatException,
    );
    expect(() => verify(document(valid.replaceFirst('Issued,',
        '"Unclosed,Invoice'))), throwsFormatException);
    expect(
      () => verify(PrivateDocument(Uint8List.fromList([0xff, 0xfe]),
          'text/csv')),
      throwsFormatException,
    );
  });

  test('strict empty page may contain headers only and cannot invent rows', () {
    final text = csv([]);
    final empty = VendorEarningsCsv.verifiedText(
      document(text),
      vendorId: 3,
      currency: 'KWD',
      page: 1,
      source: source(),
      rows: [],
    );
    expect(empty, text);
  });
}
