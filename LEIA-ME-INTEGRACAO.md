# Summa 1.16.2 com o módulo Patrimônio (1.9.2)

Este pacote é o Summa completo, já com o Patrimônio incluído como módulo. Não há mais ajuste manual de código: os únicos passos são enviar os arquivos e colocar o mesmo segredo nos dois `config.php`.

- **Onde fica:** o Patrimônio vai na pasta `patrimonio/` do site do Summa e continua com o banco MySQL dele. Nenhum dado é migrado.
- **Login único:** quem entrou no Summa abre o card **Patrimônio** e cai direto na Visão geral, sem digitar senha de novo.
- **Usuários e permissões:** passam a ser definidos no Summa, em Painel de controle › Usuários › Módulos e áreas.

## O que mudou em relação aos pacotes anteriores

**Summa 1.14.0 → 1.15.0**
- `app/perms.php`: área **Patrimônio** com sete permissões (`patrimony:*`) e o card do módulo ativo (antes "em breve").
- `app/auth.php`: o card aparece para quem tem qualquer permissão do Patrimônio.
- `app/c/patrimonio.php` (novo): rotas de entrada (login único) e de saída.
- `app/c/people.php`: o filtro "Período" da lista de alunos mostrava " período" sem o número; corrigido para "1º período", "2º período"...
- `tests/patrimonio.py` (novo) e segredo de teste em `tests/setup_local.php`.

**Summa 1.15.0 → 1.16.0**
- Módulos por integração: tela **Painel de controle › Módulos** para incluir no Workspace um sistema de outra pasta do site, com login único e permissões declaradas por ele. Veja `INTEGRACAO-MODULOS.md` e `kit-modulo/`.
- Nova permissão "Incluir e configurar módulos por integração" (quem tem Acesso total já recebe).
- Para atualizar de 1.15.0: envie `index.php` e a pasta `app/`. Não apague `app/patrimonio.secret.php` do servidor.

**Summa 1.16.1 → 1.16.2**
- Um módulo que o Summa só anunciava como "em breve" (o Setor de TI) pode ser incluído por integração com o mesmo nome; o aviso some e o card de verdade entra no lugar.
- Kit do módulo: a sessão do cliente não se mistura mais com a sessão própria do módulo.
- Para atualizar: envie `app/modules.php`, `app/perms.php` e `app/bootstrap.php`.

**Summa 1.16.0 → 1.16.1 e Patrimônio 1.9.1 → 1.9.2**
- O Patrimônio, como módulo, passa a mostrar a logo do Summa no menu e a foto de perfil do usuário no cabeçalho (a foto vai no acesso do login único).
- Lista de ativos com ações em ícones (abrir, editar, excluir); excluir pede o motivo em uma janela.
- Depois de editar ou excluir um ativo, o sistema volta para a lista, nos mesmos filtros.
- Para atualizar: envie `app/c/patrimonio.php`, `app/c/modules.php` e `app/bootstrap.php` do Summa e as pastas `app/`, `assets/` e `templates/` do Patrimônio. Não apague os arquivos `.secret.php` nem o `patrimonio/config.php`.

**Patrimônio 1.9.0 → 1.9.1**
- A saída passa a levar uma assinatura; o Summa só encerra a sessão dele com ela. Antes, um link montado por outro site poderia deslogar o usuário em navegadores antigos.
- `tests/test_summa_login_db.php` (novo).

## Passo a passo

### 1. Faça um backup antes

No painel do InfinityFree, exporte os dois bancos: o do Summa e o do Patrimônio (`..._ativo`). Copie também os dois `config.php` atuais.

### 2. Envie os arquivos

Da pasta `htdocs/` do pacote para `/htdocs` do servidor:

- `index.php`, `app/` e `assets/` (substituem os atuais);
- `patrimonio/` (pasta nova).

**Não** envie `install/`, não sobrescreva o `config.php` do Summa nem a pasta `uploads/`.

**Configuração do Patrimônio:** copie o `config.php` do Controle Patrimonial que já está publicado para `/htdocs/patrimonio/config.php`. Ele contém os dados do banco `..._ativo`. Se ainda não existir, copie `config.example.php` para `config.php` e preencha.

### 3. Crie o segredo compartilhado

Gere um texto aleatório com 64 caracteres, por exemplo com `php -r "echo bin2hex(random_bytes(32));"` ou um gerador de senhas (só letras e números). O mesmo texto vai nos dois arquivos.

