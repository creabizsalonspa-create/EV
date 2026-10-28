"""Host-independent checks for the simulator retry safety boundary."""
import importlib.util
import unittest
from importlib.machinery import SourceFileLoader
from pathlib import Path

loader = SourceFileLoader('ios_ci', str(Path(__file__).with_name('test-ios-ci')))
spec = importlib.util.spec_from_loader(loader.name, loader)
module = importlib.util.module_from_spec(spec)
loader.exec_module(module)


class SimulatorBoundaryTest(unittest.TestCase):
    startup = 'Xcode build done.\nWaiting for VM Service port to be available...'

    def test_only_pre_dart_vm_startup_is_retryable(self):
        self.assertTrue(module.may_recover_startup(124, self.startup))
        self.assertFalse(module.may_recover_startup(0, self.startup))
        self.assertFalse(module.may_recover_startup(1, 'Xcode build failed'))
        for marker in ('DEVICE: clearing isolated Keychain entry',
                       'Connecting to service protocol: synthetic',
                       'TestFailure', 'Expected:', 'Some tests failed'):
            self.assertFalse(module.may_recover_startup(124, self.startup + marker))

    def test_a_zero_exit_without_device_assertions_is_not_success(self):
        self.assertFalse(module.verified_success(0, 'All tests passed!'))
        proof = ('All tests passed!\nDEVICE: Keychain session restored\n'
                 'DEVICE: exact Arabic vendor pricing verified\n'
                 'DEVICE: native vendor onboarding and private PDF preview verified\n'
                 'DEVICE: customer favorites and private catalog status verified\n'
                 'DEVICE: customer map privacy consent navigation verified; no external tiles\n'
                 'DEVICE: consented one-shot GPS and sourced charging-stop route verified; synthetic platform/provider')
        self.assertFalse(module.verified_success(0, proof.replace('DEVICE: native vendor onboarding and private PDF preview verified', '')))
        self.assertFalse(module.verified_success(0, proof.replace('DEVICE: customer favorites and private catalog status verified', '')))
        self.assertFalse(module.verified_success(0, proof.replace('DEVICE: customer map privacy consent navigation verified; no external tiles', '')))
        self.assertFalse(module.verified_success(0, proof.replace('DEVICE: consented one-shot GPS and sourced charging-stop route verified; synthetic platform/provider', '')))
        self.assertTrue(module.verified_success(0, proof))
        self.assertFalse(module.verified_success(1, proof))

    def test_only_this_ci_runs_named_simulator_can_be_rebooted(self):
        device = '11111111-1111-1111-1111-111111111111'
        devices = {'devices': {'runtime': [{'udid': device, 'name': 'CreaBiz-123-1'}]}}
        self.assertTrue(module.owned_simulator(devices, device, '123', '1'))
        self.assertFalse(module.owned_simulator(devices, device, '456', '1'))
        self.assertFalse(module.owned_simulator(devices, device, '123', '2'))
        self.assertFalse(module.owned_simulator(devices, device, '', '1'))
        self.assertFalse(module.owned_simulator(devices, 'foreign', '123', '1'))


if __name__ == '__main__':
    unittest.main()
