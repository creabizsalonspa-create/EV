#!/usr/bin/env python3
"""Offline lockfile inventory. Declarations are not license/security approvals."""
import argparse
import hashlib
import json
import math
import os
from pathlib import Path
import re
import subprocess
import sys

LOCKS = {'composer.lock', 'package-lock.json', 'pubspec.lock'}
MANIFESTS = {'composer.json': 'composer.lock', 'package.json': 'package-lock.json', 'pubspec.yaml': 'pubspec.lock'}
RELEASE_INPUTS = {
    'Dockerfile': 'container_build',
    'compose.yaml': 'container_deployment',
    'compose.yml': 'container_deployment',
    'docker-compose.yaml': 'container_deployment',
    'docker-compose.yml': 'container_deployment',
    'gradle-wrapper.properties': 'android_toolchain',
    'settings.gradle': 'android_dependency_settings',
    'settings.gradle.kts': 'android_dependency_settings',
    'build.gradle': 'android_build',
    'build.gradle.kts': 'android_build',
    'Podfile': 'ios_dependencies',
    'Podfile.lock': 'ios_dependency_lock',
    'THIRD_PARTY_NOTICES.md': 'third_party_notices',
}
EXACT_RELEASE_INPUTS = {
    'docs/evidence/composer-licenses.json': 'composer_license_declarations',
    'services/business-api/package.json': 'unlocked_web_build_manifest',
    'services/business-api/vite.config.js': 'web_build_configuration',
    'apps/mobile/third_party/pdfium_flutter/CREABIZ_PATCH.md': 'native_dependency_provenance',
    'apps/mobile/third_party/pdfium_flutter/pubspec.yaml': 'native_dependency_manifest',
    'apps/mobile/third_party/pdfium_flutter/LICENSE': 'native_dependency_license',
    'apps/mobile/third_party/pdfium_flutter/PDFIUM_LICENSES.txt': 'native_dependency_license',
    'apps/mobile/third_party/pdfium_flutter/android/artifacts.json': 'native_binary_artifact_pins',
    'apps/mobile/third_party/pdfium_flutter/android/fetch_pdfium.py': 'native_binary_fetcher',
    'apps/mobile/third_party/pdfium_flutter/android/CMakeLists.txt': 'native_binary_build',
    'apps/mobile/third_party/pdfium_flutter/darwin/pdfium_flutter.podspec': 'native_binary_artifact_pins',
    'apps/mobile/third_party/pdfium_flutter/darwin/pdfium_flutter/Package.swift': 'native_binary_artifact_pins',
}
SOURCE_INPUTS = LOCKS | MANIFESTS.keys() | RELEASE_INPUTS.keys()


def release_input_kind(relative):
    return EXACT_RELEASE_INPUTS.get(relative.as_posix(), RELEASE_INPUTS.get(relative.name))


def is_source_input(relative):
    return relative.name in SOURCE_INPUTS or relative.as_posix() in EXACT_RELEASE_INPUTS
EXCLUDED = {'.git', 'node_modules', 'vendor', '.dart_tool', 'build', 'artifacts'}
NAME = re.compile(r'^[A-Za-z0-9_@][A-Za-z0-9_.@/-]{0,159}$')
VERSION = re.compile(r'^(?:v?[0-9x]+(?:\.[0-9x]+){1,3}(?:[-+][A-Za-z0-9._-]+)?|dev-[A-Za-z0-9._/-]{1,120})$')
LICENSE = re.compile(r'^[A-Za-z0-9(][A-Za-z0-9.+() /_-]{0,199}$')


class InventoryError(Exception):
    pass


def pairs(items):
    result = {}
    for key, value in items:
        if key in result:
            raise InventoryError('Duplicate JSON key in a lockfile.')
        result[key] = value
    return result


def reject_json_constant(_value):
    # Python's json.loads accepts NaN/Infinity by default, although they
    # are not JSON. Even unreported lockfile metadata must fail closed.
    raise InventoryError('Nonstandard numeric constant in a lockfile.')


def finite_lock_float(value):
    result = float(value)
    if not math.isfinite(result):
        raise InventoryError('Non-finite numeric value in a lockfile.')
    return result


