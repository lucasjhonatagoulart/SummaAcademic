# Summa Educação — versão PHP + MySQL para InfinityFree



Sistema de gestão acadêmica: secretaria, diretoria, financeiro, professores e alunos.
Feito em PHP 8.1+, MySQL e JavaScript, sem Composer e sem Node.js, para rodar em hospedagem compartilhada (InfinityFree).

## O que tem no pacote

```
htdocs/          → tudo o que vai para o servidor (pasta /htdocs do InfinityFree)
  index.php      → entrada do sistema
  install/       → instalador (apague depois de instalar)
  app/           → código (protegido contra acesso direto)
  assets/        → CSS e JavaScript
  uploads/       → fotos e logotipos enviados
  patrimonio/    → módulo Patrimônio (sistema à parte, banco próprio)
  ti/            → módulo Setor de TI (por integração, banco próprio)
banco/ti/        → estrutura do banco do Setor de TI
kit-modulo/      → cliente e módulo de exemplo para novos módulos por integração
tests/           → testes (não envie para o servidor)
```

Guias: `LEIA-ME-INTEGRACAO.md` (Patrimônio e histórico de versões), `INTEGRACAO-MODULOS.md` (como criar e incluir módulos) e `LEIA-ME-MODULO-TI.md` (Setor de TI).

**O que não está no repositório:** os arquivos `config.php` (senhas dos bancos), os `*.secret.php` (segredos do login único), os arquivos enviados em `uploads/` e os dados iniciais do Setor de TI. Cada `config.php` tem um modelo ao lado (`config.example.php`) ou é gerado pelo instalador.

## Instalação no InfinityFree

### 1. Banco de dados
No painel do InfinityFree (client area › Control Panel › **MySQL Databases**), o banco já criado é:

| Campo | Valor |
|---|---|
| Servidor (host) | `sql200.infinityfree.com` |
| Banco | `if0_43405800_Academic` |
| Usuário | `if0_43405800` |
| Senha | a senha da conta de hospedagem (vPanel), que aparece em client area › Account › **Show password** |

### 2. Enviar os arquivos pelo FileZilla
1. Descompacte o `academus-php.zip` (o nome do arquivo continua o mesmo) no seu computador.
2. No FileZilla, à esquerda, abra a pasta `htdocs` que veio no zip.
3. À direita (servidor), entre em `/htdocs`. Apague o `index2.html` padrão do InfinityFree, se existir.
4. Selecione **todo o conteúdo** da pasta `htdocs` local (index.php, .htaccess, app, assets, install, uploads) e arraste para `/htdocs` do servidor.
   - Não envie a pasta `htdocs` em si: o `index.php` precisa ficar direto em `/htdocs/index.php`.
   - O `.htaccess` começa com ponto. Se não aparecer, no FileZilla marque *Servidor › Forçar exibição de arquivos ocultos*.
5. Espere a fila de transferência zerar, sem nenhuma falha.

### 3. Rodar o instalador
1. Abra `https://SEU-DOMINIO/install/` no navegador.
2. Os campos do banco já vêm preenchidos. Digite só a **senha do banco**.
3. O administrador vem preenchido: `admin@academus.dev`, senha `Aquinosys`. Pode trocar o e-mail.
4. **Dados de exemplo:** deixe marcado para testar com cursos, turmas, alunos e cobranças fictícios. Desmarque para começar com o sistema vazio.
5. Clique em **Instalar**.
   - Se o servidor não deixar gravar o `config.php`, o instalador mostra o conteúdo do arquivo. Copie, salve como `config.php` no seu computador e envie pelo FileZilla para `/htdocs`.

### 4. Apagar o instalador
No FileZilla, apague a pasta `/htdocs/install`. O sistema avisa se ela continuar lá.

### 5. Entrar
Abra `https://SEU-DOMINIO/` e entre com `admin@academus.dev` / `Aquinosys`.

Com os dados de exemplo, também existem (senha `Academus@2026`):

| Perfil | E-mail |
|---|---|
| Secretaria | secretaria@academus.dev |
| Diretoria | diretoria@academus.dev |
| Financeiro | financeiro@academus.dev |
| Professor | professor@academus.dev |
| Aluno | aluno@academus.dev |

