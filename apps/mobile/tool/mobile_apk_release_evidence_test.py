"""Run root APK audit regressions in the existing native CI test discovery."""
import importlib.util
from pathlib import Path
import sys
import unittest

ROOT = Path(__file__).resolve().parents[3]
FILES = ('mobile_apk_audit_test.py', 'mobile_apk_evidence_test.py')


def load_tests(loader, standard_tests, pattern):
    suite = unittest.TestSuite()
    sys.path.insert(0, str(ROOT / 'scripts'))
    try:
        for filename in FILES:
            path = ROOT / 'tests' / filename
            if not path.is_file():
                raise RuntimeError('Missing required APK regression suite: ' + filename)
            spec = importlib.util.spec_from_file_location('_native_ci_' + path.stem, path)
            module = importlib.util.module_from_spec(spec)
            spec.loader.exec_module(module)
            tests = loader.loadTestsFromModule(module)
            if tests.countTestCases() == 0:
                raise RuntimeError('Empty required APK regression suite: ' + filename)
            suite.addTests(tests)
    finally:
        sys.path.remove(str(ROOT / 'scripts'))
    return suite


if __name__ == '__main__':
    unittest.main()
