#!/usr/bin/env python3
"""Publish verified installers only from an explicitly requested main-branch CI run."""
import argparse
import hashlib
import json
import os
from pathlib import Path
import re
import subprocess
import tempfile
from urllib.parse import quote
import zipfile

REPOSITORY = Path(__file__).resolve().parents[1]
PROJECT = "multidigitalltd/limu"
LEGACY_COMMIT = "1a7a5587b2bcc75c5c0c4ffaee0303bd355ea78b"
# Version, original commit, and previously published installer checksum.
LEGACY = [
    ("0.1.0", "550279c6272d3cb6d3a69de80600694cdd8537b4", "ece58977e517bd15120dd4113bb73f0e0cb90a3d15cab1e201b15e032a84f0ba"),
    ("0.1.1", "a25796cbbac994124216f07b347bc8155f030add", "2062c958d21fbbfa2f70664efd7c04a83d9da41aa2be839ff7c4be8001ef7e42"),
    ("0.1.2", "c31917795d0ad75eab6bc7e2205183f9e1d01fce", "10aae6036df206948424a2c75c878d99624d6c4f6a16c28f06206ffab4a0520f"),
    ("0.1.3", "55ae0b8917b5e4c54e53a8f2c24008faa437113e", "f88449c007c941f1322b4c9cda81abe33d77762deda88def5d1941b856c2fd7f"),
    ("0.1.4", "99cc9aa733048de6f2d8515b9a67946a3b6fbaf1", "931083a48bfc27235fcb9a1b13b18cf49bbed1647ee1de47916e077d98938014"),
    ("0.1.5", "337a849cf36cc18489550ce41930d11f401e72c8", "f2cd63784f38ccf70fdbb9693286ad9836f066a5e92a999828bd248381d3eaf1"),
    ("0.1.6", "4a853532f71a9c7d7e9753c145e7d8dc53ce4d22", "5d674f3108cd6aec59e11cb513967300710de37ac8189f255e9024c250e25ce7"),
    ("0.1.7", "33e077f2505c5c22ccc164b28da8bb233f5342fa", "70b5086b182a9996e856b3f8274fb46748d0bb406cceaecabc9be39c337ddc5b"),
    ("0.1.8", LEGACY_COMMIT, "a528c2c5b86ef4789c08eb9223b2d3535153a5fb60b3b319332905caf3b2c530"),
]


def run(arguments):
    return subprocess.check_output(arguments, cwd=REPOSITORY, text=True)


def digest(path):
    return hashlib.sha256(path.read_bytes()).hexdigest()


def require(condition, message):
    if not condition:
        raise RuntimeError(message)


def blob(path):
    return subprocess.check_output(["git", "show", f"{LEGACY_COMMIT}:{path}"], cwd=REPOSITORY)


def api(path, missing_ok=False, method="GET", payload=None, upload=None):
    arguments = ["gh", "api", path, "--method", method, "--header", "Cache-Control: no-cache"]
    body = None
    if payload is not None:
        arguments.extend(["--input", "-"])
        body = json.dumps(payload)
    if upload is not None:
        arguments.extend(["--header", "Content-Type: application/octet-stream", "--input", str(upload)])
    result = subprocess.run(arguments, input=body, cwd=REPOSITORY, text=True, capture_output=True)
    if result.returncode:
        if missing_ok and "HTTP 404" in result.stderr:
            return None
        raise RuntimeError("GitHub API request failed: " + result.stderr[-500:])
    return json.loads(result.stdout)


def release_by_tag(tag):
    remote = api(f"repos/{PROJECT}/releases/tags/{tag}", True)
    if remote is not None:
        return remote
    # The tag endpoint can hide drafts even from their authenticated owner.
    candidates = [release for release in api(f"repos/{PROJECT}/releases?per_page=100") if release["tag_name"] == tag]
    require(len(candidates) <= 1, "Ambiguous release drafts; refusing to choose one.")
    return candidates[0] if candidates else None


