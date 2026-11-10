import Flutter
import UIKit
import GoogleMaps

@main
@objc class AppDelegate: FlutterAppDelegate {
  override func application(
    _ application: UIApplication,
    didFinishLaunchingWithOptions launchOptions: [UIApplication.LaunchOptionsKey: Any]?
  ) -> Bool {
    // The iOS Google Maps SDK must be configured before plugin registration.
    // The key is supplied by a private xcconfig, never committed to Git.
    // Missing configuration keeps demo builds unkeyed; distribution requires
    // owner-provided API keys restricted to this app's bundle identifier.
    if let raw = Bundle.main.object(forInfoDictionaryKey: "GoogleMapsApiKey") as? String {
      let key = raw.trimmingCharacters(in: .whitespacesAndNewlines)
      if !key.isEmpty && !key.hasPrefix("$(") {
        _ = GMSServices.provideAPIKey(key)
      }
    }
    GeneratedPluginRegistrant.register(with: self)
    return super.application(application, didFinishLaunchingWithOptions: launchOptions)
  }
}