**`/htdocs/config.php` (Summa)** — acrescente dentro do `return [ ... ]`:

```php
'patrimonio_sso_secret' => 'COLE_AQUI_O_SEGREDO',
'patrimonio_url' => 'patrimonio/',
```

**`/htdocs/patrimonio/config.php` (Patrimônio)** — acrescente dentro do `return [ ... ]`:

```php
'summa' => [
    'secret' => 'COLE_AQUI_O_MESMO_SEGREDO',
    'url' => '../',
],
```

Enquanto o bloco `summa` não existir, o Patrimônio funciona sozinho, como antes, com o nome "Controle Patrimonial".

**Sem editar os `config.php`:** o segredo também pode ficar em dois arquivos à parte, que o sistema lê quando as chaves acima não existem: `app/patrimonio.secret.php` no Summa (chaves `patrimonio_sso_secret` e `patrimonio_url`) e `patrimonio/app/summa.secret.php` no Patrimônio (chaves `secret` e `url`). As duas pastas `app/` já são bloqueadas para acesso direto. Ao atualizar o sistema, não apague esses dois arquivos.

### 4. Teste

1. Entre no Summa com o administrador. O card **Patrimônio** aparece no Workspace. Clique em **Abrir módulo**.
2. Você deve cair na Visão geral do Patrimônio. O menu lateral mostra **Summa · Patrimônio** e **Todos os módulos**.
3. Em Painel de controle › Usuários, abra um usuário de teste, ligue o módulo **Patrimônio** e marque só "Entrar no Patrimônio". Ele consegue consultar, mas não edita.
4. **Sair**, dentro do Patrimônio, encerra também a sessão do Summa e volta ao login.

## Permissões

Quem tem "Acesso total (Administrador)" recebe todas automaticamente.

| No Summa | O que libera | No Patrimônio |
| --- | --- | --- |
| patrimony:access | Entrar e consultar notas, ativos e relatórios | só consulta |
| patrimony:invoices | Cadastrar, revisar e incorporar NF-e | edit_invoices |
| patrimony:assets | Editar ativos e registrar baixas | edit_assets |
| patrimony:catalogs | Gerenciar contas patrimoniais e locais | manage_catalogs |
| patrimony:export | Exportar relatórios, planilhas, XML e backup | export_data |
| patrimony:admin | Excluir ativos, cadastrar ativo sem NF-e, ver usuários do módulo | manage_users |
| patrimony:restore | Restaurar backup em uma base vazia | restore_backup |

Qualquer uma delas já faz o card aparecer e deixa a pessoa entrar para consultar.

## Como funciona o login único

1. **Geração do token:** a rota `index.php?r=patrimonio` confere se o usuário tem alguma permissão `patrimony:*` e gera um token assinado com HMAC-SHA256. Ele vale 2 minutos, só pode ser usado uma vez e leva o e-mail, o nome e as permissões.
2. **Conferência:** o Patrimônio confere a assinatura, a validade, o destino e se o token já foi usado (tabela `summa_sso_used`, criada sozinha).
3. **Conta local:** o Patrimônio cria ou atualiza uma conta com o e-mail do Summa e substitui as permissões dela pelas que vieram. Mudanças feitas no Summa valem a partir da próxima vez que a pessoa abrir o módulo.
4. **Inatividade:** a sessão aberta pelo Summa expira após 2 horas sem uso, como no Summa.

**Contas locais antigas** do Patrimônio continuam existindo como acesso de emergência, pelo endereço `patrimonio/index.php?page=login&local=1`. No modo módulo não é possível criar usuários nem mudar permissões dentro do Patrimônio. Se não forem mais usadas, troque as senhas dessas contas para algo forte.

## Se algo der errado

- **"O módulo Patrimônio ainda não foi configurado":** falta `patrimonio_sso_secret` no `config.php` do Summa, ou ele tem menos de 32 caracteres.
- **"assinatura inválida":** os segredos dos dois `config.php` estão diferentes.
- **"O acesso pelo Summa expirou":** a página demorou mais de 2 minutos. Abra o módulo de novo.
- **Sair do Patrimônio volta ao Workspace em vez do login:** os segredos estão diferentes ou o Patrimônio ainda é o 1.9.0.
- **Para desligar a integração:** apague o bloco `summa` do `patrimonio/config.php`. O sistema volta a ser o Controle Patrimonial independente; o card do Summa passa a mostrar erro ao abrir.
