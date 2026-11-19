# Módulos por integração no Summa

Como incluir no Workspace um sistema que fica em outra pasta do mesmo site, sem mexer no código do Summa. O módulo ganha um card, login único e as permissões que ele próprio declara.

Vale para módulos na **mesma hospedagem** do Summa (uma pasta ao lado do `index.php`). O Patrimônio já vem integrado de fábrica e não usa este cadastro.

## Em resumo

1. Coloque na pasta do módulo um manifesto `summa-module.json` e o arquivo `summa-client.php` (está em `kit-modulo/`).
2. Envie a pasta para `/htdocs/<pasta>/`.
3. No Summa, abra **Painel de controle › Módulos**, informe o nome da pasta e clique em **Ler manifesto e incluir**.
4. Libere o módulo para cada usuário em **Painel de controle › Usuários › Módulos e áreas**.

Para experimentar antes, envie `kit-modulo/modulo-exemplo/` para `/htdocs/modulo-exemplo/` e inclua a pasta `modulo-exemplo`.

## 1. O manifesto (`summa-module.json`)

```json
{
  "slug": "estoque",
  "name": "Estoque",
  "description": "Entradas, saídas e inventário do almoxarifado.",
  "icon": "boxes",
  "color": "teal",
  "entry": "index.php",
  "permissions": [
    { "code": "access", "label": "Entrar no módulo e consultar", "short": "Consulta" },
    { "code": "edit", "label": "Lançar entradas e saídas", "short": "Lançamentos" }
  ]
}
```

| Campo | Regra |
| --- | --- |
| `slug` | Identificador do módulo: 2 a 24 letras minúsculas, pode ter `_`, sem números nem hífen. **Não pode mudar depois**, porque identifica as permissões já liberadas. |
| `name` | Nome no card, de 2 a 40 caracteres. |
| `description` | Texto do card, até 200 caracteres. Opcional. |
| `icon` | Nome de um ícone do conjunto Lucide (ex.: `boxes`, `wrench`, `monitor-cog`). Padrão: `box`. |
| `color` | Cor do card: `azul`, `verde`, `ambar`, `violeta`, `teal`, `rosa` ou `cinza`. Padrão: `cinza`. |
| `entry` | Página de entrada, dentro da pasta do módulo. Padrão: `index.php`. |
| `permissions` | De 1 a 30 permissões. `code`: minúsculas, começando por letra (letras, números, `_` e `-`). `label`: o texto da chave na tela de usuários. `short`: rótulo curto em "Suas áreas" no card (opcional). |

Qualquer permissão liberada já faz o card aparecer para o usuário. Por isso, comece a lista com uma permissão de entrada (como `access`).

Slugs reservados: `academico`, `diretoria`, `financeiro`, `controle`, `patrimonio`, `admin`, `summa`, `modulo`, `workspace`, `login`. Um módulo que o Summa só anuncia como "em breve" (hoje, `ti`) pode ser incluído com esse slug: o aviso some e o card de verdade entra no lugar. A pasta não pode ser `app`, `assets`, `uploads`, `install` nem `tests`.

## 2. O código do módulo

Copie `kit-modulo/summa-client.php` para a pasta do módulo e chame no começo de cada página:

```php
require __DIR__ . '/summa-client.php';

$user = summa_require_login();     // ['id', 'email', 'name', 'avatar', 'perms' => ['access', ...]]

if (summa_can('edit')) {
    // mostra o botão de lançar
}
```

| Função | O que faz |
| --- | --- |
| `summa_require_login()` | Garante o login. Recebe o acesso que vem do Summa, abre a sessão do módulo e devolve o usuário. Sem sessão, manda a pessoa entrar pelo Summa. |
| `summa_require_perm('edit')` | O mesmo, e responde 403 se o usuário não tiver a permissão. Use nas telas e ações restritas. |
| `summa_can('edit', 'admin')` | Verdadeiro se o usuário tem alguma das permissões. |
| `summa_user()` | O usuário da sessão, ou `null`. |
| `summa_workspace_url()` | Endereço de "Todos os módulos". |
| `summa_logout()` | Sai do módulo e do Summa. Chame a partir de um formulário (POST), nunca de um link. |

**Se o módulo já tem sessão própria:** o cliente guarda o acesso em uma sessão só dele (`summa_mod_<slug>`). Pegue o usuário com `summa_require_login()`, chame `session_write_close()` e só então abra a sessão do módulo, informando o identificador dela com `session_id()` (o do cookie do módulo, ou um novo de `session_create_id()`). Sem isso o PHP reaproveita o identificador e as duas sessões se misturam.

