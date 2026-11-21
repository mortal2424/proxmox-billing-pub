#!/usr/bin/env bash
set -euo pipefail

if [[ $# != 1 || $1 != *@*'!'* || $EUID != 0 ]]; then
    echo "Usage as Proxmox root: $0 'user@realm!token-id'" >&2
    exit 2
fi
token=$1
account=${token%%!*}
node=$(hostname -s)
snippet=$(dirname -- "${BASH_SOURCE[0]}")/homevlad-qemu-agent.yaml
storage=hv-ci-snippets
directory=/var/lib/homevlad-snippets
role=HomeVladSnippetUse

test -s "$snippet"
install -d -m 0755 "$directory/snippets"
if config=$(pvesh get "/storage/$storage" --output-format json 2>/dev/null); then
    read -r members digest < <(python3 -c 'import json,sys
s=json.load(sys.stdin)
assert s["type"]=="dir" and s["path"]==sys.argv[1] and s["content"]=="snippets", "Storage is not a dedicated HomeVlad snippets directory"
members=s.get("nodes")
assert members and s.get("digest"), "Storage is not node-scoped"
print(members,s["digest"])
' "$directory" <<< "$config")
    if [[ ",$members," != *",$node,"* ]]; then
        pvesh set "/storage/$storage" --digest "$digest" --nodes "$members,$node"
    fi
else
    pvesm add dir "$storage" --path "$directory" --content snippets --nodes "$node"
fi
if ! pveum role add "$role" --privs 'Datastore.Allocate Datastore.Audit' 2>/dev/null; then
    pveum role list --output-format json | python3 -c 'import json,sys
assert any(r["roleid"]==sys.argv[1] and set(r.get("privs","").split(","))=={"Datastore.Allocate","Datastore.Audit"} for r in json.load(sys.stdin)), "Existing role has different permissions"
' "$role"
fi
pveum acl modify "/storage/$storage" --roles "$role" --users "$account" --tokens "$token"
tmp=$(mktemp "$directory/.homevlad-qga.XXXXXX")
trap 'rm -f -- "$tmp"' EXIT
install -m 0644 "$snippet" "$tmp"
mv -fT -- "$tmp" "$directory/snippets/homevlad-qemu-agent.yaml"
pvesh get "/nodes/$node/storage/$storage/content" --content snippets --output-format json
