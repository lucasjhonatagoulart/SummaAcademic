# Módulo Setor de TI incluído por integração (slug "ti", que o Summa anunciava como "em breve").
# Usa o módulo real, copiado para htdocs/ti, com um banco SQLite criado por este teste.
# Antes: php tests/setup_local.php e servidor na porta 8090.
import json, os, re, sys, time, urllib.request, urllib.parse, html as H
sys.path.insert(0, "tests")
from smoke import get, post, token, login, session, BASE, BAD
SITE = BASE.rsplit("/", 1)[0] + "/"

# Prepara o banco de teste do TI (SQLite) e aponta o config.php da cópia de teste para ele.
# Só mexe no config.php se ele ainda for o modelo do pacote ou já for o de teste: nunca em um config de verdade.
import sqlite3, subprocess
TI = os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "htdocs", "ti")
CFG = os.path.join(TI, "config.php"); DBF = os.path.join(os.path.dirname(os.path.abspath(__file__)), "ti-test.sqlite")
if not os.path.isdir(TI): sys.exit("Copie a pasta ti do pacote Summa-TI para htdocs/ti antes de rodar este teste.")
atual = open(CFG, encoding="utf-8").read() if os.path.isfile(CFG) else ""
if atual and "'sqlite'" not in atual: sys.exit("htdocs/ti/config.php parece ser um config de verdade; este teste não o altera.")
open(CFG, "w", encoding="utf-8").write("<?php\n$CONFIG = ['db_driver' => 'sqlite', 'db_name' => %r, 'timezone' => 'America/Sao_Paulo'];\n" % DBF)
for f in [DBF, os.path.join(TI, "summa.secret.php")]:
    if os.path.exists(f): os.remove(f)
con = sqlite3.connect(DBF)
con.executescript("""
CREATE TABLE accounts (id INTEGER PRIMARY KEY AUTOINCREMENT, nome TEXT NOT NULL, email TEXT NOT NULL UNIQUE, senha_hash TEXT NOT NULL, role TEXT NOT NULL DEFAULT 'solicitante', ativo INTEGER NOT NULL DEFAULT 1, trocar_senha INTEGER NOT NULL DEFAULT 0, criado_em TEXT NOT NULL, ultimo_acesso TEXT);
CREATE TABLE docs (colecao TEXT NOT NULL, doc_id TEXT NOT NULL, dados TEXT NOT NULL, atualizado_em TEXT NOT NULL, PRIMARY KEY (colecao, doc_id));
CREATE TABLE versoes (colecao TEXT PRIMARY KEY, versao INTEGER NOT NULL DEFAULT 1);
CREATE TABLE tentativas_login (id INTEGER PRIMARY KEY AUTOINCREMENT, ip TEXT NOT NULL, email TEXT NOT NULL, quando TEXT NOT NULL);
INSERT INTO docs VALUES ('pcs','pc1','{"nome":"PC de teste"}','2026-11-13 10:00:00');
INSERT INTO docs VALUES ('os','os1','{"titulo":"Chamado de outra pessoa","solicitanteId":"999"}','2026-11-13 10:00:00');
""")
hash_ = subprocess.run(["php", "-r", 'echo password_hash("SenhaLocal@1", PASSWORD_DEFAULT);'], capture_output=True, text=True).stdout
con.execute("INSERT INTO accounts (nome,email,senha_hash,role,ativo,trocar_senha,criado_em) VALUES ('Conta Local','local@ti.dev',?,'admin',1,0,'2026-11-13 10:00:00')", (hash_,))
con.commit(); con.close()
def check(name, cond, extra=""):
    print(("OK   " if cond else "FAIL ") + name, extra if not cond else "")
    if not cond: check.f += 1
check.f = 0
def fetch(op, path, data=None, headers=None):
    req = urllib.request.Request(SITE + path, data=json.dumps(data).encode() if data is not None else None, headers=headers or {})
    try: res = op.open(req); return res.status, res.read().decode(), res.geturl()
    except urllib.error.HTTPError as e: return e.code, e.read().decode("utf-8", "replace"), ""
