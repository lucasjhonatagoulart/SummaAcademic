# TODO — Correções locais do Controle Patrimonial PHP

**Estado:** pacote 1.5.0 entregue anteriormente; ajustes 1.6.0, correção 1.7.0 e padronização visual 1.8.0 concluídos apenas na cópia PHP local. Nenhum ZIP novo, envio ao domínio ou acesso ao banco hospedado foi realizado nesta rodada.

## 1. Port PHP independente — Concluído
- Sistema PHP 8.1+/MySQL para hospedagem compartilhada InfinityFree, sem Node.js no servidor, Docker ou Composer.
- Primeiro administrador pode ser criado em `/install.php` apenas antes da existência de usuários; novas contas são criadas no Painel e começam somente para leitura.
- A cópia PHP permanece separada do projeto React original.

## 2. NF-e e controle patrimonial — Concluído
- Importar XML modelo 55, preservar arquivo e descrições fiscais originais e permitir revisão por item.
- Só incorporar XML autorizado com `cStat` 100 ou 150; o sistema não consulta a SEFAZ.
- Pesquisar/editar/baixar ativos sem apagar histórico; uma unidade incorporada exige um código patrimonial por unidade.
- Usar contas patrimoniais e locais cadastrados, com validação no servidor.

## 3. Segurança e permissões por usuário — Concluído
- Capacidades individuais para editar/incorporar NF-e, editar/baixar ativos, gerenciar contas patrimoniais/locais, gerenciar usuários, exportar dados e restaurar backup.
- Conferir permissões em páginas, POST direto, CSRF, APIs e downloads; conta sem capacidade adicional permanece somente leitura.
- Gestor só concede o que possui, não altera a própria conta e não pode remover o último gestor.
- Restauração exige também as três capacidades de escrita correspondentes; migração existente não ressuscita capacidades revogadas.

## 4. Painel de controle e catálogos — Concluído
- Reunir contas patrimoniais, locais, criação de usuários e edição de permissões no Painel de controle.
- Mostrar somente áreas permitidas à conta; telas de NF-e usam padrões cadastrados. Na edição de ativo, somente quem possui `manage_catalogs` pode criar uma nova Conta Patrimonial junto com o salvamento; novos Locais e demais padrões continuam sendo cadastrados no Painel.
- Permitir renomear uma conta patrimonial ou um local pela capacidade existente `manage_catalogs`; atualizar dentro de uma transação as referências em ativos e itens de NF-e, sem alterar o XML original. Rejeitar nomes conflitantes, não mesclá-los durante a renomeação e manter a ação de exclusão protegida quando o padrão estiver em uso.
- A exportação do backup completo fica no card de Backup do Painel, disponível apenas a quem possui `export_data`.

## 5. Contas patrimoniais e patrimônio histórico — Concluído conforme autorização
- A edição de ativo mantém o valor atual, inclusive valores históricos fora do catálogo. Contas sem `manage_catalogs` selecionam uma opção existente; gestores de catálogo podem escolher uma sugestão ou digitar uma nova Conta Patrimonial para inclusão na mesma transação da edição.
- Em ativos atuais e importados da planilha, permitir editar classificação entre Ativo Fixo/Bem de pequeno valor, Conta Patrimonial, Local, patrimônio, nome, data/ano de aquisição, quantidade, valor, vida útil, marca, cor, tipo, condição e observações. Na edição, Local pode ficar em branco ou ser removido; nome e Conta Patrimonial continuam obrigatórios. Um Local preenchido deve estar no catálogo ou ser o valor histórico atual. Manter disponíveis os atalhos Editar na lista/detalhe para quem tem `edit_assets`.
- Persistir as alterações no registro patrimonial; para ativos ligados a NF-e ou importados, não reescrever NF-e, XML, descrição original ou metadados da planilha. Ativos diretos sem NF-e podem atualizar a própria descrição. Aceitar somente classes permitidas e continuar bloqueando patrimônio duplicado sem impedir a manutenção dos valores históricos atuais.
- Unificar apenas `Energia Fotovoltaico` e `Sistema Fotovotaico` sob **Sistema Fotovoltaico**, conforme autorização da usuária; não agrupar outros rótulos.
- Preservar todos os ativos e códigos de patrimônio históricos, inclusive duplicados/ausentes, permitir edição de legados e bloquear novas atribuições duplicadas.
- Prévia de restauração apresenta apenas contagens agregadas; não expõe códigos individuais.

