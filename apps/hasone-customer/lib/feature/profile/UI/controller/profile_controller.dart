import 'dart:io';

import 'package:evcharg/core/service/imgbb/imgbb_service.dart';
import 'package:evcharg/feature/Auth/UI/screen/login_screen.dart';
import 'package:evcharg/feature/profile/model/my_vehicle_model.dart' as vehicle_model;
import 'package:evcharg/feature/profile/model/profile_model.dart';
import 'package:evcharg/feature/profile/model/vehicle_brand_model.dart' as brand_model;
import 'package:evcharg/feature/profile/model/select_model.dart' as select_model;
import 'package:evcharg/feature/profile/model/favourite_Station_model.dart' as fav_model;
import 'package:evcharg/feature/profile/model/save_location_model.dart' as save_model;
import 'package:get/get.dart';

import '../../../../core/service/api_service/api_service.dart';
import '../../../../core/service/shared_preferance/shared_prefarance.dart';
import '../../../../core/service/session/session.dart';

class ProfileController extends GetxController {
  final _net = NetworkService().client;
  final _prefs = SharedPrefs();

  Rxn<Data> profile = Rxn<Data>();
  RxBool loading = false.obs;
  RxBool uploadingAvatar = false.obs;

  // ── My Vehicles ──────────────────────────────────────────
  RxList<vehicle_model.Data> vehicles = <vehicle_model.Data>[].obs;
  RxBool vehiclesLoading = false.obs;
  RxString togglingId = ''.obs;
  RxString deletingId = ''.obs;

  // ── Vehicle Brands & Models ──────────────────────────────
  RxList<brand_model.Data> brands = <brand_model.Data>[].obs;
  RxBool brandsLoading = false.obs;

  RxList<select_model.Data> models = <select_model.Data>[].obs;
  RxBool modelsLoading = false.obs;

  // ── Vehicle Detail (confirm screen) ──────────────────────
  Rxn<Map<String, dynamic>> vehicleDetail = Rxn<Map<String, dynamic>>();
  RxBool detailLoading = false.obs;
  RxBool addingVehicle = false.obs;

  // ── Favourite Stations ───────────────────────────────────
  RxList<fav_model.Data> favouriteStations = <fav_model.Data>[].obs;
  RxBool favouriteStationsLoading = false.obs;
  RxSet<String> favouriteStationIds = <String>{}.obs;

  // ── Saved Locations ──────────────────────────────────────
  RxList<save_model.Data> savedLocations = <save_model.Data>[].obs;
  RxBool savedLocationsLoading = false.obs;
  RxSet<String> savedLocationIds = <String>{}.obs;

  @override
  void onInit() {
    super.onInit();
    getProfile();
    // Load favourite stations and saved locations for quick lookup
    getFavouriteStations();
    getSavedLocations();
  }

  // ── GET PROFILE ──────────────────────────────────────────────────────────────
  /// GET /api/v1/Profile
  /// response: { status, data: { fullName, email, phone, avatar, dateOfBirth, sessions, kwhUsed, favourites } }
  Future<void> getProfile() async {
    // Ensure token is loaded into Session before making the request
    final token = await _prefs.getToken();
    if (token == null || token.isEmpty) {
      return; // No token available, skip API call
    }

    loading.value = true;
    final res = await _net.getRequest('/api/v1/Profile');
    loading.value = false;
    if (res.isSuccess && (res.statusCode == 200 || res.statusCode == 201)) {
      final data = res.responseData?['data'];
      if (data != null && data is Map<String, dynamic>) {
        profile.value = Data.fromJson(data);
      }
    }
  }

