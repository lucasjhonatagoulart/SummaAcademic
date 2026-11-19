# Controle Patrimonial — Ativos e NF-e (PHP)

Versão independente para hospedagem compartilhada com PHP e MySQL, preparada para o plano gratuito da InfinityFree. Não depende de Node.js, Docker ou de um serviço pago da Manus.

Versão local: **1.8.0 (em desenvolvimento, não publicada)**. O sistema aparece apenas como “Controle Patrimonial”, sem nome de marca nem logo; o texto vem de `APP_TITLE` em `app/ui.php`. A versão fica em `APP_VERSION` (`app/ui.php`) e também invalida o cache de CSS e JavaScript. O código-fonte não inclui credenciais, backups nem dados patrimoniais reais; credenciais devem ser inseridas apenas no `config.php` local criado a partir de `config.example.php`.

## Recursos

- Editor de NF-e em três etapas: dados da nota, classificação dos itens e revisão. Oferece cadastro manual ou XML modelo 55, com upload por clique/arrastar e limite de 2 MB.
- O XML é analisado e mantido temporariamente na sessão até a escolha explícita de salvar rascunho ou registrar. Cabeçalho e dados fiscais dos itens XML são somente leitura no formulário e reconstruídos no servidor; XML/descrições originais permanecem preservados.
- Somente na importação XML, Local e Patrimônio por unidade são opcionais; a Conta Patrimonial continua obrigatória para registrar. Sem esses campos, cada linha fiscal gera um registro agregado que conserva quantidade e valor total, podendo ser completado depois. Cadastro manual continua exigindo local e código por unidade.
- Aplicação rápida de conta patrimonial/local apenas nos itens incorporáveis e dados de patrimônio por unidade.
- Organização de itens como Ativo Fixo, Bem de pequeno valor ou “Não incorporar”, com conta patrimonial, local, patrimônio, marca, estado, vida útil e observações.
- Cadastro, pesquisa, edição e baixa de ativos sem apagar o histórico.
- Ativos importados da planilha também podem ser editados: classe, Conta Patrimonial, Local, patrimônio, aquisição, quantidade, valor, vida útil e demais campos operacionais. Durante a edição, Local pode ficar vazio; nome e Conta Patrimonial continuam obrigatórios, e um Local informado deve ser cadastrado ou corresponder ao valor histórico atual. A NF-e, a descrição original e a origem permanecem preservadas; códigos patrimoniais novos continuam sujeitos à verificação de duplicidade.
- Administradores com `manage_users` e `edit_assets` podem cadastrar um ativo diretamente, sem NF-e fictícia. O formulário cria uma unidade com patrimônio único; a data e o valor podem ficar vazios quando desconhecidos. Depois, **Editar sem NF-e** permite corrigir descrição, classe, Conta Patrimonial, Local, patrimônio, data, quantidade, valor e demais dados operacionais. Telas, relatórios, impressão e planilhas legíveis indicam “Sem NF-e”, enquanto o backup mantém vínculo fiscal nulo/vazio para restauração.
- Login local por usuário, senha protegida, limite de tentativas, proteção CSRF e consultas preparadas.
- **Painel de controle** começa com cards de acesso rápido para Backup completo, Contas Patrimoniais/Locais e Usuários/Permissões. Clicar abre uma tela própria com somente as opções daquele card e um retorno ao menu; cards e seções continuam condicionados às permissões individuais.
- Contas Patrimoniais e Locais podem ser renomeados no Painel com a permissão existente; a alteração atualiza as referências de ativos e itens de NF-e em uma transação, sem reescrever o XML original ou mesclar nomes conflitantes.
- Todos os usuários autenticados podem visualizar; uma conta nova sem capacidades adicionais funciona em modo somente leitura. O primeiro administrador da instalação recebe acesso completo.
- Regras de autorização verificadas no servidor para páginas, formulários, downloads e operações de restauração.
- Planilhas Excel de ativos, baixas, notas e itens; CSV separado por classe; backup ZIP com JSON e XML originais.
- Relatório patrimonial com filtro de classe (ativo imobilizado/bem de pequeno valor), status e período. O **Resumo patrimonial** é uma tabela agrupada por Conta Patrimonial, com separadores horizontais e sem linhas verticais; aparece antes dos controles e acompanha os filtros. A prévia detalhada em tabela tem limite de altura e rolagem interna; Item recebe maior largura e campos curtos ficam compactos. Imprimir/Visualizar inclui na página 1 o cabeçalho do Relatório patrimonial com os filtros e dados de geração, seguido do resumo tabular; os itens começam na página 2. O aviso sobre a quantidade de colunas foi removido do documento impresso. Na página de Relatórios há somente a ação Imprimir/Visualizar; os botões separados de PDF e Excel foram retirados. A prévia e a impressão exigem `export_data`; o backup completo fica no Painel de controle.
- A barra global mantém apenas a opção de tema. Em Notas Fiscais, as ações **Cadastrar / Importar NF-e** e **Exportar NF-es** aparecem juntas antes da listagem para facilitar o acesso; cada uma continua protegida pela permissão correspondente. A importação de backup permanece no Painel e protegida pelas permissões compostas.
- Restauração no navegador de backup da versão Ativa anterior, somente em banco vazio e após confirmação.
- A prévia de restauração informa códigos patrimoniais repetidos/ausentes; os valores históricos permanecem nos ativos e só uma reserva por código distinto é criada para impedir novas duplicações.
- Na restauração, as contas patrimoniais `Energia Fotovoltaico` e `Sistema Fotovotaico` são normalizadas para **Sistema Fotovoltaico**, conforme autorização da proprietária. A planilha e o ZIP de origem permanecem intactos.
- Dashboard com gráfico circular de ativos por conta patrimonial e barras de valor por conta patrimonial; padrão visual Summa (menu azul-marinho `#172B4D`, azul de ação `#2878D0`, fonte Inter) e botão **Aparência** com tema claro, escuro ou automático e três tamanhos de texto, na aplicação, no login e na instalação.

