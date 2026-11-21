#!/usr/bin/env bash
set -euo pipefail

cd "$(git rev-parse --show-toplevel)"
base_ref=674b85f
base_version=2.5.1-beta8.40
test_version=2.5.1-beta8.41
qa=$(mktemp -d)
export COMPOSE_PROJECT_NAME=homevlad-docker-smoke
export DOMAIN=nginx EMAIL=qa@example.invalid DB_NAME=homevlad_smoke DB_USER=homevlad_smoke
export DB_PASSWORD="$(openssl rand -hex 24)" MYSQL_ROOT_PASSWORD="$(openssl rand -hex 24)"
export PROXMOX_NODE_KEY="$(openssl rand -base64 32)"
export PROXMOX_CA_FILE=/etc/proxmox-tls/smoke.pem
compose=(docker compose)
cleanup() {
    code=$?
    if (( code != 0 )); then "${compose[@]}" logs --tail=85 apache nginx db init-ssl || true; fi
    "${compose[@]}" exec -T apache chown -R "$(id -u):$(id -g)" \
        /var/www/html/uploads /var/www/html/logs /var/www/html/backups || true
    "${compose[@]}" down --volumes --remove-orphans || true
    rm -rf -- "$qa"
}
trap cleanup EXIT

mkdir -p "$qa/checkout"
git archive HEAD | tar -x -C "$qa/checkout"
cd "$qa/checkout"
mkdir -p logs uploads backups docker/proxmox-tls webroot
openssl req -x509 -newkey rsa:2048 -nodes -days 2 -subj '/CN=nginx' \
    -addext 'subjectAltName=DNS:nginx,DNS:apache,DNS:localhost' \
    -keyout "$qa/pve.key" -out docker/proxmox-tls/smoke.pem
chmod 644 docker/proxmox-tls/smoke.pem
"${compose[@]}" config --quiet
"${compose[@]}" build apache nginx
"${compose[@]}" run --rm --no-deps -v "$qa/pve.key:/work/key:ro" \
    -v "$PWD/docker/proxmox-tls/smoke.pem:/work/cert:ro" --entrypoint sh init-ssl -c \
    'cp /work/cert /etc/nginx/ssl/fullchain.pem; cp /work/key /etc/nginx/ssl/privkey.pem'
"${compose[@]}" up -d db apache nginx

https=(curl --silent --show-error --max-time 50 --cacert "$PWD/docker/proxmox-tls/smoke.pem")
web=https://localhost
ready() {
    for attempt in {1..70}; do
        if "${https[@]}" --max-time 3 --fail "$web/console/view.html" >/dev/null 2>&1; then
            if "${compose[@]}" exec -T --user www-data apache test -S /run/homevlad-console/issuer.sock; then return; fi
        fi
        sleep 2
    done
    echo 'Docker console did not become ready' >&2
    return 1
}
ready
"${compose[@]}" exec -T nginx nginx -t
"${compose[@]}" exec -T apache apache2ctl -t
"${compose[@]}" exec -T apache sh -c 'cat >/tmp/homevlad-docker-pve.key; chmod 0600 /tmp/homevlad-docker-pve.key' < "$qa/pve.key"
"${compose[@]}" cp tools/docker-smoke.py apache:/tmp/docker-smoke.py
"${compose[@]}" cp tools/docker-smoke.php apache:/tmp/docker-smoke.php
"${compose[@]}" exec -d apache /usr/bin/python3 /tmp/docker-smoke.py serve
"${compose[@]}" exec -T --user www-data -e HOMEVLAD_DOCKER_SMOKE=1 apache php /tmp/docker-smoke.php
"${compose[@]}" exec -T --user www-data apache /usr/bin/python3 /tmp/docker-smoke.py test

session=$("${compose[@]}" exec -T --user www-data apache cat /tmp/homevlad-docker-sessions.json)
sid=$(jq -r '.sessions.admin.id' <<< "$session")
csrf=$(jq -r '.sessions.admin.csrf' <<< "$session")
cookie=(--cookie "PHPSESSID=$sid")
checkout=$(git -C "$OLDPWD" rev-parse --show-toplevel)
for file in api/mobile_admin.php includes/version.php; do
    git -C "$checkout" show "$base_ref:$file" | "${compose[@]}" exec -T apache tee "$file" >/dev/null
