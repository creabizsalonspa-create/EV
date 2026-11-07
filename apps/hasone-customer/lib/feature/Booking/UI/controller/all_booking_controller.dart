import 'package:flutter/material.dart';
import 'package:get/get.dart';

import '../../../../core/service/api_service/api_service.dart';
import '../../../../core/service/shared_preferance/shared_prefarance.dart';
import '../../model/All_booking_model.dart';
import '../../model/book_navigation_Model.dart' as nav_model;
import '../../model/confirm_arrive_navigation_model.dart' as arrive_model;
import '../../model/update_location_duration_model.dart' as update_model;
import '../../model/latest_car_update_location_model.dart' as latest_model;
import '../../model/charging_session_model.dart' as session_model;
import '../../model/charging_starting_model.dart' as status_model;
import '../../model/charging_station_model.dart' as station_model;
import '../../model/extend_session_model.dart' as extend_model;
import '../../model/stop_charging_model.dart' as stop_model;
import '../../model/pay_session_model.dart' as pay_model;
import '../../../map/model/station_details_model.dart';
import '../../../Home/UI/controller/home_controller.dart';

class AllBookingController extends GetxController {
  final _net = NetworkService().client;
  final _prefs = SharedPrefs();

  RxList<Data> bookings = <Data>[].obs;
  RxBool loading = false.obs;
  RxBool rescheduleLoading = false.obs;

  // Search & Filter
  RxString searchQuery = ''.obs;
  RxString selectedFilter = 'All'.obs; // All | Upcoming | Completed | Cancelled

  // Navigation
  Rxn<nav_model.Data> navigationData = Rxn<nav_model.Data>();
  RxBool navigationLoading = false.obs;

  // Confirm arrival
  Rxn<arrive_model.Data> arrivalData = Rxn<arrive_model.Data>();
  RxBool arrivalLoading = false.obs;

  // Update location
  Rxn<update_model.Data> updateLocationData = Rxn<update_model.Data>();
  RxBool updateLocationLoading = false.obs;

  // Latest location
  Rxn<latest_model.Data> latestLocationData = Rxn<latest_model.Data>();
  RxBool latestLocationLoading = false.obs;

  List<Data> get filteredBookings {
    var list = bookings.toList();
    if (selectedFilter.value != 'All') {
      list = list.where((b) => b.status == selectedFilter.value).toList();
    }
    final q = searchQuery.value.trim().toLowerCase();
    if (q.isNotEmpty) {
      list = list.where((b) =>
          (b.stationName ?? '').toLowerCase().contains(q) ||
          (b.address ?? '').toLowerCase().contains(q)).toList();
    }
    return list;
  }

  // Station slots for rebooking
  RxList<Slots> stationSlots = <Slots>[].obs;
  RxBool slotsLoading = false.obs;

  @override
  void onInit() {
    super.onInit();
    fetchBookings();
  }

  /// GET /api/v1/Bookings
  Future<void> fetchBookings() async {
    final token = await _prefs.getToken();
    if (token == null || token.isEmpty) return;

    loading.value = true;
    final res = await _net.getRequest('/api/v1/Bookings');
    loading.value = false;

    if (res.isSuccess && (res.statusCode == 200 || res.statusCode == 201)) {
      final model = AllBookingModel.fromJson(res.responseData ?? {});
      bookings.value = model.data ?? [];
    }
  }

  /// GET /api/v1/StationDetail/{stationId} — fetch real slots for reschedule
  Future<void> fetchStationSlots(String stationId, {String? date}) async {
    stationSlots.clear();
    slotsLoading.value = true;

    final query = <String, dynamic>{};
    if (date != null && date.isNotEmpty) {
      query['date'] = date;
    }

    final res = await _net.getRequest(
      '/api/v1/StationDetail/$stationId',
      query: query.isNotEmpty ? query : null,
    );
    slotsLoading.value = false;

    if (res.isSuccess && (res.statusCode == 200 || res.statusCode == 201)) {
      final model = StationDetailsModel.fromJson(res.responseData ?? {});
      stationSlots.value = model.data?.slots ?? [];
    }
  }

