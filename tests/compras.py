# Compras de ponta a ponta: solicitar (secretaria), cotar até 3 (financeiro), aprovar (admin), comprar, receber e enviar ao Patrimônio.
# Antes: php tests/setup_local.php e subir o servidor local na porta 8090.
import glob, json, os, re, sqlite3, sys, html as H
sys.path.insert(0, "tests")
from smoke import get, post, token, login, BASE, BAD
ROOT = os.path.join(os.path.dirname(os.path.abspath(__file__)), "..")
INBOX = os.path.join(ROOT, "htdocs", "patrimonio", "entradas-compras")

def check(name, cond, extra=""):
    print(("OK   " if cond else "FAIL ") + name, extra if not cond else "")
    if not cond: check.f += 1
check.f = 0
def flash(h): m = re.findall(r'class="alert [^"]*"[^>]*>(.*?)</div>', h, re.S); return " | ".join(re.sub("<[^>]+>", "", x) for x in m)

db = sqlite3.connect(os.path.join(ROOT, "tests", "test.sqlite"))
uid = {e: db.execute("SELECT id FROM users WHERE email = ?", (e,)).fetchone()[0] for e in ["secretaria@academus.dev", "financeiro@academus.dev"]}
db.execute("INSERT OR REPLACE INTO user_permissions (user_id, perm, effect) VALUES (?, 'purchase:request', 'GRANT')", (uid["secretaria@academus.dev"],))
db.execute("INSERT OR REPLACE INTO user_permissions (user_id, perm, effect) VALUES (?, 'purchase:manage', 'GRANT')", (uid["financeiro@academus.dev"],))
db.commit()
for f in glob.glob(os.path.join(INBOX, "*.json")): os.remove(f)

sec, h, _ = login("secretaria@academus.dev", "Academus@2026")
fin, _, _ = login("financeiro@academus.dev", "Academus@2026")
adm, _, _ = login("admin@academus.dev", "Aquinosys")

_, h, _ = get(sec, "workspace")
check("card Compras no Workspace de quem pode pedir", "Compras" in h and "comp.inicio" in h or "comp" in h)
_, h, _ = get(sec, "comp.inicio")
check("Início abre para quem pede", "Nenhuma solicitação ainda" in h and not BAD.search(h))
_, h, _ = get(sec, "comp.nova")
check("Nova solicitação mostra local, pagamento e parcelas", all(x in h for x in ["Mercado Livre", "Magalu", "Cartão de crédito", "12x", "1x (à vista)", "Patrimoniável", 'data-show-when="payment=CARTAO"']))

base = {"_csrf": token(h), "title": "Notebooks do laboratório", "sector": "TI", "justification": "Os computadores atuais não rodam os programas das aulas.", "needed_by": "", "priority": "URGENTE",
        "place": "Mercado Livre", "link": "https://exemplo.com/produto", "payment": "CARTAO", "installments": "10",
        "item[0][description]": "Notebook 15 polegadas", "item[0][quantity]": "8", "item[0][unit]": "un.", "item[0][value]": "2.300,00", "item[0][patrimonial]": "1",
        "item[1][description]": "Mouse sem fio", "item[1][quantity]": "8", "item[1][unit]": "un.", "item[1][value]": "60,00",
        "item[2][description]": "", "item[2][quantity]": "1", "item[2][unit]": "un.", "item[2][value]": ""}
_, h, _ = post(sec, "comp.nova", dict(base, installments="13", acao="enviar"))
check("parcelas acima de 12 recusadas", "1 a 12 parcelas" in flash(h), flash(h))
_, h, _ = post(sec, "comp.nova", dict(base, place="Outro", acao="enviar"))
check("local Outro exige o nome", "qual é o local" in flash(h), flash(h))
_, h, url = post(sec, "comp.nova", dict(base, acao="rascunho"))
rid = int(re.search(r"id=(\d+)", url).group(1))
check("rascunho salvo", "Rascunho salvo" in flash(h) and "Continuar editando" in h, flash(h))
_, h, _ = get(fin, "comp.solicitacao", id=rid)
check("rascunho não aparece para Compras", "não encontrada" in h.lower() or "404" in h)
_, h, _ = post(sec, "comp.solicitacao", {"_csrf": token(h if False else get(sec, "comp.solicitacao", id=rid)[1]), "acao": "enviar"}, id=rid)
check("enviada para Compras", "enviada para Compras" in flash(h) and "SC-%04d" % rid in h, flash(h))
row = db.execute("SELECT place, link, payment, installments, priority FROM purchase_requests WHERE id = ?", (rid,)).fetchone()
check("local, link, cartão em 10x gravados", row == ("Mercado Livre", "https://exemplo.com/produto", "CARTAO", 10, "URGENTE"), row)
check("linha vazia ignorada", db.execute("SELECT COUNT(*) FROM purchase_items WHERE request_id = ?", (rid,)).fetchone()[0] == 2)