## 6. Tema e dashboard — Concluído
- Identidade azul `#003566`, alternância claro/escuro persistente e suporte à preferência do dispositivo.
- No gráfico circular, usar a paleta aprovada `#8ECAE6`, `#219EBC`, `#023047`, `#FFB703` e `#FB8500`, mantendo a legenda e pequenos separadores. `Móveis e Utensílios` usa `--donut-3` (`#023047`) igualmente nos temas claro e escuro; uma conta em branco, exibida como `Sem conta patrimonial`, usa `--donut-5`; `Outras contas patrimoniais` usa `--green-dark`, a cor da barra do menu (`#003566` no tema claro e `#081421` no escuro). O mesmo mapa determina a cor da fatia e da legenda. Preservar as proporções por quantidade de registros e o gráfico de barras por valor.

## 7. Fluxo NF-e/XML alinhado à origem — Concluído na cópia PHP local
- Cadastro manual ou importação XML com editor em três etapas: Dados da nota, Classificação dos itens e Revisão.
- Upload XML por clique/arrastar-soltar até 2 MB; leitura do arquivo permanece temporária na sessão até a pessoa salvar rascunho ou registrar.
- Somente na importação XML, Patrimônio e Local podem ficar em branco; a Conta Patrimonial continua obrigatória para registrar. Se não houver códigos patrimoniais, salvar um registro agregado por item fiscal, preservando quantidade e valor total, e permitir completar Local/códigos depois na edição. No cadastro manual, manter Local e um patrimônio por unidade obrigatórios.
- Campos fiscais importados são protegidos e reconstruídos no servidor; a classificação por item e aplicação rápida de conta patrimonial/local continuam editáveis.
- Lista de notas com abas Todas/Rascunhos/Incorporadas; preservar parser, autorização, CSRF e regra `cStat`.
- O cabeçalho global mantém apenas o seletor de tema. Em Notas Fiscais, retirar os botões do cabeçalho e reunir cadastro/importação em uma ação única no rodapé; colocar **Exportar NF-es** depois da lista, paginação e aviso, mantendo guardas `edit_invoices`/`export_data`.

## 8. Relatório patrimonial — Concluído na cópia PHP local
- Selecionar filtros de período, status do ativo e classe: Ativo imobilizado, Bem de pequeno valor ou Todos.
- Selecionar os campos disponíveis do relatório; a prévia na tela é uma tabela/lista com os nomes dos campos no cabeçalho e os itens nas linhas. **Conta Patrimonial** é o rótulo usado nos filtros, cabeçalhos, resumo e saídas legíveis.
- Manter a largura da coluna Item maior; patrimônio, NF-e, data de aquisição, valor e status compactos. A prévia tem altura limitada e rolagem interna; a impressão remove o limite e mantém margens/orientação A4.
- O resumo se chama **Resumo patrimonial** e aparece antes dos controles do relatório. Não exibir a linha descritiva de status/classe/período no resumo. Em telas amplas, manter os rótulos e os valores inline: **Total de registros** à esquerda e **Valor Total (R$)** com o valor monetário na extremidade direita, na mesma linha. Em celular, manter os dois grupos lado a lado e ajustar a disposição interna sem quebrar a quantia. Usar a mesma fonte e tamanho para os dois valores numéricos. Substituir o gráfico por uma tabela com Conta Patrimonial, Registros e Valor Total (R$); mostrar somente divisórias horizontais entre as linhas, sem linhas verticais.
- Na impressão, o cabeçalho do Relatório patrimonial (marca, título, filtros, período, data, orientação e quantidade de campos) substitui “Resumo patrimonial” no topo da página 1; abaixo dele ficam os KPIs e a tabela do resumo, e os itens detalhados começam na página 2. Ocultar o cabeçalho interno do relatório detalhado na impressão para não repetir o cabeçalho da página 1; manter os cabeçalhos internos quando a página é exibida na tela. Remover a frase “Este relatório contém X colunas...”. O resumo usa os mesmos registros após status, classe e período; espaçamento e fonte de suas linhas se ajustam ao número de contas patrimoniais para não omitir grupos. A tipografia ampliada se aplica somente ao cabeçalho e resumo da primeira página. Preservar orientação/margens A4 e a tipografia das páginas detalhadas.
- Expor apenas **Imprimir / Visualizar**; remover da interface os botões separados de PDF e Excel. Endpoints mantidos no servidor continuam protegidos por `export_data`. O backup completo não aparece em Relatórios.

