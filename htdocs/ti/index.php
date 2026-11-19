<?php
/* Página inicial: mostra o login, a troca obrigatória de senha ou o sistema. */
require __DIR__ . '/lib/core.php';
require __DIR__ . '/lib/summa.php';

header('Cache-Control: no-store');
header('Content-Type: text/html; charset=utf-8');

$u = null; $erroBanco = '';
$modulo = modo_modulo() && !isset($_GET['local']);   /* ?local=1: acesso de emergência com conta local */
if ($modulo && isset($_GET['sair'])) { modulo_sair($_GET['sair']); }
try { $u = $modulo ? modulo_entrar() : usuario_atual(); } catch (Exception $e) { $erroBanco = $e->getMessage(); error_log('PTI: ' . $e->getMessage()); }

$estilo = <<<'CSS'
*{box-sizing:border-box}html,body{margin:0}
:root{--bg:#F4F6FA;--card:#fff;--ink:#14202E;--mute:#637083;--line:#E0E5EC;--accent:#1F4E8C;--crit:#B83A2A;--side:#0E2A4A}
@media (prefers-color-scheme:dark){:root{--bg:#0D1420;--card:#151E2D;--ink:#E6ECF5;--mute:#93A1B5;--line:#243249;--accent:#7FA8E8;--side:#0A1A30}}
body{background:var(--bg);color:var(--ink);font:16px/1.5 Arial,"Liberation Sans",Helvetica,sans-serif;min-height:100vh;display:grid;place-items:center;padding:20px}
.box{width:100%;max-width:400px;background:var(--card);border:1px solid var(--line);border-radius:14px;padding:28px 24px;box-shadow:0 8px 28px rgba(14,42,74,.10)}
.logo{display:flex;align-items:center;gap:12px;margin-bottom:20px}
.logo i{width:42px;height:42px;border-radius:10px;background:var(--side);display:grid;place-items:center;color:#fff;font-style:normal;font-weight:700}
.logo b{display:block;font-size:20px}.logo span{font-size:12px;color:var(--mute);letter-spacing:.06em;text-transform:uppercase}
h1{font-size:18px;margin:0 0 4px}p.s{margin:0 0 18px;color:var(--mute);font-size:14px}
label{display:block;font-size:12px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--mute);margin:14px 0 6px}
input{width:100%;font:inherit;font-size:16px;color:var(--ink);background:var(--card);border:1px solid var(--line);border-radius:8px;padding:11px}
input:focus{outline:2px solid var(--accent);outline-offset:1px}
button{width:100%;margin-top:20px;font:inherit;font-weight:700;background:var(--accent);color:#fff;border:0;border-radius:8px;padding:12px;cursor:pointer}
button[disabled]{opacity:.6;cursor:wait}
.err{color:var(--crit);font-size:14px;margin-top:12px;min-height:1.2em}
.pw{position:relative}.pw input{padding-right:88px}
.pw button.t{position:absolute;right:6px;top:50%;transform:translateY(-50%);width:auto;margin:0;padding:6px 10px;background:transparent;color:var(--accent);font-size:13px;font-weight:700;border-radius:6px}
.pw button.t:focus-visible{outline:2px solid var(--accent)}
.f{margin-top:18px;font-size:12px;color:var(--mute);text-align:center}
CSS;

function pagina($titulo, $corpo, $estilo) {
    echo '<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"><title>' . htmlspecialchars($titulo) . '</title><style>' . $estilo . '</style></head><body>' . $corpo . '</body></html>';
}

$logo = '<div class="logo"><i>TI</i><div><b>Setor de TI</b><span>Controle de computadores</span></div></div>';

if ($erroBanco !== '') {
    pagina('Setor de TI', '<div class="box">' . $logo . '<h1>Banco de dados indisponível</h1><p class="s">Não foi possível conectar ao banco. Confira os dados no arquivo <b>config.php</b> e se o schema foi importado no phpMyAdmin.</p></div>', $estilo);
    exit;
}

/* ---------- 1) sem login ---------- */
if (!$u) {
    $corpo = '<form class="box" id="f" autocomplete="on">' . $logo . '<h1>Entrar</h1><p class="s">Use o e-mail e a senha que o TI cadastrou para você.</p>
<label for="e">E-mail</label><input id="e" type="email" name="email" autocomplete="username" required autofocus>
<label for="s">Senha</label><div class="pw"><input id="s" type="password" name="senha" autocomplete="current-password" required><button type="button" class="t" aria-pressed="false" aria-label="Mostrar ou ocultar a senha">Mostrar</button></div>
<div class="err" id="m" role="alert"></div><button id="b" type="submit">Entrar</button><div class="f">Esqueceu a senha? Peça ao administrador para gerar uma nova.</div></form>
<script>
document.getElementById("f").addEventListener("submit",async function(ev){ev.preventDefault();
 var b=document.getElementById("b"),m=document.getElementById("m");b.disabled=true;m.textContent="";
 try{var r=await fetch("api.php?a=login",{method:"POST",headers:{"Content-Type":"application/json"},credentials:"same-origin",body:JSON.stringify({email:document.getElementById("e").value,senha:document.getElementById("s").value})});
  var j=await r.json();if(j.ok){location.reload()}else{m.textContent=j.erro||"Não foi possível entrar.";b.disabled=false}}
 catch(e){m.textContent="Sem conexão com o servidor. Tente de novo.";b.disabled=false}});
document.querySelectorAll(".pw .t").forEach(function(b){b.addEventListener("click",function(){var i=b.parentNode.querySelector("input");var m=i.type==="password";i.type=m?"text":"password";b.textContent=m?"Ocultar":"Mostrar";b.setAttribute("aria-pressed",m?"true":"false")})});
</script>';
    pagina('Entrar · Setor de TI', $corpo, $estilo);
    exit;
}

/* ---------- 2) troca obrigatória de senha (primeiro acesso ou senha redefinida) ---------- */
if ((int)$u['trocar_senha'] === 1) {
    $csrf = csrf_token();
    $corpo = '<form class="box" id="f">' . $logo . '<h1>Crie a sua senha</h1><p class="s">Olá, ' . htmlspecialchars(explode(' ', $u['nome'])[0]) . '. Por segurança, troque a senha provisória por uma só sua (mínimo de 8 caracteres).</p>
<label for="a">Senha provisória</label><div class="pw"><input id="a" type="password" autocomplete="current-password" required><button type="button" class="t" aria-pressed="false" aria-label="Mostrar ou ocultar a senha">Mostrar</button></div>
<label for="n">Nova senha</label><div class="pw"><input id="n" type="password" autocomplete="new-password" minlength="8" required><button type="button" class="t" aria-pressed="false" aria-label="Mostrar ou ocultar a senha">Mostrar</button></div>
<label for="c">Repita a nova senha</label><div class="pw"><input id="c" type="password" autocomplete="new-password" minlength="8" required><button type="button" class="t" aria-pressed="false" aria-label="Mostrar ou ocultar a senha">Mostrar</button></div>
<div class="err" id="m" role="alert"></div><button id="b" type="submit">Salvar e entrar</button>
<div class="f"><a href="#" id="out" style="color:inherit">Sair</a></div></form>
<script>
var T=' . json_encode($csrf) . ';
document.getElementById("out").addEventListener("click",async function(e){e.preventDefault();await fetch("api.php?a=logout",{method:"POST",headers:{"X-CSRF-Token":T},credentials:"same-origin"});location.reload()});
document.getElementById("f").addEventListener("submit",async function(ev){ev.preventDefault();
 var b=document.getElementById("b"),m=document.getElementById("m");m.textContent="";
 if(document.getElementById("n").value!==document.getElementById("c").value){m.textContent="As senhas novas não são iguais.";return}
 b.disabled=true;
 try{var r=await fetch("api.php?a=change_password",{method:"POST",headers:{"Content-Type":"application/json","X-CSRF-Token":T},credentials:"same-origin",body:JSON.stringify({atual:document.getElementById("a").value,nova:document.getElementById("n").value})});
  var j=await r.json();if(j.ok){location.reload()}else{m.textContent=j.erro||"Não foi possível salvar.";b.disabled=false}}
 catch(e){m.textContent="Sem conexão com o servidor.";b.disabled=false}});
document.querySelectorAll(".pw .t").forEach(function(b){b.addEventListener("click",function(){var i=b.parentNode.querySelector("input");var m=i.type==="password";i.type=m?"text":"password";b.textContent=m?"Ocultar":"Mostrar";b.setAttribute("aria-pressed",m?"true":"false")})});
</script>';
    pagina('Nova senha · Setor de TI', $corpo, $estilo);
    exit;
}

/* ---------- 3) sistema ---------- */
$html = file_get_contents(__DIR__ . '/lib/app.html');
$boot = json_encode(['user' => ['id' => (string)$u['id'], 'nome' => $u['nome'], 'email' => $u['email'], 'role' => $u['role']], 'csrf' => csrf_token(), 'modulo' => !empty($_SESSION['mod']), 'summaUrl' => !empty($_SESSION['mod']) ? summa_modulos_url() : summa_url(),
    'logo' => !empty($_SESSION['mod']) ? summa_logo() : '', 'foto' => !empty($_SESSION['mod']) ? (string)(isset($_SESSION['foto']) ? $_SESSION['foto'] : '') : ''],
    JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
$ver = @filemtime(__DIR__ . '/assets/shim.js') ?: 1;
$html = str_replace(['/*PTI_BOOT*/', '/*PTI_VER*/'], ['window.__PTI=' . $boot . ';', (string)$ver], $html);
echo $html;
