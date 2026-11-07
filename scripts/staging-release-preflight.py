#!/usr/bin/env python3
"""Staging-only, no-deployment EV release preflight. Never print secrets."""
import argparse
import base64
import binascii
import json
import os
from pathlib import Path
import re
import stat
import sys

class PreflightError(Exception):
    pass

DISABLED = (
    'CSMS_SIMULATOR_ENABLED', 'CSMS_COMMAND_WORKER_ENABLED',
    'CSMS_REMOTE_AUTHORIZATION_ENABLED', 'OCPI_TEST_COMMANDS_ENABLED',
    'SUBSCRIPTION_COLLECTION_ENABLED', 'PAYOUT_TEST_ENABLED',
    'CSMS_INGRESS_AUTH_ENABLED', 'EV_BATTERY_SIMULATOR_ENABLED',
)
REQUIRED = ('APP_KEY', 'DB_PASSWORD', 'STAGING_HOST', 'ACME_EMAIL',
            'STAGING_BIND_IP', 'EV_IMAGE_TAG')
POSTGRES_IMAGE = ('postgres:17-alpine@sha256:'
                  '266d3c257594e195b309ae9d1805e54c788f1d7b375f56bf6c367aac6c917e7b')

def require(ok, reason):
    if not ok:
        raise PreflightError(reason)

def read_env(path):
    # A path-based is_symlink()/stat()/read_text() sequence permits a
    # replacement between checks. Inspect and consume one no-follow fd.
    require(hasattr(os, 'O_NOFOLLOW'), 'Secure staging env reads unavailable')
    flags = os.O_RDONLY | getattr(os, 'O_CLOEXEC', 0) | os.O_NOFOLLOW
    try:
        descriptor = os.open(path, flags)
    except OSError:
        raise PreflightError('Staging env cannot be opened safely') from None
    with os.fdopen(descriptor, 'rb') as stream:
        info = os.fstat(stream.fileno())
        require(stat.S_ISREG(info.st_mode) and 0 < info.st_size <= 65536,
                'Staging env must be a bounded regular file')
        require(info.st_mode & 0o077 == 0, 'Staging env must not be group/other readable')
        payload = stream.read(65537)
        require(len(payload) == info.st_size and len(payload) <= 65536,
                'Staging env changed size during reading')
    try:
        contents = payload.decode('utf-8')
    except UnicodeDecodeError:
        raise PreflightError('Staging env must be valid UTF-8') from None
    data = {}
    for line in contents.splitlines():
        line = line.strip()
        if not line or line.startswith('#'):
            continue
        match = re.fullmatch(r'([A-Z][A-Z0-9_]*)=(.*)', line)
        require(match is not None, 'Invalid staging environment assignment')
        key, value = match.groups()
        require(key not in data, f'Duplicate staging setting: {key}')
        if len(value) > 1 and value[0] == value[-1] and value[0] in ("'", '"'):
            value = value[1:-1]
        data[key] = value
    return data

def validate_env(data):
    for key in REQUIRED:
        require(bool(data.get(key)), f'Missing required staging setting: {key}')
    try:
        decoded = base64.b64decode(data['APP_KEY'].removeprefix('base64:'), validate=True)
    except (binascii.Error, ValueError):
        raise PreflightError('APP_KEY is invalid base64') from None
    require(data['APP_KEY'].startswith('base64:') and len(decoded) == 32,
            'APP_KEY must be a retained 32-byte Laravel encryption key')
    password = data['DB_PASSWORD']
    require(len(password) >= 24 and len(set(password)) >= 10 and
            '${' not in password, 'DB_PASSWORD is weak or unresolved')
    host = data['STAGING_HOST'].lower()
    require(len(host) <= 253 and re.fullmatch(
        r'[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)+',
        host) is not None and
        not host.endswith(('.invalid', '.example', '.test', '.localhost',
                           '.local', '.internal')) and host != 'example.com',
        'STAGING_HOST must be an authorized non-example DNS hostname')
    require(re.fullmatch(r'[^\s@]+@[^\s@]+\.[A-Za-z]{2,}', data['ACME_EMAIL']) is not None and
            not data['ACME_EMAIL'].lower().endswith(('@example.com', '@example.org')),
            'ACME_EMAIL must be a responsible non-example contact')
    require(data['STAGING_BIND_IP'] == '127.0.0.1',
            'Staging gateway must bind to loopback')
    require(re.fullmatch(r'sha-[a-f0-9]{12,40}', data['EV_IMAGE_TAG']) is not None,
            'EV_IMAGE_TAG must be commit-scoped')
    for key in DISABLED:
        require(data.get(key, 'false').lower() in ('false', 'off', '0'),
                f'Staging operation must remain disabled: {key}')
    require(data.get('APP_ENV', 'staging') == 'staging', 'APP_ENV must be staging')
    require(data.get('APP_DEBUG', 'false') == 'false', 'APP_DEBUG must be false')
    for key in ('SESSION_ENCRYPT', 'SESSION_SECURE_COOKIE'):
        require(data.get(key, 'true') == 'true', f'{key} must be true')

