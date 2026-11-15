#!/usr/bin/env python3
"""Fail closed if a built native EV app exposes unreviewed deep-link entrypoints.

Source XML alone is not proof of the merged Android manifest or compiled
iOS Info.plist. This is a developer artifact audit, not a signed release gate.
"""
import argparse
from pathlib import Path
import plistlib
import xml.etree.ElementTree as ET

ANDROID = "{http://schemas.android.com/apk/res/android}"
EXPECTED = {
    ("creabizev", "payments", "path", "/hosted-return"),
    ("creabizev", "navigate", "pathPrefix", "/tab/"),
}
CATEGORIES = {
    "android.intent.category.DEFAULT",
    "android.intent.category.BROWSABLE",
}


def audit_android(root: Path) -> int:
    intermediates = root / "build/app/intermediates"
    paths = sorted(
        p for p in intermediates.rglob("AndroidManifest.xml")
        if "debug" in p.parts
        and any(part in p.parts for part in
                ("merged_manifest", "merged_manifests", "packaged_manifests"))
    )
    if not paths:
        raise AssertionError("No compiled/merged debug manifest to inspect")
    for path in paths:
        activity = ET.parse(path).find("./application/activity")
        if activity is None:
            raise AssertionError(f"Missing native launcher activity: {path}")
        if activity.get(ANDROID + "exported") != "true":
            raise AssertionError(f"Deep-link launcher not explicitly exported: {path}")
        if activity.get(ANDROID + "launchMode") != "singleTop":
            raise AssertionError(f"Expected singleTop intent delivery: {path}")
        flags = {
            m.get(ANDROID + "name"): m.get(ANDROID + "value")
            for m in activity.findall("meta-data")
        }
        if flags.get("flutter_deeplinking_enabled") != "false":
            raise AssertionError(f"Uncontrolled Flutter URL routing enabled: {path}")

        observed = set()
        count = 0
        for intent_filter in activity.findall("intent-filter"):
            actions = {
                x.get(ANDROID + "name")
                for x in intent_filter.findall("action")
            }
            if "android.intent.action.VIEW" not in actions:
                continue
            count += 1
            if actions != {"android.intent.action.VIEW"}:
                raise AssertionError(f"Unexpected VIEW action combination: {path}")
            categories = {
                x.get(ANDROID + "name")
                for x in intent_filter.findall("category")
            }
            if categories != CATEGORIES:
                raise AssertionError(f"Unsafe or incomplete VIEW categories: {path}")
            data = intent_filter.findall("data")
            if len(data) != 1:
                raise AssertionError(f"Each VIEW filter requires one fixed data tag: {path}")
            props = {
                k.removeprefix(ANDROID): v
                for k, v in data[0].attrib.items()
            }
            keys = set(props)
            if keys == {"scheme", "host", "path"}:
                entry = (props["scheme"], props["host"], "path", props["path"])
            elif keys == {"scheme", "host", "pathPrefix"}:
                entry = (props["scheme"], props["host"], "pathPrefix", props["pathPrefix"])
            else:
                raise AssertionError(f"Unexpected URL data attributes: {path}")
            observed.add(entry)
        if count != len(EXPECTED) or observed != EXPECTED:
            raise AssertionError(
                f"Compiled deep-link filters differ from reviewed fixed contract: {path}"
            )
    return len(paths)


def audit_ios(root: Path) -> None:
    path = root / "build/ios/iphonesimulator/Runner.app/Info.plist"
    info = plistlib.loads(path.read_bytes())
    types = info.get("CFBundleURLTypes")
    if not isinstance(types, list) or len(types) != 1:
        raise AssertionError("Unexpected compiled iOS URL schemes")
    if types[0].get("CFBundleURLSchemes") != ["creabizev"]:
        raise AssertionError("Built iOS bundle must expose only reviewed EV custom scheme")
    if info.get("FlutterDeepLinkingEnabled") is not False:
        raise AssertionError("Unreviewed Flutter deep-link auto-routing enabled in iOS bundle")
    if "com.apple.developer.associated-domains" in info:
        raise AssertionError("Universal-link entitlement cannot be asserted via Info.plist")


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("platform", choices=("android", "ios"))
    args = parser.parse_args()
    root = Path(__file__).resolve().parents[1]
    if args.platform == "android":
        count = audit_android(root)
        print(f"PASS: {count} built Android debug manifests contain only the two reviewed EV URL filters.")
    else:
        audit_ios(root)
        print("PASS: built iOS simulator app exposes only the reviewed EV custom scheme.")


if __name__ == "__main__":
    main()
