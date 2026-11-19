# Módulos por integração: incluir pela tela, liberar por usuário, login único de ponta a ponta com o módulo de exemplo,
# atualizar manifesto, desligar e remover.
# Antes: php tests/setup_local.php e subir o servidor local na porta 8090.
import base64, hashlib, hmac, json, os, re, shutil, sys, time, urllib.request, urllib.parse, html as H
sys.path.insert(0, "tests")
from smoke import get, post, token, login, session, BASE, BAD
ROOT = os.path.join(os.path.dirname(os.path.abspath(__file__)), "..")
SRC = os.path.join(ROOT, "kit-modulo", "modulo-exemplo")
DST = os.path.join(ROOT, "htdocs", "modulo-exemplo")
shutil.rmtree(DST, ignore_errors=True); shutil.copytree(SRC, DST)

def check(name, cond, extra=""):
    print(("OK   " if cond else "FAIL ") + name, extra if not cond else "")
    if not cond: check.f += 1
check.f = 0
class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *a, **k): return None
def cookies(op): return [h for h in op.handlers if h.__class__.__name__ == "HTTPCookieProcessor"]
def raw(op, r, **p):
    o = urllib.request.build_opener(NoRedirect, *cookies(op))
    try: res = o.open(BASE + "?" + urllib.parse.urlencode({"r": r, **p})); return res.status, "", res.read().decode()
    except urllib.error.HTTPError as e: return e.code, e.headers.get("Location", ""), e.read().decode("utf-8", "replace")
def fetch(op, path):
    try: res = op.open(BASE.rsplit("/", 1)[0] + "/" + path); return res.status, res.read().decode(), res.geturl()
    except urllib.error.HTTPError as e: return e.code, e.read().decode("utf-8", "replace"), ""
def b64e(b): return base64.urlsafe_b64encode(b).rstrip(b"=").decode()
def b64d(s): return base64.urlsafe_b64decode(s + "=" * (-len(s) % 4))
def secret(): return re.search(r"'secret' => '([a-f0-9]{64})'", open(os.path.join(DST, "summa.secret.php")).read()).group(1)
def act(op, acao):
    _, h, _ = get(op, "admin.modulo", m="exemplo")
    return post(op, "admin.modulo", {"_csrf": token(h), "acao": acao}, m="exemplo")

op, h, u = login("admin@academus.dev", "Aquinosys")
_, h, _ = get(op, "admin.controle")
check("Painel de controle mostra o card Módulos por integração", "Módulos por integração" in h and "r=admin.modulos" in h)
_, h, _ = get(op, "admin.modulos")
check("tela de módulos abre vazia", "Nenhum módulo incluído" in h and not BAD.search(h))

# 1. Recusas
for folder, msg in [("app", "é do próprio Summa"), ("nao-existe", "não existe no site"), ("../htdocs", "Informe só o nome da pasta"), ("assets", "é do próprio Summa")]:
    st, h2, _ = post(op, "admin.modulos", {"_csrf": token(h), "folder": folder})
    check(f"recusa a pasta {folder!r}", msg in h2 and "Nenhum módulo incluído" in h2, h2[h2.find("alert"):][:200])
mf = os.path.join(DST, "summa-module.json"); good = open(mf, encoding="utf-8").read()
for bad, msg in [(good.replace('"exemplo"', '"patrimonio"'), "já é usado pelo Summa"), (good.replace('"teal"', '"#ff0000"'), '&quot;color&quot; deve ser'),
                 (good.replace('"code": "edit"', '"code": "Edit!"'), "em minúsculas"), ("{nao é json", "não é um JSON válido"),
                 (good.replace('"blocks"', '"x\\"><script>"'), "ícone Lucide")]:
    open(mf, "w", encoding="utf-8").write(bad)
    st, h2, _ = post(op, "admin.modulos", {"_csrf": token(h), "folder": "modulo-exemplo"})
    check(f"recusa manifesto inválido ({msg})", msg in h2 and "Nenhum módulo incluído" in h2, h2[h2.find("alert"):][:240])
open(mf, "w", encoding="utf-8").write(good)