  // ── UPDATE PROFILE ───────────────────────────────────────────────────────────
  /// PUT /api/v1/Profile
  /// body: { fullName, phone, dateOfBirth, avatar }
  /// response: { status, message }
  Future<bool> updateProfile({
    required String fullName,
    required String phone,
    required String dateOfBirth,
    String? avatar,
  }) async {
    loading.value = true;
    final res = await _net.putRequest(
      '/api/v1/UpdateProfile',
      body: {
        'fullName': fullName,
        'phone': phone,
        'dateOfBirth': dateOfBirth,
        'avatar': avatar ?? '',
      },
    );
    loading.value = false;
    if (res.isSuccess && (res.statusCode == 200 || res.statusCode == 201)) {
      Get.snackbar(
        'Success',
        res.responseData?['message']?.toString() ?? 'Profile updated successfully.',
        snackPosition: SnackPosition.TOP,
      );
      // Refresh profile data
      await getProfile();
      return true;
    } else {
      Get.snackbar('Error', res.errorMessage ?? 'Profile update failed',
          snackPosition: SnackPosition.TOP);
      return false;
    }
  }

  // ── UPLOAD AVATAR (ImgBB) ──────────────────────────────────────────────────
  /// Picks an image, uploads to ImgBB, then updates profile with the URL
  Future<String?> uploadAvatar(File imageFile) async {
    uploadingAvatar.value = true;
    final url = await ImgBBService.uploadImage(imageFile);
    uploadingAvatar.value = false;
    if (url != null && url.isNotEmpty) {
      return url;
    } else {
      Get.snackbar('Error', 'Failed to upload image',
          snackPosition: SnackPosition.TOP);
      return null;
    }
  }

  // ── MY VEHICLES ──────────────────────────────────────────────────────────────
  /// GET /api/v1/MyVehicles
  /// response: { status, message, data: [ { _id, userId, name, model, image, connectorType, batteryCapacityKwh, isActive } ] }
  Future<void> getVehicles() async {
    final token = await _prefs.getToken();
    if (token == null || token.isEmpty) return;

    vehiclesLoading.value = true;
    final res = await _net.getRequest('/api/v1/MyVehicles');
    vehiclesLoading.value = false;

    if (res.isSuccess && (res.statusCode == 200 || res.statusCode == 201)) {
      final model = vehicle_model.MyVehicleModel.fromJson(res.responseData ?? {});
      vehicles.value = model.data ?? [];
    }
  }

  /// PUT /api/v1/MyVehicles/{id}/toggle  — Toggle Active / Disable
  /// Activating a vehicle auto-disables all others.
  Future<void> toggleVehicle(String vehicleId) async {
    if (togglingId.value.isNotEmpty) return;
    togglingId.value = vehicleId;
    final res = await _net.putRequest('/api/v1/MyVehicles/$vehicleId/toggle');
    togglingId.value = '';

    if (res.isSuccess && (res.statusCode == 200 || res.statusCode == 201)) {
      await getVehicles(); // refresh list — toggling one disables others
    } else {
      Get.snackbar('Error', res.errorMessage ?? 'Failed to toggle vehicle',
          snackPosition: SnackPosition.TOP);
    }
  }

  /// DELETE /api/v1/MyVehicles/{id}
  /// response: { status, message: "Vehicle deleted successfully." }
  Future<void> deleteVehicle(String vehicleId) async {
    if (deletingId.value.isNotEmpty) return;
    deletingId.value = vehicleId;
    final res = await _net.deleteRequest('/api/v1/MyVehicles/$vehicleId');
    deletingId.value = '';

    if (res.isSuccess && (res.statusCode == 200 || res.statusCode == 201)) {
      vehicles.removeWhere((v) => v.sId == vehicleId);
      Get.snackbar('Deleted', 'Vehicle deleted successfully.',
          snackPosition: SnackPosition.TOP);
    } else {
      Get.snackbar('Error', res.errorMessage ?? 'Failed to delete vehicle',
          snackPosition: SnackPosition.TOP);
    }
  }

  // ── VEHICLE BRANDS ────────────────────────────────────────────────────────────
  /// GET /api/v1/VehicleBrands
  Future<void> getBrands() async {
    brandsLoading.value = true;
    final res = await _net.getRequest('/api/v1/VehicleBrands');
    brandsLoading.value = false;

    if (res.isSuccess && (res.statusCode == 200 || res.statusCode == 201)) {
      final m = brand_model.VehicalBrandModel.fromJson(res.responseData ?? {});
      brands.value = m.data ?? [];
    }
  }

