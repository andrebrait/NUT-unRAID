#!/usr/bin/env python3
"""Serve the NUT plugin's WebGUI pages from an exact Unraid root, for the browser tests in this directory.

up     Extracts the pinned Unraid image (build/nut.json targets) into WORK/root with NerdTools' bootstrap and
       links /etc/rc.d as rc.S does at boot. It first checks the upgrade from desertwitch's last nut-dw release (the
       .plg's migration block, then the new package) and removes it again. It then installs the NUT backend
       named by the .plg and a plugin package built from source/nut3 (the same tar as package.yml) with the
       root's own upgradepkg, so doinst.sh runs. It then replaces the NUT daemons and clients with
       fixture-driven stubs (stubs/), writes the state files emhttpd would write, and starts the root's own
       rsyslogd, php-fpm (its own php.ini, so the real CSRF prepend) and nginx, chrooted, on 127.0.0.1:PORT.
       nginx gets Unraid's routing (rc.nginx locations) without login.
reset  Restores the plugin's flash directory and the stub fixtures to their state right after installation.
down   Stops every process running in the root, unmounts it and copies the server logs to --logs.

Requires a disposable Linux x86_64 runner and root. The idea of running a plugin against real Unraid
webGUI code with fake NUT binaries follows chodeus/ZFS-Master-NG's tests/unraid_compat_test.sh.
"""
import argparse
import json
import os
from pathlib import Path
import re
import secrets
import shutil
import signal
import subprocess
import sys
import time
import urllib.request
import xml.etree.ElementTree as ET

HERE = Path(__file__).resolve().parent
REPO = HERE.parent.parent
FLASH = "boot/config/plugins/nut3"
FIXTURES = "var/tmp/nut-e2e"            # stub inputs and outputs, see stubs/
PRISTINE = "var/tmp/nut-e2e-pristine"   # flash directory and fixtures right after installation
STUBS = {"upsc": "usr/bin", "upscmd": "usr/bin", "upsmon": "usr/sbin", "upsd": "usr/sbin", "upsdrvctl": "usr/sbin"}
RELEASED = "nut-plugin-2026.10.15-x86_64-1.txz"  # desertwitch's last release, under the old plugin name nut-dw


def run(args, **kwargs):
    print("+", " ".join(map(str, args)), flush=True)
    return subprocess.run(args, check=True, **kwargs)


def chroot(root, *args, **kwargs):
    return run(["chroot", root, *args], **kwargs)


def entity(name):
    plg = (REPO / "plugin/nut3.plg").read_text()
    return re.search(rf'<!ENTITY {name}\s+"([^"]+)">', plg).group(1)


def fixtures(root):
    """Default stub inputs: an online UPS, no slaves, a few instant commands."""
    target = root / FIXTURES
    target.mkdir(parents=True, exist_ok=True)
    for source in (HERE / "fixtures").iterdir():
        shutil.copy(source, target / source.name)


def package(target):
    # the same tar invocation as .github/workflows/package.yml (members named like makepkg's, so installpkg runs doinst.sh)
    run(["tar", "-C", REPO / "source/nut3", "--owner=0", "--group=0", "--sort=name", r"--transform=s,^\./\(.\),\1,",
         "-cJf", target, "."])


