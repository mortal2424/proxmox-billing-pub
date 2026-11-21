#!/usr/bin/env python3
"""Run inside a dedicated Linux guest and send a signed result to HomeVlad."""

import hashlib
import hmac
import json
import subprocess
import sys
import time
import urllib.request
from pathlib import Path


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None


def check(url):
    result = subprocess.run(
        ["curl", "-q", "--noproxy", "*", "--proto", "=https", "--head",
         "--connect-timeout", "4", "--max-time", "8", "--silent",
         "--output", "/dev/null", "--write-out", "%{http_code}", url],
        capture_output=True, text=True, timeout=10, check=False,
    )
    return result.returncode == 0 and result.stdout == "200"


def main():
    conf = json.loads(Path(sys.argv[1]).read_text())
    report_url = conf["report_url"]
    if not report_url.startswith("https://"):
        raise ValueError("The report endpoint must use HTTPS")

    report = {"node_id": conf["node_id"], "checked_at": int(time.time()),
              "debian": check("https://www.debian.org/"),
              "iana": check("https://www.iana.org/")}
    body = json.dumps(report, separators=(",", ":")).encode()
    signature = hmac.new(conf["key"].encode(), body, hashlib.sha256).hexdigest()
    request = urllib.request.Request(report_url, body, {
        "Content-Type": "application/json", "X-HomeVlad-Signature": signature,
    })
    with urllib.request.build_opener(urllib.request.ProxyHandler({}), NoRedirect()).open(request, timeout=10) as response:
        if response.status != 204:
            raise RuntimeError(f"HomeVlad returned HTTP {response.status}")
    print(f"Debian={report['debian']} IANA={report['iana']}")


if __name__ == "__main__":
    main()
