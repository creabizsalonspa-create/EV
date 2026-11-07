#!/usr/bin/env python3
"""Offline, read-only staging configuration gate; never prints secret values."""

import argparse
import base64
import binascii
import ipaddress
import os
from pathlib import Path
import re
import stat
import sys


class PreflightError(ValueError):
    pass


def parse_environment(path: Path) -> dict[str, str]:
    if path.is_symlink() or not path.is_file():
        raise PreflightError("Staging .env must be a regular file, not a symlink.")
    if os.name == "posix" and (stat.S_IMODE(path.stat().st_mode) & 0o077):
        raise PreflightError("Staging .env must not be accessible by group or others (chmod 600).")
    values: dict[str, str] = {}
    for number, line in enumerate(path.read_text(encoding="utf-8").splitlines(), 1):
        line = line.strip()
        if not line or line.startswith("#"):
            continue
        match = re.fullmatch(r"(?:export\s+)?([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*)", line)
        if not match:
            raise PreflightError(f"Invalid .env assignment at line {number}.")
        key, value = match.groups()
        if key in values:
            raise PreflightError(f"Duplicate .env key: {key}.")
        if value.startswith(('"', "'")):
            if len(value) < 2 or value[-1] != value[0]:
                raise PreflightError(f"Invalid quoted value for {key}.")
            value = value[1:-1]
        values[key] = value
    return values


def validate(values: dict[str, str], allow_public_bind: bool = False) -> None:
    for key in ("APP_KEY", "STAGING_HOST", "ACME_EMAIL", "DB_PASSWORD"):
        if not values.get(key):
            raise PreflightError(f"Missing required staging setting: {key}.")

    key = values["APP_KEY"]
    if not key.startswith("base64:"):
        raise PreflightError("APP_KEY must use Laravel base64: format.")
    try:
        decoded = base64.b64decode(key[7:], validate=True)
    except (binascii.Error, ValueError) as exc:
        raise PreflightError("APP_KEY is not valid base64.") from exc
    if len(decoded) != 32:
        raise PreflightError("APP_KEY must contain exactly 32 decoded bytes.")

    if len(values["DB_PASSWORD"]) < 24 or any(x in values["DB_PASSWORD"].lower() for x in ("changeme", "password", "example")):
        raise PreflightError("DB_PASSWORD must be a non-example secret of at least 24 characters.")

    host = values["STAGING_HOST"].lower()
    domain = r"(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z](?:[a-z0-9-]{0,61}[a-z0-9])?"
    if len(host) > 253 or not re.fullmatch(domain, host) or host.endswith((".local", ".localhost", ".invalid", ".test", ".example")):
        raise PreflightError("STAGING_HOST must be an authorized public DNS hostname without scheme or port.")
    if not re.fullmatch(r"[^\s@]+@[^\s@]+\.[A-Za-z]{2,}", values["ACME_EMAIL"]):
        raise PreflightError("ACME_EMAIL must be a valid certificate contact address.")

    bind = values.get("STAGING_BIND_IP", "127.0.0.1")
    try:
        address = ipaddress.ip_address(bind)
    except ValueError as exc:
        raise PreflightError("STAGING_BIND_IP must be an IP address.") from exc
    if not address.is_loopback and not allow_public_bind:
        raise PreflightError("Refusing non-loopback staging gateway bind without --allow-public-bind.")
    tag = values.get("EV_IMAGE_TAG", "local")
    if not re.fullmatch(r"[A-Za-z0-9][A-Za-z0-9_.-]{0,127}", tag):
        raise PreflightError("EV_IMAGE_TAG must be a single safe image-tag component.")


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--env", type=Path, default=Path("infra/staging/.env"))
    parser.add_argument("--allow-public-bind", action="store_true", help="Explicitly acknowledge an authorized non-loopback staging gateway.")
    args = parser.parse_args(argv)
    try:
        validate(parse_environment(args.env), allow_public_bind=args.allow_public_bind)
    except (PreflightError, OSError, UnicodeError) as exc:
        # OS messages may embed the .env path, but no credential values.
        print(f"FAIL: staging preflight: {exc}", file=sys.stderr)
        return 1
    print("PASS: staging .env syntax, privacy, Laravel key, DB secret, host, image tag and gateway binding validated offline; no deployment performed.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
