#!/usr/bin/env bash
set -Eeuo pipefail
trap 'echo "Smoke test failed at line $LINENO" >&2' ERR

checkout=$(git rev-parse --show-toplevel)
cd "$checkout"
base_ref=v2.5.1-Pre-release1
test_version=2.5.1-rc1.1
qa=$(mktemp -d)
export COMPOSE_PROJECT_NAME=homevlad-docker-smoke
export DOMAIN=nginx EMAIL=qa@example.invalid DB_NAME=homevlad_smoke DB_USER=homevlad_smoke
export DB_PASSWORD="$(openssl rand -hex 24)" MYSQL_ROOT_PASSWORD="$(openssl rand -hex 24)"
export PROXMOX_NODE_KEY="$(openssl rand -base64 32)"
export PROXMOX_CA_FILE=/etc/proxmox-tls/smoke.pem
export HOMEVLAD_LICENSE_REQUIRED=1 HOMEVLAD_LICENSE_CONFIG=/run/homevlad-license/license.php
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
git archive HEAD | tar -x --exclude=uploads --exclude=logs --exclude=backups -C "$qa/checkout"
cd "$qa/checkout"
mkdir -p logs uploads backups docker/proxmox-tls webroot .docker-private/license
cat > .docker-private/license/license.php <<'PHP'
<?php
return ['client'=>'/run/homevlad-license/client.php',
    'key_file'=>'/var/lib/homevlad-private/license/activation.key',
    'cache'=>'/var/lib/homevlad-private/license/lease.json',
    'public_key'=>'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA',
    'domain'=>'smoke.example.invalid','source_ip'=>'192.0.2.7','nonce'=>str_repeat('0',64)];
