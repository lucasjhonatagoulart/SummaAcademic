<?php
// Conexão PDO (MySQL no InfinityFree; SQLite só para testes locais) e atalhos de consulta.

function db(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;
    $c = CONFIG['db'];
    if (($c['driver'] ?? 'mysql') === 'sqlite') {
        $pdo = new PDO('sqlite:' . $c['path']);
        $pdo->exec('PRAGMA foreign_keys = ON');
    } else {
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $c['host'], $c['port'] ?? 3306, $c['name']);
        $pdo = new PDO($dsn, $c['user'], $c['pass']);
        $pdo->exec("SET time_zone = '-03:00'");
    }
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
    return $pdo;
}

function q(string $sql, array $p = []): PDOStatement {
    $st = db()->prepare($sql);
    $st->execute(array_values($p));
    return $st;
}
function rows(string $sql, array $p = []): array { return q($sql, $p)->fetchAll(); }
function row(string $sql, array $p = []): ?array { $r = q($sql, $p)->fetch(); return $r === false ? null : $r; }
function val(string $sql, array $p = []) { $v = q($sql, $p)->fetchColumn(); return $v === false ? null : $v; }
function col(string $sql, array $p = []): array { return q($sql, $p)->fetchAll(PDO::FETCH_COLUMN); }

function insert(string $table, array $data): int {
    $cols = array_keys($data);
    q("INSERT INTO $table (" . implode(',', $cols) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')', array_values($data));
    return (int) db()->lastInsertId();
}
function update(string $table, array $data, string $where, array $wp = []): int {
    $set = implode(',', array_map(fn($c) => "$c = ?", array_keys($data)));
    return q("UPDATE $table SET $set WHERE $where", array_merge(array_values($data), $wp))->rowCount();
}
function tx(callable $fn) {
    $pdo = db();
    $pdo->beginTransaction();
    try { $r = $fn(); $pdo->commit(); return $r; }
    catch (Throwable $e) { $pdo->rollBack(); throw $e; }
}
/** Placeholders "?,?,?" para IN (...) */
function in_list(array $a): string { return $a ? implode(',', array_fill(0, count($a), '?')) : 'NULL'; }

