# Guia de hospedagem — InfinityFree

Este pacote é a versão PHP independente do Ativa. Você instala o código, banco e dados na sua conta; nada é publicado automaticamente pela Manus.

## 1. Preparar a hospedagem

1. Crie sua conta de hospedagem e um domínio/subdomínio que aponte para ela.
2. Crie um banco MySQL no painel e anote host, nome do banco, usuário e senha exatamente como aparecem. O hostname pode ser diferente de `localhost`.
3. Selecione PHP 8.1 ou superior e confirme PDO MySQL e DOM/XML (`PDO`, `pdo_mysql`, `dom`, `libxml`). `mbstring` é opcional.
4. Ative HTTPS/SSL antes do primeiro login.
5. Antes de abrir publicamente a instalação, proteja temporariamente o diretório `/` com **Protected Directories** no painel InfinityFree. Mantenha essa proteção até concluir a criação da primeira conta administradora. A InfinityFree recomenda a ferramenta do painel ([orientação](https://forum.infinityfree.com/t/password-protect-a-page/88158); [proteger todo o site](https://forum.infinityfree.com/t/protecting-htdocs-with-password/30494)).

Confirme no painel os limites atuais de uso, upload, processamento e conexões da sua conta.

## 2. Instalar em um domínio novo

1. Descompacte `Ativa-PHP-InfinityFree.zip` no seu computador.
2. Copie `config.example.php` para `config.php` e preencha `db_host`, `db_name`, `db_user` e `db_pass` com os dados MySQL do painel. Não há `install_key`.
3. Envie os arquivos para `htdocs`, preservando `.htaccess`, `app/`, `templates/` e `assets/`. Não envie backups, XML avulsos, `.env` nem a planilha original.
4. Com o site protegido, abra `https://SEU-DOMINIO/install.php`. Crie a primeira conta e uma senha própria: mínimo de 8 caracteres, recomendação de 12 ou mais e limite técnico de 72 bytes.
5. O primeiro usuário recebe acesso completo. Quando ele for criado, o instalador se bloqueia. Remova a proteção temporária de `/` e entre no Ativa.
6. Abra o **Painel de controle** para cadastrar contas patrimoniais e locais e criar outros usuários. Usuários novos começam somente para visualização; selecione apenas as permissões necessárias.

O cabeçalho global mostra somente o controle de tema. Em **Notas fiscais**, use **Cadastrar / Importar NF-e** ou **Exportar NF-es (.xlsx)** acima da listagem. A ação de cadastro/importação continua restrita a `edit_invoices`, e a exportação continua restrita a `export_data`. A importação de backup está no **Painel de controle → Backup completo**, e continua restrita à combinação de permissões de restauração.

## 3. Atualizar uma instalação PHP já existente

1. Antes de substituir arquivos, baixe um backup do banco pelo painel/phpMyAdmin e guarde-o fora de `htdocs`.
2. Em phpMyAdmin, selecione o banco do Ativa, abra a aba **SQL** e execute `upgrade-permissions.sql` e `upgrade-asset-deletion-audit.sql`. São migrações aditivas; a segunda cria o histórico administrativo de exclusões e não apaga ativos, NF-es nem dados existentes.
3. Só depois de a migração SQL terminar, envie os arquivos novos e substitua os correspondentes. Não substitua nem envie outro `config.php` e não altere/apague os dados do banco.
4. Entre com a conta atual. No **Painel de controle → Usuários e permissões**, ajuste as capacidades das outras contas.

A migração registra os usuários já atualizados para impedir que uma reexecução restaure permissões que tenham sido revogadas. Faça uma cópia do banco mesmo assim. Não reexecute a restauração do backup da versão anterior.

## 4. Permissões do Painel de controle

Todas as contas autenticadas podem consultar as telas do sistema. O gestor pode conceder, individualmente, permissão para cadastrar/revisar/incorporar NF-e, editar ativos/registrar baixas, gerenciar contas patrimoniais/locais, gerenciar usuários, exportar planilhas/CSV/XML/backup e restaurar backup. Uma conta sem capacidades adicionais fica somente para leitura. Uma pessoa não pode modificar as próprias permissões nem conceder capacidades que ela própria não possui.

O Painel inicia com cards de acesso rápido para Backup completo, Contas Patrimoniais/Locais e Usuários/Permissões. Cada card só aparece para quem possui a capacidade correspondente; ao clicar, abre uma tela própria com somente as opções daquela área e o link **Voltar**. As operações continuam validadas no servidor. Nas telas de NF-e, os valores novos seguem a permissão de gestão do catálogo. Na edição de ativo, somente quem tem `manage_catalogs` pode digitar uma nova Conta Patrimonial; a conta é cadastrada junto com a edição, na mesma transação. As demais contas só escolhem padrões existentes. Padrões podem ser renomeados no Painel; a alteração sincroniza referências em ativos e itens de NF-e dentro de uma transação, sem alterar o XML original nem mesclar nomes conflitantes. A restauração é mais ampla e também exige as permissões de editar NF-e, editar ativos e gerenciar padrões, além da permissão específica de restaurar.

Na lista **Ativos**, somente uma conta administradora com `manage_users` e `edit_assets` verá **Cadastrar ativo sem NF-e**. A mesma dupla de permissões é verificada no acesso à tela e no POST. O cadastro não cria nota, número ou vínculo fiscal fictício; exige descrição, Conta Patrimonial, Local, classe e um patrimônio único, enquanto data e valor podem ficar em branco se desconhecidos. Lista, detalhe, relatórios, impressão e exports legíveis mostram **Sem NF-e**; o backup técnico preserva `invoiceNumber` vazio e `invoiceId` nulo.

Para editar um registro, use **Editar** na linha do ativo ou **Editar dados** no detalhe. Para um cadastro direto sem NF-e, o botão indica **Editar sem NF-e** e o formulário identifica o registro. Ele permite alterar todos os dados da inclusão: descrição, classe (Ativo Fixo/Bem de pequeno valor), Conta Patrimonial, Local, patrimônio, data, quantidade, valor, ano de compra, vida útil, marca, tipo, cor, estado e observações. **Local pode ficar em branco ou ser removido durante a edição**; nome e Conta Patrimonial continuam obrigatórios. Um Local preenchido deve ser cadastrado ou corresponder ao valor histórico atual. Quem tem `manage_catalogs` pode informar uma Conta Patrimonial nova durante a edição; o valor entra no catálogo dentro da mesma transação. A descrição também é atualizada no campo de apresentação do cadastro direto; não se cria nem se vincula uma NF-e. Para ativos com nota ou importados da planilha, a NF-e, a descrição original e os metadados da origem permanecem preservados. O servidor continua exigindo `edit_assets`, aceita apenas classes válidas e impede atribuir patrimônio já utilizado.

O editor de NF-e segue três etapas: **Dados da nota**, **Classificação dos itens** e **Revisão**. Escolha cadastro manual ou selecione/arraste um XML de até 2 MB. A leitura válida deixa os dados temporariamente na sessão; a nota só é gravada ao clicar em **Salvar rascunho** ou **Registrar e incorporar**. Campos fiscais e descrições originais do XML são protegidos e conferidos novamente pelo servidor. Na importação XML, Local e Patrimônio por unidade são opcionais, mas a Conta Patrimonial continua necessária para registrar. Sem códigos, cada linha fiscal é mantida como um registro agregado com quantidade e valor preservados; complete os campos depois na edição do ativo. No cadastro manual, Local e Patrimônio continuam obrigatórios. Contas Patrimoniais/Locais usam o Painel; aplicação rápida ignora itens marcados **Não incorporar**. A incorporação de XML exige `cStat` 100 ou 150; o sistema não consulta a SEFAZ.

## 5. Restaurar os dados anteriores

1. Na versão Ativa anterior, uma conta com `export_data` abre **Painel de controle → Backup completo → Exportar backup (.zip)**. Guarde o arquivo no computador, não em `htdocs`.
2. No destino vazio, entre com uma conta que tenha permissão para restauração e abra **Importar backup**.
3. Escolha o ZIP. A conferência ocorre no navegador; revise contagens e confirme. A restauração só é permitida sem ativos, NF-es, padrões, histórico de exclusões ou marcador de importação e grava tudo em uma única transação.
4. A restauração normaliza, com sua autorização, `Energia Fotovoltaico` e `Sistema Fotovotaico` para o grupo **Sistema Fotovoltaico**. A prévia mostra quantos campos de conta patrimonial serão alterados e quantas entradas do catálogo serão unificadas. O ZIP e a planilha de origem ficam intactos.
5. A prévia também mostra códigos patrimoniais repetidos e ativos sem número. Ela não apaga nem corrige esses valores históricos: preserva todos os ativos e cria uma reserva única por código distinto, para que a validação continue bloqueando novas duplicações.
6. Depois, confira os totais e algumas NF-es/ativos. O ZIP é específico do formato Ativa, não um importador genérico.

A restauração usa JSZip local, envia lotes autenticados diretamente ao próprio domínio e não encaminha os dados a terceiros.

## 6. Backup e manutenção

- Após mudanças importantes, uma conta com `export_data` baixa **Painel de controle → Backup completo → Exportar backup (.zip)** e armazena-o fora do diretório público.
- O ZIP contém ativos, baixas, notas, itens, catálogos, histórico de exclusões, XMLs disponíveis, CSVs e Excel. O motivo de exclusão aparece apenas no histórico administrativo, não nas saídas de relatórios patrimoniais. Não é criptografado; trate-o como confidencial.
- Atualize arquivos por FTP/File Manager sem substituir `config.php`; mantenha cópia do banco antes de mudanças.
- Senhas devem ser fortes e exclusivas. HTTPS e logout ao encerrar a sessão; não há recuperação por e-mail.
- As baixas são lógicas e preservadas. A exclusão de ativo remove somente o registro patrimonial e sua reserva de códigos; NF-e, itens e XML original ficam intactos. Motivo, ativo de referência, usuário e data permanecem em histórico administrativo separado, sem entrar nos relatórios. O sistema não consulta a SEFAZ nem calcula depreciação.

## 7. Tema visual

O visual segue o padrão Summa (menu azul-marinho `#172B4D`, azul de ação `#2878D0`); o botão **Aparência** do cabeçalho troca tema e tamanho do texto. O dashboard mantém o gráfico circular por conta patrimonial junto às barras de valor. O mesmo controle aparece no login e na instalação. Na primeira visita, o tema segue a preferência do navegador/dispositivo (Automático); as escolhas de tema e de tamanho do texto ficam salvas no navegador.

## 8. Diagnóstico rápido

| Sintoma | Conferir |
|---|---|
| A instalação não carrega | `config.php`, credenciais MySQL e extensões PHP; execute `schema.sql` pelo instalador de instalação nova. |
| Uma instalação existente mostra erro de tabela de permissões | Execute primeiro `upgrade-permissions.sql` em phpMyAdmin e só depois atualize os arquivos. |
| “Já existe uma conta administradora” | Entre com uma conta cadastrada; o instalador não cria usuários adicionais. |
| Erro ao conectar ao MySQL | Confira host, nome do banco, usuário e senha no painel. |
| Conta Patrimonial/local não aceita o valor | Para criar uma nova Conta Patrimonial durante a edição, confirme que a conta tem `manage_catalogs`; usuários sem essa permissão só escolhem valores existentes. Locais novos são cadastrados no Painel. |
| Usuário recebe 403 | A conta não tem a permissão necessária; peça ao gestor que ajuste o Painel de controle. |
| Download não inicia | Faça login novamente e confirme `export_data`. |
| Restauração recusada | O banco precisa estar vazio e a conta precisa de `restore_backup`, `edit_assets`, `edit_invoices` e `manage_catalogs`. |
| Página abre sem menu ou sem formatação | Envie a pasta `assets/` inteira, junto com `index.php`; acesse `https://SEU-DOMINIO/index.php` e não abra arquivos de `templates/` diretamente. Depois recarregue com `Ctrl+F5`. |
| XML não é aceito | Confirme PHP 8.1+, DOM/XML, modelo 55 e limite de 2 MB. |

## Relatórios personalizados e impressão

Em **Relatórios**, **Resumo patrimonial** aparece antes dos controles do **Relatório patrimonial** e acompanha os filtros aplicados na geração. O resumo é uma tabela por Conta Patrimonial com colunas de registros e valor, separadores somente horizontais e sem linhas verticais. Defina datas, status e classe (“Ativo imobilizado” ou “Bem de pequeno valor”), selecione as colunas e gere a prévia. A tabela detalhada possui rolagem interna e larguras ponderadas: Item recebe mais espaço; Patrimônio, Nota fiscal, data, valor e status ficam compactos. A prévia detalhada e a impressão exigem `export_data`.

Na prévia, a única ação visível é **Imprimir / Visualizar**; os botões separados para PDF e Excel foram removidos. No diálogo nativo do navegador ainda é possível escolher “Salvar como PDF”. Na impressão, o cabeçalho **Relatório patrimonial** com os filtros e dados de geração aparece na página 1 no lugar do título “Resumo patrimonial”, seguido pela tabela do resumo; a tabela detalhada começa na página 2. O aviso sobre a quantidade de colunas não aparece no documento. Filtros, colunas e orientação/margens A4 são preservados. Se o navegador acrescentar URL/data no cabeçalho ou rodapé, desative essa opção na janela de impressão para preservar espaço. O backup completo é baixado no **Painel de controle**, nunca na página de Relatórios.

## Referências técnicas

- InfinityFree — PHP no plano gratuito: <https://forum.infinityfree.com/t/to-this-q-can-i-install-node-js/93083>
- InfinityFree — Protected Directories: <https://forum.infinityfree.com/t/password-protect-a-page/88158>
- InfinityFree — proteção do site inteiro: <https://forum.infinityfree.com/t/protecting-htdocs-with-password/30494>
- PHP — PDO MySQL: <https://www.php.net/manual/en/ref.pdo-mysql.php>
- PHP — DOMDocument: <https://www.php.net/manual/en/class.domdocument.php>
- PHP — password_hash: <https://www.php.net/manual/en/function.password-hash.php>
- OWASP — autorização, privilégio mínimo e verificação por requisição: <https://cheatsheetseries.owasp.org/cheatsheets/Authorization_Cheat_Sheet.html>
- MDN — estilos para impressão: <https://developer.mozilla.org/en-US/docs/Web/CSS/Guides/Media_queries/Printing>
- MDN — tamanho, orientação e margens de `@page`: <https://developer.mozilla.org/en-US/docs/Web/CSS/Reference/At-rules/@page>
- MDN — evitar quebras internas em tabelas: <https://developer.mozilla.org/en-US/docs/Web/CSS/Reference/Properties/break-inside>
- MDN — algoritmo de largura de colunas `table-layout`: <https://developer.mozilla.org/en-US/docs/Web/CSS/Reference/Properties/table-layout>
- W3C — grupos de cabeçalho de tabelas e impressão: <https://www.w3.org/TR/CSS22/tables.html#table-display>
- MDN — rolagem da prévia com `overflow`: <https://developer.mozilla.org/en-US/docs/Web/CSS/Reference/Properties/overflow>
- MDN — impressão sem limite de rolagem: <https://developer.mozilla.org/en-US/docs/Web/CSS/Guides/Media_queries/Printing>
