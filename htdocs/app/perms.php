<?php
// Catálogo de permissões, perfis padrão e menus de cada portal.

const MODULE_LABEL = [
    'admin' => 'Administrativo',
    'academic' => 'Secretaria',
    'communication' => 'Comunicação',
    'director' => 'Diretoria',
    'finance' => 'Financeiro',
    'classroom' => 'Professor',
    'student' => 'Aluno',
    'patrimony' => 'Patrimônio',
    'purchasing' => 'Compras',
];
const MODULE_INFO = [
    'admin' => ['shield', 'Painel de controle: usuários, instituição, e-mail e auditoria.'],
    'academic' => ['graduation-cap', 'Cadastros acadêmicos, períodos, turmas, horários e históricos.'],
    'communication' => ['megaphone', 'Comunicados e quadro de avisos da página inicial.'],
    'director' => ['landmark', 'Painel da Diretoria com os indicadores institucionais.'],
    'finance' => ['wallet', 'Mensalidades, inadimplência, balancete e pagamento de professores.'],
    'classroom' => ['presentation', 'Portal do Professor. Só vale para quem tem cadastro de professor.'],
    'student' => ['backpack', 'Portal do Aluno. Só vale para quem tem cadastro de aluno.'],
    'patrimony' => ['package', 'Notas fiscais, ativos, baixas, relatórios e backup do patrimônio.'],
    'purchasing' => ['shopping-cart', 'Solicitações de compra, cotações, aprovação e recebimento.'],
];

/** Rótulos curtos das permissões do Patrimônio (aparecem em "Suas áreas" no card do Workspace) */
const PATRIMONY_AREA_LABEL = ['patrimony:access' => 'Consulta', 'patrimony:invoices' => 'Notas fiscais', 'patrimony:assets' => 'Ativos e baixas', 'patrimony:catalogs' => 'Contas e locais',
    'patrimony:export' => 'Exportação', 'patrimony:admin' => 'Administração', 'patrimony:restore' => 'Restauração de backup'];
/** Rótulos curtos das permissões de Compras (aparecem em "Suas áreas" no card do Workspace) */
const PURCHASE_AREA_LABEL = ['purchase:request' => 'Solicitar', 'purchase:manage' => 'Comprar e receber', 'purchase:approve' => 'Aprovar', 'purchase:read' => 'Consulta', 'purchase:admin' => 'Voltar etapa e excluir'];
const CONTROL_AREA_LABEL = ['users:manage' => 'Usuários', 'modules:manage' => 'Módulos', 'institution:settings' => 'Instituição e e-mail', 'platform:manage' => 'Instituições', 'audit:read' => 'Auditoria'];

/**
 * Módulos da plataforma Summa (Workspace). Cada módulo é um "manifesto": nome, ícone, cores do card,
 * as áreas de permissão que ele reúne (chaves de MODULE_LABEL) e os portais em que ele abre.
 * Os módulos com 'soon' ainda não existem neste sistema e aparecem só para quem administra usuários.
 * Um módulo com 'entry' e sem portais abre por essa rota para quem tem qualquer permissão das áreas dele
 * (ex.: o Patrimônio, que é um sistema à parte em patrimonio/ e entra por login único).
 */
