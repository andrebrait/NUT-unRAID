#!/usr/bin/env python3
"""Build the NUT Slackware package in a mapped-UID sandbox and verify it inside exact Unraid roots.

Reuses NerdTools' pinned bootstrap/sandbox modules (same commit as the unraid-build-root action);
set NERDTOOLS to that checkout. Requires a disposable Linux x86_64 runner and root.
"""
import argparse
import hashlib
import json
import os
from pathlib import Path
import shutil
import subprocess
import sys
import tarfile
import tempfile

from release_watch import plg_entity

REPO = Path(__file__).resolve().parent.parent
LOCK = json.loads((REPO / "build/nut.json").read_text())
NERDTOOLS = Path(os.environ["NERDTOOLS"])
sys.path[:0] = [str(NERDTOOLS / "build/unraid-build-root"), str(NERDTOOLS / "build")]
from bootstrap import clone_root, digest, fetch, prepare_image, restore_toolchain_links, run  # noqa: E402
from sandbox import mapped_root, run_sandbox  # noqa: E402

ENV = {"PATH": "/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin",
       "HOME": "/root", "LC_ALL": "C", "TZ": "UTC"}


def package_file():
    p = LOCK["package"]
    return f"{p['name']}-{p['version']}-x86_64-{p['build']}{p['tag']}.txz"


def runtime_packages():
    """The exact net-snmp/libmodbus packages the plugin installs next to NUT."""
    paths = []
    for item in LOCK["runtime_packages"]:
        path = REPO / item["file"]
        if digest(path) != item["sha256"]:
            raise ValueError(f"SHA256 mismatch: {item['file']}")
        paths.append(path)
    return paths


def check_fixes(tarball):
    """Fail unless every pinned security/bug fix is present in the exact source tarball."""
    with tarfile.open(tarball) as tar:
        prefix = tar.getmembers()[0].name.split("/")[0]
        for fix in LOCK["fixes"]:
            text = tar.extractfile(f"{prefix}/{fix['source']}").read().decode()
            if fix["marker"] not in text:
                raise ValueError(f"Fix missing from source: {fix['id']}")
            line = text[:text.index(fix["marker"])].count("\n") + 1
            print(f"fix present: {fix['id']}: {fix['source']}:{line}: {fix['marker']!r}")


def unpack(archive, root):
    # Payload plus makepkg's symlink lines only; package install scripts are never executed here.
    with tarfile.open(archive) as tar:
        try:
            script = tar.extractfile("install/doinst.sh").read().decode()
        except KeyError:
            script = ""
    run(["tar", "--extract", "--file", archive, "--directory", root, "--skip-old-files", "--exclude=install"])
    restore_toolchain_links(script, root)


def build(args):
    if LOCK["nut"]["commit"][:9] not in (REPO / "build/recipes/nut/slack-desc").read_text():
        raise ValueError("slack-desc does not name the pinned NUT commit")
    work = Path(args.work)
    work.mkdir(parents=True, exist_ok=True)
    out = Path(args.out)
    out.mkdir(parents=True, exist_ok=True)
    inputs = {item["name"]: (item, fetch(item, work / "inputs")) for item in LOCK["inputs"]}
    check_fixes(inputs["nut"][1])
    environment = {**ENV, "TMP": "/tmp/nut-build", "OUTPUT": "/out",
                   "VERSION": LOCK["package"]["version"], "BUILD": LOCK["package"]["build"],
                   "TAG": LOCK["package"]["tag"], "NUT_VERSION_FORCED": LOCK["nut"]["version_forced"],
                   "NUT_TARBALL": inputs["nut"][0]["file"], "AVAHI_TARBALL": inputs["avahi"][0]["file"]}
    with tempfile.TemporaryDirectory(prefix="nut-build-", dir=work) as temporary:
        temporary = Path(temporary)
        temporary.chmod(0o711)
        root = temporary / "root"
        clone_root(Path(args.development_root), root)
        for archive in runtime_packages():
            unpack(archive, root)
        recipe = root / "work/recipe"
        shutil.copytree(REPO / "build/recipes/nut", recipe)
        for item, path in inputs.values():
            shutil.copyfile(path, recipe / item["file"])
        (root / "out").mkdir()
        with mapped_root(root):
            run_sandbox(root, ["/bin/bash", "nut.SlackBuild"], environment, cwd="/work/recipe", timeout=7200)
        results = [path.name for path in (root / "out").glob("*.txz")]
        if results != [package_file()]:
            raise ValueError(f"Expected exactly {package_file()}, found {results}")
        shutil.copyfile(root / "out" / results[0], out / results[0])
    print(f"built {out / results[0]}")


def elf_members(archive, root):
    with tarfile.open(archive) as tar:
        for member in tar.getmembers():
            path = root / member.name.lstrip("./")
            if member.isfile() and not path.is_symlink() and path.is_file():
                with path.open("rb") as stream:
                    if stream.read(4) == b"\x7fELF":
                        yield "/" + member.name.lstrip("./")


def check(root, command):
    """Run read-only inside the target root; return (ok, combined output)."""
    try:
        result = run_sandbox(root, command, ENV, timeout=60, capture=True, read_only=True)
    except subprocess.CalledProcessError as error:
        return False, f"{error.stdout or ''}{error.stderr or ''}exit status {error.returncode}\n"
    return True, result.stdout + result.stderr


