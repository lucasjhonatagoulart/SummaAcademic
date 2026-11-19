<?php
// Utilidades de saída, formulários e formatação.

function h($s): string { return htmlspecialchars((string) ($s ?? ''), ENT_QUOTES, 'UTF-8'); }
function url(string $r, array $p = []): string { return 'index.php?' . http_build_query(['r' => $r] + $p); }
function go(string $r, array $p = []): never { header('Location: ' . url($r, $p)); exit; }
function back_to(string $fallbackRoute, array $p = []): never {
    $ref = $_POST['_back'] ?? '';
    if ($ref && str_starts_with($ref, 'index.php?')) { header('Location: ' . $ref); exit; }
    go($fallbackRoute, $p);
}
function is_post(): bool { return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'; }
function p(string $k, $d = '') { $v = $_POST[$k] ?? $d; return is_string($v) ? trim($v) : $v; }
function g(string $k, $d = '') { $v = $_GET[$k] ?? $d; return is_string($v) ? trim($v) : $v; }
function gi(string $k): int { return (int) ($_GET[$k] ?? 0); }
function pi_(string $k): int { return (int) ($_POST[$k] ?? 0); }

function today(): string { return date('Y-m-d'); }
function now(): string { return date('Y-m-d H:i:s'); }
function fmt_date(?string $d): string { return $d ? date('d/m/Y', strtotime(substr($d, 0, 10))) : '—'; }
/** "Terça-feira, 10 de novembro" (sem depender da extensão intl) */
function date_long(string $d): string {
    $t = strtotime($d);
    $wd = ['Domingo', 'Segunda-feira', 'Terça-feira', 'Quarta-feira', 'Quinta-feira', 'Sexta-feira', 'Sábado'][(int) date('w', $t)];
    $m = ['janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'][(int) date('n', $t) - 1];
    return $wd . ', ' . date('j', $t) . ' de ' . $m;
}
function fmt_dt(?string $d): string { return $d ? date('d/m/Y H:i', strtotime($d)) : '—'; }
function money(?int $cents): string { return 'R$ ' . number_format(($cents ?? 0) / 100, 2, ',', '.'); }
function num($n, int $dec = 1): string { return $n === null ? '—' : number_format((float) $n, $dec, ',', '.'); }
function plural(int $n, string $one, string $many): string { return $n . ' ' . ($n === 1 ? $one : $many); }
function initials(string $name): string {
    $p = preg_split('/\s+/', preg_replace('/^(Prof\.?|Profa\.?|Dra?\.?)\s+/i', '', trim($name)));
    return mb_strtoupper(mb_substr($p[0] ?? '', 0, 1) . (count($p) > 1 ? mb_substr(end($p), 0, 1) : ''));
}
function first_name(string $name): string { return explode(' ', preg_replace('/^(Prof\.?|Profa\.?|Dra?\.?)\s+/i', '', trim($name)))[0]; }

// ---------- Mensagens ----------
function flash(string $tone, string $msg): void { $_SESSION['flash'][] = [$tone, $msg]; }
function flashes(): string {
    $f = $_SESSION['flash'] ?? []; unset($_SESSION['flash']);
    return implode('', array_map(fn($x) => alert($x[0], $x[1]), $f));
}

// ---------- CSRF ----------
function csrf_token(): string { return $_SESSION['csrf'] ??= bin2hex(random_bytes(32)); }
function csrf(): string { return '<input type="hidden" name="_csrf" value="' . h(csrf_token()) . '">'; }
function csrf_ok(): bool { return is_string($_POST['_csrf'] ?? null) && hash_equals(csrf_token(), $_POST['_csrf']); }
/** Formulário POST com CSRF; $route recebe o envio */
function form_open(string $route, array $params = [], string $attrs = ''): string {
    return '<form method="post" action="' . h(url($route, $params)) . '" ' . $attrs . '>' . csrf();
}

// ---------- Componentes ----------
function icon(string $name, string $extra = ''): string { return '<i data-lucide="' . h($name) . '" ' . $extra . '></i>'; }
function badge(string $text, string $tone = 'b-neutral'): string { return '<span class="badge ' . $tone . '">' . $text . '</span>'; }
function alert(string $tone, string $msg, string $title = ''): string {
    $cls = ['ok' => 'a-ok', 'success' => 'a-ok', 'bad' => 'a-bad', 'danger' => 'a-bad', 'warn' => 'a-warn', 'info' => 'a-info'][$tone] ?? 'a-info';
    $ic = ['a-ok' => 'circle-check', 'a-bad' => 'circle-alert', 'a-warn' => 'triangle-alert', 'a-info' => 'info'][$cls];
    return '<div class="alert ' . $cls . '" role="' . ($cls === 'a-bad' ? 'alert' : 'status') . '"><span class="ic">' . icon($ic) . '</span><div>' . ($title ? '<strong>' . $title . '</strong>' : '') . $msg . '</div></div>';
}
function page_head(string $title, string $desc = '', string $actions = ''): string {
    return '<div class="page-head"><div><h1>' . $title . '</h1>' . ($desc ? '<p>' . $desc . '</p>' : '') . '</div>' . ($actions ? '<div class="row">' . $actions . '</div>' : '') . '</div>';
}
function crumb(string $route, string $label, array $p = []): string { return '<a class="crumb" href="' . h(url($route, $p)) . '">' . icon('arrow-left') . ' ' . h($label) . '</a>'; }
function empty_state(string $ic, string $title, string $desc = '', string $action = ''): string {
    return '<div class="empty"><span class="ring">' . icon($ic) . '</span><strong>' . $title . '</strong>' . ($desc ? '<p class="small">' . $desc . '</p>' : '') . $action . '</div>';
}
function stat_card(string $label, string $value, string $ic, string $tone = '', string $hint = ''): string {
    return '<div class="card stat"><span class="stat-ic ' . $tone . '">' . icon($ic) . '</span><div><p class="label">' . $label . '</p><p class="value">' . $value . '</p>' . ($hint ? '<p class="hint">' . $hint . '</p>' : '') . '</div></div>';
}
function pct_bar(?float $pct, float $min = 75): string {
    if ($pct === null) return '<span class="muted">—</span>';
    $tone = $pct < $min ? 'bad' : ($pct < $min + 10 ? 'warn' : 'ok');
    return '<div class="row" style="gap:10px;flex-wrap:nowrap"><div class="bar ' . $tone . '" style="width:80px"><span style="width:' . min(100, $pct) . '%"></span></div><span class="tnum">' . num($pct) . '%</span></div>';
}
/** Abas em pílulas: [[chave, rótulo, ícone, url]] */
function tabs(array $items, string $active, string $label = 'Seções'): string {
    $o = '<nav class="tabs section-tabs" aria-label="' . h($label) . '">';
    foreach ($items as [$k, $l, $ic, $href]) $o .= '<a class="tab ' . ($k === $active ? 'active' : '') . '" href="' . h($href) . '"' . ($k === $active ? ' aria-current="page"' : '') . '>' . icon($ic) . h($l) . '</a>';
    return $o . '</nav>';
}
function avatar(?string $path, string $name, string $style = ''): string {
    if ($path) return '<span class="avatar" style="' . $style . '"><img src="' . h($path) . '" alt="" style="width:100%;height:100%;object-fit:cover;border-radius:50%"></span>';
    return '<span class="avatar" style="' . $style . '">' . h(initials($name)) . '</span>';
}
function field(string $label, string $control, string $hint = '', string $cls = ''): string {
    return '<div class="field ' . $cls . '">' . $label . $control . ($hint ? '<small>' . $hint . '</small>' : '') . '</div>';
}
function input(string $name, string $label, $value = '', string $type = 'text', string $attrs = '', string $hint = '', string $cls = ''): string {
    return field('<label for="f-' . h($name) . '">' . $label . '</label>', '<input class="input" id="f-' . h($name) . '" name="' . h($name) . '" type="' . $type . '" value="' . h($value) . '" ' . $attrs . '>', $hint, $cls);
}
function textarea(string $name, string $label, $value = '', string $attrs = '', string $hint = '', string $cls = ''): string {
    return field('<label for="f-' . h($name) . '">' . $label . '</label>', '<textarea class="input" id="f-' . h($name) . '" name="' . h($name) . '" ' . $attrs . '>' . h($value) . '</textarea>', $hint, $cls);
}
/** $options: [valor => rótulo] */
function select(string $name, string $label, array $options, $value = '', string $attrs = '', string $hint = '', string $cls = '', string $placeholder = ''): string {
    $o = $placeholder !== '' ? '<option value="">' . h($placeholder) . '</option>' : '';
    foreach ($options as $v => $l) $o .= '<option value="' . h($v) . '"' . ((string) $v === (string) $value ? ' selected' : '') . '>' . h($l) . '</option>';
    return field('<label for="f-' . h($name) . '">' . $label . '</label>', '<select class="input" id="f-' . h($name) . '" name="' . h($name) . '" ' . $attrs . '>' . $o . '</select>', $hint, $cls);
}
function checkbox(string $name, string $label, bool $checked, string $desc = '', string $value = '1'): string {
    return '<label class="check-field"><input type="checkbox" name="' . h($name) . '" value="' . h($value) . '"' . ($checked ? ' checked' : '') . '><span><strong>' . $label . '</strong>' . ($desc ? '<span class="muted small">' . $desc . '</span>' : '') . '</span></label>';
}
function result_line(int $shown, int $total, string $noun): string {
    return '<p class="small muted" style="padding:12px 20px;border-top:1px solid var(--line)">' . ($shown === $total ? "$total $noun." : "$shown de $total $noun.") . '</p>';
}

// ---------- Auditoria ----------
function audit(string $action, string $summary, ?string $entity = null, ?int $entityId = null, ?string $reason = null): void {
    $u = current_user();
    insert('audit_logs', [
        'institution_id' => inst_id(), 'actor_id' => $u['id'] ?? null, 'action' => $action, 'entity' => $entity, 'entity_id' => $entityId,
        'summary' => mb_substr($summary, 0, 500), 'reason' => $reason ? mb_substr($reason, 0, 255) : null,
        'ip' => substr($_SERVER['REMOTE_ADDR'] ?? '', 0, 45), 'created_at' => now(),
    ]);
}

function abort(int $code, string $msg = ''): never {
    http_response_code($code);
    $title = $code === 404 ? 'Página não encontrada' : ($code === 403 ? 'Acesso negado' : 'Erro');
    $body = '<div class="card" style="max-width:560px;margin:40px auto">' . empty_state($code === 403 ? 'lock' : 'circle-alert', $title, h($msg ?: ($code === 403 ? 'Seu perfil não tem permissão para esta tela.' : 'Confira o endereço.')), '<a class="btn btn-secondary" href="index.php">Voltar ao início</a>') . '</div>';
    if (current_user()) layout($title, $body); else bare_page($title, $body);
    exit;
}

// ---------- Envio de imagens (foto de perfil e logo) ----------
function ini_bytes(string $v): int { $v = trim($v); $n = (int) $v; return match (strtolower(substr($v, -1))) { 'g' => $n << 30, 'm' => $n << 20, 'k' => $n << 10, default => $n }; }
// Limite do arquivo que chega ao servidor: o menor entre 10 MB e o que o PHP da hospedagem aceita.
function upload_limit(): int {
    $l = 10 << 20;
    foreach (['upload_max_filesize', 'post_max_size'] as $k) { $b = ini_bytes((string) ini_get($k)); if ($b > 0) $l = min($l, $b); }
    return $l;
}
function mb_label(int $b): string { return num($b / 1048576, $b % 1048576 ? 1 : 0) . ' MB'; }
/** Valida e grava a imagem. $mode 'avatar' = quadrada 512px JPEG; 'logo' = até 1200px mantendo transparência; 'banner' = até 1600px (comunicados e avisos).
 *  Regrava pelo GD quando disponível (remove metadados como localização do celular). Retorna [caminho, erro]. */
function save_image(?array $f, string $prefix, string $mode): array {
    if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return [null, 'Escolha uma imagem.'];
    if (in_array($f['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) || $f['size'] > upload_limit()) return [null, 'A imagem passa de ' . mb_label(upload_limit()) . '. Escolha uma menor.'];
    if ($f['error'] !== UPLOAD_ERR_OK) return [null, 'O envio falhou. Tente de novo.'];
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
    $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$mime] ?? null;
    $info = $ext ? @getimagesize($f['tmp_name']) : false;
    if (!$ext || !$info) return [null, 'Use uma imagem JPG, PNG ou WebP.'];
    $dir = dirname(__DIR__) . '/uploads/';
    $base = $prefix . '-' . bin2hex(random_bytes(8));
    $src = null;
    if (function_exists('imagecreatefromstring') && $info[0] * $info[1] <= 40_000_000) $src = @imagecreatefromstring((string) file_get_contents($f['tmp_name']));
    if (!$src) { // sem GD: grava o arquivo como veio
        $name = "uploads/$base.$ext";
        $ok = is_uploaded_file($f['tmp_name']) ? move_uploaded_file($f['tmp_name'], $dir . basename($name)) : rename($f['tmp_name'], $dir . basename($name));
        return $ok ? [$name, null] : [null, 'Não foi possível salvar a imagem.'];
    }
    [$w, $h] = [imagesx($src), imagesy($src)];
    if ($mode === 'avatar') {
        $side = min($w, $h); $out = imagecreatetruecolor(512, 512);
        imagefill($out, 0, 0, imagecolorallocate($out, 255, 255, 255));
        imagecopyresampled($out, $src, 0, 0, intdiv($w - $side, 2), intdiv($h - $side, 2), 512, 512, $side, $side);
        $name = "uploads/$base.jpg"; $ok = imagejpeg($out, $dir . basename($name), 88);
    } else {
        $k = min(1, ($mode === 'banner' ? 1600 : 1200) / max($w, $h)); $nw = max(1, (int) round($w * $k)); $nh = max(1, (int) round($h * $k));
        $out = imagecreatetruecolor($nw, $nh); imagealphablending($out, false); imagesavealpha($out, true);
        imagefill($out, 0, 0, imagecolorallocatealpha($out, 0, 0, 0, 127));
        imagecopyresampled($out, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
        if ($ext === 'jpg') { $name = "uploads/$base.jpg"; $ok = imagejpeg($out, $dir . basename($name), 90); }
        else { $name = "uploads/$base.png"; $ok = imagepng($out, $dir . basename($name), 6); }
    }
    return $ok ? [$name, null] : [null, 'Não foi possível salvar a imagem.'];
}
function remove_upload(?string $path): void { if ($path && str_starts_with($path, 'uploads/') && !str_contains($path, '..')) @unlink(dirname(__DIR__) . '/' . $path); }
/** Foto recortada no navegador que chega como texto (data:image/jpeg;base64,...) quando o navegador não aceita trocar o arquivo do campo. */
function data_url_upload(string $data): ?array {
    if (!preg_match('#^data:image/(jpeg|png|webp);base64,([A-Za-z0-9+/=]+)$#', $data, $m)) return null;
    $bin = base64_decode($m[2], true);
    if ($bin === false) return null;
    $tmp = tempnam(sys_get_temp_dir(), 'acd');
    file_put_contents($tmp, $bin);
    return ['name' => 'foto.' . $m[1], 'tmp_name' => $tmp, 'size' => strlen($bin), 'error' => UPLOAD_ERR_OK];
}
