#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
php_bin=${PHP_BIN:-php}
test_dir=$(mktemp -d)
server=
cleanup() {
    if [ -n "$server" ]; then kill "$server" 2>/dev/null || :; wait "$server" 2>/dev/null || :; fi
    rm -rf "$test_dir"
}
trap cleanup EXIT
port=$(python3 -c 'import socket; s=socket.socket(); s.bind(("127.0.0.1",0)); print(s.getsockname()[1]); s.close()')
"$php_bin" -d "session.save_path=$test_dir" -S "127.0.0.1:$port" -t . tools/test-security-regressions.php > "$test_dir/server.log" 2>&1 &
server=$!
url="http://127.0.0.1:$port"
cookie="$test_dir/cookies"
for i in {1..30}; do
    curl -fs -c "$cookie" "$url/__session" > "$test_dir/before" && break
    sleep .1
done
grep -Fxq anonymous "$test_dir/before"
code=$(curl -sS -b "$cookie" -c "$cookie" -o /dev/null -w '%{http_code}' "$url/admin/test/backup_debug2.php")
test "$code" = 404 || test "$code" = 403
test "$(curl -fsS -b "$cookie" "$url/__session")" = anonymous
csrf=$(curl -fsS -b "$cookie" -c "$cookie" "$url/__csrf?token=1")
test "$(curl -sS -b "$cookie" -o /dev/null -w '%{http_code}' "$url/__csrf")" = 405
test "$(curl -sS -b "$cookie" -d 'form_csrf=invalid' -o /dev/null -w '%{http_code}' "$url/__csrf")" = 403
test "$(curl -fsS -b "$cookie" --data-urlencode "form_csrf=$csrf" "$url/__csrf")" = accepted
echo 'PASS deleted debug route left the browser unauthenticated; CSRF rejects GET and bad tokens'