Confira a permissão **no servidor**, em cada ação que grava ou apaga. Esconder o botão não basta.

**Marca:** para o módulo ter a cara da plataforma, use a logo do Summa no menu (`../assets/brand/logo-fundo-escuro.png` sobre fundo escuro, `logo-fundo-claro.png` sobre fundo claro) e o ícone `../assets/brand/icone.png` no menu recolhido.

O `modulo-exemplo/` mostra tudo isso em uma página, e traz um `.htaccess` que fecha ao navegador o segredo, o cliente e o manifesto. Copie-o também.

## 3. O que o Summa faz ao incluir

- Lê e confere o manifesto. Se algo estiver fora das regras, a tela diz o que corrigir e nada é gravado.
- Gera um segredo de 64 caracteres só para este módulo e grava `summa.secret.php` na pasta dele. Se o servidor não deixar gravar, a tela mostra o conteúdo para você criar o arquivo à mão.
- Passa a mostrar o módulo no Workspace e na tela de permissões dos usuários. Quem tem Acesso total vê o card na hora.

Na tela do módulo você pode:

- **Atualizar manifesto:** depois de editar o `summa-module.json`. Permissões que saírem do manifesto são retiradas dos usuários.
- **Desligar / Ligar:** some do Workspace e ninguém entra; as permissões dos usuários ficam guardadas.
- **Gerar novo segredo:** se o arquivo `summa.secret.php` tiver vazado. O anterior deixa de valer na hora.
- **Remover:** tira o card e apaga as permissões do módulo de todos os usuários. A pasta e os dados do módulo não são apagados.

Quem administra módulos precisa da permissão **Incluir e configurar módulos por integração** (área Painel de controle). Incluir um módulo é confiar no código daquela pasta: ele passa a receber nome, e-mail e permissões de quem o abre.

## 4. Como funciona o login único (referência)

1. O card abre `index.php?r=modulo.<slug>`. O Summa confere se o usuário tem alguma permissão do módulo e redireciona para `<pasta>/<entry>?sso=<token>`.
2. O token é `base64url(JSON) + "." + base64url(HMAC-SHA256(parte 1, segredo))`. O JSON traz:

| Campo | Conteúdo |
| --- | --- |
| `iss` | `"summa"` |
| `aud` | o slug do módulo |
| `sub` | id do usuário no Summa |
| `u`, `n` | e-mail e nome |
| `a` | caminho da foto de perfil dentro do Summa (`uploads/...`), ou vazio. No cliente PHP vira `$user['avatar']`, já com o endereço pronto para um `<img>`. |
| `p` | lista dos códigos de permissão liberados (como no manifesto) |
| `iat`, `exp` | emissão e validade (2 minutos) |
| `jti` | identificador único, 32 caracteres hexadecimais |

3. O módulo confere assinatura, destino (`aud`), validade e uso único (`jti`), abre a própria sessão e tira o token do endereço. O `summa-client.php` já faz tudo isso; os identificadores usados ficam na pasta `summa-data/`, criada sozinha e fechada ao navegador.
4. Mudanças de permissão no Summa valem a partir da próxima vez que a pessoa abrir o módulo. A sessão do módulo expira após 2 horas sem uso.
5. Para sair, o módulo redireciona para `index.php?r=modulo_sair.<slug>&t=<hora>&s=<assinatura>`, com `s = base64url(HMAC-SHA256("sair|" + t, segredo))`. O Summa só encerra a sessão com assinatura válida e recente (2 minutos).

Um módulo escrito em outra linguagem só precisa repetir os passos 3 e 5.

## 5. Se algo der errado

- **"Não encontrei o arquivo summa-module.json":** o manifesto não está na raiz da pasta informada.
- **"Manifesto: …":** a mensagem diz qual campo corrigir. Corrija e inclua de novo.
- **Selo "Sem segredo na pasta":** abra o módulo e clique em **Gravar na pasta**, ou crie o arquivo com o conteúdo mostrado.
- **"assinatura inválida" ao abrir:** o `summa.secret.php` da pasta não é o atual. Clique em **Gerar novo segredo**.
- **"Este módulo ainda não foi incluído no Summa":** a página foi aberta direto, sem o módulo estar cadastrado.
- **O card não aparece para um usuário:** falta liberar alguma permissão do módulo para ele, ou o módulo está desligado.