  // ── VEHICLE MODELS ───────────────────────────────────────────────────────────
  /// GET /api/v1/VehicleModels/{brandId}
  Future<void> getModels(String brandId) async {
    models.clear();
    modelsLoading.value = true;
    final res = await _net.getRequest('/api/v1/VehicleModels/$brandId');
    modelsLoading.value = false;

    if (res.isSuccess && (res.statusCode == 200 || res.statusCode == 201)) {
      final m = select_model.SelectMOdel.fromJson(res.responseData ?? {});
      models.value = m.data ?? [];
    }
  }

  // ── VEHICLE MODEL DETAIL ─────────────────────────────────────────────────────
  /// GET /api/v1/VehicleModelDetail/{modelId}
  /// response: { status, data: { _id, modelName, image, brandName, brandImage } }
  Future<void> getVehicleDetail(String modelId) async {
    vehicleDetail.value = null;
    detailLoading.value = true;
    final res = await _net.getRequest('/api/v1/VehicleModelDetail/$modelId');
    detailLoading.value = false;

    if (res.isSuccess && (res.statusCode == 200 || res.statusCode == 201)) {
      final data = res.responseData?['data'];
      if (data != null && data is Map<String, dynamic>) {
        vehicleDetail.value = data;
      }
    }
  }

  // ── ADD VEHICLE ──────────────────────────────────────────────────────────────
  /// POST /api/v1/MyVehicles
  /// body: { modelId, connectorType, batteryCapacityKwh }
  Future<bool> addVehicle({
    required String modelId,
    required String connectorType,
    required int batteryCapacityKwh,
  }) async {
    addingVehicle.value = true;
    final res = await _net.postRequest(
      '/api/v1/MyVehicles',
      body: {
        'modelId': modelId,
        'connectorType': connectorType,
        'batteryCapacityKwh': batteryCapacityKwh,
      },
    );
    addingVehicle.value = false;

  if (res.isSuccess && (res.statusCode == 200 || res.statusCode == 201)) {
    await getVehicles(); // Refresh list so new vehicle appears immediately
    return true;
  } else {
    Get.snackbar('Error', res.errorMessage ?? 'Failed to add vehicle',
        snackPosition: SnackPosition.TOP);
    return false;
  }
  }

  // ── FAVOURITE STATIONS ───────────────────────────────────────────────────────
  /// GET /api/v1/FavouriteStations
  /// response: { status, data: [ { _id, userId, stationName, address, image, pricePerHour, status, latitude, longitude } ] }
  Future<void> getFavouriteStations() async {
    final token = await _prefs.getToken();
    if (token == null || token.isEmpty) return;

    favouriteStationsLoading.value = true;
    final res = await _net.getRequest('/api/v1/FavouriteStations');
    favouriteStationsLoading.value = false;

    if (res.isSuccess && (res.statusCode == 200 || res.statusCode == 201)) {
      final model = fav_model.FavouriteStationModel.fromJson(res.responseData ?? {});
      favouriteStations.value = model.data ?? [];
      // Update the set of favourite station IDs for quick lookup
      favouriteStationIds.clear();
      favouriteStationIds.addAll(favouriteStations.map((s) => s.stationId ?? s.sId ?? '').where((id) => id.isNotEmpty));
    }
  }