def boot(html):
    m = re.search(r"window\.__PTI=(\{.*?\});</script>", html, re.S)
    return json.loads(m.group(1)) if m else {}

op, h, u = login("admin@academus.dev", "Aquinosys")
_, h, _ = get(op, "workspace")
check("antes de incluir, o TI aparece como 'em breve'", "Setor de TI</strong>" in h and "em breve" in h)
_, h, _ = get(op, "admin.modulos")
st, h, u = post(op, "admin.modulos", {"_csrf": token(h), "folder": "ti"})
check("o Summa aceita incluir o módulo com o slug ti", "m=ti" in u and "Setor de TI incluído" in h and not BAD.search(h), h[h.find("alert"):][:240])
check("segredo gravado em ti/summa.secret.php", os.path.isfile(os.path.join("htdocs", "ti", "summa.secret.php")))
_, h, _ = get(op, "workspace")
check("o card de verdade entra e o 'em breve' sai", "<h2>Setor de TI</h2>" in h and "index.php?r=modulo.ti" in h and "em breve" not in h)
_, h, _ = get(op, "admin.usuario_novo")
check("permissões do TI aparecem na tela de usuários", all(f'value="x_ti:{c}"' in h for c in ["solicitar", "diretor", "tecnico", "admin"]))

# Administrador abre o módulo
st, h, u = get(op, "modulo.ti")
b = boot(h)
check("administrador entra no TI sem o token no endereço", u.endswith("/ti/index.php") and b.get("modulo") is True, u)
check("papel do administrador no TI é admin", b.get("user", {}).get("role") == "admin" and b.get("user", {}).get("email") == "admin@academus.dev", str(b.get("user")))
check("Todos os módulos leva ao Workspace do Summa", b.get("summaUrl") == "../index.php?r=workspace", str(b.get("summaUrl")))
check("logo do Summa vai para o menu do módulo", b.get("logo") == "../assets/brand/logo-fundo-escuro.png", str(b.get("logo")))
st, j, _ = fetch(op, "ti/api.php?a=sync", headers={"X-CSRF-Token": b.get("csrf", "")})
jj = json.loads(j) if j.startswith("{") else {}
names = {c.name: c.value for h_ in op.handlers if h_.__class__.__name__ == "HTTPCookieProcessor" for c in h_.cookiejar}
check("sessão do módulo e sessão do cliente do Summa são separadas", "PTISESS" in names and "summa_mod_ti" in names and names["PTISESS"] != names["summa_mod_ti"], str(list(names)))
check("a API do TI reconhece a sessão aberta pelo Summa", st == 200 and jj.get("ok") is True and "pc1" in (jj.get("dados", {}).get("pcs") or {}), f"{st} {j[:160]}")
st, j, _ = fetch(op, "ti/api.php?a=set", data={"col": "pcs", "id": "pc2", "dados": {"nome": "PC novo"}}, headers={"X-CSRF-Token": b.get("csrf", ""), "Content-Type": "application/json"})
check("administrador grava um computador pela API", st == 200 and '"ok":true' in j, f"{st} {j[:160]}")
st, j, _ = fetch(op, "ti/api.php?a=accounts")
check("contas ficam bloqueadas na API no modo módulo", st == 403 and "Painel de controle do Summa" in j, f"{st} {j[:120]}")
st, j, _ = fetch(op, "ti/api.php?a=login", data={"email": "local@ti.dev", "senha": "SenhaLocal@1"}, headers={"Content-Type": "application/json"})
check("login local é recusado para quem entrou pelo Summa", st == 403, str(st))