def upgrade(root):
    """Install desertwitch's last nut-dw release with changed settings, then upgrade as the .plg does and check the result."""
    old, new = root / "boot/config/plugins/nut-dw", root / FLASH
    packages = root / "tmp/nut-e2e-upgrade"
    packages.mkdir(parents=True)
    shutil.copy(REPO / "archive" / RELEASED, packages / RELEASED)
    plugin = f"nut-plugin-{entity('version')}-x86_64-1.txz"
    package(packages / plugin)

    old.mkdir(parents=True)
    chroot(root, "/sbin/upgradepkg", "--install-new", f"/tmp/nut-e2e-upgrade/{RELEASED}")  # installs its default.cfg
    defaults = (old / "nut-dw.cfg").read_text()
    (old / "nut-dw.cfg").write_text(defaults.replace('NAME="ups"', 'NAME="upgradeups"').replace('POLL="15"', 'POLL="7"'))
    chroot(root, "/etc/rc.d/rc.nut", "write_config")  # the old release writes the settings into the NUT files on start
    (old / RELEASED).write_bytes(b"")  # a package the old .plg cached
    (root / "var/log/plugins").mkdir(parents=True, exist_ok=True)
    for plg in (root / "boot/config/plugins/nut-dw.plg", root / "var/log/plugins/nut-dw.plg"):
        plg.write_text("<PLUGIN/>\n")
    cfg = (old / "nut-dw.cfg").read_text()
    ups = {p.name: p.read_text() for p in (old / "ups").iterdir()}
    assert 'NAME="upgradeups"' in cfg and "[upgradeups]" in ups["ups.conf"], "the old release did not take the settings"

    snippet = next(i.text for i in ET.parse(REPO / "plugin/nut3.plg").iter("INLINE")
                   if i.text.lstrip().startswith("# Migrate from nut-dw"))
    chroot(root, "/bin/bash", "-c", snippet)
    assert (new / "nut3.cfg").read_text() == cfg, "settings not moved to nut3.cfg"
    assert {p.name: p.read_text() for p in (new / "ups").iterdir()} == ups, "ups/ files not moved"
    assert not (new / RELEASED).exists() and not (new / "nut-dw.cfg").exists(), "cached package or nut-dw.cfg copied"
    for gone in (old, root / "boot/config/plugins/nut-dw.plg", root / "var/log/plugins/nut-dw.plg",
                 root / "usr/local/emhttp/plugins/nut-dw"):
        assert not gone.exists() and not gone.is_symlink(), f"{gone} remains"

    chroot(root, "/sbin/upgradepkg", "--install-new", f"/tmp/nut-e2e-upgrade/{plugin}")
    # the old release's files were not generated by this plugin: they are imported and stay in use until Apply
    report = json.loads((new / "import-report.json").read_text())
    assert not report.get("accepted"), "the import was accepted without the user's Apply"
    imported = (new / "nut3.cfg").read_text()
    assert 'NAME="upgradeups"' in imported and 'POLL="7"' in imported, "the settings were not kept in the import"
    for name, text in ups.items():
        assert (new / "ups" / name).read_text() == text, f"ups/{name} changed before Apply"
    assert (new / "backup/legacy/ups.conf").read_text() == ups["ups.conf"], "ups.conf was not backed up"
    assert not (root / "usr/local/emhttp/plugins/nut-dw").exists(), "/usr/local/emhttp/plugins/nut-dw remains"
    print("Upgrade from nut-dw: settings and ups/ files carried over", flush=True)

    # the page tests start from a fresh installation
    chroot(root, "/sbin/removepkg", "nut-plugin")
    shutil.rmtree(new)
    shutil.rmtree(packages)


def install(root, work):
    packages = root / "tmp/nut-e2e-packages"
    packages.mkdir(parents=True)
    backend = f"{entity('nutNAME')}.txz"
    shutil.copy(REPO / "packages" / backend, packages / backend)
    plugin = f"nut-plugin-{entity('version')}-x86_64-1.txz"
    package(packages / plugin)
    (root / FLASH).mkdir(parents=True)  # the .plg creates it before installing the package
    # backend first, plugin last, as the .plg does on 7.3.4+; the log is checked by nut.spec.js
    log = chroot(root, "/sbin/upgradepkg", "--install-new", f"/tmp/nut-e2e-packages/{backend}",
                 f"/tmp/nut-e2e-packages/{plugin}", stdout=subprocess.PIPE, stderr=subprocess.STDOUT, text=True).stdout
    print(log, flush=True)
    (work / "install.log").write_text(log)
    shutil.rmtree(packages)

    for name, directory in STUBS.items():
        shutil.copy(HERE / "stubs" / name, root / directory / name)
        (root / directory / name).chmod(0o755)
    # stand-in daemon: `pgrep -x upsmon` matches the executable's name, so a copy of sleep named upsmon
    sleep = next(p for p in (root / "usr/bin/sleep", root / "bin/sleep") if p.exists())
    (root / "usr/libexec/nut-e2e").mkdir(parents=True)
    shutil.copy(sleep, root / "usr/libexec/nut-e2e/upsmon")
    fixtures(root)