## 9. Cards do Painel como entrada para seções — Concluído localmente
- A página inicial do Painel mostra cartões responsivos, com identidade `#003566`, foco visível e somente para áreas autorizadas.
- Contas Patrimoniais/Locais, Usuários/Permissões e Backup aparecem como blocos de acesso rápido; formulários e detalhes não são mostrados juntos nessa tela.

## 10. Cards abrem uma seção por vez — Concluído localmente
- Ao clicar em um card, abrir uma view PHP própria com somente as opções relacionadas àquele bloco e um link com o rótulo “Voltar”.
- Acesso a `backup`, `catalogs` e `users` é validado por permissão no servidor, inclusive ao digitar a URL diretamente; não carregar dados de outras seções.
- POSTs de cadastro/edição e erros retornam à seção apropriada, mantendo os formulários e ações existentes.
- Os cartões de Contas Patrimoniais e Locais têm a mesma altura em telas largas e estreitas, sem perder a rolagem interna. A importação de backup saiu do cabeçalho global e continua no Painel, visível somente com a combinação autorizada de permissões.

## 11. Documentação e distribuição — Documentação atualizada localmente; distribuição pendente
- README, plano, TODO, versão e guia InfinityFree atualizados para os fluxos e para a nomenclatura exibida **Conta Patrimonial**.
- Mudanças são exclusivamente locais; nenhum `config.php`, esquema ou registro hospedado foi alterado. Não gerar outro ZIP nem publicar até novo pedido após revisão.

## 12. Refinamentos responsivos das subviews e do resumo — Concluído na cópia PHP local
- Em Contas Patrimoniais/Locais, Usuários/Permissões e Backup, não repetir em um segundo cabeçalho o kicker e o título que já identificam a seção no topo. Manter a descrição interna sem o cartão/título repetido e rotular o retorno apenas “Voltar”.
- As listas de Contas Patrimoniais e Locais têm rolagem interna, com altura visual aproximada de sete linhas (principalmente a lista de Locais), mantendo cabeçalho legível e acesso por teclado/touch.
- Cards, cabeçalhos, formulários, tabelas e controles das seções se ajustam à tela sem remover operações ou afrouxar permissões.
- No resumo por Conta Patrimonial, exibir linhas tabulares apenas com separadores horizontais; o valor monetário permanece alinhado à direita, sem divisórias verticais.

## 13. Nomenclatura Conta Patrimonial — Concluído na cópia PHP local
- Em toda a interface, usar “Conta Patrimonial/Contas Patrimoniais” nos rótulos do Painel/cadastro, ativos, NF-e, dashboard, filtros, relatórios, backup/restauração e cabeçalhos legíveis das planilhas/CSV.
- Atualizar também mensagens de validação e a documentação voltada à usuária. Não renomear `category`, `categories`, chaves do banco/API/JSON, nomes de campos, seletores técnicos, funções, permissões, payloads de backup nem valores históricos/cadastrados.
- Não alterar o esquema, fazer migração ou reescrever qualquer registro para cumprir esta troca de nomenclatura.

