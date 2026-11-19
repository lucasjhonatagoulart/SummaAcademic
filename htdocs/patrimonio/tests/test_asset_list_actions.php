<?php

declare(strict_types=1);

// Lista de ativos: ações em ícones (abrir, editar, excluir), exclusão com motivo em uma janela
// e retorno à lista, nos mesmos filtros, depois de editar ou excluir.
$root = dirname(__DIR__);
$failures = 0; $total = 0;
$check = static function (bool $ok, string $label) use (&$failures, &$total): void {
    $total++;
    if ($ok) { echo "OK - $label\n"; return; }
    $failures++; fwrite(STDERR, "FALHOU: $label\n");
};
$list = (string)file_get_contents($root . '/templates/assets.php');
$detail = (string)file_get_contents($root . '/templates/asset-detail.php');
$actions = (string)file_get_contents($root . '/app/actions.php');
$render = (string)file_get_contents($root . '/app/render.php');
$ui = (string)file_get_contents($root . '/app/ui.php');
$js = (string)file_get_contents($root . '/assets/app.js');

$check(str_contains($list, "icon('eye')") && str_contains($list, "icon('pencil')") && str_contains($list, "icon('trash-2')")
    && !str_contains($list, '>Abrir</a>'), 'lista usa ícones para abrir, editar e excluir');
$check(substr_count($list, 'aria-label="') >= 3 && str_contains($list, 'title="Abrir"') && str_contains($list, 'title="Excluir"'), 'cada ícone tem nome acessível e dica');
foreach (['eye', 'pencil', 'trash-2'] as $name) $check(str_contains($ui, "'$name' => '<"), "ícone $name existe no conjunto embutido");
$check(str_contains($list, "\$canDelete = can('manage_users') && can('edit_assets')") && str_contains($list, '<?php if ($canDelete): ?><a class="button small icon-only danger"'), 'Excluir só aparece para quem pode excluir');
$check(str_contains($list, '<dialog class="dialog" data-delete-dialog') && str_contains($list, 'value="delete-asset"') && str_contains($list, 'name="reason" required')
    && str_contains($list, 'csrf_field()') && str_contains($list, 'data-delete-id'), 'janela de exclusão pede o motivo e envia com proteção CSRF');
$check(str_contains($list, "#excluir") && str_contains($js, "typeof dialog.showModal !== 'function'"), 'sem suporte a janela, o link leva ao formulário na ficha do ativo');
$check(str_contains($js, 'nameLabel.textContent') && !str_contains($js, 'nameLabel.innerHTML'), 'nome do ativo entra na janela como texto, nunca como HTML');

$posBaixa = strpos($detail, 'Registrar baixa'); $posExcluir = strpos($detail, '<h2>Excluir ativo</h2>'); $posDados = strpos($detail, 'Dados do ativo');
$check($posDados !== false && $posExcluir !== false && $posExcluir > $posDados && ($posBaixa === false || $posExcluir > $posBaixa) && str_contains($detail, 'id="excluir"'), 'na ficha, a exclusão fica no fim da página, depois dos dados e da baixa');

preg_match('/function save_asset_action\(\): never\s*\{(.*?)\n\}/s', $actions, $save);
$check(str_contains($save[1] ?? '', "redirect_to('assets', assets_return_query())") && !str_contains($save[1] ?? '', "redirect_to('asset', ['id' => \$id])"), 'depois de salvar a edição, volta para a lista de ativos');
preg_match('/function delete_asset_action\(\): never\s*\{(.*?)\n\}/s', $actions, $delete);
$check(str_contains($delete[1] ?? '', "redirect_to('assets', assets_return_query())"), 'depois de excluir, volta para a lista de ativos');
$check(str_contains($render, "\$_SESSION['assets_list_query']"), 'a lista guarda filtros e página para o retorno');

// A função de retorno só devolve valores simples e não vazios
preg_match('/function assets_return_query\(\): array\s*\{.*?\n\}/s', $actions, $fn);
eval($fn[0] ?? 'function assets_return_query(): array { return ["erro" => 1]; }');
$_SESSION = [];
$check(assets_return_query() === [], 'sem lista visitada, volta para a lista sem filtros');
$_SESSION['assets_list_query'] = ['q' => 'hemi', 'status' => 'all', 'p' => 3, 'category' => '', 'x' => ['a']];
$check(assets_return_query() === ['q' => 'hemi', 'status' => 'all', 'p' => 3], 'retorno leva busca, situação e página; ignora vazios e valores que não são texto');
$_SESSION['assets_list_query'] = 'texto';
$check(assets_return_query() === [], 'valor inesperado na sessão é ignorado');

echo "Total: $total verificações das ações da lista de ativos, $failures falha(s).\n";
exit($failures ? 1 : 0);