def strict_lock_json(text):
    # Bound nesting independently of Python's recursion limit. Brackets inside
    # strings (including escaped quotes and backslashes) do not count.
    depth = 0
    in_string = False
    escaped = False
    for ch in text:
        if in_string:
            if escaped:
                escaped = False
            elif ch == '\\':
                escaped = True
            elif ch == '"':
                in_string = False
        elif ch == '"':
            in_string = True
        elif ch in '[{':
            depth += 1
            if depth > 64:
                raise InventoryError('Lockfile JSON nesting exceeds the inventory bound.')
        elif ch in ']}':
            depth -= 1
    return json.loads(text, object_pairs_hook=pairs,
                      parse_constant=reject_json_constant,
                      parse_float=finite_lock_float)


def declared_licenses(raw):
    if raw is None:
        return []
    if isinstance(raw, dict):
        raw = raw.get('type')
    values = raw if isinstance(raw, list) else [raw]
    if not all(isinstance(v, str) and LICENSE.fullmatch(v) for v in values):
        raise InventoryError('Unsupported license declaration in a lockfile.')
    return sorted(set(values))


def component(ecosystem, lock, instance, name, version, licenses=None, dev=False, source='locked'):
    if not isinstance(name, str) or not NAME.fullmatch(name):
        raise InventoryError('Invalid dependency name in a lockfile.')
    if not isinstance(version, str) or not VERSION.fullmatch(version):
        raise InventoryError('Missing or unsupported dependency version in a lockfile.')
    identity = '\n'.join([ecosystem, lock, instance, name, version])
    return {'id': hashlib.sha256(identity.encode()).hexdigest(), 'ecosystem': ecosystem,
            'lockfile': lock, 'instance': instance, 'name': name, 'version': version,
            'development_only': dev is True, 'source_kind': source,
            'declared_licenses': declared_licenses(licenses), 'license_approved': False}


def json_components(text, lock, composer):
    data = strict_lock_json(text)
    if not isinstance(data, dict):
        raise InventoryError('A JSON lockfile must contain an object.')
    result = []
    if composer:
        names = set()
        for group in ['packages', 'packages-dev']:
            packages = data.get(group, [])
            if not isinstance(packages, list):
                raise InventoryError('Invalid Composer package collection.')
            for package in packages:
                if not isinstance(package, dict) or package.get('name') in names:
                    raise InventoryError('Invalid or duplicate Composer package.')
                names.add(package.get('name'))
                result.append(component('composer', lock, group + '/' + str(package.get('name')),
                                        package.get('name'), package.get('version'), package.get('license'), group == 'packages-dev'))
        if 'packages' not in data:
            raise InventoryError('Composer locked package collection is missing.')
    else:
        if type(data.get('lockfileVersion')) is not int or data['lockfileVersion'] not in [2, 3] or not isinstance(data.get('packages'), dict):
            raise InventoryError('Only npm lockfile versions2/3 with package instances are supported.')
        for instance, package in sorted(data['packages'].items()):
            if instance == '':
                continue  # Root source project is not a third-party dependency.
            # An npm package instance must be a concrete installed dependency,
            # not a synthetic path or workspace record masquerading as one.
            # Workspace links require separate audited source resolution; even
            # a supplied version is not proof of a locked package's contents.
            if (not isinstance(package, dict) or not isinstance(instance, str)
                    or '\\' in instance or not instance.startswith('node_modules/')
                    or any(part in ['', '.', '..'] for part in instance.split('/'))):
                raise InventoryError('Invalid or unsupported npm package instance.')
            if 'link' in package and type(package['link']) is not bool:
                raise InventoryError('Malformed npm workspace link declaration.')
            if package.get('link') is True:
                raise InventoryError('Unresolved npm workspace link cannot be credited as a locked dependency.')
            if 'dev' in package and type(package['dev']) is not bool:
                raise InventoryError('Malformed npm development-only declaration.')
            name = package.get('name') or instance.rsplit('node_modules/', 1)[-1]
            result.append(component('npm', lock, instance, name, package.get('version'), package.get('license'), package.get('dev'), 'npm_lock'))
    return result


def scalar(value):
    value = value.strip()
    if value.startswith('"'):
        parsed = json.loads(value)
        if not isinstance(parsed, str):
            raise InventoryError('Invalid quoted Flutter lock scalar.')
        return parsed
    if value.startswith("'") and value.endswith("'"):
        return value[1:-1].replace("''", "'")
    return value