const PLATFORM_MODULES = [
    'academico' => ['name' => 'Acadêmico', 'icon' => 'graduation-cap', 'stripe' => '#2878D0', 'ink' => '#1F68B8', 'soft' => '#E8F1FB',
        'desc' => 'Secretaria, cursos, turmas, horários, notas, frequência, históricos e os portais do professor e do aluno.',
        'areas' => ['academic', 'communication', 'classroom', 'student'], 'portals' => ['admin', 'prof', 'aluno']],
    'diretoria' => ['name' => 'Diretoria', 'icon' => 'landmark', 'stripe' => '#12808A', 'ink' => '#0E6971', 'soft' => '#E3F3F4',
        'desc' => 'Indicadores institucionais, alunos e professores, resumo financeiro e balancete para acompanhar a instituição.',
        'areas' => ['director'], 'portals' => ['dir'], 'labels' => ['reports:institutional' => 'Indicadores', 'reports:finance' => 'Financeiro e balancete']],
    'financeiro' => ['name' => 'Financeiro', 'icon' => 'wallet', 'stripe' => '#1F845A', 'ink' => '#17694A', 'soft' => '#E6F4EE',
        'desc' => 'Mensalidades e cobranças, inadimplência, pagamento de professores, balancete e exportação para o ERP.',
        'areas' => ['finance'], 'portals' => ['fin'], 'labels' => ['finance:manage' => 'Alunos e cobranças', 'payroll:manage' => 'Professores']],
    'compras' => ['name' => 'Compras', 'icon' => 'shopping-cart', 'stripe' => '#C2417A', 'ink' => '#9C2F60', 'soft' => '#FBE9F1',
        'desc' => 'Solicitações de compra, cotações de até 3 fornecedores, aprovação, compra e recebimento, com envio dos itens patrimoniáveis ao Patrimônio.',
        'areas' => ['purchasing'], 'portals' => ['comp'], 'labels' => PURCHASE_AREA_LABEL],
    'controle' => ['name' => 'Painel de controle', 'icon' => 'sliders-horizontal', 'stripe' => '#172B4D', 'ink' => '#172B4D', 'soft' => '#E9EDF4',
        'desc' => 'Usuários e liberação de módulos, dados da instituição, envio de e-mails, instituições cadastradas e auditoria.',
        'areas' => ['admin'], 'portals' => [], 'entry' => 'admin.controle', 'labels' => CONTROL_AREA_LABEL],
    'patrimonio' => ['name' => 'Patrimônio', 'icon' => 'package', 'stripe' => '#F0B44D', 'ink' => '#7A5310', 'soft' => '#FDF4E3',
        'desc' => 'Controle patrimonial: notas fiscais, ativos, contas patrimoniais, locais, baixas, relatórios e backup.',
        'areas' => ['patrimony'], 'portals' => [], 'entry' => 'patrimonio', 'labels' => PATRIMONY_AREA_LABEL],
    'ti' => ['name' => 'Setor de TI', 'icon' => 'monitor-cog', 'stripe' => '#6E56CF', 'ink' => '#5B45B0', 'soft' => '#EEEBFA',
        'desc' => 'Chamados, equipamentos e acessos.', 'areas' => [], 'portals' => [], 'soon' => true],
];

/** Telas do módulo Painel de controle (ficam no portal admin, mas pertencem a este módulo) */
const CONTROL_ROUTES = ['admin.controle', 'admin.usuarios', 'admin.usuario', 'admin.usuario_novo', 'admin.modulos', 'admin.modulo', 'admin.instituicao', 'admin.instituicoes', 'admin.auditoria'];

const PERMISSIONS = [
    'users:manage' => ['admin', 'Gerenciar usuários e permissões'],
    'modules:manage' => ['admin', 'Incluir e configurar módulos por integração'],
    'institution:settings' => ['admin', 'Alterar dados da instituição'],
    'platform:manage' => ['admin', 'Cadastrar instituições e escolher a instituição em uso'],
    'audit:read' => ['admin', 'Consultar a auditoria'],
    'students:manage' => ['academic', 'Cadastrar e editar alunos'],
    'professors:manage' => ['academic', 'Cadastrar e editar professores'],
    'courses:manage' => ['academic', 'Gerenciar cursos e matrizes curriculares'],
    'terms:manage' => ['academic', 'Gerenciar períodos letivos e encerrar períodos'],
    'classes:manage' => ['academic', 'Gerenciar turmas, ofertas, quadro de horários e eletivas'],
    'transcripts:issue' => ['academic', 'Emitir e imprimir históricos escolares'],
    'records:edit-after-deadline' => ['academic', 'Editar notas e frequência após o prazo'],
    'announcements:manage' => ['communication', 'Publicar comunicados e gerenciar todo o quadro de avisos'],
    'board:manage' => ['communication', 'Publicar avisos no quadro de avisos'],
    'reports:institutional' => ['director', 'Consultar indicadores institucionais, alunos e professores'],
    'reports:finance' => ['director', 'Ver o resumo financeiro e o balancete'],
    'finance:read' => ['academic', 'Ver a situação financeira na ficha do aluno'],
    'finance:manage' => ['finance', 'Mensalidades: gerar cobranças e registrar pagamentos dos alunos'],
    'payroll:manage' => ['finance', 'Professores: valor da aula, balanço de aulas e pagamentos'],
    'classroom:own' => ['classroom', 'Acessar as próprias turmas (diário, chamada e notas)'],
    'student-portal:own' => ['student', 'Consultar cursos, notas, frequência e financeiro próprios'],
    'electives:enroll:own' => ['student', 'Escolher eletivas e horários'],
    'purchase:request' => ['purchasing', 'Abrir solicitações de compra e acompanhar as próprias'],
    'purchase:manage' => ['purchasing', 'Registrar compras e recebimentos, cotar por qualquer solicitante e cadastrar setores (vê todas as solicitações)'],
    'purchase:approve' => ['purchasing', 'Aprovar compras, pedir cotação ou recusar (Diretoria e Presidência)'],
    'purchase:read' => ['purchasing', 'Consultar todas as solicitações, sem editar'],
    'purchase:admin' => ['purchasing', 'Voltar uma solicitação para a etapa anterior e excluir solicitações'],
    'patrimony:access' => ['patrimony', 'Entrar no Patrimônio e consultar notas, ativos e relatórios'],
    'patrimony:invoices' => ['patrimony', 'Cadastrar, revisar e incorporar NF-e (importar XML)'],
    'patrimony:assets' => ['patrimony', 'Editar ativos e registrar baixas'],
    'patrimony:catalogs' => ['patrimony', 'Gerenciar contas patrimoniais e locais'],
    'patrimony:export' => ['patrimony', 'Exportar relatórios, planilhas, XML e backup completo'],
    'patrimony:admin' => ['patrimony', 'Excluir ativos, cadastrar ativo sem NF-e e ver os usuários do módulo'],
    'patrimony:restore' => ['patrimony', 'Restaurar backup em uma base vazia'],
];

