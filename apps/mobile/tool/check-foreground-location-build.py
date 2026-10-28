#!/usr/bin/env python3
"""Validate the built artifacts, including transitive permission/locale merging."""
import argparse
import json
from pathlib import Path
import plistlib
import subprocess
import xml.etree.ElementTree as ET

parser = argparse.ArgumentParser()
parser.add_argument('platform', choices=['android', 'ios'])
args = parser.parse_args()
root = Path(__file__).resolve().parents[1]
if args.platform == 'android':
    candidates = [p for p in (root/'build/app/intermediates').rglob('AndroidManifest.xml')
                  if any(part in ['merged_manifest','merged_manifests','packaged_manifests'] for part in p.parts) and 'debug' in p.parts]
    assert candidates, 'No compiled debug application manifest found.'
    for path in candidates:
        manifest = ET.parse(path)
        permissions = {p.attrib['{http://schemas.android.com/apk/res/android}name'] for p in manifest.findall('uses-permission')}
        assert {'android.permission.ACCESS_COARSE_LOCATION','android.permission.ACCESS_FINE_LOCATION'}.issubset(permissions)
        assert 'android.permission.ACCESS_BACKGROUND_LOCATION' not in permissions
        assert 'android.permission.FOREGROUND_SERVICE_LOCATION' not in permissions
    print(f'PASS: {len(candidates)} actual merged Android manifests retain foreground coarse/fine only; no background/foreground-service location permission.')
else:
    application = root/'build/ios/iphonesimulator/Runner.app'
    info = plistlib.loads((application/'Info.plist').read_bytes())
    assert info.get('NSLocationWhenInUseUsageDescription')
    assert 'NSLocationAlwaysAndWhenInUseUsageDescription' not in info
    assert 'NSLocationAlwaysUsageDescription' not in info
    assert 'location' not in info.get('UIBackgroundModes', [])
    for locale in ['en','ar']:
        path = application/f'{locale}.lproj/InfoPlist.strings'
        assert path.is_file(), f'Missing bundled {locale} location privacy strings.'
        values = json.loads(subprocess.check_output(['plutil','-convert','json','-o','-',str(path)],text=True))
        assert values.get('NSLocationWhenInUseUsageDescription')
        if locale == 'ar':
            assert any('\u0600' <= c <= '\u06ff' for c in values['NSLocationWhenInUseUsageDescription'])
    print('PASS: built iOS application has localized English/Arabic foreground-only privacy text and no background/Always location capability.')