def pub_components(text, lock):
    # Deliberately constrained to generated pub lock structure. Unsupported
    # syntax fails instead of silently producing a partial dependency list.
    packages, current, active, seen = [], None, False, set()
    def finish():
        if current is None:
            return
        if current.get('source') not in ['hosted', 'git', 'path', 'sdk']:
            raise InventoryError('Unsupported or missing Flutter dependency source.')
        packages.append(component('pub', lock, current['name'], current['name'], current.get('version'),
                                  dev=current.get('dependency') == 'direct dev', source=current['source']))
    for line in text.splitlines():
        if not line.strip() or line.lstrip().startswith('#'):
            continue
        if '\t' in line:
            raise InventoryError('Tabs are unsupported in generated Flutter locks.')
        indent = len(line) - len(line.lstrip())
        value = line.strip()
        if indent == 0:
            if value == 'packages:':
                if active or seen:
                    raise InventoryError('Duplicate Flutter package collection.')
                active = True
                continue
            if value == 'sdks:':
                finish()
                current = None
                active = False
                continue
            raise InventoryError('Unsupported Flutter lockfile structure.')
        if not active:
            continue
        if indent == 2:
            finish()
            if not re.fullmatch(r'[a-zA-Z0-9_]+:', value) or value[:-1] in seen:
                raise InventoryError('Invalid or duplicate Flutter package.')
            name = value[:-1]
            seen.add(name)
            current = {'name': name}
        elif indent == 4 and current is not None:
            if ':' not in value:
                raise InventoryError('Invalid Flutter package field.')
            key, raw = value.split(':', 1)
            if key not in ['dependency', 'description', 'source', 'version'] or key in current:
                raise InventoryError('Unsupported or duplicate Flutter package field.')
            current[key] = scalar(raw) if raw.strip() else None
        elif indent >= 6 and current is not None:
            if current.get('description') is not None:
                raise InventoryError('Invalid Flutter dependency description nesting.')
        else:
            raise InventoryError('Unsupported Flutter dependency indentation.')
    finish()
    if not seen:
        raise InventoryError('Flutter locked packages are missing.')
    return packages


