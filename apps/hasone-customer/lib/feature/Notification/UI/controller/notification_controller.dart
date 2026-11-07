import 'package:get/get.dart';

import '../../../../core/service/api_service/api_service.dart';
import '../../../../core/service/shared_preferance/shared_prefarance.dart';
import '../../model/notification_model.dart' as settings_model;
import '../../model/show_notification_model.dart';

class NotificationController extends GetxController {
  final _net = NetworkService().client;
  final _prefs = SharedPrefs();

  // ── Notification Inbox ──────────────────────────────────
  RxList<Notifications> notifications = <Notifications>[].obs;
  RxInt unreadCount = 0.obs;
  RxInt total = 0.obs;
  RxInt currentPage = 1.obs;
  RxInt totalPages = 1.obs;
  RxBool inboxLoading = false.obs;

  // ── Notification Settings ───────────────────────────────
  Rxn<settings_model.Data> settings = Rxn<settings_model.Data>();
  RxBool settingsLoading = false.obs;
  /// Tracks which single key is currently saving (empty = none)
  RxString savingKey = ''.obs;

  @override
  void onInit() {
    super.onInit();
    fetchNotificationInbox();
    fetchNotificationSettings();
  }

  /// GET /api/v1/NotificationInbox?page=1&limit=20
  /// Returns paginated notifications, unreadCount, total, page, totalPages
  Future<void> fetchNotificationInbox({int page = 1, int limit = 20}) async {
    final token = await _prefs.getToken();
    if (token == null || token.isEmpty) return;

    inboxLoading.value = true;
    final res = await _net.getRequest(
      '/api/v1/NotificationInbox',
      query: {'page': page, 'limit': limit},
    );
    inboxLoading.value = false;

    if (res.isSuccess && (res.statusCode == 200 || res.statusCode == 201)) {
      final model = ShowNotificationModel.fromJson(res.responseData ?? {});
      final d = model.data;
      if (d != null) {
        notifications.value = d.notifications ?? [];
        unreadCount.value = d.unreadCount ?? 0;
        total.value = d.total ?? 0;
        currentPage.value = d.page ?? 1;
        totalPages.value = d.totalPages ?? 1;
      }
    }
  }

  /// GET /api/v1/Notifications
  /// Returns notification settings
  Future<void> fetchNotificationSettings() async {
    final token = await _prefs.getToken();
    if (token == null || token.isEmpty) return;

    settingsLoading.value = true;
    final res = await _net.getRequest('/api/v1/Notifications');
    settingsLoading.value = false;

    if (res.isSuccess && (res.statusCode == 200 || res.statusCode == 201)) {
      final model = settings_model.NotificationModel.fromJson(res.responseData ?? {});
      settings.value = model.data;
    }
  }

  /// PATCH /api/v1/Notifications
  /// Update notification settings
  Future<void> _saveSettings({
    required bool chargingStatusAlerts,
    required bool lowBatteryAlerts,
    required bool bookingUpdates,
    required bool stationUpdates,
    required bool paymentAndSession,
  }) async {
    final res = await _net.patchRequest(
      '/api/v1/Notifications',
      body: {
        'chargingStatusAlerts': chargingStatusAlerts,
        'lowBatteryAlerts': lowBatteryAlerts,
        'bookingUpdates': bookingUpdates,
        'stationUpdates': stationUpdates,
        'paymentAndSession': paymentAndSession,
      },
    );

    if (res.isSuccess && (res.statusCode == 200 || res.statusCode == 201)) {
      await fetchNotificationSettings();
    } else {
      Get.snackbar('Error', res.errorMessage ?? 'Failed to update settings',
          snackPosition: SnackPosition.TOP);
    }
  }

  /// Toggle a single setting — only that row shows loading
  Future<void> toggleSetting(String key, bool value) async {
    final settings_model.Data? s = settings.value;
    if (s == null || savingKey.value.isNotEmpty) return;

    savingKey.value = key;
    await _saveSettings(
      chargingStatusAlerts: key == 'chargingStatusAlerts' ? value : (s.chargingStatusAlerts ?? false),
      lowBatteryAlerts:     key == 'lowBatteryAlerts'     ? value : (s.lowBatteryAlerts ?? false),
      bookingUpdates:       key == 'bookingUpdates'       ? value : (s.bookingUpdates ?? false),
      stationUpdates:       key == 'stationUpdates'       ? value : (s.stationUpdates ?? false),
      paymentAndSession:    key == 'paymentAndSession'    ? value : (s.paymentAndSession ?? false),
    );
    savingKey.value = '';
  }

  /// Format createdAt ISO string to readable time-ago
  String timeAgo(String? iso) {
    if (iso == null || iso.isEmpty) return '';
    try {
      final dt = DateTime.parse(iso).toLocal();
      final diff = DateTime.now().difference(dt);
      if (diff.inMinutes < 1) return 'Just now';
      if (diff.inMinutes < 60) return '${diff.inMinutes} min ago';
      if (diff.inHours < 24) return '${diff.inHours} hours ago';
      if (diff.inDays < 7) return '${diff.inDays} days ago';
      return '${dt.day}/${dt.month}/${dt.year}';
    } catch (_) {
      return '';
    }
  }
}
