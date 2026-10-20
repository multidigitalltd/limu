#!/usr/bin/env python3
"""Prepare a disposable CI installation without reading production configuration."""
import hashlib
import json
import os
from pathlib import Path, PurePosixPath
import re
import secrets
import stat
import urllib.parse
import urllib.request
import zipfile

VERSION = "7.1.3"
ARCHIVE_SHA256 = "eb58a90ed55147f90f7bd55d0d3334960a69b7e8a4cc142c8ddfea8965218227"
REPOSITORY = Path(__file__).resolve().parents[1]


def php_string(value):
    return "'" + value.replace("\\", "\\\\").replace("'", "\\'") + "'"


def prepare():
    location = os.environ.get("LIMU_CI_DIR")
    if not location:
        raise RuntimeError("Set LIMU_CI_DIR to a new directory under RUNNER_TEMP or /tmp.")
    directory = Path(location).resolve()
    temporary = Path(os.environ.get("RUNNER_TEMP", "/tmp")).resolve()
    if directory == temporary or temporary not in directory.parents or REPOSITORY in directory.parents:
        raise RuntimeError("The disposable CI directory must be outside the checkout and inside the temporary directory.")
    if directory.exists() and any(directory.iterdir()):
        raise RuntimeError("Refusing to overwrite an existing CI installation.")
    directory.mkdir(parents=True, exist_ok=True)
    directory.chmod(0o700)
    (directory / "artifacts").mkdir()
    home = os.environ.get("LIMU_TEST_URL", "http://127.0.0.1:8090")
    parsed = urllib.parse.urlparse(home)
    if parsed.scheme != "http" or parsed.hostname != "127.0.0.1" or not parsed.port or parsed.path or parsed.username or parsed.password or parsed.query or parsed.fragment:
        raise RuntimeError("CI HTTP must use an explicit loopback port.")
    if os.environ.get("LIMU_DB_NAME", "limu_test") != "limu_test":
        raise RuntimeError("The only supported CI database is limu_test.")
    prefix = os.environ.get("LIMU_DB_PREFIX", "test_")
    if not re.fullmatch(r"[A-Za-z][A-Za-z0-9_]{0,30}_", prefix):
        raise RuntimeError("The CI table prefix must be 2-32 safe ASCII characters ending in an underscore.")

    archive = directory / f"wordpress-{VERSION}.zip"
    url = f"https://downloads.wordpress.org/release/wordpress-{VERSION}.zip"
    digest = hashlib.sha256()
    size = 0
    with urllib.request.urlopen(url, timeout=60) as response, archive.open("wb") as output:
        while True:
            chunk = response.read(1024 * 1024)
            if not chunk:
                break
            size += len(chunk)
            if size > 64 * 1024 * 1024:
                raise RuntimeError("Unexpected WordPress archive size.")
            output.write(chunk)
            digest.update(chunk)
    if digest.hexdigest() != ARCHIVE_SHA256:
        raise RuntimeError("The WordPress archive does not match its verified SHA-256.")
    with zipfile.ZipFile(archive) as bundle:
        entries = bundle.infolist()
        if len(entries) > 15000 or sum(item.file_size for item in entries) > 250 * 1024 * 1024:
            raise RuntimeError("Unexpected WordPress archive contents.")
        for entry in entries:
            name = PurePosixPath(entry.filename)
            if name.is_absolute() or ".." in name.parts or not name.parts or name.parts[0] != "wordpress" or "\\" in entry.filename:
                raise RuntimeError("Unsafe archive path.")
            if stat.S_ISLNK(entry.external_attr >> 16):
                raise RuntimeError("Archive symlinks are not allowed.")
        bundle.extractall(directory)
    archive.unlink()
    wordpress = directory / "wordpress"
    values = {
        "DB_NAME": "limu_test",
        "DB_USER": os.environ.get("LIMU_DB_USER", "limu_test"),
        "DB_PASSWORD": os.environ.get("LIMU_DB_PASSWORD", "limu_test"),
        "DB_HOST": os.environ.get("LIMU_DB_HOST", "127.0.0.1:3306"),
        "DB_CHARSET": "utf8mb4",
        "DB_COLLATE": "",
        "WP_HOME": home,
        "WP_SITEURL": home,
    }
    values.update({key: secrets.token_hex(48) for key in [
        "AUTH_KEY", "SECURE_AUTH_KEY", "LOGGED_IN_KEY", "NONCE_KEY",
        "AUTH_SALT", "SECURE_AUTH_SALT", "LOGGED_IN_SALT", "NONCE_SALT",
    ]})
    config = "<?php\n"
    config += "".join(f"define( '{key}', {php_string(value)} );\n" for key, value in values.items())
    config += "$table_prefix = " + php_string(prefix) + ";\n"
    config += "define( 'WP_DEBUG', true );\ndefine( 'WP_DEBUG_DISPLAY', false );\n"
    config += "define( 'WP_DEBUG_LOG', " + php_string(str(directory / "artifacts/php-errors.log")) + " );\n"
    config += "define( 'DISABLE_WP_CRON', true );\ndefine( 'AUTOMATIC_UPDATER_DISABLED', true );\n"
    config += "define( 'WP_AUTO_UPDATE_CORE', false );\ndefine( 'WP_HTTP_BLOCK_EXTERNAL', true );\n"
    config += "define( 'DISALLOW_FILE_EDIT', true );\ndefine( 'ABSPATH', __DIR__ . '/' );\n"
    config += "require_once ABSPATH . 'wp-settings.php';\n"
    (wordpress / "wp-config.php").write_text(config)
    (wordpress / "wp-config.php").chmod(0o600)
    (wordpress / "wp-content/plugins/limu-crm").symlink_to(REPOSITORY / "limu-crm", target_is_directory=True)
    must_use = wordpress / "wp-content/mu-plugins"
    must_use.mkdir(exist_ok=True)
    (must_use / "ci-no-mail.php").write_text(
        "<?php\n// Test infrastructure only; no emails may leave CI.\n"
        "add_filter( 'pre_wp_mail', '__return_true', PHP_INT_MAX );\n"
    )
    (directory / "router.php").write_text(
        "<?php\n$root = __DIR__ . '/wordpress';\n"
        "$path = parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH );\n"
        "if ( is_file( $root . $path ) ) { return false; }\n"
        "require $root . '/index.php';\n"
    )
    (directory / ".limu-ci").write_text("limu_test\n")
    print(f"Verified WordPress {VERSION}; disposable files prepared outside the checkout.")


if __name__ == "__main__":
    prepare()
