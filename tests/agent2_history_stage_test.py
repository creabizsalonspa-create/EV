"""Source-contract checks for shipping the real history page and its guards."""
import importlib.util
from pathlib import Path
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('history_stage', ROOT / 'tools/agent2/stage_next_ocpp_history.py')
patcher = importlib.util.module_from_spec(spec)
spec.loader.exec_module(patcher)


class HistoryStageTest(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix='agent2-history-stage-')
        self.addCleanup(self.temporary.cleanup)
        self.root = Path(self.temporary.name)
        self.sidebar = self.root / 'components/Sidebar.tsx'
        self.sidebar.parent.mkdir()
        self.original = 'const items=[\n  { href: "/ocpp-sessions",   label: "OCPP Sessions",   icon: Zap },\n];\n'
        self.sidebar.write_text(self.original)
        self.target = self.root / 'app/(admin)/ocpp-history/page.tsx'

    def test_installs_unchanged_component_and_both_evidence_dependencies(self):
        patcher.patch(self.root)
        source = ROOT / 'integrations/hasone-next-admin'
        for installed, name in [(self.target, 'ocpp-history-page.tsx'),
                                (self.target.parent / 'ocppHistoryEvidence.mjs', 'ocppHistoryEvidence.mjs'),
                                (self.target.parent / 'ocppHistoryEvidence.d.mts', 'ocppHistoryEvidence.d.mts')]:
            self.assertEqual(installed.read_bytes(), (source / name).read_bytes())
        self.assertEqual(self.sidebar.read_text().count('href: "/ocpp-history"'), 1)

    def test_conflicting_dependency_refuses_before_page_or_sidebar_writes(self):
        self.target.parent.mkdir(parents=True)
        dependency = self.target.parent / 'ocppHistoryEvidence.mjs'
        dependency.write_text('// unreviewed existing dependency\n')
        with self.assertRaises(RuntimeError):
            patcher.patch(self.root)
        self.assertFalse(self.target.exists())
        self.assertEqual(self.sidebar.read_text(), self.original)
        self.assertEqual(dependency.read_text(), '// unreviewed existing dependency\n')

    def test_repeat_or_changed_sidebar_cannot_duplicate_or_overwrite(self):
        self.sidebar.write_text('const items=[];\n')
        with self.assertRaises(RuntimeError):
            patcher.patch(self.root)
        self.assertFalse(self.target.exists())
        self.assertEqual(self.sidebar.read_text(), 'const items=[];\n')
        self.sidebar.write_text(self.original)
        patcher.patch(self.root)
        before = self.target.read_bytes(), self.sidebar.read_bytes()
        with self.assertRaises(RuntimeError):
            patcher.patch(self.root)
        self.assertEqual((self.target.read_bytes(), self.sidebar.read_bytes()), before)


if __name__ == '__main__':
    unittest.main()