const SYSTEM_ROLES = [
    'ADMIN' => ['Administrador', 'Acesso integral ao sistema', '*'],
    'STAFF' => ['Secretaria Acadêmica', 'Cadastros acadêmicos, históricos e comunicados',
        ['students:manage', 'professors:manage', 'courses:manage', 'terms:manage', 'classes:manage', 'transcripts:issue', 'records:edit-after-deadline', 'announcements:manage', 'board:manage', 'finance:read']],
    'DIRECTOR' => ['Diretoria', 'Consulta de indicadores e do financeiro; aprova compras', ['reports:institutional', 'reports:finance', 'purchase:approve']],
    'FINANCE' => ['Financeiro', 'Cobranças, pagamentos e inadimplência', ['finance:manage', 'payroll:manage']],
    'PROFESSOR' => ['Professor', 'Turmas vinculadas: diário, chamada e notas', ['classroom:own']],
    'STUDENT' => ['Aluno', 'Dados do próprio aluno', ['student-portal:own', 'electives:enroll:own']],
    'CUSTOM' => ['Personalizado', 'Sem permissões pré-marcadas: escolha uma a uma', []],
];
const ROLE_ORDER = ['ADMIN', 'STAFF', 'DIRECTOR', 'FINANCE', 'PROFESSOR', 'STUDENT', 'CUSTOM'];
/** Papéis que ainda existem por trás: acesso total e os vinculados a cadastro de aluno/professor */
const ACCESS_LABEL = ['ADMIN' => 'Acesso total', 'PROFESSOR' => 'Professor', 'STUDENT' => 'Aluno'];
const RECORD_ROLES = ['PROFESSOR', 'STUDENT'];
/** Permissões que dão acesso ao Painel Administrativo */
const ADMIN_PORTAL_PERMS = ['users:manage', 'modules:manage', 'institution:settings', 'platform:manage', 'audit:read', 'students:manage', 'professors:manage', 'courses:manage', 'terms:manage', 'classes:manage', 'transcripts:issue', 'records:edit-after-deadline', 'announcements:manage', 'board:manage'];
const CONTROL_PANEL_PERMS = ['users:manage', 'modules:manage', 'institution:settings', 'platform:manage', 'audit:read'];

/**
 * Catálogos em uso: os fixos acima somados aos módulos incluídos pelo Painel de controle (app/modules.php).
 * As telas usam estas funções; as constantes ficam como a parte fixa do sistema.
 */
function perm_catalog(): array { return function_exists('ext_perm_catalog') ? PERMISSIONS + ext_perm_catalog() : PERMISSIONS; }
function module_labels(): array {
    $out = MODULE_LABEL;
    if (function_exists('ext_modules')) foreach (ext_modules() as $slug => $m) $out[ext_area($slug)] = $m['name'];
    return $out;
}
function module_info(): array {
    $out = MODULE_INFO;
    if (function_exists('ext_modules')) foreach (ext_modules() as $slug => $m) $out[ext_area($slug)] = [$m['icon'], $m['desc'] ?: 'Módulo incluído por integração.'];
    return $out;
}
/** Módulos do Workspace: os por integração entram depois dos que já existem e antes dos "em breve" */
function platform_modules(): array {
    if (!function_exists('ext_platform_modules') || !($ext = ext_platform_modules())) return PLATFORM_MODULES;
    $out = []; $placed = false;
    foreach (PLATFORM_MODULES as $k => $m) {
        if (!$placed && !empty($m['soon'])) { $out += $ext; $placed = true; }
        if (!empty($m['soon']) && isset($ext[$k])) continue;   // o "em breve" sai quando o módulo de verdade é incluído
        $out[$k] = $m;
    }
    return $placed ? $out : $out + $ext;
}

const PORTAL_LABEL = ['admin' => 'Painel Administrativo', 'dir' => 'Painel da Diretoria', 'fin' => 'Financeiro', 'comp' => 'Compras', 'prof' => 'Portal do Professor', 'aluno' => 'Portal do Aluno'];

