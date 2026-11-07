import 'package:get/get.dart';
import 'package:google_maps_flutter/google_maps_flutter.dart';

import '../../../../core/service/api_service/api_service.dart';
import '../../../Home/UI/controller/home_controller.dart';
import '../../model/nearest_station_model.dart';
import '../../model/station_details_model.dart';

/// Lightweight controller — reuses HomeController's data so map opens instantly.
class MapController extends GetxController {
  final _net = NetworkService().client;

  Rx<int?> selectedStation = Rx<int?>(null);
  GoogleMapController? googleMapController;

  HomeController get _home => Get.find<HomeController>();

  /// Search query text
  RxString searchQuery = ''.obs;

  /// Station details
  Rxn<StationData> stationDetails = Rxn<StationData>();
  RxBool detailsLoading = false.obs;

  /// All stations from HomeController
  RxList<Data> get stations => _home.stations;
  get currentPosition => _home.currentPosition;
  bool get dataReady => _home.stations.isNotEmpty || !_home.initialLoading.value;

  /// Filtered stations based on search query
  List<Data> get filteredStations {
    final q = searchQuery.value.trim().toLowerCase();
    if (q.isEmpty) return stations;
    return stations.where((s) {
      final name = (s.name ?? '').toLowerCase();
      final address = (s.address ?? '').toLowerCase();
      return name.contains(q) || address.contains(q);
    }).toList();
  }

  /// Search suggestions — returns matching stations for the dropdown
  List<Data> get searchSuggestions {
    final q = searchQuery.value.trim().toLowerCase();
    if (q.isEmpty) return [];
    return stations.where((s) {
      final name = (s.name ?? '').toLowerCase();
      final address = (s.address ?? '').toLowerCase();
      return name.contains(q) || address.contains(q);
    }).toList();
  }

  LatLng get cameraTarget {
    final pos = _home.currentPosition.value;
    if (pos != null) return LatLng(pos.latitude, pos.longitude);
    return const LatLng(23.8103, 90.4125);
  }

  void selectStation(int? index) {
    selectedStation.value = index;
  }

  /// Move camera to a specific station and select it
  void goToStation(Data station) {
    if (station.latitude == null || station.longitude == null) return;

    // Find index in original stations list
    final idx = stations.indexWhere((s) => s.sId == station.sId);
    if (idx != -1) {
      selectedStation.value = idx;
    }

    googleMapController?.animateCamera(
      CameraUpdate.newLatLngZoom(
        LatLng(station.latitude!, station.longitude!),
        16,
      ),
    );
  }

  /// Clear search
  void clearSearch() {
    searchQuery.value = '';
  }

  /// Reactive slots for date-wise updates in ViewDetailsScreen
  RxList<Slots> dateSlots = <Slots>[].obs;
  RxBool slotsLoading = false.obs;

  /// GET /api/v1/StationDetail/:stationId
  Future<StationData?> getStationDetails(String stationId, {String? date}) async {
    detailsLoading.value = true;
    stationDetails.value = null;

    // Build query params
    final query = <String, dynamic>{};
    final pos = _home.currentPosition.value;
    if (pos != null) {
      query['latitude'] = pos.latitude;
      query['longitude'] = pos.longitude;
    }
    if (date != null && date.isNotEmpty) {
      query['date'] = date;
    }

    final res = await _net.getRequest(
      '/api/v1/StationDetail/$stationId',
      query: query.isNotEmpty ? query : null,
    );

    detailsLoading.value = false;

    if (res.isSuccess && (res.statusCode == 200 || res.statusCode == 201)) {
      final model = StationDetailsModel.fromJson(res.responseData ?? {});
      stationDetails.value = model.data;
      // Also populate dateSlots from initial load
      dateSlots.value = model.data?.slots ?? [];
      return model.data;
    }
    return null;
  }

  /// GET /api/v1/Stations/{stationId}/slots?date=08 Mar, Sun
  /// Fetches fresh slot availability for a specific date
  Future<List<Slots>> fetchSlotsForDate(String stationId, String formattedDate) async {
    slotsLoading.value = true;
    dateSlots.clear();

    final res = await _net.getRequest(
      '/api/v1/Stations/$stationId/slots',
      query: {'date': formattedDate},
    );
    slotsLoading.value = false;

    if (res.isSuccess && (res.statusCode == 200 || res.statusCode == 201)) {
      final model = SlotsResponseModel.fromJson(res.responseData ?? {});
      final slots = model.data?.slots ?? [];
      dateSlots.value = slots;
      return slots;
    }
    return [];
  }
}

