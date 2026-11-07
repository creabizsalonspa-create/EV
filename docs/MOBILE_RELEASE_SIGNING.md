# Android release signing — Agent 6

Both native Flutter apps previously assigned `signingConfigs.debug` to Android
`release` tasks. This is development-only and must not be shipped. Android
release APK/AAB tasks now fail without an explicit, private signing keystore.
Neither application identity nor production distribution permissions were changed.

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
