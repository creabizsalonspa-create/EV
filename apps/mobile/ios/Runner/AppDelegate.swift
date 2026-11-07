import Flutter
import UIKit

@main
@objc class AppDelegate: FlutterAppDelegate {
  private var returnPending = false
  private var returnChannel: FlutterMethodChannel?

  private func acceptReturn(_ url: URL) -> Bool {
    guard let parts = URLComponents(url: url, resolvingAgainstBaseURL: false),
      parts.scheme == "creabizev", parts.host == "payments", parts.path == "/hosted-return",
      parts.user == nil, parts.password == nil, parts.port == nil,
      parts.query == nil, parts.fragment == nil else { return false }
    returnPending = true
    returnChannel?.invokeMethod("hostedReturn", arguments: nil)
    return true
  }

  override func application(
    _ application: UIApplication,
    didFinishLaunchingWithOptions launchOptions: [UIApplication.LaunchOptionsKey: Any]?
  ) -> Bool {
    GeneratedPluginRegistrant.register(with: self)
    if let controller = window?.rootViewController as? FlutterViewController {
      returnChannel = FlutterMethodChannel(name: "com.creabiz.ev/payment_return", binaryMessenger: controller.binaryMessenger)
      returnChannel?.setMethodCallHandler { [weak self] call, result in
        guard call.method == "consumeReturn" else { result(FlutterMethodNotImplemented); return }
        let pending = self?.returnPending ?? false
        self?.returnPending = false
        result(pending)
      }
    }
    if let url = launchOptions?[.url] as? URL { _ = acceptReturn(url) }
    return super.application(application, didFinishLaunchingWithOptions: launchOptions)
  }

  override func application(_ app: UIApplication, open url: URL, options: [UIApplication.OpenURLOptionsKey: Any] = [:]) -> Bool {
    if acceptReturn(url) { return true }
    return super.application(app, open: url, options: options)
  }
}
