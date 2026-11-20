# Compras de ponta a ponta (banco recém-criado): a secretaria pede; a direção (admin) pede cotação; a secretaria cota;
# a direção assina e aprova; o financeiro compra, recebe e envia ao Patrimônio. Depois: aprovação direta, voltar etapa e excluir.
# Antes: php tests/setup_local.php e subir o servidor local na porta 8090.
import datetime, glob, json, os, re, sqlite3, sys
sys.path.insert(0, "tests")
from smoke import get, post, token, login, BAD
ROOT = os.path.join(os.path.dirname(os.path.abspath(__file__)), "..")
INBOX = os.path.join(ROOT, "htdocs", "patrimonio", "entradas-compras")
TODAY = datetime.date.today().isoformat()

def check(name, cond, extra=""):
    print(("OK   " if cond else "FAIL ") + name, extra if not cond else "")
    if not cond: check.f += 1
check.f = 0
def flash(h): return " | ".join(re.sub("<[^>]+>", "", x) for x in re.findall(r'class="alert [^"]*"[^>]*>(.*?)</div>', h, re.S))
def status(rid): return db.execute("SELECT status FROM purchase_requests WHERE id = ?", (rid,)).fetchone()[0]

db = sqlite3.connect(os.path.join(ROOT, "tests", "test.sqlite"))
uid = {e: db.execute("SELECT id FROM users WHERE email = ?", (e,)).fetchone()[0] for e in ["secretaria@academus.dev", "financeiro@academus.dev"]}
db.execute("INSERT OR REPLACE INTO user_permissions (user_id, perm, effect) VALUES (?, 'purchase:request', 'GRANT')", (uid["secretaria@academus.dev"],))
db.execute("INSERT OR REPLACE INTO user_permissions (user_id, perm, effect) VALUES (?, 'purchase:manage', 'GRANT')", (uid["financeiro@academus.dev"],))
db.commit()
for f in glob.glob(os.path.join(INBOX, "*.json")): os.remove(f)
check("perfil Diretoria já aprova compras", db.execute("SELECT COUNT(*) FROM role_permissions rp JOIN roles r ON r.id = rp.role_id WHERE r.kind = 'DIRECTOR' AND rp.perm = 'purchase:approve'").fetchone()[0] == 1)

sec, _, _ = login("secretaria@academus.dev", "Academus@2026")
fin, _, _ = login("financeiro@academus.dev", "Academus@2026")
adm, _, _ = login("admin@academus.dev", "Aquinosys")

_, h, _ = get(sec, "comp.nova")
check("nova: setor em lista, valores com máscara, sem campo de título", '<select class="input" id="f-sector"' in h and "data-money" in h and 'name="title"' not in h and "Biblioteca" in h)
check("nova: local, pagamento e parcelas", all(x in h for x in ["Mercado Livre", "Magalu", "Cartão de crédito", "12x", 'data-show-when="payment=CARTAO"']))
base = {"_csrf": token(h), "sector": "TI", "justification": "Os computadores atuais não rodam os programas das aulas.", "needed_by": "", "priority": "URGENTE",
        "place": "Mercado Livre", "link": "https://exemplo.com/produto", "payment": "CARTAO", "installments": "10",
        "item[0][description]": "Notebook 15 polegadas", "item[0][quantity]": "8", "item[0][unit]": "un.", "item[0][value]": "2.300,00", "item[0][patrimonial]": "1",
        "item[1][description]": "Mouse sem fio", "item[1][quantity]": "8", "item[1][unit]": "un.", "item[1][value]": "60,00",
        "item[2][description]": "", "item[2][quantity]": "1", "item[2][unit]": "un.", "item[2][value]": ""}
_, h, _ = post(sec, "comp.nova", dict(base, sector="Cozinha", acao="enviar"))
check("setor fora da lista recusado", "Escolha o setor" in flash(h), flash(h))
_, h, _ = post(sec, "comp.nova", dict(base, **{"item[1][value]": "", "acao": "enviar"}))
check("item sem valor estimado recusado", "valor estimado do item 2" in flash(h), flash(h))
_, h, _ = post(sec, "comp.nova", dict(base, installments="13", acao="enviar"))
check("parcelas acima de 12 recusadas", "1 a 12 parcelas" in flash(h), flash(h))
_, h, url = post(sec, "comp.nova", dict(base, acao="enviar"))
rid = int(re.search(r"id=(\d+)", url).group(1))
title = "Solicitação de Compra Nº %04d" % rid
check("título gerado e enviada para a direção", title in h and "enviada para a direção" in flash(h), flash(h))
check("aguardando a direção, sem cotação ainda", status(rid) == "ENVIADA" and "Cotação, se pedida" in h)

