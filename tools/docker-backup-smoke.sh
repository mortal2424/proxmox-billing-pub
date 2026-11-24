# Sourced only by tools/docker-smoke.sh after a disposable Compose stack has started.
set -E
trap 'printf "Backup smoke failed at line %s\n" "$LINENO" >&2' ERR
"${compose[@]}" cp tools/docker-backup-smoke.php apache:/tmp/docker-backup-smoke.php
app=("${compose[@]}" exec -T --user www-data apache)
fixture=("${compose[@]}" exec -T --user www-data -e HOMEVLAD_DOCKER_SMOKE=1 apache php /tmp/docker-backup-smoke.php)
smoke_password=$(openssl rand -hex 24)
printf '%s' "$smoke_password" | "${fixture[@]}" seed
config_hash=$("${app[@]}" sha256sum includes/config.php)

new_session() {
    local data
    data=$("${fixture[@]}" session)
    cookie=(--cookie "PHPSESSID=$(jq -er '.sid' <<< "$data")")
    backup_csrf=$(jq -er '.csrf' <<< "$data")
}
backup_action() {
    local action=$1
    shift
    local response
    if ! response=$("${https[@]}" --fail-with-body "${cookie[@]}" -H 'X-Backup-Action: 1' \
        --data-urlencode "backup_csrf=$backup_csrf" "$@" "$web/admin/backup.php?action=$action"); then
        jq -r '.message // "No error message"' <<< "$response" >&2
        return 1
    fi
    printf '%s\n' "$response"
}
schedule() {
    backup_action save_schedule -d "id=$1" --data-urlencode "name=$2" -d "keep_count=$3" \
        -d schedule_type=daily -d schedule_time=03:00 -d backup_type=db "${@:4}" | jq -e '.success'
}
scheduled_files() {
    "${app[@]}" sh -c 'find admin/backups -maxdepth 1 -type f -name "schedule_${1}_*.zip" -printf "%f\n" | sort' sh "$1"
}
manual_archive() {
    backup_action create -d "backup_type=$1" -d comment=SmokeArchive |
        jq -er 'select(.success == true) | .message | capture("(?<archive>backup_[A-Za-z0-9_.-]+\\.zip)$").archive'
}
hex_of_file() {
    "${app[@]}" sha256sum "admin/backups/$1"
}

new_session
if ! manual=$(manual_archive db); then
    "${app[@]}" sh -c 'MYSQL_PWD="$DB_PASSWORD" mariadb-dump --single-transaction --skip-lock-tables --no-tablespaces --hex-blob -h "$DB_HOST" -u "$DB_USER" "$DB_NAME" >/dev/null' || true
    exit 1
fi
manual_hash=$(hex_of_file "$manual")
schedule 0 'Smoke A' 5
schedule 0 'Smoke B' 5
a=$("${fixture[@]}" schedule 'Smoke A')
b=$("${fixture[@]}" schedule 'Smoke B')
for index in {1..4}; do
    backup_action "run_now&id=$a" -d run=1 | jq -e '.success'
    sleep 1
done
backup_action "run_now&id=$b" -d run=1 | jq -e '.success'
four=$(scheduled_files "$a")
other=$(scheduled_files "$b")
test "$(wc -l <<< "$four")" = 4
test -n "$other"
"${https[@]}" --fail "${cookie[@]}" "$web/admin/backup.php" > "$qa/backup.html"
node - "$qa/backup.html" "$a" <<'JS'
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const html = fs.readFileSync(process.argv[2], 'utf8');
const script = html.match(/function updateRetentionOffer\(\) \{[\s\S]*?^\}/m)?.[0];
assert(script, 'Retention function missing from the rendered page');
const input = {value: '2'};
const check = {checked: false};
const offer = {};
const elements = {
    scheduleId: {value: process.argv[3]},
    scheduleForm: {querySelector: name => name.includes('keep_count') ? input : check},
    retentionOffer: offer,
};
const document = {getElementById: id => elements[id]};
vm.runInNewContext(script + '\nupdateRetentionOffer();', {document});
assert.equal(check.disabled, false);
assert.equal(check.checked, false);
assert.match(offer.textContent, /2 из 4/);
check.checked = true;
input.value = '5';
vm.runInNewContext(script + '\nupdateRetentionOffer();', {document});
assert.equal(check.disabled, true);
assert.equal(check.checked, false);
console.log('Retention UI offered deletion of two out of four with the checkbox off');
JS
schedule "$a" 'Smoke A' 2
test "$four" = "$(scheduled_files "$a")"
schedule "$a" 'Smoke A' 2 -d prune_existing=1
newest=$(tail -2 <<< "$four")
test "$newest" = "$(scheduled_files "$a")"
test "$other" = "$(scheduled_files "$b")"
test "$manual_hash" = "$(hex_of_file "$manual")"
echo 'Saved the smaller limit, required an explicit opt-in for immediate deletion, preserved the newest copies and other archives'

