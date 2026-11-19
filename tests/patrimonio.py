# Módulo Patrimônio no Summa: card no Workspace, liberação por usuário, login único e saída assinada.
# Antes: php tests/setup_local.php (já grava o segredo de teste no config.php) e subir o servidor local na porta 8090.
import base64, hashlib, hmac, json, os, re, sys, time, urllib.request, urllib.parse, html as H
sys.path.insert(0, "tests")
from smoke import get, post, token, login, session, BASE, BAD
SECRET = "s" * 64  # o mesmo de tests/setup_local.php
def check(name, cond, extra=""):
    print(("OK   " if cond else "FAIL ") + name, extra if not cond else "")
    if not cond: check.f += 1
check.f = 0

class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *a, **k): return None
def raw(op, r, **p):
    """GET sem seguir o redirecionamento: devolve (status, Location, corpo)."""
    o = urllib.request.build_opener(NoRedirect, *[h for h in op.handlers if h.__class__.__name__ == "HTTPCookieProcessor"])
    try: res = o.open(BASE + "?" + urllib.parse.urlencode({"r": r, **p})); return res.status, "", res.read().decode()
    except urllib.error.HTTPError as e: return e.code, e.headers.get("Location", ""), e.read().decode("utf-8", "replace")
def b64d(s): return base64.urlsafe_b64decode(s + "=" * (-len(s) % 4))
def b64e(b): return base64.urlsafe_b64encode(b).rstrip(b"=").decode()
def claims(loc):
    tok = urllib.parse.parse_qs(urllib.parse.urlparse(loc).query)["sso"][0]
    body, sig = tok.split(".")
    ok = hmac.compare_digest(b64e(hmac.new(SECRET.encode(), body.encode(), hashlib.sha256).digest()), sig)
    return json.loads(b64d(body)), ok

# 1. Administrador: card no Workspace e login único com todas as permissões
op, h, u = login("admin@academus.dev", "Aquinosys")
_, h, _ = get(op, "workspace")
check("card Patrimônio no Workspace do administrador", "<h2>Patrimônio</h2>" in h and "index.php?r=patrimonio" in h and not BAD.search(h))
check("card não aparece mais como 'em breve'", not re.search(r"Patrimônio</strong>\s*<span class=\"muted\">· em breve", h))
st, loc, _ = raw(op, "patrimonio")
c, ok = claims(loc) if "sso=" in loc else ({}, False)
check("rota entrega token assinado para patrimonio/", st == 303 and loc.startswith("patrimonio/index.php?sso=") and ok, f"{st} {loc[:60]}")
check("administrador leva todas as permissões do módulo", sorted(c.get("p", [])) == sorted(["edit_invoices", "edit_assets", "manage_catalogs", "export_data", "manage_users", "restore_backup"]), str(c.get("p")))
check("token vale 2 minutos e é para o Patrimônio", c.get("aud") == "patrimonio" and c.get("exp", 0) - c.get("iat", 0) == 120 and c.get("u") == "admin@academus.dev")

check("sem foto de perfil, o token leva a foto vazia", c.get("a") == "")
import sqlite3
dbf = os.path.join(os.path.dirname(os.path.abspath(__file__)), "test.sqlite")
def set_avatar(v):
    con = sqlite3.connect(dbf); con.execute("UPDATE users SET avatar_path = ? WHERE email = 'admin@academus.dev'", (v,)); con.commit(); con.close()
set_avatar("uploads/avatars/u1-abc123.jpg"); c, ok = claims(raw(op, "patrimonio")[1])
check("token leva o caminho da foto de perfil", ok and c.get("a") == "uploads/avatars/u1-abc123.jpg", str(c.get("a")))
for bad in ["../config.php", "uploads/../config.php", "https://exemplo.com/x.jpg", "uploads/x.php", "uploads/a b.jpg"]:
    set_avatar(bad); c, ok = claims(raw(op, "patrimonio")[1])
    check(f"caminho de foto fora do padrão não vai no token ({bad})", ok and c.get("a") == "", str(c.get("a")))
set_avatar(None)