## Envio de e-mail (senhas provisórias, recuperação, avisos)

O InfinityFree bloqueia o `mail()` do PHP, então o sistema envia por SMTP.
Configure em **Painel de controle › Instituição e e-mail › E-mail**, e use **Enviar teste** para conferir.

- **Gmail:** servidor `smtp.gmail.com`, porta `587`, segurança `TLS`, usuário = seu Gmail, senha = uma **senha de app** (Conta Google › Segurança › Verificação em duas etapas › Senhas de app).
- **Brevo** (grátis, 300 e-mails/dia): `smtp-relay.brevo.com`, porta `587`, TLS, usuário e chave SMTP do painel Brevo.

Atenção: o plano gratuito do InfinityFree pode bloquear conexões SMTP de saída. Se o teste falhar, o sistema continua funcionando: a senha provisória de cada usuário criado aparece na tela para o administrador repassar.

## Atualizar uma instalação existente

O banco de dados se atualiza sozinho no primeiro acesso depois de enviar os arquivos novos.

Envie de novo as pastas `app/`, `assets/` e `patrimonio/` (esta sem sobrescrever o `patrimonio/config.php`) (inclusive `assets/brand/`, com as logos) e o `index.php`. **Não** sobrescreva o `config.php` nem a pasta `uploads/`, e não envie a pasta `install/`.

## Marca (nome, slogan, cor e logos)

- Nome, nome completo, slogan, cor da marca e altura das logos: `htdocs/app/brand.php`.
- Logos e ícones: `htdocs/assets/brand/` — `logo-fundo-escuro.png` (menu lateral e tela de entrada), `logo-fundo-claro.png` (celular e instalador), `logo-branca.png`, `logo-preta.png`, `icone.png` (menu recolhido) e os favicons.
- Para trocar a logo, substitua os arquivos mantendo os mesmos nomes (PNG com fundo transparente).

## Workspace (plataforma Summa)

Depois de entrar, quem tem acesso a mais de um módulo vê o **Workspace**: um card para cada módulo liberado (hoje **Acadêmico**, **Diretoria**, **Financeiro**, **Compras**, **Painel de controle** e **Patrimônio**), com as áreas que a pessoa acessa e o botão *Abrir módulo*. Quem tem um só módulo (ex.: aluno, professor) entra direto nele.
Dentro de um módulo, *Todos os módulos* (no topo do menu lateral e no menu do perfil) volta ao Workspace.
A liberação fica em **Gerenciamento de usuários › Instituições e permissões › Módulos e áreas**: cada módulo tem a chave Liberado/Bloqueado e, ao clicar, mostra as áreas. Módulo bloqueado não abre, mesmo com áreas marcadas.
Os módulos são definidos em `PLATFORM_MODULES` (`app/perms.php`): nome, ícone, cores, áreas e portais. O Setor de TI já está listado como *em breve*.

**Módulos por integração:** em Painel de controle › Módulos é possível incluir no Workspace um sistema que está em outra pasta do site. Ele traz um manifesto (`summa-module.json`) com nome, ícone, cor e permissões; o Summa gera o segredo do login único e passa a oferecer as permissões na tela de usuários. O contrato e um módulo de exemplo estão em `INTEGRACAO-MODULOS.md` e `kit-modulo/`. O cadastro fica em `settings.ext_modules`, sem tabela nova.

O **Patrimônio** é um sistema à parte, na pasta `patrimonio/`, com banco próprio. O card abre a rota `index.php?r=patrimonio`, que entrega a ele um acesso assinado (login único). Para ligar, veja `LEIA-ME-INTEGRACAO.md`; enquanto `patrimonio_sso_secret` não estiver no `config.php`, o card aparece, mas avisa que o módulo não foi configurado.

## Compras (solicitações de compra)

Módulo nativo, no portal `comp` (`app/c/purchases.php`). Fluxo: **Rascunho → Enviada → Em cotação → Aguardando aprovação → Aprovada → Comprada → Recebida** (ou Recusada / Cancelada).