_, h, _ = get(fin, "comp.cotacao", id=rid)
check("sem pedido da direção, não cota", "quando a direção pede cotação" in flash(h), flash(h))
_, h, _ = get(adm, "comp.inicio")
check("Início: aguardando a direção", "Aguardando a direção" in h and "Notebook 15 polegadas + 1 item" in h)
check("aviso no sino para a direção", "1 compra aguardando a direção" in h)
_, h, _ = get(adm, "comp.solicitacao", id=rid)
check("direção vê aprovar direto, pedir cotação e recusar", all(x in h for x in ["Assinar e aprovar sem cotação", "Solicitar cotação de preços", "Recusar", "R$ 18.880,00"]))
_, h, _ = get(sec, "comp.solicitacao", id=rid)
check("quem pede não decide", "Assinar e aprovar" not in h and "Aguardando a direção" in h)
_, h, _ = post(sec, "comp.solicitacao", {"_csrf": token(h), "acao": "pedir_cotacao"}, id=rid)
check("quem pede não pede cotação", "não está disponível" in flash(h), flash(h))
_, h, _ = get(adm, "comp.solicitacao", id=rid)
_, h, _ = post(adm, "comp.solicitacao", {"_csrf": token(h), "acao": "pedir_cotacao", "note": "Cotar em pelo menos duas lojas"}, id=rid)
check("direção pede cotação", "Cotação pedida" in flash(h) and status(rid) == "COTACAO", flash(h))

_, h, _ = get(sec, "comp.inicio")
check("aviso ao solicitante para cotar", "A direção pediu cotação de preços" in h)
items = [r[0] for r in db.execute("SELECT id FROM purchase_items WHERE request_id = ? ORDER BY position", (rid,))]
quotes = [("Alfa Informática", "Site do fornecedor", "BOLETO", "1", "2.199,00", "59,90", "", "12"),
          ("TechStore", "Mercado Livre", "CARTAO", "10", "2.249,00", "61,00", "25,00", "5"),
          ("Magalu", "Magalu", "PIX", "1", "2.290,00", "58,00", "", "7")]
for sup, place, pay, inst, p1, p2, ship, days in quotes:
    _, h, _ = get(sec, "comp.cotacao", id=rid)
    _, h, _ = post(sec, "comp.cotacao", {"_csrf": token(h), "supplier": sup, "place": place, "payment": pay, "installments": inst, "shipping": ship, "delivery_days": days, "notes": "", f"price[{items[0]}]": p1, f"price[{items[1]}]": p2}, id=rid)
check("solicitante registra 3 cotações", db.execute("SELECT COUNT(*) FROM purchase_quotes WHERE request_id = ?", (rid,)).fetchone()[0] == 3, flash(h))
tot = db.execute("SELECT total_cents FROM purchase_quotes WHERE supplier = 'TechStore'").fetchone()[0]
check("total com frete", tot == 8 * 224900 + 8 * 6100 + 2500, tot)
_, h, _ = get(sec, "comp.cotacao", id=rid)
check("quarta cotação bloqueada", "já tem 3 cotações" in flash(h), flash(h))
_, h, _ = get(sec, "comp.solicitacao", id=rid, ver="tabela")
check("comparação em tabela", "Em verde, o menor preço" in h and "pc-best" in h)
_, h, _ = get(sec, "comp.solicitacao", id=rid)
check("cartões com Exibir em tabela", "pc-quote" in h and "Exibir em tabela" in h and "Menor preço" in h)
_, h, _ = post(sec, "comp.solicitacao", {"_csrf": token(h), "acao": "para_aprovacao"}, id=rid)
check("cotações enviadas para a direção", "Cotações enviadas" in flash(h) and status(rid) == "APROVACAO", flash(h))

