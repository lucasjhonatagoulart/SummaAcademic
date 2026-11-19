# Entra com cada usuário e abre todas as rotas, procurando erros de PHP.
import re, sys, urllib.request, urllib.parse, http.cookiejar
import os
BASE = os.environ.get("BASE", "http://127.0.0.1:8090/index.php")
BAD = re.compile(r"(Fatal error|Warning:|Notice:|Deprecated:|Uncaught|Parse error|TypeError)")
def session():
    cj = http.cookiejar.CookieJar(); op = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(cj)); return op
def get(op, r, **p):
    q = urllib.parse.urlencode({"r": r, **p}); 
    try: res = op.open(f"{BASE}?{q}"); return res.status, res.read().decode("utf-8", "replace"), res.geturl()
    except urllib.error.HTTPError as e: return e.code, e.read().decode("utf-8", "replace"), ""
def token(html): m = re.search(r'name="_csrf" value="([^"]+)"', html); return m.group(1) if m else ""
def post(op, r, data, **p):
    q = urllib.parse.urlencode({"r": r, **p})
    try: res = op.open(f"{BASE}?{q}", urllib.parse.urlencode(data, doseq=True).encode()); return res.status, res.read().decode("utf-8", "replace"), res.geturl()
    except urllib.error.HTTPError as e: return e.code, e.read().decode("utf-8", "replace"), ""
def login(email, pw):
    op = session(); _, html, _ = get(op, "login")
    st, html, url = post(op, "login", {"_csrf": token(html), "email": email, "password": pw})
    return op, html, url
if __name__ == "__main__":
    email, pw = sys.argv[1], sys.argv[2]; routes = sys.argv[3:]
    op, html, url = login(email, pw)
    print("login ->", url.split("?")[-1])
    fails = 0
    for r in routes:
        rr, _, qs = r.partition("?"); params = dict(urllib.parse.parse_qsl(qs))
        st, html, u = get(op, rr, **params)
        m = BAD.search(html); title = re.search(r"<h1[^>]*>(.*?)</h1>", html, re.S)
        ok = st == 200 and not m
        if not ok: fails += 1
        print(("OK  " if ok else "FAIL"), st, r, "|", (re.sub("<[^>]+>", "", title.group(1)).strip()[:50] if title else "-"), ("| " + html[m.start():m.start()+300].replace("\n"," ") if m else ""))
    print("falhas:", fails)
