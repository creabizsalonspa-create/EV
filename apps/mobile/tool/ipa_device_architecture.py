"""Inspect the main signed iPhoneOS executable's Mach-O device platform."""
import plistlib
import struct

from audit_ipa_package import require

ARM64 = 0x0100000C
MH_EXECUTE = 2
LC_BUILD_VERSION = 0x32
PLATFORM_IOS = 2
MAX_COMMAND_BYTES = 16 * 1024 * 1024


def device_architecture(app):
    metadata = plistlib.loads((app / 'Info.plist').read_bytes())
    executable = app / metadata['CFBundleExecutable']
    with executable.open('rb') as stream:
        header = stream.read(32)
        require(len(header) == 32 and header[:4] == b'\xcf\xfa\xed\xfe',
                'Expected thin little-endian 64-bit device executable; fat binaries require separate review')
        _, cpu, subtype, kind, count, size, _, reserved = struct.unpack('<8I', header)
        require(cpu == ARM64 and (subtype & 0xffffff) in (0, 2) and kind == MH_EXECUTE and reserved == 0,
                'Main executable is not ARM64 iOS application code')
        require(0 < count <= 10000 and count * 8 <= size <= MAX_COMMAND_BYTES
                and size <= executable.stat().st_size - 32, 'Invalid Mach-O load-command table')
        commands = stream.read(size)
        require(len(commands) == size, 'Truncated Mach-O load-command table')
    offset = 0
    versions = []
    for _ in range(count):
        require(offset + 8 <= size, 'Truncated Mach-O command')
        command, length = struct.unpack_from('<2I', commands, offset)
        require(length >= 8 and length % 8 == 0 and offset + length <= size,
                'Invalid Mach-O command size')
        if command == LC_BUILD_VERSION:
            require(length >= 24, 'Truncated Mach-O build version')
            platform, minimum, sdk, tools = struct.unpack_from('<4I', commands, offset + 8)
            require(length == 24 + tools * 8 and platform == PLATFORM_IOS
                    and 0 < minimum <= sdk, 'Main executable does not target physical iOS')
            versions.append((minimum, sdk))
        # Do not accept ambiguous legacy platform commands alongside modern evidence.
        require(command not in (0x24, 0x25, 0x2f, 0x30), 'Legacy Mach-O platform command requires separate review')
        offset += length
    require(offset == size and len(versions) == 1, 'Missing or ambiguous iOS build platform')
    minimum, sdk = versions[0]
    def version(value):
        return f'{value >> 16}.{(value >> 8) & 255}.{value & 255}'
    declared = tuple(int(part) for part in metadata['MinimumOSVersion'].split('.'))
    declared += (0,) * (3 - len(declared))
    require(tuple(int(part) for part in version(minimum).split('.')) <= declared,
            'Main executable minimum exceeds declared minimum iOS version')
    return {'main_executable_device_architecture_verified': True,
            'main_executable_architecture': 'arm64e' if (subtype & 0xffffff) == 2 else 'arm64',
            'main_executable_platform': 'iOS', 'main_executable_minimum_ios': version(minimum),
            'main_executable_sdk': version(sdk), 'nested_executable_architectures_verified': False}
