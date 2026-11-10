# ⚡ EVCharg — EV Charging Station Finder & Booking App

A full-featured **Flutter** mobile application for locating, booking, and managing electric vehicle (EV) charging sessions. Built with **GetX** for state management, **Dio** for networking, and **Google Maps** for real-time station discovery.

---

## 📑 Table of Contents

- [Features](#-features)
- [Screenshots & Assets](#-screenshots--assets)
- [Tech Stack](#-tech-stack)
- [Architecture](#-architecture)
- [Project Structure](#-project-structure)
- [Getting Started](#-getting-started)
  - [Prerequisites](#prerequisites)
  - [Installation](#installation)
  - [Environment Configuration](#environment-configuration)
- [Core Services](#-core-services)
- [Feature Modules](#-feature-modules)
- [API Reference](#-api-reference)
- [Payment Integration](#-payment-integration)
- [State Management](#-state-management)
- [Utilities](#-utilities)
- [Assets](#-assets)
- [Testing](#-testing)
- [Contributing](#-contributing)
- [License](#-license)

---

## ✨ Features

| Category | Features |
|---|---|
| **Authentication** | Email/password registration, OTP verification, login, forgot password, Google Sign-In |
| **Home** | Dashboard with nearby stations overview |
| **Map** | Google Maps integration with custom markers (active/inactive stations), real-time location, polyline routes |
| **Booking** | Book charging slots, view booking details, navigate to station, QR code scanning, confirm arrival |
| **Charging Session** | Start/stop/extend charging sessions, live session monitoring |
| **Payments** | Stripe payment integration, SSLCommerz payment gateway, multiple payment methods |
| **Profile** | Edit profile, upload profile picture (ImgBB), manage vehicles, favourite stations, saved locations |
| **Vehicles** | Add vehicles by brand/model, view vehicle details |
| **Notifications** | View and manage push notifications |
| **Other** | Privacy policy, help center/FAQ, dark/light theme support |

---

## 🖼 Screenshots & Assets

The app includes the following screen assets in `assets/images/`:

| Asset | Description |
|---|---|
| `Splash.png` | Splash/loading screen |
| `Onboarding.png` | Onboarding walkthrough |
| `login.png` | Login screen background |
| `carScreen.png` / `car.png` / `modern_car.png` | Vehicle illustrations |
| `charging.png` / `charging_station.png` / `station_charging.png` | Charging visuals |
| `booking_details.png` | Booking details illustration |
| `success.png` / `success_payment.png` | Success confirmation screens |
| `stopCharging.png` | Charging stopped illustration |
| `viewdetails.png` | Station detail view |
| `saveLocation.png` | Saved locations illustration |
| `My_Vehicle.png` | My vehicle section illustration |
| `activeMapIcon.svg` / `inactiveMapIcon.svg` | Custom map markers for available/unavailable stations |
| `google.svg` | Google Sign-In button icon |
| SVG icons: `home`, `maps`, `booking`, `charge`, `profile` | Bottom navigation bar icons |

---

## 🛠 Tech Stack

| Technology | Purpose |
|---|---|
| [Flutter](https://flutter.dev/) `SDK ^3.9.2` | Cross-platform UI framework |
| [Dart](https://dart.dev/) | Programming language |
| [GetX](https://pub.dev/packages/get) `^4.7.3` | State management, dependency injection, routing |
| [Dio](https://pub.dev/packages/dio) `^5.9.1` | HTTP client for REST API calls |
| [Google Maps Flutter](https://pub.dev/packages/google_maps_flutter) `^2.14.2` | Interactive maps |
| [Geolocator](https://pub.dev/packages/geolocator) `^14.0.2` | Device location services |
| [Flutter Polyline Points](https://pub.dev/packages/flutter_polyline_points) `^2.1.0` | Route polylines on map |
| [Flutter Stripe](https://pub.dev/packages/flutter_stripe) `^12.3.0` | Stripe payment processing |
| [Flutter SSLCommerz](https://pub.dev/packages/flutter_sslcommerz) `^3.0.1` | SSLCommerz payment gateway |
| [Google Sign-In](https://pub.dev/packages/google_sign_in) `^7.2.0` | Google OAuth authentication |
| [Flutter ScreenUtil](https://pub.dev/packages/flutter_screenutil) `^5.9.3` | Responsive UI scaling |
| [Flutter SVG](https://pub.dev/packages/flutter_svg) `^2.2.3` | SVG image rendering |
| [Mobile Scanner](https://pub.dev/packages/mobile_scanner) `^7.2.0` | QR/barcode scanning |
| [Image Picker](https://pub.dev/packages/image_picker) `^1.2.1` | Camera/gallery image selection |
| [Shared Preferences](https://pub.dev/packages/shared_preferences) `^2.5.4` | Local key-value storage |
| [URL Launcher](https://pub.dev/packages/url_launcher) `^6.2.5` | Opening external URLs |
| [Flutter InAppWebView](https://pub.dev/packages/flutter_inappwebview) `^6.1.5` | In-app browser for SSLCommerz |
| [Logger](https://pub.dev/packages/logger) `^2.6.2` | Structured logging |

---

## 🏗 Architecture

The project follows a **feature-first** architecture with a clear separation of concerns:

```
┌─────────────────────────────────────────────┐
│                    App Layer                │
│  (Colors, Themes, Widgets, Assets, Utils)   │
├─────────────────────────────────────────────┤
│                Feature Layer                │
│  ┌──────────┐  ┌──────────┐  ┌──────────┐  │
│  │   Auth   │  │ Booking  │  │   Home   │  │
│  │ ┌──────┐ │  │ ┌──────┐ │  │ ┌──────┐ │  │
│  │ │Model │ │  │ │Model │ │  │ │  UI  │ │  │
│  │ ├──────┤ │  │ ├──────┤ │  │ │Ctrl. │ │  │
│  │ │  UI  │ │  │ │  UI  │ │  │ │Screen│ │  │
│  │ │Ctrl. │ │  │ │Ctrl. │ │  │ └──────┘ │  │
│  │ │Screen│ │  │ │Screen│ │  └──────────┘  │
│  │ └──────┘ │  │ └──────┘ │                │
│  └──────────┘  └──────────┘  + Map, Profile│
│                Profile, Notifications, etc. │
├─────────────────────────────────────────────┤
│                 Core Layer                  │
│  ┌───────────────────────────────────────┐  │
│  │             Services                  │  │
│  │  NetworkClient │ ApiService │ Session │  │
│  │  SharedPrefs │ PaymentService │ ImgBB │  │
│  ├───────────────────────────────────────┤  │
│  │             Utilities                 │  │
│  │  DistanceHelper │ MapMarkerHelper     │  │
│  └───────────────────────────────────────┘  │
└─────────────────────────────────────────────┘
```

**Pattern per feature module:**
- **`model/`** — Data models (JSON serialization/deserialization)
- **`UI/controller/`** — GetX controllers (business logic, API calls)
- **`UI/screen/`** — Flutter widget screens (presentation)

---

## 📂 Project Structure

```
lib/
├── main.dart                          # App entry point, Stripe & Maps init
├── controller_binder_screen.dart      # Global GetX dependency injection
│
├── app/                               # Shared app-level utilities
│   ├── assets.dart                    # Asset path constants
│   ├── color.dart                     # KColors — app color palette
│   ├── resource.dart                  # R class — static access to colors
│   ├── theme_controller.dart          # Theme management
│   ├── fild_title.dart                # Reusable field title widget
│   ├── loading.dart                   # Loading indicator widget
│   ├── widget_button.dart             # Reusable button widget
│   ├── winput_text.dart               # Reusable text input widget
│   ├── wtext.dart                     # Reusable text widget
│   └── utils.dart                     # General utilities
│
├── core/                              # Core infrastructure
│   ├── service/
│   │   ├── api_service/
│   │   │   └── api_service.dart       # NetworkService — base API config
│   │   ├── network/
│   │   │   ├── network_client.dart    # Dio-based HTTP client wrapper
│   │   │   └── network_responce.dart  # NetworkResponse model (part file)
│   │   ├── session/
│   │   │   └── session.dart           # In-memory auth token cache
│   │   ├── shared_preferance/
│   │   │   └── shared_prefarance.dart # SharedPreferences wrapper
│   │   ├── payment/
│   │   │   └── payment_service.dart   # Stripe & SSLCommerz integration
│   │   └── imgbb/
│   │       └── imgbb_service.dart     # ImgBB image upload service
│   └── utils/
│       ├── distance_helper.dart       # Haversine distance & ETA calculator
│       └── map_marker_helper.dart     # Custom SVG map marker generator
│
├── feature/                           # Feature modules
│   ├── main_screen.dart               # Main scaffold with bottom nav
│   ├── bottom_sheet_Navigation.dart   # Custom bottom navigation bar
│   │
│   ├── Auth/                          # Authentication
│   │   ├── model/
│   │   │   └── user_model.dart
│   │   └── UI/
│   │       ├── controller/
│   │       │   └── auth_controller.dart
│   │       └── screen/
│   │           ├── splash_screen.dart
│   │           ├── onboarding_screen.dart
│   │           ├── login_screen.dart
│   │           ├── sign_in_screen.dart
│   │           ├── verify_otp_screen.dart
│   │           ├── forgotpassword_screen.dart
│   │           ├── forgot_otp_verify_screen.dart
│   │           └── create_new_password_screen.dart
│   │
│   ├── Home/                          # Home dashboard
│   │   └── UI/
│   │       ├── controller/
│   │       │   └── home_controller.dart
│   │       └── screen/
│   │           └── home_screen.dart
│   │
│   ├── map/                           # Map & station discovery
│   │   ├── model/
│   │   │   ├── nearest_station_model.dart
│   │   │   └── station_details_model.dart
│   │   └── ui/
│   │       ├── controller/
│   │       │   └── map_controller.dart
│   │       └── screen/
│   │           └── map_screen.dart
│   │
│   ├── Booking/                       # Booking & charging flow
│   │   ├── model/
│   │   │   ├── All_booking_model.dart
│   │   │   ├── bookingdetailsMOdel.dart
│   │   │   ├── bookingpaymentmodel.dart
│   │   │   ├── book_navigation_Model.dart
│   │   │   ├── charging_session_model.dart
│   │   │   ├── charging_starting_model.dart
│   │   │   ├── charging_station_model.dart
│   │   │   ├── confirm_arrive_navigation_model.dart
│   │   │   ├── extend_session_model.dart
│   │   │   ├── get_payment_method_model.dart
│   │   │   ├── latest_car_update_location_model.dart
│   │   │   ├── payment_model.dart
│   │   │   ├── pay_session_model.dart
│   │   │   ├── stop_charging_model.dart
│   │   │   └── update_location_duration_model.dart
│   │   └── UI/
│   │       ├── controller/
│   │       │   ├── booking_controller.dart
│   │       │   └── all_booking_controller.dart
│   │       └── screen/
│   │           ├── booking_screen.dart
│   │           ├── booking_details.dart
│   │           ├── booking_confirm_screen.dart
│   │           ├── Charging_Station_screen.dart
│   │           ├── charging_screen.dart
│   │           ├── Charging_Stopped_screen.dart
│   │           ├── navigate_station_screen.dart
│   │           ├── payment_method_screen.dart
│   │           ├── rebooking_screen.dart
│   │           ├── scanning_screen.dart
│   │           └── sslcommerz_webview_screen.dart
│   │
│   ├── profile/                       # User profile management
│   │   ├── model/
│   │   │   ├── profile_model.dart
│   │   │   ├── my_vehicle_model.dart
│   │   │   ├── vehicle_brand_model.dart
│   │   │   ├── vehicle_details_model.dart
│   │   │   ├── favourite_Station_model.dart
│   │   │   ├── save_location_model.dart
│   │   │   └── select_model.dart
│   │   └── UI/
│   │       ├── controller/
│   │       │   └── profile_controller.dart
│   │       └── screen/
│   │           ├── profile_screen.dart
│   │           ├── edit_profile_screen.dart
│   │           ├── My_Vehicle_screen.dart
│   │           ├── add_vehicle_confirm_screen.dart
│   │           ├── select_brand_screen.dart
│   │           ├── select_model_screen.dart
│   │           ├── Favourite_Station_screen.dart
│   │           └── Saved_Locations_screen.dart
│   │
│   ├── Notification/                  # Notifications
│   │   ├── model/
│   │   │   ├── notification_model.dart
│   │   │   └── show_notification_model.dart
│   │   └── UI/
│   │       ├── controller/
│   │       └── screen/
│   │           ├── Notification_screen.dart
│   │           └── show_Notification_screen.dart
│   │
│   ├── viewdetails/                   # Station detail view
│   │   └── UI/screen/
│   │       └── view_details_screen.dart
│   │
│   ├── vehicel model/                 # Vehicle model selection
│   │   └── UI/screen/
│   │       └── vehicel_model_screen.dart
│   │
│   └── Privacy Policy/                # Legal & help
│       └── UI/screen/
│           ├── Privacy_Policy_screen.dart
│           └── Help_Center_FAQ_screen.dart
```

---

## 🚀 Getting Started

### Prerequisites

- **Flutter SDK** `>=3.9.2`
- **Dart SDK** `>=3.9.2`
- **Android Studio** or **VS Code** with Flutter/Dart plugins
- **Google Maps API Key** (for Android/iOS)
- **Stripe Publishable Key** (for payments)
- Physical device or emulator with Google Play Services (for Maps)

### Installation

```bash
# 1. Clone the repository
git clone <repository-url>
cd evcharg

# 2. Install dependencies
flutter pub get

# 3. Run the app
flutter run
```

### Environment Configuration

#### Google Maps API Key

**Android:** Add your API key in `android/app/src/main/AndroidManifest.xml`:
```xml
<meta-data
    android:name="com.google.android.geo.API_KEY"
    android:value="YOUR_GOOGLE_MAPS_API_KEY"/>
```

**iOS:** Add your API key in `ios/Runner/AppDelegate.swift`:
```swift
GMSServices.provideAPIKey("YOUR_GOOGLE_MAPS_API_KEY")
```

#### Backend API

The app connects to a backend hosted at:
```
https://uv-charging-backend-1.onrender.com
```

To change the backend URL, edit `lib/core/service/api_service/api_service.dart`:
```dart
String get _apiBaseUrl => "https://your-backend-url.com";
```

#### Stripe

Stripe is initialized in `main.dart` via `PaymentService.initStripe()`. The publishable key is configured in `lib/core/service/payment/payment_service.dart`.

#### SSLCommerz

SSLCommerz credentials are configured in `lib/core/service/payment/payment_service.dart` with store ID and password.

---

## ⚙ Core Services

### NetworkClient (`core/service/network/network_client.dart`)

A Dio-based HTTP client wrapper providing:
- **Automatic header injection** (Authorization Bearer token, Content-Type)
- **Request/response logging** via `Logger`
- **Timeout configuration** (connect: 15s, receive: 20s, send: 20s)
- **Error handling** with structured `NetworkResponse` objects
- **Unauthorized (401) interception** → auto-logout and redirect to login
- Methods: `getRequest()`, `postRequest()`, `putRequest()`, `deleteRequest()`, `patchRequest()`

### NetworkService (`core/service/api_service/api_service.dart`)

High-level API service that:
- Configures `NetworkClient` with the base URL
- Builds common headers (Accept, Content-Type, Authorization)
- Handles 401 unauthorized responses (clears session, navigates to login)

### Session (`core/service/session/session.dart`)

Simple in-memory token cache:
- `Session.accessToken` — stores the current JWT token
- `Session.setToken(token)` — updates the cached token
- `Session.clear()` — clears the token on logout

### SharedPrefs (`core/service/shared_preferance/shared_prefarance.dart`)

Persistent storage wrapper using `SharedPreferences`:
- `saveToken(token)` / `getToken()` — JWT token persistence
- `saveUser(map)` / `getUser()` — User data persistence
- `clear()` — Wipe all stored data on logout
- Automatically syncs with `Session` cache

### PaymentService (`core/service/payment/payment_service.dart`)

Dual payment gateway integration:
- **Stripe** — `initStripe()`, `confirmStripePayment(clientSecret)`
- **SSLCommerz** — `startSSLCommerzPayment(amount, transactionId, ...)`

### ImgBBService (`core/service/imgbb/imgbb_service.dart`)

Image hosting service:
- `uploadImage(File)` — Upload image file, returns public URL
- `uploadBase64(String)` — Upload base64-encoded image

---

## 📦 Feature Modules

### 🔐 Auth Module

**Screens:** Splash → Onboarding → Login → Sign Up → OTP Verification → Forgot Password → Create New Password

| Screen | Description |
|---|---|
| `SplashScreen` | App launch screen, checks existing session |
| `OnboardingScreen` | First-time user walkthrough |
| `LoginScreen` | Email/password login + Google Sign-In |
| `SignInScreen` | New user registration (name, email, phone, password) |
| `VerifyOtpScreen` | 6-digit OTP verification after registration |
| `ForgotPasswordScreen` | Enter email to receive password reset OTP |
| `ForgotOtpVerifyScreen` | Verify OTP for password reset |
| `CreateNewPasswordScreen` | Set new password after OTP verification |

**API Endpoints:**
- `POST /api/v1/Register` — Register with fullName, phone, email, password
- `POST /api/v1/VerifyOTP` — Verify OTP with email and otp
- `POST /api/v1/Login` — Login with email and password
- `POST /api/v1/ForgotPassword` — Request password reset
- Google Sign-In via `google_sign_in` package

### 🏠 Home Module

| Screen | Description |
|---|---|
| `HomeScreen` | Main dashboard showing nearby stations and quick actions |

### 🗺 Map Module

| Screen | Description |
|---|---|
| `MapScreen` | Interactive Google Map with station markers (active/inactive) |

**Models:**
- `NearestStationModel` — Nearby charging station data
- `StationDetailsModel` — Detailed station information

**Features:**
- Custom SVG markers (green for available, red for unavailable)
- User location tracking via `Geolocator`
- Polyline route drawing with `flutter_polyline_points`
- Distance & ETA calculation using Haversine formula

### 📅 Booking Module

Complete charging session lifecycle:

| Screen | Description |
|---|---|
| `BookingScreen` | List of all bookings (current & past) |
| `BookingDetailsScreen` | Detailed view of a specific booking |
| `BookingConfirmScreen` | Confirm booking before payment |
| `PaymentMethodScreen` | Choose payment method (Stripe/SSLCommerz) |
| `NavigateStationScreen` | Turn-by-turn navigation to station |
| `ScanningScreen` | QR code scanner to verify charger |
| `ChargingStationScreen` | Station info before starting charge |
| `ChargingScreen` | Live charging session monitor |
| `ChargingStoppedScreen` | Session summary after stopping |
| `RebookingScreen` | Rebook a previous session |
| `SSLCommerzWebviewScreen` | In-app browser for SSLCommerz payment |

**Models (15 models):** Cover booking creation, payment processing, charging session lifecycle, navigation, and session extension.

### 👤 Profile Module

| Screen | Description |
|---|---|
| `ProfileScreen` | User profile overview with menu items |
| `EditProfileScreen` | Edit name, phone, addresses, profile picture |
| `MyVehicleScreen` | List of registered vehicles |
| `AddVehicleConfirmScreen` | Confirm vehicle addition |
| `SelectBrandScreen` | Browse vehicle brands |
| `SelectModelScreen` | Browse vehicle models per brand |
| `FavouriteStationScreen` | Favourite/saved charging stations |
| `SavedLocationsScreen` | Saved home/work/custom locations |

**Models:** Profile, vehicles (brand/model/details), favourite stations, saved locations.

### 🔔 Notification Module

| Screen | Description |
|---|---|
| `NotificationScreen` | List of notifications |
| `ShowNotificationScreen` | Detailed notification view |

### 📋 View Details Module

| Screen | Description |
|---|---|
| `ViewDetailsScreen` | Detailed charging station information |

### 🚗 Vehicle Model Module

| Screen | Description |
|---|---|
| `VehicelModelScreen` | Vehicle model selection screen |

### 📄 Privacy Policy Module

| Screen | Description |
|---|---|
| `PrivacyPolicyScreen` | Privacy policy content |
| `HelpCenterFAQScreen` | Help center with frequently asked questions |

---

## 🌐 API Reference

The app communicates with a REST API backend. Base URL:

```
https://uv-charging-backend-1.onrender.com
```

### Authentication Endpoints

| Method | Endpoint | Description |
|---|---|---|
| `POST` | `/api/v1/Register` | Register new user |
| `POST` | `/api/v1/VerifyOTP` | Verify email OTP |
| `POST` | `/api/v1/Login` | User login |
| `POST` | `/api/v1/ForgotPassword` | Request password reset |

All authenticated requests include `Authorization: Bearer <token>` header.

---

## 💳 Payment Integration

### Stripe
- Initialized at app startup via `PaymentService.initStripe()`
- Payment flow: Backend creates PaymentIntent → Client receives `clientSecret` → Stripe payment sheet presented → Payment confirmed
- Uses `flutter_stripe` package for native payment sheet UI

### SSLCommerz
- Alternative payment gateway (popular in South Asia)
- Uses `flutter_sslcommerz` with in-app WebView
- Supports sandbox/test mode

---

## 🔄 State Management

The app uses **GetX** for state management and dependency injection.

### Controller Bindings (`controller_binder_screen.dart`)

```dart
// Permanent controllers (always in memory)
AuthController     — Authentication state & API calls
ProfileController  — Profile data & operations

// Lazy-loaded controllers (created on first use, recreated after disposal)
HomeController     — Home screen data
MapController      — Map state, markers, location
BookingController  — Booking operations
AllBookingController — Booking list management
```

### Reactive State

Controllers use GetX reactive variables (`Rx`, `RxBool`, `Rxn`, `RxList`) for automatic UI updates:
```dart
Rxn<UserModel> user = Rxn<UserModel>();   // nullable reactive
RxBool loading = false.obs;                // boolean reactive
```

---

## 🧰 Utilities

### DistanceHelper (`core/utils/distance_helper.dart`)
- Calculates distance between user and station using **Haversine formula**
- Estimates driving duration (assumes 30 km/h average city speed)
- Provides formatted distance strings (e.g., "1.2 km away")

### MapMarkerHelper (`core/utils/map_marker_helper.dart`)
- Converts SVG assets to `BitmapDescriptor` for Google Maps markers
- Caches icons to avoid repeated rendering
- Provides green (available) and red (unavailable) station markers

---

## 🎨 Assets

All assets are located in `assets/images/` and declared in `pubspec.yaml`.

| Type | Files |
|---|---|
| **PNG Images** | Splash, onboarding, cars, charging stations, success screens, etc. |
| **SVG Icons** | Bottom nav icons (home, maps, booking, profile, charge), Google logo, map markers |

### Design System

- **Design Size:** 390×844 (iPhone 14 Pro reference)
- **Responsive Scaling:** via `flutter_screenutil`
- **Color Palette:** Defined in `KColors` class — navy blue, mint green, coral accents
- **Access Pattern:** `R.color.deepNavyBlue`, `R.color.mintGreen`, etc.

---

## 🧪 Testing

```bash
# Run all tests
flutter test

# Run with coverage
flutter test --coverage
```

Test files are located in the `test/` directory.

---

## 🤝 Contributing

1. Fork the repository
2. Create a feature branch (`git checkout -b feature/amazing-feature`)
3. Commit your changes (`git commit -m 'Add amazing feature'`)
4. Push to the branch (`git push origin feature/amazing-feature`)
5. Open a Pull Request

### Code Style
- Follow [Dart style guide](https://dart.dev/guides/language/effective-dart/style)
- Lint rules defined in `analysis_options.yaml`
- Use feature-first folder structure for new modules
- Each feature should have `model/`, `UI/controller/`, and `UI/screen/` subdirectories

---

## 📄 License

This project is private and not published to pub.dev.

---

## 📞 Support

For issues, questions, or feature requests, please open an issue in the repository.