# 2. Incluir
st, h, u = post(op, "admin.modulos", {"_csrf": token(h), "folder": "Modulo-Exemplo/"})
check("inclui o módulo e abre a tela dele", "m=exemplo" in u and "Módulo de exemplo incluído" in h and not BAD.search(h), u)
check("segredo gravado na pasta do módulo", os.path.isfile(os.path.join(DST, "summa.secret.php")) and "está na pasta do módulo com o segredo atual" in h)
check("tela do módulo lista as três permissões", all(c in h for c in [">access<", ">edit<", ">admin<"]))
st, h2, _ = post(op, "admin.modulos", {"_csrf": token(get(op, "admin.modulos")[1]), "folder": "modulo-exemplo"})
check("não inclui o mesmo módulo duas vezes", "Já existe um módulo incluído" in h2)

# 3. Workspace e permissões
_, h, _ = get(op, "workspace")
check("card do módulo no Workspace do administrador", "<h2>Módulo de exemplo</h2>" in h and "index.php?r=modulo.exemplo" in h and not BAD.search(h))
_, h, _ = get(op, "admin.usuario_novo")
check("Módulos e áreas lista o módulo com as permissões dele", 'aria-label="Liberar o módulo Módulo de exemplo"' in h and all(f'value="x_exemplo:{c}"' in h for c in ["access", "edit", "admin"]))
EM = f"mod{int(time.time())}@academus.dev"
st, h, _ = post(op, "admin.usuario_novo", {"_csrf": token(h), "name": "Teste Módulo", "email": EM, "perm[]": ["x_exemplo:access", "x_exemplo:edit"]})
pw = re.search(r'user-select:all">([^<]+)<', h)
check("criar usuário só com o módulo", pw is not None and not BAD.search(h))
_, h, _ = get(op, "admin.usuarios", q=EM)
check("lista de usuários mostra o módulo liberado", "Módulo de exemplo" in h.split("<tbody>")[-1])
_, h, _ = get(op, "admin.modulos")
check("lista de módulos conta 1 usuário", re.search(r'<td class="tnum">3</td><td class="tnum">1</td>', h) is not None)

# 4. Login único de ponta a ponta
op2, h, u = login(EM, H.unescape(pw.group(1)) if pw else "")
st, h, u = post(op2, "senha", {"_csrf": token(h), "current": H.unescape(pw.group(1)), "password": "NovaSenha@2026", "confirm": "NovaSenha@2026"})
check("depois de criar a senha, cai direto no módulo, já sem o token no endereço", u.endswith("/modulo-exemplo/index.php") and "Olá, Teste Módulo" in h, u)
on = dict(re.findall(r'data-perm="([a-z]+)" data-on="([01])"', h))
check("módulo recebe só as permissões liberadas", on == {"access": "1", "edit": "1", "admin": "0"}, str(on))
st, loc, _ = raw(op2, "modulo.exemplo")
tok = urllib.parse.parse_qs(urllib.parse.urlparse(loc).query).get("sso", [""])[0]
body, sig = (tok.split(".") + [""])[:2]
c = json.loads(b64d(body)) if body else {}
check("token assinado com o segredo do módulo", st == 303 and loc.startswith("modulo-exemplo/index.php?sso=") and hmac.compare_digest(b64e(hmac.new(secret().encode(), body.encode(), hashlib.sha256).digest()), sig))
check("token identifica módulo, usuário e permissões", c.get("aud") == "exemplo" and c.get("u") == EM and c.get("p") == ["access", "edit"] and c.get("exp", 0) - c.get("iat", 0) == 120, str(c))
anon = session()
st, h, _ = fetch(anon, "modulo-exemplo/index.php?sso=" + urllib.parse.quote(tok))
check("token entra uma vez", st == 200 and "Olá, Teste Módulo" in h, str(st))
st, h, _ = fetch(session(), "modulo-exemplo/index.php?sso=" + urllib.parse.quote(tok))
check("o mesmo token não entra de novo", st == 403 and "já foi usado" in h, str(st))
forged = body + "." + b64e(hmac.new(b"x" * 64, body.encode(), hashlib.sha256).digest())
st, h, _ = fetch(session(), "modulo-exemplo/index.php?sso=" + urllib.parse.quote(forged))
check("token com outra assinatura é recusado", st == 403 and "assinatura inválida" in h, str(st))
fake = dict(c, p=["access", "edit", "admin"], jti="a" * 32); fb = b64e(json.dumps(fake).encode())
st, h, _ = fetch(session(), "modulo-exemplo/index.php?sso=" + urllib.parse.quote(fb + "." + sig))
check("token adulterado (permissão a mais) é recusado", st == 403, str(st))
st, h, u = fetch(session(), "modulo-exemplo/index.php")
check("sem sessão, o módulo manda entrar pelo Summa", "r=login" in u, u)