def state(root):
    """The emhttpd state the page layout reads; emhttpd itself is closed source and not run."""
    version = re.search(r'version="?([^"\n]+)', (root / "etc/unraid-version").read_text()).group(1)
    emhttp = root / "var/local/emhttp"
    emhttp.mkdir(parents=True, exist_ok=True)
    (emhttp / "var.ini").write_text("\n".join(f'{k}="{v}"' for k, v in {
        "version": version, "NAME": "Tower", "COMMENT": "NUT page tests", "timeZone": "UTC",
        "csrf_token": secrets.token_hex(8).upper(), "mdState": "STARTED", "fsState": "Started",
        "fsNumMounted": "0", "mdColor": "green-on", "regTy": "Pro", "regTo": "NUT page tests",
        "regBuildTime": str(int(time.time())), "safeMode": "no", "startMode": "Normal",
    }.items()) + "\n")
    (emhttp / "network.ini").write_text('[eth0]\nBONDING="no"\nBRIDGING="no"\nUSE_DHCP:0="no"\n'
                                        'IPADDR:0="127.0.0.1"\nNETMASK:0="255.0.0.0"\n')
    (root / "var/tmp/unRAIDServer.txt").write_text("")  # release notes the layout would otherwise build
    localtime = root / "etc/localtime"  # local_prepend.php takes the time zone from this link's target
    localtime.unlink(missing_ok=True)
    localtime.symlink_to("/usr/share/zoneinfo/UTC")


def php_fpm_listen(root):
    for conf in [root / "etc/php-fpm.conf", *sorted((root / "etc/php-fpm.d").glob("*.conf"))]:
        if conf.is_file() and (m := re.search(r"^\s*listen\s*=\s*(\S+)", conf.read_text(), re.M)):
            return m.group(1)
    raise ValueError("no php-fpm listen address in the root's configuration")


def start(root, port):
    (root / "tmp").chmod(0o1777)
    run(["mount", "-t", "proc", "proc", root / "proc"])
    for directory in ("var/log/nginx", "var/log/nginx-e2e", "var/tmp/nginx-e2e"):
        (root / directory).mkdir(parents=True, exist_ok=True)
    # the plugin's scripts pipe into logger, which exits without /dev/log and so cuts rc.nut short
    chroot(root, "/usr/sbin/rsyslogd")
    for _ in range(20):
        if (root / "dev/log").exists():
            break
        time.sleep(0.5)
    else:
        raise RuntimeError("rsyslogd did not create /dev/log in the root")
    # exactly as rc.php-fpm starts it
    chroot(root, "/usr/sbin/php-fpm", "--daemonize", "--fpm-config", "/etc/php-fpm.conf",
           "--pid", "/var/run/php-fpm.pid", "--allow-to-run-as-root")

    version = subprocess.run(["chroot", root, "/usr/sbin/nginx", "-V"], capture_output=True, text=True).stderr
    original = (root / "etc/nginx/nginx.conf").read_text()
    params = (root / "etc/nginx/fastcgi_params").read_text()
    listen = php_fpm_listen(root)
    nchan = "nchan" in version
    conf = (HERE / "nginx.conf").read_text()
    conf = conf.replace("@MODULES@", "\n".join(re.findall(r"^\s*load_module\s+[^;]+;", original, re.M)))
    conf = conf.replace("@PORT@", str(port))
    # Unraid's fastcgi_params carries what its locations rely on; add what a stock one would lack
    extra = [] if "SCRIPT_FILENAME" in params else ["fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;"]
    if "fastcgi_pass" not in params:
        extra.append(f"fastcgi_pass {listen if ':' in listen else 'unix:' + listen};")
    conf = conf.replace("@FASTCGI_EXTRA@", "\n            ".join(extra))
    for block in ("NCHAN_SERVER", "NCHAN_LOCATION"):
        conf = re.sub(rf"\s*# @{block}@(.*?)# @END@", lambda m: m.group(1) if nchan else "", conf, flags=re.S)
    (root / "etc/nginx/nginx-e2e.conf").write_text(conf)
    print(f"{version.strip()}\nphp-fpm listen: {listen}\nnchan: {nchan}\n--- fastcgi_params\n{params}", flush=True)
    chroot(root, "/usr/sbin/nginx", "-t", "-c", "/etc/nginx/nginx-e2e.conf")
    chroot(root, "/usr/sbin/nginx", "-c", "/etc/nginx/nginx-e2e.conf")

    url = f"http://127.0.0.1:{port}/Settings/NUTsettings"
    for _ in range(60):
        try:
            with urllib.request.urlopen(url, timeout=30) as response:
                if 'id="nut-settings"' in response.read().decode(errors="replace"):
                    print(f"Serving {url}", flush=True)
                    return
        except OSError as error:
            print(f"waiting for {url}: {error}", flush=True)
        time.sleep(1)
    raise RuntimeError(f"{url} never served the NUT settings form")


