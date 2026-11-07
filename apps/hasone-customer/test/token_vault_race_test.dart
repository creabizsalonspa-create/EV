import 'dart:async';

import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:evcharg/core/service/api_service/api_config.dart';
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
  bool failWriteAfterCommit = false;

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
    if (failWriteAfterCommit) throw StateError('write failed after commit');
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

  test('failed vault deletion blocks rejected token across new instances', () async {
    final vault = ControlledTokenVault();
    final prefs = SharedPrefs(vault: vault);
    await prefs.saveToken('expired-credential');
    await prefs.saveUser({'email': 'previous@example.test'});
    vault.failDelete = true;
    await expectLater(prefs.clear(), throwsStateError);
    final storage = await SharedPreferences.getInstance();
    expect(
      storage.getBool('credential_blocked:${ApiConfig.baseUrl}'),
      isTrue,
    );
    // A new SharedPrefs instance also fails closed: neither token nor the
    // old cached profile is exposed while a Keychain deletion is pending.
    final nextLaunch = SharedPrefs(vault: vault);
    expect(await nextLaunch.getToken(), isNull);
    expect(await nextLaunch.getUser(), isNull);
    expect(vault.value, 'expired-credential');
    expect(Session.accessToken, isNull);

    vault.failDelete = false;
    await nextLaunch.saveToken('fresh-credential');
    expect(await nextLaunch.getToken(), 'fresh-credential');
    expect(storage.getBool('credential_blocked:${ApiConfig.baseUrl}'), isNull);
  });

  test('failed secure write cannot expose a partially stored bearer', () async {
    final vault = ControlledTokenVault()..failWriteAfterCommit = true;
    final prefs = SharedPrefs(vault: vault);
    await expectLater(
      prefs.saveToken('partially-stored'),
      throwsStateError,
    );
    final storage = await SharedPreferences.getInstance();
    expect(
      storage.getBool('credential_blocked:${ApiConfig.baseUrl}'),
      isTrue,
    );
    expect(vault.value, 'partially-stored');
    expect(await SharedPrefs(vault: vault).getToken(), isNull);
    expect(Session.accessToken, isNull);

    vault.failWriteAfterCommit = false;
    await prefs.saveToken('approved-new-login');
    expect(await prefs.getToken(), 'approved-new-login');
    expect(storage.getBool('credential_blocked:${ApiConfig.baseUrl}'), isNull);
  });

  test('new login replaces old cached profile before publishing its token', () async {
    final vault = ControlledTokenVault();
    final prefs = SharedPrefs(vault: vault);
    await prefs.saveToken('first-user');
    await prefs.saveUser({'email': 'first@example.test'});
    expect((await prefs.getUser())?['email'], 'first@example.test');
    await prefs.saveToken('second-user');
    expect(Session.accessToken, 'second-user');
    expect(await prefs.getUser(), isNull);
    await prefs.saveUser({'email': 'second@example.test'});
    expect((await prefs.getUser())?['email'], 'second@example.test');
  });

  test('delayed old logout cannot erase an already switched account', () async {
    final vault = ControlledTokenVault();
    final prefs = SharedPrefs(vault: vault);
    await prefs.saveToken('first-account-token');
    final staleGeneration = SharedPrefs.credentialGeneration;
    final staleToken = Session.accessToken;
    await prefs.saveToken('second-account-token');
    final result = await prefs.clearIfCurrent(
      expectedGeneration: staleGeneration,
      expectedToken: staleToken,
    );
    expect(result, isFalse);
    expect(await prefs.getToken(), 'second-account-token');
    expect(Session.accessToken, 'second-account-token');
  });

  test('current account can explicitly logout via identity fence', () async {
    final vault = ControlledTokenVault();
    final prefs = SharedPrefs(vault: vault);
    await prefs.saveToken('active-session');
    final accepted = await prefs.clearIfCurrent(
      expectedGeneration: SharedPrefs.credentialGeneration,
      expectedToken: Session.accessToken,
    );
    expect(accepted, isTrue);
    expect(vault.value, isNull);
    expect(await prefs.getToken(), isNull);
    expect(Session.accessToken, isNull);
  });

  test('logout superseded during vault deletion cannot navigate as completed', () async {
    final vault = ControlledTokenVault();
    final prefs = SharedPrefs(vault: vault);
    await prefs.saveToken('first-session');
    final signal = Completer<void>();
    final release = Completer<void>();
    vault.deleteStarted = signal;
    vault.deleteWait = release.future;
    final staleLogout = prefs.clearIfCurrent(
      expectedGeneration: SharedPrefs.credentialGeneration,
      expectedToken: Session.accessToken,
    );
    await signal.future;
    final nextLogin = prefs.saveToken('second-session');
    release.complete();
    expect(await staleLogout, isFalse);
    await nextLogin;
    expect(await prefs.getToken(), 'second-session');
    expect(Session.accessToken, 'second-session');
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