done
"${compose[@]}" exec -T apache rm -f includes/mobile_admin_directory.php
"${compose[@]}" exec -T --user www-data apache php cronjobs/check_updates.php
"${https[@]}" --fail "${cookie[@]}" "$web/admin/check_updates_count.php" | jq -e '.success and (.count >= 1)'

update=(--data-urlencode "version=$test_version" --data-urlencode "csrf_token=$csrf")
"${https[@]}" --fail "${cookie[@]}" "${update[@]}" -d action=preview "$web/admin/update.php" > "$qa/preview.html"
grep -q "Пакет $test_version" "$qa/preview.html"
grep -q 'Нативная админка HomeVlad 1.5' "$qa/preview.html"

"${compose[@]}" exec -T apache sh -c 'printf "\n// Docker smoke local change\n" >> api/mobile_admin.php'
changed=$("${compose[@]}" exec -T apache sha256sum api/mobile_admin.php)
"${https[@]}" --fail "${cookie[@]}" "${update[@]}" -d action=preview "$web/admin/update.php" > "$qa/conflict.html"
grep -q 'Требуется ручное слияние' "$qa/conflict.html"
grep -q 'api/mobile_admin.php' "$qa/conflict.html"
code=$("${https[@]}" "${cookie[@]}" "${update[@]}" -o "$qa/rejected.json" -w '%{http_code}' "$web/admin/ajax_apply_update.php")
test "$code" = 400
jq -e '.success == false' "$qa/rejected.json"
test "$changed" = "$("${compose[@]}" exec -T apache sha256sum api/mobile_admin.php)"
"${compose[@]}" exec -T apache test ! -e includes/mobile_admin_directory.php
echo 'Installer refused a modified file without applying the rest of the package'

git -C "$checkout" show "$base_ref:api/mobile_admin.php" | "${compose[@]}" exec -T apache tee api/mobile_admin.php >/dev/null
# An ordinary write error must leave the source version usable, including on the first file.
source_hash=$("${compose[@]}" exec -T apache sha256sum api/mobile_admin.php)
for directory in api includes; do
    "${compose[@]}" exec -T apache chmod 0555 "$directory"
    code=$("${https[@]}" "${cookie[@]}" "${update[@]}" -o "$qa/write-error.json" -w '%{http_code}' "$web/admin/ajax_apply_update.php")
    "${compose[@]}" exec -T apache chmod 0755 "$directory"
    test "$code" = 400
    jq -e '.success == false and (.error | startswith("Не удалось установить "))' "$qa/write-error.json"
    test "$source_hash" = "$("${compose[@]}" exec -T apache sha256sum api/mobile_admin.php)"
    "${compose[@]}" exec -T apache test ! -e backups/updates/recovery.json
done
echo 'Failed first and later writes kept the source file and cleared maintenance mode'
"${https[@]}" --fail "${cookie[@]}" "${update[@]}" "$web/admin/ajax_apply_update.php" | jq -e '.success'
"${https[@]}" --fail "${cookie[@]}" "$web/api/mobile_admin.php?resource=accounts&q=example.invalid" |
    jq -e '.items | any(.email == "admin@example.invalid")'
verify='require "includes/db.php"; $d=(new Database())->getConnection();
 $v=$d->query("SELECT version FROM system_versions ORDER BY id DESC LIMIT 1")->fetchColumn();
 $n=$d->query("SELECT COUNT(*) FROM users WHERE email=\x27customer@example.invalid\x27")->fetchColumn();
 if ($v!=="2.5.1-beta8.41" || (int)$n!==1) exit(1); echo "$v and test account confirmed\n";'
"${compose[@]}" exec -T --user www-data apache php -r "$verify"
"${compose[@]}" up -d --no-deps --force-recreate apache nginx
ready
"${compose[@]}" exec -T --user www-data apache php -r "$verify"
"${compose[@]}" exec -T --user www-data apache php -l includes/mobile_admin_directory.php
echo 'Update and database survived recreation from the current release image'