  /// GET /api/v1/Stations/{stationId}/slots?date=08 Mar, Sun
  /// Fetches fresh slot availability for a specific date (used when user changes date in rebooking)
  Future<void> fetchSlotsForDate(String stationId, String formattedDate) async {
    stationSlots.clear();
    slotsLoading.value = true;

    final res = await _net.getRequest(
      '/api/v1/Stations/$stationId/slots',
      query: {'date': formattedDate},
    );
    slotsLoading.value = false;

    if (res.isSuccess && (res.statusCode == 200 || res.statusCode == 201)) {
      final model = SlotsResponseModel.fromJson(res.responseData ?? {});
      stationSlots.value = model.data?.slots ?? [];
    }
  }

  /// Fetch slots when only stationName is available (no stationId in booking list).
  /// Finds the station from HomeController's already-loaded stations list by name,
  /// then calls GET /api/v1/StationDetail/{stationId} to get all real slots.
  Future<void> fetchSlotsByBookingId(String bookingId) async {
    // bookingId param kept for backward compat — not used since endpoint doesn't exist
    // This is called with stationName instead — see RebookingScreen
  }

  Future<void> fetchSlotsByStationName(String stationName, {String? date}) async {
    stationSlots.clear();
    slotsLoading.value = true;

    try {
      // Find station from HomeController's loaded stations list
      final homeCtrl = Get.find<HomeController>();
      final nameLower = stationName.trim().toLowerCase();

      final matched = homeCtrl.stations.firstWhereOrNull(
        (s) => (s.name ?? '').trim().toLowerCase() == nameLower,
      );

      if (matched == null || (matched.sId ?? '').isEmpty) {
        slotsLoading.value = false;
        return;
      }

      // If date provided, use the slots endpoint; otherwise use StationDetail
      if (date != null && date.isNotEmpty) {
        final res = await _net.getRequest(
          '/api/v1/Stations/${matched.sId}/slots',
          query: {'date': date},
        );
        slotsLoading.value = false;

        if (res.isSuccess && (res.statusCode == 200 || res.statusCode == 201)) {
          final model = SlotsResponseModel.fromJson(res.responseData ?? {});
          stationSlots.value = model.data?.slots ?? [];
        }
      } else {
        // Fetch all real slots via StationDetail
        final res = await _net.getRequest('/api/v1/StationDetail/${matched.sId}');
        slotsLoading.value = false;

        if (res.isSuccess && (res.statusCode == 200 || res.statusCode == 201)) {
          final model = StationDetailsModel.fromJson(res.responseData ?? {});
          stationSlots.value = model.data?.slots ?? [];
        }
      }
    } catch (_) {
      slotsLoading.value = false;
    }
  }

  /// PUT /api/v1/Bookings/{id}/cancel
  Future<bool> cancelBooking(String bookingId) async {
    final res = await _net.putRequest('/api/v1/Bookings/$bookingId/cancel');
    if (res.isSuccess && (res.statusCode == 200 || res.statusCode == 201)) {
      await fetchBookings();
      return true;
    }
    return false;
  }

  /// PUT /api/v1/Bookings/{id}/reschedule
  /// body: { date, time, slotStart, slotEnd }
  Future<bool> rescheduleBooking({
    required String bookingId,
    required String date,
    required String time,
    required String slotStart,
    required String slotEnd,
  }) async {
    rescheduleLoading.value = true;
    final res = await _net.putRequest(
      '/api/v1/Bookings/$bookingId/reschedule',
      body: {
        'date': date,
        'time': time,
        'slotStart': slotStart,
        'slotEnd': slotEnd,
      },
    );
    rescheduleLoading.value = false;

    if (res.isSuccess && (res.statusCode == 200 || res.statusCode == 201)) {
      await fetchBookings();
      return true;
    }
    return false;
  }