def specification(version, target, directory, names, legacy):
    assets = [{"name": name, "bytes": (directory / name).stat().st_size, "sha256": digest(directory / name)} for name in names]
    checksums = directory / "SHA256SUMS"
    checksums.write_text("".join(f"{asset['sha256']}  {asset['name']}\n" for asset in assets))
    assets.append({"name": "SHA256SUMS", "bytes": checksums.stat().st_size, "sha256": digest(checksums)})
    marker = f"<!-- limu-crm-release commit={target} legacy={str(legacy).lower()} -->"
    if legacy:
        notes = f"גרסת ארכיון {version}, מהקומיט המקורי `{target}`.\n\nהחבילה נשמרה ללא שינוי בבייטים ובחתימה לצורך הצלבה ושחזור היסטוריים. זו אינה הגרסה העדכנית והיא אינה כוללת תיקונים מגרסאות מאוחרות יותר. להתקנה שוטפת יש לבחור בגרסה המתוחזקת החדשה.\n\nחתימות הקבצים מצורפות ב־`SHA256SUMS`.\n"
        if version == "0.1.8":
            notes += "\nמצורפים גם ZIP התיעוד המקורי ו־`limu-crm-evidence-through-0.1.8.zip`: בדיוק 140 קובצי ראיות מהקומיט המקורי, בנתיבי `artifacts/` ההיסטוריים. הלוגים, המדידות המצרפיות וצילומי נתוני הדמה אינם כוללים מסד פונים או אישורי גישה.\n"
    else:
        notes = f"חבילת CRM {version}, מהקומיט `{target}`.\n\nהחבילה עברה את בדיקות CI על PHP 7.4.33 ועל PHP 8.4 ונבדקה מחדש מול קובצי ההפעלה של אותו קומיט. חתימת SHA-256 מצורפת ב־`SHA256SUMS`. חיבור iCount נבדק עם ספק מדומה בלבד.\n\n[תיאור השינויים וההתקנה](https://github.com/{PROJECT}/blob/{target}/README.md)\n"
    notes += "\n" + marker + "\n"
    (directory / "notes.md").write_text(notes)
    return {"version": version, "tag": "crm-v" + version, "target_commit": target, "legacy": legacy, "directory": str(directory), "marker": marker, "title": f"crm {version}" + (" (legacy archive)" if legacy else ""), "assets": assets, "verified": False}


def current(output, package):
    version = json.loads((REPOSITORY / "package.json").read_text())["version"]
    require(re.fullmatch(r"\d+\.\d+\.\d+", version), "Invalid current version.")
    name = f"limu-crm-{version}.zip"
    require({p.name for p in package.iterdir()} == {name, "SHA256SUMS"}, "Unexpected tested package contents.")
    require(all(p.is_file() and not p.is_symlink() for p in package.iterdir()), "Package must contain regular files.")
    expected = digest(package / name) + "  " + name + "\n"
    require((package / "SHA256SUMS").read_text() == expected, "Tested installer checksum mismatch.")
    rebuilt = output / "rebuilt-current"
    run(["node", "scripts/package.mjs", "--output", str(rebuilt)])
    require(digest(rebuilt / name) == digest(package / name), "Tested installer differs from the checked-out runtime.")
    target = run(["git", "rev-parse", "HEAD"]).strip()
    return [specification(version, target, rebuilt, [name], False)]