def snapshot(root):
    pristine = root / PRISTINE
    shutil.copytree(root / FLASH, pristine / "flash", symlinks=True)
    shutil.copytree(root / FIXTURES, pristine / "fixtures", symlinks=True)


def up(args):
    if Path("/etc/unraid-version").exists():
        raise SystemExit("Refusing to run on an Unraid system")
    nerdtools = Path(os.environ["NERDTOOLS"])
    sys.path.insert(0, str(nerdtools / "build/unraid-build-root"))
    from bootstrap import prepare_image  # NerdTools' pinned bootstrap, same commit as native-nut.yml

    target = json.loads((REPO / "build/nut.json").read_text())["targets"][args.target]
    work = Path(args.work)
    root = work / "root"
    root.mkdir(parents=True)
    image = {"url": target["url"], "sha256": target["sha256"], "file": f"unRAIDServer-{args.target}-x86_64.zip"}
    prepare_image(image, args.target, work / "inputs", root, target["bzroot_sha256"])
    shutil.rmtree(work / "inputs")
    # as rc.S does at boot: /etc/rc.d becomes the /usr layer's rc.d (rc.6, rc.nginx, ...)
    shutil.rmtree(root / "etc/rc.d")
    (root / "etc/rc.d").symlink_to("/usr/local/etc/rc.d")
    upgrade(root)
    install(root, work)
    state(root)
    snapshot(root)
    start(root, args.port)


def reset(args):
    root = Path(args.work) / "root"
    subprocess.run(["chroot", root, "/usr/sbin/upsmon", "-c", "stop"], check=True)
    for name, target in (("flash", FLASH), ("fixtures", FIXTURES)):
        shutil.rmtree(root / target, ignore_errors=True)
        shutil.copytree(root / PRISTINE / name, root / target, symlinks=True)
    (root / "usr/local/emhttp/plugins/nut3/misc/start-failed").unlink(missing_ok=True)


def down(args):
    root = (Path(args.work) / "root").resolve()
    for sig in (signal.SIGTERM, signal.SIGKILL):
        for pid in filter(str.isdigit, os.listdir("/proc")):
            try:
                if os.readlink(f"/proc/{pid}/root") == str(root):
                    os.kill(int(pid), sig)
            except OSError:
                pass
        time.sleep(2)
    subprocess.run(["umount", root / "proc"])
    if args.logs:
        logs = Path(args.logs)
        logs.mkdir(parents=True, exist_ok=True)
        for log in [*(root / "var/log").glob("*"), *(root / "var/log/nginx-e2e").glob("*")]:
            if log.is_file():
                shutil.copy(log, logs / log.name)
        shutil.copytree(root / FLASH, logs / "flash", symlinks=True, dirs_exist_ok=True)


def main():
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--work", default="/tmp/nut-e2e")
    sub = parser.add_subparsers(dest="command", required=True)
    u = sub.add_parser("up")
    u.add_argument("--target", required=True, choices=sorted(json.loads((REPO / "build/nut.json").read_text())["targets"]))
    u.add_argument("--port", type=int, default=8080)
    sub.add_parser("reset")
    d = sub.add_parser("down")
    d.add_argument("--logs")
    args = parser.parse_args()
    if sys.platform != "linux" or os.geteuid() != 0:
        raise SystemExit("Requires a disposable Linux runner and root")
    {"up": up, "reset": reset, "down": down}[args.command](args)


if __name__ == "__main__":
    main()