  // ══════════════════════════════════════════════════════════════════════════════
  // NAVIGATION APIs
  // ══════════════════════════════════════════════════════════════════════════════

  /// GET /api/v1/Bookings/{bookingId}/navigate
  Future<nav_model.Data?> fetchNavigation(String bookingId) async {
    navigationLoading.value = true;
    navigationData.value = null;
    final res = await _net.getRequest('/api/v1/Bookings/$bookingId/navigate');
    navigationLoading.value = false;

    if (res.isSuccess && (res.statusCode == 200 || res.statusCode == 201)) {
      final model = nav_model.BookNavigationModel.fromJson(res.responseData ?? {});
      navigationData.value = model.data;
      return model.data;
    }
    return null;
  }

  /// POST /api/v1/Bookings/{bookingId}/arrive
  /// body: { latitude, longitude }
  Future<arrive_model.Data?> confirmArrival(
    String bookingId, {
    required double latitude,
    required double longitude,
  }) async {
    arrivalLoading.value = true;
    arrivalData.value = null;
    final res = await _net.postRequest(
      '/api/v1/Bookings/$bookingId/arrive',
      body: {
        'latitude': latitude,
        'longitude': longitude,
      },
    );
    arrivalLoading.value = false;

    if (res.isSuccess && (res.statusCode == 200 || res.statusCode == 201)) {
      final model = arrive_model.ConfirmArriveStationModel.fromJson(res.responseData ?? {});
      arrivalData.value = model.data;
      return model.data;
    }

    // Handle "Arrival already confirmed" as success
    final errorMsg = res.errorMessage ?? '';
    if (res.statusCode == 400 && errorMsg.toLowerCase().contains('already confirmed')) {
      final alreadyData = arrive_model.Data(
        bookingId: bookingId,
        popupTitle: 'Arrival Confirm',
        arrivalStatus: 'Confirmed',
        stationName: navigationData.value?.stationName ?? updateLocationData.value?.stationName ?? '',
        description: "You've already arrived at the charging station. Plug in your vehicle to start charging.",
      );
      arrivalData.value = alreadyData;
      return alreadyData;
    }

    return null;
  }

  /// PUT /api/v1/Bookings/{bookingId}/location
  /// body: { latitude, longitude }
  Future<update_model.Data?> updateLocation({
    required String bookingId,
    required double latitude,
    required double longitude,
  }) async {
    updateLocationLoading.value = true;
    final res = await _net.putRequest(
      '/api/v1/Bookings/$bookingId/location',
      body: {
        'latitude': latitude,
        'longitude': longitude,
      },
    );
    updateLocationLoading.value = false;

    if (res.isSuccess && (res.statusCode == 200 || res.statusCode == 201)) {
      final model = update_model.UpdateLocationDurationMOdel.fromJson(res.responseData ?? {});
      updateLocationData.value = model.data;
      return model.data;
    }
    return null;
  }

  /// GET /api/v1/Bookings/{bookingId}/location
  Future<latest_model.Data?> fetchLatestLocation(String bookingId) async {
    latestLocationLoading.value = true;
    final res = await _net.getRequest('/api/v1/Bookings/$bookingId/location');
    latestLocationLoading.value = false;

    if (res.isSuccess && (res.statusCode == 200 || res.statusCode == 201)) {
      final model = latest_model.LatestCarUpdateLocationMOdel.fromJson(res.responseData ?? {});
      latestLocationData.value = model.data;
      return model.data;
    }
    return null;
  }

  // ══════════════════════════════════════════════════════════════════════════════
  // CHARGING STATION (pre-scan) API
  // ══════════════════════════════════════════════════════════════════════════════

  Rxn<station_model.Data> chargingStationData = Rxn<station_model.Data>();
  RxBool chargingStationLoading = false.obs;

