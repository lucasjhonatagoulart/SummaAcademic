<?php
// Módulos por integração: sistemas à parte, em uma pasta desta hospedagem, incluídos pelo Painel de controle.
// Cada módulo traz um manifesto (summa-module.json) com nome, ícone, cor e as permissões que ele oferece.
// O cadastro fica em settings.ext_modules (JSON), então não exige alteração no banco.

const EXT_MANIFEST = 'summa-module.json';
const EXT_SECRET_FILE = 'summa.secret.php';
/** Pastas do próprio Summa: não podem ser módulos */
const EXT_RESERVED_FOLDERS = ['app', 'assets', 'uploads', 'install', 'tests'];
/** Nomes já usados por módulos e rotas do Summa */
const EXT_RESERVED_SLUGS = ['admin', 'summa', 'modulo', 'workspace', 'login'];
/** Cores do card: [faixa, ícone e texto, fundo suave] */
const EXT_PALETTE = [
    'azul' => ['#2878D0', '#1F68B8', '#E8F1FB'],
    'verde' => ['#1F845A', '#17694A', '#E6F4EE'],
    'ambar' => ['#F0B44D', '#7A5310', '#FDF4E3'],
    'violeta' => ['#6E56CF', '#5B45B0', '#EEEBFA'],
    'teal' => ['#12808A', '#0E6971', '#E3F3F4'],
    'rosa' => ['#C2417A', '#9C2F60', '#FBE9F1'],
    'cinza' => ['#5E6C84', '#44546F', '#EEF1F5'],
];