  /// POST /api/v1/FavouriteStations/toggle
  /// body: { stationId, stationName, address, image, pricePerHour, status, latitude, longitude }
  Future<bool> toggleFavouriteStationApi({
    required String stationId,
    required String stationName,
    required String address,
    String? image,
    String? pricePerHour,
    String? status,
    double? latitude,
    double? longitude,
  }) async {
    final bool wasAlreadyFavourite = favouriteStationIds.contains(stationId);

    // Optimistic instant update
    if (wasAlreadyFavourite) {
      favouriteStationIds.remove(stationId);
      favouriteStations.removeWhere((s) => (s.stationId ?? s.sId) == stationId);
    } else {
      favouriteStationIds.add(stationId);
    }

    final res = await _net.postRequest(
      '/api/v1/FavouriteStations/toggle',
      body: {
        'stationId': stationId,
        'stationName': stationName,
        'address': address,
        'image': image ?? '',
        'pricePerHour': pricePerHour ?? '',
        'status': status ?? 'Available',
        'latitude': latitude ?? 0.0,
        'longitude': longitude ?? 0.0,
      },
    );

    if (res.isSuccess && (res.statusCode == 200 || res.statusCode == 201)) {
      if (!wasAlreadyFavourite) {
        // Refresh to get full data including _id
        await getFavouriteStations();
        Get.snackbar('Success', 'Added to favourites', snackPosition: SnackPosition.TOP);
      } else {
        Get.snackbar('Removed', 'Removed from favourites', snackPosition: SnackPosition.TOP);
      }
      return true;
    } else {
      // Revert optimistic update on failure
      if (wasAlreadyFavourite) {
        favouriteStationIds.add(stationId);
        await getFavouriteStations();
      } else {
        favouriteStationIds.remove(stationId);
      }
      Get.snackbar('Error', res.errorMessage ?? 'Failed to toggle favourite',
          snackPosition: SnackPosition.TOP);
      return false;
    }
  }

  /// Simple toggle for favourite station (used from ViewDetailsScreen)
  Future<void> toggleFavouriteStation(String stationId) async {
    // This is a simple toggle - we need station details
    // For now, just toggle the ID in the set
    if (favouriteStationIds.contains(stationId)) {
      await removeFavouriteStation(stationId);
    } else {
      await addFavouriteStation(stationId);
    }
  }

  /// POST /api/v1/FavouriteStations (add)
  /// body: { stationId }
  Future<bool> addFavouriteStation(String stationId) async {
    final res = await _net.postRequest(
      '/api/v1/FavouriteStations/toggle',
      body: {'stationId': stationId},
    );

    if (res.isSuccess && (res.statusCode == 200 || res.statusCode == 201)) {
      favouriteStationIds.add(stationId);
      Get.snackbar('Success', 'Added to favourites',
          snackPosition: SnackPosition.TOP);
      return true;
    } else {
      Get.snackbar('Error', res.errorMessage ?? 'Failed to add to favourites',
          snackPosition: SnackPosition.TOP);
      return false;
    }
  }

  /// DELETE /api/v1/FavouriteStations/{stationId}
  Future<bool> removeFavouriteStation(String stationId) async {
    final res = await _net.postRequest(
      '/api/v1/FavouriteStations/toggle',
      body: {'stationId': stationId},
    );

    if (res.isSuccess && (res.statusCode == 200 || res.statusCode == 201)) {
      favouriteStationIds.remove(stationId);
      favouriteStations.removeWhere((s) => s.sId == stationId);
      Get.snackbar('Removed', 'Removed from favourites',
          snackPosition: SnackPosition.TOP);
      return true;
    } else {
      Get.snackbar('Error', res.errorMessage ?? 'Failed to remove from favourites',
          snackPosition: SnackPosition.TOP);
      return false;
    }
  }

  // ── SAVED LOCATIONS ──────────────────────────────────────────────────────────
  /// GET /api/v1/SavedLocations
  /// response: { status, data: [ { _id, userId, stationName, address, image, status, latitude, longitude } ] }
  Future<void> getSavedLocations() async {
    final token = await _prefs.getToken();
    if (token == null || token.isEmpty) return;

    savedLocationsLoading.value = true;
    final res = await _net.getRequest('/api/v1/SavedLocations');
    savedLocationsLoading.value = false;

    if (res.isSuccess && (res.statusCode == 200 || res.statusCode == 201)) {
      final model = save_model.SavelocationModel.fromJson(res.responseData ?? {});
      savedLocations.value = model.data ?? [];
      // Update the set of saved location IDs for quick lookup
      savedLocationIds.clear();
      savedLocationIds.addAll(savedLocations.map((s) => s.stationId ?? s.sId ?? '').where((id) => id.isNotEmpty));
    }
  }

