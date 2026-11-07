# Android release signing — Agent 6

Both native Flutter apps previously assigned `signingConfigs.debug` to Android
`release` tasks. This is development-only and must not be shipped. Android
release APK/AAB tasks now fail without an explicit, private signing keystore.
A release build now requires owner-approved Android identity in the
separate HAS.ONE customer app; development identities remain isolated.

## Additional mobile platform protections

Both Android apps now set `android:allowBackup="false"` and explicitly exclude
credential-protected and device-protected storage from legacy cloud backup,
Android 12+ cloud backup and device-to-device transfer. These are release
privacy boundaries; test fixtures and credentials must not be migrated to
another device.

The separate HAS.ONE customer iOS app now declares photo-library access for
its profile image picker, in addition to camera and location descriptions.

**Local demo transport:** Both Android apps permit HTTP only to the exact
`localhost` and `127.0.0.1` hosts in debug manifests with debug-only
network-security rules. Both iOS apps select `Info-Debug.plist` for the Debug
configuration and permit those same exact loopback hosts. Release iOS targets
retain the ordinary `Info.plist` (without ATS cleartext exceptions), and
release Android manifests do not reference debug network policy. Real mobile
builds still require the correct private service configuration and platform
verification: these policies are not a public staging deployment.

### Separate customer app: local debug API origin

The HAS.ONE customer Flutter `ApiConfig` now accepts an HTTP origin only
when **both** `EV_LOCAL_DEMO=true` and a non-production Flutter build are
active, and only for exact `localhost` or `127.0.0.1`. The default API
origin remains HTTPS. `dart.vm.product` forces HTTPS even if a production
build was accidentally given a demo flag. Remote HTTP, URL credentials,
paths, query parameters, and fragments remain rejected.

A disposable local-demo invocation:

```sh
cd apps/hasone-customer
flutter run --debug --dart-define=EV_LOCAL_DEMO=true \
  --dart-define=EV_API_BASE_URL=http://127.0.0.1:8000
flutter test test/api_config_test.dart
```

For Android emulators, loopback means the **device's** own loopback.
An authorized `adb reverse tcp:8000 tcp:8000` forwarding tunnel is needed
for this exact origin to reach a development machine's API. The iOS
simulator can reach the Mac's localhost if a local service is running.
Do not use a public staging service with this demo flag or consider this
proof of authenticated API/charger operation. Store/release builds must
use an authorized HTTPS origin.

## Google Maps native SDK provisioning

The separate HAS.ONE customer app uses Google Maps on Android and iOS. An
Android API key is not interchangeable with an iOS bundle-restricted key.

**Android store application ID:** the committed HAS.ONE customer ID is
`com.creabiz.hasone.customer.dev`, intended for development. A production
Android release now also requires an explicit, approved
`HASONE_ANDROID_APPLICATION_ID` Gradle project property: a dotted Android
package ID with at least three segments that does not end in `.dev`.
Debug builds retain the current ID if no property is given. CI can pass
`ORG_GRADLE_PROJECT_HASONE_ANDROID_APPLICATION_ID` as a protected build
variable. Before distributing to stores, the owner must approve that package
ID **and** bind the Google Maps Android API key to it and its final signing
certificate fingerprint. This source guard does not provision or confirm
an approved ID; it simply prevents distributing the known demo identity.

**Android:** the existing `MAPS_API_KEY` Gradle project property supplies
the manifest's Google Maps SDK key. Approved release `assembleRelease`,
`bundleRelease` and `packageRelease` tasks now reject a missing key;
debug/local-demo builds may still compile without it but maps will not work.
Use a key restricted to the final Android package ID, signing-certificate
fingerprint, and required Maps API. The current `.dev` application ID must
be approved or replaced by the owner before store distribution. For a CI
runner, `ORG_GRADLE_PROJECT_MAPS_API_KEY` can supply the value without
embedding it in committed Gradle files.

