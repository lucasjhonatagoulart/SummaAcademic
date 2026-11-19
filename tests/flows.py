# Fluxos com gravação: cria usuário, primeiro acesso, aula+notas, eletiva, pagamento, histórico + verificação.
import re, sys, time, html as H
EM = f"teste{int(time.time())}@academus.dev"
sys.path.insert(0, "tests")
from smoke import get, post, token, login, BAD
def check(name, cond, extra=""):
    print(("OK   " if cond else "FAIL ") + name, extra); 
    if not cond: check.f += 1
check.f = 0
def flashmsg(h): m = re.findall(r'class="alert a-(\w+)"[^>]*>(.*?)</div>', h, re.S); return [(k, re.sub("<[^>]+>", "", v).strip()[:160]) for k, v in m]

# 1. admin cria usuário (secretaria)
op, h, _ = login("admin@academus.dev", "Aquinosys")
_, h, _ = get(op, "admin.usuario_novo"); 
perms = ["students:manage", "transcripts:issue", "board:manage"]  # módulos Secretaria e Comunicação
st, h, u = post(op, "admin.usuario_novo", {"_csrf": token(h), "name": "Teste Fluxo", "email": EM, "perm[]": perms})
fm = flashmsg(h); pw = re.search(r'user-select:all">([^<]+)<', h)
check("criar usuário", pw is not None and not BAD.search(h), str(fm))
temp = H.unescape(pw.group(1)) if pw else ""
# 2. login do novo usuário -> forçado a criar senha
op2, h, u = login(EM, temp)
check("primeiro acesso pede nova senha", "r=senha" in u, u)
st, h, u = post(op2, "senha", {"_csrf": token(h), "current": temp, "password": "NovaSenha@2026", "confirm": "NovaSenha@2026"})
check("nova senha aceita", "r=senha" not in u and not BAD.search(h), u + " " + str(flashmsg(h)))
op3, h, u = login(EM, "NovaSenha@2026")
check("login com nova senha", "inicio" in u, u)

# 3. professor registra aula com chamada e lança notas
op, h, _ = login("professor@academus.dev", "Academus@2026")
_, h, _ = get(op, "prof.turma", id="1", aba="aulas")
present = re.findall(r'name="present\[\]" value="(\d+)"', h)
st, h, u = post(op, "prof.turma", {"_csrf": token(h), "acao": "aula", "lesson_date": "2026-11-09", "topic": "Aula de teste automatizado", "hours": "2", "present[]": present[:-1]}, id="1", aba="aulas")
check("registrar aula + chamada", any(k == "ok" for k, _ in flashmsg(h)), str(flashmsg(h)))
_, h, _ = get(op, "prof.turma", id="1", aba="notas")
st, h, u = post(op, "prof.turma", {"_csrf": token(h), "acao": "avaliacao", "name": "Prova teste", "weight": "1"}, id="1", aba="notas")
check("criar avaliação", any(k == "ok" for k, _ in flashmsg(h)), str(flashmsg(h)))
aid = re.findall(r'name="assessment_id" value="(\d+)"', h)
enr = sorted(set(re.findall(r'name="score\[(\d+)\]"', h)))
if aid and enr:
    data = {"_csrf": token(h), "acao": "notas", "assessment_id": aid[-1], "publish": "1"}
    for i, e in enumerate(enr): data[f"score[{e}]"] = str(5 + i % 5)
    st, h, u = post(op, "prof.turma", data, id="1", aba="notas")
    check("lançar e publicar notas", any(k == "ok" for k, _ in flashmsg(h)), str(flashmsg(h)))
else: check("lançar notas (form encontrado)", False, f"{aid} {enr[:3]}")

# 4. aluno escolhe eletiva
op, h, _ = login("aluno@academus.dev", "Academus@2026")
_, h, _ = get(op, "aluno.eletivas")
secs = re.findall(r'name="section_id" value="(\d+)"', h)
res = []
for s in secs[:4]:
    _, h, _ = get(op, "aluno.eletivas"); st, h, u = post(op, "aluno.eletivas", {"_csrf": token(h), "acao": "inscrever", "section_id": s}); res.append(flashmsg(h))
check("eletiva (algum retorno)", bool(secs) and all(r for r in res), str(res)[:300])

# 5. financeiro registra pagamento
op, h, _ = login("financeiro@academus.dev", "Academus@2026")
_, h, _ = get(op, "fin.alunos", situacao="inadimplentes")
sid = re.search(r'fin\.aluno&amp;id=(\d+)', h).group(1)
_, h, _ = get(op, "fin.aluno", id=sid)
cid = re.search(r'name="acao" value="pagar"><input type="hidden" name="charge_id" value="(\d+)"', h).group(1)
st, h, u = post(op, "fin.aluno", {"_csrf": token(h), "acao": "pagar", "charge_id": cid, "amount": "650,00", "paid_on": "2026-11-09", "method": "PIX"}, id=sid)
check("registrar pagamento", any(k == "ok" for k, _ in flashmsg(h)), str(flashmsg(h)))
_, h, _ = get(op, "fin.cobrancas"); st, h, u = post(op, "fin.cobrancas", {"_csrf": token(h), "acao": "gerar", "competence": "2027-01", "due_day": "10"})
check("gerar mensalidades", any(k == "ok" for k, _ in flashmsg(h)), str(flashmsg(h)))
st, h, u = post(op, "fin.cobrancas", {"_csrf": token(h), "acao": "gerar", "competence": "2027-01", "due_day": "10"})
check("gerar de novo não duplica", any(k == "info" for k, _ in flashmsg(h)), str(flashmsg(h)))

# 6. admin emite histórico e verifica
op, h, _ = login("admin@academus.dev", "Aquinosys")
_, h, _ = get(op, "admin.aluno", id="1", aba="historico")
st, h, u = post(op, "admin.emitir", {"_csrf": token(h), "signers[]": ["director", "secretary"]}, id="1")
code = re.search(r"\b([A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4})\b", h)
check("emitir histórico", code is not None and not BAD.search(h), u)
from smoke import session
anon = session(); _, h, _ = get(anon, "verificar", codigo=code.group(1) if code else "x")
check("verificação pública", "autêntic" in h.lower() or "confere" in h.lower(), re.sub("<[^>]+>", " ", h)[-0:0])
print("falhas:", check.f)