  /// POST /api/v1/SavedLocations
  /// body: { stationId }
  /// POST /api/v1/SavedLocations/toggle
  /// body: { stationId, stationName, address, image, status, latitude, longitude }
  Future<bool> toggleSavedLocationApi({
    required String stationId,
    required String stationName,
    required String address,
    String? image,
    String? status,
    double? latitude,
    double? longitude,
  }) async {
    final bool wasAlreadySaved = savedLocationIds.contains(stationId);

    // Optimistic instant update
    if (wasAlreadySaved) {
      savedLocationIds.remove(stationId);
      savedLocations.removeWhere((s) => (s.stationId ?? s.sId) == stationId);
    } else {
      savedLocationIds.add(stationId);
    }

    final res = await _net.postRequest(
      '/api/v1/SavedLocations/toggle',
      body: {
        'stationId': stationId,
        'stationName': stationName,
        'address': address,
        'image': image ?? '',
        'status': status ?? 'Available',
        'latitude': latitude ?? 0.0,
        'longitude': longitude ?? 0.0,
      },
    );

    if (res.isSuccess && (res.statusCode == 200 || res.statusCode == 201)) {
      if (!wasAlreadySaved) {
        // Refresh to get full data including _id
        await getSavedLocations();
        Get.snackbar('Success', 'Location saved', snackPosition: SnackPosition.TOP);
      } else {
        Get.snackbar('Removed', 'Location removed', snackPosition: SnackPosition.TOP);
      }
      return true;
    } else {
      // Revert optimistic update on failure
      if (wasAlreadySaved) {
        savedLocationIds.add(stationId);
        await getSavedLocations();
      } else {
        savedLocationIds.remove(stationId);
      }
      Get.snackbar('Error', res.errorMessage ?? 'Failed to toggle saved location',
          snackPosition: SnackPosition.TOP);
      return false;
    }
  }

  /// Simple toggle for saved location (used from ViewDetailsScreen)
  Future<void> toggleSavedLocation(String stationId) async {
    if (savedLocationIds.contains(stationId)) {
      await removeSavedLocation(stationId);
    } else {
      await addSavedLocation(stationId);
    }
  }

  Future<bool> addSavedLocation(String stationId) async {
    final res = await _net.postRequest(
      '/api/v1/SavedLocations/toggle',
      body: {'stationId': stationId},
    );

    if (res.isSuccess && (res.statusCode == 200 || res.statusCode == 201)) {
      savedLocationIds.add(stationId);
      Get.snackbar('Success', 'Location saved',
          snackPosition: SnackPosition.TOP);
      return true;
    } else {
      Get.snackbar('Error', res.errorMessage ?? 'Failed to save location',
          snackPosition: SnackPosition.TOP);
      return false;
    }
  }

  Future<bool> removeSavedLocation(String stationId) async {
    final res = await _net.postRequest(
      '/api/v1/SavedLocations/toggle',
      body: {'stationId': stationId},
    );

    if (res.isSuccess && (res.statusCode == 200 || res.statusCode == 201)) {
      savedLocationIds.remove(stationId);
      savedLocations.removeWhere((s) => s.sId == stationId);
      Get.snackbar('Removed', 'Location removed',
          snackPosition: SnackPosition.TOP);
      return true;
    } else {
      Get.snackbar('Error', res.errorMessage ?? 'Failed to remove location',
          snackPosition: SnackPosition.TOP);
      return false;
    }
  }

  // ── LOGOUT ───────────────────────────────────────────────────────────────────
  /// GET /api/v1/UserLogout
  /// response: { status: "success" }
  Future<void> logout() async {
    // Snapshot the original session BEFORE the logout HTTP request. Its
    // response may arrive after another user has already signed in.
    final generation = SharedPrefs.credentialGeneration;
    final token = Session.accessToken;
    loading.value = true;
    try {
      await _net.getRequest('/api/v1/UserLogout');
      // Local logout still applies if the server does not respond successfully,
      // but never clears credentials for a newer signed-in account.
      final cleared = await _prefs.clearIfCurrent(
        expectedGeneration: generation,
        expectedToken: token,
      );
      if (!cleared) return;
      profile.value = null;
      Get.offAll(() => const LoginScreen());
    } finally {
      loading.value = false;
    }
  }
}