**iOS:** `Runner/AppDelegate.swift` now imports GoogleMaps and calls
`GMSServices.provideAPIKey` before registering Flutter plugins when a
configured key is available. Both iOS Info plists take their value from
`$(EV_GOOGLE_MAPS_IOS_KEY)`. On an authorized macOS/Xcode build host,
create an **untracked** `apps/hasone-customer/ios/Flutter/MapsKeys.xcconfig`
containing the approved bundle-restricted key:

```xcconfig
EV_GOOGLE_MAPS_IOS_KEY = YOUR_APPROVED_IOS_MAPS_KEY
EV_HASONE_IOS_RELEASE_BUNDLE_ID = com.yourcompany.approved.application
```

The `EV_HASONE_IOS_RELEASE_BUNDLE_ID` variable is used in the
HAS.ONE customer iOS `Release` and `Profile` Runner and RunnerTests
configurations. Its value must be an owner-approved final iOS bundle ID,
registered in the Apple Developer account, with corresponding signing
provisioning and Google Maps iOS API-key restriction. The `Debug` Runner
and RunnerTests retain `com.creabiz.hasone.customer.dev`. Never use the
example `com.yourcompany.approved.application` for delivery.

Both Debug and Release xcconfigs include this optional file. The file is
ignored by Git. No key or production identifier is committed, generated,
guessed or provisioned by these source changes. `xcodebuild` on an
authorized macOS runner must verify the exact release bundle ID and signing.
The source-only guard does not prove that Xcode will accept a missing or
malformed build-setting value. Missing iOS configuration allows unrelated simulator
tests to build, **but maps cannot be accepted as working**. Before customer
delivery, configure the bundle-restricted key, compile on macOS/Xcode,
open real Google Maps views on device, and confirm API/billing and location
restrictions. A mobile API key is embedded in the distributed app and must
be restricted by the provider; keeping it untracked in source control is
not a substitute for API restriction.

See the official native SDK setup:
https://developers.google.com/maps/documentation/ios-sdk/config

## Secrets supplied by the authorized release operator

Set four **Gradle project properties** for each app through a secured CI secret
manager or restricted runner environment. Gradle accepts
`ORG_GRADLE_PROJECT_<PROPERTY>` environment variables:

| Native application | Required property prefix |
| --- | --- |
| Shared customer/vendor (`apps/mobile`) | `EV_ANDROID_` |
| Separate HAS.ONE customer (`apps/hasone-customer`) | `HASONE_ANDROID_` |

For **each** prefix the required suffixes are `STORE_FILE`,
`STORE_PASSWORD`, `KEY_ALIAS` and `KEY_PASSWORD`.

- `STORE_FILE`: path to an authorized, readable release keystore **on that
  build runner** (not in this repository).
- The other three values are protected Gradle properties; do not print them,
  commit them, place them in command history, or include them in logs or artifacts.
- Use distinct keystores and aliases if the two applications have distinct
  distribution owners. Keystore access/backup remains the operator's duty.
- For local debug builds, these credentials are not required. If absent,
  `assembleRelease`, `bundleRelease` and `packageRelease` stop with an
  explicit signing-error message; they never fall back to the debug signer.

After approval and credentials provisioning, from the selected app directory:

```sh
flutter pub get
flutter build appbundle --release --dart-define=EV_API_BASE_URL=https://authorized-host.example
```

Use the app's approved HTTPS backend configuration and verify the final
APK/AAB certificate, signing identity, SHA-256 and application ID before
uploading anything to a store. The separate HAS.ONE app currently has an
application ID ending in `.dev`; do not invent a replacement production ID or
claim store readiness without the owner's confirmed package name.

For source-only regression evidence:

```sh
python3 -B -m unittest discover -s tests -p mobile_release_signing_test.py
```

The source regression does not compile Kotlin, produce a signed artifact, prove
Google Play release eligibility, sign iOS, or perform physical-device testing.
Those gates still require authorized Android/JDK/Flutter and macOS/Xcode runners,
owned signing keys, and explicit external acceptance.
