#!/usr/bin/env bash
set -euo pipefail
umask 077

if [[ $EUID != 0 || $# != 1 || ! $1 =~ ^homevlad-node-[a-zA-Z0-9][a-zA-Z0-9-]{0,39}$ ]]; then
    echo "Usage on the Proxmox node as root: $0 homevlad-node-N-v1" >&2
    exit 2
fi
for command in pveum pvesh pvesm python3 flock; do command -v "$command" >/dev/null; done
source_dir=$(dirname -- "${BASH_SOURCE[0]}")
if ! grep -q '^#!.*bash' "$source_dir/install-qemu-agent-snippet.sh" ||
    ! grep -q '^#cloud-config' "$source_dir/homevlad-qemu-agent.yaml" ||
    ! bash -n "$source_dir/install-qemu-agent-snippet.sh"; then
    echo "Use the original install/cloud-init files from the same HomeVlad source archive." >&2
    exit 2
fi
node=$(hostname -s)
pvesh get "/nodes/$node/status" --output-format json >/dev/null
state=/root/.homevlad-node
install -d -m 0700 -- "$state"
exec 9>"$state/setup.lock"
flock -n 9
account=homevlad-billing@pve
marker='HomeVlad billing integration v1'
token="$account!$1"
credential="$state/$1.json"

ensure_role() {
    local role=$1 privileges=$2
    if ! pveum role add "$role" --privs "$privileges" 2>/dev/null; then
        pveum role list --output-format json | python3 -c '
import json, re, sys
roles = json.load(sys.stdin)
name, desired = sys.argv[1:]
actual = next((row.get("privs", "") for row in roles if row["roleid"] == name), "")
if set(filter(None, re.split(r"[\s,]+", actual))) != set(desired.split()):
    sys.exit("Existing role differs from HomeVlad policy: " + name)
' "$role" "$privileges"
    fi
}

if ! pveum user add "$account" --enable 1 --comment "$marker" 2>/dev/null; then
    pveum user list --output-format json | python3 -c '
import json,sys,time
account,marker=sys.argv[1:]
if not any(r["userid"]==account and r.get("comment")==marker and int(r.get("enable",0))==1
           and (not int(r.get("expire",0)) or int(r["expire"])>time.time()) for r in json.load(sys.stdin)):
    sys.exit("Existing service account is incompatible")
' "$account" "$marker"
fi

if [[ ! -f "$credential" ]]; then
    staged=$(mktemp "$state/.token.XXXXXX")
    trap 'rm -f -- "$staged"' EXIT
    if ! pveum user token add "$account" "$1" --privsep 1 --comment "$marker" --output-format json > "$staged"; then
        echo "Could not create token. If that ID already exists, keep it and use a new token name." >&2
        exit 1
    fi
    # The API only returns the secret on creation. Preserve the result before granting ACLs.
    mv -fT -- "$staged" "$credential"
fi
python3 -c '
import json,sys
with open(sys.argv[1]) as f:
    token=json.load(f)
if token.get("full-tokenid")!=sys.argv[2] or not token.get("value") or not int(token["info"]["privsep"]):
    sys.exit("Unexpected token response; keep the saved file for inspection")
' "$credential" "$token"
pveum user token list "$account" --output-format json | python3 -c '
import json,sys,time
if not any(r["tokenid"]==sys.argv[1] and int(r.get("privsep",0))==1
           and (not int(r.get("expire",0)) or int(r["expire"])>time.time()) for r in json.load(sys.stdin)):
    sys.exit("Saved token is missing, expired or no longer privilege-separated")
' "$1"

while IFS='|' read -r role scope privileges; do
    ensure_role "$role" "$privileges"
    pveum acl modify "$scope" --roles "$role" --users "$account" --propagate 1
    pveum acl modify "$scope" --roles "$role" --tokens "$token" --propagate 1
done <<'POLICY'
HomeVladAuditV1|/|Sys.Audit
HomeVladGuestsV1|/vms|VM.Allocate VM.Audit VM.Clone VM.Console VM.PowerMgmt VM.Config.CPU VM.Config.Memory VM.Config.Disk VM.Config.Network VM.Config.Options VM.Config.CDROM VM.Config.HWType VM.Config.Cloudinit VM.GuestAgent.Audit
HomeVladStorageV1|/storage|Datastore.Audit Datastore.AllocateSpace Datastore.AllocateTemplate
HomeVladNetworksV1|/sdn/zones|SDN.Audit SDN.Use
POLICY
bash "$source_dir/install-qemu-agent-snippet.sh" "$token" >/dev/null
printf 'Node: %s\nToken ID: %s\nSecret file (root only): %s\n' "$node" "$token" "$credential"
