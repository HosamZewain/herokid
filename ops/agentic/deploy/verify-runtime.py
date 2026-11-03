#!/usr/bin/env python3
"""Read-only host attestation. Never print container env or credential contents."""
import argparse
import json
import re
import subprocess


def docker(*arguments):
    return json.loads(subprocess.check_output(["docker", *arguments], text=True))


def require(condition, message):
    if not condition:
        raise RuntimeError(message)


def verify(decision, egress, network, decision_image, egress_image, auth, socket):
    for item, expected, memory, pids in [
        (decision, decision_image, 512 * 1024**2, 128),
        (egress, egress_image, 128 * 1024**2, 32),
    ]:
        cfg, host = item["Config"], item["HostConfig"]
        require(re.fullmatch(r"sha256:[a-f0-9]{64}", expected), "immutable image ID required")
        require(item["Image"] == expected and item["State"]["Running"], "image/state mismatch")
        require(re.fullmatch(r"[1-9][0-9]*:[1-9][0-9]*", cfg["User"]), "non-root numeric identity required")
        require(host["ReadonlyRootfs"] and not host["Privileged"], "read-only unprivileged root required")
        require(host["CapDrop"] == ["ALL"] and not host.get("CapAdd"), "capabilities forbidden")
        require(host["SecurityOpt"] == ["no-new-privileges:true"], "unreviewed security profile")
        require(host.get("Init") is True, "reviewed child-process reaper required")
        require(not host.get("PidMode") and not host.get("IpcMode") == "host", "host namespaces forbidden")
        require(not host.get("PortBindings") and not host.get("Devices"), "ports/devices forbidden")
        require(host["PidsLimit"] == pids and host["Memory"] == memory, "resource limit mismatch")
        require(host["NanoCpus"] == (500_000_000 if item is decision else 250_000_000), "CPU ceiling mismatch")
        require(host["RestartPolicy"]["Name"] == "no", "automatic container activation forbidden")
        require(not host.get("ExtraHosts") and not host.get("Links"), "extra network paths forbidden")
        require(not any(re.match(r"(OPENAI_API_KEY|APP_KEY|AWS_|AZURE_|CODEX_HOME)", value) for value in cfg["Env"]), "unexpected credential environment")
    require(egress["Config"]["User"] == "13:13" and not egress["Mounts"], "proxy must have no host/auth mount")
    require(decision["Config"]["Cmd"] == ["node", "bridge.mjs"]
            and decision["Config"]["WorkingDir"] == "/opt/herokid-agentic"
            and decision["Config"]["Entrypoint"] == ["docker-entrypoint.sh"], "runtime entrypoint mismatch")
    require(egress["Config"]["Cmd"] == ["/usr/sbin/squid", "-N", "-f", "/etc/squid/squid.conf"]
            and not egress["Config"]["Entrypoint"], "proxy entrypoint mismatch")
    mounts = {m["Destination"]: m for m in decision["Mounts"]}
    require(set(mounts) == {"/run/agentic-auth", "/run/bridge"}, "unexpected model mount")
    require(mounts["/run/agentic-auth"]["Source"] == auth and not mounts["/run/agentic-auth"]["RW"], "credential mount mismatch")
    require(mounts["/run/bridge"]["Source"] == socket and mounts["/run/bridge"]["RW"], "socket mount mismatch")
    require(network["Internal"] and not network["EnableIPv6"], "private IPv4 network required")
    require(network["Options"].get("com.docker.network.bridge.gateway_mode_ipv4") == "isolated", "host gateway isolation required")
    require(set(network["Containers"]) == {decision["Id"], egress["Id"]}, "unexpected private network peer")
    require(set(decision["NetworkSettings"]["Networks"]) == {network["Name"]}, "direct runtime egress forbidden")
    require(len(egress["NetworkSettings"]["Networks"]) == 2 and network["Name"] in egress["NetworkSettings"]["Networks"], "proxy network mismatch")
    require(host_tmpfs_ok(decision, 128 * 1024**2) and host_tmpfs_ok(egress, 16 * 1024**2), "bounded noexec scratch required")
    return {"verified": True, "private_network": True, "host_gateway_absent": True, "model_ports_published": False,
            "model_mounts": ["read_only_dedicated_auth", "private_unix_socket"], "direct_model_egress": False}


def host_tmpfs_ok(item, expected):
    mounts = item["HostConfig"].get("Tmpfs") or {}
    value = mounts.get("/tmp", "")
    match = re.search(r"(?:^|,)size=(\d+)([kmg]?)", value, re.I)
    size = int(match[1]) * {"": 1, "k": 1024, "m": 1024**2, "g": 1024**3}[match[2].lower()] if match else 0
    return set(mounts) == {"/tmp"} and "noexec" in value.split(",") and "nosuid" in value.split(",") and size == expected


if __name__ == "__main__":
    parser = argparse.ArgumentParser()
    for name in ["decision", "egress", "network", "decision-image", "egress-image", "auth-dir", "socket-dir"]:
        parser.add_argument("--" + name, required=True)
    args = parser.parse_args()
    require(int(docker("version", "--format", "{{json .Server.Version}}").split(".")[0]) >= 28, "Docker 28+ required")
    try:
        report = verify(docker("inspect", args.decision)[0], docker("inspect", args.egress)[0],
                        docker("network", "inspect", args.network)[0], args.decision_image, args.egress_image,
                        args.auth_dir, args.socket_dir)
        print(json.dumps(report))
    except (RuntimeError, KeyError):
        raise SystemExit("Auto Pilot deployment attestation refused; keep evaluation disabled.")
