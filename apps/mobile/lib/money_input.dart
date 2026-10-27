/// Parse exact UI decimals, including Arabic digits; never silently round prices.
int? decimalMinor(String value, int digits, {int maximum = 1000000}) {
  if (digits < 0 || digits > 3) return null;
  final normalized = value.trim().runes.map((c) {
    if (c >= 0x660 && c <= 0x669) return '${c - 0x660}';
    if (c >= 0x6f0 && c <= 0x6f9) return '${c - 0x6f0}';
    return c == 0x66b ? '.' : String.fromCharCode(c);
  }).join();
  final pattern = digits == 0
      ? RegExp(r'^\d+$')
      : RegExp('^\\d+(?:\\.\\d{1,$digits})?\$');
  if (normalized.length > 20 || !pattern.hasMatch(normalized)) return null;
  final pieces = normalized.split('.');
  final amount = int.tryParse(
    pieces.first + (pieces.length == 2 ? pieces[1] : '').padRight(digits, '0'),
  );
  return amount != null && amount >= 0 && amount <= maximum ? amount : null;
}

String minorDecimal(int amount, int digits) {
  if (amount < 0 || digits < 0 || digits > 3)
    throw const FormatException('Invalid monetary precision.');
  final text = '$amount'.padLeft(digits + 1, '0');
  return digits == 0
      ? text
      : '${text.substring(0, text.length - digits)}.${text.substring(text.length - digits)}';
}