/** Módulos cadastrados, por slug. $onlyActive = só os ligados. */
function ext_modules(bool $onlyActive = true): array {
    $all = $GLOBALS['EXT_MODULES_CACHE'] ?? null;
    if ($all === null) {
        $all = json_decode((string) setting('ext_modules', '[]'), true);
        $GLOBALS['EXT_MODULES_CACHE'] = $all = is_array($all) ? $all : [];
    }
    return $onlyActive ? array_filter($all, fn($m) => !empty($m['active'])) : $all;
}
function ext_modules_save(array $all): void {
    ksort($all);
    set_setting('ext_modules', json_encode($all, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $GLOBALS['EXT_MODULES_CACHE'] = $all;
}
/** Área de permissão do módulo (chave em module_labels) e código completo de uma permissão dele */
function ext_area(string $slug): string { return 'x_' . $slug; }
function ext_perm(string $slug, string $code): string { return 'x_' . $slug . ':' . $code; }

/** Permissões dos módulos ligados, no formato de PERMISSIONS */
function ext_perm_catalog(): array {
    $out = [];
    foreach (ext_modules() as $slug => $m) foreach ($m['perms'] as $p) $out[ext_perm($slug, $p['code'])] = [ext_area($slug), $p['label']];
    return $out;
}
/** Módulos ligados, no formato de PLATFORM_MODULES */
function ext_platform_modules(): array {
    $out = [];
    foreach (ext_modules() as $slug => $m) {
        [$stripe, $ink, $soft] = EXT_PALETTE[$m['color']] ?? EXT_PALETTE['cinza'];
        $labels = [];
        foreach ($m['perms'] as $p) $labels[ext_perm($slug, $p['code'])] = $p['short'];
        $out[$slug] = ['name' => $m['name'], 'icon' => $m['icon'], 'stripe' => $stripe, 'ink' => $ink, 'soft' => $soft, 'desc' => $m['desc'],
            'areas' => [ext_area($slug)], 'portals' => [], 'entry' => 'modulo.' . $slug, 'labels' => $labels, 'ext' => true];
    }
    return $out;
}

/** Pasta do site (htdocs) e caminho de um arquivo dentro da pasta de um módulo */
function ext_site_root(): string { return dirname(__DIR__); }
function ext_folder_ok(string $folder): bool {
    return (bool) preg_match('/^[a-z0-9][a-z0-9_-]{0,39}$/', $folder) && !in_array($folder, EXT_RESERVED_FOLDERS, true) && is_dir(ext_site_root() . '/' . $folder);
}

/**
 * Lê e confere o manifesto de uma pasta. Devolve [módulo, ''] ou [null, mensagem de erro].
 * Só aceita o que a tela sabe mostrar com segurança: textos curtos, ícone e códigos em formato fixo.
 */
function ext_read_manifest(string $folder): array {
    if (!preg_match('/^[a-z0-9][a-z0-9_-]{0,39}$/', $folder)) return [null, 'Informe só o nome da pasta, em minúsculas, sem barras (ex.: estoque).'];
    if (in_array($folder, EXT_RESERVED_FOLDERS, true)) return [null, "A pasta \"$folder\" é do próprio Summa e não pode ser um módulo."];
    if (!is_dir(ext_site_root() . '/' . $folder)) return [null, "A pasta \"$folder\" não existe no site. Envie a pasta do módulo para o servidor antes de incluir."];
    $file = ext_site_root() . '/' . $folder . '/' . EXT_MANIFEST;
    if (!is_file($file)) return [null, 'Não encontrei o arquivo ' . EXT_MANIFEST . " na pasta \"$folder\"."];
    if (filesize($file) > 65536) return [null, 'O arquivo ' . EXT_MANIFEST . ' é grande demais (limite de 64 KB).'];
    $j = json_decode((string) file_get_contents($file), true);
    if (!is_array($j)) return [null, 'O arquivo ' . EXT_MANIFEST . ' não é um JSON válido.'];
    return ext_validate_manifest($j, $folder);
}
function ext_validate_manifest(array $j, string $folder): array {
    $txt = fn($v) => is_string($v) ? trim(preg_replace('/\s+/u', ' ', $v)) : '';
    $slug = $txt($j['slug'] ?? '');
    if (!preg_match('/^[a-z][a-z_]{1,23}$/', $slug)) return [null, 'Manifesto: "slug" deve ter de 2 a 24 letras minúsculas (pode usar _), sem números nem hífen.'];
    // Módulos que já existem no Summa ficam reservados; um que está só anunciado como "em breve" pode ser assumido pela integração
    if (in_array($slug, EXT_RESERVED_SLUGS, true) || (isset(PLATFORM_MODULES[$slug]) && empty(PLATFORM_MODULES[$slug]['soon']))) return [null, "Manifesto: o slug \"$slug\" já é usado pelo Summa. Escolha outro."];
    $name = $txt($j['name'] ?? '');
    if (mb_strlen($name) < 2 || mb_strlen($name) > 40) return [null, 'Manifesto: "name" deve ter de 2 a 40 caracteres.'];
    $desc = $txt($j['description'] ?? '');
    if (mb_strlen($desc) > 200) return [null, 'Manifesto: "description" pode ter até 200 caracteres.'];
    $icon = $txt($j['icon'] ?? '') ?: 'box';
    if (!preg_match('/^[a-z0-9][a-z0-9-]{0,39}$/', $icon)) return [null, 'Manifesto: "icon" deve ser o nome de um ícone Lucide (ex.: boxes).'];
    $color = $txt($j['color'] ?? '') ?: 'cinza';
    if (!isset(EXT_PALETTE[$color])) return [null, 'Manifesto: "color" deve ser uma destas: ' . implode(', ', array_keys(EXT_PALETTE)) . '.'];
    $entry = $txt($j['entry'] ?? '') ?: 'index.php';
    if (!preg_match('/^[A-Za-z0-9_][A-Za-z0-9_.\/-]{0,79}$/', $entry) || str_contains($entry, '..') || str_contains($entry, '//')) return [null, 'Manifesto: "entry" deve ser um arquivo dentro da pasta do módulo (ex.: index.php).'];
    $list = $j['permissions'] ?? null;
    if (!is_array($list) || !$list || count($list) > 30) return [null, 'Manifesto: "permissions" deve listar de 1 a 30 permissões.'];
    $perms = []; $seen = [];
    foreach ($list as $p) {
        $code = is_array($p) ? $txt($p['code'] ?? '') : '';
        if (!preg_match('/^[a-z][a-z0-9_-]{0,30}$/', $code)) return [null, 'Manifesto: cada permissão precisa de um "code" em minúsculas, começando por letra (ex.: access).'];
        if (isset($seen[$code])) return [null, "Manifesto: a permissão \"$code\" aparece duas vezes."];
        $seen[$code] = true;
        $label = $txt($p['label'] ?? '');
        if (mb_strlen($label) < 3 || mb_strlen($label) > 120) return [null, "Manifesto: a permissão \"$code\" precisa de um \"label\" de 3 a 120 caracteres."];
        $short = $txt($p['short'] ?? '') ?: $label;
        $perms[] = ['code' => $code, 'label' => $label, 'short' => mb_substr($short, 0, 30)];
    }
    return [['slug' => $slug, 'folder' => $folder, 'name' => $name, 'desc' => $desc, 'icon' => $icon, 'color' => $color, 'entry' => $entry, 'perms' => $perms], ''];
}

/** Grava o segredo na pasta do módulo (arquivo que só devolve um array). Devolve true se gravou. */
function ext_write_secret(array $m): bool {
    if (!ext_folder_ok($m['folder']) || !preg_match('/^[a-f0-9]{64}$/', $m['secret'])) return false;
    $php = "<?php\n// Gerado pelo Summa ao incluir o módulo. Não envie este arquivo a ninguém.\n// 'secret' confere o acesso que vem do Summa; 'url' é o endereço do Summa visto desta pasta.\nreturn [\n    'slug' => '" . $m['slug'] . "',\n    'secret' => '" . $m['secret'] . "',\n    'url' => '../',\n];\n";
    return @file_put_contents(ext_site_root() . '/' . $m['folder'] . '/' . EXT_SECRET_FILE, $php, LOCK_EX) !== false;
}
function ext_secret_in_place(array $m): bool {
    $file = ext_site_root() . '/' . $m['folder'] . '/' . EXT_SECRET_FILE;
    return is_file($file) && str_contains((string) @file_get_contents($file), "'" . $m['secret'] . "'");
}
/** Padrão de LIKE para as permissões do módulo (o _ do nome não pode valer como curinga) */
function ext_like(string $slug): string { return str_replace('_', '!_', ext_area($slug)) . ':%'; }
/** Apaga as permissões gravadas de um módulo (todas, ou só os códigos informados) */
function ext_drop_perms(string $slug, ?array $codes = null): void {
    foreach (['user_permissions', 'role_permissions'] as $t) {
        if ($codes === null) q("DELETE FROM $t WHERE perm LIKE ? ESCAPE '!'", [ext_like($slug)]);
        else foreach ($codes as $c) q("DELETE FROM $t WHERE perm = ?", [ext_perm($slug, $c)]);
    }
}
/** Quantos usuários têm alguma permissão liberada no módulo (fora quem tem Acesso total) */
function ext_user_count(string $slug): int {
    return (int) val("SELECT COUNT(DISTINCT user_id) FROM user_permissions WHERE effect = 'GRANT' AND perm LIKE ? ESCAPE '!'", [ext_like($slug)]);
}