"${fixture[@]}" due "$a"
cron_completed=false
for attempt in {1..45}; do
    current=$(scheduled_files "$a")
    if [[ "$current" != "$newest" && "$(wc -l <<< "$current")" = 2 ]]; then
        cron_completed=true
        break
    fi
    sleep 2
done
test "$cron_completed" = true
latest=$(tail -1 <<< "$current")
test "$(head -1 <<< "$current")" = "$(tail -1 <<< "$newest")"
test "$other" = "$(scheduled_files "$b")"
test "$( "${app[@]}" stat -c '%U:%a' "admin/backups/$latest" )" = www-data:600
"${https[@]}" --fail "${cookie[@]}" --get -d action=download --data-urlencode "file=$latest" "$web/admin/backup.php" > "$qa/cron.zip"
unzip -t "$qa/cron.zip" >/dev/null
echo 'The real Docker cron created a private downloadable archive and enforced only its schedule limit'
"${compose[@]}" exec -T apache service cron stop

"${compose[@]}" exec -T apache sh -c 'printf site-before > static/backup-smoke.txt'
full=$(manual_archive full)
"${compose[@]}" exec -T apache sh -c 'printf site-after > static/backup-smoke.txt'
full_hash=$(hex_of_file "$full")
preview_restore() {
    "${https[@]}" --fail "${cookie[@]}" --get --data-urlencode "file=$full" "$web/admin/backup_restore.php" > "$qa/restore.html"
    grep -q 'База данных: есть' "$qa/restore.html"
    grep -q 'Файлы и база данных' "$qa/restore.html"
    grep -q 'uploads/backup-smoke.txt' "$qa/restore.html"
    preview=$(sed -n 's/.*name="preview_token" value="\([a-f0-9]*\)".*/\1/p' "$qa/restore.html")
    test "${#preview}" = 48
}
restore() {
    "${https[@]}" --max-time 180 --fail-with-body "${cookie[@]}" \
        --data-urlencode "backup_file=$full" --data-urlencode "backup_csrf=$backup_csrf" \
        --data-urlencode "preview_token=$preview" --data-urlencode "restore_type=$1" \
        --data-urlencode "confirm_password=$smoke_password" --data-urlencode 'confirmation=ВОССТАНОВИТЬ' \
        "$web/admin/backup_restore.php"
}
"${fixture[@]}" change
preview_restore
"${compose[@]}" exec -T apache chmod 0555 uploads
restore full > "$qa/restore-rollback.html"
"${compose[@]}" exec -T apache chmod 0755 uploads
grep -q 'Не удалось заменить файл' "$qa/restore-rollback.html"
"${fixture[@]}" verify changed changed
"${app[@]}" sh -c 'find backups/restore -name result.json -exec cat {} \;' | jq -e 'select(.rolled_back == true)'
echo 'A file write failure rolled back the imported database without leaving the panel in maintenance mode'

preview_restore
restore full > "$qa/restored.html"
grep -q 'Восстановление завершено' "$qa/restored.html"
"${fixture[@]}" verify restored restored
test "$config_hash" = "$( "${app[@]}" sha256sum includes/config.php )"
test "$full_hash" = "$(hex_of_file "$full")"
test "$manual_hash" = "$(hex_of_file "$manual")"
"${app[@]}" sh -c 'find backups/restore -name database-before.sql -type f -print | grep -q .'
echo 'Full archive restored DB and uploads while preserving image files, config, and safety copy'

new_session
"${fixture[@]}" change
preview_restore
restore db > "$qa/restored-db.html"
grep -q 'Восстановление завершено' "$qa/restored-db.html"
"${fixture[@]}" verify restored changed
new_session
"${fixture[@]}" change
preview_restore
restore files > "$qa/restored-files.html"
grep -q 'Восстановление завершено' "$qa/restored-files.html"
"${fixture[@]}" verify changed restored
echo 'DB-only and files-only modes left the unselected component intact'
