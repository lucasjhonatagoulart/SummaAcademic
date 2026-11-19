<?php
/* API do Setor de TI: login, sincronização, documentos e administração de contas. */
require __DIR__ . '/lib/core.php';

$a = isset($_GET['a']) ? (string)$_GET['a'] : '';
$metodo = $_SERVER['REQUEST_METHOD'];

try {
    iniciar_sessao();

    /* ---------- login / logout (sem sessão prévia) ---------- */
    if ($a === 'login') {
        if (!empty($_SESSION['mod'])) erro('Este módulo entra pelo Summa.', 403);
        if ($metodo !== 'POST') erro('Método inválido.', 405);
        $b = corpo();
        $email = strtolower(trim(isset($b['email']) ? (string)$b['email'] : ''));
        $senha = isset($b['senha']) ? (string)$b['senha'] : '';
        $ip = isset($_SERVER['REMOTE_ADDR']) ? substr($_SERVER['REMOTE_ADDR'], 0, 45) : '';
        $pdo = db();
        $pdo->prepare('DELETE FROM tentativas_login WHERE quando < ?')->execute([date('Y-m-d H:i:s', time() - 900)]);
        $st = $pdo->prepare('SELECT COUNT(*) AS n FROM tentativas_login WHERE (email = ? OR ip = ?) AND quando >= ?');
        $st->execute([$email, $ip, date('Y-m-d H:i:s', time() - 900)]);
        $r = $st->fetch();
        if ((int)$r['n'] >= 10) erro('Muitas tentativas. Aguarde 15 minutos e tente de novo.', 429);
        $st = $pdo->prepare('SELECT * FROM accounts WHERE email = ?');
        $st->execute([$email]);
        $u = $st->fetch();
        $ok = $u && (int)$u['ativo'] && password_verify($senha, $u['senha_hash']);
        if (!$ok) {
            $pdo->prepare('INSERT INTO tentativas_login (ip, email, quando) VALUES (?, ?, ?)')->execute([$ip, $email, agora()]);
            erro('E-mail ou senha incorretos.', 401);
        }
        session_regenerate_id(true);
        $_SESSION['uid'] = (int)$u['id'];
        $_SESSION['csrf'] = bin2hex(random_bytes(24));
        $pdo->prepare('DELETE FROM tentativas_login WHERE email = ?')->execute([$email]);
        $pdo->prepare('UPDATE accounts SET ultimo_acesso = ? WHERE id = ?')->execute([agora(), (int)$u['id']]);
        json_saida(['ok' => true, 'trocar_senha' => (int)$u['trocar_senha'] === 1]);
    }

    $u = usuario_atual();
    if (!$u) erro('Entre para continuar.', 401);

    if ($a === 'logout') {
        if ($metodo !== 'POST') erro('Método inválido.', 405);
        exigir_csrf();
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
        json_saida(['ok' => true]);
    }

    /* ---------- troca da própria senha ---------- */
    if ($a === 'change_password') {
        if (!empty($_SESSION['mod'])) erro('A senha é gerenciada pelo Summa.', 403);
        if ($metodo !== 'POST') erro('Método inválido.', 405);
        exigir_csrf();
        $b = corpo();
        $atual = isset($b['atual']) ? (string)$b['atual'] : '';
        $nova = isset($b['nova']) ? (string)$b['nova'] : '';
        if (strlen($nova) < 8) erro('A nova senha precisa ter pelo menos 8 caracteres.');
        $st = db()->prepare('SELECT senha_hash FROM accounts WHERE id = ?');
        $st->execute([(int)$u['id']]);
        $r = $st->fetch();
        if (!password_verify($atual, $r['senha_hash'])) erro('A senha atual está incorreta.', 403);
        if ($atual === $nova) erro('Escolha uma senha diferente da atual.');
        db()->prepare('UPDATE accounts SET senha_hash = ?, trocar_senha = 0 WHERE id = ?')->execute([password_hash($nova, PASSWORD_DEFAULT), (int)$u['id']]);
        json_saida(['ok' => true]);
    }

    if ((int)$u['trocar_senha'] === 1) erro('Defina uma nova senha para continuar.', 403);

    /* ---------- sincronização: devolve só as coleções que mudaram ---------- */
    if ($a === 'sync') {
        $tem = [];
        if (!empty($_GET['v'])) foreach (explode(',', (string)$_GET['v']) as $par) {
            $p = explode(':', $par);
            if (count($p) === 2) $tem[$p[0]] = (int)$p[1];
        }
        $versoes = []; $dados = [];
        foreach (array_merge(COLECOES, ['users']) as $col) {
            $v = versao_de($col);
            $versoes[$col] = $v;
            if (isset($tem[$col]) && $tem[$col] === $v) continue;
            $dados[$col] = new stdClass();
            $mapa = [];
            if ($col === 'users') {
                foreach (db()->query('SELECT id, nome, role, ativo FROM accounts')->fetchAll() as $r) {
                    $mapa[(string)$r['id']] = ['nome' => $r['nome'], 'role' => $r['role'], 'ativo' => (int)$r['ativo'] === 1];
                }
            } else {
                if ($col === 'manut' && !staff($u)) { $dados[$col] = new stdClass(); continue; }
                $st = db()->prepare('SELECT doc_id, dados FROM docs WHERE colecao = ?');
                $st->execute([$col]);
                foreach ($st->fetchAll() as $r) {
                    $d = json_decode($r['dados'], true);
                    if (!is_array($d)) $d = [];
                    if (pode_ler($u, $col, $d, $r['doc_id'])) $mapa[$r['doc_id']] = $d;
                }
            }
            if ($mapa) $dados[$col] = $mapa;
        }
        json_saida(['ok' => true, 'v' => $versoes, 'dados' => $dados]);
    }

    /* ---------- leitura de um documento ---------- */
    if ($a === 'get') {
        $col = isset($_GET['col']) ? (string)$_GET['col'] : '';
        $id = isset($_GET['id']) ? (string)$_GET['id'] : '';
        if (!in_array($col, COLECOES, true) || !id_valido($id)) erro('Pedido inválido.');
        $d = doc_ler($col, $id);
        if ($d === null) json_saida(['ok' => true, 'existe' => false, 'dados' => null]);
        if (!pode_ler($u, $col, $d, $id)) json_saida(['ok' => true, 'existe' => true, 'dados' => null]); // só confirma que existe
        json_saida(['ok' => true, 'existe' => true, 'dados' => $d]);
    }

    /* ---------- escrita ---------- */
    if (in_array($a, ['set', 'update', 'delete'], true)) {
        if ($metodo !== 'POST') erro('Método inválido.', 405);
        exigir_csrf();
        $b = corpo();
        $col = isset($b['col']) ? (string)$b['col'] : '';
        $id = isset($b['id']) ? (string)$b['id'] : '';
        if (!in_array($col, COLECOES, true) || !id_valido($id)) erro('Pedido inválido.');
        $pdo = db();
        $existente = doc_ler($col, $id);

        // quem pode escrever
        if ($col === 'os') {
            if (equipe($u)) { /* livre */ }
            else {
                // solicitante e diretor só criam pedidos novos, em nome próprio
                if ($a !== 'set' || $existente !== null) erro('Sem permissão.', 403);
                $dados = isset($b['dados']) ? $b['dados'] : null;
                if (!is_array($dados) || (string)(isset($dados['solicitanteId']) ? $dados['solicitanteId'] : '') !== (string)$u['id']) erro('Sem permissão.', 403);
            }
        } else {
            if (!equipe($u)) erro('Sem permissão.', 403);
        }

        if ($a === 'delete') {
            $pdo->prepare('DELETE FROM docs WHERE colecao = ? AND doc_id = ?')->execute([$col, $id]);
            versao_subir($col);
            json_saida(['ok' => true]);
        }

        $dados = limpar_dados(isset($b['dados']) ? $b['dados'] : null);
        if ($a === 'update') {
            $base = $existente === null ? [] : $existente;
            $dados = array_merge($base, remover_marcas($dados));
            foreach (isset($b['dados']) ? $b['dados'] : [] as $k => $v) { if (is_array($v) && isset($v['__delete__'])) unset($dados[$k]); }
        }
        $json = json_encode($dados, JSON_UNESCAPED_UNICODE);
        if ($existente === null) {
            try { $pdo->prepare('INSERT INTO docs (colecao, doc_id, dados, atualizado_em) VALUES (?, ?, ?, ?)')->execute([$col, $id, $json, agora()]); }
            catch (PDOException $e) { erro('Já existe um registro com este número.', 409); }
        } else {
            $pdo->prepare('UPDATE docs SET dados = ?, atualizado_em = ? WHERE colecao = ? AND doc_id = ?')->execute([$json, agora(), $col, $id]);
        }
        versao_subir($col);
        json_saida(['ok' => true]);
    }

    /* ---------- administração de contas (somente administrador) ---------- */
    if (strpos($a, 'account') === 0) {
        if ($u['role'] !== 'admin') erro('Apenas administradores.', 403);
        if (!empty($_SESSION['mod'])) erro('As contas são gerenciadas no Painel de controle do Summa.', 403);
        $pdo = db();
        if ($a === 'accounts') {
            /* contas desativadas antes desta versão: passam a ser tratadas como removidas (libera o e-mail e some da lista) */
            foreach ($pdo->query('SELECT id FROM accounts WHERE ativo = 0 AND email NOT LIKE \'%@removido.invalid\'')->fetchAll() as $z) { conta_apagar($pdo, (int)$z['id']); }
            $rows = $pdo->query("SELECT id, nome, email, role, ativo, ultimo_acesso, criado_em, trocar_senha FROM accounts WHERE email NOT LIKE '%@removido.invalid' ORDER BY nome")->fetchAll();
            foreach ($rows as &$r) { $r['id'] = (string)$r['id']; $r['ativo'] = (int)$r['ativo'] === 1; $r['trocar_senha'] = (int)$r['trocar_senha'] === 1; }
            json_saida(['ok' => true, 'accounts' => $rows]);
        }
        if ($metodo !== 'POST') erro('Método inválido.', 405);
        exigir_csrf();
        $b = corpo();

        if ($a === 'account_create') {
            $nome = trim(isset($b['nome']) ? (string)$b['nome'] : '');
            $email = strtolower(trim(isset($b['email']) ? (string)$b['email'] : ''));
            $role = isset($b['role']) ? (string)$b['role'] : '';
            $senha = isset($b['senha']) ? (string)$b['senha'] : '';
            if (tam($nome) < 3 || tam($nome) > 120) erro('Informe o nome completo.');
            if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) erro('E-mail inválido.');
            if (!in_array($role, PAPEIS, true)) erro('Função inválida.');
            if (strlen($senha) < 8) erro('A senha provisória precisa ter pelo menos 8 caracteres.');
            $st = $pdo->prepare('SELECT id FROM accounts WHERE email = ?');
            $st->execute([$email]);
            if ($st->fetch()) erro('Já existe uma conta com este e-mail.', 409);
            $pdo->prepare('INSERT INTO accounts (nome, email, senha_hash, role, ativo, trocar_senha, criado_em) VALUES (?, ?, ?, ?, 1, 1, ?)')
                ->execute([$nome, $email, password_hash($senha, PASSWORD_DEFAULT), $role, agora()]);
            versao_subir('users');
            json_saida(['ok' => true]);
        }

        $alvo = isset($b['id']) ? (int)$b['id'] : 0;
        $st = $pdo->prepare('SELECT * FROM accounts WHERE id = ?');
        $st->execute([$alvo]);
        $conta = $st->fetch();
        if (!$conta) erro('Conta não encontrada.', 404);
        $eu = (int)$u['id'] === $alvo;

        if ($a === 'account_update') {
            $campos = []; $vals = [];
            if (isset($b['role'])) {
                if (!in_array($b['role'], PAPEIS, true)) erro('Função inválida.');
                if ($eu && $b['role'] !== 'admin') erro('Você não pode tirar o seu próprio acesso de administrador.');
                $campos[] = 'role = ?'; $vals[] = $b['role'];
            }
            if (isset($b['nome'])) {
                $n = trim((string)$b['nome']);
                if (tam($n) < 3 || tam($n) > 120) erro('Nome inválido.');
                $campos[] = 'nome = ?'; $vals[] = $n;
            }
            if (isset($b['email'])) {
                $em = strtolower(trim((string)$b['email']));
                if (!filter_var($em, FILTER_VALIDATE_EMAIL) || strlen($em) > 190) erro('E-mail inválido.');
                $st = $pdo->prepare('SELECT id FROM accounts WHERE email = ? AND id <> ?');
                $st->execute([$em, $alvo]);
                if ($st->fetch()) erro('Já existe outra conta com este e-mail.', 409);
                $campos[] = 'email = ?'; $vals[] = $em;
            }
            if (isset($b['ativo'])) {
                if ($eu && !$b['ativo']) erro('Você não pode remover a sua própria conta.');
                $campos[] = 'ativo = ?'; $vals[] = $b['ativo'] ? 1 : 0;
            }
            if (!$campos) erro('Nada para alterar.');
            $vals[] = $alvo;
            $pdo->prepare('UPDATE accounts SET ' . implode(', ', $campos) . ' WHERE id = ?')->execute($vals);
            versao_subir('users');
            json_saida(['ok' => true]);
        }

        if ($a === 'account_delete') {
            if ($eu) erro('Você não pode remover a sua própria conta.');
            conta_apagar($pdo, $alvo);
            versao_subir('users');
            json_saida(['ok' => true]);
        }

        if ($a === 'account_reset') {
            $senha = isset($b['senha']) ? (string)$b['senha'] : '';
            if (strlen($senha) < 8) erro('A senha provisória precisa ter pelo menos 8 caracteres.');
            $pdo->prepare('UPDATE accounts SET senha_hash = ?, trocar_senha = 1 WHERE id = ?')->execute([password_hash($senha, PASSWORD_DEFAULT), $alvo]);
            json_saida(['ok' => true]);
        }
    }

    erro('Ação desconhecida.', 404);
} catch (Exception $e) {
    error_log('PTI: ' . $e->getMessage());
    erro('Erro no servidor. Confira a configuração do banco de dados.', 500);
}
