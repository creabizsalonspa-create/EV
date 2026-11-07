import 'package:geolocator/geolocator.dart';
import 'package:get/get.dart';

import '../../../../core/service/api_service/api_service.dart';
import '../../../map/model/nearest_station_model.dart';

class HomeController extends GetxController {
  final _net = NetworkService().client;

  RxList<Data> stations = <Data>[].obs;
  RxBool loading = false.obs;

  /// True until the very first successful data load completes
  RxBool initialLoading = true.obs;

  /// Current device location (observable so the map can react)
  Rx<Position?> currentPosition = Rx<Position?>(null);

  @override
  void onInit() {
    super.onInit();
    _initLocationAndFetch();
  }

  /// 1. Request permission
  /// 2. Get current position
  /// 3. Fetch nearest stations with lat/lng
  Future<void> _initLocationAndFetch() async {
    initialLoading.value = true;
    await _determinePosition();
    await getNearestStations();
    initialLoading.value = false;
  }

  /// Determine and store the current position (with permission handling).
  Future<void> _determinePosition() async {
    bool serviceEnabled = await Geolocator.isLocationServiceEnabled();
    if (!serviceEnabled) {
      // Location services are not enabled; use fallback
      return;
    }

    LocationPermission permission = await Geolocator.checkPermission();
    if (permission == LocationPermission.denied) {
      permission = await Geolocator.requestPermission();
      if (permission == LocationPermission.denied) {
        return; // Permission denied; will use fallback lat/lng
      }
    }

    if (permission == LocationPermission.deniedForever) {
      return; // Permanently denied; will use fallback lat/lng
    }

    final position = await Geolocator.getCurrentPosition(
      locationSettings: const LocationSettings(
        accuracy: LocationAccuracy.high,
      ),
    );
    currentPosition.value = position;
  }


  /// GET /api/v1/NearestStations?latitude=...&longitude=...
  /// [silent] = true means don't show loading indicator (for background refresh)
  Future<void> getNearestStations({bool silent = false}) async {
    // Fallback to Dhaka if location not available
    final double lat = currentPosition.value?.latitude ?? 23.8103;
    final double lng = currentPosition.value?.longitude ?? 90.4125;

    if (!silent) loading.value = true;
    final res = await _net.getRequest(
      '/api/v1/NearestStations',
      query: {
        'latitude': lat,
        'longitude': lng,
      },
    );
    if (!silent) loading.value = false;
    if (res.isSuccess && (res.statusCode == 200 || res.statusCode == 201)) {
      final raw = res.responseData?['data'];
      if (raw != null && raw is List) {
        stations.value = raw
            .map((e) => Data.fromJson(e as Map<String, dynamic>))
            .toList();
      }
    }
  }
}