## 14. Cadastro direto de ativo sem NF-e — Concluído na cópia PHP local
- A lista de Ativos mostra **Cadastrar ativo sem NF-e** apenas a contas com `manage_users` e `edit_assets`; as mesmas duas permissões são exigidas no GET e no POST.
- Criar um ativo sem linha em `invoices`/`invoice_items`, sem NF-e fictícia: `invoiceNumber=''`, `invoiceId=NULL`, origem interna explícita e uma unidade com exatamente um código patrimonial único. Descrição, Conta Patrimonial, Local e classe são obrigatórios; data, valor, vida útil, marca, tipo, cor, estado e observações podem ser informados quando disponíveis.
- A lista/detalhe oferecem **Editar sem NF-e** para ativos sem vínculo fiscal. O mesmo formulário permite alterar descrição, classe, Conta Patrimonial, Local (opcional), patrimônio, data, quantidade, valor, ano de compra, vida útil, marca, tipo, cor, estado e observações. A edição mantém `invoiceNumber=''` e `invoiceId=NULL`; a descrição de cadastro direto é sincronizada no campo de apresentação, sem mexer nos dados de origem de ativos com NF-e ou importados.
- Mostrar “Sem NF-e” na lista/detalhe de ativos, prévia/impressão/Excel do relatório e exports CSV/XLSX legíveis. Preservar strings vazias/nulas no backup JSON para restauração fiel.
- A gravação do cadastro usa transação para gravar ativo e reserva do patrimônio; validação de duplicidade consulta também registros legados.

## 15. Ações da página de Notas Fiscais — Concluído na cópia PHP local
- Manter a barra global apenas com o controle de tema.
- Exibir **Cadastrar / Importar NF-e** e **Exportar NF-es (.xlsx)** imediatamente antes da listagem de notas, para que não seja necessário rolar até o fim da página.
- Mostrar cadastro/importação somente com `edit_invoices` e exportação somente com `export_data`; permitir que as ações se adaptem a telas estreitas sem transbordamento.

## 16. Padronização visual pelo Summa (1.8.0) — Concluído na cópia PHP local
- Layout, cores, disposição e fontes seguem o Summa: tokens em `assets/theme.css`, estrutura e componentes em `assets/app.css`, peças comuns em `app/ui.php`.
- Substitui as decisões anteriores de identidade `#003566` (itens 6 e 9) e do alternador de tema com dois estados; a paleta do gráfico circular foi mantida.
- Nome e logo removidos: a interface mostra somente “Controle Patrimonial” (`APP_TITLE`). Chaves internas `ativa-*` (sessão, localStorage, formato do backup) foram mantidas de propósito.
- Pendências: unificar os nomes de classes com os do Summa (`.btn`, `.card`); cabeçalho CSP (exige tirar o `onsubmit` de `templates/catalogs.php`); dividir `app/actions.php`; no tema escuro, a fatia azul-marinho do gráfico circular tem pouco contraste; conferir as telas com dados reais no navegador.

**Validação local:** 43 arquivos PHP passaram em `php -l`. Passaram as suítes de domínio, permissões, seleção de contas, edição de ativos (26 verificações sem banco), dashboard (20), fluxo NF-e/XML, validação do registro, cadastro direto sem NF-e (34), construtor de relatórios (29), Painel (40), navegação/ações de NF-e (15), tema, restauração, wizard e impressão (43). Os três CSS passaram no parser; todos os scripts JavaScript passaram em `node --check`. Em Chromium, prévias temporárias com 7 grupos fictícios filtrados geraram duas páginas tanto em A4 paisagem quanto retrato: o cabeçalho do relatório aparece no topo da página 1 em lugar de “Resumo patrimonial”, todas as linhas da tabela de resumo ficam abaixo dele, e o detalhamento começa na página 2. A contagem não se repete no cabeçalho e o aviso sobre quantidade de colunas não aparece no PDF. Os KPIs foram conferidos de 320 a 1405 px; as ações de NF-e aparecem antes da listagem e foram verificadas em prévias de 1440 px e 390 px, sem transbordamento; os cartões de Contas Patrimoniais e Locais tiveram alturas idênticas no Chromium em desktop e sob o breakpoint móvel. Nenhum dado de teste foi gravado no banco ou incluído na aplicação. Não houve publicação, alteração de hospedagem nem geração de ZIP.

