<?php

declare(strict_types=1);

// Página de exemplo de um módulo do Summa. Troque o conteúdo pelo do seu sistema.
require __DIR__ . '/summa-client.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['acao'] ?? '') === 'sair') {
    summa_require_login();
    // O token do formulário impede que outro site force a saída
    if (hash_equals((string)($_SESSION['csrf'] ?? ''), (string)($_POST['csrf'] ?? ''))) summa_logout();
}

$user = summa_require_login();
$_SESSION['csrf'] ??= bin2hex(random_bytes(32));
$manifest = json_decode((string)file_get_contents(__DIR__ . '/summa-module.json'), true);
$e = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $e($manifest['name']) ?> · Summa</title>
<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
  body { margin: 0; background: #F4F6FA; color: #172B4D; font: 14px/1.5 Montserrat, "Segoe UI", Roboto, Arial, sans-serif; }
  header { background: #172B4D; color: #fff; min-height: 64px; padding: 10px 24px; display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 12px; box-sizing: border-box; }
  header strong { font-size: 16px; }
  .actions { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; }
  .btn { min-height: 44px; padding: 0 14px; display: inline-flex; align-items: center; border: 1px solid rgba(255,255,255,.45); border-radius: 8px; background: transparent; color: #fff; font: inherit; font-weight: 600; text-decoration: none; cursor: pointer; box-sizing: border-box; }
  main { max-width: 760px; margin: 0 auto; padding: 32px 24px 56px; display: flex; flex-direction: column; gap: 16px; }
  h1 { margin: 0; font-size: 24px; line-height: 1.2; }
  .muted { color: #5E6C84; margin: 0; }
  .card { background: #fff; border: 1px solid #E1E5EC; border-radius: 12px; padding: 20px 24px; }
  .card h2 { margin: 0 0 12px; font-size: 15px; }
  ul { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 8px; }
  li { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 10px 14px; border: 1px solid #E1E5EC; border-radius: 8px; }
  .pill { padding: 3px 10px; border-radius: 999px; font-size: 12px; font-weight: 600; white-space: nowrap; }
  .on { background: #E6F4EE; color: #17694A; } .off { background: #EEF1F5; color: #44546F; }
</style>
</head>
<body>
<header>
  <strong>Summa · <?= $e($manifest['name']) ?></strong>
  <div class="actions">
    <a class="btn" href="<?= $e(summa_workspace_url()) ?>">Todos os módulos</a>
    <form method="post" style="margin:0"><input type="hidden" name="acao" value="sair"><input type="hidden" name="csrf" value="<?= $e($_SESSION['csrf']) ?>"><button class="btn" type="submit">Sair</button></form>
  </div>
</header>
<main>
  <div>
    <h1>Olá, <?= $e($user['name']) ?></h1>
    <p class="muted">Você entrou pelo Summa como <?= $e($user['email']) ?>.</p>
  </div>
  <section class="card">
    <h2>O que você pode fazer aqui</h2>
    <ul>
      <?php foreach ($manifest['permissions'] as $p): $has = summa_can($p['code']); ?>
      <li data-perm="<?= $e($p['code']) ?>" data-on="<?= $has ? '1' : '0' ?>"><span><?= $e($p['label']) ?></span><span class="pill <?= $has ? 'on' : 'off' ?>"><?= $has ? 'Liberado' : 'Bloqueado' ?></span></li>
      <?php endforeach; ?>
    </ul>
  </section>
  <p class="muted">As permissões são definidas no Summa, em Painel de controle › Gerenciamento de usuários, e valem a partir da próxima vez que a pessoa abrir o módulo.</p>
</main>
</body>
</html>
