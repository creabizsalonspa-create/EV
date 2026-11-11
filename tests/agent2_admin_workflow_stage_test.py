"""Isolated Next patch-contract checks; not a Next build/browser substitute."""
import importlib.util
from pathlib import Path
import shutil
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
REFERENCE = ROOT / 'integrations/hasone-next-admin'
FILES = ['OcppBookingWorkflow.tsx', 'ocppWorkflowEvidence.mjs', 'ocppWorkflowEvidence.d.mts']
spec = importlib.util.spec_from_file_location('agent2_admin_stage', ROOT / 'tools/agent2/stage_next_ocpp_booking_workflow.py')
stager = importlib.util.module_from_spec(spec)
spec.loader.exec_module(stager)
SOURCE = '''import { PageLoader } from "@/components/PageLoader";
type Booking={
  isPaid: boolean; status: string; createdAt: string;
};
const headings=["Station","Vehicle","Date","Time","Amount","Paid","Status","Created"];
const loading=<td colSpan={8}>loading</td>;
const error=<td colSpan={8}>error</td>;
const empty=<td colSpan={8}>empty</td>;
const date=<td>{new Date(b.createdAt).toLocaleDateString()}</td>;
const amount=<td>${(b.totalAmount || 0).toFixed(2)}</td>;
'''


class AdminWorkflowStagingTest(unittest.TestCase):
    def setUp(self):
        self.directory = tempfile.TemporaryDirectory(prefix='agent2-admin-stage-')
        self.addCleanup(self.directory.cleanup)
        self.root = Path(self.directory.name)
        self.stage = self.root / 'next'
        self.page = self.stage / 'app/(admin)/bookings/page.tsx'
        self.page.parent.mkdir(parents=True)
        (self.stage / 'components').mkdir()
        self.page.write_text(SOURCE)
        self.reference = self.root / 'integrations/hasone-next-admin'
        self.reference.mkdir(parents=True)
        for name in FILES:
            shutil.copyfile(REFERENCE / name, self.reference / name)
        self.old_file = stager.__file__
        stager.__file__ = str(self.root / 'tools/agent2/stage_next_ocpp_booking_workflow.py')
        self.addCleanup(setattr, stager, '__file__', self.old_file)

    def snapshot(self):
        return {str(p.relative_to(self.stage)): p.read_bytes() for p in self.stage.rglob('*') if p.is_file()}

    def test_stages_exact_component_runtime_helper_and_declaration(self):
        stager.patch(self.stage)
        for name in FILES:
            self.assertEqual((self.reference / name).read_bytes(), (self.stage / 'components' / name).read_bytes())
        text = self.page.read_text()
        self.assertIn('import OcppBookingWorkflow', text)
        self.assertIn('booking={b} onUpdated={fetchBookings}', text)
        self.assertIn('>AED ', text)

    def test_missing_runtime_or_type_helper_is_refused_before_writes(self):
        for name in FILES[1:]:
            path = self.reference / name
            original = path.read_bytes()
            path.unlink()
            before = self.snapshot()
            with self.assertRaises(RuntimeError):
                stager.patch(self.stage)
            self.assertEqual(before, self.snapshot())
            path.write_bytes(original)

    def test_existing_helper_is_not_overwritten(self):
        helper = self.stage / 'components/ocppWorkflowEvidence.mjs'
        helper.write_text('// existing integration work\n')
        before = self.snapshot()
        with self.assertRaises(RuntimeError):
            stager.patch(self.stage)
        self.assertEqual(before, self.snapshot())

    def test_repeat_refuses_without_modifying_source(self):
        stager.patch(self.stage)
        before = self.snapshot()
        with self.assertRaises(RuntimeError):
            stager.patch(self.stage)
        self.assertEqual(before, self.snapshot())

    def test_late_source_anchor_failure_leaves_no_partial_helper_or_page(self):
        self.page.write_text(SOURCE.replace('>${', '>USD {'))
        before = self.snapshot()
        with self.assertRaises(RuntimeError):
            stager.patch(self.stage)
        self.assertEqual(before, self.snapshot())

    def test_production_paths_are_refused_before_reading(self):
        with self.assertRaisesRegex(RuntimeError, 'Refusing live'):
            stager.patch(Path('/home/hasio/ev-deployment/admin-password-rotation'))


if __name__ == '__main__':
    unittest.main()
