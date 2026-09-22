import csv
from collections import Counter
from urllib.parse import unquote

p = r"c:\Users\marks\Downloads\supabase_logs.csv"
path_c = Counter()
status_c = Counter()
sess_get = sess_patch = user_hits = n = 0
embed_gets = 0
plain_sess_gets = 0
ts_min = ts_max = None
ua_php = ua_dart = ua_other = 0
errors = 0

with open(p, encoding="utf-8", errors="replace") as f:
    for row in csv.DictReader(f):
        n += 1
        path = (row.get("pathname") or "").split("?")[0]
        method = (row.get("method") or "").strip('"')
        msg = row.get("event_message") or ""
        decoded = unquote(msg)
        st = (row.get("status") or "").strip()
        status_c[st] += 1
        if st and st not in ("200", "201", "204", "304"):
            errors += 1
        ts = (row.get("timestamp") or "").strip()
        if ts:
            if ts_min is None or ts < ts_min:
                ts_min = ts
            if ts_max is None or ts > ts_max:
                ts_max = ts
        path_c[f"{method} {path}"] += 1
        if "mobile_sessions" in path:
            if method == "GET":
                sess_get += 1
                if "users(" in decoded:
                    embed_gets += 1
                else:
                    plain_sess_gets += 1
            if method == "PATCH":
                sess_patch += 1
        if "/rest/v1/users" in path and "limit=1" in msg:
            user_hits += 1
        low = msg.lower()
        if "dart/" in low:
            ua_dart += 1
        elif " | null" in msg or msg.endswith("| null"):
            ua_php += 1

print("rows", n)
print("window", ts_min, "->", ts_max)
print("GET mobile_sessions", sess_get, "embed", embed_gets, "plain", plain_sess_gets)
print("PATCH mobile_sessions", sess_patch)
print("users limit=1", user_hits)
print("non-2xx-ish", errors)
print("status", dict(status_c.most_common(8)))
print("--- top 25 ---")
for k, v in path_c.most_common(25):
    pct = 100.0 * v / n if n else 0
    print(f"{v:6d}  {pct:5.1f}%  {k}")