def inventory(root):
    root = root.resolve(strict=True)
    locks, components = [], []
    commit, dirty, selected = None, None, None
    if (root / '.git').exists():
        try:
            def git(*args):
                return subprocess.check_output(['git', '-C', str(root), *args], stderr=subprocess.DEVNULL, timeout=5)
            if Path(git('rev-parse', '--show-toplevel').decode().strip()).resolve() != root:
                raise InventoryError('Source checkout identity does not match the inventory root.')
            commit = git('rev-parse', 'HEAD').decode().strip()
            if not re.fullmatch(r'[0-9a-f]{40}', commit):
                raise InventoryError('Source commit identity is unavailable.')
            dirty = bool(git('status', '--porcelain', '--untracked-files=no'))
            selected = [Path(p) for p in git('ls-files', '-z').decode('utf-8').split('\0') if p]
            selected = [p for p in selected if is_source_input(p)]
        except (OSError, subprocess.SubprocessError):
            raise InventoryError('Source index could not be verified.') from None
    if selected is None:
        selected = []
        def reject_walk_error(_error):
            raise InventoryError('A source directory could not be inspected.')
        for folder, directories, files in os.walk(root, followlinks=False, onerror=reject_walk_error):
            directories[:] = sorted(d for d in directories if d not in EXCLUDED and not Path(folder, d).is_symlink())
            relative_files = (Path(folder, name).relative_to(root) for name in files)
            selected.extend(p for p in relative_files if is_source_input(p))
    selected_locks = sorted(p for p in selected if p.name in LOCKS)
    locknames = {p.as_posix() for p in selected_locks}
    manifests_without_lock = sorted(p.as_posix() for p in selected if p.name in MANIFESTS
                                   and p.with_name(MANIFESTS[p.name]).as_posix() not in locknames)
    if not selected_locks or len(selected_locks) > 256:
        raise InventoryError('Supported source lockfile count is outside the inventory bound.')
    selected_release_inputs = sorted(p for p in selected if release_input_kind(p) is not None)
    if len(selected_release_inputs) > 256:
        raise InventoryError('Release input count exceeds the inventory bound.')
    total_bytes = 0

    def read_source(relative):
        nonlocal total_bytes
        if relative.is_absolute() or '..' in relative.parts:
            raise InventoryError('Invalid inventory source path.')
        path = root / relative
        if any((root / Path(*relative.parts[:i])).is_symlink() for i in range(1, len(relative.parts) + 1)) or not path.resolve().is_relative_to(root) or not path.is_file() or path.stat().st_size > 20 * 1024 * 1024:
            raise InventoryError('Unsafe or oversized inventory source.')
        raw = path.read_bytes()
        total_bytes += len(raw)
        if total_bytes > 64 * 1024 * 1024:
            raise InventoryError('Source bytes exceed the inventory bound.')
        digest = hashlib.sha256(raw).hexdigest()
        matches_commit = None
        if commit:
            try:
                ref = commit + ':' + relative.as_posix()
                size = int(git('cat-file', '-s', ref).decode().strip())
                matches_commit = size <= 20 * 1024 * 1024 and hashlib.sha256(git('show', ref)).hexdigest() == digest
            except (ValueError, OSError, subprocess.SubprocessError):
                matches_commit = False
        return raw, {'path': relative.as_posix(), 'sha256': digest, 'matches_source_commit': matches_commit}

    for relative in selected_locks:
        raw, evidence = read_source(relative)
        text = raw.decode('utf-8')
        lock = evidence['path']
        items = pub_components(text, lock) if relative.name == 'pubspec.lock' else json_components(text, lock, relative.name == 'composer.lock')
        locks.append({**evidence, 'component_count': len(items)})
        components.extend(items)
        if len(components) > 100000:
            raise InventoryError('Dependency component count exceeds the inventory bound.')

    release_inputs = []
    for relative in selected_release_inputs:
        _, evidence = read_source(relative)
        release_inputs.append({**evidence, 'kind': release_input_kind(relative)})

    components.sort(key=lambda c: (c['lockfile'], c['instance'], c['name'], c['version']))
    return {'schema': 'ev.dependency-inventory.v1', 'source_commit': commit, 'tracked_checkout_dirty': dirty,
            'file_selection': 'git_index' if commit else 'filesystem_real_directories',
            'tool_sha256': hashlib.sha256(Path(__file__).read_bytes()).hexdigest(),
            'lockfiles': sorted(locks, key=lambda v: v['path']),
            'release_inputs': release_inputs,
            'release_inputs_match_source_commit': all(v['matches_source_commit'] for v in release_inputs) if commit and release_inputs else None,
            'lockfiles_match_source_commit': all(v['matches_source_commit'] for v in locks) if commit else None, 'component_count': len(components),
            'components': components, 'manifests_without_inventory_lock': manifests_without_lock,
            'dependency_constraints_verified': False, 'components_without_license_declarations': sum(not c['declared_licenses'] for c in components),
            'license_approval': False, 'security_scan_performed': False, 'runtime_installation_verified': False,
            'production_accepted': False, 'scope': 'Lockfile declarations and release input hashes; URLs and credentials are never emitted and dependency scripts are never executed.'}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--root', type=Path, default=Path(__file__).resolve().parent.parent)
    parser.add_argument('--require-source-match', action='store_true',
                        help='Require a clean Git checkout with commit-matched locks and release inputs.')
    args = parser.parse_args()
    try:
        report = inventory(args.root)
    except (InventoryError, OSError, ValueError, TypeError, UnicodeError, RecursionError):
        print(json.dumps({'error': 'Dependency inventory rejected unsupported, malformed or unsafe lockfile data.'}), file=sys.stderr)
        return 2
    if args.require_source_match and (report['tracked_checkout_dirty'] is not False
                                      or report['lockfiles_match_source_commit'] is not True
                                      or report['release_inputs_match_source_commit'] is False):
        print(json.dumps({'error': 'Release inventory requires a clean Git checkout with commit-matched inputs.'}), file=sys.stderr)
        return 2
    print(json.dumps(report, sort_keys=True, indent=2))
    return 0


if __name__ == '__main__':
    sys.exit(main())
