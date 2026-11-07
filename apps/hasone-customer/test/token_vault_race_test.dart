import 'dart:async';

import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:evcharg/core/service/session/session.dart';
import 'package:evcharg/core/service/shared_preferance/shared_prefarance.dart';

class ControlledTokenVault implements TokenVault {
  String? value;
  Completer<void>? readStarted;
  Completer<void>? writeStarted;
  Completer<void>? deleteStarted;
  Future<void>? readWait;
  Future<void>? writeWait;
  Future<void>? deleteWait;
  bool failDelete = false;

  void started(Completer<void>? signal) {
    if (signal != null && !signal.isCompleted) signal.complete();
  }

  @override
  Future<String?> read({required String key}) async {
    final saved = value;
    started(readStarted);
    if (readWait != null) await readWait;
    return saved;
  }

  @override
  Future<void> write({required String key, required String value}) async {
    started(writeStarted);
    if (writeWait != null) await writeWait;
    this.value = value;
  }

  @override
  Future<void> delete({required String key}) async {
    started(deleteStarted);
    if (deleteWait != null) await deleteWait;
    if (failDelete) throw StateError('secure delete failed');
    value = null;
  }
}

void main() {
  setUp(() {
    SharedPreferences.setMockInitialValues({});
    Session.clear();
  });

  test('a late Keychain read cannot restore a token after logout', () async {
    final vault = ControlledTokenVault();
    final prefs = SharedPrefs(vault: vault);
    await prefs.saveToken('old-credential');
    final started = Completer<void>();
    final release = Completer<void>();
    vault.readStarted = started;
    vault.readWait = release.future;
    final pendingRead = prefs.getToken();
    await started.future;
    final clearing = SharedPrefs(vault: vault).clear();
    release.complete();
    expect(await pendingRead, isNull);
    await clearing;
    expect(vault.value, isNull);
    expect(Session.accessToken, isNull);
  });

  test('an old delayed token write cannot resurrect a cleared session', () async {
    final vault = ControlledTokenVault();
    final prefs = SharedPrefs(vault: vault);
    final started = Completer<void>();
    final release = Completer<void>();
    vault.writeStarted = started;
    vault.writeWait = release.future;
    final writing = prefs.saveToken('old-credential');
    await started.future;
    final clearing = SharedPrefs(vault: vault).clear();
    release.complete();
    await writing;
    await clearing;
    expect(vault.value, isNull);
    expect(Session.accessToken, isNull);
  });

  test('new login is retained when earlier logout deletion finishes', () async {
    final vault = ControlledTokenVault();
    final prefs = SharedPrefs(vault: vault);
    await prefs.saveToken('old-credential');
    final started = Completer<void>();
    final release = Completer<void>();
    vault.deleteStarted = started;
    vault.deleteWait = release.future;
    final clearing = prefs.clear();
    await started.future;
    final latestLogin = SharedPrefs(vault: vault).saveToken('new-credential');
    release.complete();
    await clearing;
    await latestLogin;
    expect(vault.value, 'new-credential');
    expect(Session.accessToken, 'new-credential');
  });

  test('failed secure deletion never claims an erased on-device token', () async {
    final vault = ControlledTokenVault();
    final prefs = SharedPrefs(vault: vault);
    await prefs.saveToken('old-credential');
    vault.failDelete = true;
    await expectLater(prefs.clear(), throwsStateError);
    expect(Session.accessToken, isNull);
    expect(vault.value, 'old-credential');
    vault.failDelete = false;
    await prefs.clear();
    expect(vault.value, isNull);
  });

  test('cached user profile is erased along with the token', () async {
    final vault = ControlledTokenVault();
    final prefs = SharedPrefs(vault: vault);
    await prefs.saveToken('old-credential');
    await prefs.saveUser({'email': 'synthetic@example.test'});
    expect((await prefs.getUser())?['email'], 'synthetic@example.test');
    await prefs.clear();
    expect(await prefs.getUser(), isNull);
    expect(vault.value, isNull);
  });
}
