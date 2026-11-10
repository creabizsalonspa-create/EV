#!/usr/bin/env python3
"""Source-bound offline developer handoff for shared EV customer/vendor native app."""
import argparse
import sys
import tarfile
import zipfile

from delivery_bundle import BundleError
from delivery_workflow_gate import package_from_workflow


def main(argv=None):
    parser = argparse.ArgumentParser(description='Verify shared EV native developer artifacts against GitHub workflow/source, never production')
    parser.add_argument('--output', required=True)
    parser.add_argument('--source-sha', required=True)
    parser.add_argument('--run-id', type=int, required=True)
    parser.add_argument('--run-json', required=True)
    parser.add_argument('--artifacts-json', required=True)
    parser.add_argument('--android-wrapper', required=True)
    parser.add_argument('--ios-wrapper', required=True)
    args = parser.parse_args(argv)
    try:
        package_from_workflow(
            args.output, args.source_sha, args.run_id,
            args.run_json, args.artifacts_json,
            args.android_wrapper, args.ios_wrapper,
            profile='ev_shared_native',
        )
    except (BundleError, OSError, ValueError, EOFError, RuntimeError,
            tarfile.TarError, zipfile.BadZipFile):
        print('FAIL: shared EV native developer artifacts are not source/workflow verified; no private details printed.', file=sys.stderr)
        return 1
    print('PASS: shared EV customer/vendor developer artifacts match declared workflow and source; no stage binding, device signing, hardware, payments or production acceptance.')
    return 0


if __name__ == '__main__':
    sys.exit(main())
