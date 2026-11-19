<?php

declare(strict_types=1);

$GLOBALS['test_permissions'] = ['export_data'];
$GLOBALS['checks'] = 0;
function summa_manages_users(): bool { return false; }
function can(string $permission): bool
{
    return in_array($permission, $GLOBALS['test_permissions'], true);
}
function can_restore_backup(): bool
{
    foreach (['restore_backup','edit_assets','edit_invoices','manage_catalogs'] as $permission) if (!can($permission)) return false;
    return true;
}
function page_url(string $page, array $extra = []): string
{
    return 'index.php?' . http_build_query(['page' => $page] + $extra);
}
function h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function render_template(string $template, array $data = []): string
{
    return '<div data-template="' . htmlspecialchars($template, ENT_QUOTES, 'UTF-8') . '"></div>';
}
function verify(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException('Falhou: ' . $message);
    $GLOBALS['checks']++;
    echo "OK - {$message}\n";
}
function render_control_panel(string $section = ''): string
{
    $controlSection = $section;
    $catalogs = ['category' => [], 'location' => []];
    $admins = [];
    ob_start();
    include __DIR__ . '/../templates/control-panel.php';
    return (string)ob_get_clean();
}

$exportOverview = render_control_panel();
verify(str_contains($exportOverview, 'href="index.php?page=control-panel&amp;section=backup"'), 'card de backup navega para sua subview própria');
verify(str_contains($exportOverview, 'Backup completo') && !str_contains($exportOverview, 'download=backup'), 'a tela de cards não mistura o detalhe nem o download do backup');
verify(substr_count($exportOverview, 'class="control-access-card"') === 1, 'conta somente de exportação vê apenas o card autorizado');
verify(!str_contains($exportOverview, 'section=catalogs') && !str_contains($exportOverview, 'section=users'), 'cards de gestão não aparecem para a conta de exportação');
$exportDetail = render_control_panel('backup');
verify(str_contains($exportDetail, 'download=backup') && str_contains($exportDetail, '>← Voltar</a>') && !str_contains($exportDetail, 'Voltar aos cards'), 'detalhe de backup preserva a ação autorizada e mostra apenas “Voltar”');
verify(!str_contains($exportDetail, 'control-panel-access-grid') && !str_contains($exportDetail, 'data-template='), 'subview de backup não mostra cards ou outros detalhes');
verify(str_contains($exportDetail, 'Inclui os dados patrimoniais, fiscais e os padrões cadastrados.') && !str_contains($exportDetail, 'Exportar backup completo</h3>'), 'backup mantém apenas a descrição interna sem repetir seu título');
verify(!str_contains($exportDetail, 'Importar backup'), 'importação de backup não fica exposta sem a permissão composta');
$GLOBALS['test_permissions'] = ['export_data','restore_backup','edit_assets','edit_invoices','manage_catalogs'];
$restoreDetail = render_control_panel('backup');
verify(str_contains($restoreDetail, 'Importar backup') && str_contains($restoreDetail, 'page=restore'), 'importação de backup permanece acessível dentro do card autorizado do Painel');

$GLOBALS['test_permissions'] = ['manage_catalogs', 'manage_users'];
$managementOverview = render_control_panel();
verify(substr_count($managementOverview, 'class="control-access-card"') === 2, 'visão inicial mostra somente dois cards autorizados de gestão');
verify(str_contains($managementOverview, 'section=catalogs') && str_contains($managementOverview, 'section=users'), 'cards administrativos navegam às respectivas views');
verify(!str_contains($managementOverview, 'data-template=') && !str_contains($managementOverview, 'Criar usuário'), 'a visão inicial não apresenta os formulários dos blocos');
$catalogDetail = render_control_panel('catalogs');
verify(str_contains($catalogDetail, 'data-template="catalogs.php"') && !str_contains($catalogDetail, 'data-template="admins.php"'), 'view de categorias exibe apenas o detalhe de catálogos');
verify(str_contains($catalogDetail, '>← Voltar</a>') && !str_contains($catalogDetail, 'Voltar aos cards'), 'view de categorias rotula o retorno somente como “Voltar”');
$usersDetail = render_control_panel('users');
verify(str_contains($usersDetail, 'data-template="admins.php"') && !str_contains($usersDetail, 'data-template="catalogs.php"'), 'view de usuários exibe apenas as opções de usuários');
verify(str_contains($usersDetail, '>← Voltar</a>') && !str_contains($usersDetail, 'Voltar aos cards'), 'view de usuários rotula o retorno somente como “Voltar”');

$catalogTemplate = (string)file_get_contents(__DIR__ . '/../templates/catalogs.php');
$adminTemplate = (string)file_get_contents(__DIR__ . '/../templates/admins.php');
verify(str_contains($catalogTemplate, 'class="control-module-description"') && !str_contains($catalogTemplate, 'control-module-heading') && !str_contains($catalogTemplate, '<h2>Contas Patrimoniais e locais</h2>'), 'catálogos preserva a descrição e remove o kicker e o título duplicados');
verify(str_contains($adminTemplate, 'class="control-module-description"') && !str_contains($adminTemplate, 'control-module-heading') && !str_contains($adminTemplate, '<h2>Usuários e permissões</h2>'), 'usuários preserva a descrição e remove o kicker e o título duplicados');
verify(str_contains($catalogTemplate, 'catalog-list-scroll') && str_contains($catalogTemplate, 'role="region"') && str_contains($catalogTemplate, 'tabindex="0"'), 'listas de categorias e locais são regiões de rolagem acessíveis');
verify(str_contains($catalogTemplate, 'data-catalog-edit-open') && str_contains($catalogTemplate, '>Editar</button>') && str_contains($catalogTemplate, 'data-catalog-edit-form') && str_contains($catalogTemplate, 'name="new_value"') && str_contains($catalogTemplate, 'value="catalog-edit"') && str_contains($catalogTemplate, 'hidden>') && str_contains($catalogTemplate, '>Salvar</button>') && str_contains($catalogTemplate, '>Cancelar</button>'), 'cada padrão mostra Editar e revela o campo oculto com Salvar/Cancelar');
verify(str_contains($catalogTemplate, 'value="catalog-delete"') && str_contains($catalogTemplate, '>Remover</button>'), 'a edição mantém a ação de remoção existente');