qids = {s: q for q, s in db.execute("SELECT id, supplier FROM purchase_quotes WHERE request_id = ?", (rid,))}
_, h, _ = get(adm, "comp.solicitacao", id=rid)
check("já marca a mais barata", re.search(r'value="%d" checked' % qids["Alfa Informática"], h) is not None)
_, h, _ = post(adm, "comp.solicitacao", {"_csrf": token(h), "acao": "aprovar", "quote": qids["TechStore"], "note": "", "password": "Aquinosys"}, id=rid)
check("escolher a mais cara exige motivo", "não é a de menor preço" in flash(h), flash(h))
_, h, _ = post(adm, "comp.solicitacao", {"_csrf": token(h), "acao": "aprovar", "quote": qids["TechStore"], "note": "Entrega em 5 dias", "password": "errada"}, id=rid)
check("senha errada não assina", "Senha incorreta" in flash(h) and status(rid) == "APROVACAO", flash(h))
_, h, _ = post(adm, "comp.solicitacao", {"_csrf": token(h), "acao": "aprovar", "quote": qids["TechStore"], "note": "Entrega em 5 dias", "password": "Aquinosys"}, id=rid)
check("assinada e aprovada", "aprovada e assinada" in flash(h) and "Assinou a aprovação" in h, flash(h))
check("valor aprovado = escolhida", db.execute("SELECT approved_cents, winner_quote_id FROM purchase_requests WHERE id = ?", (rid,)).fetchone() == (tot, qids["TechStore"]))

_, h, _ = get(fin, "comp.solicitacao", id=rid)
_, h, _ = post(fin, "comp.solicitacao", {"_csrf": token(h), "acao": "comprar", "purchase_ref": "ML-2000", "purchased_on": TODAY}, id=rid)
check("compra registrada", "Compra registrada" in flash(h), flash(h))
_, h, _ = get(fin, "comp.receber", id=rid)
d = {"_csrf": token(h), "invoice_number": "000184552", "received_on": TODAY, f"recv[{items[0]}]": "9", f"recv[{items[1]}]": "8", f"pat[{items[0]}]": "1"}
_, h, _ = post(fin, "comp.receber", d, id=rid)
check("receber mais que o pedido é recusado", "de 0 a 8" in flash(h), flash(h))
d[f"recv[{items[0]}]"] = "7"
_, h, url = post(fin, "comp.receber", d, id=rid)
check("recebimento pergunta do Patrimônio", "Importar para o Patrimônio?" in h and "patrimonio=1" in url)
_, h, _ = post(fin, "comp.solicitacao", {"_csrf": token(h), "acao": "patrimonio"}, id=rid)
files = glob.glob(os.path.join(INBOX, "*.json"))
check("enviado ao Patrimônio", "enviados ao Patrimônio" in flash(h) and len(files) == 1, flash(h))
if files:
    p = json.load(open(files[0], encoding="utf-8"))
    check("entrada com patrimoniável, quantidade recebida e preço da cotação", p["fornecedor"] == "TechStore" and len(p["itens"]) == 1 and p["itens"][0]["quantidade"] == 7 and p["itens"][0]["valor_unitario"] == "2249.00", p)
    os.remove(files[0])

# voltar etapa (quem tem permissão) com o motivo
_, h, _ = get(sec, "comp.solicitacao", id=rid)
check("sem permissão, não vê voltar etapa", "Voltar etapa ou excluir" not in h)
_, h, _ = post(sec, "comp.solicitacao", {"_csrf": token(h), "acao": "voltar", "note": "teste"}, id=rid)
check("sem permissão, não volta etapa", "não está disponível" in flash(h) and status(rid) == "RECEBIDA", flash(h))
_, h, _ = get(adm, "comp.solicitacao", id=rid)
check("admin vê voltar para Comprada", "Voltar para “Comprada”" in h)
_, h, _ = post(adm, "comp.solicitacao", {"_csrf": token(h), "acao": "voltar", "note": ""}, id=rid)
check("voltar exige motivo", "motivo" in flash(h), flash(h))
_, h, _ = post(adm, "comp.solicitacao", {"_csrf": token(h), "acao": "voltar", "note": "NF-e lançada errada"}, id=rid)
row = db.execute("SELECT status, invoice_number, patrimony_status FROM purchase_requests WHERE id = ?", (rid,)).fetchone()
check("voltou para Comprada e limpou o recebimento", row == ("COMPRADA", None, "") and "Patrimônio" in flash(h), (row, flash(h)))
_, h, _ = post(adm, "comp.solicitacao", {"_csrf": token(h), "acao": "voltar", "note": "Pedido cancelado na loja"}, id=rid)
check("voltou para Aprovada", status(rid) == "APROVADA", flash(h))
_, h, _ = post(adm, "comp.solicitacao", {"_csrf": token(h), "acao": "voltar", "note": "Rever a escolha"}, id=rid)
check("voltou para Cotação para aprovar e limpou a assinatura", db.execute("SELECT status, decided_by, winner_quote_id FROM purchase_requests WHERE id = ?", (rid,)).fetchone() == ("APROVACAO", None, None), flash(h))
check("histórico registra as voltas", db.execute("SELECT COUNT(*) FROM purchase_events WHERE request_id = ? AND action = 'revertida'", (rid,)).fetchone()[0] == 3)

