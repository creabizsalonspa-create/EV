import 'dart:convert';

import 'api.dart';
import 'vendor_earnings_csv.dart';

final class VendorSettlementCsv {
  static const fields = [
    'id',
    'number',
    'currency',
    'minor_units',
    'amount_minor',
    'state',
    'created_at',
    'executed_at',
    'evidence',
  ];

  static String verifiedText(
    PrivateDocument document,
    List<Map<String, dynamic>> source,
  ) {
    if (document.mime != 'text/csv' || source.length > 25) {
      throw const FormatException('Unsupported settlement page export.');
    }
    final text = utf8.decode(document.bytes);
    final rows = VendorEarningsCsv.parseBounded(text);
    if (rows.length != source.length + 1 || !_sameCells(rows.first, fields)) {
      throw const FormatException('Settlement export page changed.');
    }
    for (var i = 0; i < source.length; i++) {
      final row = source[i];
      final cells = fields.map((field) {
        final value = row[field]?.toString() ?? '';
        return RegExp(
              r'^[\p{Z}\p{Cc}\p{Cf}]*[=+@-]',
              unicode: true,
            ).hasMatch(value)
            ? "'$value"
            : value;
      }).toList();
      if (!_sameCells(rows[i + 1], cells)) {
        throw const FormatException('Settlement export differs from history.');
      }
    }
    return text;
  }

  static bool _sameCells(List<String> first, List<String> second) {
    if (first.length != second.length) return false;
    for (var i = 0; i < first.length; i++) {
      if (first[i] != second[i]) return false;
    }
    return true;
  }
}