# 5. Usuário sem o módulo
op3, h, _ = login("financeiro@academus.dev", "Academus@2026")
st, loc, body3 = raw(op3, "modulo.exemplo")
check("sem permissão: 403 e nenhum token", st == 403 and "sso=" not in loc and "não está liberado" in body3, str(st))
st, _, _ = raw(op3, "admin.modulos")
check("quem não administra módulos não abre a tela", st == 403, str(st))
st, _, _ = raw(op, "modulo.naoexiste")
check("módulo inexistente: 404", st == 404, str(st))

# 6. Saída
st, loc, _ = raw(op2, "modulo_sair.exemplo")
check("saída sem assinatura não encerra a sessão", loc.endswith("r=workspace"), loc)
ts = str(int(time.time())); s = b64e(hmac.new(secret().encode(), ("sair|" + ts).encode(), hashlib.sha256).digest())
st, loc, _ = raw(op2, "modulo_sair.exemplo", t=ts, s=s); _, h, u = get(op2, "workspace")
check("saída assinada pelo módulo encerra a sessão do Summa", loc.endswith("r=login") and "r=login" in u, loc)

# 7. Atualizar manifesto: permissão retirada some dos usuários
open(mf, "w", encoding="utf-8").write(re.sub(r',\s*\{ "code": "edit"[^}]*\}', "", good).replace("Módulo de exemplo", "Exemplo renomeado"))
st, h, _ = act(op, "atualizar")
check("atualizar manifesto troca nome e retira a permissão", "Exemplo renomeado" in h and "retiradas dos usuários: edit" in h and ">edit<" not in h and not BAD.search(h))
op2, h, _ = login(EM, "NovaSenha@2026")
st, loc, _ = raw(op2, "modulo.exemplo")
c = json.loads(b64d(urllib.parse.parse_qs(urllib.parse.urlparse(loc).query)["sso"][0].split(".")[0]))
check("usuário fica só com a permissão que restou", c.get("p") == ["access"], str(c.get("p")))
open(mf, "w", encoding="utf-8").write(good.replace('"exemplo"', '"outro"'))
st, h, _ = act(op, "atualizar")
check("atualizar não aceita troca de slug", "O slug não pode mudar" in h)
open(mf, "w", encoding="utf-8").write(good)

# 8. Novo segredo, desligar, ligar
old = secret(); st, h, _ = act(op, "segredo")
check("novo segredo é gravado na pasta", secret() != old and "Novo segredo gerado e gravado" in h)
st, h, _ = act(op, "desligar")
_, w, _ = get(op, "workspace"); st2, _, _ = raw(op2, "modulo.exemplo"); _, un, _ = get(op, "admin.usuario_novo")
check("desligado: sai do Workspace, da rota e da tela de permissões", "Módulo desligado" in h and "r=modulo.exemplo" not in w and st2 == 404 and "x_exemplo:" not in un, str(st2))
st, h, _ = act(op, "ligar")
st2, loc, _ = raw(op2, "modulo.exemplo")
check("ligado de novo: permissões dos usuários continuam", st2 == 303 and "sso=" in loc, str(st2))

# 9. Remover
st, h, u = act(op, "remover")
check("remover volta à lista vazia", "removido do Summa" in h and "Nenhum módulo incluído" in h)
check("remover apaga o segredo e mantém a pasta", os.path.isdir(DST) and not os.path.exists(os.path.join(DST, "summa.secret.php")))
_, h, _ = get(op, "admin.usuarios", q=EM)
check("permissões do módulo saem dos usuários", "Sem permissões" in h.split("<tbody>")[-1], h.split("<tbody>")[-1][:300])
_, h, _ = get(op, "admin.auditoria", acao="module")
check("auditoria registra as operações", all(x in h for x in ["Módulo incluído por integração", "Manifesto do módulo", "desligado", "removido"]))
st, h, _ = fetch(session(), "modulo-exemplo/index.php")
check("módulo fora do Summa avisa que não foi incluído", st == 500 and "ainda não foi incluído no Summa" in h, str(st))

shutil.rmtree(DST, ignore_errors=True)
print("falhas:", check.f)
sys.exit(1 if check.f else 0)