def extract_services(compose):
    match = re.search(r'^services:\s*$', compose, re.M)
    require(match is not None, 'Services section missing')
    services, current = {}, None
    for line in compose[match.end():].splitlines():
        if line and not line.startswith((' ', '#')):
            break
        found = re.fullmatch(r'  ([a-z][a-z0-9-]*):\s*', line)
        if found:
            current = found.group(1)
            services[current] = []
        elif current:
            services[current].append(line)
    return {name: '\n'.join(lines) for name, lines in services.items()}

def check_policy(root):
    root = Path(root) / 'infra/staging'
    compose = (root / 'compose.yaml').read_text()
    caddy = (root / 'Caddyfile').read_text()
    image = (root / 'Dockerfile').read_text()
    require('name: ev-staging' in compose and "APP_DEBUG: 'false'" in compose
            and 'APP_ENV: staging' in compose, 'Invalid staging environment')
    for field in ("SESSION_ENCRYPT: 'true'", "SESSION_SECURE_COOKIE: 'true'",
                  'DB_CONNECTION: pgsql', 'DB_HOST: database'):
        require(field in compose, 'Required isolation or secure session field missing')
    for key in DISABLED:
        require(re.search(r'^    ' + re.escape(key) + r": 'false'\s*$", compose, re.M),
                f'Staging compose must disable: {key}')
    services = extract_services(compose)
    for name in ('application', 'worker', 'scheduler', 'database', 'gateway'):
        require(name in services, f'Missing staging service: {name}')
    for name in ('application', 'worker', 'scheduler', 'database'):
        require(not re.search(r'^    ports:', services[name], re.M),
                f'Internal service must not publish ports: {name}')
    require(POSTGRES_IMAGE in services['database'], 'Postgres must use pinned reviewed image')
    require("'${STAGING_BIND_IP:-127.0.0.1}:8443:443'" in services['gateway'],
            'Gateway port must default to loopback')
    for value in ('reverse_proxy application:80', 'admin off',
                  'X-Content-Type-Options nosniff', 'X-Frame-Options DENY',
                  'Referrer-Policy strict-origin-when-cross-origin'):
        require(value in caddy, 'Missing Caddy isolation/header policy')
    require(re.search(r'FROM php:[^\s]+@sha256:[a-f0-9]{64}', image) is not None and
            'chmod 0700 storage/app/private' in image and 'HEALTHCHECK' in image,
            'Staging runtime must have pinned PHP, private storage and healthcheck')
    return sorted(services)

def audit(root, env_path=None):
    services = check_policy(root)
    if env_path is not None:
        validate_env(read_env(env_path))
    return {
        'status': 'PASS',
        'classification': 'staging configuration review only',
        'staging_env_checked': env_path is not None,
        'private_secret_values_logged': False,
        'services_checked': services,
        'external_provider_or_production_accepted': False,
    }

def main(argv=None):
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--root', type=Path, default=Path(__file__).resolve().parents[1])
    parser.add_argument('--env', type=Path)
    parser.add_argument('--output', type=Path)
    args = parser.parse_args(argv)
    if args.output:
        args.output.unlink(missing_ok=True)
    try:
        result = audit(args.root, args.env)
    except (OSError, UnicodeError, PreflightError) as exc:
        print(f'FAIL: {exc}', file=sys.stderr)
        return 1
    if args.output:
        args.output.parent.mkdir(parents=True, exist_ok=True)
        temporary = args.output.with_suffix('.preflight-tmp')
        temporary.unlink(missing_ok=True)
        fd = os.open(temporary, os.O_CREAT | os.O_EXCL | os.O_WRONLY, 0o600)
        with os.fdopen(fd, 'w') as f:
            json.dump(result, f, sort_keys=True, indent=2)
            f.write('\n')
        os.replace(temporary, args.output)
    print('PASS: staging configuration only; NOT production acceptance')
    return 0

if __name__ == '__main__':
    sys.exit(main())
