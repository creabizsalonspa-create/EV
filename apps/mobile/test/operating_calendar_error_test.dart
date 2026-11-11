import 'package:creabiz_ev/api.dart';
import 'package:creabiz_ev/strings.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  test('only known calendar conflicts become localized calendar reasons', () {
    expect(
      errorKey(
        const ApiException(
          409,
          'The selected time is outside the station operating calendar.',
        ),
      ),
      'calendarClosed',
    );
    expect(
      errorKey(
        const ApiException(
          409,
          'The operating calendar is unavailable. Refresh before booking or starting.',
        ),
      ),
      'calendarUnavailable',
    );
    expect(
      errorKey(const ApiException(409, 'PRIVATE UNKNOWN DIAGNOSTIC')),
      'conflict',
    );
    expect(
      errorKey(
        const ApiException(
          500,
          'The selected time is outside the station operating calendar.',
        ),
      ),
      'serverError',
    );
    expect(
      EvStrings.en['calendarClosed'],
      isNot(EvStrings.ar['calendarClosed']),
    );
    expect(EvStrings.ar['calendarUnavailable'], contains('حدّث'));
  });
}
