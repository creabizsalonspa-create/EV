import 'package:flutter/foundation.dart';
import 'package:flutter/services.dart';

/// This bridge only selects an existing signed-in, read-only top-level screen.
/// Links must never encode a user, booking, charging, token or payment action.
const navigationTabs = <String>[
  'discover',
  'reservations',
  'sessions',
  'garage',
  'support',
  'fleet',
];

int? navigationTabIndex(String? target) {
  if (target == null) return null;
  final index = navigationTabs.indexOf(target);
  return index < 0 ? null : index;
}

/// The native hosts validate the complete URL and only supply a known tab.
/// Dart validates it again because platform channel values are untrusted.
class NativeNavigationBridge {
  static const channel = MethodChannel('com.creabiz.ev/navigation');
  static final ValueNotifier<int> pending = ValueNotifier<int>(0);
  static bool _started = false;

  static void start() {
    if (_started) return;
    _started = true;
    channel.setMethodCallHandler((call) async {
      if (call.method == 'navigationAvailable') {
        pending.value += 1;
      }
    });
  }

  static Future<int?> consume() async {
    try {
      final raw = await channel.invokeMethod<String>('consumeNavigation');
      return navigationTabIndex(raw);
    } on PlatformException {
      return null;
    } on MissingPluginException {
      // Widget tests and unsupported hosts never receive native links.
      return null;
    }
  }
}