$panelCss = (string)file_get_contents(__DIR__ . '/../assets/permissions.css');
verify(str_contains($panelCss, 'control-panel-access-grid') && str_contains($panelCss, 'repeat(auto-fit'), 'cards mantêm uma grade responsiva');
verify(str_contains($panelCss, 'focus-visible') && str_contains($panelCss, 'var(--blue)') && !str_contains($panelCss, '#003566'), 'cards mantêm foco acessível e usam as cores do padrão visual (tokens)');
verify(str_contains($panelCss, '.control-section-heading') && str_contains($panelCss, 'flex-direction:column'), 'cabeçalho da subview e retorno empilham em telas compactas');
verify(str_contains($panelCss, 'max-height:min(25.5rem,60vh)') && str_contains($panelCss, 'overflow-y:auto') && str_contains($panelCss, 'position:sticky'), 'listas limitam a altura a cerca de sete linhas e mantêm o cabeçalho visível');
verify(str_contains($panelCss, '.catalog-add-form .field{flex-basis:100%') && str_contains($panelCss, '.control-backup-actions{grid-template-columns:minmax(0,1fr)'), 'formulários e ações do backup se ajustam a telas estreitas');
verify(str_contains($panelCss, '.catalog-edit-form') && str_contains($panelCss, '.catalog-edit-input') && str_contains($panelCss, '@media(max-width:460px)'), 'o campo de edição dos padrões permanece responsivo');
verify(str_contains($panelCss, '.catalog-edit-form[hidden],.catalog-edit-display[hidden]{display:none!important}') && str_contains($panelCss, '.catalog-edit-display{display:flex'), 'CSS respeita hidden mesmo com os componentes flexíveis');

$renderer = (string)file_get_contents(__DIR__ . '/../app/render.php');
$actions = (string)file_get_contents(__DIR__ . '/../app/actions.php');
$layout = (string)file_get_contents(__DIR__ . '/../templates/layout.php');
$catalogJs = (string)file_get_contents(__DIR__ . '/../assets/app.js');
$index = (string)file_get_contents(__DIR__ . '/../index.php');
verify(str_contains($renderer, "'backup' => 'export_data'") && str_contains($renderer, "'catalogs' => 'manage_catalogs'") && str_contains($renderer, "'users' => 'manage_users'"), 'roteador associa cada subview à permissão correta');
verify(str_contains($renderer, 'require_permission($sectionPermissions[$controlSection])'), 'acesso à subview é verificado no servidor');
verify(str_contains($renderer, '$overview || $controlSection === \'catalogs\''), 'dados de catálogo só carregam no menu ou na seção de catálogos');
verify(str_contains($renderer, '$overview || $controlSection === \'users\''), 'dados de usuários só carregam no menu ou na seção de usuários');
verify(str_contains($catalogJs, "closest('[data-catalog-edit-open]')") && str_contains($catalogJs, "closest('[data-catalog-edit-cancel]')") && str_contains($catalogJs, "setAttribute('aria-expanded', 'true')") && str_contains($catalogJs, 'form.dataset.originalValue'), 'JavaScript abre o campo, anuncia o estado e restaura o valor ao cancelar');
verify(str_contains($actions, "redirect_to('control-panel', ['section' => 'catalogs'])") && str_contains($actions, "redirect_to('control-panel', ['section' => 'users'])"), 'sucesso de cadastro/gestão retorna à sua seção');
verify(str_contains($actions, '$extra[\'section\'] = \'catalogs\'') && str_contains($actions, '$extra[\'section\'] = \'users\''), 'erros de POST preservam a seção de origem');
verify(str_contains($actions, "in_array(\$action, ['catalog-add','catalog-edit','catalog-delete'], true)") && str_contains($actions, "require_permission('manage_catalogs')") && str_contains($actions, "'catalog-add','catalog-edit','catalog-delete'"), 'a ação de edição usa manage_catalogs e retorna à subview mesmo em erro');
verify(str_contains($actions, 'UPDATE catalogs SET value = ?') && str_contains($actions, 'UPDATE assets SET `') && str_contains($actions, 'UPDATE invoice_items SET `') && str_contains($actions, 'BINARY `'), 'renomeação atualiza catálogo e referências de ativos/NF-e atomicamente por nome exato');
verify(str_contains($layout, "can('manage_catalogs') || can('manage_users') || can('export_data')"), 'navegação do Painel continua condicionada às permissões');
verify(str_contains($layout, 'head_assets()') && str_contains((string)file_get_contents(__DIR__ . '/../app/ui.php'), "asset_url('permissions.css')"), 'layout carrega o CSS dos cards, subviews e editor com a versão do aplicativo');
verify(str_contains($index, "require_permission('export_data')") && str_contains($index, "if (\$kind === 'backup') download_backup()"), 'download de backup mantém validação server-side');

echo 'Total: ' . $GLOBALS['checks'] . " verificações de navegação e permissões do Painel aprovadas.\n";