def legacy(output):
    manifest = dict(line.split()[::-1] for line in blob("downloads/SHA256SUMS").decode().splitlines())
    paths = run(["git", "ls-tree", "-r", "--name-only", LEGACY_COMMIT, "--", "artifacts"]).splitlines()
    require(len(paths) == 140 and all(p.startswith("artifacts/") and ".." not in Path(p).parts for p in paths), "Unexpected legacy evidence manifest.")
    evidence = {name: blob(name) for name in paths}
    require(sum(len(content) for content in evidence.values()) == 789803, "Unexpected legacy evidence bytes.")
    releases = []
    for version, target, expected in LEGACY:
        directory = output / version
        directory.mkdir(exist_ok=True)
        name = f"limu-crm-{version}.zip"
        names = [name]
        require(manifest.get(name, manifest.get("downloads/" + name)) == expected, "Original checksum manifest mismatch.")
        (directory / name).write_bytes(blob("downloads/" + name))
        require(digest(directory / name) == expected, "Historical installer checksum mismatch.")
        if version == "0.1.8":
            documentation = "limu-crm-documentation.zip"
            (directory / documentation).write_bytes(blob("downloads/" + documentation))
            require(digest(directory / documentation) == manifest.get(documentation, manifest.get("downloads/" + documentation)), "Historical documentation checksum mismatch.")
            bundle = directory / "limu-crm-evidence-through-0.1.8.zip"
            with zipfile.ZipFile(bundle, "w", compression=zipfile.ZIP_DEFLATED, compresslevel=9) as archive:
                for path, content in evidence.items():
                    entry = zipfile.ZipInfo(path, date_time=(1980, 1, 1, 0, 0, 0))
                    entry.compress_type = zipfile.ZIP_DEFLATED
                    entry.external_attr = 0o100644 << 16
                    archive.writestr(entry, content, compresslevel=9)
            names.extend([documentation, bundle.name])
        for filename in names:
            with zipfile.ZipFile(directory / filename) as archive:
                require(archive.testzip() is None, "Invalid legacy ZIP CRC.")
        releases.append(specification(version, target, directory, names, True))
    return releases


def verify_tag(tag, target, missing_ok=False):
    # Read Git refs directly so a previous REST 404 cannot hide a newly published tag.
    ref = "refs/tags/" + tag
    lines = run(["git", "ls-remote", "--tags", "https://github.com/" + PROJECT + ".git", ref, ref + "^{}"]).splitlines()
    references = {line.split()[1]: line.split()[0] for line in lines}
    if not references and missing_ok:
        return
    require(references.get(ref + "^{}", references.get(ref)) == target, "A release tag is missing or points at a different commit.")


def download_asset(asset, destination):
    with destination.open("wb") as output:
        result = subprocess.run(["gh", "api", f"repos/{PROJECT}/releases/assets/{asset['id']}", "--header", "Accept: application/octet-stream"], cwd=REPOSITORY, stdout=output, stderr=subprocess.PIPE)
    require(result.returncode == 0, "Could not download the release asset by its verified ID.")


