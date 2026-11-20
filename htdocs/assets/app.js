// Plataforma: ícones, menu do usuário, menu lateral, listas de permissões e confirmações.
(function () {
  "use strict";
  const $ = (s, r = document) => r.querySelector(s), $$ = (s, r = document) => [...r.querySelectorAll(s)];
  const paint = () => window.lucide && window.lucide.createIcons();
  paint();

  // Menu do usuário
  const btn = $("[data-menu-btn]"), menu = $("[data-menu]");
  if (btn && menu) {
    btn.addEventListener("click", (e) => { e.stopPropagation(); const open = menu.hidden; menu.hidden = !open; btn.setAttribute("aria-expanded", String(open)); });
    document.addEventListener("click", (e) => { if (!menu.hidden && !menu.contains(e.target)) { menu.hidden = true; btn.setAttribute("aria-expanded", "false"); } });
    document.addEventListener("keydown", (e) => { if (e.key === "Escape") { menu.hidden = true; btn.setAttribute("aria-expanded", "false"); } });
  }
  // Menu lateral (celular) e recolher (computador)
  const shell = $("[data-shell]"), side = $(".sidebar"), scrim = $("[data-close-side]");
  $("[data-open-side]")?.addEventListener("click", () => { side.classList.add("open"); scrim.hidden = false; });
  scrim?.addEventListener("click", () => { side.classList.remove("open"); scrim.hidden = true; });
  try { if (localStorage.getItem("acd-collapsed") === "1") shell?.classList.add("collapsed"); } catch (e) {}
  $("[data-toggle-collapse]")?.addEventListener("click", () => { shell.classList.toggle("collapsed"); try { localStorage.setItem("acd-collapsed", shell.classList.contains("collapsed") ? "1" : "0"); } catch (e) {} });

  // Confirmação antes de ações sensíveis
  document.addEventListener("submit", (e) => { const m = e.target.getAttribute("data-confirm"); if (m && !confirm(m)) e.preventDefault(); });
  // Campos que aparecem conforme outro campo (data-show-when="nome=valor1|valor2"); os escondidos ficam desativados
  const showWhen = $$("[data-show-when]");
  const syncShow = () => showWhen.forEach((box) => {
    const [name, vals] = box.dataset.showWhen.split("="), form = box.closest("form");
    const el = form && (form.querySelector(`[name="${name}"]:checked`) || form.querySelector(`select[name="${name}"]`));
    const on = !!el && vals.split("|").includes(el.value);
    box.hidden = !on; $$("input,select,textarea", box).forEach((x) => { x.disabled = !on; });
  });
  if (showWhen.length) { document.addEventListener("change", syncShow); syncShow(); }
  // Valor em reais: digita só números e os centavos entram sozinhos (1 → 0,01; 12345 → 123,45)
  const moneyFmt = (digits) => { const d = digits.replace(/\D/g, "").replace(/^0+(?=\d)/, "").slice(0, 13); if (!d) return ""; const c = d.padStart(3, "0"); return c.slice(0, -2).replace(/\B(?=(\d{3})+(?!\d))/g, ".") + "," + c.slice(-2); };
  document.addEventListener("input", (e) => { const el = e.target; if (!el.matches || !el.matches("input[data-money]")) return; el.value = moneyFmt(el.value); el.setSelectionRange(el.value.length, el.value.length); });
  // Linhas que se adicionam e removem (data-add-row="#template", data-remove-row)
  let rowSeq = 1000;
  document.addEventListener("click", (e) => {
    const add = e.target.closest("[data-add-row]");
    if (add) {
      const tpl = $(add.dataset.addRow), body = add.closest(".card")?.querySelector("[data-rows]");
      if (!tpl || !body) return;
      body.insertAdjacentHTML("beforeend", tpl.innerHTML.replaceAll("__N__", String(rowSeq++)));
      paint(); body.lastElementChild?.querySelector("input")?.focus();
    }
    const rm = e.target.closest("[data-remove-row]");
    if (rm) { const body = rm.closest("[data-rows]"), tr = rm.closest("[data-row]"); if (body && tr && body.querySelectorAll("[data-row]").length > 1) tr.remove(); else tr?.querySelectorAll("input").forEach((i) => { i.value = i.type === "checkbox" ? i.value : ""; i.checked = false; }); }
  });
  // Filtros que enviam ao mudar
  $$("[data-autosubmit]").forEach((el) => el.addEventListener("change", () => el.form.submit()));
  // Linhas clicáveis
  $$("tr[data-href]").forEach((tr) => tr.addEventListener("click", (e) => { if (!e.target.closest("a,button,input,select,label,form")) location.href = tr.dataset.href; }));

  // Liberação de módulos e áreas por usuário (tela D1): chave por módulo, áreas ao expandir, "Acesso total"
  function sync(root) {
    const full = $("[data-full-toggle]"), isFull = !!(full && full.checked);
    root.classList.toggle("is-full", isFull);
    $$("[data-plat-mod]", root).forEach((pm) => {
      const tg = $("[data-mod-toggle]", pm); if (isFull) tg.checked = true; tg.disabled = isFull;
      const on = tg.checked, boxes = $$(".perm-item input", pm);
      pm.classList.toggle("is-off", !on);
      boxes.forEach((x) => { x.disabled = isFull || !on; });
      const n = boxes.filter((x) => x.checked).length, areas = $$(".perm-group", pm).filter((g) => $$("input:checked", g).length).length;
      $("[data-mod-state]", pm).textContent = on ? "Liberado" : "Bloqueado";
      $("[data-mod-off]", pm).hidden = on;
      $("[data-mod-empty]", pm).hidden = !on || isFull || n > 0;
      $("[data-mod-sum]", pm).textContent = isFull ? "Acesso total" : !on ? "Bloqueado" : n ? `${areas} ${areas === 1 ? "área" : "áreas"} · ${n} de ${boxes.length} ${boxes.length === 1 ? "permissão" : "permissões"}` : "Nenhuma área marcada";
    });
    const live = $$(".perm-item input", root).filter((x) => !x.disabled || isFull);
    const n = live.filter((x) => x.checked).length, total = $$(".perm-item input", root).length;
    $$(".perm-group", root).forEach((g) => { const b = $$("input", g), on = b.filter((x) => x.checked).length, dis = b.every((x) => x.disabled); $("[data-count]", g).textContent = `(${on} de ${b.length})`; const btn = $("[data-all]", g); btn.textContent = on === b.length ? "Desmarcar todas" : "Marcar todas"; btn.disabled = dis; });
    $('[data-sum="n"]', root).textContent = isFull ? "Acesso total: todos os módulos e áreas" : `${$$("[data-mod-toggle]:checked", root).length} de ${$$("[data-mod-toggle]", root).length} módulos liberados · ${n} de ${total} permissões`;
    const rs = $("[data-clear]", root); if (rs) rs.disabled = isFull || !n;
  }
  $$(".perm-list").forEach((root) => {
    sync(root);
    root.addEventListener("change", (e) => {
      const tg = e.target.closest("[data-mod-toggle]");
      if (tg && tg.checked) { const pm = tg.closest("[data-plat-mod]"), body = $(".plat-mod-body", pm), btn = $("[data-mod-expand]", pm); if (!$$(".perm-item input:checked", pm).length) { body.hidden = false; btn.setAttribute("aria-expanded", "true"); } }
      sync(root);
    });
    root.addEventListener("click", (e) => {
      const exp = e.target.closest("[data-mod-expand]");
      if (exp) { const body = document.getElementById(exp.getAttribute("aria-controls")), open = body.hidden; body.hidden = !open; exp.setAttribute("aria-expanded", String(open)); return; }
      const all = e.target.closest("[data-all]"), clr = e.target.closest("[data-clear]");
      if (all) { const b = $$("input", all.closest(".perm-group")), on = b.every((x) => x.checked); b.forEach((x) => (x.checked = !on)); sync(root); }
      if (clr) { $$(".perm-item input", root).forEach((x) => (x.checked = false)); sync(root); }
    });
    const full = $("[data-full-toggle]"); if (full) full.addEventListener("change", () => sync(root));
  });
  $$("[data-print]").forEach((b) => b.addEventListener("click", () => window.print()));
  // Horário padrão preenche início e fim
  $$("[data-slot-pick]").forEach((sel) => sel.addEventListener("change", () => { const v = sel.value.split("-"); if (v.length === 2) { sel.form.start_time.value = v[0]; sel.form.end_time.value = v[1]; } }));
  // Chamada: marcar todos presentes/ausentes
  $$("[data-att-all]").forEach((b) => b.addEventListener("click", () => $$(`input[type=checkbox][name^="present"]`, b.closest("form")).forEach((x) => (x.checked = b.dataset.attAll === "1"))));
  // ---------- Imagens: abrir arquivo grande sem travar ----------
  const loadImage = (file) => (window.createImageBitmap
    ? createImageBitmap(file, { imageOrientation: "from-image" }).catch(() => createImageBitmap(file))
    : Promise.reject()).catch(() => new Promise((ok, fail) => { const r = new FileReader(); r.onload = () => { const i = new Image(); i.onload = () => ok(i); i.onerror = fail; i.src = r.result; }; r.onerror = fail; r.readAsDataURL(file); }));
  const imgW = (i) => i.naturalWidth || i.width, imgH = (i) => i.naturalHeight || i.height;
  const setFile = (input, blob, name) => { try { const dt = new DataTransfer(); dt.items.add(new File([blob], name, { type: blob.type })); input.files = dt.files; return true; } catch (e) { return false; } };
  const toast = (msg) => { let t = $(".img-toast"); if (!t) { t = document.createElement("div"); t.className = "img-toast"; t.setAttribute("role", "alert"); document.body.appendChild(t); } t.textContent = msg; t.hidden = false; clearTimeout(t._h); t._h = setTimeout(() => (t.hidden = true), 5000); };
  const MAX_SOURCE = 30 * 1024 * 1024;

  // ---------- Recorte da foto de perfil ----------
  function openCropper(file, onDone, onCancel) {
    loadImage(file).then((img) => {
      const wrap = document.createElement("div");
      wrap.className = "crop-modal";
      wrap.innerHTML = '<div class="crop-dialog" role="dialog" aria-modal="true" aria-labelledby="crop-t">'
        + '<div class="crop-head"><h3 id="crop-t">Ajustar foto</h3><button type="button" class="crop-x" data-c="cancel" aria-label="Fechar">×</button></div>'
        + '<div class="crop-stage" tabindex="0" aria-label="Arraste para enquadrar. Use a rolagem ou o controle abaixo para aproximar."><canvas></canvas><div class="crop-mask"></div></div>'
        + '<div class="crop-tools"><button type="button" class="crop-ico" data-c="out" aria-label="Afastar">−</button><input type="range" min="1" max="4" step="0.01" value="1" aria-label="Zoom"><button type="button" class="crop-ico" data-c="in" aria-label="Aproximar">+</button><button type="button" class="btn btn-ghost btn-sm" data-c="rot" aria-label="Girar">⟳ Girar</button></div>'
        + '<p class="crop-hint">Arraste a foto para enquadrar. No celular, use dois dedos para aproximar.</p>'
        + '<div class="crop-foot"><button type="button" class="btn btn-ghost" data-c="cancel">Cancelar</button><button type="button" class="btn btn-primary" data-c="save">Salvar foto</button></div></div>';
      document.body.appendChild(wrap); document.body.classList.add("no-scroll");
      const stage = $(".crop-stage", wrap), cv = $("canvas", wrap), ctx = cv.getContext("2d"), range = $("input[type=range]", wrap);
      let S = 0, base = 1, zoom = 1, rot = 0, ox = 0, oy = 0;
      const dims = () => (rot % 180 ? [imgH(img), imgW(img)] : [imgW(img), imgH(img)]);
      const clamp = () => { const [w, h] = dims(), sc = base * zoom; const mx = Math.max(0, (w * sc - S) / 2), my = Math.max(0, (h * sc - S) / 2); ox = Math.min(mx, Math.max(-mx, ox)); oy = Math.min(my, Math.max(-my, oy)); };
      const paint = (c, size, bg) => { const k = size / S; c.save(); if (bg) { c.fillStyle = bg; c.fillRect(0, 0, size, size); } else c.clearRect(0, 0, size, size);
        c.imageSmoothingQuality = "high"; c.translate(size / 2 + ox * k, size / 2 + oy * k); c.rotate((rot * Math.PI) / 180); c.scale(base * zoom * k, base * zoom * k); c.drawImage(img, -imgW(img) / 2, -imgH(img) / 2); c.restore(); };
      const draw = () => { clamp(); paint(ctx, cv.width, null); };
      const layout = () => { S = stage.clientWidth; const d = window.devicePixelRatio || 1; cv.width = cv.height = Math.round(S * d); const [w, h] = dims(); base = S / Math.min(w, h); draw(); };
      const setZoom = (z, cx = 0, cy = 0) => { z = Math.min(4, Math.max(1, z)); const f = z / zoom; ox = cx + (ox - cx) * f; oy = cy + (oy - cy) * f; zoom = z; range.value = z; draw(); };
      layout(); window.addEventListener("resize", layout);
      // arrastar e pinça
      const pts = new Map(); let last = null, pinch = null;
      const rel = (e) => { const r = stage.getBoundingClientRect(); return [e.clientX - r.left - S / 2, e.clientY - r.top - S / 2]; };
      stage.addEventListener("pointerdown", (e) => { stage.setPointerCapture(e.pointerId); pts.set(e.pointerId, [e.clientX, e.clientY]); last = [e.clientX, e.clientY]; if (pts.size === 2) { const [a, b] = [...pts.values()]; pinch = { d: Math.hypot(a[0] - b[0], a[1] - b[1]), z: zoom }; } });
      stage.addEventListener("pointermove", (e) => { if (!pts.has(e.pointerId)) return; pts.set(e.pointerId, [e.clientX, e.clientY]);
        if (pts.size === 2 && pinch) { const [a, b] = [...pts.values()]; const r = stage.getBoundingClientRect(); setZoom(pinch.z * (Math.hypot(a[0] - b[0], a[1] - b[1]) / pinch.d), (a[0] + b[0]) / 2 - r.left - S / 2, (a[1] + b[1]) / 2 - r.top - S / 2); return; }
        if (pts.size === 1 && last) { ox += e.clientX - last[0]; oy += e.clientY - last[1]; last = [e.clientX, e.clientY]; draw(); } });
      const up = (e) => { pts.delete(e.pointerId); pinch = null; last = pts.size ? [...pts.values()][0] : null; };
      stage.addEventListener("pointerup", up); stage.addEventListener("pointercancel", up);
      stage.addEventListener("wheel", (e) => { e.preventDefault(); const [cx, cy] = rel(e); setZoom(zoom * (e.deltaY < 0 ? 1.08 : 1 / 1.08), cx, cy); }, { passive: false });
      range.addEventListener("input", () => setZoom(parseFloat(range.value)));
      let saved = false;
      const close = () => { if (!saved && onCancel) onCancel(); window.removeEventListener("resize", layout); document.removeEventListener("keydown", key); wrap.remove(); document.body.classList.remove("no-scroll"); };
      const key = (e) => { if (e.key === "Escape") close(); else if (document.activeElement === stage) { const m = { ArrowLeft: [10, 0], ArrowRight: [-10, 0], ArrowUp: [0, 10], ArrowDown: [0, -10] }[e.key];
        if (m) { e.preventDefault(); ox += m[0]; oy += m[1]; draw(); } else if (e.key === "+" || e.key === "=") setZoom(zoom * 1.1); else if (e.key === "-") setZoom(zoom / 1.1); } };
      document.addEventListener("keydown", key);
      wrap.addEventListener("click", (e) => { const c = e.target.closest("[data-c]"); if (e.target === wrap) return close(); if (!c) return;
        const a = c.dataset.c;
        if (a === "cancel") close();
        else if (a === "in") setZoom(zoom * 1.15); else if (a === "out") setZoom(zoom / 1.15);
        else if (a === "rot") { rot = (rot + 90) % 360; ox = oy = 0; zoom = 1; range.value = 1; layout(); }
        else if (a === "save") { c.disabled = true; c.textContent = "Salvando…"; const out = document.createElement("canvas"); out.width = out.height = 512; paint(out.getContext("2d"), 512, "#fff");
          out.toBlob((b) => { saved = true; close(); onDone(b); }, "image/jpeg", 0.9); } });
      stage.focus();
    }).catch(() => toast("Não foi possível abrir esta imagem. Use JPG, PNG ou WebP."));
  }
  $$("[data-cropper]").forEach((input) => {
    const form = input.form;
    input.addEventListener("change", () => {
      const f = input.files[0]; if (!f) return;
      if (!/^image\/(jpeg|png|webp)$/.test(f.type)) { toast("Use uma imagem JPG, PNG ou WebP."); input.value = ""; return; }
      if (f.size > MAX_SOURCE) { toast("Esta imagem é grande demais (máximo 30 MB)."); input.value = ""; return; }
      input.value = ""; // limpa já; o arquivo recortado entra no campo ao salvar o recorte
      openCropper(f, (blob) => {
        if (!blob) return toast("Não foi possível recortar a imagem.");
        const url = URL.createObjectURL(blob), cur = $(".avatar-pick .avatar", form);
        if (cur) cur.innerHTML = '<img src="' + url + '" alt="" style="width:100%;height:100%;object-fit:cover;border-radius:50%">';
        form.remover_foto.value = "0";
        form._cropped = blob;
        const ok = setFile(input, blob, "foto.jpg");
        if (!ok) { const r = new FileReader(); r.onload = () => (form.foto_data.value = r.result); r.readAsDataURL(blob); }
        const st = $("[data-photo-status]", form); if (st) { st.textContent = "Foto ajustada. Clique em Salvar informações para gravar."; st.classList.add("is-pending"); }
        form.dispatchEvent(new Event("input", { bubbles: true }));
      }, () => { if (form._cropped) setFile(input, form._cropped, "foto.jpg"); });
    });
  });
  $$("[data-pick-avatar]").forEach((b) => b.addEventListener("click", () => { const i = $("[data-cropper]"); if (i) i.click(); }));
  $$("[data-remove-avatar]").forEach((b) => b.addEventListener("click", () => {
    const form = b.form, cur = $(".avatar-pick .avatar", form);
    if (cur) cur.textContent = b.dataset.initials || "";
    form.remover_foto.value = "1"; form.foto_data.value = ""; form._cropped = null; const i = $("[data-cropper]", form); if (i) i.value = "";
    const st = $("[data-photo-status]", form); if (st) { st.textContent = "A foto será removida quando você clicar em Salvar informações."; st.classList.add("is-pending"); }
    form.dispatchEvent(new Event("input", { bubbles: true }));
  }));
  // Perfil: avisa se sair da página com alterações não salvas
  $$("[data-profile-form]").forEach((form) => {
    let dirty = false; const note = $("[data-unsaved]", form);
    form.addEventListener("input", () => { dirty = true; if (note) note.hidden = false; });
    form.addEventListener("submit", () => { dirty = false; const b = $("button[type=submit]", form); if (b) { b.disabled = true; b.textContent = "Salvando…"; } });
    window.addEventListener("beforeunload", (e) => { if (dirty) { e.preventDefault(); e.returnValue = ""; } });
  });

  // ---------- Logo: reduz imagens grandes antes do envio e mostra a prévia ----------
  $$("[data-image-shrink]").forEach((input) => {
    const maxDim = parseInt(input.dataset.imageShrink, 10) || 1600, maxBytes = parseInt(input.dataset.maxBytes, 10) || 0;
    const label = input.closest(".file-pick"), name = label && $("span", label), prev = input.dataset.preview && $(input.dataset.preview);
    input.addEventListener("change", () => {
      const f = input.files[0]; if (!f) return;
      if (!/^image\/(jpeg|png|webp)$/.test(f.type)) { toast("Use uma imagem PNG, JPG ou WebP."); input.value = ""; return; }
      if (f.size > MAX_SOURCE) { toast("Esta imagem é grande demais (máximo 30 MB)."); input.value = ""; return; }
      if (name) name.textContent = f.name;
      loadImage(f).then((img) => {
        const w = imgW(img), h = imgH(img), k = Math.min(1, maxDim / Math.max(w, h));
        const show = (src) => { if (prev) { prev.innerHTML = '<img src="' + src + '" alt="' + (input.dataset.alt || "Prévia da logo") + '">'; prev.hidden = false; } };
        if (k === 1 && (!maxBytes || f.size <= maxBytes * 0.95)) { const r = new FileReader(); r.onload = () => show(r.result); r.readAsDataURL(f); return; }
        const c = document.createElement("canvas"); c.width = Math.round(w * k); c.height = Math.round(h * k); c.getContext("2d").drawImage(img, 0, 0, c.width, c.height);
        const type = f.type === "image/jpeg" ? "image/jpeg" : "image/png";
        show(c.toDataURL(type, 0.9));
        c.toBlob((b) => { if (b && setFile(input, b, type === "image/png" ? "logo.png" : "logo.jpg")) { if (name) name.textContent = f.name + " (reduzida para " + c.width + "×" + c.height + ")"; } }, type, 0.9);
      }).catch(() => toast("Não foi possível abrir esta imagem."));
    });
  });
  // Imagem de comunicado/aviso: abre ampliada por cima da página (Esc, clique fora ou no X fecha)
  document.addEventListener("click", (e) => {
    const a = e.target.closest("a[data-lightbox]"); if (!a || e.ctrlKey || e.metaKey || e.shiftKey) return;
    e.preventDefault();
    const box = document.createElement("div"); box.className = "lightbox"; box.setAttribute("role", "dialog"); box.setAttribute("aria-modal", "true"); box.setAttribute("aria-label", "Imagem ampliada");
    const img = document.createElement("img"); img.src = a.getAttribute("href"); img.alt = ($("img", a) || {}).alt || "";
    const x = document.createElement("button"); x.type = "button"; x.className = "lightbox-close"; x.setAttribute("aria-label", "Fechar"); x.textContent = "×";
    const open = document.createElement("a"); open.className = "lightbox-open"; open.href = img.src; open.target = "_blank"; open.rel = "noopener"; open.textContent = "Abrir em nova aba";
    box.append(img, x, open); document.body.appendChild(box); document.body.classList.add("no-scroll"); x.focus();
    const close = () => { box.remove(); document.body.classList.remove("no-scroll"); document.removeEventListener("keydown", key); a.focus(); };
    const key = (ev) => { if (ev.key === "Escape") close(); };
    document.addEventListener("keydown", key);
    box.addEventListener("click", (ev) => { if (ev.target === box || ev.target === x) close(); });
  });
  // Carregador: aparece se a próxima página demorar mais de 350 ms (links internos e envio de formulários)
  const loader = document.querySelector("[data-page-loader]"); let loaderT = 0;
  const showLoader = () => { if (!loader) return; clearTimeout(loaderT); loaderT = setTimeout(() => { loader.hidden = false; }, 350); };
  const hideLoader = () => { clearTimeout(loaderT); if (loader) loader.hidden = true; };
  window.addEventListener("pageshow", hideLoader);
  document.addEventListener("click", (e) => {
    if (e.defaultPrevented || e.button !== 0 || e.ctrlKey || e.metaKey || e.shiftKey || e.altKey) return;
    const a = e.target.closest("a[href]"); if (!a || a.target || a.hasAttribute("download") || a.hasAttribute("data-lightbox") || a.hasAttribute("data-no-loader")) return;
    const href = a.getAttribute("href"); if (!href || href.startsWith("#") || href.startsWith("mailto:") || href.startsWith("tel:") || href.startsWith("javascript:")) return;
    const u = new URL(a.href, location.href); if (u.origin !== location.origin || (u.pathname === location.pathname && u.search === location.search && u.hash)) return;
    showLoader();
  });
  document.addEventListener("click", (e) => { const tr = e.target.closest("tr[data-href]"); if (tr && !e.target.closest("a,button,input,select,label")) showLoader(); });
  document.addEventListener("submit", (e) => { const f = e.target; if (e.defaultPrevented || f.target || f.hasAttribute("data-no-loader")) return; setTimeout(() => { if (!e.defaultPrevented) showLoader(); }, 0); });
  // Pagamento de professor: total = valor das aulas ± ajuste, atualizado enquanto digita
  $$("[data-pay-form]").forEach((form) => {
    const out = $("[data-pay-total]", form), gIn = $("[data-pay-gross-input]", form), gEl = document.querySelector("[data-pay-gross]");
    const cents = (v) => { v = String(v || "").replace(/[R$\s]/g, ""); if (v.includes(",")) v = v.replace(/\./g, "").replace(",", "."); const n = parseFloat(v); return isNaN(n) ? 0 : Math.round(Math.abs(n) * 100); };
    const fmt = (c) => (c < 0 ? "−" : "") + "R$ " + (Math.abs(c) / 100).toLocaleString("pt-BR", { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    const calc = () => {
      const gross = gIn ? cents(gIn.value) : parseInt((gEl && gEl.dataset.payGross) || "0", 10);
      const adj = cents(($("[data-pay-adjust]", form) || {}).value) * (($("[data-pay-kind]", form) || {}).value === "desconto" ? -1 : 1);
      if (out) out.textContent = fmt(gross + adj);
    };
    form.addEventListener("input", calc); form.addEventListener("change", calc); calc();
  });
  // Histórico: a prévia mostra só as assinaturas marcadas
  $$("[data-sign-form]").forEach((form) => {
    const sync = () => $$("[data-sign-toggle]", form).forEach((c) => $$('.doc [data-sign="' + c.value + '"]').forEach((el) => (el.hidden = !c.checked)));
    form.addEventListener("change", sync); sync();
    form.addEventListener("submit", (e) => { if (!$$("[data-sign-toggle]:checked", form).length) { e.preventDefault(); e.stopImmediatePropagation(); toast("Marque pelo menos uma assinatura."); } }, true);
  });
  // Tema claro/escuro/automático: guardado em cookie (1 ano) para a página já abrir no tema certo
  document.addEventListener("click", (e) => {
    const b = e.target.closest("[data-theme-set]"); if (!b) return;
    e.preventDefault();
    const t = b.dataset.themeSet, root = document.documentElement;
    if (t === "auto") { root.removeAttribute("data-theme"); document.cookie = "academus_theme=; Max-Age=0; Path=/; SameSite=Lax"; }
    else { root.setAttribute("data-theme", t); document.cookie = "academus_theme=" + t + "; Max-Age=31536000; Path=/; SameSite=Lax"; }
    $$("[data-theme-set]").forEach((x) => { x.setAttribute("aria-pressed", String(x.dataset.themeSet === t)); if (x.hasAttribute("aria-checked")) x.setAttribute("aria-checked", String(x.dataset.themeSet === t)); });
  });
  // Menu de tema no topo
  const tBtn = $("[data-theme-btn]"), tMenu = $("[data-theme-menu]");
  if (tBtn && tMenu) {
    const setOpen = (o) => { tMenu.hidden = !o; tBtn.setAttribute("aria-expanded", String(o)); };
    tBtn.addEventListener("click", (e) => { e.stopPropagation(); setOpen(tMenu.hidden); });
    tMenu.addEventListener("click", (e) => { if (e.target.closest("[data-theme-set]")) setTimeout(() => setOpen(false), 120); });
    document.addEventListener("click", (e) => { if (!tMenu.hidden && !e.target.closest(".theme-wrap")) setOpen(false); });
    document.addEventListener("keydown", (e) => { if (e.key === "Escape" && !tMenu.hidden) { setOpen(false); tBtn.focus(); } });
  }
  // Tamanho do texto: pequeno, médio (padrão) e grande; guardado em cookie (1 ano)
  document.addEventListener("click", (e) => {
    const b = e.target.closest("[data-size-set]"); if (!b) return;
    e.preventDefault();
    const z = b.dataset.sizeSet, root = document.documentElement;
    if (z === "md") { root.removeAttribute("data-size"); document.cookie = "academus_size=; Max-Age=0; Path=/; SameSite=Lax"; }
    else { root.setAttribute("data-size", z); document.cookie = "academus_size=" + z + "; Max-Age=31536000; Path=/; SameSite=Lax"; }
    $$("[data-size-set]").forEach((x) => x.setAttribute("aria-pressed", String(x.dataset.sizeSet === z)));
  });
  // Sino de notificações: abre a lista e marca tudo como lido (o número vermelho some)
  const nBtn = $("[data-notif-btn]"), nMenu = $("[data-notif-menu]");
  if (nBtn && nMenu) {
    let marked = false;
    const setOpen = (o) => { nMenu.hidden = !o; nBtn.setAttribute("aria-expanded", String(o)); };
    nBtn.addEventListener("click", (e) => {
      e.stopPropagation(); setOpen(nMenu.hidden);
      if (!nMenu.hidden && !marked && $("[data-notif-count]", nBtn)) {
        marked = true;
        const fd = new FormData(); fd.append("_csrf", nBtn.dataset.csrf); fd.append("ids", nBtn.dataset.ids);
        fetch(nBtn.dataset.url, { method: "POST", body: fd, credentials: "same-origin" }).then((r) => { if (r.ok) { const d = $("[data-notif-count]", nBtn); if (d) d.remove(); nBtn.setAttribute("aria-label", "Notificações"); } }).catch(() => (marked = false));
      }
    });
    document.addEventListener("click", (e) => { if (!nMenu.hidden && !e.target.closest(".notif-wrap")) setOpen(false); });
    document.addEventListener("keydown", (e) => { if (e.key === "Escape" && !nMenu.hidden) { setOpen(false); nBtn.focus(); } });
  }
  // Instituições do usuário: a de vínculo não aparece como "acesso extra"; com Acesso total, todas ficam marcadas
  const instHome = $("[data-inst-home]");
  if (instHome) {
    const syncInst = () => {
      const full = !!$("[data-full-toggle]")?.checked;
      $$(".inst-extra").forEach((l) => { const me = l.dataset.inst === instHome.value, box = $("input", l); l.hidden = me; box.disabled = full; if (full) box.checked = true; });
    };
    instHome.addEventListener("change", syncInst); $("[data-full-toggle]")?.addEventListener("change", syncInst); syncInst();
  }
})();
