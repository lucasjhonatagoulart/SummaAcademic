<?php
// Tela de entrada: painel da marca à esquerda (telas largas) e formulário à direita.
?><!doctype html>
<html lang="pt-BR">
<head>
    <?= head_assets() ?>
    <title>Entrar · <?= h(display_title()) ?></title>
</head>
<body>
<div class="login">
    <?= auth_brand_panel() ?>
    <section class="login-form-wrap">
        <main class="login-box">
            <?= auth_mobile_logo() ?>
            <?php $inSumma = summa_enabled(); $showLocal = !$inSumma || isset($_GET['local']) || $flash; ?>
            <div>
                <p class="eyebrow">Acesso restrito</p>
                <h1><?= $inSumma ? 'Entre pelo Summa' : 'Entre no seu controle' ?></h1>
            </div>
            <?php if ($inSumma): ?>
            <p>O Patrimônio é um módulo da plataforma Summa. Entre com a sua conta do Summa; usuários e permissões são definidos no Painel de controle de lá.</p>
            <a class="button primary" href="<?= h(summa_url('patrimonio')) ?>">Entrar pelo Summa</a>
            <?php else: ?>
            <p>Use sua conta para visualizar e manter seus registros. Cada usuário tem permissões próprias definidas no Painel de controle.</p>
            <?php endif; ?>
            <?php if ($installed): ?><div class="alert success">Instalação concluída. Entre com a conta de administrador que acabou de criar.</div><?php endif; ?>
            <?php if ($flash): ?><div class="alert error" role="alert"><?= h($flash['message']) ?></div><?php endif; ?>
            <?php if ($showLocal): ?>
            <?php if ($inSumma): ?><p class="tiny-note">Conta local do Patrimônio (uso de emergência, quando o Summa estiver fora do ar):</p><?php endif; ?>
            <form method="post" action="index.php" autocomplete="on">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="login">
                <label class="field"><span>Usuário</span><input type="text" name="username" required maxlength="80" autocomplete="username"></label>
                <label class="field"><span>Senha</span><input type="password" name="password" required autocomplete="current-password"></label>
                <button class="button<?= $inSumma ? '' : ' primary' ?>" type="submit">Entrar<?= $inSumma ? ' com conta local' : '' ?></button>
            </form>
            <?php else: ?>
            <p class="tiny-note"><a href="index.php?page=login&amp;local=1"><u>Usar a conta local do Patrimônio</u></a> (emergência).</p>
            <?php endif; ?>
            <?php if (!$inSumma): ?><p class="tiny-note">O acesso é privado. Se ainda não instalou o sistema, use <a href="install.php"><u>o instalador seguro</u></a>.</p><?php endif; ?>
            <?= appearance_panel() ?>
        </main>
    </section>
</div>
</body>
</html>