_, h, _ = get(sec, "comp.cotacao", id=rid)
check("quem só pede não registra cotação", "403" in h or "permissão" in h.lower())
_, h, _ = get(adm, "comp.inicio")
check("Início com KPIs e abas", all(x in h for x in ["Aguardando aprovação", "Em cotação", "A receber", "Todas"]) and "Notebooks do laboratório" in h)
_, h, _ = get(fin, "comp.solicitacoes", sel=rid)
check("Solicitações com painel ao lado", "pc-split" in h and "Próximo passo" in h and "Registrar cotação" in h)

items = [r[0] for r in db.execute("SELECT id FROM purchase_items WHERE request_id = ? ORDER BY position", (rid,))]
quotes = [("Alfa Informática", "Site do fornecedor", "BOLETO", "1", "2.199,00", "59,90", "", "12"),
          ("TechStore", "Mercado Livre", "CARTAO", "10", "2.249,00", "61,00", "25,00", "5"),
          ("Magalu", "Magalu", "PIX", "1", "2.290,00", "58,00", "", "7")]
for sup, place, pay, inst, p1, p2, ship, days in quotes:
    _, h, _ = get(fin, "comp.cotacao", id=rid)
    d = {"_csrf": token(h), "supplier": sup, "place": place, "payment": pay, "installments": inst, "shipping": ship, "delivery_days": days, "notes": "", f"price[{items[0]}]": p1, f"price[{items[1]}]": p2}
    _, h, _ = post(fin, "comp.cotacao", d, id=rid)
check("3 cotações registradas", db.execute("SELECT COUNT(*) FROM purchase_quotes WHERE request_id = ?", (rid,)).fetchone()[0] == 3, flash(h))
tot = db.execute("SELECT total_cents FROM purchase_quotes WHERE supplier = 'TechStore'").fetchone()[0]
check("total calculado com frete", tot == 8 * 224900 + 8 * 6100 + 2500, tot)
_, h, _ = get(fin, "comp.cotacao", id=rid)
check("quarta cotação bloqueada", "já tem 3 cotações" in flash(h), flash(h))
_, h, _ = get(fin, "comp.solicitacao", id=rid)
check("cotações em cartões com o botão Exibir em tabela", "pc-quote" in h and "Exibir em tabela" in h and "Menor preço" in h and "Menor prazo" in h)
_, h, _ = get(fin, "comp.solicitacao", id=rid, ver="tabela")
check("comparação em tabela", "Em verde, o menor preço" in h and "pc-best" in h)
_, h, _ = post(fin, "comp.solicitacao", {"_csrf": token(h), "acao": "aprovar", "quote": "1"}, id=rid)
check("Compras não aprova", "não está disponível" in flash(h), flash(h))
_, h, _ = post(fin, "comp.solicitacao", {"_csrf": token(h), "acao": "para_aprovacao"}, id=rid)
check("enviada para aprovação", "enviada para aprovação" in flash(h), flash(h))

_, h, _ = get(adm, "inicio") if False else get(adm, "comp.inicio")
check("aviso de aprovação no sino", "1 compra aguardando aprovação" in h)
qids = {s: q for q, s in db.execute("SELECT id, supplier FROM purchase_quotes WHERE request_id = ?", (rid,))}
_, h, _ = get(adm, "comp.solicitacao", id=rid)
check("painel de aprovação já marca a mais barata", re.search(r'value="%d" checked' % qids["Alfa Informática"], h) is not None)
_, h, _ = post(adm, "comp.solicitacao", {"_csrf": token(h), "acao": "aprovar", "quote": qids["TechStore"], "note": ""}, id=rid)
check("escolher a mais cara exige motivo", "não é a de menor preço" in flash(h), flash(h))
_, h, _ = post(adm, "comp.solicitacao", {"_csrf": token(h), "acao": "recusar", "note": ""}, id=rid)
check("recusar exige motivo", "escreva o motivo" in flash(h), flash(h))
_, h, _ = post(adm, "comp.solicitacao", {"_csrf": token(h), "acao": "aprovar", "quote": qids["TechStore"], "note": "Entrega em 5 dias e parcelado"}, id=rid)
check("aprovada com a escolhida", "aprovada com TechStore" in flash(h), flash(h))
check("valor aprovado = cotação escolhida", db.execute("SELECT approved_cents, winner_quote_id FROM purchase_requests WHERE id = ?", (rid,)).fetchone() == (tot, qids["TechStore"]))
_, h, _ = get(sec, "comp.solicitacao", id=rid)
check("quem pediu vê o andamento", "Aprovada" in h and "TechStore" in h and "Aprovar compra" not in h)

