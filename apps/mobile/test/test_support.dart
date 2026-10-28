// Isolated test fixtures. Never imported by application source.
import 'package:creabiz_ev/api.dart';
import 'package:creabiz_ev/preferences.dart';

class TestStore implements PreferenceStore {
  String? value;
  @override
  Future<String?> read() async => value;
  @override
  Future<void> write(String value) async {
    this.value = value;
  }
}

class FixtureApi extends EvApi {
  final String role;
  bool failNext = false;
  bool expired = false;
  final queries = <Map<String, String>>[];
  final calls = <String>[];
  FixtureApi({this.role = 'customer'})
    : super(Uri.parse('http://127.0.0.1:8000'));
  @override
  Future<Account> signIn(String email, String password, {String? name}) async =>
      Account.fromJson({
        'id': 1,
        'name': 'Synthetic test account',
        'role': role,
      });
  @override
  Future<dynamic> request(
    String path, {
    String method = 'GET',
    Map<String, dynamic>? body,
    Map<String, String> query = const {},
  }) async {
    calls.add('$method $path');
    if (expired) throw const ApiException(401, 'expired');
    if (path == 'csrf') return {'token': 'fixture'};
    if (path == 'favorites/status') return {'favorite_station_ids': <int>[]};
    if (path == 'me')
      return {'id': 1, 'name': 'Synthetic test account', 'role': role};
    if (path == 'stations') {
      queries.add(query);
      if (failNext) {
        failNext = false;
        throw const ApiException(503, 'temporary');
      }
      final page = int.parse(query['page'] ?? '1');
      return {
        'data': [
          {
            'id': page,
            'name': 'Contract station page $page',
            'connector_type': 'CCS2',
            'evidence': 'SIMULATED',
            'power_kw': 50,
            'reservable': true,
          },
        ],
        'current_page': page,
        'last_page': 2,
        'total': 26,
      };
    }
    return {'data': [], 'current_page': 1, 'last_page': 1, 'total': 0};
  }
}