- **Nova solicitação:** título, setor, justificativa, prazo, prioridade, **local da compra** (Mercado Livre, Magalu, Amazon, Shopee, site do fornecedor, loja física ou outro; link para os sites), **forma de pagamento** (Pix, Boleto ou Cartão de crédito, com parcelas de 1x a 12x numa lista) e itens, cada um marcado como patrimoniável ou consumo.
- **Cotações:** até 3 por solicitação, com preço por item, frete, prazo, local e pagamento. A ficha mostra as cotações em cartões (com selos Menor preço e Menor prazo) e o botão *Exibir em tabela* para comparar item a item.
- **Aprovação única:** quem aprova escolhe a cotação vencedora. Recusar, ou escolher uma que não é a mais barata, exige motivo.
- **Recebimento:** número da NF-e, data e quantidade recebida por item. Se houver itens patrimoniáveis, aparece *Importar para o Patrimônio?* (Importar / Agora não; dá para enviar depois pela ficha).
- **Envio ao Patrimônio:** o Summa grava um arquivo em `patrimonio/entradas-compras/` (pasta bloqueada para a web) e o Patrimônio o importa ao abrir a Visão geral ou Notas fiscais, como **NF-e em rascunho** com fornecedor, número, quantidades e valores da cotação. Lá se define conta patrimonial, local e patrimônio e se incorpora. O mesmo envio nunca é importado duas vezes.
- **Permissões** (liberadas por usuário em Módulos e áreas › Compras): *Solicitar* (`purchase:request`, vê só as próprias), *Cotar e comprar* (`purchase:manage`), *Aprovar* (`purchase:approve`) e *Consulta* (`purchase:read`). Nenhum perfil pronto recebe Compras: o administrador libera para cada pessoa.
- Também tem Fornecedores (montado pelas cotações), Relatórios (por mês, por setor e por etapa), avisos no sino e registro na auditoria.

## Exportar para ERP (Omie)

Em **Financeiro › Exportar**, escolha o período e baixe a planilha Excel (.xlsx) com 4 abas: *Contas a Receber (Omie)*, *Recebimentos*, *Clientes (Omie)* e *Resumo*.
No Omie, as contas a receber entram pela **planilha padrão** (Finanças › Contas a Receber › Importar Planilha): copie as colunas da aba *Contas a Receber (Omie)* para as colunas de mesmo nome.
Categoria e Conta Corrente precisam estar escritas exatamente como no cadastro do Omie, e os clientes (alunos) precisam estar cadastrados lá antes.

## Segurança

- Senhas guardadas com `password_hash` (bcrypt). A conta é bloqueada por 15 minutos depois de 5 tentativas erradas.
- Toda gravação usa token CSRF. A sessão expira após 2 horas sem uso.
- `app/` e `config.php` não podem ser abertos pelo navegador (`.htaccess`).
- Os envios de imagem (fotos, logos e imagens de comunicados e avisos) são conferidos pelo conteúdo e renomeados.
- Toda alteração fica registrada na **Auditoria**.
- Ative o SSL grátis no painel do InfinityFree (*SSL Certificates*) e use `https://`.

## Testes (no seu computador, opcional)

Precisa de PHP 8.1+ com SQLite e de Python 3.

```bash
php tests/setup_local.php          # cria banco SQLite com dados de exemplo
php -S 127.0.0.1:8090 -t htdocs    # servidor local
php tests/run.php                  # regras de negócio
python3 tests/smoke.py admin@academus.dev Aquinosys admin.inicio admin.controle
python3 tests/flows.py             # fluxos completos
python3 tests/patrimonio.py        # módulo Patrimônio: card, liberação, login único e saída
python3 tests/modulos.py           # módulos por integração: incluir, liberar, login único, atualizar, remover
python3 tests/compras.py           # Compras: solicitar, cotar, aprovar, comprar, receber e enviar ao Patrimônio
php htdocs/patrimonio/tests/test_summa_purchases.php   # Patrimônio: importação das entradas de Compras
```

`setup_local.php` grava um `htdocs/config.php` de teste. Apague esse arquivo antes de enviar para o servidor.
