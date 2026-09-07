# Plataforma de Conteúdo em Vídeo

Plataforma onde **produtores** criam cursos, organizam módulos e aulas, enviam
vídeos e publicam o conteúdo, e onde **consumidores autorizados** encontram esses
cursos e assistem às aulas.

Backend em Laravel 13, frontend em Nuxt 4, integração real entre as duas camadas:
o vídeo sobe do navegador direto para o armazenamento de objetos, o processamento
acontece fora da requisição HTTP e volta por um callback assinado, e a publicação
só é permitida quando o vídeo está pronto de verdade.

Tudo sobe com **um comando**. A máquina precisa de Docker Engine, Docker Compose
e GNU Make — e de mais nada: nenhuma linguagem, gerenciador de pacotes ou banco
precisa estar instalado.

---

## Sumário

- [O que a solução entrega](#o-que-a-solução-entrega)
- [Stack](#stack)
- [Executar](#executar)
- [Testes e qualidade](#testes-e-qualidade)
- [Documentação da API](#documentação-da-api)
- [Arquitetura em resumo](#arquitetura-em-resumo)
- [Especificações versionadas](#especificações-versionadas)
- [Limitações conhecidas](#limitações-conhecidas)
- [Estrutura do repositório](#estrutura-do-repositório)

---

## O que a solução entrega

### Jornada do produtor

Autenticar, criar cursos, montar a árvore de módulos e aulas, enviar o vídeo de
uma aula, acompanhar o processamento até o fim e publicar.

A ordem de módulos e aulas é definida e preservada pelo servidor: cada item novo
entra na próxima posição livre do seu pai, e o formulário nem tem esse campo. Um
produtor enxerga e alcança **apenas** o próprio conteúdo — curso de outro
produtor responde como recurso inexistente, e não como acesso negado.

### Jornada do consumidor

Autenticar, ver o catálogo dos cursos que lhe foram **concedidos e publicados**,
navegar pelos módulos e aulas e reproduzir o vídeo.

O consumidor não vê rascunhos, não vê aulas não publicadas e não vê cursos sem
concessão. A reprodução devolve dados de reprodução — uma URL assinada de curta
duração —, nunca o arquivo pela API.

### O caminho do vídeo

O ciclo completo, do arquivo no disco de quem envia até o `<video>` de quem
assiste:

```
seleção do arquivo
   └─ a API autoriza e abre o envio multipart, devolvendo o plano de partes
        └─ o navegador envia cada parte DIRETO ao armazenamento, com URL assinada
             └─ a API conclui e verifica o objeto por conta própria (HeadObject)
                  └─ o trabalho vai para a fila e sai da requisição HTTP
                       └─ o simulador representa o provedor externo
                            └─ e devolve o desfecho por webhook HTTP assinado
                                 └─ o vídeo chega a `ready` e a aula pode publicar
                                      └─ o consumidor recebe uma URL assinada e assiste
```

Os bytes do vídeo **nunca atravessam** o Laravel nem o servidor do Nuxt.

---

## Stack

| Camada | Tecnologias |
| --- | --- |
| Backend | PHP 8.4, Laravel 13, API REST/JSON, DDD e Arquitetura Hexagonal |
| Frontend | Nuxt 4, Vue 3, TypeScript, Composition API, Nuxt UI |
| Dados | MySQL 8 — domínio, sessão e fila |
| Armazenamento | RustFS, compatível com a API do S3 |
| Ambiente | Docker Compose |
| Testes | PHPUnit, Vitest, Playwright |
| Qualidade | Pint, ESLint, verificação de tipos, linter de OpenAPI |

---

## Executar

### Requisitos

Três ferramentas no host: **Docker Engine, Docker Compose e GNU Make.**

Nada além delas. PHP, Composer, Node, npm, MySQL e os linters **não** precisam
existir na máquina: as dependências das duas aplicações são instaladas pelas
próprias imagens do projeto, e todo comando deste README roda em container.

| Item | Versão de referência |
| --- | --- |
| Docker Engine | 28.4 |
| Docker Compose | 2.39 |
| GNU Make | 4.x |

As imagens de terceiros são fixadas por tag **e** digest — nunca `latest` —, para
que o ambiente reproduza o mesmo comportamento em outra máquina e em outro dia.

### Preparar o arquivo de ambiente

```bash
cp .env.example .env
```

Esse é o único arquivo a preparar, e os valores padrão já servem para a avaliação
local. Ele é a fonte das variáveis que o Compose injeta em cada container.

**Não preencha `APP_KEY`.** A chave de criptografia é gerada na primeira subida,
dentro da imagem do backend, gravada no seu `.env` — que não vai para o Git — e
nunca substituída depois. Uma chave publicada no repositório seria idêntica em
toda cópia dele, e assinar sessão com um segredo conhecido equivale a não
assinar.

O `.env` da raiz nunca é criado nem sobrescrito automaticamente: um arquivo já
preenchido é a configuração real da máquina.

### Subir

```bash
make up
```

O alvo é idempotente e faz, nesta ordem: confere o arquivo de ambiente, constrói
as imagens, garante a chave de criptografia, instala as dependências das duas
aplicações e sobe os serviços. Rodar de novo não apaga volume, não recria banco e
não reinstala o que já está atualizado.

A primeira execução leva alguns minutos, porque constrói as imagens e instala as
dependências. As seguintes levam segundos.

### Endereços

| O quê | Endereço |
| --- | --- |
| Interface | <http://localhost:3000> |
| API | <http://localhost:8080> |
| Armazenamento de objetos | <http://localhost:19000> |

**A porta de entrada é <http://localhost:3000/login>.** As três portas são
publicadas apenas em `127.0.0.1`, e não em todas as interfaces da máquina.

Esses três endereços não são intercambiáveis com os nomes internos dos serviços:
o cookie de sessão é *host-only*, o CORS enumera a origem completa e a assinatura
das URLs do armazenamento cobre o host que aparece nelas. É por isso que a
configuração separa endereço interno de endereço público.

### Serviços

`make up` sobe oito serviços:

| Serviço | Papel | Depende de |
| --- | --- | --- |
| `mysql` | MySQL 8: domínio, sessão e fila | — |
| `rustfs` | Armazenamento de objetos compatível com S3 | — |
| `setup` | Migrations, seed, bucket e CORS; roda uma vez e sai | `mysql`, `rustfs` |
| `api` | PHP-FPM com a aplicação Laravel | `setup` |
| `web` | Servidor HTTP à frente do `api` | `api` |
| `worker` | Consome a fila `default` e delega o processamento | `setup` |
| `simulator-worker` | Representa o provedor externo e chama o webhook | `setup` |
| `frontend` | Nuxt em modo desenvolvimento | `web` saudável |

`api`, `worker`, `simulator-worker` e `setup` compartilham a **mesma imagem**:
são ciclos de vida diferentes da mesma aplicação, e o que muda entre eles é o
comando — não são microserviços.

O `setup` termina antes de `api`, `worker` e `simulator-worker` iniciarem, então
banco migrado, bucket criado com CORS e dados de avaliação já existem quando a
aplicação sobe. Por isso ele aparece como `Exited` em `docker compose ps`: é o
resultado esperado, não uma falha.

Não há Redis. A fila de banco resolve o problema com um componente a menos.

### Credenciais de avaliação

As três contas de demonstração, seus perfis e para que serve cada uma estão em
**[`docs/demonstracao.md`](docs/demonstracao.md)**, junto do roteiro completo.

São credenciais **locais e fictícias**, preparadas pelo seed. Elas estão
documentadas em um único lugar, e os demais documentos apontam para lá em vez de
repeti-las.

### Reset do cenário

O seed **insere o que falta e não altera o que já existe**: subir o ambiente de
novo não duplica registros nem desfaz o que foi feito durante uma demonstração. A
contrapartida é que ele prepara, mas não repara. Para voltar ao estado inicial:

```bash
docker compose stop worker simulator-worker
docker compose run --rm api php artisan migrate:fresh --seed --force
docker compose up --detach worker simulator-worker
```

> **Comando destrutivo.** `migrate:fresh` **apaga todas as tabelas e todos os
> dados** do banco antes de recriá-lo. Use-o apenas neste ambiente local de
> avaliação, e apenas quando a intenção for descartar tudo o que existe.

**Os consumidores da fila param antes e voltam depois, e a ordem não é
decorativa.** Recriar o esquema no meio de um `queue:work` ativo é uma corrida: o
consumidor pode ler ou gravar numa tabela que está sendo derrubada, e o desfecho
muda a cada execução. Parar os dois primeiro elimina a disputa; devolvê-los
depois os coloca sobre o esquema novo.

---

## Testes e qualidade

Cada camada tem sua suíte e ao menos uma verificação adicional de qualidade.
Todos os comandos rodam em container descartável.

### Backend

```bash
# Formatação
docker compose run --rm --no-deps api ./vendor/bin/pint --test

# Suíte completa: unitários, feature e integração
docker compose run --rm --no-deps api php artisan test

# Contrato da API
make openapi-lint
```

A suíte roda contra **MySQL real e RustFS real**, no schema de testes declarado
em `DB_TEST_DATABASE` — separado do banco da aplicação, para que testar não apague
os dados preparados para a avaliação. Não há SQLite em memória: locks,
constraints e comportamento transacional precisam corresponder ao banco que a
aplicação usa em execução, e é justamente esse comportamento que os testes de
idempotência verificam.

Por isso o ambiente precisa estar de pé (`make up`) antes de rodar a suíte de
backend.

> **Sobre os avisos.** `php artisan test` reporta um aviso por teste dizendo que
> `/app/.env` não pôde ser lido. É esperado e não indica falha: no ambiente
> containerizado a configuração é injetada pelo Compose como variáveis de
> ambiente, e nenhum arquivo `.env` existe dentro de `backend/` — por decisão
> registrada no próprio `backend/.env.example`. O resultado da suíte é a linha
> final, e ela não tem falhas.

### Frontend

```bash
docker compose run --rm --no-deps frontend npm run lint
docker compose run --rm --no-deps frontend npm run typecheck
docker compose run --rm --no-deps frontend npm run test
docker compose run --rm --no-deps frontend npm run build
```

Lint, verificação de tipos, suíte de componentes e composables, e o build de
produção do Nuxt. **Nenhum deles depende do backend estar de pé** — `--no-deps`
garante isso, e é o que permite à pipeline rodar a camada do frontend sozinha.
Só as dependências precisam estar instaladas, o que `make up` já fez.

### Jornada integrada

```bash
make e2e
```

**Esta é a única entrada oficial do teste de ponta a ponta.** Um único cenário,
em navegador real, atravessando Nuxt, Laravel, MySQL, fila, simulador e
armazenamento de verdade: o produtor abre o curso preparado, cria módulo e aula,
envia um vídeo, acompanha o processamento até `ready`, publica; o consumidor
entra, encontra o curso no catálogo e reproduz a aula.

O alvo interrompe os consumidores da fila, recria e semeia a base, devolve os
consumidores, espera pelas verificações de saúde e só então roda o navegador num
container descartável — que existe apenas durante a execução e não faz parte do
ambiente normal.

> **`make e2e` recria e semeia a base de dados.** Ele executa
> `migrate:fresh --seed --force` como primeiro passo, para que a segunda execução
> seja idêntica à primeira. **Tudo o que tiver sido criado durante uma
> demonstração é descartado.** Ao terminar, o curso da jornada fica com o módulo,
> a aula e o vídeo que o próprio teste criou; use o reset acima para voltar ao
> cenário vazio.

O Playwright e o navegador vivem na imagem do teste. Não é preciso instalá-los no
host, e chamar o Playwright diretamente pularia o reset, o seed e a espera pelas
verificações de saúde.

### Pipeline

O workflow em [`.github/workflows/ci.yml`](.github/workflows/ci.yml) executa a
cada `push` e a cada `pull_request`, com **dois jobs independentes e em
paralelo**:

```
Backend    imagem → chave → composer install → ambiente do projeto → Pint → PHPUnit → OpenAPI
Frontend   imagem → npm ci → ESLint → typecheck → Vitest → build do Nuxt
```

Nenhum dos dois depende do outro, e falha em qualquer etapa reprova a execução.

O job de backend sobe o **próprio ambiente do projeto**, com o mesmo
`docker-compose.yml` da execução local, em vez de declarar serviços paralelos no
provedor de CI — duas descrições do mesmo ambiente divergiriam, e a suíte não
depende só do MySQL: há integração contra o armazenamento e testes que exercem o
callback HTTP real.

A jornada integrada **não** roda na pipeline. Ela permanece reproduzível por um
comando único e é executada localmente: subir a pilha completa com navegador a
cada push acrescenta minutos e uma classe própria de instabilidade para
reafirmar o que a jornada já prova quando executada.

---

## Documentação da API

| Documento | Para quê |
| --- | --- |
| [`docs/openapi.yaml`](docs/openapi.yaml) | Contrato OpenAPI 3.1, referência normativa. Importável em Postman, Insomnia, Bruno ou Swagger UI |
| [`docs/api.md`](docs/api.md) | Guia de uso: autenticar, preservar a sessão, a ordem mínima da jornada e as respostas de erro |

O contrato é validado por `make openapi-lint`, e as regras conferem também **os
exemplos contra os schemas** que eles ilustram.

Os tipos do cliente Nuxt são **gerados a partir do contrato**, e não escritos à
mão: o frontend deriva da mesma fonte que a documentação.

---

## Arquitetura em resumo

O detalhamento — com alternativas consideradas, custos e trade-offs de cada
decisão — está em
[`specs/001-video-platform/plan.md`](specs/001-video-platform/plan.md). O resumo
abaixo aponta para as seções.

### Backend em DDD e Arquitetura Hexagonal

Quatro camadas, com a regra de dependência sempre apontando para dentro:

| Camada | Contém |
| --- | --- |
| `Domain` | Entidades, agregados, value objects e invariantes. **PHP puro** |
| `Application` | Casos de uso, portas e limite transacional |
| `Infrastructure` | Eloquent, armazenamento S3, fila, HMAC, relógio — os adapters |
| `Interfaces` | Controllers, Form Requests, Resources, middleware, rotas, console |

O código é organizado por **área de capacidade** — `Identity`, `Catalog`,
`Video`, `Shared` —, e não por tipo de arquivo. O `Domain` não importa Eloquent,
não conhece HTTP e não usa facades: é o que torna as regras testáveis sem
framework.

Sem CQRS, sem event sourcing, sem repositório genérico. Nenhum deles traria
benefício neste escopo. *(plan §5)*

### Autenticação

**Sanctum em modo SPA**: a sessão vive num cookie `HttpOnly`, e não num token
manipulado por JavaScript. Credencial acessível ao JavaScript é credencial
exposta a XSS.

O CSRF é tratado pelo par `XSRF-TOKEN` / `X-XSRF-TOKEN`, e o CORS **enumera as
origens explicitamente** — nunca `*`, porque o navegador rejeita curinga com
credenciais e o curinga abriria a API a qualquer origem.

A autorização acontece em duas camadas: o **perfil** na fronteira HTTP, que falha
com `403`; e **propriedade e concessão** dentro do caso de uso, que falham com
`404` — para não transformar a API em oráculo de identificadores alheios. A
interface esconde ações indisponíveis, mas nunca é a proteção: toda decisão é
tomada de novo no backend. *(plan §9)*

### Frontend

**Nuxt SPA, com `ssr: false`.** Todas as jornadas exigem login e nenhuma precisa
de SEO; desligar a renderização no servidor elimina um segundo contexto de
execução e todo o repasse de cookie entre o servidor Nuxt e o Laravel.

O estado compartilhado guarda **apenas sessão e usuário**. Estado de tela vive na
tela, e não há store global: o que precisaria dela cabe em duas chaves. *(plan §15)*

### Upload

**Multipart direto do navegador para o armazenamento, com URLs pré-assinadas.**
O Laravel autoriza e cria o envio; os bytes vão do navegador ao RustFS.

| Parâmetro | Valor |
| --- | --- |
| Tamanho de parte | 64 MiB |
| Concorrência | uma parte por vez |
| Validade da URL de parte | 15 minutos, renovável |
| Tipo aceito | `video/mp4` |
| Tamanho máximo | 10 GiB |

O progresso é real — partes concluídas sobre o total —, e uma parte que falha é
reenviada sozinha. A chave do objeto é derivada do identificador da tentativa:
entrada de usuário não compõe caminho de armazenamento. *(plan §11)*

### Verificação da conclusão

A confirmação do navegador **não** é aceita como prova. Depois de
`CompleteMultipartUpload`, o servidor faz `HeadObject` e confere por conta
própria quatro coisas: a chave é a esperada para aquela tentativa, o tamanho
corresponde ao declarado, o `Content-Type` está entre os aceitos, e os metadados
controlados carregam o identificador da tentativa.

Só depois disso o estado muda e o trabalho é enfileirado — na **mesma
transação**, porque a fila vive no mesmo MySQL: ou as duas coisas existem, ou
nenhuma delas. *(plan §12)*

### Processamento

**Database Queue sobre MySQL**, em duas filas e dois workers separados: o
`worker` consome o processamento e delega; o `simulator-worker` representa o
provedor externo.

O simulador **não escreve nas tabelas de domínio**. A única forma de ele afetar o
estado é chamar o webhook HTTP real, assinado com HMAC-SHA256 sobre timestamp e
corpo bruto, com janela de cinco minutos contra replay. É o que faz a integração
exercitar assinatura, idempotência e transições exatamente como um provedor de
verdade faria — e é o que permite trocá-lo por um provedor real sem tocar no
domínio.

O `event_id` de cada callback é **estável**, derivado da tentativa e do cenário.
Uma reentrega carrega o mesmo identificador, e o webhook repete o desfecho já
registrado sem efeito novo. *(plan §13)*

### Acompanhamento do estado

Por **polling**, e somente enquanto o vídeo está em estado transitório
(`pending`, `uploading`, `uploaded`, `processing`). Ao alcançar `ready` ou
`failed`, a consulta para: estados terminais não mudam sozinhos. O intervalo é de
3 segundos e a consulta é encerrada ao sair da tela.

Sem WebSocket nem SSE: exigiriam infraestrutura persistente para um dado que muda
poucas vezes por vídeo. *(plan §15.2)*

### Reprodução

Objeto **privado** no armazenamento e URL `GET` pré-assinada de **cinco
minutos**, emitida somente depois de quatro verificações: consumidor autenticado,
concessão para o curso, aula publicada e vídeo `ready`. A API devolve dados de
reprodução, nunca o arquivo. *(plan §14)*

---

## Especificações versionadas

O comportamento é descrito antes de ser implementado, e as especificações evoluem
junto com o código:

| Documento | Conteúdo |
| --- | --- |
| [`spec.md`](specs/001-video-platform/spec.md) | Jornadas, regras de negócio, requisitos e critérios de aceitação |
| [`plan.md`](specs/001-video-platform/plan.md) | Arquitetura, contratos, decisões e trade-offs |
| [`tasks.md`](specs/001-video-platform/tasks.md) | Decomposição da implementação e rastreabilidade |

---

## Limitações conhecidas

Honestidade sobre o que ficou de fora vale mais que a aparência de completude.
Nenhum dos itens abaixo está implementado, e todos estão registrados em
[`plan.md` §19](specs/001-video-platform/plan.md).

**Envio abandonado bloqueia a aula.** Uma tentativa interrompida cuja conclusão
nunca é solicitada permanece em `uploading`, e a aula recusa um novo envio
enquanto isso durar. Não há cancelamento, descarte nem expiração: os quatro
exigiriam novos comandos, estados, telas e rotina de limpeza.

**Partes órfãs no armazenamento.** Um envio multipart nunca concluído deixa
partes ocupando espaço. Não há rotina de limpeza.

**Entrega de callback esgotada deixa a tentativa em `processing`.** Se o
`simulator-worker` esgotar as tentativas de entrega, o job vai para `failed_jobs`
e o vídeo fica parado — porque a aplicação não recebeu desfecho confiável, e
inventar um seria pior. A recuperação é `php artisan queue:retry`, que reentrega
o mesmo evento. Não há expiração automática de tentativas presas.

**A URL de reprodução é compartilhável até expirar.** Cinco minutos reduzem, não
eliminam, a redistribuição. Mitigar de fato exigiria cookies assinados, tokens
por sessão de player ou DRM.

**Envio de uma parte por vez.** O upload não paraleliza transferências, o que
deixa banda ociosa em conexões rápidas. O contrato já comporta a mudança, porque
a URL de cada parte é emitida sob demanda.

**Sem reordenação.** Módulos e aulas recebem a próxima posição livre e não há
operação para movê-los depois.

**Sem checksum ponta a ponta.** A verificação do objeto é de existência, chave,
tamanho e tipo. O `ETag` é tratado como comprovante opaco da parte, e não como
checksum do vídeo: o protocolo não promete um valor específico, e apoiar
integridade nele quebraria ao trocar de provedor ou mudar o número de partes.

**Sem transcodificação.** Apenas `video/mp4` é aceito. Aceitar outros formatos
seria prometer uma reprodução que a solução não entrega.

**Sem retomada entre sessões.** Uma transferência interrompida pode ser retomada
na mesma tela, mas o plano de partes não é persistido: recarregar a página perde
o progresso em andamento.

**Sem atualização em tempo real.** O acompanhamento é por polling.

**Sem APM e sem métricas.** A observabilidade é o mínimo defensável: healthcheck
público, log estruturado com identificador de correlação por requisição,
propagado até os jobs, eventos de domínio registrados e `failed_jobs` como
registro durável das falhas.

**O armazenamento é uma *release candidate*.** O RustFS foi a decisão de maior
risco técnico do projeto, e por isso validado antes de qualquer código de domínio
depender dele — envio multipart, política de CORS, `HeadObject` e URLs
pré-assinadas foram comprovados na prática. Ainda assim, a versão aprovada é uma
`1.0.0-rc.5`, e não uma versão final.

**A expiração das URLs pré-assinadas foi configurada, mas não observada
vencendo.** Os prazos estão definidos — 15 minutos por parte do envio, 5 minutos
para reprodução — e o storage os aplica na assinatura. O que não foi exercitado é
o vencimento real: nenhuma validação esperou o prazo passar para ver a URL ser
recusada.

**Sanctum em modo SPA exige domínio-base compartilhado.** A autenticação por
sessão em cookie depende de frontend e API serem *first-party*. Localmente isso é
resolvido por ambos viverem em `localhost`; numa eventual publicação, os dois
precisariam compartilhar o domínio-base. É o custo assumido ao preferir cookie
`HttpOnly` a um token manipulável por JavaScript.

**Um perfil por usuário.** Cada conta é produtor **ou** consumidor, e os papéis
não se acumulam. Permitir acúmulo exigiria rever a matriz de autorização inteira,
porque hoje uma rota decide pelo perfil único da conta.

**Cobertura de concorrência limitada a um cenário.** Só a entrega concorrente do
mesmo evento tem teste que coloca duas execuções em disputa. Deslocamento de
posição, conclusão de envio e publicação simultâneas contam com lock, transação e
constraint, mas não com teste dedicado sob corrida real.

### Evoluções naturais

Expiração e cancelamento de tentativas; retomada entre sessões, persistindo o
plano de partes; envio de partes em paralelo; reordenação de módulos e aulas;
checksum por parte; um provedor de processamento real no lugar do simulador, pelo
mesmo contrato; atualização por WebSocket ou SSE; ambiente público; cookies
assinados ou tokens por sessão de player; observabilidade com métricas e tracing.

Nenhuma delas exige reescrever o domínio — é o retorno esperado das portas.

---

## Estrutura do repositório

```
backend/                aplicação Laravel
    app/
        Identity/       autenticação, perfis, concessão de acesso
        Catalog/        curso, módulo, aula, estrutura, publicação
        Video/          tentativa de envio, ciclo de vida, webhook, reprodução
        Shared/         tipos e utilitários comuns às três áreas
    database/           migrations e dados de avaliação
    tests/              unitários, feature e integração

frontend/               aplicação Nuxt
    app/
        pages/          rotas das duas jornadas
        components/     course, module, lesson, video, ui
        composables/    sessão, acesso à API, envio multipart e acompanhamento
        middleware/     proteção das rotas autenticadas
        types/          tipos derivados do contrato OpenAPI
    tests/              componentes e composables

e2e/                    jornada integrada em navegador real
docker/                 imagens do projeto
docs/                   contrato da API, guia de uso e roteiro de demonstração
scripts/                orquestração de tarefas do ambiente
specs/                  especificações versionadas
```