def publish(release):
    directory = Path(release["directory"])
    tag = release["tag"]
    verify_tag(tag, release["target_commit"], True)
    remote = release_by_tag(tag)
    if remote is None:
        # Keep the POST response ID; drafts need not be visible in tag/list indexes yet.
        remote = api(f"repos/{PROJECT}/releases", method="POST", payload={
            "tag_name": tag, "target_commitish": release["target_commit"],
            "name": release["title"], "body": (directory / "notes.md").read_text(),
            "draft": True, "prerelease": False, "make_latest": "false",
        })
    require(remote["target_commitish"] == release["target_commit"] and not remote["prerelease"], "Release metadata does not match the reviewed commit.")
    if remote["draft"]:
        require(remote["name"] == release["title"] and release["marker"] in (remote["body"] or ""), "Refusing to change an unrelated draft.")
    expected = {asset["name"] for asset in release["assets"]}
    actual = [asset["name"] for asset in remote["assets"]]
    require(len(actual) == len(set(actual)) and set(actual) <= expected, "Unexpected release assets; nothing was overwritten.")
    assets_by_name = {asset["name"]: asset for asset in remote["assets"]}
    for asset in release["assets"]:
        require(digest(directory / asset["name"]) == asset["sha256"], "Local asset changed before publication.")
        if asset["name"] not in actual:
            require(remote["draft"], "Published release is missing an asset; refusing to modify it.")
            upload_url = f"https://uploads.github.com/repos/{PROJECT}/releases/{remote['id']}/assets?name=" + quote(asset["name"], safe="")
            uploaded = api(upload_url, method="POST", upload=directory / asset["name"])
            require(uploaded["name"] == asset["name"] and uploaded["size"] == asset["bytes"], "Uploaded asset metadata does not match the source.")
            assets_by_name[asset["name"]] = uploaded
    require(set(assets_by_name) == expected, "Release asset set mismatch.")
    verification = directory / "downloaded"
    verification.mkdir(exist_ok=True)
    for asset in release["assets"]:
        downloaded = verification / asset["name"]
        download_asset(assets_by_name[asset["name"]], downloaded)
        require(downloaded.stat().st_size == asset["bytes"] and digest(downloaded) == asset["sha256"], "Uploaded asset differs from the verified source.")
        asset["url"] = assets_by_name[asset["name"]]["browser_download_url"]
        asset["release_asset_id"] = assets_by_name[asset["name"]]["id"]
        asset["download_sha256"] = digest(downloaded)
    if remote["draft"]:
        remote = api(f"repos/{PROJECT}/releases/{remote['id']}", method="PATCH", payload={"draft": False, "make_latest": str(not release["legacy"]).lower()})
    require(not remote["draft"], "Release remained a draft.")
    require(len(remote["assets"]) == len(expected) and {a["name"] for a in remote["assets"]} == expected, "Published release asset set mismatch.")
    for asset in release["assets"]:
        asset["url"] = next(a["browser_download_url"] for a in remote["assets"] if a["name"] == asset["name"])
    verify_tag(tag, release["target_commit"])
    release.update(verified=True, release_id=remote["id"], url=remote["html_url"])
    print("VERIFIED", tag, len(release["assets"]), "assets", flush=True)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("mode", choices=["current", "legacy"])
    parser.add_argument("--prepare-only", action="store_true", help="Validate and stage files without contacting GitHub.")
    parser.add_argument("--output", type=Path)
    parser.add_argument("--package-dir", type=Path)
    args = parser.parse_args()
    temporary = Path(os.environ.get("RUNNER_TEMP", tempfile.gettempdir()))
    output = (args.output or temporary / "crm-release-metadata" / args.mode).resolve()
    require(not output.is_relative_to(REPOSITORY), "Release staging must stay outside the checkout.")
    output.mkdir(parents=True, exist_ok=True, mode=0o700)
    if not args.prepare_only:
        require(os.environ.get("GITHUB_ACTIONS") == "true" and os.environ.get("GITHUB_EVENT_NAME") == "workflow_dispatch" and os.environ.get("GITHUB_REF") == "refs/heads/main", "Publication requires an explicit main-branch Actions dispatch.")
        require(os.environ.get("GITHUB_REPOSITORY") == PROJECT and os.environ.get("GH_TOKEN"), "Expected scoped GitHub Actions token and repository.")
        require(run(["git", "rev-parse", "HEAD"]).strip() == os.environ.get("GITHUB_SHA"), "Checkout differs from the validated workflow commit.")
    releases = legacy(output) if args.mode == "legacy" else current(output, (args.package_dir or temporary / "tested-installer/package").resolve())
    manifest = {"repository": PROJECT, "mode": args.mode, "prepared_only": args.prepare_only, "source_commit": run(["git", "rev-parse", "HEAD"]).strip(), "releases": releases}
    manifest_path = output / "manifest.json"
    manifest_path.write_text(json.dumps(manifest, ensure_ascii=False, indent=2) + "\n")
    if args.prepare_only:
        print("PREPARED", len(releases), "release specifications without GitHub changes:", manifest_path)
        return
    for release in releases:
        publish(release)
        manifest_path.write_text(json.dumps(manifest, ensure_ascii=False, indent=2) + "\n")
    latest = api(f"repos/{PROJECT}/releases/latest")
    current_version = json.loads((REPOSITORY / "package.json").read_text())["version"]
    require(latest["tag_name"] == "crm-v" + current_version, "The current installer must remain the latest release.")
    manifest["latest_verified"] = latest["tag_name"]
    manifest_path.write_text(json.dumps(manifest, ensure_ascii=False, indent=2) + "\n")


if __name__ == "__main__":
    main()
