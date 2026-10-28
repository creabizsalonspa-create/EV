import 'dart:convert';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'api.dart';

abstract interface class FinancialOperationStore {
  Future<Map<String, dynamic>?> read(String scope);
  Future<void> write(String scope, Map<String, dynamic> value);
  Future<void> remove(String scope);
}

class DeviceFinancialOperationStore implements FinancialOperationStore {
  final FlutterSecureStorage storage;
  DeviceFinancialOperationStore({FlutterSecureStorage? storage})
    : storage =
          storage ??
          const FlutterSecureStorage(
            aOptions: AndroidOptions(encryptedSharedPreferences: true),
            iOptions: IOSOptions(
              accessibility: KeychainAccessibility.unlocked_this_device,
            ),
          );
  String key(String scope) =>
      'creabiz.financial-operation.v1.${base64Url.encode(utf8.encode(scope))}';
  @override
  Future<Map<String, dynamic>?> read(String scope) async {
    final raw = await storage.read(key: key(scope));
    if (raw == null) return null;
    final data = jsonDecode(raw);
    if (data is! Map || data['key'] is! String || data['body'] is! Map)
      throw const FormatException('Invalid private operation receipt.');
    return Map<String, dynamic>.from(data);
  }

  @override
  Future<void> write(String scope, Map<String, dynamic> value) =>
      storage.write(key: key(scope), value: jsonEncode(value));
  @override
  Future<void> remove(String scope) => storage.delete(key: key(scope));
}

/// Exact payload and identity are stored BEFORE dispatch. No automatic replay,
/// replacement key or editable draft after an uncertain result, including restart.
dynamic _freezeFinancialValue(dynamic value) {
  if (value is Map)
    return Map<String, dynamic>.unmodifiable(
      value.map(
        (key, item) => MapEntry(key as String, _freezeFinancialValue(item)),
      ),
    );
  if (value is List)
    return List<dynamic>.unmodifiable(value.map(_freezeFinancialValue));
  return value;
}

class FinancialOperation {
  final FinancialOperationStore store;
  final String scope;
  Map<String, dynamic>? pending;
  static final queues = <String, Future<void>>{};
  FinancialOperation(this.store, this.scope);
  Future<T> serial<T>(Future<T> Function() action) {
    final result = (queues[scope] ?? Future<void>.value()).then(
      (_) => action(),
    );
    final tail = result.then<void>(
      (_) {},
      onError: (Object e, StackTrace s) {},
    );
    queues[scope] = tail;
    tail.whenComplete(() {
      if (identical(queues[scope], tail)) queues.remove(scope);
    });
    return result;
  }

  Future<void> restore() => serial(() async {
    final saved = await store.read(scope);
    pending = saved == null
        ? null
        : _freezeFinancialValue(saved) as Map<String, dynamic>;
  });
  Future<Map<String, dynamic>> prepare(Map<String, dynamic> body) {
    // Snapshot before the first asynchronous boundary, including nested selections.
    final frozen =
        _freezeFinancialValue(jsonDecode(jsonEncode(body)))
            as Map<String, dynamic>;
    return serial(() async {
      // Retain this screen's original identity even when another screen has already
      // acknowledged it. A stale retry must not silently allocate a fresh request.
      final saved = await store.read(scope);
      pending ??= saved == null
          ? null
          : _freezeFinancialValue(saved) as Map<String, dynamic>;
      if (pending != null) {
        if (jsonEncode(pending!['body']) != jsonEncode(frozen))
          throw const ApiException(409, 'Resolve the existing request first.');
        return Map<String, dynamic>.unmodifiable({
          ...frozen,
          'idempotency_key': pending!['key'],
        });
      }
      final value = <String, dynamic>{'key': operationKey(), 'body': frozen};
      await store.write(scope, value);
      pending = _freezeFinancialValue(value) as Map<String, dynamic>;
      return Map<String, dynamic>.unmodifiable({
        ...frozen,
        'idempotency_key': value['key'],
      });
    });
  }

  Future<void> confirmed() => serial(() async {
    final current = await store.read(scope);
    if (current != null && pending != null && current['key'] == pending!['key'])
      await store.remove(scope);
    pending = null;
  });
}
