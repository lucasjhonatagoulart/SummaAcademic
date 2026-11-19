# Ativa em PHP — plano de adaptação e correções locais

## Objetivo e limites

Manter uma versão independente em PHP 8.1+ e MySQL para a hospedagem gratuita InfinityFree, sem Node.js em runtime, Docker, SSH ou Composer no servidor. A aplicação React/TypeScript original permanece preservada em `/home/ubuntu/ativnfe`; as correções descritas aqui são somente para a cópia PHP em `/home/ubuntu/output/ativa-php-infinityfree`.

A versão local está em 1.7.0 enquanto as novas correções são revisadas. Não alterar `config.php`, banco hospedado ou arquivos do domínio; não criar outro ZIP nem publicar sem pedido explícito.

A interface e as saídas para pessoas usuárias usarão **Conta Patrimonial** para identificar a classificação vinculada ao ativo ou item da NF-e. Isso é somente nomenclatura de apresentação: preservar coluna/chave `category`, payloads, autorizações, dados históricos e valores armazenados, sem migração nem regravação de registros.

## Arquitetura, instalação e autorização

O aplicativo usa PHP com HTML renderizado no servidor, PDO/MySQL, CSS local e JavaScript pequeno, sem CDN. O instalador cria o primeiro administrador somente enquanto não houver usuários e então se bloqueia; essa conta recebe as capacidades iniciais. Usuários posteriores são criados no Painel de controle e começam somente para leitura.

As capacidades individuais são: editar/incorporar NF-e e importar XML; editar ativos e registrar baixas; gerenciar contas patrimoniais/locais; criar usuários e definir permissões; exportar dados; restaurar backup. Cada rota, formulário/POST, API e download verifica a permissão correspondente no servidor, com CSRF e consultas preparadas. Gestores só concedem capacidades que possuem, não alteram a própria conta e não podem despromover a última conta capaz de gerir usuários. `restore_backup` exige também `edit_assets`, `edit_invoices` e `manage_catalogs`. Criar uma Conta Patrimonial durante a edição de ativo requer `manage_catalogs`; excluir um ativo exige `manage_users` e `edit_assets`.

O gráfico circular do dashboard usa a paleta aprovada `#8ECAE6`, `#219EBC`, `#023047`, `#FFB703` e `#FB8500`, com pequenos separadores entre fatias e legenda textual mantida. `Móveis e Utensílios` mantém `#023047` nos temas claro e escuro. `Outras contas patrimoniais` usa a variável `--green-dark`, a mesma cor de fundo da barra do menu: `#003566` no claro e `#081421` no escuro. O mesmo mapeamento é aplicado à fatia e à legenda, sem alterar contagens nem filtros.

## NF-e, XML e patrimônio

O parser aceita XML de NF-e modelo 55, limita o arquivo a 2 MB, bloqueia DTD/entidades e verifica chave, dígito, CNPJ, data, itens e valores. A incorporação de XML exige `cStat` 100 ou 150; o sistema não consulta a SEFAZ. XML e descrições originais ficam preservados no servidor/banco.

O fluxo de lançamento acompanha a origem React: escolha entre cadastro manual e XML, editor em três etapas (dados da nota, classificação dos itens, revisão), suporte a arrastar/soltar, campos fiscais do XML protegidos, classificação por item, aplicação rápida de conta patrimonial/local e revisão final. A leitura do XML fica temporária na sessão até a pessoa salvar o rascunho ou registrar; não cria nota automaticamente. Apenas na importação XML, Local e Patrimônio por unidade podem ficar vazios; a Conta Patrimonial continua obrigatória para registrar. Sem códigos, salvar um único ativo agregado por item fiscal, mantendo quantidade e valor total, e permitir completar os dados depois. O cadastro manual conserva a exigência de Local e um patrimônio por unidade.