## 16. Importação do pacote de identidade visual — Concluído na cópia PHP local
- Colocar as variantes fornecidas e o símbolo em uma pasta organizada sob `assets/brand/`; os cinco arquivos originais (SVG/PNG claro e escuro, mais símbolo PNG) permanecem copiados sem transformação.
- Na marca da sidebar/cabeçalho escuro, usar `ativa-logo-escuro.svg`, com dimensões responsivas e texto alternativo exato `ativa. — Controle Patrimonial`; manter o restante do layout e das interações. A variante clara e o símbolo isolado permanecem disponíveis.
- Atualizar o cache-busting do CSS principal e não enviar arquivos à InfinityFree nem alterar banco, domínio, deploy ou publicação.
**Validação local:** os cinco arquivos coincidem byte a byte com os fornecidos; ambos os SVGs são XML válidos e não contêm `script`/`foreignObject`; 43 arquivos PHP passam no lint; 10 suítes PHP, 4 suítes JavaScript, `node --check` em 5 arquivos JavaScript e o parser dos 3 CSS passam. Nenhum banco foi acessado.


## 17. Edição administrativa de Conta Patrimonial e exclusão auditada — Concluído na cópia PHP local
- Corrigir o erro “Selecione uma conta patrimonial já cadastrada. Para acrescentar outra, use o Painel de controle.” Para contas com `edit_assets` e `manage_catalogs`, permitir escolher uma sugestão existente ou digitar a nova Conta Patrimonial na edição; cadastrar um valor novo e salvar o ativo na mesma transação. Contas sem `manage_catalogs` continuam limitadas às opções já cadastradas.
- Permitir excluir permanentemente um ativo somente a quem possui simultaneamente `manage_users` e `edit_assets`, com confirmação explícita e campo obrigatório para o motivo. Verificar autorização no servidor e CSRF no POST.
- Apagar somente o registro do ativo e suas reservas de patrimônio; preservar NF-e, itens fiscais e XML original. Registrar em tabela separada o identificador e resumo do ativo, o motivo, o administrador (ID e nome) e a data/hora.
- Exibir os registros de exclusão em uma página administrativa protegida. Não incluir motivo nem dados da trilha de auditoria nos relatórios patrimoniais, suas colunas, filtros ou arquivos de exportação. Preservar o histórico no backup completo e na restauração; aceitar backups anteriores sem o arquivo de auditoria.
- Atualizar `schema.sql` e criar migração aditiva para instalações existentes; apenas entregar o SQL localmente, sem executá-lo, alterar `config.php`/banco hospedado, gerar ZIP ou publicar. **Validação local:** lint em 44/44 arquivos PHP, 12/12 suítes PHP, 4/4 suítes JavaScript, sintaxe em 9/9 scripts, parser CSS 3/3 e DDL do schema idêntico à migração. Nenhum banco foi acessado.

## 17. Simulação ponta a ponta (1.8.0) — Concluída fora do projeto
- [x] Fluxo completo em navegador sobre cópia com SQLite no lugar do MySQL: instalação, entrada, catálogos, NF-e por XML (3 etapas), ativos (editar, baixa, cadastro sem NF-e, patrimônio duplicado), relatório em PDF, downloads (Excel, backup, XML), Aparência, menu recolhido e gaveta no celular, saída e rota protegida.
- [x] Corrigido: coluna Patrimônio vazia em "Ativos recentes" (consulta sem o campo).
- [ ] Com texto "Grande" em telas de ~1440 px, a tabela "Ativos recentes" ganha rolagem horizontal; avaliar empilhar as colunas da Visão geral nesse tamanho.
- [ ] Repetir a verificação em MySQL real (InfinityFree), pois a simulação não exercita travas GET_LOCK, FOR UPDATE nem o ON DUPLICATE KEY real.

## 18. Módulo do Summa (1.9.0)
- [x] Login único por token assinado (HMAC-SHA256, 2 min, uso único) e sincronização de permissões a cada entrada.
- [x] Menu "Summa · Patrimônio", "Todos os módulos", títulos "Tela · Summa Patrimônio", saída encerrando também o Summa.
- [x] Usuários do módulo somente leitura no modo módulo; conta local de emergência em ?page=login&local=1.
- [ ] Aplicar os 3 ajustes do Summa 1.14.0 (perms.php, PLATFORM_MODULES, user_modules) — pendente do código do Summa 1.14.0.
- [ ] Testar no InfinityFree real com os dois bancos.

