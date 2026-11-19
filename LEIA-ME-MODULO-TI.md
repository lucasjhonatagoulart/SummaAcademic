# Summa · Setor de TI — pacote do módulo (1.0.1)

Este pacote coloca o Setor de TI como módulo do Summa, na pasta `htdocs/ti/` do mesmo site. Os dados ficam em um banco só do Setor de TI.

**Precisa do Summa 1.16.2 ou superior.** Versões anteriores recusam o módulo, porque reservavam o nome `ti` para o aviso "em breve".

## O que mudou da 1.0.0 para a 1.0.1
- **Sessão corrigida:** na 1.0.0 a página abria pelo Summa, mas as chamadas à API não reconheciam o usuário. Agora o módulo usa a sessão dele, separada da sessão do cliente do Summa.
- **Cliente do Summa incluído:** o `summa-client.php` já vem na pasta. Não é mais preciso copiar do kit.
- **Marca e foto:** aberto pelo Summa, o menu mostra a logo do Summa e o botão da conta mostra a foto de perfil.
- **Todos os módulos** leva ao Workspace do Summa. Antes, quem só tinha o TI liberado voltava para o próprio TI.
- **Sair** só vale com o código da sessão, então um link de outro site não desloga ninguém.

## 1. Enviar os arquivos
Envie a pasta `ti` inteira para dentro de `htdocs/` (ao lado do `index.php` do Summa). Fica `htdocs/ti/index.php`, `htdocs/ti/api.php` etc.

## 2. Banco de dados
O banco precisa estar na **mesma conta de hospedagem** do Summa. Na InfinityFree, um site não consegue usar o banco de outra conta.

- **O Setor de TI atual (tifacesm.free.nf) está na mesma conta do Summa:** copie para `htdocs/ti/config.php` os mesmos dados de banco do `config.php` do site atual. Os dados são mantidos. Não importe os arquivos SQL.
- **Está em outra conta, ou é instalação nova:** crie um banco MySQL **só para o TI** na conta do Summa (não use o banco do Summa: as tabelas `accounts` e `docs` colidiriam). Importe `banco/ti/schema.sql` e, para levar os dados, exporte o banco atual pelo phpMyAdmin e importe no novo. Os dados iniciais (`dados.sql`, com a conta do administrador e os computadores da planilha) ficam fora do repositório público; estão no pacote `Summa-TI-1.0.1.zip`. Depois preencha o `config.php`.

Copie `htdocs/ti/config.example.php` para `htdocs/ti/config.php` e preencha host, nome do banco, usuário e senha. É o único arquivo que você edita, e ele não vai para o repositório.

## 3. Incluir no Summa
Painel de controle › Módulos, informe a pasta `ti` e clique em **Ler manifesto e incluir**. O Summa gera o segredo e grava `htdocs/ti/summa.secret.php`. O aviso "Setor de TI · em breve" some e o card entra no Workspace.

Depois, em Usuários › Módulos e áreas, libere as áreas de cada pessoa:

| Área no Summa | Papel no Setor de TI |
|---|---|
| Solicitar | Abre chamados e vê só os próprios |
| Consulta | Vê inventário, chamados e manutenções, sem editar |
| Técnico | Atende chamados e edita computadores, redes, projetores e manutenções |
| Administrador | Tudo do técnico, com acesso total ao módulo |

Quem tem mais de uma área fica com a maior. A mudança vale na próxima vez que a pessoa abrir o módulo. Quem tem Acesso total no Summa entra como Administrador.

## Como fica o uso
- A conta local é criada ou atualizada sozinha, pelo e-mail, quando a pessoa abre o módulo. Nome e papel vêm do Summa.
- Com o Summa ativo, a tela de contas do módulo some e a senha não é trocada aqui: tudo é no Painel de controle do Summa.
- O menu da conta tem "Todos os módulos"; "Sair da conta" encerra o módulo e o Summa. Sessão parada por 2 horas expira.
- Sem `summa.secret.php` na pasta, o módulo funciona sozinho, com o login próprio de antes.
- Acesso de emergência com a conta local: `ti/index.php?local=1`.

## Conferir depois de enviar (não testado na hospedagem real)
1. Abrir o card com um administrador: o painel deve carregar os computadores e chamados.
2. Abrir com um usuário só com "Solicitar": ele vê só os próprios chamados.
3. Sair pelo módulo e conferir que o Summa também pediu login.

## Se algo der errado
- **"O slug ti já é usado pelo Summa":** o Summa ainda não é o 1.16.2. Envie a atualização do Summa antes.
- **"Banco de dados indisponível":** os dados do `config.php` estão errados, ou o banco é de outra conta de hospedagem.
- **Selo "Sem segredo na pasta" na tela do módulo:** abra o módulo no Painel de controle e clique em **Gravar na pasta**.
- **O painel abre vazio e volta para o login:** a pasta ainda tem arquivos da versão 1.0.0. Envie de novo a pasta `ti` inteira.

## Teste automatizado
`tests/ti.py` roda o módulo de ponta a ponta dentro do Summa, com um banco SQLite criado pelo próprio teste (26 verificações). Veja as instruções no topo do arquivo.