Na edição de qualquer ativo, inclusive registros vindos da planilha, **Conta Patrimonial** mantém o valor atual (mesmo histórico e fora do catálogo). Usuários sem `manage_catalogs` escolhem somente opções cadastradas; quem possui `manage_catalogs` pode escolher uma sugestão ou digitar uma nova conta, que é cadastrada junto da edição na mesma transação. Resolver grafias equivalentes para o padrão existente para evitar duplicações. Permitir também alterar a classe entre Ativo Fixo/Bem de pequeno valor, patrimônio, Local, nome, data/ano de aquisição, quantidade, valor, vida útil, marca, cor, tipo, condição e observações. Local pode ficar vazio ou ser removido durante a edição; nome e Conta Patrimonial continuam obrigatórios. Se um Local for informado, validar contra o catálogo ou permitir manter o valor histórico já existente. Validar classe por allowlist e patrimônio sem duplicar códigos. Para ativos ligados a NF-e ou importados, preservar XML, NF-e, descrição original e metadados de origem. Para o cadastro direto identificado pela origem interna `Cadastro direto sem NF-e`, expor o botão/formulário específico, permitir editar todos os campos de inclusão (inclusive data, quantidade e valor), sincronizar a descrição editada e preservar o vínculo fiscal vazio/nulo. Contas Patrimoniais/Locais podem ser renomeadas no Painel sob `manage_catalogs`; atualizar referências dos ativos e dos itens de NF-e na mesma transação. Rejeitar nomes conflitantes para que a edição não mescle valores inadvertidamente. A única consolidação aprovada é `Energia Fotovoltaico` e `Sistema Fotovotaico` para **Sistema Fotovoltaico**; não agrupar outras grafias automaticamente. Essa consolidação de dados autorizada permanece separada da troca de rótulos solicitada.

O cadastro direto de um ativo sem NF-e é uma rota/formulário separado do editor fiscal. O atalho, GET e POST exigem `manage_users` e `edit_assets`; não inserir em `invoices`/`invoice_items`, manter `invoiceNumber=''` e `invoiceId=NULL`, atribuir uma unidade e reservar um código patrimonial único em transação. Exigir descrição, conta, local, classe e patrimônio; data/valor podem ser nulos. Usar “Sem NF-e” apenas nas apresentações legíveis, preservando os valores internos do backup para restauração fiel.

A restauração do backup preserva ativos e códigos patrimoniais históricos, cria uma reserva por código distinto, permite editar registros antigos repetidos e impede novas duplicidades. A prévia mostra contagens agregadas, sem revelar códigos.

## Relatórios patrimoniais

A tela permite escolher período, situação do ativo e classificação (ativo imobilizado ou bem de pequeno valor), além dos campos permitidos. A coluna Item recebe mais largura; campos curtos como patrimônio, NF-e, aquisição, valor e status usam larguras menores. **Conta Patrimonial** é o rótulo exibido nos filtros, cabeçalhos, resumo e saídas legíveis.

O resumo se chama **Resumo patrimonial** e aparece antes dos controles do relatório detalhado na tela. Não exibir a linha textual de status/classe/período no resumo; o texto explica que ele acompanha os filtros aplicados no relatório. Em telas amplas, manter rótulo e valor inline, com **Total de registros** à esquerda e **Valor Total (R$)** na extremidade direita da mesma linha; os dois valores numéricos usam a mesma tipografia e tamanho. Em celular, os dois grupos continuam lado a lado e ajustam sua composição interna sem quebrar o valor. Substituir o gráfico agrupado por uma tabela com Conta Patrimonial, Registros e Valor Total (R$), sem divisórias verticais; manter somente linhas horizontais entre o cabeçalho e as linhas de dados. Remover a divisória sob o título do resumo.

A prévia detalhada na tela mantém sua rolagem interna. A única ação visível na página de Relatórios é **Imprimir / Visualizar**; os botões separados de PDF e Excel são removidos da interface. Endpoints mantidos no servidor continuam protegidos por `export_data`. Pelo diálogo nativo de impressão, o navegador ainda pode oferecer “Salvar como PDF”.

Na impressão, o cabeçalho do **Relatório patrimonial** (marca, título, status, classe, período, data de geração, orientação e número de campos) substitui o título “Resumo patrimonial” no topo da página 1; abaixo dele aparecem os KPIs e a tabela do resumo. O relatório detalhado começa na página 2 sem repetir o cabeçalho já mostrado na primeira página; o cabeçalho interno do detalhamento segue disponível na visualização em tela. O aviso “Este relatório contém X colunas...” não deve aparecer no documento. O número de contas filtradas regula o tamanho de fonte e o espaço vertical das linhas, exclusivamente no resumo, para aproveitar a página sem esconder grupos. A tabela usa apenas separadores horizontais, remove o limite de rolagem e conserva as margens seguras.

## Painel de controle e cartões

