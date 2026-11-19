/* Ponte entre o app e o servidor PHP. Entrega ao app as mesmas funções que ele já usava
   (banco de documentos e dados da conta), agora falando com api.php. */
(function () {
  "use strict";
  var B = window.__PTI || {};
  var USER = B.user || {};
  var estado = { v: {}, dados: {}, subs: {}, pronto: null, timer: null, buscando: null };
  var COLS = ["pcs", "os", "osx", "manut", "redes", "projetores", "users"];

  function api(acao, op) {
    op = op || {};
    var metodo = op.method || "POST";
    var url = "api.php?a=" + encodeURIComponent(acao) + (op.query ? "&" + op.query : "");
    var init = { method: metodo, credentials: "same-origin", headers: { "X-CSRF-Token": B.csrf || "" } };
    if (metodo === "POST") { init.headers["Content-Type"] = "application/json"; init.body = JSON.stringify(op.body || {}); }
    return fetch(url, init).then(function (r) {
      return r.json().catch(function () { return { ok: false, erro: "Resposta inesperada do servidor." }; }).then(function (j) {
        if (r.status === 401) { location.reload(); throw new Error("sessao"); }
        if (!j.ok) { var e = new Error(j.erro || "Erro"); e.status = r.status; throw e; }
        return j;
      });
    });
  }

  function aviso(col) {
    (estado.subs[col] || []).forEach(function (s) {
      try { s.cb(fotografia(col)); } catch (e) { if (window.console) console.error(e); }
    });
  }

  function fotografia(col) {
    var m = estado.dados[col] || {};
    return { docs: Object.keys(m).map(function (id) { return { id: id, data: function () { return JSON.parse(JSON.stringify(m[id])); } }; }) };
  }

  function sincronizar() {
    if (estado.buscando) return estado.buscando;
    var v = COLS.filter(function (c) { return estado.v[c] != null; }).map(function (c) { return c + ":" + estado.v[c]; }).join(",");
    estado.buscando = api("sync", { method: "GET", query: v ? "v=" + encodeURIComponent(v) : "" }).then(function (j) {
      Object.keys(j.v).forEach(function (c) { estado.v[c] = j.v[c]; });
      Object.keys(j.dados || {}).forEach(function (c) {
        var d = j.dados[c];
        estado.dados[c] = (d && typeof d === "object") ? d : {};
        aviso(c);
      });
      return true;
    }).catch(function (e) {
      Object.keys(estado.subs).forEach(function (c) { (estado.subs[c] || []).forEach(function (s) { if (s.err && !estado.dados[c]) s.err(e); }); });
      return false;
    }).then(function (r) { estado.buscando = null; return r; });
    return estado.buscando;
  }

  function agendar() {
    clearInterval(estado.timer);
    estado.timer = setInterval(function () { if (document.visibilityState === "visible") sincronizar(); }, 20000);
  }
  document.addEventListener("visibilitychange", function () { if (document.visibilityState === "visible") sincronizar(); });

  function garantirPrimeira() {
    if (!estado.pronto) { estado.pronto = sincronizar(); agendar(); }
    return estado.pronto;
  }

  function dividir(path) { var p = String(path).split("/"); return { col: p[0], id: p[1] }; }

  function doc(path) {
    var p = dividir(path);
    return {
      get: function () {
        return api("get", { method: "GET", query: "col=" + encodeURIComponent(p.col) + "&id=" + encodeURIComponent(p.id) }).then(function (j) {
          return { exists: !!j.existe, data: function () { return j.dados; } };
        });
      },
      set: function (d) { return api("set", { body: { col: p.col, id: p.id, dados: d } }).then(function () { return sincronizar(); }); },
      update: function (d) { return api("update", { body: { col: p.col, id: p.id, dados: d } }).then(function () { return sincronizar(); }); },
      delete: function () { return api("delete", { body: { col: p.col, id: p.id } }).then(function () { return sincronizar(); }); }
    };
  }

  var dbApi = {
    doc: doc,
    collection: function (col) {
      return {
        doc: function (id) { return doc(col + "/" + id); },
        onSnapshot: function (cb, err) {
          var s = { cb: cb, err: err };
          (estado.subs[col] = estado.subs[col] || []).push(s);
          garantirPrimeira().then(function () { if (estado.dados[col]) { try { cb(fotografia(col)); } catch (e) { if (window.console) console.error(e); } } });
          return function () { estado.subs[col] = (estado.subs[col] || []).filter(function (x) { return x !== s; }); };
        }
      };
    }
  };

  var userApi = {
    isOwner: function () { return Promise.resolve(USER.role === "admin"); },
    canEdit: function () { return Promise.resolve(USER.role === "admin" || USER.role === "tecnico"); },
    can: function () { return Promise.resolve(true); },
    id: function () { return Promise.resolve(String(USER.id)); },
    me: function () { return Promise.resolve({ name: USER.nome, email: USER.email }); },
    profiles: function (ids) {
      return garantirPrimeira().then(function () {
        var m = estado.dados.users || {}, out = {};
        (ids || []).forEach(function (id) { if (m[id]) out[id] = { name: m[id].nome, guest: false }; });
        return out;
      });
    },
    search: function () { return Promise.resolve([]); }
  };

  window.claude = {
    use: function (nome) {
      if (nome === "db") return Promise.resolve(dbApi);
      if (nome === "user") return Promise.resolve(userApi);
      return Promise.resolve(null);
    }
  };

  window.PTI = {
    user: USER,
    api: api,
    sync: sincronizar,
    logout: function () {
      if (B.modulo) { location.href = "index.php?sair=" + encodeURIComponent(B.csrf || ""); return Promise.resolve(); }
      return api("logout").catch(function () {}).then(function () { location.reload(); });
    }
  };
})();