O sistema não emite NF-e nem consulta a SEFAZ: o status exibido reflete o XML. Os valores são históricos de aquisição, não depreciação.

## Arquivos e dependências

`index.php` é a entrada; `install.php` cria as tabelas e o primeiro administrador; `app/` contém autenticação, permissões, PDO, parser fiscal, exports e restauração; `app/ui.php` reúne a versão, os ícones e as peças de interface comuns (cabeçalho da página, Aparência, tela de entrada); `templates/` contém as telas; `assets/` contém CSS, JavaScript, logo e JSZip local. `schema.sql` é aplicado pelo instalador. `upgrade-permissions.sql` é a migração de uma instalação que já tenha usuários.

Requer PHP 8.1+, PDO MySQL e DOM/XML (`DOMDocument`, `DOMXPath`). `mbstring` é opcional. Recomenda-se HTTPS e MySQL/MariaDB. O upload XML é limitado a 2 MB; o original fica no banco, nunca numa pasta pública.

## Padrão visual

O Ativa segue o padrão visual do Summa. As cores, raios, sombras e a fonte ficam em tokens no `assets/theme.css` (claro e escuro); `assets/app.css` traz a base, a estrutura da página (menu lateral, cabeçalho, conteúdo), os componentes e as seções de cada tela; `assets/permissions.css` traz o Painel de controle. Não use cores fixas nos estilos: use os tokens (`--navy`, `--blue`, `--surface`, `--gray`, `--line`, `--muted`, `--green`, `--red`, `--yellow`).

- Menu lateral fixo de 256 px, recolhível no computador e em gaveta no celular; cabeçalho de 64 px com **Aparência** e menu do usuário.
- `assets/theme.js` aplica tema e tamanho do texto antes de a página aparecer; `assets/shell.js` cuida do menu lateral e dos menus do cabeçalho.
- A fonte Inter vem do Google Fonts; sem internet, o sistema usa Segoe UI, Roboto ou Arial.
- A paleta do gráfico circular do dashboard foi mantida.

## Instalação e atualização

Leia [GUIA-INFINITYFREE.md](GUIA-INFINITYFREE.md). Em uma instalação nova, crie um banco, copie `config.example.php` para `config.php`, preencha as credenciais, proteja temporariamente o site com **Protected Directories** da InfinityFree, envie os arquivos e acesse `/install.php`. O instalador só cria a primeira conta quando ainda não existem administradores; não há `install_key`. Remova a proteção depois da criação da primeira conta.

No primeiro login, use o **Painel de controle** para cadastrar contas patrimoniais e locais antes de registrar notas/ativos. Crie usuários conforme necessário. Contas novas começam sem permissões extras; a conta administradora inicial recebe todas. Senhas aceitam 8 ou mais caracteres, com recomendação de 12+, e limite técnico de 72 bytes.

Para **atualizar uma instalação PHP já existente**, baixe um backup do banco e execute `upgrade-permissions.sql` e `upgrade-asset-deletion-audit.sql` em phpMyAdmin **antes de substituir os arquivos**. A primeira migração mantém o acesso que os administradores existentes já tinham; a segunda cria a tabela separada de auditoria sem alterar ativos ou NF-es. Depois, você pode restringir permissões no Painel de controle. Não substitua o `config.php` e não execute novamente a restauração do backup.

Mantenha `config.php`, XMLs e backups fora de repositório público. O backup ZIP contém informação patrimonial/fiscal sensível e não é criptografado. Se CSS não atualizar, recarregue com `Ctrl+F5`.

## Testes locais

```sh
find . -type f -name '*.php' -print0 | xargs -0 -n1 php -l
php tests/test_invoice_workflow.php
php tests/test_invoice_registration.php
php tests/test_direct_asset_registration.php
php tests/test_domain.php
php tests/test_permission_policy.php
php tests/test_asset_category_select.php
php tests/test_asset_edit.php
php tests/test_asset_deletion.php
php tests/test_site_actions.php
php tests/test_dashboard_chart.php
php tests/test_report_builder.php
php tests/test_control_panel_export.php
node tests/test_theme.js
node tests/test_invoice_wizard.js
node tests/test_report_print.js
node tests/test_restore_categories.js
```

O `.htaccess` bloqueia acesso HTTP direto a `app/`, `templates/`, `tests/`, configuração e SQL. Não remova a proteção.

## Módulo do Summa (1.9.0)

Com o bloco `summa` no `config.php` (veja `config.example.php`), o sistema vira o módulo **Summa Patrimônio**: login único pelo Summa, "Todos os módulos" no menu e permissões definidas no Summa (Painel de controle › Usuários › Módulos e áreas). A conta local continua disponível em `index.php?page=login&local=1` para emergências. Passo a passo completo no `LEIA-ME-INTEGRACAO.md` do pacote.