# aprovação direta, sem cotação
_, h, _ = get(sec, "comp.nova")
_, h, url = post(sec, "comp.nova", dict(base, _csrf=token(h), sector="Biblioteca", acao="enviar"))
r2 = int(re.search(r"id=(\d+)", url).group(1))
_, h, _ = get(adm, "comp.solicitacao", id=r2)
_, h, _ = post(adm, "comp.solicitacao", {"_csrf": token(h), "acao": "aprovar_direto", "note": "", "password": ""}, id=r2)
check("aprovar direto exige a senha", "Senha incorreta" in flash(h) and status(r2) == "ENVIADA", flash(h))
_, h, _ = post(adm, "comp.solicitacao", {"_csrf": token(h), "acao": "aprovar_direto", "note": "", "password": "Aquinosys"}, id=r2)
check("aprovada sem cotação pelo estimado", status(r2) == "APROVADA" and db.execute("SELECT approved_cents FROM purchase_requests WHERE id = ?", (r2,)).fetchone()[0] == 8 * 230000 + 8 * 6000 and "Sem cotação" in h, flash(h))

# recusar direto e excluir
_, h, _ = get(sec, "comp.nova")
_, h, url = post(sec, "comp.nova", dict(base, _csrf=token(h), acao="enviar"))
r3 = int(re.search(r"id=(\d+)", url).group(1))
_, h, _ = get(adm, "comp.solicitacao", id=r3)
_, h, _ = post(adm, "comp.solicitacao", {"_csrf": token(h), "acao": "recusar", "note": ""}, id=r3)
check("recusar exige motivo", "escreva o motivo" in flash(h), flash(h))
_, h, _ = post(adm, "comp.solicitacao", {"_csrf": token(h), "acao": "recusar", "note": "Sem orçamento este mês"}, id=r3)
check("recusada direto pela direção", status(r3) == "RECUSADA", flash(h))
_, h, _ = post(adm, "comp.solicitacao", {"_csrf": token(h), "acao": "excluir", "note": ""}, id=r3)
check("excluir exige motivo", "motivo da exclusão" in flash(h), flash(h))
_, h, _ = post(adm, "comp.solicitacao", {"_csrf": token(h), "acao": "excluir", "note": "Duplicada"}, id=r3)
check("excluída com registro na auditoria", db.execute("SELECT COUNT(*) FROM purchase_requests WHERE id = ?", (r3,)).fetchone()[0] == 0 and db.execute("SELECT COUNT(*) FROM audit_logs WHERE action = 'purchase.delete'").fetchone()[0] == 1, flash(h))
_, h, _ = get(sec, "comp.nova")
_, h, url = post(sec, "comp.nova", dict(base, _csrf=token(h), acao="rascunho"))
r4 = int(re.search(r"id=(\d+)", url).group(1))
check("quem pediu pode excluir o próprio rascunho", "Excluir rascunho" in h)
_, h, _ = post(sec, "comp.solicitacao", {"_csrf": token(h), "acao": "excluir"}, id=r4)
check("rascunho excluído", db.execute("SELECT COUNT(*) FROM purchase_requests WHERE id = ?", (r4,)).fetchone()[0] == 0, flash(h))

# setores
_, h, _ = get(adm, "comp.setores")
_, h, _ = post(adm, "comp.setores", {"_csrf": token(h), "sectors": "Pós-graduação\nTI\nti\n\nReitoria"})
check("setores salvos sem repetir", "3 setores salvos" in flash(h), flash(h))
_, h, _ = get(sec, "comp.nova")
check("nova usa a lista de setores", "Reitoria" in h and "Biblioteca" not in h)
_, h, _ = get(sec, "comp.setores")
check("quem só pede não edita setores", "403" in h or "permissão" in h.lower())
for r in ["comp.fornecedores", "comp.relatorios", "comp.solicitacoes"]:
    _, h, _ = get(adm, r)
    check(r + " abre", not BAD.search(h) and ("TechStore" in h if r == "comp.fornecedores" else True))
print("falhas:", check.f); sys.exit(1 if check.f else 0)
