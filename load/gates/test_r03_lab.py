"""Read-only host qualification tests use synthetic sysfs/procfs and Docker output."""
import json
import subprocess
import tempfile
import unittest
from pathlib import Path

from r03_lab import observe_host


class R03LabTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        self.sys = self.root / 'sys'
        self.proc = self.root / 'proc'
        self.proc.mkdir()
        (self.proc / 'swaps').write_text('Filename Type Size Used Priority\n')
        self.containers = []
        self.stand = {'services': {}}
        for cpu, service in enumerate(['app', 'postgres', 'minio', 'k6']):
            folder = self.sys / f'devices/system/cpu/cpu{cpu}'
            (folder / 'cpufreq').mkdir(parents=True)
            (folder / 'cpufreq/scaling_governor').write_text('performance')
            (folder / 'topology').mkdir()
            (folder / 'topology/physical_package_id').write_text('0')
            (folder / 'topology/core_id').write_text(str(cpu))
            self.stand['services'][service] = {'cpuset': str(cpu), 'mem_limit': 100, 'memswap_limit': 100}
            if service != 'k6':
                self.containers.append(self.container(service, cpu))
        self.stand['services']['pgbouncer'] = self.stand['services']['app'].copy()
        self.containers.append(self.container('pgbouncer', 0))
        folder = self.sys / 'devices/system/cpu/intel_pstate'
        folder.mkdir()
        (folder / 'no_turbo').write_text('1')

    def container(self, service, cpu):
        return {'Id': service, 'Config': {'Env': ['SECRET=do-not-capture'], 'Labels': {
            'com.docker.compose.project': 'test', 'com.docker.compose.service': service}},
            'HostConfig': {'Memory': 100, 'MemorySwap': 100, 'CpusetCpus': str(cpu), 'CpuQuota': 0}}

    def observe(self):
        def run(cmd, **kwargs):
            output = '\n'.join(row['Id'] for row in self.containers) if cmd[1] == 'ps' else json.dumps(self.containers)
            return subprocess.CompletedProcess(cmd, 0, output, '')
        return observe_host('test', self.stand, self.sys, self.proc, run)

    def test_current_observations_can_admit_without_exposing_environment(self):
        result = self.observe()
        self.assertTrue(all(row['ok'] for row in result.values()))
        self.assertNotIn('do-not-capture', json.dumps(result))

    def test_powersave_turbo_swap_and_foreign_stack_each_reject(self):
        (self.sys / 'devices/system/cpu/cpu0/cpufreq/scaling_governor').write_text('powersave')
        self.assertFalse(self.observe()['L-1']['ok'])
        (self.sys / 'devices/system/cpu/cpu0/cpufreq/scaling_governor').write_text('performance')
        (self.sys / 'devices/system/cpu/intel_pstate/no_turbo').write_text('0')
        self.assertFalse(self.observe()['L-1']['ok'])
        (self.proc / 'swaps').write_text('Filename Type Size Used Priority\n/swapfile file 1024 0 -2\n')
        self.assertFalse(self.observe()['L-5']['ok'])
        foreign = self.container('foreign', 10)
        foreign['Config']['Labels']['com.docker.compose.project'] = 'other'
        self.containers.append(foreign)
        self.assertFalse(self.observe()['L-3']['ok'])

    def test_physical_core_overlap_or_actual_cpuset_drift_reject(self):
        (self.sys / 'devices/system/cpu/cpu3/topology/core_id').write_text('0')
        self.assertFalse(self.observe()['L-2']['ok'])
        (self.sys / 'devices/system/cpu/cpu3/topology/core_id').write_text('3')
        self.containers[0]['HostConfig']['CpusetCpus'] = '3'
        self.assertFalse(self.observe()['L-2']['ok'])

    def test_generator_swap_and_unobserved_host_reject(self):
        self.stand['services']['k6']['memswap_limit'] = 200
        self.assertFalse(self.observe()['L-5']['ok'])
        self.containers.clear()
        result = self.observe()
        self.assertFalse(result['L-2']['ok'])
        self.assertFalse(result['L-5']['ok'])


if __name__ == '__main__':
    unittest.main()