# 2. Tela de permissões mostra o módulo com as sete permissões
_, h, _ = get(op, "admin.usuario_novo")
check("Módulos e áreas lista o Patrimônio", 'aria-label="Liberar o módulo Patrimônio"' in h and len(re.findall(r'value="patrimony:[a-z]+"', h)) == 7)

# 3. Usuário só com consulta e exportação
EM = f"patr{int(time.time())}@academus.dev"
st, h, _ = post(op, "admin.usuario_novo", {"_csrf": token(h), "name": "Teste Patrimônio", "email": EM, "perm[]": ["patrimony:access", "patrimony:export"]})
pw = re.search(r'user-select:all">([^<]+)<', h)
check("criar usuário só com Patrimônio", pw is not None and not BAD.search(h))
_, h, _ = get(op, "admin.usuarios", q=EM)
check("lista de usuários mostra o módulo liberado", "Patrimônio" in h.split("<tbody>")[-1])
op2, h, u = login(EM, H.unescape(pw.group(1)) if pw else "")
st, h, u = post(op2, "senha", {"_csrf": token(h), "current": H.unescape(pw.group(1)), "password": "NovaSenha@2026", "confirm": "NovaSenha@2026"})
op2 = session(); _, h, _ = get(op2, "login")
o = urllib.request.build_opener(NoRedirect, *[x for x in op2.handlers if x.__class__.__name__ == "HTTPCookieProcessor"])
try: o.open(BASE + "?r=login", urllib.parse.urlencode({"_csrf": token(h), "email": EM, "password": "NovaSenha@2026"}).encode()); first = ""
except urllib.error.HTTPError as e: first = e.headers.get("Location", "")
check("quem só tem o Patrimônio entra direto no módulo", first.endswith("r=patrimonio"), first)
st, loc, _ = raw(op2, "patrimonio")
c, ok = claims(loc) if "sso=" in loc else ({}, False)
check("token leva só as permissões liberadas", ok and c.get("p") == ["export_data"] and c.get("u") == EM, str(c.get("p")))
_, h, _ = get(op2, "workspace")
check("Workspace dele mostra Consulta e Exportação", "<li>Consulta</li>" in h and "<li>Exportação</li>" in h and "<li>Ativos e baixas</li>" not in h)

# 4. Usuário sem o módulo
op3, h, _ = login("financeiro@academus.dev", "Academus@2026")
st, loc, body = raw(op3, "patrimonio")
check("sem permissão do módulo: 403 e nenhum token", st == 403 and "sso=" not in loc and "não está liberado" in body, str(st))
_, h, _ = get(op3, "fin.inicio")
check("menu de módulos dele não oferece o Patrimônio", "r=patrimonio" not in h)

# 5. Saída
st, loc, _ = raw(op, "patrimonio_sair")
_, h, u = get(op, "admin.controle")
check("link de saída sem assinatura não encerra a sessão", loc.endswith("r=workspace") and "r=login" not in u, loc)
ts = str(int(time.time())); bad = b64e(hmac.new(b"x" * 64, ("sair|" + ts).encode(), hashlib.sha256).digest())
raw(op, "patrimonio_sair", t=ts, s=bad); _, h, u = get(op, "admin.controle")
check("assinatura com outro segredo não encerra a sessão", "r=login" not in u)
old = str(int(time.time()) - 600); sig = b64e(hmac.new(SECRET.encode(), ("sair|" + old).encode(), hashlib.sha256).digest())
raw(op, "patrimonio_sair", t=old, s=sig); _, h, u = get(op, "admin.controle")
check("assinatura vencida não encerra a sessão", "r=login" not in u)
sig = b64e(hmac.new(SECRET.encode(), ("sair|" + ts).encode(), hashlib.sha256).digest())
st, loc, _ = raw(op, "patrimonio_sair", t=ts, s=sig); _, h, u = get(op, "admin.controle")
check("saída assinada encerra a sessão do Summa", loc.endswith("r=login") and "r=login" in u, loc + " " + u)
print("falhas:", check.f)
sys.exit(1 if check.f else 0)