  /// GET /api/v1/ChargingStation/{bookingId}
  /// Returns station data including sessionId needed for POST /ChargingSession/{sessionId}/start
  Future<station_model.Data?> fetchChargingStation(String bookingId) async {
    chargingStationLoading.value = true;
    chargingStationData.value = null;

    final res = await _net.getRequest('/api/v1/ChargingStation/$bookingId');
    chargingStationLoading.value = false;

    if (res.isSuccess && (res.statusCode == 200 || res.statusCode == 201)) {
      final model = station_model.ChargingStationMOdel.fromJson(res.responseData ?? {});
      chargingStationData.value = model.data;
      return model.data;
    }

    // Handle "too early" error (400)
    if (res.statusCode == 400 && res.responseData is Map) {
      final msg = (res.responseData as Map)['message']?.toString() ?? '';
      chargingSessionError.value = msg.isNotEmpty ? msg : 'Cannot start charging yet.';
    }

    return null;
  }

  // ══════════════════════════════════════════════════════════════════════════════
  // CHARGING SESSION APIs
  // ══════════════════════════════════════════════════════════════════════════════

  // Start charging session
  Rxn<session_model.Data> chargingSessionData = Rxn<session_model.Data>();
  RxBool chargingSessionLoading = false.obs;
  RxnString chargingSessionError = RxnString();

  // Live charging status
  Rxn<status_model.Data> chargingStatusData = Rxn<status_model.Data>();
  RxBool chargingStatusLoading = false.obs;

  /// POST /api/v1/ChargingSession/{bookingId}/start
  /// Returns session data on success, null on failure.
  /// Sets chargingSessionError on "too early" or other errors.
  Future<session_model.Data?> startChargingSession(String bookingId) async {
    chargingSessionLoading.value = true;
    chargingSessionError.value = null;
    chargingSessionData.value = null;

    final res = await _net.postRequest(
      '/api/v1/ChargingSession/$bookingId/start',
    );
    chargingSessionLoading.value = false;

    if (res.isSuccess && (res.statusCode == 200 || res.statusCode == 201)) {
      final model = session_model.ChargingSessionModel.fromJson(res.responseData ?? {});
      chargingSessionData.value = model.data;
      return model.data;
    }

    // Handle error - e.g. "too early"
    final errorMsg = res.errorMessage ?? '';
    if (res.statusCode == 400) {
      // Try to extract message from response
      String msg = errorMsg;
      if (res.responseData is Map) {
        msg = (res.responseData as Map)['message']?.toString() ?? errorMsg;
      }
      chargingSessionError.value = msg.isNotEmpty ? msg : 'Failed to start charging session.';
    } else {
      chargingSessionError.value = errorMsg.isNotEmpty ? errorMsg : 'Failed to start charging session.';
    }

    return null;
  }

  /// GET /api/v1/ChargingSession/{sessionId}/status
  /// Returns live charging status (batteryPercent, timeRemaining, kwhUsed, etc.)
  Future<status_model.Data?> fetchChargingStatus(String sessionId) async {
    chargingStatusLoading.value = true;

    final res = await _net.getRequest('/api/v1/ChargingSession/$sessionId/status');
    chargingStatusLoading.value = false;

    if (res.isSuccess && (res.statusCode == 200 || res.statusCode == 201)) {
      final model = status_model.ChargingStartingModel.fromJson(res.responseData ?? {});
      chargingStatusData.value = model.data;
      return model.data;
    }
    return null;
  }

  // ══════════════════════════════════════════════════════════════════════════════
  // EXTEND SESSION
  // ══════════════════════════════════════════════════════════════════════════════

  Rxn<extend_model.Data> extendSessionData = Rxn<extend_model.Data>();
  RxBool extendSessionLoading = false.obs;

  /// POST /api/v1/ChargingSession/{sessionId}/extend
  /// body: { "extendMins": 30 }
  Future<extend_model.Data?> extendSession(String sessionId, int extendMins) async {
    extendSessionLoading.value = true;
    extendSessionData.value = null;

    final res = await _net.postRequest(
      '/api/v1/ChargingSession/$sessionId/extend',
      body: {'extendMins': extendMins},
    );
    extendSessionLoading.value = false;

    if (res.isSuccess && (res.statusCode == 200 || res.statusCode == 201)) {
      final model = extend_model.ExtendSessionModel.fromJson(res.responseData ?? {});
      extendSessionData.value = model.data;
      return model.data;
    }
    return null;
  }

