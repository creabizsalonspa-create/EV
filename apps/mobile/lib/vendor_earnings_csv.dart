import 'dart:convert';

import 'api.dart';

/// A bounded, validated private view of one canonical vendor-earnings CSV page.
/// It is not a bank export, external settlement statement or file on disk.
final class VendorEarningsCsv {
  static const headers = <String>[
    'invoice_id', 'invoice_number', 'currency', 'minor_units',
    'subtotal_minor', 'tax_minor', 'commission_minor',
    'vendor_allocated_minor', 'paid_minor', 'refunded_minor',
    'vendor_refunded_minor', 'unpaid_credited_minor',
    'net_collected_minor', 'amount_due_minor', 'pending_reserved_minor',
    'completed_settlement_minor', 'available_minor', 'evidence',
  ];

  static String literalNumber(String value) =>
      RegExp(r'^[\s\x00-\x1f]*[=+@-]').hasMatch(value)
      ? "'$value"
      : value;

  /// Parse strict RFC-style double quotes and comma-delimited rows.
  /// Every untrusted cell must equal the source snapshot already verified
  /// for the logged-in vendor, currency and exact page.
  static List<List<String>> parseBounded(String source) {
    final rows = <List<String>>[];
    var row = <String>[];
    var cell = StringBuffer();
    var inQuotes = false, started = false, closedQuote = false;
    void field() {
      row.add(cell.toString());
      cell = StringBuffer();
      started = false;
      closedQuote = false;
    }
    void record() {
      rows.add(row);
      row = <String>[];
      if (rows.length > 26) {
        throw const FormatException('CSV exceeds bounded page.');
      }
    }

    for (var i = 0; i < source.length; i++) {
      final char = source[i];
      if (inQuotes) {
        if (char == '"') {
          if (i + 1 < source.length && source[i + 1] == '"') {
            cell.write('"');
            i++;
          } else {
            inQuotes = false;
            closedQuote = true;
          }
        } else {
          cell.write(char);
        }
      } else if (char == '"') {
        if (started || closedQuote) {
          throw const FormatException('Invalid CSV quote.');
        }
        started = true;
        inQuotes = true;
      } else if (char == ',') {
        field();
      } else if (char == '\n' || char == '\r') {
        if (char == '\r') {
          if (i + 1 >= source.length || source[i + 1] != '\n') {
            throw const FormatException('Invalid CSV line ending.');
          }
          i++;
        }
        field();
        record();
      } else {
        if (closedQuote || char == '\u0000') {
          throw const FormatException('Unsafe CSV cell.');
        }
        started = true;
        cell.write(char);
      }
    }
    if (inQuotes) {
      throw const FormatException('Unclosed CSV quote.');
    }
    if (started || closedQuote || row.isNotEmpty) {
      field();
      record();
    }
    return rows;
  }

  static String verifiedText(
    PrivateDocument document, {
    required int vendorId,
    required String currency,
    required int page,
    required Map<String, dynamic> source,
    required List<Map<String, dynamic>> rows,
  }) {
    if (document.mime != 'text/csv' ||
        document.bytes.isEmpty ||
        document.bytes.length > 65536 ||
        vendorId < 1 ||
        !RegExp(r'^[A-Z]{3}$').hasMatch(currency) ||
        page < 1 || page > 10000 ||
        source['vendor_id'] != vendorId ||
        source['currency'] != currency ||
        source['current_page'] != page ||
        source['evidence'] != 'SIMULATED_CANONICAL_SETTLEMENT' ||
        source['external_psp_or_bank_verified'] != false ||
        source['real_payout_enabled'] != false ||
        rows.length > 25) {
      throw const FormatException('Untrusted private earnings export.');
    }
    final text = utf8.decode(document.bytes, allowMalformed: false);
    final data = parseBounded(text);
    if (data.length != rows.length + 1 ||
        data.isEmpty ||
        data.first.length != headers.length) {
      throw const FormatException('Mismatched source export page.');
    }
    for (var i = 0; i < headers.length; i++) {
      if (data.first[i] != headers[i]) {
        throw const FormatException('Unrecognized financial CSV columns.');
      }
    }
    for (var row = 0; row < rows.length; row++) {
      final csv = data[row + 1];
      if (csv.length != headers.length) {
        throw const FormatException('Incomplete invoice CSV row.');
      }
      for (var column = 0; column < headers.length; column++) {
        final key = headers[column];
        final expected = rows[row][key];
        if (expected == null ||
            csv[column] !=
                (key == 'invoice_number'
                    ? literalNumber(expected as String)
                    : '$expected')) {
          throw const FormatException('CSV differs from canonical invoice page.');
        }
      }
    }
    return text;
  }
}