# Usuário só com "Solicitar"
EM = f"ti{int(time.time())}@academus.dev"
_, h, _ = get(op, "admin.usuario_novo")
st, h, _ = post(op, "admin.usuario_novo", {"_csrf": token(h), "name": "Teste Solicitante", "email": EM, "perm[]": ["x_ti:solicitar"]})
pw = re.search(r'user-select:all">([^<]+)<', h)
op2, h, u = login(EM, H.unescape(pw.group(1)) if pw else "")
st, h, u = post(op2, "senha", {"_csrf": token(h), "current": H.unescape(pw.group(1)), "password": "NovaSenha@2026", "confirm": "NovaSenha@2026"})
b2 = boot(h)
check("quem só tem o TI cai direto no módulo como solicitante", u.endswith("/ti/index.php") and b2.get("user", {}).get("role") == "solicitante", f"{u} {b2.get('user')}")
st, j, _ = fetch(op2, "ti/api.php?a=sync", headers={"X-CSRF-Token": b2.get("csrf", "")})
jj = json.loads(j) if j.startswith("{") else {}
check("solicitante vê computadores, mas não o chamado de outra pessoa", st == 200 and "pc1" in (jj.get("dados", {}).get("pcs") or {}) and "os1" not in (jj.get("dados", {}).get("os") or {}), j[:200])
st, j, _ = fetch(op2, "ti/api.php?a=set", data={"col": "pcs", "id": "pc3", "dados": {"nome": "x"}}, headers={"X-CSRF-Token": b2.get("csrf", ""), "Content-Type": "application/json"})
check("solicitante não grava computador", st == 403, f"{st} {j[:120]}")

# Mudança de permissão vale na próxima abertura
uid = re.search(r"admin\.usuario&(?:amp;)?id=(\d+)", get(op, "admin.usuarios", q=EM)[1]).group(1)
_, h, _ = get(op, "admin.usuario", id=uid)
fields = {"_csrf": token(h), "acao": "permissoes", "perm[]": ["x_ti:solicitar", "x_ti:tecnico"]}
st, h, _ = post(op, "admin.usuario", fields, id=uid)
st, h, u = get(op2, "modulo.ti")
check("ao abrir de novo, vale a maior permissão (técnico)", boot(h).get("user", {}).get("role") == "tecnico", str(boot(h).get("user")))

# Sem permissão
op3, h, _ = login("financeiro@academus.dev", "Academus@2026")
st, h, _ = get(op3, "modulo.ti")
check("sem permissão do TI: 403 no Summa", st == 403 and "não está liberado" in h, str(st))
st, h, u = fetch(session(), "ti/index.php")
check("sem sessão, o TI manda entrar pelo Summa", "r=login" in u, u)

# Sair
b2 = boot(get(op2, "modulo.ti")[1])
st, h, u = fetch(op2, "ti/index.php?sair=1")
check("link de saída sem o token da sessão não desloga", boot(h).get("modulo") is True, u)
st, h, u = fetch(op2, "ti/index.php?sair=" + urllib.parse.quote(b2.get("csrf", "")))
check("sair pelo TI encerra também o Summa", "r=login" in u, u)
_, h, u = get(op2, "workspace")
check("depois de sair, o Summa pede login", "r=login" in u, u)

# Acesso de emergência com conta local
loc = session()
st, h, _ = fetch(loc, "ti/index.php?local=1")
check("?local=1 mostra o login próprio do TI", st == 200 and 'id="f"' in h and "Entrar" in h)
st, j, _ = fetch(loc, "ti/api.php?a=login", data={"email": "local@ti.dev", "senha": "SenhaLocal@1"}, headers={"Content-Type": "application/json"})
check("conta local entra pelo acesso de emergência", st == 200 and '"ok":true' in j, f"{st} {j[:120]}")

# Remover devolve o "em breve"
_, h, _ = get(op, "admin.modulo", m="ti")
st, h, _ = post(op, "admin.modulo", {"_csrf": token(h), "acao": "remover"}, m="ti")
_, h, _ = get(op, "workspace")
check("removido do Summa, volta a aparecer como 'em breve'", "em breve" in h and "r=modulo.ti" not in h)
print("falhas:", check.f)
sys.exit(1 if check.f else 0)