A tela inicial do Painel apresenta somente cartões para as seções que a conta pode acessar: Backup, Contas Patrimoniais/Locais e Usuários/Permissões. Clicar em um cartão abre uma view PHP própria com apenas as opções daquela área, e há um link “Voltar”. O roteador valida a seção e a permissão no servidor, carrega apenas os dados necessários e retorna POSTs de sucesso/erro à mesma seção. Nenhum formulário ou detalhe das demais áreas aparece na tela inicial.

O visual reutiliza os cartões aprovados no padrão visual Summa (tokens de `assets/theme.css`), tema claro/escuro/automático, foco visível e grade responsiva; não altera os formulários, operações, dados ou capacidades existentes. Nas subviews, manter o título e a descrição principais com “Voltar”, mas retirar o kicker e o título repetidos nos conteúdos internos, preservando somente a descrição do módulo. As listas de contas patrimoniais e locais usam rolagem interna acessível, com altura para cerca de sete linhas e ajuste à altura da tela; os dois cartões de catálogo devem ter a mesma altura. Cabeçalhos, formulários, tabelas e cartões internos se adaptam a telas estreitas. No cabeçalho global fica apenas o seletor de tema; atalhos de NF-e/backup não ocupam essa área. A restauração do backup continua dentro da subview de Backup, sob a mesma permissão composta.

Em Notas Fiscais, retirar as ações do cabeçalho global. Manter cadastro/importação e Exportar NF-es em um grupo acessível imediatamente antes da listagem, para reduzir o deslocamento até as ações. Preservar as guardas de `edit_invoices` e `export_data` e deixar os botões quebrarem responsivamente em telas estreitas.

## Exportação e restauração

Os downloads existentes de XLSX/CSV/XML e o backup ZIP completo preservam seus controles de permissão. Os cabeçalhos legíveis dos arquivos chamarão o campo de **Conta Patrimonial**, mantendo as chaves/colunas técnicas existentes. O relatório Excel personalizado usa apenas as colunas/filtros selecionados. O backup completo inclui o histórico de exclusões para preservar os motivos e a autoria, mas esse histórico não faz parte de relatórios nem das planilhas patrimoniais. O backup é acessado dentro do card de Backup no Painel e permanece confidencial; nunca incluir o backup real no pacote de instalação.

A restauração só grava em banco vazio (incluindo ausência de registros de auditoria), após validação/preview/confirmação e staging, em transação única; backups antigos sem arquivo de histórico continuam aceitos como zero exclusões. Preservar a migração de permissões existente e não reatribuir capacidades revogadas.

## Estrutura principal

- `index.php`: roteador, ações, APIs, downloads e autorização server-side.
- `install.php`, `schema.sql`, `upgrade-permissions.sql`, `upgrade-asset-deletion-audit.sql`: primeiro acesso, estrutura e migrações aditivas.
- `app/`: bootstrap/PDO, segurança, ações, parser NF-e, relatórios, XLSX/CSV/ZIP, auditoria e restauração.
- `templates/`: layout comum e telas PHP de dashboard, ativos, histórico administrativo de exclusões, NF-e, relatórios, Painel, contas patrimoniais, usuários e restauração.
- `assets/`: CSS da aplicação/tema/permissões, JavaScript local e recursos de marca.
- `tests/`: testes locais sem banco hospedado; `README.md` e `GUIA-INFINITYFREE.md` documentam uso/hospedagem; `TODO.md` registra aceites e status; `research.md` mantém fontes técnicas.

## Marca e segurança operacional

Padrão visual Summa (azul-marinho `#172B4D`, azul `#2878D0`, Inter); Aparência com tema claro, escuro ou automático e três tamanhos de texto; interface responsiva em pt-BR. Preservar CSRF, sessões seguras, hashes de senha, limite de tentativas, acesso mínimo e tratamento privado de XML/backup. Não usar dados fictícios em banco nem expor conteúdo patrimonial nos testes ou na prévia.


## Pacote de identidade visual (somente local)

Manter em `assets/brand/` as variantes claras e escuras SVG/PNG fornecidas, além do símbolo isolado PNG. Na navegação autenticada, usar apenas `ativa-logo-escuro.svg` na sidebar escura, com `alt="ativa. — Controle Patrimonial"`; dimensionar o wordmark pela largura disponível e manter a altura proporcional. Não substituir o favicon, não alterar o layout/interações e não publicar nem enviar os arquivos à InfinityFree sem autorização explícita.