_, h, _ = get(fin, "comp.solicitacao", id=rid)
_, h, _ = post(fin, "comp.solicitacao", {"_csrf": token(h), "acao": "comprar", "purchase_ref": "ML-2000", "purchased_on": "2099-01-01"}, id=rid)
check("data futura recusada", "hoje ou antes" in flash(h), flash(h))
_, h, _ = post(fin, "comp.solicitacao", {"_csrf": token(h), "acao": "comprar", "purchase_ref": "ML-2000", "purchased_on": __import__("datetime").date.today().isoformat()}, id=rid)
check("compra registrada", "Compra registrada" in flash(h), flash(h))
_, h, _ = get(fin, "comp.receber", id=rid)
check("recebimento já marca os patrimoniáveis", re.search(r'name="pat\[%d\]" value="1" checked' % items[0], h) and not re.search(r'name="pat\[%d\]" value="1" checked' % items[1], h))
d = {"_csrf": token(h), "invoice_number": "000184552", "received_on": __import__("datetime").date.today().isoformat(), f"recv[{items[0]}]": "9", f"recv[{items[1]}]": "8", f"pat[{items[0]}]": "1"}
_, h, _ = post(fin, "comp.receber", d, id=rid)
check("receber mais que o pedido é recusado", "de 0 a 8" in flash(h), flash(h))
d[f"recv[{items[0]}]"] = "7"
_, h, url = post(fin, "comp.receber", d, id=rid)
check("recebimento abre a pergunta do Patrimônio", "Importar para o Patrimônio?" in h and "Agora não" in h and "patrimonio=1" in url, flash(h))
_, h, _ = post(fin, "comp.solicitacao", {"_csrf": token(h), "acao": "patrimonio_nao"}, id=rid)
check("Agora não deixa para depois", "depois" in flash(h) and "Enviar ao Patrimônio agora" in h, flash(h))
_, h, _ = post(fin, "comp.solicitacao", {"_csrf": token(h), "acao": "patrimonio"}, id=rid)
check("enviado ao Patrimônio", "enviados ao Patrimônio" in flash(h), flash(h))
files = glob.glob(os.path.join(INBOX, "*.json"))
check("arquivo de entrada gravado", len(files) == 1)
if files:
    p = json.load(open(files[0], encoding="utf-8"))
    check("entrada só com o patrimoniável, quantidade recebida e preço da cotação", p["nf_numero"] == "000184552" and p["fornecedor"] == "TechStore" and len(p["itens"]) == 1 and p["itens"][0]["quantidade"] == 7 and p["itens"][0]["valor_unitario"] == "2249.00", p)
    os.remove(files[0])
_, h, _ = post(fin, "comp.solicitacao", {"_csrf": token(h), "acao": "patrimonio"}, id=rid)
check("não envia duas vezes", "não está disponível" in flash(h), flash(h))
for r in ["comp.fornecedores", "comp.relatorios"]:
    _, h, _ = get(adm, r)
    check(r + " abre", "TechStore" in h if r == "comp.fornecedores" else "Por setor" in h and not BAD.search(h))
_, h, _ = get(sec, "comp.fornecedores")
check("quem só pede não vê fornecedores", "403" in h or "permissão" in h.lower())

# cancelar e recusar
_, h, _ = get(sec, "comp.nova")
_, h, url = post(sec, "comp.nova", dict(base, _csrf=token(h), title="Cadeiras", acao="enviar"))
r2 = int(re.search(r"id=(\d+)", url).group(1))
_, h, _ = post(sec, "comp.solicitacao", {"_csrf": token(h), "acao": "cancelar", "note": ""}, id=r2)
check("cancelar exige motivo", "motivo" in flash(h), flash(h))
_, h, _ = post(sec, "comp.solicitacao", {"_csrf": token(h), "acao": "cancelar", "note": "Já recebemos doação"}, id=r2)
check("quem pediu cancela antes da aprovação", "cancelada" in flash(h), flash(h))
check("histórico registra tudo", db.execute("SELECT COUNT(*) FROM purchase_events WHERE request_id = ?", (rid,)).fetchone()[0] >= 10)
check("auditoria registra a aprovação", db.execute("SELECT COUNT(*) FROM audit_logs WHERE action = 'purchase.approve'").fetchone()[0] == 1)
print("falhas:", check.f); sys.exit(1 if check.f else 0)
