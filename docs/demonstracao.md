# Guia de demonstração

Este documento descreve **os dados que o ambiente prepara sozinho** e para que
serve cada um deles. Ele é iniciado junto do esquema do banco e cresce conforme
as funcionalidades chegam: o roteiro completo das duas jornadas, com a sequência
de telas, é escrito ao final da implementação.

Tudo aqui é ficcional e local. Não há dado pessoal, chave de produção ou segredo
real neste repositório.

## O que já existe

O banco completo, os dados de avaliação e a **autenticação**: as três contas
abaixo entram pela API, a sessão é mantida por cookie e cada perfil é reconhecido.

Ainda **não** existem telas nem endpoints de negócio — nenhum curso, aula ou
vídeo é criado ou consultado pela API neste momento. O que este documento
descreve é o ponto de partida sobre o qual essas funcionalidades serão
construídas.

## Endpoints disponíveis

| Método e rota | O que faz |
| --- | --- |
| `GET /sanctum/csrf-cookie` | Entrega o cookie de proteção que as operações seguintes exigem |
| `POST /api/auth/login` | Autentica e abre a sessão |
| `GET /api/auth/me` | Devolve quem está autenticado na sessão atual |
| `POST /api/auth/logout` | Encerra a sessão |

A API responde em `http://localhost:8080`.

Três detalhes do comportamento, úteis para quem for avaliar:

- **Uma operação que altera estado exige o cookie de proteção.** Sem ele, a
  resposta é `419` com o código `CSRF_TOKEN_MISMATCH`, e nada é executado. É por
  isso que o primeiro passo é sempre buscar o cookie.
- **Credencial recusada responde sempre igual.** E-mail que não existe e senha
  errada produzem exatamente a mesma resposta `422`. A API não diz qual dos dois
  falhou, de propósito: dizer transformaria a tela de login num verificador de
  quem tem conta.
- **Sem sessão, a resposta é `401`**, que é diferente de `403`. O primeiro
  significa "entre de novo"; o segundo, "você está autenticado, mas este perfil
  não pode".

### Percorrendo o fluxo sem interface

Ainda não há tela de login. Para exercitar a jornada agora, qualquer cliente HTTP
que guarde cookies serve — importe a documentação da API quando ela existir, ou
use um cliente de linha de comando com um arquivo de cookies. A sequência é:
buscar o cookie de proteção, enviar o login com o valor desse cookie no cabeçalho
`X-XSRF-TOKEN`, consultar `GET /api/auth/me` e encerrar com `POST /api/auth/logout`.

## Como os dados são preparados

O serviço `setup` roda uma vez a cada subida do ambiente, aplica as migrations
pendentes e executa o seed:

```bash
make up
```

O seed **insere o que falta e não altera o que já existe**. Subir o ambiente de
novo não duplica registros nem desfaz alterações feitas durante uma
demonstração. A contrapartida é que ele prepara, mas não repara: para devolver o
cenário ao estado inicial depois de exercitá-lo, recrie o banco.

> **Atenção — comando destrutivo.** `migrate:fresh` **apaga todas as tabelas e
> todos os dados** do banco configurado antes de recriá-lo do zero. Use-o apenas
> no ambiente local de avaliação, e apenas quando a intenção for descartar tudo
> o que existe.

```bash
docker compose run --rm api php artisan migrate:fresh --seed
```

## Contas de avaliação

As três contas compartilham a mesma senha fictícia:

```
VideoDemo2026!
```

| E-mail | Perfil | Papel na demonstração |
| --- | --- | --- |
| `producer@video-platform.test` | produtor | Dono do curso da jornada principal e do cenário de falha |
| `consumer@video-platform.test` | consumidor | Tem acesso concedido ao curso da jornada principal |
| `other-producer@video-platform.test` | produtor | Existe para comprovar o isolamento entre produtores |

A senha aparece em texto puro aqui porque é uma credencial local e fictícia,
criada para a avaliação. No banco ela é gravada apenas como hash, e nenhuma
resposta da API a devolve.

Este é o único lugar onde as credenciais de demonstração estão documentadas; os
demais documentos apontam para cá em vez de repeti-las.

## Os três cenários preparados

O seed monta três situações independentes. Elas não se tocam de propósito:
exercitar uma não interfere nas outras.

### 1. Curso da jornada principal — começa vazio

**Fundamentos de Produção de Vídeo**, do produtor de demonstração, em `draft`,
com acesso já concedido ao consumidor.

O curso não tem nenhum módulo e nenhuma aula, e é assim que ele deve permanecer
até a demonstração começar. É esse vazio que a jornada preenche: criar o módulo,
criar a aula, enviar o vídeo, publicar e então consumir como o outro perfil.

A jornada parte de um curso já concedido porque não existe operação para
conceder acesso a um curso recém-criado — concessões são criadas apenas por
seed nesta entrega. A criação de um curso novo é demonstrada separadamente.

### 2. Curso de outro produtor — para provar isolamento

**Curso de Outro Produtor**, pertencente à terceira conta, sem concessão para o
consumidor de demonstração.

Serve de alvo real para verificar que um produtor não alcança o conteúdo de
outro e que o consumidor não vê cursos que não lhe foram concedidos.

### 3. Cenário dedicado de falha de processamento

Um curso **separado** — *Cenário de Falha de Processamento* —, também do
produtor de demonstração, com módulo e aula próprios e uma tentativa de vídeo
parada no estado `processing`.

O identificador da tentativa é fixo e documentado:

```
01936f1a-7c00-7a3e-9b7d-2f5c8e4a1d60
```

Ele é fixo justamente para que o roteiro possa citá-lo: um valor sorteado a cada
execução tornaria a demonstração irreproduzível. É um identificador fictício de
seed, não um segredo.

Três detalhes desse cenário:

- a tentativa está em `processing` porque é o único estado a partir do qual um
  callback de falha é uma transição válida;
- a aula aponta para essa tentativa e ainda não está publicada;
- **nenhum arquivo existe no storage** para ela, e não precisa existir — o
  callback de falha não lê o vídeo.

O curso da jornada principal não é tocado por esse cenário. Acionar a falha não
suja o caminho da demonstração de sucesso.

> **O comando que dispara a falha ainda não existe.** Ele é implementado junto
> do simulador de processamento, e este documento passa a descrevê-lo quando
> isso acontecer. Até lá, o que existe é o cenário preparado e esperando.

## Conferindo o cenário

Para ver o que foi preparado, sem depender de tela ou endpoint:

```bash
docker compose run --rm api php artisan db:table users
docker compose run --rm api php artisan db:table courses
docker compose run --rm api php artisan db:table video_attempts
```
