#!/usr/bin/env python3
"""Audit the compiled Android manifest and distribution signer, not source only.

Requires Android SDK build-tools (aapt; apksigner for release). This audit
does NOT prove physical-device behavior, Google API restrictions, or store
acceptance. No signing secrets or API-key values are printed.
"""
import argparse
import hashlib
import json
import os
from pathlib import Path
import re
import subprocess
import sys


class AuditError(ValueError):
    """A release safety invariant is not met."""


def _sdk_tool(name, supplied=None):
    if supplied:
        return str(supplied)
    sdk = os.environ.get("ANDROID_SDK_ROOT") or os.environ.get("ANDROID_HOME")
    if sdk:
        candidates = sorted((Path(sdk) / "build-tools").glob("*/" + name),
                            reverse=True)
        if candidates:
            return str(candidates[0])
    raise AuditError("Android SDK build tool unavailable: " + name)


def _run(command):
    try:
        process = subprocess.run(
            command, check=True, capture_output=True, text=True, timeout=90,
        )
    except (OSError, subprocess.CalledProcessError, subprocess.TimeoutExpired) as exc:
        raise AuditError("Could not verify APK using Android build tools") from exc
    return process.stdout


def _required_false(manifest, name):
    pattern = r"android:" + re.escape(name) + r"[^\n]*\(type 0x12\)0x0\b"
    if not re.search(pattern, manifest):
        raise AuditError("Compiled Android manifest must set " + name + "=false")


def audit_manifest(
    badging, manifest, *, expected_package, mode="debug",
    maps_required=False, expected_signer_sha256=None, signer_output=None,
):
    """Evaluate compiled aapt/apksigner output; safe to test with fixtures."""
    match = re.search(r"(?m)^package: name='([^']+)'", badging)
    if match is None:
        raise AuditError("Compiled APK package identity could not be read")
    package = match.group(1)
    if package != expected_package:
        raise AuditError("Compiled APK package identity does not match expected ID")

    sdk = re.search(r"(?m)^targetSdkVersion:'([0-9]+)'", badging)
    if sdk is None:
        raise AuditError("Compiled target Android SDK version is missing")

    _required_false(manifest, "allowBackup")
    _required_false(manifest, "usesCleartextTraffic")
    for name in ("fullBackupContent", "dataExtractionRules"):
        if not re.search(r"android:" + name + r"\([^)]*\)=@0x[0-9a-fA-F]+",
                         manifest):
            raise AuditError("Compiled Android manifest is missing " + name)

    if maps_required:
        maps = re.search(
            r'A:\s*android:name[^\n]*="com\.google\.android\.geo\.API_KEY"'
            r'[^\n]*\n\s*A:\s*android:value[^\n]*="([^"]*)"',
            manifest,
        )
        if maps is None or not maps.group(1).strip() or \
                maps.group(1).startswith("$("):
            raise AuditError("Compiled APK is missing its Google Maps API key")

    result = {"package": package, "target_sdk": int(sdk.group(1)),
              "mode": mode, "backup_disabled": True,
              "remote_cleartext_disabled": True}
    if mode == "release":
        if package.endswith((".dev", ".debug", ".test")):
            raise AuditError("Distribution APK uses a nonproduction package ID")
        if not expected_signer_sha256 or \
                not re.fullmatch(r"[0-9a-fA-F]{64}", expected_signer_sha256):
            raise AuditError("Release requires the approved signing certificate digest")
        if not signer_output:
            raise AuditError("Release APK signature has not been verified")
        # A configured digest may accidentally have been copied from a
        # debug build. Reject the conventional Android debug certificate
        # even when its fingerprint is the caller's supplied "expected" one.
        if re.search(
            r"(?mi)^Signer #\d+ certificate DN:[^\n]*\bCN\s*=\s*Android Debug\b",
            signer_output,
        ):
            raise AuditError("Distribution APK is signed with Android Debug")
        signer = re.search(
            r"Signer #1 certificate SHA-256 digest:\s*([0-9a-fA-F]{64})",
            signer_output,
        )
        if signer is None or \
                signer.group(1).lower() != expected_signer_sha256.lower():
            raise AuditError("Release APK signing certificate does not match")
        result["approved_signer_digest"] = signer.group(1).lower()
    elif mode != "debug":
        raise AuditError("Unknown APK release mode")
    return result


def audit_file(args):
    apk = Path(args.apk)
    if not apk.is_file():
        raise AuditError("APK file does not exist")
    aapt = _sdk_tool("aapt", args.aapt)
    badging = _run([aapt, "dump", "badging", str(apk)])
    manifest = _run([aapt, "dump", "xmltree", str(apk), "AndroidManifest.xml"])
    signature = None
    if args.mode == "release":
        signer = _sdk_tool("apksigner", args.apksigner)
        signature = _run([signer, "verify", "--print-certs", str(apk)])
    result = audit_manifest(
        badging, manifest,
        expected_package=args.expected_package, mode=args.mode,
        maps_required=args.require_maps_key,
        expected_signer_sha256=args.expected_signer_sha256,
        signer_output=signature,
    )
    digest = hashlib.sha256()
    with apk.open("rb") as source:
        for chunk in iter(lambda: source.read(1024 * 1024), b""):
            digest.update(chunk)
    result["sha256"] = digest.hexdigest()
    return result


def main(argv=None):
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("apk", help="Local compiled APK to inspect")
    parser.add_argument("--expected-package", required=True)
    parser.add_argument("--mode", choices=("debug", "release"), default="debug")
    parser.add_argument("--expected-signer-sha256",
                        help="Mandatory expected certificate SHA-256 for release")
    parser.add_argument("--require-maps-key", action="store_true")
    parser.add_argument("--aapt", help="Override Android SDK aapt path")
    parser.add_argument("--apksigner", help="Override Android SDK apksigner path")
    args = parser.parse_args(argv)
    try:
        result = audit_file(args)
    except AuditError as exc:
        print("APK AUDIT FAILED: " + str(exc), file=sys.stderr)
        return 1
    print(json.dumps(result, sort_keys=True))
    return 0


if __name__ == "__main__":
    sys.exit(main())