/** Menus: [rótulo, rota, ícone, permissões (qualquer uma), rotas que acendem o item] */
function nav_for(string $portal): array {
    $mural = ['Comunicados e avisos', 'mural', 'megaphone', ['announcements:manage', 'board:manage']];
    return match ($portal) {
        'controle' => [
            'Painel de controle' => [
                ['Visão geral', 'admin.controle', 'layout-dashboard', CONTROL_PANEL_PERMS],
                ['Usuários', 'admin.usuarios', 'user-cog', ['users:manage'], ['admin.usuario', 'admin.usuario_novo']],
                ['Módulos', 'admin.modulos', 'blocks', ['modules:manage'], ['admin.modulo']],
                ['Instituição e e-mail', 'admin.instituicao', 'landmark', ['institution:settings']],
                ['Instituições', 'admin.instituicoes', 'building-2', ['platform:manage']],
                ['Auditoria', 'admin.auditoria', 'history', ['audit:read']],
            ],
        ],
        'admin' => [
            'Visão geral' => [
                ['Início', 'admin.inicio', 'house'],
                ['Indicadores', 'admin.indicadores', 'chart-column', ['reports:institutional', 'students:manage']],
            ],
            'Gestão acadêmica' => [
                ['Cursos', 'admin.cursos', 'graduation-cap', ['courses:manage', 'classes:manage', 'terms:manage'], ['admin.curso', 'admin.periodos', 'admin.turmas', 'admin.ofertas', 'admin.oferta', 'admin.horarios']],
                ['Alunos', 'admin.alunos', 'users', ['students:manage'], ['admin.aluno', 'admin.aluno_form', 'admin.documento']],
                ['Professores', 'admin.professores', 'users-round', ['professors:manage'], ['admin.professor', 'admin.professor_form']],
            ],
            'Secretaria' => [
                $mural,
                ['Documentos emitidos', 'admin.documentos', 'file-badge', ['transcripts:issue']],
            ],
        ],
        'dir' => [
            'Diretoria' => [
                ['Início', 'dir.inicio', 'house'],
                ['Indicadores', 'dir.indicadores', 'chart-column', ['reports:institutional']],
                ['Alunos', 'dir.alunos', 'users', ['reports:institutional'], ['dir.aluno']],
                ['Professores', 'dir.professores', 'users-round', ['reports:institutional'], ['dir.professor']],
                ['Financeiro', 'dir.financeiro', 'wallet', ['reports:finance']],
                ['Balancete', 'dir.balancete', 'scale', ['reports:finance']],
                $mural,
            ],
        ],
        'fin' => [
            'Financeiro' => [
                ['Início', 'fin.inicio', 'house'],
                ['Indicadores', 'fin.indicadores', 'chart-column', ['finance:manage']],
                ['Alunos', 'fin.alunos', 'users', ['finance:manage'], ['fin.situacao', 'fin.aluno', 'fin.cobrancas', 'fin.notificar']],
                ['Professores', 'fin.professores', 'users-round', ['payroll:manage'], ['fin.professor', 'fin.prof_pagamento']],
                ['Balancete', 'fin.balancete', 'scale', ['finance:manage']],
                ['Exportar', 'fin.exportar', 'file-spreadsheet', ['finance:manage']],
                $mural,
            ],
        ],
        'comp' => [
            'Compras' => [
                ['Início', 'comp.inicio', 'house'],
                ['Solicitações', 'comp.solicitacoes', 'file-text', [], ['comp.solicitacao', 'comp.nova', 'comp.cotacao', 'comp.receber']],
                ['Fornecedores', 'comp.fornecedores', 'truck', ['purchase:manage', 'purchase:approve', 'purchase:read']],
                ['Relatórios', 'comp.relatorios', 'chart-column', ['purchase:manage', 'purchase:approve', 'purchase:read']],
                ['Setores', 'comp.setores', 'building', ['purchase:manage', 'purchase:approve']],
            ],
        ],
        'prof' => [
            'Docência' => [
                ['Início', 'prof.inicio', 'house'],
                ['Minhas turmas', 'prof.turmas', 'school', [], ['prof.turma']],
                ['Meus horários', 'prof.horarios', 'calendar-days'],
                $mural,
            ],
        ],
        'aluno' => [
            'Estudos' => [
                ['Início', 'aluno.inicio', 'house'],
                ['Meus cursos', 'aluno.cursos', 'book-open', [], ['aluno.curso', 'aluno.disciplina']],
                ['Eletivas', 'aluno.eletivas', 'list-checks', ['electives:enroll:own']],
                ['Horários', 'aluno.horarios', 'calendar-days'],
                ['Financeiro', 'aluno.financeiro', 'wallet'],
            ],
        ],
        default => [],
    };
}
