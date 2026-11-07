import 'package:get/get.dart';

import 'core/service/app_settings/app_settings_service.dart';
import 'feature/Auth/UI/controller/auth_controller.dart';
import 'feature/Booking/UI/controller/all_booking_controller.dart';
import 'feature/Booking/UI/controller/booking_controller.dart';
import 'feature/Home/UI/controller/home_controller.dart';
import 'feature/map/ui/controller/map_controller.dart';
import 'feature/profile/UI/controller/profile_controller.dart';

class ControllerBinding extends Bindings {
  @override
  void dependencies() {
    Get.put<AppSettingsService>(AppSettingsService(), permanent: true);
    Get.put<AuthController>(AuthController(), permanent: true);
    Get.put<ProfileController>(ProfileController(), permanent: true);
    Get.lazyPut<HomeController>(() => HomeController(), fenix: true);
    Get.lazyPut<MapController>(() => MapController(), fenix: true);
    Get.lazyPut<BookingController>(() => BookingController(), fenix: true);
    Get.lazyPut<AllBookingController>(() => AllBookingController(), fenix: true);
  }
}