function setting(string $k, $default = null) {
    static $cache = null;
    if ($cache === null) $cache = array_column(rows('SELECT k, v FROM settings'), 'v', 'k');
    return $cache[$k] ?? $default;
}
// Atualiza o banco de uma instalação existente sem reinstalar (roda uma vez por versão).
const SCHEMA_VERSION = 12;
function migrate(): void {
    if ((int) setting('schema_version', '1') >= SCHEMA_VERSION) return;
    try { db()->query('SELECT teaching_director_name FROM institutions LIMIT 1'); }
    catch (PDOException $e) { db()->exec('ALTER TABLE institutions ADD COLUMN teaching_director_name VARCHAR(160)'); }
    if ((int) setting('schema_version', '1') < 3) users_to_individual_perms();
    if ((int) setting('schema_version', '1') < 4 && setting('mail_from_name') === 'Academus') set_setting('mail_from_name', APP_NAME); // novo nome da plataforma
    if ((int) setting('schema_version', '1') < 6) { // notificações lidas de cada usuário
        try { db()->query('SELECT notif_seen FROM users LIMIT 1'); } catch (PDOException $e) { db()->exec('ALTER TABLE users ADD COLUMN notif_seen TEXT'); }
    }
    if ((int) setting('schema_version', '1') < 7) { // acesso de um usuário a mais de uma instituição
        try { db()->query('SELECT user_id FROM user_institutions LIMIT 1'); }
        catch (PDOException $e) {
            require_once __DIR__ . '/install_lib.php'; // tem a conversão do schema para MySQL/SQLite
            foreach (schema_statements(CONFIG['db']['driver'] ?? 'mysql') as $st) if (str_contains($st, 'CREATE TABLE user_institutions')) db()->exec($st);
        }
    }
    if ((int) setting('schema_version', '1') < 8) { // imagem (PNG/JPG) em comunicados e avisos
        try { db()->query('SELECT image_path FROM announcements LIMIT 1'); } catch (PDOException $e) { db()->exec('ALTER TABLE announcements ADD COLUMN image_path VARCHAR(255)'); }
    }
    if ((int) setting('schema_version', '1') < 9) { // Financeiro › Professores: valor da aula e pagamentos
        try { db()->query('SELECT professor_id FROM professor_rates LIMIT 1'); }
        catch (PDOException $e) {
            require_once __DIR__ . '/install_lib.php';
            foreach (schema_statements(CONFIG['db']['driver'] ?? 'mysql') as $st)
                if (preg_match('/CREATE (TABLE|INDEX) (professor_rates|professor_payments|professor_payment_lessons|prof_pay_inst)\b/', $st)) db()->exec($st);
        }
    }
    if ((int) setting('schema_version', '1') < 10) { // nova permissão Financeiro › Professores: quem já gerencia o financeiro recebe junto
        foreach (col("SELECT user_id FROM user_permissions WHERE perm = 'finance:manage' AND effect = 'GRANT' AND user_id NOT IN (SELECT user_id FROM user_permissions WHERE perm = 'payroll:manage')") as $uid)
            insert('user_permissions', ['user_id' => $uid, 'perm' => 'payroll:manage', 'effect' => 'GRANT']);
        foreach (col("SELECT role_id FROM role_permissions WHERE perm = 'finance:manage' AND role_id NOT IN (SELECT role_id FROM role_permissions WHERE perm = 'payroll:manage')") as $rid)
            insert('role_permissions', ['role_id' => $rid, 'perm' => 'payroll:manage']);
    }
    if ((int) setting('schema_version', '1') < 11) { // Diretoria vira módulo: resumo financeiro ganha permissão própria; "ver financeiro na ficha do aluno" fica no Acadêmico
        $acad = ['students:manage', 'professors:manage', 'courses:manage', 'terms:manage', 'classes:manage', 'transcripts:issue', 'records:edit-after-deadline', 'announcements:manage', 'board:manage'];
        foreach (col("SELECT DISTINCT user_id FROM user_permissions WHERE perm = 'finance:read' AND effect = 'GRANT'") as $uid) {
            $mine = col("SELECT perm FROM user_permissions WHERE user_id = ? AND effect = 'GRANT'", [$uid]);
            if (in_array('reports:institutional', $mine, true) && !in_array('reports:finance', $mine, true)) insert('user_permissions', ['user_id' => $uid, 'perm' => 'reports:finance', 'effect' => 'GRANT']);
            if (!array_intersect($acad, $mine)) q("DELETE FROM user_permissions WHERE user_id = ? AND perm = 'finance:read'", [$uid]);
        }
        foreach (col("SELECT DISTINCT role_id FROM role_permissions WHERE perm = 'reports:institutional'") as $rid)
            if (!val("SELECT 1 FROM role_permissions WHERE role_id = ? AND perm = 'reports:finance'", [$rid])) insert('role_permissions', ['role_id' => $rid, 'perm' => 'reports:finance']);
    }
    if ((int) setting('schema_version', '1') < 12) { // módulo Compras
        try { db()->query('SELECT id FROM purchase_requests LIMIT 1'); }
        catch (PDOException $e) {
            require_once __DIR__ . '/install_lib.php';
            foreach (schema_statements(CONFIG['db']['driver'] ?? 'mysql') as $st)
                if (preg_match('/CREATE (TABLE|INDEX) (purchase_requests|purchase_items|purchase_quotes|purchase_quote_prices|purchase_events|purchase_req_inst|purchase_events_req)\b/', $st)) db()->exec($st);
        }
    }
    // remetente dos e-mails com nome antigo da plataforma passa a usar o nome da marca instalada
    if (in_array(setting('mail_from_name'), ['Academus', 'Aquinus', 'Summa'], true) && setting('mail_from_name') !== APP_NAME) set_setting('mail_from_name', APP_NAME);
    set_setting('schema_version', (string) SCHEMA_VERSION);
}
/** Converte os perfis antigos (Secretaria, Diretoria, Financeiro, Personalizado) em permissões individuais.
 *  Ficam só: Acesso total (ADMIN) e os papéis ligados a cadastro (PROFESSOR, STUDENT). O acesso de cada um não muda. */
function users_to_individual_perms(): void {
    $all = array_keys(PERMISSIONS); $keep = ['ADMIN', 'PROFESSOR', 'STUDENT'];
    $rolePerms = [];
    foreach (rows('SELECT r.kind, rp.perm FROM role_permissions rp JOIN roles r ON r.id = rp.role_id') as $r) $rolePerms[$r['kind']][] = $r['perm'];
    $permsOf = fn(array $kinds) => in_array('ADMIN', $kinds, true) ? $all : array_values(array_unique(array_merge([], ...array_map(fn($k) => $rolePerms[$k] ?? [], $kinds))));
    $byUser = [];
    foreach (rows('SELECT ur.user_id, r.kind FROM user_roles ur JOIN roles r ON r.id = ur.role_id') as $r) $byUser[$r['user_id']][] = $r['kind'];
    tx(function () use ($byUser, $keep, $permsOf) {
        foreach ($byUser as $uid => $kinds) {
            $stay = array_values(array_intersect($kinds, $keep));
            if (count($stay) === count($kinds)) continue;
            $over = array_column(rows('SELECT perm, effect FROM user_permissions WHERE user_id = ?', [$uid]), 'effect', 'perm');
            $eff = effective_perms($permsOf($kinds), $over);
            $new = overrides_from_selection($permsOf($stay), $eff);
            q('DELETE FROM user_roles WHERE user_id = ? AND role_id IN (SELECT id FROM roles WHERE kind NOT IN (' . in_list($keep) . '))', array_merge([$uid], $keep));
            q('DELETE FROM user_permissions WHERE user_id = ?', [$uid]);
            foreach ($new as $code => $e) insert('user_permissions', ['user_id' => $uid, 'perm' => $code, 'effect' => $e]);
        }
    });
}
function set_setting(string $k, ?string $v): void {
    if (row('SELECT k FROM settings WHERE k = ?', [$k])) q('UPDATE settings SET v = ? WHERE k = ?', [$v, $k]);
    else insert('settings', ['k' => $k, 'v' => $v]);
}
