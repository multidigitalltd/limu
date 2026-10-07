"""Run only after integration.php, with the same disposable test database."""
import os
import pathlib
import subprocess

php = os.environ.get('LIMU_PHP', 'php')
probe = pathlib.Path(__file__).with_name('concurrent-payment.php')
workers = [subprocess.Popen([php, str(probe)], stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True) for _ in range(2)]
results = [worker.communicate(timeout=30) for worker in workers]
assert all(worker.returncode == 0 for worker in workers), 'Concurrent operation failed'
assert results[0][0].strip().isdigit() and results[0][0] == results[1][0], 'Replay created multiple payments'
subprocess.run([php, str(probe), '--verify'], check=True, timeout=30)
print('PASS Concurrent requests returned one payment and changed the balance once.')
