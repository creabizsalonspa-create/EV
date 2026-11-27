"""Check an independently pinned App Store provisioning profile, not CMS trust."""
from datetime import datetime, timezone
import hashlib
import plistlib
import re
import subprocess

from audit_ipa_package import require, AuditError


def decode_profile(path):
    try:
        result = subprocess.run(['/usr/bin/security', 'cms', '-D', '-i', str(path)],
                                capture_output=True, timeout=30, check=False)
    except (OSError, subprocess.TimeoutExpired) as exc:
        raise AuditError('Provisioning profile decoder unavailable') from exc
    require(result.returncode == 0 and 0 < len(result.stdout) <= 4 * 1024 * 1024,
            'Cannot decode provisioning profile')
    try:
        return plistlib.loads(result.stdout)
    except (ValueError, TypeError, plistlib.InvalidFileException) as exc:
        raise AuditError('Invalid provisioning profile') from exc


def profile_evidence(app, team, bundle, leaf, pin, *, now=None):
    require(isinstance(pin, str) and re.fullmatch(r'[0-9a-fA-F]{64}', pin),
            'Expected independently approved provisioning profile digest')
    path = app / 'embedded.mobileprovision'
    require(path.is_file() and 0 < path.stat().st_size <= 4 * 1024 * 1024,
            'Missing or oversized embedded provisioning profile')
    before = hashlib.sha256(path.read_bytes()).hexdigest()
    require(before == pin.lower(), 'Provisioning profile pin mismatch')
    profile = decode_profile(path)
    require(isinstance(profile, dict), 'Invalid profile data')
    require(profile.get('TeamIdentifier') == [team] and profile.get('Platform') == ['iOS'],
            'Provisioning profile team or platform mismatch')
    require('ProvisionedDevices' not in profile and profile.get('ProvisionsAllDevices', False) is False,
            'Expected App Store profile, not device or enterprise distribution')
    rights = profile.get('Entitlements')
    require(isinstance(rights, dict) and rights.get('application-identifier') == team + '.' + bundle
            and rights.get('com.apple.developer.team-identifier') == team
            and rights.get('get-task-allow') is False,
            'Provisioning profile app identity or debug entitlement mismatch')
    require(rights.get('beta-reports-active') is True, 'Expected App Store distribution entitlement')
    dates = [profile.get(key) for key in ('CreationDate', 'ExpirationDate')]
    require(all(isinstance(d, datetime) for d in dates), 'Missing profile validity dates')
    start, end = [d.replace(tzinfo=timezone.utc) if d.tzinfo is None else d.astimezone(timezone.utc) for d in dates]
    require(start <= (now or datetime.now(timezone.utc)) < end, 'Provisioning profile not currently valid')
    certs = profile.get('DeveloperCertificates')
    require(isinstance(certs, list) and 0 < len(certs) <= 100 and
            all(isinstance(c, bytes) and 0 < len(c) <= 1024 * 1024 for c in certs)
            and leaf in certs, 'Signing certificate not authorized by pinned profile')
    require(hashlib.sha256(path.read_bytes()).hexdigest() == before, 'Provisioning profile changed during inspection')
    return {'profile_pin_verified': True, 'profile_identity_and_validity_verified': True,
            'profile_distribution_type': 'app-store', 'profile_expires_utc': end.isoformat(),
            'profile_sha256': before, 'profile_cms_trust_verified': False,
            'profile_all_entitlements_verified': False, 'provisioning_verified': False}