def shipped_package():
    """The package the plugin ships on Unraid 7.3.4+: packages/<nutNAME>.txz from plugin/nut-dw.plg."""
    path = REPO / "packages" / f"{plg_entity('nutNAME')}.txz"
    if hashlib.md5(path.read_bytes()).hexdigest() != plg_entity("nutMD5"):
        raise ValueError(f"{path.name} does not match the nutMD5 in nut-dw.plg")
    return path


def verify(args):
    if args.url:
        if not args.sha256:
            raise ValueError("--url requires --sha256")
        target = {"url": args.url, "sha256": args.sha256, "bzroot_sha256": args.bzroot_sha256}
    elif args.target in LOCK["targets"]:
        target = LOCK["targets"][args.target]
    else:
        raise ValueError(f"Unraid {args.target} has no pinned image; pass --url and --sha256")
    package = Path(args.package).resolve() if args.package else shipped_package()
    print(f"Verifying {package.name} on Unraid {args.target}")
    archives = [*runtime_packages(), package]
    work = Path(args.work)
    work.mkdir(parents=True, exist_ok=True)
    with tempfile.TemporaryDirectory(prefix="nut-verify-", dir=work) as temporary:
        temporary = Path(temporary)
        temporary.chmod(0o711)
        root = temporary / "root"
        root.mkdir()
        image = {"url": target["url"], "sha256": target["sha256"], "file": f"unRAIDServer-{args.target}-x86_64.zip"}
        prepare_image(image, args.target, temporary / "inputs", root, target.get("bzroot_sha256"))
        staged = root / "tmp/nut-packages"
        staged.mkdir(parents=True)
        for archive in archives:
            shutil.copyfile(archive, staged / archive.name)
        failures = []
        with mapped_root(root):
            # Same command the plugin runs on the box, including package install scripts.
            run_sandbox(root, ["/sbin/upgradepkg", "--install-new", *(f"/tmp/nut-packages/{a.name}" for a in archives)],
                        ENV, timeout=600)
            # ld.so resolves the transitive closure, so net-snmp/libmodbus libraries NUT needs are covered too.
            for path in elf_members(package, root):
                ok, output = check(root, ["/lib64/ld-linux-x86-64.so.2", "--list", path])
                print(f"== {path}\n{output}", end="")
                if not ok or "not found" in output:
                    failures.append(path)
            for command in LOCK["smoke"]:
                ok, output = check(root, command)
                print(f"== {' '.join(command)}\n{output}", end="")
                if not ok or LOCK["nut"]["version_forced"].split("-")[0] not in output:
                    failures.append(" ".join(command))
            for path, libraries in LOCK["links"].items():
                ok, output = check(root, ["/lib64/ld-linux-x86-64.so.2", "--list", path])
                for library in libraries:
                    resolved = [line.strip() for line in output.splitlines() if line.strip().startswith(f"{library} => /")]
                    print(f"link: {path}: {resolved or library + ' MISSING'}")
                    if not ok or not resolved:
                        failures.append(f"{path} -> {library}")
            # No upsd runs here, so the connection fails; only the client-side SSL setup output matters.
            forbidden = LOCK["forbidden_output"]
            _, output = check(root, forbidden["command"])
            print(f"== {' '.join(forbidden['command'])}\n{output}", end="")
            failures.extend(f"{forbidden['command'][0]} printed {pattern!r}" for pattern in forbidden["patterns"] if pattern in output)
        for fix in LOCK["fixes"]:
            if "binary" in fix:
                if fix["binary_marker"].encode() not in (root / fix["binary"]).read_bytes():
                    failures.append(fix["binary"])
                else:
                    print(f"fix compiled in: {fix['id']}: /{fix['binary']} contains {fix['binary_marker']!r}")
        if failures:
            raise ValueError(f"Unraid {args.target} verification failed: {failures}")
    print(f"Verified {package.name} on Unraid {args.target}: ELF closure resolved, smoke commands passed.")


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    sub = parser.add_subparsers(dest="command", required=True)
    # Work dirs must be traversable by the mapped sandbox UIDs; the runner's home is not.
    b = sub.add_parser("build")
    b.add_argument("--development-root", required=True)
    b.add_argument("--out", required=True)
    b.add_argument("--work", default="/tmp")
    v = sub.add_parser("verify")
    v.add_argument("--target", required=True, help="Unraid version; a pinned one in nut.json, else give --url/--sha256")
    v.add_argument("--package", help="package to verify (default: the one nut-dw.plg ships as nutNAME)")
    v.add_argument("--url", help="Unraid ZIP URL (overrides the nut.json pin)")
    v.add_argument("--sha256", help="SHA256 of the Unraid ZIP")
    v.add_argument("--bzroot-sha256", help="optional SHA256 of the ZIP's bzroot member")
    v.add_argument("--work", default="/tmp")
    args = parser.parse_args()
    if sys.platform != "linux" or os.uname().machine != "x86_64" or os.geteuid() != 0:
        raise SystemExit("Requires a disposable Linux x86_64 runner and root")
    build(args) if args.command == "build" else verify(args)


if __name__ == "__main__":
    main()
