#!/usr/bin/env python3
"""Run the existing suites sequentially against the disposable CI installation."""
import os
from pathlib import Path
import shutil
import socket
import subprocess
import sys
import time
import urllib.error
import urllib.parse
import urllib.request

REPOSITORY = Path(__file__).resolve().parents[1]
SERVER_SUITES = [
    "integration", "security-review", "auth-security", "native-capture",
    "duplicate-periods", "date-range", "icount", "source-mapping", "mapping-repair",
    "billing-exclusion", "catalog-cache", "duplicate-index", "read-cache", "core-access",
    "capture-retry", "bulk-rates",
]
BROWSER_SUITES = ["browser", "frontend-review", "settings-cache", "icount-frontend"]


def run_suite(name, command, environment, output):
    log = output / (name + ".txt")
    with log.open("w") as stream:
        result = subprocess.run(command, cwd=REPOSITORY, env=environment, stdout=stream, stderr=subprocess.STDOUT, timeout=240)
    lines = log.read_text(errors="replace").splitlines()
    if result.returncode:
        print("\n".join(lines[-35:]), flush=True)
        raise SystemExit(result.returncode)
    print("PASS", name, lines[-1] if lines else "", flush=True)


def main():
    mode = sys.argv[1] if len(sys.argv) == 2 else ""
    if mode not in ("php", "browser", "concurrency"):
        raise RuntimeError("Choose php, browser, or concurrency.")
    directory = Path(os.environ["LIMU_CI_DIR"]).resolve()
    wordpress = Path(os.environ["LIMU_WP_ROOT"]).resolve()
    if not (directory / ".limu-ci").is_file() or wordpress != directory / "wordpress":
        raise RuntimeError("Prepare the disposable CI installation first.")
    environment = os.environ.copy()
    environment.update(
        LIMU_TEST_LOGIN=str(directory / "test-login.json"),
        LIMU_MEMBER_LOGIN=str(directory / "member-login.json"),
        LIMU_AXE_PATH=str(REPOSITORY / "node_modules/axe-core/axe.min.js"),
        LIMU_SCREENSHOT_DIR=str(directory / "artifacts"),
    )
    output = directory / "artifacts"
    output.mkdir(exist_ok=True)
    php = environment.get("LIMU_PHP") or shutil.which("php")
    if not php:
        raise RuntimeError("PHP CLI is required.")
    environment["LIMU_PHP"] = php
    if mode == "php":
        for suite in SERVER_SUITES:
            path = REPOSITORY / "tests" / (suite + ".php")
            if not path.is_file():
                raise RuntimeError("Required server suite is missing: " + suite)
            run_suite(suite, [php, str(path)], environment, output)
        return
    if mode == "concurrency":
        run_suite("integration-before-concurrency", [php, "tests/integration.php"], environment, output)
        run_suite("concurrency", ["python3", "tests/concurrency.py"], environment, output)
        return

    base = environment.get("LIMU_TEST_URL", "http://127.0.0.1:8090")
    parsed = urllib.parse.urlparse(base)
    if parsed.scheme != "http" or parsed.hostname != "127.0.0.1" or not parsed.port or parsed.path or parsed.username or parsed.password or parsed.query or parsed.fragment:
        raise RuntimeError("Browser CI is restricted to loopback.")
    environment["LIMU_TEST_URL"] = base
    if not environment.get("LIMU_CHROMIUM"):
        browser_path = subprocess.check_output(["node", "-e", "process.stdout.write(require('playwright').chromium.executablePath())"], cwd=REPOSITORY, text=True)
        environment["LIMU_CHROMIUM"] = browser_path
    if not Path(environment["LIMU_CHROMIUM"]).is_file():
        raise RuntimeError("Install the Playwright Chromium browser before running CI.")
    with socket.socket() as probe:
        probe.bind(("127.0.0.1", parsed.port))
    with (output / "http-server.log").open("w") as server_log:
        server = subprocess.Popen([php, "-S", f"127.0.0.1:{parsed.port}", "-t", str(wordpress), str(directory / "router.php")], cwd=REPOSITORY, env=environment, stdout=server_log, stderr=subprocess.STDOUT)
        try:
            for _ in range(40):
                if server.poll() is not None:
                    raise RuntimeError("The CI HTTP server exited before readiness.")
                try:
                    with urllib.request.urlopen(base + "/crm/", timeout=1) as response:
                        html = response.read().decode("utf-8")
                        if response.status == 200 and 'id="user_login"' in html and 'id="user_pass"' in html:
                            break
                except (OSError, urllib.error.URLError):
                    pass
                time.sleep(0.25)
            else:
                raise RuntimeError("The CI CRM login did not become ready.")
            for suite in BROWSER_SUITES:
                run_suite(suite, ["node", f"tests/{suite}.cjs"], environment, output)
        finally:
            server.terminate()
            try:
                server.wait(timeout=5)
            except subprocess.TimeoutExpired:
                server.kill()
                server.wait()


if __name__ == "__main__":
    main()
