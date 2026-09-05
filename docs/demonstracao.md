# Guia de demonstração

Este documento descreve **os dados que o ambiente prepara sozinho** e para que
serve cada um deles. Ele é iniciado junto do esquema do banco e cresce conforme
as funcionalidades chegam: o roteiro completo das duas jornadas, com a sequência
de telas, é escrito ao final da implementação.

Tudo aqui é ficcional e local. Não há dado pessoal, chave de produção ou segredo
real neste repositório.

## O que já existe

O banco completo, os dados de avaliação, a **autenticação** e o **catálogo do
produtor inteiro**: as três contas abaixo entram pela API, a sessão é mantida por
cookie, cada perfil é reconhecido, e um produtor autenticado já cria, lista e
consulta os próprios cursos, organiza módulos e aulas dentro deles e lê a
estrutura completa do curso de uma vez — sem alcançar nada de outro produtor.

Ainda **não** existem telas, nem envio de vídeo, publicação ou catálogo do
consumidor. O que este documento descreve é a árvore do conteúdo funcionando de
ponta a ponta: regra de negócio, caso de uso, persistência e API.

## Endpoints disponíveis

| Método e rota | O que faz |
| --- | --- |
| `GET /sanctum/csrf-cookie` | Entrega o cookie de proteção que as operações seguintes exigem |
| `POST /api/auth/login` | Autentica e abre a sessão |
| `GET /api/auth/me` | Devolve quem está autenticado na sessão atual |
| `POST /api/auth/logout` | Encerra a sessão |
| `POST /api/courses` | Cria um curso do produtor autenticado |
| `GET /api/courses` | Lista, paginados, os cursos do produtor autenticado |
| `GET /api/courses/{course}` | Devolve um curso do próprio produtor |
| `POST /api/courses/{course}/modules` | Cria um módulo no fim do curso |
| `GET /api/courses/{course}/modules` | Lista os módulos do curso, na ordem |
| `POST /api/modules/{module}/lessons` | Cria uma aula no fim do módulo |
| `GET /api/lessons/{lesson}` | Devolve uma aula, com o estado do vídeo dela |
| `GET /api/courses/{course}/structure` | Devolve o curso com módulos e aulas, ordenados |

A API responde em `http://localhost:8080`.

Todas as rotas de catálogo exigem sessão válida **e** perfil de produtor. Um
consumidor autenticado recebe `403`.

Cinco detalhes do comportamento, úteis para quem for avaliar:

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
- **Curso de outro produtor responde `404`, e não `403`.** A resposta é idêntica
  à de um curso que nunca existiu — mesmo status, mesmo cabeçalho, mesmo corpo.
  Um `403` confirmaria que aquele identificador existe, e quem percorresse uma
  lista de identificadores obteria o catálogo alheio sem nunca ver o conteúdo.
- **A lista e a contagem também respeitam o isolamento.** O `meta.total` de um
  produtor não inclui cursos de ninguém mais: o recorte por dono acontece na
  consulta ao banco, antes de contar e paginar. Sem isso, o número sozinho já
  revelaria quantos cursos os outros produtores têm.

### Cursos: o que esperar

O curso nasce em `draft` e recebe um identificador gerado pelo backend. Título e
descrição vêm do corpo da requisição; **proprietário, estado, identificador e
data de criação não** — eles são definidos pelo servidor, e enviá-los no corpo
não altera nada.

A listagem devolve 15 itens por página por padrão. `per_page` acima de 50 é
atendido e limitado a 50; `per_page` zero, negativo ou não numérico é recusado
com `422`. A ordem é do curso mais recente para o mais antigo.

### Módulos e aulas: o que esperar

**A posição é do servidor, e o formulário nem tem esse campo.** Um módulo novo
entra no fim do curso, uma aula nova entra no fim do módulo, e a posição é sempre
a próxima livre: 1, 2, 3, 4. Enviar `position` no corpo não muda nada — nem
mesmo pedir a posição 1 quando ela já está ocupada. Nenhum item já criado é
deslocado.

Cada pai tem a sua própria sequência. Dois cursos começam do 1 cada um; dois
módulos do mesmo curso também.

**A aula nasce rascunho e sem vídeo.** `published_at` e `video_state` vêm nulos, e
enviá-los no corpo não tem efeito. `video_state` passa a refletir o estado real da
tentativa de vídeo quando ela existir — publicação e envio chegam nas próximas
etapas.

**A estrutura é uma leitura só e não é paginada.** `GET /api/courses/{course}/structure`
devolve o curso, seus módulos e as aulas de cada módulo, todos na ordem de
posição, incluindo os rascunhos. Paginar uma árvore quebraria a ordem que ela
existe para preservar. Curso sem módulos devolve `modules: []`; módulo sem aulas,
`lessons: []`.

**O isolamento vale na árvore inteira.** Criar módulo em curso de outro produtor,
criar aula em módulo alheio, consultar aula alheia ou pedir a estrutura de um
curso que não é seu: as quatro respondem o mesmo `404` de recurso inexistente. A
verificação do dono acontece dentro da consulta ao banco, atravessando aula →
módulo → curso → proprietário — o conteúdo alheio nunca chega a ser carregado.

### Percorrendo o fluxo sem interface

Ainda não há tela de login. Para exercitar a jornada agora, qualquer cliente HTTP
que guarde cookies serve — importe a documentação da API quando ela existir, ou
use um cliente de linha de comando com um arquivo de cookies. A sequência é:
buscar o cookie de proteção, enviar o login com o valor desse cookie no cabeçalho
`X-XSRF-TOKEN`, consultar `GET /api/auth/me` e encerrar com `POST /api/auth/logout`.

Com a sessão aberta, `POST /api/courses` cria um curso — também exigindo o
cabeçalho `X-XSRF-TOKEN`, por ser uma operação que altera estado — e
`GET /api/courses` lista o que aquele produtor tem. Em seguida,
`POST /api/courses/{course}/modules` e `POST /api/modules/{module}/lessons`
montam a árvore, e `GET /api/courses/{course}/structure` mostra o resultado
inteiro. Para conferir o isolamento, basta pedir, autenticado como um produtor, o
identificador de um curso do outro: a resposta é `404`.

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
criar a aula, enviar o vídeo, publicar e então consumir como o outro perfil. Os
dois primeiros passos já funcionam pela API.

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