  // ══════════════════════════════════════════════════════════════════════════════
  // STOP CHARGING
  // ══════════════════════════════════════════════════════════════════════════════

  Rxn<stop_model.Data> stopChargingData = Rxn<stop_model.Data>();
  RxBool stopChargingLoading = false.obs;

  /// POST /api/v1/ChargingSession/{sessionId}/stop
  /// Returns summary: energyDelivered, costPerKwh, extendSessionCharge, totalAmount, needsPayment
  Future<stop_model.Data?> stopCharging(String sessionId) async {
    stopChargingLoading.value = true;
    stopChargingData.value = null;

    final res = await _net.postRequest(
      '/api/v1/ChargingSession/$sessionId/stop',
    );
    stopChargingLoading.value = false;

    if (res.isSuccess && (res.statusCode == 200 || res.statusCode == 201)) {
      final model = stop_model.StopChargingModel.fromJson(res.responseData ?? {});
      stopChargingData.value = model.data;
      return model.data;
    }
    return null;
  }

  // ══════════════════════════════════════════════════════════════════════════════
  // PAY SESSION (extension charges)
  // ══════════════════════════════════════════════════════════════════════════════

  Rxn<pay_model.Data> paySessionData = Rxn<pay_model.Data>();
  RxBool paySessionLoading = false.obs;

  /// POST /api/v1/ChargingSession/{sessionId}/pay
  /// Pays extension charges with selected payment method.
  Future<pay_model.Data?> paySession(String sessionId, {String paymentMethod = 'card', String? cardId, String? stripePaymentIntentId}) async {
    paySessionLoading.value = true;
    paySessionData.value = null;

    final body = <String, dynamic>{
      'paymentMethod': paymentMethod,
    };
    if (cardId != null && cardId.isNotEmpty) {
      body['cardId'] = cardId;
    }
    if (stripePaymentIntentId != null && stripePaymentIntentId.isNotEmpty) {
      body['stripePaymentIntentId'] = stripePaymentIntentId;
    }

    final res = await _net.postRequest(
      '/api/v1/ChargingSession/$sessionId/pay',
      body: body,
    );
    paySessionLoading.value = false;

    if (res.isSuccess && (res.statusCode == 200 || res.statusCode == 201)) {
      final model = pay_model.PaySessionModel.fromJson(res.responseData ?? {});
      paySessionData.value = model.data;
      return model.data;
    }

    // Show backend error message
    final errorMsg = res.responseData is Map
        ? (res.responseData['message'] ?? 'Payment failed. Please try again.')
        : 'Payment failed. Please try again.';
    Get.snackbar('Payment Error', errorMsg.toString(),
        snackPosition: SnackPosition.BOTTOM,
        backgroundColor: Colors.red.shade100,
        colorText: Colors.red.shade900,
        margin: const EdgeInsets.all(16));
    return null;
  }

  // ══════════════════════════════════════════════════════════════════════════════
  // SUBMIT REVIEW
  // ══════════════════════════════════════════════════════════════════════════════

  RxBool submitReviewLoading = false.obs;

  /// POST /api/v1/ChargingSession/{sessionId}/review
  /// body: { "rating": 5, "description": "Great charging experience!" }
  Future<bool> submitReview({
    required String sessionId,
    required int rating,
    required String description,
  }) async {
    submitReviewLoading.value = true;

    final res = await _net.postRequest(
      '/api/v1/ChargingSession/$sessionId/review',
      body: {
        'rating': rating,
        'description': description,
      },
    );
    submitReviewLoading.value = false;

    return res.isSuccess && (res.statusCode == 200 || res.statusCode == 201);
  }
}