PHP
cat > .docker-private/license/client.php <<'PHP'
<?php
function licenseTrialUntil(array $config, int $now): int { return $now + 3600; }
function pairingRequest(...$args): string { return 'smoke'; }
function readPairingRequest(string $request): array { return ['installation'=>str_repeat('a',32)]; }
function currentLease(string $key, ...$args): array {
    if ($key === 'rejected-fixture') throw new DomainException('Fixture denial');
    if ($key === 'refresh-fixture' && PHP_SAPI === 'cli' && basename($_SERVER['argv'][0]) === 'refresh_license.php') {
        file_put_contents('/var/lib/homevlad-private/license/worker-refresh', "refreshed\n");
    }
    return ['limits'=>['vms_running'=>0]];
}
PHP
chmod 0755 .docker-private .docker-private/license
chmod 0644 .docker-private/license/*.php
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

https=(curl --silent --show-error --max-time 50 --resolve nginx:443:127.0.0.1 --cacert "$PWD/docker/proxmox-tls/smoke.pem")
web=https://nginx
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
"${compose[@]}" cp tests/licensing_config_test.php apache:/tmp/licensing_config_test.php
"${compose[@]}" exec -T --user www-data apache php /tmp/licensing_config_test.php /var/www/html
image_version=$("${compose[@]}" exec -T apache php -r 'require "includes/version.php"; echo HOMEVLAD_VERSION;')
echo "Built Docker image for $image_version"
wrong_host=$("${https[@]}" -H 'Host: unknown.example.invalid' -o /dev/null -w '%{http_code}' "$web/")
test "$wrong_host" = 421
"${compose[@]}" exec -T nginx nginx -t
"${compose[@]}" exec -T apache apache2ctl -t
if [[ ${1:-} == backup ]]; then
    source tools/docker-backup-smoke.sh
    exit
fi
"${compose[@]}" exec -T apache sh -c 'cat >/tmp/homevlad-docker-pve.key; chmod 0600 /tmp/homevlad-docker-pve.key' < "$qa/pve.key"
"${compose[@]}" cp tools/docker-smoke.py apache:/tmp/docker-smoke.py
"${compose[@]}" cp tools/docker-smoke.php apache:/tmp/docker-smoke.php
"${compose[@]}" exec -d apache /usr/bin/python3 /tmp/docker-smoke.py serve
"${compose[@]}" exec -T --user www-data -e HOMEVLAD_DOCKER_SMOKE=1 apache php /tmp/docker-smoke.php
"${compose[@]}" exec -T --user www-data apache /usr/bin/python3 /tmp/docker-smoke.py test
"${compose[@]}" exec -T --user www-data apache /usr/bin/python3 /tmp/docker-smoke.py license
"${compose[@]}" exec -T --user www-data apache php -r '
    require "includes/console_settings.php";
    $status=consoleBrokerStatus();
    saveConsoleSettings($status["origins"][0],45,$status,[],["url"=>null]);'


session=$("${compose[@]}" exec -T --user www-data apache cat /tmp/homevlad-docker-sessions.json)
sid=$(jq -r '.sessions.admin.id' <<< "$session")
csrf=$(jq -r '.sessions.admin.csrf' <<< "$session")
cookie=(--cookie "PHPSESSID=$sid")
node_csrf=$(jq -r '.sessions.admin.node_csrf' <<< "$session")
mobile_csrf=$(jq -r '.sessions.admin.mobile_csrf' <<< "$session")
check_zone_count() {
    result=$("${compose[@]}" exec -T --user www-data apache php -r '
        require "includes/db.php";
        echo (new Database())->getConnection()->query(
            "SELECT COUNT(*) FROM proxmox_clusters WHERE name LIKE \x27qa-license-%\x27"
        )->fetchColumn();')
    echo "License zone fixtures: expected $1, got $result"
    test "$result" = "$1"
}
try_zone_creation() {
    "${https[@]}" "${cookie[@]}" --data-urlencode "node_csrf=$node_csrf" \
        -d "add_cluster=1&name=qa-license-web-$1" "$web/admin/add_cluster.php" -o "$qa/license-web.html"
    jq -nc --arg csrf "$mobile_csrf" --arg name "qa-license-mobile-$1" \
        '{action:"zone_save",csrf:$csrf,name:$name,active:true,maintenance:false,limit:20,threshold:80}' |
        "${https[@]}" "${cookie[@]}" -H 'Content-Type: application/json' --data-binary @- \
            "$web/api/mobile_admin.php" -o "$qa/license-mobile.json"
}
try_account_creation() {
    jq -nc --arg csrf "$mobile_csrf" --arg email "qa-license-$1@example.invalid" \
        '{action:"account_save",csrf:$csrf,email:$email,fullName:"License fixture",type:"individual",
          password:"qa-test-password",isActive:true,isAdmin:false}' |
        "${https[@]}" "${cookie[@]}" -H 'Content-Type: application/json' --data-binary @- \
            "$web/api/mobile_admin.php" -o "$qa/license-account.json"
}
mv .docker-private/license/license.php .docker-private/license/license.php.disabled
try_zone_creation missing
check_zone_count 0
jq -e '.error | contains("Настройки лицензии недоступны")' "$qa/license-mobile.json"
try_account_creation missing
jq -e '.error | contains("Настройки лицензии недоступны")' "$qa/license-account.json"
mv .docker-private/license/license.php.disabled .docker-private/license/license.php
try_account_creation trial
jq -e '.success and (.id > 0)' "$qa/license-account.json"
"${compose[@]}" exec -T --user www-data apache php -r '
    require "includes/db.php";
    $db = (new Database())->getConnection();
    $accounts = $db->query("SELECT email FROM users WHERE email LIKE \x27qa-license-%@example.invalid\x27")->fetchAll(PDO::FETCH_COLUMN);
    if ($accounts !== ["qa-license-trial@example.invalid"]) exit(1);'
try_zone_creation trial
jq -e '.success == true' "$qa/license-mobile.json"
check_zone_count 2

# Exercise the previous build with its matching version in the disposable database.
"${compose[@]}" exec -T --user www-data apache php -r '
    require "includes/db.php";
    $db = (new Database())->getConnection();
    $db->prepare("INSERT INTO system_versions (version) VALUES (?) ON DUPLICATE KEY UPDATE version = VALUES(version)")->execute([$argv[1]]);
' "${base_ref#v}"
manifest="updates/$test_version/manifest.json"
jq -r '.files | keys[]' "$manifest" | while IFS= read -r file; do
    git -C "$checkout" show "$base_ref:$file" | "${compose[@]}" exec -T apache tee "$file" >/dev/null
done
"${compose[@]}" exec -T --user www-data apache php cronjobs/check_updates.php
"${https[@]}" --fail "${cookie[@]}" "$web/admin/check_updates_count.php" | jq -e '.success and (.count >= 1)'

update=(--data-urlencode "version=$test_version" --data-urlencode "csrf_token=$csrf")
"${https[@]}" --fail "${cookie[@]}" "${update[@]}" -d action=preview "$web/admin/update.php" > "$qa/preview.html"
grep -q "Пакет $test_version" "$qa/preview.html"
grep -q 'v2.5.1-Pre-release1.1' "$qa/preview.html"
if grep -q 'Требуется ручное слияние' "$qa/preview.html"; then exit 1; fi
code=$("${https[@]}" "${cookie[@]}" "${update[@]}" -o "$qa/rejected.json" -w '%{http_code}' "$web/admin/ajax_apply_update.php")
test "$code" = 403
jq -e '.success == false' "$qa/rejected.json"
"${compose[@]}" exec -T --user www-data apache sh -c 'test ! -w . && test ! -w includes && test ! -w vendor && test -w backups'
verify='require "includes/db.php"; $d=(new Database())->getConnection();
 $n=$d->query("SELECT COUNT(*) FROM users WHERE email=\x27customer@example.invalid\x27")->fetchColumn();
 if ((int)$n!==1) exit(1);'
"${compose[@]}" exec -T --user www-data apache php -r "$verify"
"${compose[@]}" exec -T --user www-data -e HOMEVLAD_LICENSE_CONFIG=/run/homevlad-license/license.php apache php -r '
    require "includes/license_guard.php";
    $c = homevladLicenseConfig();
    if (!extension_loaded("sodium") || !is_readable($c["client"]) ||
        is_writable($c["client"]) || file_put_contents($c["cache"], "test lease") === false) exit(1);
    if (file_put_contents($c["key_file"], "refresh-fixture") === false) exit(1);'
"${compose[@]}" up -d --no-deps --force-recreate apache nginx
ready
"${compose[@]}" exec -T --user www-data apache php -r "$verify"
"${compose[@]}" exec -T --user www-data apache php -l includes/mobile_admin_directory.php
"${compose[@]}" exec -T --user www-data apache grep -Fqx "test lease" /var/lib/homevlad-private/license/lease.json
for attempt in {1..20}; do
    if "${compose[@]}" exec -T --user www-data apache grep -Fqx refreshed /var/lib/homevlad-private/license/worker-refresh; then
        break
    fi
    sleep 1
done
"${compose[@]}" exec -T --user www-data apache grep -Fqx refreshed /var/lib/homevlad-private/license/worker-refresh
"${compose[@]}" exec -T --user www-data apache php -r 'require "includes/console_settings.php"; $status=consoleBrokerStatus(); if ($status["status"]!=="ok" || consoleSettings()["ticket_ttl"]!==45) exit(1);'
test "$image_version" = "$("${compose[@]}" exec -T apache php -r 'require "includes/version.php"; echo HOMEVLAD_VERSION;')"
echo "Recreated the $image_version image and verified the existing database"
