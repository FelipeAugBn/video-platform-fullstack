# Decomposição da Implementação — Plataforma de Conteúdo em Vídeo

**Feature:** 001-video-platform
**Estado:** aprovado como baseline de implementação. Evolui apenas por mudança
explicitamente revisada e aprovada, mantendo coerência com `spec.md` e `plan.md`
**Escopo deste documento:** ordem de execução, dependências e critérios de conclusão

---

## Como usar este documento

Este documento não redefine requisito nem decisão. A `spec.md` define o
comportamento, o `plan.md` define como construir, e o desafio define o que é
obrigatório. Onde houver divergência, eles prevalecem sobre qualquer tarefa
descrita aqui.

### Regra de marcação

> Uma tarefa só pode ser marcada `[x]` quando estiver implementada e seus testes
> correspondentes tiverem sido executados com sucesso. `[x]` significa **pronta
> para revisão**, não aprovada.

Quatro consequências dessa regra:

1. **Nenhuma tarefa é marcada durante a criação deste documento.** Todas nascem
   `[ ]`.
2. **Falha de ferramenta ou de ambiente não autoriza mudar o plano.** Se o
   formatador não roda, o problema é o formatador — não é motivo para remover a
   verificação de qualidade da tarefa.
3. **Decisão arquitetural imprevista interrompe a tarefa.** Ela não é resolvida
   dentro do commit: para, explica alternativas, custos e impactos, e aguarda
   aprovação.
4. **Testes não são adiados para o fim.** Cada tarefa carrega os seus. Uma fase
   não fecha com implementação verde e teste pendente.

### Revisão de escopo

A decomposição original tinha 109 pacotes. Depois de a fundação containerizada
ficar pronta, uma revisão consolidou as tarefas pendentes em **24**, para ajustar
a granularidade ao recorte funcional que o desafio pede e retirar complexidade sem
benefício proporcional. Nenhum requisito obrigatório foi removido.

Duas alterações de escopo aprovadas depois dessa consolidação acrescentaram a
**T110** e a **T111**, levando a decomposição a **26** tarefas consolidadas e
**37** no total, contando as onze da fundação. Nenhuma delas renumerou ou
reaproveitou identificador: cada uma entrou no próximo número livre. A T110 é
exigência do desafio elaborada em decisão de produto; a T111 é `[DECISÃO]` do
projeto, e o desafio não a pede.

Os identificadores **não foram renumerados nem reaproveitados**: os que saíram da
lista de pendências estão registrados, com destino e motivo, na seção *IDs
absorvidos ou retirados da baseline*.

T001 a T011 continuam concluídas e marcadas `[x]`, e **suas entregas e
implementações históricas não foram alteradas** — nada do que elas produziram foi
refeito ou retirado. O que pode ser ajustado no texto delas é uma referência ao
futuro que a redução tornou inválida: uma tarefa concluída que apontava para um
identificador depois absorvido ou retirado passa a apontar para o destino
correto, para o documento não descrever um caminho que deixou de existir.

### Ambiente

O host não possui PHP, Composer, MySQL nem a versão de Node exigida pelo Nuxt 4.
Todos os comandos rodam em container. Antes de o `docker-compose.yml` existir, as
tarefas usam `docker build` e `docker run`; a partir dele, `docker compose`.
Nenhum comando deste documento depende de arquivo ou serviço criado por uma
tarefa posterior.

### Restrição de Git

A implementação não executa `commit`, `push`, criação de branch nem clone, e não
dispara execução remota de pipeline. Onde uma validação exigir estado remoto, a
tarefa diz explicitamente **quem executa**:

| Tipo | Quem executa | Exemplo |
| --- | --- | --- |
| Validação local | Implementação | Testes, lint, build, subida do Compose |
| Validação remota | Responsável pelo repositório | Execução do workflow após `push`, verificação a partir de clone limpo |

Essas duas categorias não se bloqueiam. Uma **tarefa de implementação fecha com
suas verificações locais**; o que só o responsável pelo repositório pode
executar fica concentrado em verificações externas explícitas — T104, para a
pipeline, e T109, que reúne todas antes do fechamento. Assim a documentação não espera por um `push`, e a
entrega não fecha sem a evidência.

A implementação nunca presume uma validação externa como cumprida: ela reporta a
pendência, nomeando quem a executa.

---

## Fase 0 — Redução de risco

O `plan.md` §19.1 registra o RustFS como a decisão de maior risco técnico. Nada
neste documento começa antes de ele ser validado e consolidado.

- [x] **T001** Spike de validação do RustFS
  - **Objetivo:** provar, com evidência reproduzível, que o RustFS entrega as
    quatro capacidades das quais o plano depende — multipart, CORS, `HeadObject`
    e URLs pré-assinadas — antes que qualquer código as assuma.
  - **Arquivos previstos:** `docker/spike/compose.rustfs.yml`,
    `docker/spike/validar-interno.sh`, `docker/spike/validar-publico.sh`,
    `docker/spike/executar.sh`, `docker/spike/cors.json`.
  - **Requisitos:** ABERTO-002, ABERTO-003, ABERTO-007; RF-UPL-001 a 013;
    RF-PLB-005; RNF-001; plan §§11, 12, 14.2, 16, 19.1.
  - **Implementação:** subir apenas RustFS num compose isolado, com imagem e tag
    fixadas, publicando a porta do endpoint público no host. A validação tem
    **dois lados**, e é isso que torna a distinção entre endereço interno e
    público verificável:

    - **Cliente interno** — container ligado à rede do compose, com a CLI
      compatível com S3. Faz as operações de SDK e de assinatura, prova que o
      **nome do serviço** é alcançável, e representa a futura comunicação da API.
    - **Cliente externo** — container descartável de `curl`, executado com
      `--network host` e **sem** acesso à rede do compose. Exerce as URLs
      pré-assinadas pelo endereço **publicado no host**, e representa o navegador.

    Um multipart não se fecha num sentido só: quem assina a URL não é quem recebe
    o `ETag`, e quem conclui o upload precisa desse `ETag`. Os dois lados portanto
    **se alternam**, em quatro etapas, trocando dados por um diretório temporário
    montado nos dois containers:

    O caminho tem **dois nomes, e eles não se confundem**: `TROCA_DIR` é o
    diretório temporário **no host**, e `/troca` é onde ele aparece **dentro de
    qualquer um dos dois containers**. Os scripts nunca veem `TROCA_DIR`: dentro
    dos containers, `validar-interno.sh` e `validar-publico.sh` leem e escrevem
    sempre em `/troca`, caminho fixo.

    1. **Cliente interno — preparação** (`validar-interno.sh preparar`): cria o
       bucket, aplica o CORS de `cors.json`, gera o arquivo de amostra, inicia o
       `CreateMultipartUpload` e grava em `/troca` o `UploadId`, a chave do objeto
       e a URL `PUT` pré-assinada da parte 1, com validade de 15 minutos.
    2. **Cliente externo — envio** (`validar-publico.sh enviar`): lê a URL `PUT`
       de `/troca`, executa o preflight `OPTIONS` com o `Origin` do frontend,
       envia a parte pela URL pública, confere os headers de CORS **na resposta do
       `PUT`** e grava o `ETag` devolvido em `/troca`.
    3. **Cliente interno — conclusão** (`validar-interno.sh concluir`): lê
       `UploadId`, chave e `ETag` de `/troca`, executa o
       `CompleteMultipartUpload`, executa o `HeadObject` sobre o objeto resultante
       e grava em `/troca` a URL `GET` pré-assinada, com validade de 5 minutos.
    4. **Cliente externo — leitura** (`validar-publico.sh ler`): baixa pela URL
       `GET` pelo endereço publicado no host e compara os bytes com o arquivo de
       amostra, byte a byte.

    O `executar.sh` cria o diretório com `TROCA_DIR="$(mktemp -d)"` e o remove com
    `trap 'rm -rf "$TROCA_DIR"' EXIT`, inclusive quando uma etapa falha. A
    montagem é **explícita nas quatro invocações**, sempre como
    `-v "$TROCA_DIR:/troca"` — inclusive no cliente interno, que usa
    `docker compose run --rm -v ...` em vez de declarar o bind no
    `compose.rustfs.yml`. Isso é deliberado: um bind escrito no compose dependeria
    de interpolação de variável de ambiente no arquivo, que é justamente o tipo de
    acoplamento invisível que quebra quando alguém roda o serviço por fora do
    script. O volume aparece **apenas** nos dois containers envolvidos.

    Nada temporário é escrito na árvore versionável: o arquivo de amostra, as URLs
    assinadas e as credenciais temporárias vivem só em `TROCA_DIR`, fora do
    repositório, e nenhuma delas é impressa integralmente na saída do script.
    Com `set -euo pipefail`, a primeira etapa que falhar interrompe as seguintes.

    Nenhum dos dois lados exige PHP, Composer, Node ou SDK instalado no host — os
    dois são containers. O container de `curl` não é serviço da aplicação: o
    script o cria e o descarta. Nenhuma linha de código de aplicação é escrita
    aqui.
  - **Testes/validação:** um comando principal executa e valida as quatro etapas:
    `docker compose -f docker/spike/compose.rustfs.yml up -d && docker/spike/executar.sh`.
    O `executar.sh` invoca, nesta ordem:

    ```
    TROCA_DIR="$(mktemp -d)"
    trap 'rm -rf "$TROCA_DIR"' EXIT

    docker compose -f docker/spike/compose.rustfs.yml run --rm \
      -v "$TROCA_DIR:/troca" \
      cliente-interno /spike/validar-interno.sh preparar

    docker run --rm --network host \
      -v "$TROCA_DIR:/troca" \
      -v "$PWD/docker/spike:/spike:ro" \
      "curlimages/curl:$CURL_TAG" \
      /spike/validar-publico.sh enviar

    docker compose -f docker/spike/compose.rustfs.yml run --rm \
      -v "$TROCA_DIR:/troca" \
      cliente-interno /spike/validar-interno.sh concluir

    docker run --rm --network host \
      -v "$TROCA_DIR:/troca" \
      -v "$PWD/docker/spike:/spike:ro" \
      "curlimages/curl:$CURL_TAG" \
      /spike/validar-publico.sh ler
    ```

    com `CURL_TAG` fixada no próprio `executar.sh`. As duas invocações de `curl`
    rodam **fora** da rede do compose. Catorze pontos, cada um preso a uma etapa e
    a uma evidência objetiva:

    | # | Etapa | Verificação | Evidência de aprovação |
    | --- | --- | --- | --- |
    | 1 | antes | Imagem e versão fixadas | `docker compose config` mostra tag explícita, nunca `latest` |
    | 2 | antes | Inicialização via Compose | Container sobe e permanece `running` |
    | 3 | antes | Healthcheck | `docker compose ps` reporta `healthy` |
    | 4 | 1 — interno | Criação do bucket e do CORS por script | Bucket listado e política de CORS lida de volta após a execução |
    | 5 | 1 — interno | Endpoint interno | O cliente interno alcança o storage pelo **nome do serviço** na rede do compose |
    | 6 | 1 — interno | Criação de upload multipart | Resposta contém `UploadId`, gravado em `/troca` |
    | 7 | 1 — interno | URL pré-assinada de parte | URL gerada com expiração de 15 minutos, gravada em `/troca` |
    | 8 | 2 — externo | Preflight de CORS | `OPTIONS` com `Origin` do frontend responde permitindo `PUT` |
    | 9 | 2 — externo | Envio real de uma parte | `PUT` pela URL pré-assinada, com `--network host`, retorna sucesso e `ETag`, gravado em `/troca` |
    | 10 | 2 — externo | CORS na operação real | A resposta do próprio `PUT` traz os headers de CORS esperados |
    | 11 | 3 — interno | Conclusão multipart | `CompleteMultipartUpload` com o `ETag` produzido na etapa 2 é aceito e o objeto passa a existir |
    | 12 | 3 — interno | `HeadObject` | Retorna tamanho, `Content-Type` e os metadados gravados |
    | 13 | 3 — interno | URL GET pré-assinada | URL gerada com expiração de 5 minutos, gravada em `/troca` |
    | 14 | 4 — externo | Endpoint público e integridade | `GET` pelo endereço publicado no host, sem acesso à rede do compose, e conteúdo byte a byte igual ao arquivo de amostra |

  - **Depende de:** nenhuma.
  - **Critério de conclusão:** as quatro etapas executadas em sequência pelo
    comando principal e os catorze pontos aprovados, com a saída capturada para a
    T002. **Se multipart, CORS, `HeadObject` ou URLs pré-assinadas falharem, a
    implementação para aqui** e volta para revisão arquitetural. Substituir o
    RustFS por outro storage exige nova decisão aprovada.

- [x] **T002** Relatório versionado do spike
  - **Objetivo:** deixar o resultado auditável depois que o andaime for removido.
  - **Arquivos previstos:** `docs/spikes/rustfs.md`.
  - **Requisitos:** RNF-011, RNF-012; plan §19.1.
  - **Implementação:** registrar a tag validada, o resultado dos catorze pontos,
    as configurações de CORS que funcionaram, e os endereços interno e público
    usados. **Evidência sanitizada, com uma política única:** de cada URL
    pré-assinada o relatório registra esquema, host, porta, caminho, a validade
    configurada e o resultado da operação; a **query string é inteiramente
    omitida**, sem marcador e sem valor parcial, porque é ela que carrega
    assinatura e credencial temporárias. Os nomes `X-Amz-Signature` e
    `X-Amz-Credential` não aparecem no documento, nem como rótulo. É este arquivo
    que sobrevive à consolidação, não o diretório do spike.
  - **Testes/validação:** `cat docs/spikes/rustfs.md` mostra os catorze pontos com
    resultado e as quatro etapas de T001, e
    `grep -nE "X-Amz-Signature|X-Amz-Credential" docs/spikes/rustfs.md`
    retorna **zero ocorrências**, confirmando a política de omissão da query
    string.
  - **Depende de:** T001.
  - **Critério de conclusão:** relatório completo e versionado.

- [x] **T003** Consolidar a configuração do spike
  - **Objetivo:** promover o que será reaproveitado e remover apenas o andaime
    descartável.
  - **Arquivos previstos:** `docker/rustfs/cors.json`,
    `docker/rustfs/criar-bucket.sh`; remoção de `docker/spike/`.
  - **Requisitos:** ABERTO-012; plan §16.
  - **Implementação:** promovidos — política de CORS e script de criação do
    bucket, que serão usados pelo serviço `setup`. Removidos — o compose isolado e
    os três scripts de validação, cujo papel passa a ser dos testes de integração
    da Fase 6. O relatório de T002 permanece.
  - **Testes/validação:** `ls docker/rustfs/` mostra os dois arquivos promovidos;
    `ls docker/spike/` falha porque o diretório não existe mais;
    `cat docs/spikes/rustfs.md` continua disponível.
  - **Depende de:** T002.
  - **Critério de conclusão:** promoção feita, andaime removido, evidência
    preservada.

> **Checkpoint da Fase 0**
> **Passa a funcionar:** as quatro capacidades críticas do storage estão
> comprovadas, e a configuração de CORS e de bucket está pronta para ser usada
> pelo ambiente.
> **Testes verdes:** o roteiro de catorze pontos, executado uma vez e registrado.
> **Comandos:** `cat docs/spikes/rustfs.md`, `ls docker/rustfs/`.
> **Ainda não iniciado:** nada além do storage. Sem `docker-compose.yml`, sem
> Laravel, sem Nuxt, sem migrations.

---

## Fase 1 — Fundação containerizada

- [x] **T004** Preparação comum do repositório
  - **Objetivo:** diretórios das duas aplicações e regras de ignore ajustadas,
    incluindo a exceção que o fixture do E2E vai precisar.
  - **Arquivos previstos:** `.gitignore`, `backend/.gitkeep`,
    `frontend/.gitkeep`, `e2e/.gitkeep`.
  - **Requisitos:** RNF-006, RNF-018; plan §16.
  - **Implementação:** conferir que `vendor/`, `node_modules/`, `.env` e
    artefatos de build seguem ignorados. Acrescentar a exceção `!e2e/fixtures/**`,
    porque o `.gitignore` ignora `*.mp4` globalmente e o fixture da jornada
    integrada precisa ser versionado.
  - **Testes/validação:**
    `git check-ignore -q e2e/fixtures/exemplo.mp4; echo $?` precisa imprimir **1**.
    O status é a prova, não a saída em texto: com uma regra de negação, o modo
    verboso imprime a própria regra `!e2e/fixtures/**` e retorna zero, o que
    pareceria o contrário do que é. Código 1 significa "não ignorado".
  - **Depende de:** T003.
  - **Critério de conclusão:** estrutura criada e exceção comprovada.

- [x] **T005** Imagem base do backend
  - **Objetivo:** uma imagem PHP 8.4 que servirá a `api`, `worker`,
    `simulator-worker` e `setup`, diferindo apenas no comando.
  - **Arquivos previstos:** `docker/backend/Dockerfile`,
    `docker/backend/php.ini`, `docker/backend/entrypoint.sh`.
  - **Requisitos:** ABERTO-012; RNF-020; plan §16.1.
  - **Implementação:** PHP 8.4 com as extensões que Laravel e MySQL exigem, mais
    Composer. Sem servidor HTTP na imagem: o processo é PHP-FPM. Versões fixadas.
  - **Testes/validação:** `docker build -t video-platform-backend:dev docker/backend`
    conclui, e `docker run --rm video-platform-backend:dev php -v` reporta 8.4.
    Ainda não existe Compose — os comandos são de Docker puro, de propósito.
  - **Depende de:** T004.
  - **Critério de conclusão:** imagem construída e versão confirmada.

- [x] **T006** Scaffolding do Laravel 13
  - **Objetivo:** aplicação Laravel instalada, ainda sem regra de negócio.
  - **Arquivos previstos:** árvore de `backend/`, `backend/composer.json`.
  - **Requisitos:** stack obrigatória do desafio §2; plan §2.
  - **Implementação:** instalar com a imagem de T005, montando `backend/` como
    volume. Confirmar a versão do Laravel e a versão de PHP que ela exige — se
    houver divergência com o assumido no plano, **parar e reportar** em vez de
    ajustar por conta própria.

    Instalar também `aws/aws-sdk-php`, fixando a restrição de versão no
    `composer.json`; o `composer.lock` registra a versão efetivamente resolvida.
    Não é serviço novo nem decisão nova: é a biblioteca cliente de S3 já
    pressuposta pelo storage do plano, e ela é usada em **dois** pontos — o
    script de bootstrap do bucket, promovido em T003 e executado pelo `setup` em
    T011, e o adapter `ObjectStorage` em T042. Instalá-la aqui é o que torna a
    ordem das tarefas executável: o `setup` roda dentro desta imagem e não pode
    depender de nenhuma ferramenta externa.
  - **Testes/validação:**
    `docker run --rm -v "$PWD/backend":/app -w /app video-platform-backend:dev php artisan --version`
    e
    `docker run --rm -v "$PWD/backend":/app -w /app video-platform-backend:dev composer show aws/aws-sdk-php`,
    que precisa listar a versão instalada.
  - **Depende de:** T005.
  - **Critério de conclusão:** Laravel 13 instalado sobre PHP 8.4, versão
    registrada.

- [x] **T007** Base do Compose com MySQL
  - **Objetivo:** o primeiro `docker-compose.yml`, com o banco saudável — a partir
    daqui os comandos passam a usar `docker compose`.
  - **Arquivos previstos:** `docker-compose.yml`, `docker/mysql/my.cnf`.
  - **Requisitos:** RNF-009; plan §§7, 16.
  - **Implementação:** MySQL 8 com tag fixada, volume nomeado, charset
    `utf8mb4`, healthcheck por `mysqladmin ping`, rede do projeto declarada. Um
    segundo banco para a suíte de testes, separado dos dados de avaliação.
  - **Testes/validação:** `docker compose up -d mysql` e
    `docker compose ps` reportando `healthy`; `docker compose restart mysql`
    preserva os dados.
  - **Depende de:** T006.
  - **Critério de conclusão:** serviço saudável e volume preservando dados.

- [x] **T008** Serviço `rustfs`
  - **Objetivo:** o storage validado no spike, integrado ao Compose e saudável.
  - **Arquivos previstos:** `docker-compose.yml`, `docker/rustfs/`.
  - **Requisitos:** ABERTO-002, ABERTO-012; plan §16.
  - **Implementação:** tag validada em T001, volume nomeado, healthcheck.
    Endereço **interno** e endereço **público** declarados como variáveis
    distintas. O serviço apenas sobe e fica saudável — **criar o bucket e aplicar
    o CORS é responsabilidade do `setup`** (T011), não desta tarefa.
  - **Testes/validação:** `docker compose up -d rustfs` e
    `docker compose ps` reportando `healthy`.
  - **Depende de:** T007.
  - **Critério de conclusão:** storage saudável; bucket ainda não existe, e isso é
    esperado.

- [x] **T009** `[P]` Imagem e scaffolding do Nuxt 4
  - **Objetivo:** aplicação Nuxt instalada e buildando, ainda sem telas.
  - **Arquivos previstos:** `docker/frontend/Dockerfile`, árvore de `frontend/`,
    `frontend/package.json`, `frontend/nuxt.config.ts`.
  - **Requisitos:** stack obrigatória do desafio §2; ABERTO-008; plan §15.1.
  - **Implementação:** Node em versão compatível com o Nuxt 4, fixada.
    `ssr: false` já no `nuxt.config.ts`, conforme plan §15.1 — decisão tomada, não
    escolha desta tarefa. Esta tarefa **não edita** `docker-compose.yml`; o
    serviço `frontend` entra em T012. É o que a torna paralelizável com T005 a
    T008.
  - **Testes/validação:**
    `docker build -t video-platform-frontend:dev docker/frontend` e
    `docker run --rm -v "$PWD/frontend":/app -w /app video-platform-frontend:dev npm run build`.
  - **Depende de:** T004.
  - **Critério de conclusão:** build do Nuxt conclui sem erro.

- [x] **T010** Serviços `api` e `web`, com verificação de prontidão
  - **Objetivo:** a API respondendo HTTP através de um servidor web à frente do
    PHP-FPM.
  - **Arquivos previstos:** `docker-compose.yml`, `docker/web/nginx.conf`.
  - **Requisitos:** RNF-020; plan §16.2.
  - **Implementação:** `api` roda PHP-FPM e seu healthcheck verifica o
    **processo** — PHP-FPM fala FastCGI, não HTTP. O healthcheck HTTP fica no
    `web`, apontado para `/up`, o endpoint de prontidão padrão do Laravel. Ele é
    a verificação HTTP **definitiva** do ambiente, e deliberadamente mínima:
    responde se a aplicação está de pé, sem consultar banco nem storage, porque
    quem ordena a subida dessas duas dependências são os healthchecks de cada uma
    e o serviço de preparação.
  - **Testes/validação:** `docker compose up -d web` e
    `curl -fsS http://localhost:8080/up` retornando sucesso.
  - **Depende de:** T008.
  - **Critério de conclusão:** `api` e `web` saudáveis, com a verificação HTTP
    respondendo pelo endpoint de prontidão.

- [x] **T011** Serviço `setup`
  - **Objetivo:** preparar banco e storage antes de a aplicação subir, e então
    encerrar.
  - **Arquivos previstos:** `docker-compose.yml`, `docker/backend/setup.sh`.
  - **Requisitos:** RNF-009, RNF-019, RNF-020; plan §16.2.
  - **Implementação:** aguarda `mysql` e `rustfs` saudáveis; cria o bucket e
    aplica o CORS com os arquivos promovidos em T003; roda as migrations
    disponíveis; sai com código zero. **Neste momento só existem as migrations
    padrão do Laravel** — as de domínio e os seeds chegam na Fase 3, em T022. O
    script é escrito para evoluir sem mudar de forma.
  - **Testes/validação:** `docker compose up setup` termina com código zero, e o
    bucket passa a existir — o que não acontecia ao fim de T008.
  - **Depende de:** T010.
  - **Critério de conclusão:** bucket e CORS criados pelo `setup`, migrations
    padrão aplicadas.

- [x] **T012** Serviços restantes e inicialização do ambiente
  - **Objetivo:** completar os oito serviços e deixar o ambiente subindo com um
    comando, sem segredo real no repositório.
  - **Arquivos previstos:** `docker-compose.yml`, `backend/config/queue.php`,
    `.env.example`, `backend/.env.example`, `frontend/.env.example`, `Makefile`.
  - **Requisitos:** ABERTO-004, ABERTO-005, ABERTO-012; RNF-010, RNF-018,
    RNF-020; plan §§13.1, 16.
  - **Implementação:** `worker` e `simulator-worker` reutilizam a imagem de `api`,
    mudando apenas o comando e a fila. `frontend` depende de `web` saudável.
    **Nenhum Redis.**

    **A infraestrutura de filas é configurada aqui**, porque é ela que permite aos
    dois processos existirem: conexão de fila no driver `database`, `worker`
    consumindo `default`, `simulator-worker` consumindo `simulator`, os comandos
    definitivos dos dois no Compose, as opções operacionais já aprovadas no plano
    — três tentativas, backoff de 10, 30 e 60 segundos, timeout de 60 segundos,
    falha final em `failed_jobs` — e as variáveis correspondentes nos arquivos de
    exemplo.

    Configurada não é o mesmo que em uso: os dois processos sobem e consultam as
    respectivas filas, que permanecem vazias porque **nenhum job da aplicação
    existe ou é despachado nesta etapa**. O primeiro chega em T055.

    As tabelas de fila que eles consultam existem desde T011, criadas pelas
    migrations padrão do skeleton, e são **temporárias**: T022 as substitui pelas
    definitivas do projeto.

    Na mesma tarefa, os três `.env.example` com **apenas placeholders** —
    endereços interno e público do storage, credenciais, banco e segredo do
    webhook — e um `Makefile` cujo alvo de subida é o comando único da entrega.
    As duas coisas andam juntas porque o arquivo de exemplo só fica correto
    depois que o Compose declara todas as variáveis que consome.
  - **Testes/validação:** `cp .env.example .env && make up` sobe o ambiente a
    partir do arquivo de exemplo, e a conferência é por `docker compose ps -a`,
    **não** por `docker compose ps`: o segundo omite container encerrado, e é
    justamente um deles que precisa ser inspecionado.

    Os oito serviços do Compose não têm o mesmo desfecho esperado, e confundi-los
    faria uma subida correta parecer defeituosa:

    - **sete processos permanentes em `Up`** — `mysql`, `rustfs`, `api`, `web`,
      `worker`, `simulator-worker` e `frontend`;
    - **um serviço temporário concluído**, `setup`, em `Exited (0)`. Ele prepara
      banco e storage e termina; sair é o sucesso dele, não uma queda.

    Nenhum deles em reinício em laço.

    Cada consumidor é comprovado por evidência independente, e **não** por uma
    frase informativa específica no log — mensagem de cortesia do framework é
    contingente, e prender a validação a ela testaria a formatação, não o
    processo. O que se exige de cada um: o container em execução; o comando do
    processo `1`, lido de `/proc/1/cmdline`, nomeando a fila e as opções
    operacionais; `queue:monitor` alcançando a fila de dentro daquele container;
    a conexão viva com o MySQL, visível em `information_schema.processlist`;
    `jobs` e `failed_jobs` vazias; e nenhum erro nos logs.

    `docker compose config | grep -i redis` não retorna nada, e
    `grep -rIn "password\|secret\|key" .env.example` mostra somente
    placeholders.
  - **Depende de:** T011.
  - **Critério de conclusão:** oito serviços contabilizados — sete processos
    permanentes em execução e o `setup` concluído com sucesso (`Exited (0)`) —,
    sem reinício em laço, sem Redis, os dois workers consultando suas filas com a
    conexão em banco configurada, e o ambiente subindo com um comando a partir do
    arquivo de exemplo.

> **Checkpoint da Fase 1**
> **Serviços definidos:** os oito do plano — `mysql`, `rustfs`, `setup`, `api`,
> `web`, `worker`, `simulator-worker` e `frontend`.
> **Efetivamente prontos:** `mysql`, `rustfs`, `setup`, `api`, `web` e
> `frontend`. O `setup` já cria bucket e CORS e aplica as migrations existentes.
> **Definidos e configurados, mas ainda sem trabalho:** a infraestrutura de filas
> está ativa — conexão em banco, `worker` em `default` e `simulator-worker` em
> `simulator` —, e os dois processos sobem e consultam suas filas. Elas
> permanecem vazias porque nenhum job da aplicação existe ou é despachado antes de
> T055. As tabelas que eles leem são as do skeleton, e T022 as substitui pelas
> definitivas. Não afirmamos que estão fazendo trabalho de aplicação.
> **Testes verdes:** nenhum de aplicação; não há código de negócio ainda.
> **Comandos:** `make up`, `docker compose ps`,
> `docker compose run --rm frontend npm run build`.
> **Ainda não iniciado:** domínio, migrations de domínio, seeds, autenticação,
> endpoints e telas.

---

## Fase 2 — Fundação do backend e contrato HTTP

O contrato de resposta é estabelecido **antes** do primeiro endpoint, para que
nenhuma rota nasça num formato que depois precise ser reescrito.

- [x] **T014** Arquitetura base, qualidade e infraestrutura de contrato HTTP
  - **Objetivo:** a árvore do backend refletindo a arquitetura decidida, com
    ferramental de qualidade rodando e o contrato de resposta pronto antes do
    primeiro endpoint.
  - **Arquivos previstos:** `backend/app/Identity/`, `backend/app/Catalog/`,
    `backend/app/Video/` e `backend/app/Shared/`, cada uma com `Domain/`,
    `Application/`, `Infrastructure/` e `Interfaces/`;
    `backend/app/Shared/Domain/Failure/` com o catálogo de falhas e
    `backend/app/Shared/Domain/Exception/` com a falha de domínio;
    `backend/app/Shared/Interfaces/Http/Resource/` com o recurso base, a base de
    coleção, a normalização de tamanho de página e a resposta paginada;
    `backend/app/Shared/Interfaces/Http/Problem/` com o corpo de erro, o
    mapeamento para status HTTP e o tratamento centralizado;
    `backend/bootstrap/app.php`; `backend/routes/api.php`; `backend/pint.json`;
    `backend/phpunit.xml`; `backend/tests/TestCase.php` e
    `backend/tests/Support/` com a escolha do schema de testes;
    `backend/tests/Feature/Shared/HttpContractTest.php`;
    `backend/tests/Unit/Support/TestDatabaseTest.php`; o comentário de
    `backend/tests/Feature/HealthEndpointTest.php`; e `docker-compose.yml`, que
    entrega o schema de testes ao serviço `api`.

    `backend/composer.json` **não** entra: o mapeamento `App\` já cobre as áreas
    novas, e acrescentar entradas por área produziria diff sem efeito.
  - **Requisitos:** ABERTO-010, ABERTO-011, ABERTO-013; RNF-004, RNF-005,
    RNF-008; RN-AUT-005; RF-WHK-011; RF-UI-009, RF-UI-010, RF-UI-017;
    plan §§5.1, 5.2, 10.1, 10.2, 10.3, 17.1.
  - **Implementação:** criar a árvore e ajustar o autoload PSR-4. Nenhuma classe
    de negócio ainda. Sem repositório genérico, CQRS ou event sourcing.

    Pint com preset adotado e PHPUnit apontando para o banco de testes criado em
    T007 — os dois disponíveis por comando desde o início, porque toda tarefa
    seguinte fecha com eles.

    Recurso e coleção sob `data`; coleção paginada acrescenta `meta` e `links`,
    com `per_page` padrão 15 e teto 50 — valor acima é limitado a 50, não recusado.
    Erros em `application/problem+json` com `type`, `title`, `status`, `detail`,
    `code` e, na validação, `errors` por campo. Nenhum rastro de execução no
    corpo.

    O catálogo de falhas públicas é uma **enumeração fechada** de código e
    mensagem: quem registra uma falha escolhe um caso declarado, e não existe
    caminho que aceite texto livre vindo de fora. É o que faz RN-AUT-005 e
    RF-WHK-011 valerem por construção. Exceções de domínio carregam código
    funcional estável.

    **`404` e `405` são respostas distintas.** Rota inexistente responde `404`;
    rota existente chamada com verbo errado responde `405`, com `code`
    `METHOD_NOT_ALLOWED` e o cabeçalho `Allow` **preservado** — sem ele a
    resposta diria que o método está errado sem dizer qual serve. É o
    significado padrão do HTTP, e ajuda o diagnóstico sem revelar detalhe
    interno (plan §10.3).

    **O schema de testes é escolhido em `tests/TestCase.php`**, antes de a
    aplicação de teste ser criada — e não num bootstrap global da suíte. Assim a
    seleção vale apenas para os testes de feature e integração, que de fato
    inicializam o framework. Os unitários de domínio são PHP puro e continuam
    rodando sem banco, sem variável de ambiente e sem framework, que é o retorno
    prático da separação de camadas (plan §17.2).
  - **Testes/validação:** `docker compose run --rm api composer dump-autoload`
    sem aviso; `docker compose run --rm api ./vendor/bin/pint --test`; e uma rota
    de teste temporária exercitando envelope, paginação e os formatos de erro,
    por `docker compose run --rm api php artisan test --filter=HttpContract`.
    O teste afirma também que o catálogo de falhas não admite mensagem arbitrária.

    Provas adicionais: campo condicional declarado com `when()` desaparecendo do
    recurso individual **e** de dentro da resposta paginada, e aparecendo quando
    a condição é verdadeira; verbo incorreto respondendo `405` com `Allow`
    contendo os métodos aceitos; as exceções que os casos de uso realmente vão
    lançar — falha de autorização em `403` e modelo ausente em `404`, sem citar
    a classe nem o identificador procurado. E três provas negativas do banco: a
    suíte unitária passa com `DB_TEST_DATABASE` vazia; a de feature falha, antes
    do bootstrap do framework, quando essa variável está ausente ou vazia; e
    falha também quando ela coincide com o banco da aplicação.
  - **Depende de:** T012.
  - **Critério de conclusão:** árvore criada, autoload e ferramental verdes, e os
    formatos de resposta disponíveis e testados antes de existir endpoint de
    negócio.

- [x] **T017** `VideoState` e a tabela de transições
  - **Objetivo:** um único lugar que responde se uma transição é permitida.
  - **Arquivos previstos:** `backend/app/Video/Domain/VideoState.php`,
    `backend/tests/Unit/Video/VideoStateTest.php`.
  - **Requisitos:** RF-VID-001; RN-VID-001 a 003; AC-VID-008; plan §6.2.
  - **Implementação:** os seis estados e exatamente as transições da tabela do
    plano. Saltos e regressões rejeitados. `ready` e `failed` terminais. É o
    value object que carrega regra, e o único do domínio com teste próprio.
  - **Testes/validação:** teste percorrendo a matriz completa de origem e destino.
    `docker compose run --rm api php artisan test --filter=VideoState`.
  - **Depende de:** T014.
  - **Critério de conclusão:** AC-VID-008 coberto e verde.

> **Checkpoint da Fase 2**
> **Passa a funcionar:** o domínio existe em PHP puro e responde pela regra de
> estado do vídeo; o contrato HTTP existe antes do primeiro endpoint, com
> envelope, paginação e formato de erro testados.
> **Testes verdes:** `VideoState`, contrato HTTP e catálogo de falhas.
> **Comandos:** `docker compose run --rm api php artisan test`,
> `docker compose run --rm api ./vendor/bin/pint --test`.
> **Ainda não iniciado:** persistência, endpoints, autenticação, frontend.

---

## Fase 3 — Persistência e dados de avaliação

- [x] **T022** Esquema completo e dados de avaliação
  - **Objetivo:** o banco inteiro aplicando e revertendo, com o cenário de
    avaliação semeado pela subida do ambiente.
  - **Arquivos previstos:** migrations de infraestrutura e de domínio,
    `backend/database/seeders/`, `docker/backend/setup.sh` atualizado,
    `docs/demonstracao.md` iniciado com o identificador do cenário de falha.
  - **Requisitos:** RF-CUR-001; RF-AUL-005; RF-CONS-006; RF-WHK-008; RF-PROC-006;
    RN-ORD-003; RN-CUR-001; RN-AUT-003; RN-VID-002; RN-IDM-002, RN-IDM-003;
    RN-VID-006 a 008; AC-E2E-001; RNF-009, RNF-019; ABERTO-001, ABERTO-003,
    ABERTO-004; spec seção 4; plan §§7.1, 7.2.
  - **Implementação:** em uma leva, na ordem de dependência de chave estrangeira.

    **Os dois workers param antes, e voltam depois.** `worker` e
    `simulator-worker` estão de pé desde T012 e consultam as tabelas de fila do
    skeleton. Substituir essas tabelas com os processos ativos os faria consultar
    um esquema em transformação. A sequência é obrigatória:

    1. `docker compose stop worker simulator-worker`;
    2. substituir e remover as migrations padrão afetadas;
    3. executar as migrations e os seeds definitivos;
    4. validar o banco;
    5. `docker compose up -d worker simulator-worker`;
    6. confirmar que os dois voltaram saudáveis e seguem aguardando trabalho.

    **Transição a partir do scaffold.** A T011 aplicou as três migrations
    originais do scaffold, e elas criaram oito tabelas: `users`,
    `password_reset_tokens`, `sessions`, `cache`, `cache_locks`, `jobs`,
    `job_batches` e `failed_jobs`. Alterar, substituir ou remover aqueles
    arquivos antes de desfazer o que eles aplicaram deixaria o registro de
    controle apontando migrations que já não existem na forma original — e sem os
    métodos `down` correspondentes não haveria como reverter. A ordem abaixo é
    obrigatória:

    1. Consultar o estado das migrations antes de tocar em qualquer arquivo.
    2. Se o lote aplicado na T011 ainda constar, revertê-lo enquanto os três
       arquivos originais existem e mantêm seus métodos `down` intactos.
    3. Confirmar que as oito tabelas acima deixaram de existir.
    4. Só então substituir as migrations do scaffold.

    Em um banco novo, no qual o lote da T011 nunca chegou a ser aplicado, não há
    rollback inicial a executar: os passos 1 a 3 apenas constatam isso, e a
    sequência segue a partir do passo 4. A transição é segura exatamente nesta
    altura porque não há o que perder: nenhum seed foi executado e nenhum dado de
    domínio existe.

    **Infraestrutura.** Apenas `sessions`, `cache`, `cache_locks`, `jobs` e
    `failed_jobs`, no esquema padrão do framework — com uma exceção declarada:
    `sessions.user_id` nasce `CHAR(36)` anulável, indexado, com charset `ascii`,
    collation `ascii_bin` e **sem** chave estrangeira, porque é ali que o
    framework grava o identificador do usuário autenticado e `users.id` é um
    UUID; o `BIGINT` da migration padrão não comporta o UUID textual, a gravação
    da sessão falharia sob o modo estrito do MySQL e o fluxo de autenticação não
    conseguiria persistir a sessão (plan §7.1). **`job_batches` não é
    recriada** — batching não é usado por decisão alguma do plano — e
    **`password_reset_tokens` também não**, porque recuperação de senha está fora
    de escopo. O driver de cache é `database` e não `file`, porque o lock de
    conclusão precisa ser enxergado por `api`, `worker` e `simulator-worker`.

    **Domínio.** `users` com `id` em `CHAR(36)` charset `ascii` e collation
    `ascii_bin`, `role` restrito a produtor ou consumidor e e-mail único —
    escrita do zero, porque a versão demonstrativa do scaffold saiu na transição
    acima. Depois `courses`, `modules` e `lessons`, com estado do curso restrito
    a `draft` e `available`, unicidade de curso mais posição e de módulo mais
    posição, `published_at` anulável e índices para leitura ordenada e listagem
    por dono. `lessons` **nasce sem** `current_video_attempt_id`: a coluna e sua
    chave estrangeira entram numa migration posterior, depois de `video_attempts`
    existir, e a reversão remove primeiro a coluna e depois a tabela. Em seguida
    `video_attempts`, com `storage_key` única e os campos declarados separados dos
    verificados; `course_access_grants`, com unicidade de curso mais consumidor; e
    `webhook_events`, com `event_id` único, `received_video_id` **sem** chave
    estrangeira — um evento válido pode apontar para tentativa inexistente e ainda
    assim precisa de desfecho registrado —, `video_attempt_id` anulável com chave
    estrangeira, e `outcome` anulável para permitir a reserva.

    Todos os identificadores são UUIDv7 em `CHAR(36)`, e charset e collation
    seguem o que o plano §7 determina.

    **Seeds.** Produtor e consumidor de demonstração com credenciais fictícias;
    um curso vazio do produtor, em `draft`; a concessão desse curso ao consumidor;
    e um segundo produtor com curso próprio, para provar isolamento. Os seeds
    escrevem pelo query builder, diretamente nas tabelas: nesta altura da ordem
    não existem repositórios de `Course`, `Module`, `Lesson` ou `VideoAttempt`, e
    antecipá-los inverteria a dependência.

    **O curso concedido ao consumidor permanece vazio**, sem módulo nem aula: é
    esse vazio que a jornada AC-E2E-001 preenche.

    **Cenário dedicado de falha**, separado e também do produtor de demonstração:
    um curso próprio, com módulo e aula próprios, e uma tentativa de vídeo em
    `processing`. O curso concedido ao consumidor **não é tocado**. O
    identificador da tentativa é este UUIDv7 **fixo, fictício e documentado**, não
    gerado a cada execução:

    ```
    01936f1a-7c00-7a3e-9b7d-2f5c8e4a1d60
    ```

    É ele que o comando de falha de T067 e o roteiro de T105 citam
    **literalmente**, e um valor aleatório tornaria a demonstração
    irreproduzível. É dado fictício de seed, não segredo. Nenhum arquivo real
    precisa existir no storage para este cenário.

    O `setup` passa a executar os seeds.
  - **Testes/validação:** rollback do scaffold executado antes de qualquer
    alteração nos arquivos, sempre que o lote original estiver aplicado.
    `docker compose run --rm api php artisan migrate` e
    `docker compose run --rm api php artisan migrate:rollback` — ambos sem erro,
    revertendo na ordem inversa e sem violação de chave estrangeira. Ao final:
    **cinco** tabelas de infraestrutura presentes, sem contar a tabela de controle
    `migrations`; `password_reset_tokens` e `job_batches` ausentes; e as
    migrations reaplicadas.

    Contra MySQL real: e-mail duplicado rejeitado; duas posições iguais no mesmo
    pai rejeitadas; segundo par curso mais consumidor rejeitado; `event_id`
    repetido rejeitado e evento cujo `received_video_id` não existe em
    `video_attempts` aceito.

    `docker compose run --rm api php artisan test --filter=Schema` contra o banco
    já migrado.

    **Idempotência do seed, provada sem recriar o banco.** `migrate:fresh --seed`
    não serve como prova: ele apaga tudo antes de semear, então a segunda execução
    começa de um banco vazio e não exerce a repetição. A sequência é:

    1. preparar o banco uma vez;
    2. `docker compose run --rm api php artisan db:seed --force`;
    3. `docker compose run --rm api php artisan db:seed --force` de novo, **sem**
       `migrate:fresh` entre as duas;
    4. conferir que nenhuma duplicata apareceu e que as contagens por tabela e os
       identificadores esperados — incluindo o do cenário de falha — permaneceram
       estáveis entre as duas execuções.

    E `--filter=Seed` afirmando que o curso concedido ao consumidor continua
    **sem módulos e sem aulas**.
  - **Depende de:** T017.
  - **Critério de conclusão:** esquema aplicando e revertendo, cenário de
    avaliação e cenário de falha semeados pelo `setup`, seed comprovadamente
    idempotente em duas execuções seguidas sobre o mesmo banco, workers de volta e
    saudáveis, e nenhuma tabela fora das declaradas aqui.

> **Checkpoint da Fase 3**
> **Passa a funcionar:** o esquema completo aplica e reverte na ordem correta, o
> `setup` popula os dados de avaliação na subida, e as tabelas definitivas de fila
> e de lock substituem as do skeleton. A infraestrutura de filas está ativa desde
> T012, mas nenhum job funcional da aplicação existe ou é despachado ainda.
> **Testes verdes:** `VideoState` e contrato HTTP da Fase 2, mais os testes de
> esquema e de seed contra MySQL real.
> **Comandos:** `docker compose up setup`,
> `docker compose run --rm api php artisan migrate:fresh --seed`,
> `docker compose run --rm api php artisan test`.
> **Ainda não iniciado:** agregados, repositórios, endpoints, autenticação,
> upload, o primeiro job da aplicação, frontend.

---

## Fase 4 — Autenticação e autorização

- [x] **T030** Sessão, endpoints de autenticação e autorização por perfil
  - **Objetivo:** as duas personas entram pela API, com sessão em cookie, e cada
    rota exige o perfil certo.
  - **Arquivos previstos:** `backend/config/sanctum.php`,
    `backend/config/session.php`, `backend/config/cors.php`, `.env.example`;
    rotas, controller e requests em `Identity/Interfaces/Http/`; middleware de
    perfil; handler de exceções ajustado; `backend/app/Models/User.php` e
    `backend/database/factories/UserFactory.php`.
  - **Requisitos:** ABERTO-001; RF-AUT-001, RF-AUT-005 a RF-AUT-009; RN-AUT-001,
    RN-AUT-002, RN-AUT-005; RF-UI-013, RF-UI-014; AC-UI-003; RNF-010;
    plan §§9, 10.3, 10.4.
  - **Implementação:** Sanctum em modo SPA, driver de sessão em banco, cookie
    `HttpOnly`, `SameSite=Lax`, `Secure` sob HTTPS. Três configurações distintas,
    não uma lista repetida: `SANCTUM_STATEFUL_DOMAINS` com hosts e porta,
    `SESSION_DOMAIN` com o domínio do cookie, e `allowed_origins` do CORS com
    origens completas. `supports_credentials` verdadeiro, nunca curinga.

    `POST /api/auth/login`, `POST /api/auth/logout` e `GET /api/auth/me`, usando o
    envelope e o `problem+json` de T014. Credencial inválida não revela se o
    e-mail existe.

    Middleware de perfil: rotas de gestão exigem produtor, rotas de consumo
    exigem consumidor, e a falha resulta em `403`. A checagem de perfil **não**
    substitui a de propriedade, que vive no caso de uso e chega em T034.

    **A chave de criptografia da aplicação passa a ser obrigatória aqui, e não
    antes.** Quem participa da sessão HTTP do navegador é a **API** — é ela que
    emite e lê o cookie. A chave é o que permite ao framework cifrar e assinar,
    e o cookie da autenticação depende disso: sem ela, não há sessão.

    Todos os containers PHP recebem **a mesma** chave, porque executam a mesma
    aplicação Laravel e precisam de uma configuração criptográfica coerente —
    qualquer um deles pode cifrar ou verificar um valor, e duas chaves seriam
    duas aplicações. Isso **não** significa que `setup`, `worker` e
    `simulator-worker` leiam normalmente a sessão do navegador; eles não
    participam do fluxo HTTP autenticado.

    A chave real é **gerada localmente** e fica **fora do Git**, em arquivo de
    ambiente ignorado — nunca como valor versionado.

    **As mensagens de validação em português nascem aqui.** Até T014 o contrato
    garantia o *formato* de `errors`; o texto de cada campo ainda vinha do
    idioma padrão do framework. Esta tarefa escreve as mensagens em português,
    usando arquivos de tradução do próprio projeto ou mensagens declaradas nos
    Form Requests — **sem acrescentar dependência só para traduzir**, o que
    contrariaria o critério de não somar componente sem necessidade
    demonstrada. Um teste afirma que `errors` devolve mensagem em português.

    **O `User` e sua factory ainda são os do esqueleto, e alinhá-los ao esquema
    é o primeiro passo desta tarefa** — antes dos testes e dos endpoints de
    autenticação, que dependem de conseguir criar usuários. A T022 gravou a
    tabela definitiva: chave `CHAR(36)` não incremental, `role` obrigatório, e
    **sem** `email_verified_at` nem `remember_token`.

    O desencontro é concreto e vale descrever sem exagero. Uma consulta por
    e-mail funciona com o model atual; o que não funciona é o resto:

    - o model declara metadados inadequados para uma chave UUID — ele pressupõe
      identificador numérico auto-incremento;
    - a factory tenta gravar `email_verified_at` e `remember_token`, colunas que
      não existem mais;
    - a factory não fornece `role`, que é obrigatório, nem um identificador que
      o esquema aceite.

    Somando os três: **criar usuários e escrever testes de autenticação fica
    incompatível com o esquema** enquanto as duas classes não forem ajustadas.

    O ajuste, com uma regra que organiza o resto — **quem gera o identificador é
    o model, e só ele**:

    - o model adota o trait `HasUuids` do framework, que nesta versão gera
      **UUIDv7** e já declara a chave como string não incremental, dispensando
      gerador próprio e configuração manual de `$keyType` e `$incrementing`;
    - a **factory não declara nem gera `id`**. Ela deixa a geração para o model,
      e é isso que evita duas fontes produzindo o mesmo identificador — duas
      fontes divergem em silêncio, e a que estiver errada só aparece quando
      alguém compara os valores;
    - a factory usa um `role` válido como padrão e oferece **estados
      explícitos** `producer` e `consumer`, os únicos que o enum do banco
      aceita;
    - `fillable`, `hidden` e `casts` passam a citar **apenas colunas
      existentes**, com `role` onde for necessário, e as referências a
      `email_verified_at` e `remember_token` saem das duas classes.

    Os testes desta tarefa criam usuários **pelos dois estados da factory**, e
    não por escrita direta: é o que prova que o model persiste de verdade contra
    o esquema, que o identificador **produzido pelo model** é UUIDv7 e que os
    dois perfis funcionam.

    **Credencial inválida responde `422`, sempre igual** (RF-AUT-008). E-mail
    inexistente e senha incorreta produzem a mesma resposta — mesmo status, mesmo
    `code`, mesmo campo e mesmo texto genérico —, porque diferenciá-las
    transformaria o login num verificador de cadastro. O `401` fica reservado à
    ausência de sessão.

    **Token CSRF ausente ou inválido responde `419`** com o código
    `CSRF_TOKEN_MISMATCH`, em `problem+json`, e a operação **não é executada**
    (RF-AUT-009). O código entra no catálogo compartilhado junto do mapeamento de
    status; os códigos já existentes são preservados, e a falha de CSRF nunca vira
    `500`.

    **A chave de criptografia passa a ser gerada pela subida oficial do
    ambiente.** Um script versionado detecta chave ausente ou vazia no arquivo de
    ambiente da raiz, gera 32 bytes aleatórios dentro da imagem do backend — o
    host não tem PHP —, grava apenas aquela linha e **nunca substitui uma chave
    existente**. O valor não é impresso em nenhuma saída, e nenhum arquivo de
    exemplo carrega chave funcional. A preparação do ambiente recusa subir com
    chave ausente ou malformada, antes de tocar em banco ou storage.

    Ausência ou expiração de sessão produz `401`; perfil sem permissão produz
    `403`. Cada um com código funcional estável — é o que permite ao frontend
    distinguir reautenticar de não pode. O `404` de recurso alheio é acrescentado
    em T034, quando existir recurso para ocultar.
  - **Testes/validação:** a suíte roda com **sessão em banco**, e não em
    memória: uma sessão que não grava linha não prova nada sobre a coluna
    `user_id` em `CHAR(36)` nem sobre a persistência que a autenticação depende.

    `docker compose run --rm api php artisan test --filter=Auth`, cobrindo: os
    dois estados da factory e o identificador UUIDv7 gerado pelo model; login das
    duas contas de demonstração; sessão gravada no MySQL com o UUID completo;
    regeneração do identificador da sessão ao autenticar; o cookie anterior
    deixando de abrir a sessão após o logout; respostas idênticas para e-mail
    inexistente e senha incorreta; mensagens de validação em português; ausência
    de senha e de qualquer segredo nas respostas; `401` sem sessão; `403` para
    perfil incorreto; cada perfil alcançando a própria área; `419` com token
    ausente ou inválido, sem executar a operação; preflight de CORS autorizando a
    origem exata com credenciais e recusando a de terceiro; ausência de
    `personal_access_tokens` e o esquema preservado em treze tabelas.

    **O teste de CSRF precisa desligar o desvio de ambiente.** O middleware do
    framework libera a requisição sem olhar o token quando a aplicação está em
    ambiente de teste; um teste que envie um POST sem token e receba sucesso
    comprova o desvio, não a proteção. A suíte troca o ambiente antes da
    requisição, e uma asserção confirma que o desvio ficou realmente desligado.

    As rotas usadas para exercitar o middleware de perfil são registradas **pela
    própria suíte** e não existem no arquivo de rotas da aplicação.

    Complementando a suíte, um **fluxo HTTP real** contra o serviço web, com
    cookie jar: obter o cookie CSRF, provar o `419` sem token, autenticar com o
    token correto, consultar a sessão, encerrar e confirmar que a consulta volta a
    responder `401`; comparar as duas recusas de credencial; e conferir o CORS das
    duas origens. É onde a troca de cookies acontece entre processos separados,
    sem container compartilhado.

    Para a chave: cenários em arquivos temporários — variável vazia, linha
    ausente e chave já preenchida —, provando que ela é criada quando falta,
    preservada quando existe, decodifica para exatamente 32 bytes e nunca aparece
    na saída. Depois, `make up` duas vezes, confirmando os sete serviços
    permanentes, o `setup` em `Exited (0)` e a mesma chave preservada.
  - **Depende de:** T022.
  - **Critério de conclusão:** autenticação e logout por sessão em cookie, com a
    sessão persistida no MySQL; `401` e `403` distinguíveis por status e por
    código; credencial inválida sempre no mesmo `422`; token de CSRF ausente ou
    inválido em `419` sem executar a operação; chave da aplicação gerada na
    subida e preservada nas seguintes; e o esquema sem tabela de tokens
    pessoais.

> **Checkpoint da Fase 4**
> **Passa a funcionar:** login e logout pela API com sessão em cookie, rotas
> separadas por perfil, e `401` distinto de `403`.
> **Testes verdes:** sessão, endpoints de autenticação, perfil e mapeamento de
> erro.
> **Comandos:** `docker compose run --rm api php artisan test --testsuite=Feature`.
> **Ainda não iniciado:** catálogo, propriedade, upload, fila, webhook, frontend.

---

## Fase 5 — Catálogo do produtor

Cada agregado chega junto do seu repositório e dos seus endpoints — decomposição
vertical. Nenhum adapter é criado antes do agregado que ele persiste.

- [x] **T034** Agregado `Course`, isolamento por propriedade e endpoints de curso
  - **Objetivo:** o produtor cria, lista e consulta os próprios cursos, e não
    alcança nem descobre os alheios.
  - **Arquivos previstos:** `Catalog/Domain/Course.php`,
    `Catalog/Application/Port/CourseRepository.php`,
    `Catalog/Infrastructure/Persistence/Eloquent/`,
    `Catalog/Application/CreateCourse/`, `ListCourses/`, `GetCourse/`, serviço de
    autorização, controller, request, resource, handler de exceções ajustado.
  - **Requisitos:** RF-CUR-001 a 004; RN-PROP-001 a 003, RN-PROP-005; RN-AUT-006;
    RN-CUR-001; AC-PROD-001, AC-PROD-002, AC-PROD-007; plan §§5.3, 6.1, 9.3.
  - **Implementação:** `POST /api/courses` com título e descrição; estado inicial
    `draft`, dono vindo da sessão — nunca do corpo. `GET /api/courses` paginado no
    formato de T014, e `GET /api/courses/{course}` trazendo identificador, título,
    descrição, proprietário, estado e data de criação.

    O filtro por dono vai **na consulta SQL**, não depois de carregar. Recurso de
    terceiro e recurso inexistente produzem a mesma resposta `404`, com o mesmo
    corpo. O repositório expõe apenas o que estes casos de uso e os próximos
    precisam, incluindo leitura travada da linha do curso. Sem repositório
    genérico.
  - **Testes/validação:** unitário do agregado; feature do endpoint com `422` em
    entrada inválida, isolamento na lista e no detalhe, padrão de 15 e teto de 50;
    integração do repositório contra MySQL real; e a comparação entre a resposta
    para identificador de outro produtor e para identificador inexistente —
    indistinguíveis.
    `docker compose run --rm api php artisan test --filter=Course`.
  - **Depende de:** T030.
  - **Critério de conclusão:** AC-PROD-001, AC-PROD-002 e AC-PROD-007 verdes.

- [x] **T037** Módulos, aulas, ordem de criação e estrutura do curso
  - **Objetivo:** a árvore do curso existindo, ordenada, e consultável de uma vez.
  - **Arquivos previstos:** `Catalog/Domain/Module.php`, `Catalog/Domain/Lesson.php`,
    portas e adapters de `ModuleRepository` e `LessonRepository`,
    `Shared/Application/Port/TransactionManager.php` e seu adapter em
    `Shared/Infrastructure/Transaction/`, porta de leitura da estrutura com DTOs
    imutáveis em `Catalog/Application/`, `Catalog/Application/CreateModule/`,
    `CreateLesson/`, `ListModules/`, `GetLesson/`, `GetCourseStructure/`,
    controllers, requests, resources.
  - **Requisitos:** RF-MOD-001 a 004, RF-MOD-006, RF-MOD-007; RF-AUL-001 a 004,
    RF-AUL-008, RF-AUL-009; RF-EST-001 a 004; RN-ORD-001 a 004; AC-PROD-003;
    plan §§5.3, 6.1, 7.4, 8.1, 10.4.
  - **Implementação:** `POST /api/courses/{course}/modules` e
    `POST /api/modules/{module}/lessons`. **A posição não vem no corpo:** o caso
    de uso trava a linha do pai, lê a maior posição existente e grava a seguinte
    (RN-ORD-002). Nenhum item já criado é reescrito. A UNIQUE dentro do pai
    permanece como última linha de defesa. A aula nasce rascunho, sem vídeo.

    O limite da transação é declarado pelo caso de uso através da porta
    `TransactionManager` (plan §§5.3, 7.4): lock do pai, cálculo da posição e
    inserção formam uma operação só. O adapter Laravel de Shared/Infrastructure é
    o único que chama `DB::transaction` — `Application` continua sem importar
    `Illuminate`.

    Leituras: `GET /api/courses/{course}/modules` e `GET /api/lessons/{lesson}`,
    sempre ordenados por posição. E `GET /api/courses/{course}/structure`, que
    devolve curso, módulos e aulas organizados — a visão do produtor inclui
    rascunhos e o estado do vídeo de cada aula. A árvore **não é paginada**: a
    paginação quebraria a ordem que a spec exige preservar.
  - **Testes/validação:** feature confirmando que três criações sucessivas ocupam
    as posições 1, 2 e 3; que a quarta ocupa a 4 sem alterar as anteriores; que o
    mesmo vale para aulas dentro do módulo; que duas leituras consecutivas
    retornam a mesma sequência; que módulo em curso alheio responde `404`; e que a
    aula nasce sem `published_at`. Mais a estrutura completa em ordem, sem
    conteúdo de outro produtor.
    `docker compose run --rm api php artisan test --filter=Structure`.
  - **Depende de:** T034.
  - **Critério de conclusão:** AC-PROD-003 e RF-EST cobertos e verdes.

> **Checkpoint da Fase 5**
> **Passa a funcionar:** o produtor cria e consulta cursos, módulos e aulas, com
> ordem de criação preservada, isolamento entre produtores e estrutura completa.
> Publicação ainda **não existe** como operação — ela chega em T053, depois de o
> agregado de vídeo existir.
> **Testes verdes:** agregados, repositórios, endpoints de catálogo, ocultação de
> existência e ordem.
> **Comandos:** `docker compose run --rm api php artisan test`,
> `docker compose run --rm api ./vendor/bin/pint --test`.
> **Ainda não iniciado:** vídeo, upload, publicação, fila, webhook, frontend.

---

## Fase 6 — Upload multipart

- [x] **T042** Porta `ObjectStorage` e adapter do RustFS
  - **Objetivo:** isolar o storage atrás de um contrato do problema, não da AWS.
  - **Arquivos previstos:** `Video/Application/Port/ObjectStorage.php`,
    `Video/Infrastructure/Storage/`, configuração de endereço interno e público.
  - **Requisitos:** ABERTO-002; RNF-001; AC-VID-001; plan §5.3.
  - **Implementação:** a porta fala em criar envio, emitir URL de parte, concluir,
    inspecionar e emitir URL de leitura. O adapter traduz para o SDK. Endereço
    interno para as chamadas do backend, endereço público para as URLs entregues
    ao navegador — assinar com o host errado gera URL que a API aceita e o
    navegador não alcança.
  - **Testes/validação:** integração contra o RustFS do Compose, exercitando cada
    operação — é o teste que substitui os scripts de validação removidos em T003.
    `docker compose run --rm api php artisan test --filter=ObjectStorage`.
  - **Depende de:** T037.
  - **Critério de conclusão:** integração verde contra storage real.

- [x] **T043** Agregado `VideoAttempt` e o fluxo completo de upload
  - **Objetivo:** abrir o envio, emitir URLs de parte, concluir com verificação no
    servidor, consultar o estado e permitir novo envio depois de falha.
  - **Arquivos previstos:** `Video/Domain/VideoAttempt.php`,
    `Video/Application/Port/VideoAttemptRepository.php` e adapter,
    `Video/Application/Port/AttemptLock.php` e `Video/Infrastructure/Lock/`,
    `Video/Application/OpenUpload/`, `IssuePartUrl/`, `CompleteUpload/`,
    `GetLessonVideo/`, controllers, requests, resources.
  - **Requisitos:** RF-UPL-001 a 013; RF-VID-002; RN-VID-001, RN-VID-002,
    RN-VID-005; RN-IDM-001; RF-ERR-001 a 003, RF-ERR-005; RF-UI-005 a 007;
    RNF-003; AC-VID-001, AC-VID-002, AC-VID-003, AC-VID-010, AC-VID-011,
    AC-VID-012; plan §§6.1, 8.2, 11.3, 12.2, 12.3, 12.4.
  - **Implementação:** o agregado usa `VideoState` de T017 para validar transição;
    o repositório expõe busca por identificador, por aula, leitura travada e
    gravação. Um repositório por raiz.

    **Abertura** — `POST /api/lessons/{lesson}/video/uploads` com nome, tipo e
    tamanho. Autoriza o dono da aula; valida tipo e tamanho contra limites
    configurados **no backend**, nunca informados pelo cliente; rejeita se houver
    tentativa ativa em `pending`, `uploading`, `uploaded` ou `processing`; aceita
    quando a anterior está em `failed`, criando nova tentativa em `pending` e
    apontando a aula para ela; deriva a `storage_key` do identificador da
    tentativa; grava metadados controlados com esse identificador; e cria o envio
    multipart.

    **Partes** — `POST /api/video-uploads/{attempt}/parts/{n}/url`, validade de 15
    minutos, renovação pela mesma rota. O **primeiro** pedido move de `pending`
    para `uploading`; os seguintes não mudam estado. Sem endpoint adicional de
    início.

    **Lock** — `video-upload-complete:{videoAttemptId}` pelo driver de cache
    `database`, com lease maior que o timeout do cliente S3 e liberação em
    `finally`. Um lock em arquivo não é visto pelos outros containers.

    **Conclusão** — `POST /api/video-uploads/{attempt}/complete`. Dentro do lock,
    a sequência do plano: transação curta que valida, commit,
    `CompleteMultipartUpload`, `HeadObject`, e nova transação que reavalia e
    transiciona. **Nenhuma transação MySQL aberta enquanto o storage responde.**
    Verificar chave esperada, tamanho declarado, `Content-Type` aceito e metadados
    com o identificador da tentativa; **confirmada de forma confiável** a falha em
    qualquer uma delas, a tentativa vai a `failed` com código e mensagem do
    catálogo. O `ETag` **não** é usado como checksum. Tentativa fora de
    `uploading` devolve o desfecho já obtido, sem novo processamento. Diante de
    `NoSuchUpload` ou resposta ambígua, o `HeadObject` decide: objeto válido
    reconcilia como conclusão anterior bem-sucedida; ausência confirmada leva a
    `failed`.

    **Só evidência confiável autoriza `failed`.** Quando a aplicação não consegue
    avaliar o objeto — indisponibilidade ou falha transitória; credencial,
    permissão, assinatura ou configuração incorreta; resposta que o adapter não
    reconheça com segurança — não há prova sobre o arquivo, e o desfecho é outro:
    **preserva `uploading`**, não inicia processamento, responde falha de
    infraestrutura e deixa a conclusão repetível depois que a integração estiver
    disponível ou corrigida (plan §12.4). A porta já entrega essa distinção
    classificada: `StorageUnavailable` é ausência de evidência, e não recusa do
    conteúdo.

    **Consulta** — `GET /api/lessons/{lesson}/video` devolve o estado e, quando
    houver, a informação pública de falha.
  - **Testes/validação:** feature para aula de outro produtor com `404`, tipo e
    tamanho inválidos com `422`, tentativa ativa com `409` e tentativa em `failed`
    aceita; transição no primeiro pedido de URL de parte; exclusão mútua do lock;
    conclusão com objeto ausente, tamanho divergente, tipo divergente e metadados
    ausentes — todas em `failed` com mensagem segura; conclusão repetida sem
    processamento adicional; os três desfechos do resultado ambíguo; envio
    interrompido permanecendo em `uploading` sem jamais alcançar `uploaded`; e
    todos os estados representados na consulta.

    **Os desfechos da conclusão são afirmados um a um**, porque o estado não os
    distingue: uma conclusão aceita agora e uma repetida terminam as duas em
    `uploaded`, e só a primeira enfileira trabalho (plan §10.3).

    | Situação | Resposta esperada no teste |
    | --- | --- |
    | Conclusão nova e válida | `202`, com **um** `ProcessVideoJob` despachado |
    | Repetição de conclusão aceita, em `uploaded`, `processing` ou `ready` | `200`, sem job novo e sem chamada ao storage |
    | Objeto ausente ou incompatível | `409` em `application/problem+json`, com o `code` gravado na tentativa |
    | Repetição da recusa | o mesmo `409` e o mesmo `code`, sem nova ida ao storage |
    | Conclusão sobre tentativa em `pending` | `409` com `VIDEO_UPLOAD_NOT_ACTIVE`, sem tocar no storage e sem enfileirar |
    | Ausência de evidência sobre o objeto | `503`, com a tentativa preservada em `uploading` e sem `failure_code` |

    Dois testes cobrem o que a forma da recusa protege: que o `failed` **fica
    persistido** depois do `409` — uma rejeição lançada de dentro da transação o
    desfaria junto —, e que um novo envio passa a ser aceito depois dela
    (RF-UPL-005).

    **Mais um cenário, sobre falha de integração:** com a porta `ObjectStorage`
    substituída por uma que produz `StorageUnavailable`, a conclusão precisa
    deixar a tentativa **em `uploading`**, não iniciar processamento nenhum,
    responder falha de infraestrutura e **não gravar `failure_code`** — nem
    qualquer outra marca definitiva. O teste usa a exceção da porta diretamente;
    não repete a configuração de credencial inválida, porque a tradução da
    resposta do provedor em `StorageUnavailable` já está coberta pela T042.
    `docker compose run --rm api php artisan test --filter=Upload`.
  - **Depende de:** T042.
  - **Critério de conclusão:** AC-VID-001, 002, 003, 010, 011 e 012 verdes.

> **Checkpoint da Fase 6**
> **Passa a funcionar:** o produtor abre um envio, recebe URLs de parte, envia
> direto ao storage e conclui; o servidor verifica o objeto antes de transicionar,
> e a conclusão é serializada e idempotente.
> **Testes verdes:** integração do adapter, abertura, URL de parte, conclusão,
> repetição, ambiguidade, substituição e interrupção.
> **Comandos:** `docker compose up -d`,
> `docker compose run --rm api php artisan test`.
> **Ainda não iniciado:** publicação, o primeiro job da aplicação, webhook,
> simulador, reprodução, frontend.

---

## Fase 7 — Publicação

- [x] **T053** Publicação de aula e sua idempotência
  - **Objetivo:** publicar quando as condições são satisfeitas, de forma
    idempotente, coordenando três agregados.
  - **Arquivos previstos:** `Catalog/Application/PublishLesson/`, controller.
  - **Requisitos:** RF-PUB-001 a 003; RN-PUB-001 a 006; RN-IDM-004; RF-ERR-010;
    AC-PROD-004, AC-PROD-005, AC-PROD-006; plan §§8.3, 14.1.
  - **Implementação:** `POST /api/lessons/{lesson}/publish`. Numa transação, trava
    a aula e a tentativa atual e o domínio decide. Sem vídeo `ready` com
    referência presente, `409` com código que identifica a condição. Publicar de
    novo devolve `200` sem efeito. A primeira publicação promove o curso a
    `available` na mesma transação, condicional ao estado atual — duas publicações
    simultâneas não produzem escrita conflitante.
  - **Testes/validação:** feature cobrindo **todos** os estados não elegíveis
    (`pending`, `uploading`, `uploaded`, `processing`, `failed`) com `409`; a
    aceitação em `ready` com referência, montada pelo teste que grava a tentativa
    nesse estado pelo repositório; a republicação sem efeito; e a promoção do
    curso acontecendo uma única vez.
    `docker compose run --rm api php artisan test --filter=PublishLesson`.
  - **Depende de:** T043.
  - **Critério de conclusão:** AC-PROD-004, AC-PROD-005 e AC-PROD-006 verdes.

> **Checkpoint da Fase 7**
> **Passa a funcionar:** publicação completa — rejeitada em todos os estados não
> elegíveis, aceita em `ready` com referência, idempotente, e promovendo o curso a
> `available` na primeira vez.
> **Testes verdes:** publicação em todos os estados e republicação sem efeito.
> **Comandos:** `docker compose run --rm api php artisan test --filter=Publish`.
> **Ainda não iniciado:** o primeiro job da aplicação, webhook, simulador,
> reprodução, frontend.

---

## Fase 8 — Fila e processamento

- [x] **T055** Primeiro uso da fila: enfileiramento atômico e job de processamento
  - **Objetivo:** o primeiro trabalho assíncrono da aplicação, gravado na mesma
    transação que muda o estado e seguro para ser repetido.
  - **Arquivos previstos:**
    `Video/Infrastructure/Queue/ProcessVideoJob.php`,
    `Video/Application/StartProcessing/`, `Video/Domain/ProcessingEventId.php`,
    ajuste no caso de uso de conclusão de envio e, se o comportamento de despacho
    exigir, `backend/config/queue.php`.
  - **Requisitos:** ABERTO-004; RF-PROC-001, RF-PROC-002; RN-VID-001; RN-IDM-002;
    RNF-002, RNF-003; plan §§7.4, 13.1, 13.2.
  - **Implementação:** a infraestrutura de fila já existe: a conexão em banco, as
    filas `default` e `simulator`, os comandos dos dois processos e as opções
    operacionais — tentativas, backoff e timeout — foram configurados em T012, e
    os workers estão de pé consultando suas filas desde lá. **Esta tarefa não os
    cria de novo.** O que ela acrescenta é o primeiro uso funcional: até aqui as
    filas estavam vazias. Nenhum Redis, nenhum Horizon.

    O `ProcessVideoJob` é criado **dentro** da mesma transação que grava
    `uploaded`, e não depois dela. A fila usa a conexão padrão da aplicação, então
    a linha de `jobs` participa daquele commit: no rollback não sobra nem estado
    nem job; no commit os dois passam a existir juntos; e antes do commit a linha
    não existe para nenhum outro processo, então o `worker` não a alcança
    (plan §7.4).

    `ProcessVideoJob` recebe **apenas** o identificador da tentativa e recarrega o
    estado sob lock. Em `uploaded`, transiciona para `processing` e enfileira a
    entrega destinada à fila `simulator` — as duas coisas **na mesma transação**,
    pela mesma razão; em `processing`, trata como retomada e enfileira a entrega
    novamente; em `ready` ou `failed`, encerra sem efeito; em `pending` ou
    `uploading`, não inicia.

    A retomada em `processing` agenda de novo a **mesma** entrega, com o **mesmo**
    `event_id` — e é seguro justamente por isso: o webhook idempotente reconhece a
    repetição e não produz efeito novo.

    Nenhum Redis, nenhum Horizon, nenhum outbox. A garantia vem de fila e domínio
    compartilharem a conexão MySQL, e vale enquanto for assim.

    O `simulator-worker` já consulta essa fila desde T012, mas o componente que
    **processa** a entrega simulada só existe em T067. Nesta etapa, portanto, o
    despacho é comprovado de forma isolada — `Queue::fake` ou inspeção direta do
    job agendado —, e **nenhum fluxo real que dependa do consumidor funcional é
    disparado**, nem por teste nem por comando manual.

    O `event_id` da entrega é derivado de forma determinística do identificador da
    tentativa e do cenário — sucesso ou falha. Nunca aleatório: um identificador
    novo a cada tentativa transformaria retentativa em evento novo e anularia a
    idempotência do webhook.
  - **Testes/validação:** duas famílias de teste, que provam coisas diferentes e
    não se substituem.

    **Contrato do despacho, com fila falsa.** `Queue::fake` serve para verificar
    *qual* classe foi despachada, *qual* fila recebeu o trabalho e que o
    `event_id` é estável e distinto entre cenários. Cobre também os cinco estados
    de entrada do job e a retomada em `processing`, que reagenda a entrega com o
    mesmo `event_id`.

    **Atomicidade, com banco real.** `Queue::fake` intercepta o despacho antes de
    ele chegar ao banco, e o driver `sync` executa o job na hora: **nenhum dos
    dois prova atomicidade transacional** — provam contrato e efeito, não que
    estado e job compartilham o mesmo commit. Essa prova exige um teste de
    integração com **MySQL real**, sobre o schema de testes do projeto e com
    `QUEUE_CONNECTION=database`. Durante a execução, o `worker` precisa estar
    **parado ou isolado**, senão ele consome a linha antes da inspeção e o teste
    passa a medir a corrida em vez da transação. O que se afirma:

    - **rollback** deixa ausentes tanto a alteração de domínio quanto a linha de
      `jobs` — nenhuma das duas persiste;
    - **commit** deixa as duas persistidas.

    **SQLite em memória não vale aqui.** Ele tem outro comportamento
    transacional, e passar nele não diz nada sobre a Database Queue sobre MySQL
    que é a decisão do projeto (plan §7.4).

    O banco de testes vem de `DB_TEST_DATABASE`, já definido pelo ambiente — o
    comando concreto é escrito quando esta tarefa for implementada, sem nome de
    banco fixado à mão.

    Fecha com `docker compose logs worker` mostrando o `worker` consumindo, pela
    primeira vez, um job da aplicação na fila `default`.
  - **Depende de:** T053.
  - **Critério de conclusão:** o primeiro job da aplicação consumido pelo `worker`
    na fila `default`, o enfileiramento provadamente atômico com a mudança de
    estado, os cinco caminhos do job verdes, a entrega para a fila `simulator`
    comprovadamente agendada e o identificador de evento estável.
    **Esta tarefa não afirma que a entrega simulada foi processada** — isso é
    T067.

> **Checkpoint da Fase 8**
> **Passa a funcionar:** concluir um envio enfileira o processamento na mesma
> transação que grava o estado; o job transiciona para `processing` e agenda a
> entrega para a fila do simulador, também atomicamente, de forma retomável e com
> identificador de evento estável.
> **Testes verdes:** enfileiramento atômico, os cinco caminhos do job, o
> agendamento provado com fila falsa e a estabilidade do `event_id`.
> **Comandos:** `docker compose up -d`,
> `docker compose run --rm api php artisan test`,
> `docker compose logs worker`.
> **Ainda não iniciado:** o endpoint de callback, o simulador que o chama, o
> comando de falha, reprodução e frontend. O `simulator-worker` consulta a fila
> desde T012, mas **o componente que processa a entrega simulada só chega em
> T067** — até lá, nenhuma entrega é executada de verdade.

---

## Fase 9 — Webhook

O endpoint que o simulador vai chamar precisa existir **antes** do simulador. A
fase inteira roda com o callback sendo exercido diretamente pelos testes.

- [x] **T059** Endpoint de callback completo
  - **Objetivo:** a rota oficial validando origem, aplicando o evento
    atomicamente e sendo idempotente pelo `event_id`.
  - **Arquivos previstos:** rota `POST /api/webhooks/video-processing`,
    controller e request em `Video/Interfaces/Http/`, middleware de assinatura em
    `Video/Infrastructure/Webhook/`,
    `Video/Application/Port/WebhookEventStore.php` e adapter Eloquent,
    `Video/Application/HandleProcessingCallback/`, `docker-compose.yml`.
  - **Requisitos:** ABERTO-006; RF-WHK-001 a 007, RF-WHK-009 a 011; RN-IDM-002,
    RN-IDM-003; RN-VID-004, RN-VID-006 a 008; RN-AUT-005; RF-ERR-006 a 009,
    RF-ERR-014; RNF-003; AC-VID-004 a 007, AC-VID-009, AC-VID-013;
    plan §§8.4, 10.3, 13.4.
  - **Implementação:** a rota fica fora de sessão e de CSRF — o emissor é um
    serviço, não um navegador.

    **O segredo compartilhado entra no ambiente aqui.** `WEBHOOK_SECRET` passa a
    ser declarado no ambiente compartilhado do Compose, com **valor obrigatório e
    não vazio**: subir sem ele precisa falhar de imediato, e não seguir
    calculando assinatura sobre chave vazia — o que aceitaria qualquer emissor
    que soubesse do descuido. O **mesmo** valor serve aos dois lados: o simulador
    de T067 assina com ele, e o verificador HMAC desta tarefa confere com ele.
    Dois segredos seriam duas verdades, e toda entrega terminaria em `401`.

    O `.env.example` da raiz e o `backend/.env.example` já o documentam desde
    T012, deliberadamente antes de existir consumidor: o contrato de configuração
    é publicado uma vez, e a tarefa que passa a lê-lo não precisa reabrir aqueles
    arquivos. O `frontend/.env.example` **não** o contém, e não por esquecimento:
    variável pública do Nuxt é entregue ao navegador junto com o pacote da
    aplicação, e um segredo de assinatura ali deixaria de ser segredo.

    **Origem.** Headers `X-Webhook-Timestamp` e `X-Webhook-Signature` no formato
    `v1=<hex>`. String assinada é `timestamp + "." + corpo bruto`, e a verificação
    usa o **corpo bruto**, antes de qualquer desserialização. Comparação com
    `hash_equals`. Janela de cinco minutos. Falha responde `401` sem efeito.

    **Estrutura.** Campos presentes, `status` entre os aceitos, `video_id`
    sintaticamente UUID. Carga inválida responde `422` **sem criar reserva**.

    **Idempotência e efeito.** Tentar inserir a reserva com `outcome` nulo dentro
    da transação. `processed_at` entra **no próprio `INSERT` da reserva**, e não
    numa escrita posterior: a coluna é obrigatória e não tem default, então uma
    linha não pode existir sem ela. Um teste confirma que todo evento concluído
    tem `processed_at` preenchido.

    Colisão na unicidade leva à leitura do desfecho registrado, que
    é **repetido sem olhar o corpo recebido** — o `event_id` é a única chave, e o
    conteúdo da reentrega não reabre a decisão. Inserção bem-sucedida resolve a
    tentativa e avalia contra o estado travado por `FOR UPDATE`: transição válida
    aplica e grava `accepted`; tentativa inexistente ou estado incompatível
    preserva o vídeo e grava `rejected_permanent`; falha transitória faz
    **rollback**, sem deixar linha. Nenhuma linha commitada fica sem `outcome`.

    **Efeitos.** `ready` exige `playback_reference` presente e grava a referência;
    `failed` exige `playback_reference` nulo e grava o caso do catálogo de falhas,
    com mensagem pública genérica **derivada internamente**. A carga oficial não
    ganha campo novo.

    **Respostas.** `200` para aceito e para repetição de aceito; `409` para
    rejeição permanente e sua repetição; `5xx` com `Retry-After` para falha
    transitória; `401` para assinatura inválida; `422` para carga inválida.
    **Sem `202`** — o callback é aplicado sincronamente.
  - **Testes/validação:**
    `docker compose run --rm api php artisan test --filter=Webhook`, cobrindo:
    assinatura ausente, inválida, corpo alterado e timestamp fora da janela;
    carga malformada sem reserva; callback de sucesso e de falha com o efeito
    correto e a aula em `failed` seguindo não publicável; reentrega do mesmo
    `event_id` repetindo o desfecho, inclusive com corpo diferente; callback de
    falha para vídeo em `ready` sem regressão; callback para `video_id` UUID
    válido sem tentativa correspondente registrado como rejeição permanente com
    `video_attempt_id` nulo, contrastado com UUID inválido em `422`; falha
    transitória não deixando desfecho e a reentrega posterior sendo aplicada; e a
    **prova direcionada de concorrência**, disparando o mesmo `event_id` em
    paralelo e confirmando um único efeito.
  - **Depende de:** T055.
  - **Critério de conclusão:** AC-VID-004, 005, 006, 007, 009 e 013 verdes, e a
    entrega concorrente do mesmo evento com efeito único.

> **Checkpoint da Fase 9**
> **Passa a funcionar:** o endpoint de callback existe, verifica origem, é
> idempotente por `event_id`, distingue aceito de rejeição permanente e de falha
> transitória, e produz os efeitos corretos — exercido pelos testes, ainda sem
> emissor real.
> **Testes verdes:** validação estrutural, HMAC, reserva e desfechos, vídeo
> inexistente, códigos, efeitos e entrega concorrente.
> **Comandos:** `docker compose run --rm api php artisan test --filter=Webhook`.
> **Ainda não iniciado:** o simulador que chamará este endpoint, o comando de
> falha, reprodução e frontend.

---

## Fase 10 — Simulador

- [x] **T067** Simulador, cenário de falha e retomada do processamento
  - **Objetivo:** o fornecedor externo simulado, chamando o callback real, com
    sucesso determinístico e falha acionável por comando.
  - **Arquivos previstos:** `Video/Infrastructure/Simulator/`, job da fila
    `simulator`, `Video/Interfaces/Console/SimulateVideoFailureCommand.php`,
    `backend/tests/Feature/Video/ProcessRetryTest.php`.
  - **Requisitos:** RF-PROC-003, RF-PROC-004, RF-PROC-006; RF-WHK-008;
    RN-IDM-002; RF-ERR-004; plan §§8.6, 13.2, 13.3.
  - **Implementação:** o `simulator-worker` está de pé e consultando a fila
    `simulator` desde T012, e T055 passou a agendar entregas nela. **É aqui que
    nasce o componente que as processa** — antes desta tarefa, nenhuma entrega do
    simulador é consumida.

    O fluxo normal produz **sucesso determinístico**. A entrega é um `POST` HTTP
    assinado ao endpoint de T059, alcançado pelo nome do serviço na rede do
    Compose.
    **Proibido escrever nas tabelas de domínio** — a única forma de afetar o
    estado é o callback. Reutiliza o `event_id` estável de T055 ao reentregar;
    repete diante de falha de rede e de `5xx`; para diante de `200` ou `409`.
    Esgotadas as tentativas, o job termina em `failed_jobs` e a tentativa
    permanece em `processing`, porque nenhum desfecho confiável chegou.

    O comando `demo:simulate-video-failure {videoAttemptId}` recebe qual tentativa
    preparada recebe o callback de falha. Usa o mesmo componente, o mesmo HMAC e o
    mesmo contrato oficial, com o `event_id` estável do cenário de falha. Nenhuma
    escrita direta em tabela de domínio.
  - **Testes/validação:**
    `docker compose run --rm api php artisan test --filter=Simulator`,
    confirmando que o vídeo chega a `ready` pelo caminho HTTP e que o componente
    não referencia repositórios de domínio; e
    `docker compose run --rm api php artisan test --filter=ProcessRetry`,
    executando o job sobre uma tentativa já em `processing` para confirmar que ele
    retoma e entrega, que a entrega duplicada com o mesmo `event_id` não produz
    efeito adicional, e que o job esgotado vai para `failed_jobs` **sem** alterar
    o estado do vídeo.

    O cenário de falha usa o UUID fixo semeado em T022 — a tentativa **dedicada**,
    não a do curso da jornada principal. Com ele numa variável de shell, para
    evitar interpretar caracteres:

    ```
    ATTEMPT_ID="01936f1a-7c00-7a3e-9b7d-2f5c8e4a1d60"
    docker compose run --rm api php artisan demo:simulate-video-failure "$ATTEMPT_ID"
    ```

    E `--filter=SimulateFailure`, incluindo a execução repetida sem efeito novo.
  - **Depende de:** T059.
  - **Critério de conclusão:** sucesso determinístico verde, restrição de escrita
    comprovada, cenário de falha reprodutível e idempotente, e retomada coberta.

> **Checkpoint da Fase 10**
> **Passa a funcionar:** o ciclo do vídeo fecha ponta a ponta no backend — enviar,
> concluir, processar, receber o callback e chegar a `ready` ou `failed`, com o
> cenário de falha acionável por comando.
> **Testes verdes:** toda a suíte de backend até aqui, mais simulador, comando de
> falha e retomada.
> **Comandos:** `docker compose up -d`,
> `docker compose run --rm api php artisan test`, e o comando de falha com o
> identificador literal do cenário dedicado de T022.
> **Ainda não iniciado:** catálogo do consumidor, reprodução, frontend.

---

## Fase 11 — Consumo e reprodução

- [x] **T070** Catálogo do consumidor, autorização por concessão e reprodução
  - **Objetivo:** o consumidor vê o que lhe foi concedido, navega pelo conteúdo
    publicado e obtém os dados de reprodução — e nada além disso.
  - **Arquivos previstos:** `Identity/Application/Port/AccessGrantRepository.php`
    e adapter, serviço de autorização, `Catalog/Application/ListGrantedCourses/`,
    `GetPublishedStructure/`, `Video/Application/GetPlayback/`, controllers,
    resources.
  - **Requisitos:** RF-CONS-001 a 005; RF-PLB-001 a 008; RN-AUT-002 a 004;
    RN-PROP-005; RF-UI-013, RF-UI-015; RF-ERR-011; AC-CONS-001 a 005;
    plan §§9.3, 10.4, 14.2.
  - **Implementação:** a concessão entra **na cláusula da consulta**, não em
    filtro posterior. Curso sem concessão responde `404`, indistinguível de
    inexistente.

    `GET /api/catalog/courses` paginado, apenas cursos concedidos **e** em
    `available`. `GET /api/catalog/courses/{course}` devolve a árvore com
    **somente aulas publicadas**, na ordem.

    `GET /api/lessons/{lesson}/playback` faz quatro verificações antes de emitir
    qualquer coisa — autenticado, concessão, aula publicada, vídeo `ready`. Só
    então uma URL `GET` pré-assinada de cinco minutos, gerada com o endereço
    público. O retorno traz dados de reprodução, nunca o arquivo. Sem concessão,
    `404` indistinguível de inexistente; aula não publicada ou vídeo fora de
    `ready`, `409` com código próprio — a negativa por disponibilidade é
    distinguível da negativa por autorização.
  - **Testes/validação:**
    `docker compose run --rm api php artisan test --filter=Consumer`, cobrindo a
    listagem restrita a concedidos e disponíveis, a árvore sem rascunhos, a
    reprodução autorizada, a comparação entre curso não concedido e curso
    inexistente, o `409` de conteúdo indisponível, e a matriz cruzada — produtor
    dono, produtor alheio, consumidor concedido, consumidor sem concessão e não
    autenticado — contra aula publicada e não publicada.
  - **Depende de:** T067.
  - **Critério de conclusão:** AC-CONS-001 a 005 verdes no backend.

> **Checkpoint da Fase 11**
> **Passa a funcionar:** o backend cobre a jornada inteira — criar, enviar,
> processar, publicar e reproduzir, com autorização em cada passo.
> **Testes verdes:** toda a suíte de backend.
> **Comandos:** `docker compose run --rm api php artisan test`,
> `docker compose run --rm api ./vendor/bin/pint --test`.
> **Ainda não iniciado:** consolidação do OpenAPI e todo o frontend.

---

## Fase 12 — Contrato publicável

- [x] **T076** OpenAPI completo e publicável
  - **Objetivo:** o contrato inteiro documentado, válido e executável por quem
    avalia.
  - **Arquivos previstos:** `docs/openapi.yaml`, `docs/api.md`.
  - **Requisitos:** RNF-011; ABERTO-010; plan §10.5.
  - **Implementação:** o documento cobre **todas** as rotas do backend —
    autenticação, catálogo do produtor, upload, publicação, webhook, catálogo do
    consumidor e reprodução —, com schemas compartilhados de envelope, paginação e
    problema, exemplos por operação e os corpos de erro. Sem prefixo de versão.

    **As operações mutantes protegidas por sessão declaram o `419`**, com o corpo
    de problema e o código `CSRF_TOKEN_MISMATCH`. Sem isso, quem gerar cliente a
    partir do contrato trataria a resposta como falha desconhecida — e é
    justamente a que tem tratamento próprio: renovar o token e repetir uma vez.

    Esta é a **única** tarefa de contrato: base e consolidação viraram uma
    entrega só, feita depois de os endpoints existirem. Documentar rota a rota
    enquanto elas nascem obrigaria a reabrir o mesmo arquivo em quase toda tarefa
    do backend, e a versão intermediária nunca seria conferível.

    Junto dele, um guia curto de leitura e uso em `docs/api.md`.
  - **Testes/validação:** linter de OpenAPI em container, e comparação entre a
    lista de rotas registradas
    (`docker compose run --rm api php artisan route:list --path=api`) e as rotas
    documentadas. Depois, importar o documento numa ferramenta de requisições e
    executar o fluxo principal contra o ambiente local.
  - **Depende de:** T070.
  - **Critério de conclusão:** documento válido, nenhuma rota da API ausente, e o
    fluxo principal executável a partir dele.

> **Checkpoint da Fase 12**
> **Passa a funcionar:** o backend está completo e com contrato documentado,
> válido e executável.
> **Testes verdes:** toda a suíte de backend, mais o linter do OpenAPI.
> **Comandos:** `docker compose run --rm api php artisan test`,
> `docker compose run --rm api php artisan route:list --path=api`.
> **Ainda não iniciado:** o frontend, que a partir daqui deriva seus tipos do
> contrato completo.

---

## Fase 13 — Fundação do frontend

- [x] **T078** Fundação do frontend, qualidade, cliente HTTP, sessão e login
  - **Objetivo:** o frontend subindo, falando com a API com sessão e CSRF, com
    tipos derivados do contrato, vocabulário de estados e a primeira tela.
  - **Arquivos previstos:** `frontend/nuxt.config.ts`, `frontend/app/app.vue`,
    `frontend/app/assets/css/`, `frontend/eslint.config.mjs`,
    `frontend/vitest.config.ts`, `frontend/tsconfig.json`,
    `frontend/app/types/api.ts`, `frontend/app/composables/useApi.ts`,
    `frontend/app/composables/useAuth.ts`, `frontend/app/middleware/auth.ts`,
    `frontend/app/components/ui/`, `frontend/app/pages/login.vue`.
  - **Requisitos:** ABERTO-001, ABERTO-008, ABERTO-009, ABERTO-010, ABERTO-013;
    RF-AUT-001, RF-AUT-005, RF-AUT-006; RF-UI-001 a 003, RF-UI-008 a 017;
    RNF-005, RNF-008; AC-UI-001, AC-UI-002, AC-UI-003; plan §§15.1 a 15.5, 17.1.
  - **Implementação:** `ssr: false` já definido em T009, TypeScript estrito,
    Composition API. Nuxt UI v4 sobre Tailwind CSS 4, como única biblioteca
    principal de componentes. ESLint, verificação de tipos e Vitest com Nuxt e Vue
    Test Utils disponíveis por comando.

    Tipos para envelope, paginação, corpo de problema e todos os recursos,
    derivados do **OpenAPI consolidado em T076**. Nenhuma regra de negócio
    replicada aqui, apenas formatos.

    `useApi` é o único ponto que fala HTTP: envia credenciais, obtém o cookie CSRF
    antes da primeira requisição mutante, reenvia o valor no header e converte
    `application/problem+json` num erro tipado com status e código. Nenhum segredo
    no código cliente.

    **Tratamento do `419`.** Diante da primeira resposta `419`
    `CSRF_TOKEN_MISMATCH`, o cliente busca um novo cookie CSRF e repete a
    requisição **uma única vez**. Se a repetição também falhar, o erro é entregue
    à interface como qualquer outro. O limite é obrigatório: sem ele, um `419`
    persistente — sessão encerrada no servidor, configuração errada de domínio —
    produziria um laço infinito de renovação e reenvio, e a tela ficaria travada
    em vez de explicar o problema. Um teste cobre os dois desfechos: a repetição
    que resolve e a que não resolve.

    `useState` guarda **apenas** sessão e usuário — sem Pinia. `401` em qualquer
    chamada dispara o estado de sessão expirada, distinto de acesso negado.

    Componentes de estado reutilizados por todas as telas: carregamento, lista
    vazia, ação em andamento, sucesso, validação por campo, conflito de regra,
    indisponibilidade da API, erro de rede, acesso negado e conteúdo indisponível.
    Lista vazia e falha precisam ser visualmente distintas.

    A tela de login fecha a fundação: formulário acessível, com rótulos e foco,
    erro de credencial sem revelar se o e-mail existe, e encaminhamento conforme o
    perfil. É o primeiro fluxo de formulário completo — sucesso, validação e erro.
  - **Testes/validação:**
    `docker compose run --rm frontend npm run lint`,
    `npm run typecheck`, `npm run build` e
    `npm run test`, este último cobrindo `useApi` em sucesso, `422`, `409`, `401`
    e falha de rede; `useAuth` no caminho de sessão expirada; os componentes de
    estado; e o formulário de login nos três desfechos.
  - **Depende de:** T076.
  - **Critério de conclusão:** os quatro comandos verdes, e AC-UI-001, AC-UI-002 e
    AC-UI-003 cobertos no nível de composable, componente e formulário.

> **Checkpoint da Fase 13**
> **Passa a funcionar:** o frontend sobe, fala com a API com sessão e CSRF, tem
> tipos derivados do contrato completo, vocabulário de estados pronto e login
> funcionando.
> **Testes verdes:** composables de API e autenticação, componentes de estado e a
> tela de login.
> **Comandos:** `docker compose run --rm frontend npm run lint`,
> `npm run typecheck`, `npm run test`, `npm run build`.
> **Ainda não iniciado:** telas das jornadas, upload no navegador, E2E.

---

## Fase 14 — Jornada do produtor

- [x] **T085** Catálogo do produtor na interface
  - **Objetivo:** o produtor vê os próprios cursos, cria um novo, abre o detalhe e
    monta a estrutura.
  - **Arquivos previstos:** `frontend/app/pages/producer/courses/index.vue`,
    `frontend/app/pages/producer/courses/[id].vue`, componentes de lista,
    formulário, módulo e aula.
  - **Requisitos:** RF-CUR-001, RF-CUR-002; RF-MOD-002; RF-AUL-002; RF-EST-001,
    RF-EST-002, RF-EST-004; RN-ORD-002; RF-UI-001 a 003, RF-UI-008, RF-UI-009;
    AC-PROD-001, AC-PROD-003, AC-UI-001.
  - **Implementação:** carregamento, lista vazia com próxima ação, confirmação de
    criação, validação por campo vinda da API. O detalhe exibe estado do curso,
    módulos e aulas na ordem, com o estado do vídeo de cada aula.

    Os formulários de módulo e de aula **não têm campo de posição**: a ordem é do
    backend, e a árvore reflete o que a API devolveu. **A ordem não é recalculada
    no cliente.**
  - **Testes/validação:**
    `docker compose run --rm frontend npm run test -- courses`, cobrindo
    carregamento, lista vazia, sucesso e validação; e
    `npm run test -- structure`, confirmando a ordem exibida e que o item recém
    criado aparece ao final.
  - **Depende de:** T078.
  - **Critério de conclusão:** AC-PROD-001, AC-PROD-003 e AC-UI-001 cobertos na
    interface.

- [x] **T088** Upload, acompanhamento do processamento e publicação
  - **Objetivo:** enviar o vídeo pelo navegador, acompanhar o estado e publicar a
    aula.
  - **Arquivos previstos:** `frontend/app/composables/useMultipartUpload.ts`,
    `frontend/app/composables/useVideoStatus.ts`, componente de upload, componente
    de estado do vídeo, componente de publicação.
  - **Requisitos:** RF-UPL-003; RF-VID-002; RF-PUB-001, RF-PUB-002; RF-UI-003 a
    008, RF-UI-010; RF-ERR-001; RNF-001; AC-VID-001, AC-VID-007, AC-VID-012,
    AC-UI-004, AC-PROD-004, AC-PROD-005; plan §§11.2, 11.4, 15.2.
  - **Implementação:** o composable particiona em 64 MiB e envia **uma parte por
    vez**, pedindo a URL de cada parte à API e guardando o `ETag`. Renova URL
    expirada e reenvia **apenas** a parte que falhou. Os bytes nunca passam pelo
    servidor Nuxt.

    Progresso real, por partes concluídas sobre o total. Falha de transferência é
    exibida com opção de retomar dentro da mesma tentativa e **nunca** chama a
    conclusão nem apresenta o vídeo como pronto — é aqui que AC-VID-012 é provado
    na interface, sem teste autônomo.

    `useVideoStatus` consulta a cada **três segundos** enquanto o estado é
    transitório — `pending`, `uploading`, `uploaded`, `processing` —, para ao
    alcançar `ready` ou `failed`, e ao desmontar a tela. Sem WebSocket, sem SSE.

    Cada estado do ciclo de vida tem representação distinta: enviando,
    processando, pronto e falhou. A falha mostra a mensagem pública devolvida pela
    API e o caminho para nova tentativa.

    O botão de publicar só aparece com o vídeo `ready` — conveniência visual,
    **nunca** proteção; o backend decide de novo. Rejeição `409` vira mensagem de
    condição não satisfeita, derivada do código funcional.
  - **Testes/validação:**
    `docker compose run --rm frontend npm run test -- upload`, cobrindo
    particionamento, envio sequencial, falha de uma parte com reenvio isolado,
    renovação de URL, progresso, e a falha de transferência que **não** chama a
    conclusão e não exibe o vídeo como pronto; `npm run test -- video-status`,
    com relógio controlado, para o início e a parada do polling; e
    `npm run test -- publish`, para os dois desfechos da publicação.
  - **Depende de:** T085.
  - **Critério de conclusão:** AC-VID-001, AC-VID-012, AC-UI-004 e AC-PROD-004
    cobertos na interface.

> **Checkpoint da Fase 14**
> **Passa a funcionar:** a jornada do produtor inteira pela interface — entrar,
> criar curso, módulo e aula, enviar vídeo com progresso, acompanhar o
> processamento, ver falha e publicar.
> **Testes verdes:** componentes e composables de cada entrega acima.
> **Comandos:** `docker compose up -d`,
> `docker compose run --rm frontend npm run test`, `npm run lint`,
> `npm run typecheck`.
> **Ainda não iniciado:** telas do consumidor, E2E, pipeline.

---

## Fase 15 — Jornada do consumidor

- [x] **T093** Jornada do consumidor, negativas, responsividade e acessibilidade
  - **Objetivo:** o consumidor vê o que pode assistir, navega, reproduz e entende
    quando não consegue.
  - **Arquivos previstos:** `frontend/app/pages/catalog/index.vue`,
    `frontend/app/pages/catalog/courses/[id].vue`,
    `frontend/app/pages/catalog/lessons/[id].vue`, componentes de estado da área
    do consumidor, ajustes nos componentes e páginas existentes.
  - **Requisitos:** RF-CONS-001 a 005; RF-PLB-005 a 008; RN-AUT-004; RN-PROP-005;
    RF-UI-001, RF-UI-002, RF-UI-013, RF-UI-015, RF-UI-016; AC-CONS-001 a 004;
    plan §§9.3, 15.5.
  - **Implementação:** lista apenas cursos concedidos e disponíveis, com lista
    vazia distinta de erro. Navegação renderiza somente o que a API devolve, que
    já exclui rascunhos — nada de filtrar no cliente. A tela de reprodução solicita
    os dados e alimenta um elemento de vídeo do navegador com a URL temporária,
    sem player avançado.

    A interface tem **três** estados públicos de negativa, não quatro, e isso é
    deliberado:

    | Resposta | Estado exibido |
    | --- | --- |
    | `401` | Sessão expirada, conduzindo à reautenticação |
    | `403` e `404` | **O mesmo** estado de acesso negado |
    | `409` | Conteúdo conhecido, ainda indisponível para reprodução |

    `403` e `404` colapsam num único estado de propósito. O backend já responde
    `404` de forma indistinguível para recurso inexistente e para recurso
    existente sem concessão (RN-PROP-005, RF-PLB-008); se a tela dissesse "não
    encontrado" num caso e "sem permissão" no outro, ela desfaria no cliente a
    ocultação que o servidor construiu. **A interface nunca afirma se o recurso
    existe.**

    Fechando a fase, a revisão de responsividade e acessibilidade das duas
    jornadas: formulários com rótulos associados, ordem de foco coerente,
    contraste adequado e navegação consistente. Não é exigido acabamento
    comercial.

    As duas frentes são verificadas de formas diferentes, porque medem coisas
    diferentes. **Acessibilidade é automatizável aqui**: semântica do markup,
    rótulos, papéis, navegação por teclado, foco e estados acessíveis são
    afirmações sobre a árvore renderizada, e o ambiente de teste de componente as
    enxerga. **Responsividade não é**: o ambiente de teste não faz layout, não
    aplica consulta de mídia e não tem viewport de verdade, então uma asserção de
    layout ali não provaria nada. Ela fica como inspeção manual em navegador com
    largura reduzida, consolidada em T109. Nenhuma ferramenta visual nova e nenhum
    segundo navegador automatizado entram por causa disso.
  - **Testes/validação:**
    `docker compose run --rm frontend npm run test -- catalog`, cobrindo lista,
    navegação e reprodução; `npm run test -- playback-denied`, afirmando que
    `403` e `404` produzem **o mesmo texto e o mesmo estado** e que nenhum deles
    menciona existência ou inexistência do recurso; e `npm run test -- a11y`,
    para semântica, rótulos, papéis, navegação por teclado, ordem de foco e
    estados acessíveis.
  - **Depende de:** T088.
  - **Critério de conclusão:** AC-CONS-001 a 004 cobertos na interface, sem que
    ela revele existência, e as asserções de acessibilidade verdes. A conferência
    de responsividade em largura reduzida é inspeção manual em navegador, cobrada
    em T109 — **não bloqueia esta tarefa**.

> **Checkpoint da Fase 15**
> **Passa a funcionar:** as duas jornadas completas pela interface, do
> gerenciamento do conteúdo até assisti-lo como consumidor autorizado.
> **Testes verdes:** toda a suíte de frontend.
> **Comandos:** `docker compose run --rm frontend npm run test`, `npm run lint`,
> `npm run typecheck`, `npm run build`.
> **Ainda não iniciado:** E2E, pipeline, documentação final.

---

## Fase 16 — E2E

O navegador do Playwright **não** é um nono serviço. Ele vive num profile `e2e`,
que `docker compose up` não sobe. Os oito serviços aprovados no plano continuam
sendo os únicos do comando normal.

- [x] **T100** Infraestrutura de E2E, fixture e jornada crítica
  - **Objetivo:** provar AC-E2E-001 atravessando interface e backend reais, por um
    único comando reproduzível.
  - **Arquivos previstos:** `e2e/playwright.config.ts`, `docker/e2e/Dockerfile`,
    serviço `e2e` no `docker-compose.yml` **com `profiles: [e2e]`**,
    `e2e/fixtures/video-curto.mp4`, `e2e/tests/jornada-completa.spec.ts`,
    `scripts/e2e.sh` e o alvo `make e2e`.

    A imagem do E2E também carrega `docker/e2e/package.json` e
    `docker/e2e/package-lock.json`, que fixam a versão da ferramenta ao lado do
    Dockerfile, e `docker/e2e/encaminhador.mjs`, o ponto de entrada que preserva
    as três origens públicas dentro do container.
  - **Requisitos:** ABERTO-013; RNF-006, RNF-007; AC-E2E-001; AC-VID-001;
    spec seção 15.5; plan §§16.1, 17.2.
  - **Implementação:** o serviço aponta para o `frontend` pela rede interna e
    depende de `web` saudável.

    A reprodutibilidade exige uma **orquestração versionada**, porque
    `migrate:fresh` no meio de workers ativos gera corrida: um worker pode
    consumir ou gravar durante o reset. E porque o container de E2E **não tem
    Docker e não recebe o socket do Docker**: tudo que precisa do daemon — reset e
    seed — roda no **host**, pelo script, antes do navegador começar.

    `scripts/e2e.sh`, sem modos e sem argumento, executa sempre a mesma sequência:

    1. `docker compose stop worker simulator-worker` — os consumidores param antes
       do reset;
    2. `docker compose run --rm api php artisan migrate:fresh --seed` — base
       recriada e semeada;
    3. `docker compose up -d worker simulator-worker` — consumidores voltam;
    4. espera ativa até `docker compose ps` reportar `web`, `mysql` e `rustfs`
       como `healthy`;
    5. `docker compose --profile e2e run --rm e2e npx playwright test` — a única
       jornada.

    `make e2e` é o atalho — **esta é a entrada oficial do E2E**, e o checkpoint da
    fase e o README de T105 apontam para ela. O Playwright nunca é chamado direto:
    seria pular o reset, o seed e a espera pelos healthchecks.

    A fixture é um arquivo de poucos kilobytes, suficiente para caber em uma única
    parte do multipart. A exceção `!e2e/fixtures/**` foi acrescentada ao
    `.gitignore` em T004, justamente porque `*.mp4` é ignorado globalmente.

    A jornada, num único arquivo: o produtor entra, **abre o curso já preparado
    por seed** — não cria um curso novo, porque não existe operação para conceder
    acesso a um curso recém-criado —, cria módulo e aula, envia a fixture pelo
    upload multipart real, aguarda o processamento concluir e publica. Confirma
    que o curso passou a `available`. Em seguida troca de sessão, o consumidor
    entra, navega até a aula e obtém os dados de reprodução.

    **Um único cenário.** Os caminhos de erro obrigatórios — publicação bloqueada,
    consumidor sem concessão, falha de processamento, API indisponível e sessão
    expirada — já são provados pelos testes de backend e de frontend das fases
    anteriores, cada um no nível em que a regra vive. Repeti-los em navegador real
    acrescentaria minutos e uma classe própria de instabilidade sem acrescentar
    garantia.

    Rodar o script duas vezes seguidas produz o mesmo resultado, porque o passo 2
    descarta o estado da execução anterior.
  - **Testes/validação:** três conferências.
    `docker compose up -d && docker compose ps` mostrando **oito** serviços, sem
    o `e2e`; `git check-ignore -q e2e/fixtures/video-curto.mp4; echo $?`
    imprimindo **1**, e `git status --short --untracked-files=all` listando o
    arquivo — o status de saída é a prova, pelo mesmo motivo explicado em T004; e
    `make e2e` fechando com a jornada **passando** em navegador real.
    **"Nenhum teste encontrado" não conta como aprovação.**
  - **Depende de:** T093.
  - **Critério de conclusão:** os oito serviços normais preservados, o Playwright
    disponível sob demanda, os cinco passos executando na ordem, e AC-E2E-001
    verde com o upload real percorrido pela interface.

> **Checkpoint da Fase 16**
> **Passa a funcionar:** a jornada crítica comprovada por navegador real contra a
> pilha completa, sem alterar a composição normal do ambiente.
> **Testes verdes:** backend, frontend e a jornada E2E.
> **Comandos:** `docker compose up -d` e, para o E2E, sempre `make e2e`.
> **Ainda não iniciado:** pipeline e documentação final.

---

## Fase 17 — Pipeline

- [x] **T102** Pipeline com os jobs de backend e frontend
  - **Objetivo:** um arquivo de pipeline que instala, verifica e testa as duas
    camadas em paralelo, e constrói o Nuxt.
  - **Arquivos previstos:** `.github/workflows/ci.yml`.
  - **Requisitos:** RNF-008, RNF-011, RNF-017; ABERTO-013; plan §17.4.
  - **Implementação:** disparo em `push` e `pull_request`. **Dois** jobs, sem
    dependência entre eles.

    **`backend`** — a suíte não depende só do MySQL: há integração contra o
    RustFS e testes que exercem o callback HTTP real. Rodar apenas a parte que
    dispensa storage e anunciar a suíte como verde seria uma afirmação falsa. Por
    isso o job **reutiliza o `docker-compose.yml` do projeto** em vez de declarar
    serviços soltos: sobe `mysql`, `rustfs`, `setup` — que cria bucket e CORS —,
    `api` e `web`, aguarda os healthchecks, e então executa `pint --test`, a
    **suíte completa** de PHPUnit dentro do container `api`, e o linter de
    sintaxe do OpenAPI. Nenhum serviço novo; nenhum Redis.

    **`frontend`** — `npm ci`, ESLint, verificação de tipos, Vitest e build do
    Nuxt, em container, sem depender do backend.

    O E2E **não** compõe a pipeline: ele permanece reproduzível por `make e2e`,
    documentado no README e executado localmente. O desafio pede que o teste
    integrado exista e seja executável por comando documentado. Como esta tarefa
    não orquestra nem depende do E2E, ela parte de T093 — o mesmo ponto de onde
    T100 parte —, e as duas correm em ramos independentes.

    Um único arquivo, sem reusable workflow e sem `workflow_run`.
  - **Testes/validação:** **local, e é o que fecha esta tarefa** — validar a
    sintaxe com um linter de workflow em container, e executar cada comando do
    YAML localmente pelo mesmo caminho que o job usará, confirmando que a suíte
    completa de backend passa com storage e API disponíveis e que o documento
    OpenAPI valida.
  - **Depende de:** T093.
  - **Critério de conclusão:** sintaxe válida, dois jobs declarados, e todos os
    comandos do workflow verdes localmente. A execução remota é responsabilidade
    de T104 e **não bloqueia esta tarefa**.

- [x] **T104** Evidência remota da pipeline
  - **Objetivo:** fechar a única parte da verificação que a implementação não pode
    executar.
  - **Arquivos previstos:** nenhum — é uma tarefa de verificação.
  - **Requisitos:** RNF-008, RNF-017; a restrição de versionamento descrita neste
    documento — a implementação não executa `commit`, `push` nem dispara workflow.
  - **Implementação:** esta é a **única** tarefa que depende de estado remoto, e
    ela existe justamente para não bloquear as demais. A implementação entrega os
    blocos de commit prontos e a lista do que precisa ser observado — os dois jobs
    executando em paralelo e a reprovação acontecendo quando uma etapa falha.
    **Não** será criada falha proposital no código, o que exigiria deixar o
    repositório quebrado ou fazer push temporário; a reprovação é observada
    naturalmente na primeira execução com problema, ou verificada num branch
    descartável, a critério do responsável pelo repositório.
  - **Testes/validação:** **validação externa** — `push` e conferência do
    resultado no GitHub Actions.
  - **Depende de:** T102.
  - **Critério de conclusão:** o responsável pelo repositório confirma que os dois
    jobs executaram. A tarefa permanece `[ ]` até lá, e a pendência é reportada.
    **As tarefas seguintes não esperam por ela** — apenas o fechamento da entrega,
    em T109, exige essa confirmação.

> **Checkpoint da Fase 17**
> **Passa a funcionar:** um workflow único instala, verifica e testa as duas
> camadas em paralelo, constrói o Nuxt e valida o contrato.
> **Testes verdes:** sintaxe do workflow e todos os comandos que ele invoca,
> verificados localmente — inclusive a suíte completa de backend com MySQL,
> RustFS, `setup`, `api` e `web` disponíveis.
> **Comandos locais:** linter de workflow em container, mais os comandos de teste
> das fases anteriores.
> **Pendência remota:** a execução no GitHub Actions é validação externa e está
> concentrada em **T104**. T102 fecha com validação local; a documentação da Fase
> 18 não espera por essa evidência.
> **Ainda não iniciado:** documentação final.

---

## Fase 18 — Documentação e fechamento

- [x] **T105** README, arquitetura, limitações e roteiro de demonstração
  - **Objetivo:** outro desenvolvedor compreende, executa, avalia e demonstra a
    solução sem ler o código.
  - **Arquivos previstos:** `README.md`, `docs/demonstracao.md`.
  - **Requisitos:** RNF-011, RNF-019, RNF-020; RF-WHK-008; desafio §13.
  - **Implementação:** requisitos, variáveis de ambiente, comando único de subida,
    comando de reset, credenciais fictícias de avaliação, comandos de teste de
    cada camada e como acessar frontend e API. O E2E é documentado pela entrada
    oficial de T100 — `make e2e` —, nunca pelo Playwright direto. Ponteiro para o
    `docs/api.md` e o `docs/openapi.yaml` de T076.

    Resumo das decisões — autenticação, upload, storage, processamento,
    renderização — com ponteiro para o `plan.md`. E as limitações conscientes:
    aula bloqueada por envio abandonado, partes órfãs, URL de reprodução
    compartilhável, tentativa presa em `processing` após esgotar a entrega,
    envio de uma parte por vez, ausência de reordenação, de checksum ponta a
    ponta, de transcodificação, de cancelamento, de expiração automática, de
    retomada entre sessões, de tempo real, de APM e de métricas. **Nenhum desses
    itens tem tarefa de implementação** — eles existem só aqui.

    Roteiro de demonstração de sucesso: entrar como produtor, abrir o curso
    preparado, criar módulo e aula, enviar vídeo, acompanhar o processamento,
    publicar, entrar como consumidor e reproduzir. Roteiro de falha: acionar o
    comando sobre a tentativa **dedicada** ao cenário de falha, que pertence a um
    curso separado e não é a do curso vazio da jornada principal:

    ```
    ATTEMPT_ID="01936f1a-7c00-7a3e-9b7d-2f5c8e4a1d60"
    docker compose run --rm api php artisan demo:simulate-video-failure "$ATTEMPT_ID"
    ```

    O UUID é o mesmo literal preparado por T022 e usado por T067; o roteiro
    explica a qual cenário ele pertence, para o avaliador não confundir com o
    curso da jornada principal.
  - **Testes/validação:** executar cada comando documentado no ambiente já
    existente e confirmar que funciona, incluindo `make e2e`; percorrer os dois
    roteiros do começo ao fim; e revisão cruzada das limitações contra o
    `plan.md` §19.
  - **Depende de:** T100, T102.
  - **Critério de conclusão:** todos os comandos verificados localmente e os dois
    roteiros reproduzidos sem improviso. A reprodução a partir de clone limpo é
    validação externa e está concentrada em T109 — **não bloqueia esta tarefa**.

- [x] **T110** Reprodução do próprio vídeo pelo produtor
  - **Alteração de escopo aprovada depois da baseline.** Não renumera nem
    reaproveita identificador: entra como o próximo número livre, e a
    decomposição consolidada passa de 24 para 25 tarefas.
  - **Objetivo:** o produtor proprietário assiste ao vídeo que enviou, na mesma
    seção da aula, antes de decidir publicar — e a rota do consumidor continua
    exatamente como está.
  - **Arquivos previstos:** `backend/app/Video/Application/IssuePlayback/`,
    `backend/app/Video/Application/GetOwnedPlayback/`,
    `backend/app/Video/Application/GetPlayback/GetPlayback.php`,
    `backend/app/Video/Interfaces/Http/Controller/OwnedPlaybackController.php`,
    `backend/app/Video/Interfaces/Http/Resource/PlaybackResource.php`,
    `backend/routes/api.php`, `backend/app/Providers/AppServiceProvider.php`,
    `backend/tests/Feature/Video/ProducerPlaybackTest.php`,
    `backend/tests/Feature/Shared/HttpSurfaceTest.php`,
    `backend/tests/Feature/Shared/HttpContractTest.php`,
    `backend/tests/Feature/Identity/AuthRoleTest.php`, `docs/openapi.yaml`,
    `docs/api.md`, `docs/demonstracao.md`, `frontend/app/types/api.ts`,
    `frontend/app/components/video/ConferenciaDoVideo.vue`,
    `frontend/app/components/lesson/ItemDeAula.vue`,
    `frontend/tests/components/conferencia.spec.ts`.
  - **Requisitos:** RF-PLB-009, AC-PROD-008; RN-PROP-005, RN-AUT-002,
    RN-AUT-006; RF-AUL-006.
  - **Implementação — backend.** `GET /api/lessons/{lesson}/video/playback`, no
    grupo `auth:sanctum` + `role:producer`, com restrição UUID, nome
    `lessons.video.playback` e controller próprio invocável. Sem route model
    binding: carregar a aula antes de saber de quem ela é seria ler recurso
    alheio para só então recusá-lo. Exige sessão válida, perfil `producer`,
    propriedade resolvida dentro da consulta por `LessonRepository::findOwned`,
    tentativa atual em `ready` e referência de reprodução presente. A publicação
    **não** participa: rascunho e aula publicada respondem igual.

    A emissão da URL é **extraída**, não copiada. `IssuePlayback` localiza a
    tentativa atual, exige que seja reproduzível, assina pela porta
    `ObjectStorage` com o mesmo `url_ttl` de cinco minutos, converte falha de
    storage em `SERVICE_UNAVAILABLE` e devolve o mesmo resultado. Ela não
    autoriza: o caso de uso do consumidor confere concessão e publicação antes de
    chamá-la, o do produtor confere propriedade. O registro do TTL no
    `AppServiceProvider` passa a apontar para ela, e é um só.

    **O endpoint do consumidor não muda.** Continua exigindo perfil `consumer`,
    concessão e aula publicada; nada foi movido para um grupo comum, nenhum
    endereço passou a aceitar os dois perfis e não há ramificação por perfil
    dentro de caso de uso nenhum (plan §14.2).
  - **Implementação — interface.** No mesmo `ItemDeAula`, entre o painel do vídeo
    e a ação de publicar, porque a ordem na tela é a ordem da decisão. A ação
    aparece **somente** com o estado compartilhado em `ready`, não depende de
    `published_at` e não dispara requisição ao montar: a URL é pedida no clique.
    Enquanto pede, o controle fica desabilitado e informa andamento; o sucesso
    renderiza o `VideoReprodutor` existente ali mesmo; o erro aparece em estado
    próprio, e repetir pede uma URL nova. A URL não é exibida como texto nem
    oferecida para download.

    Sair de `ready` — ou trocar de aula — descarta reprodução e erro **e
    invalida a solicitação em voo**. Cada solicitação carrega uma geração local;
    a resposta de uma geração vencida não escreve reprodução, não escreve erro e
    não mexe no carregamento. Sem isso, uma Promise iniciada antes da mudança
    ainda resolveria depois dela, reexibindo um vídeo que já não é o atual ou uma
    negativa que já não descreve nada — e o carregamento ficaria preso, deixando
    o botão travado quando o vídeo voltasse a `ready`. Um contador por instância
    resolve o caso inteiro: `useApi` não foi ampliado e não há cancelamento
    global.

    A interface apenas decide quando oferecer o botão, a partir de estado que já
    tem. Nenhuma regra de autorização, publicação ou disponibilidade é duplicada
    aqui — o backend continua sendo a autoridade (RN-AUT-002, RF-UI-017).
  - **Contrato.** A operação entra no `docs/openapi.yaml` reaproveitando
    `ReproducaoEnvelope`, `AulaId` e as respostas compartilhadas, com `401`,
    `403`, `404`, `409` e `503` documentados e exemplos sem URL ou credencial
    funcional. Os tipos do frontend são regerados **apenas** por
    `npm run generate:types`. O inventário passa a 22 operações no contrato e 23
    registradas; `docs/api.md` acompanha a contagem.
  - **Testes/validação:** suíte de feature própria para a reprodução do produtor
    — dono com aula em rascunho e com aula publicada, resposta com exatamente os
    três campos, validade de cinco minutos, assinatura sobre a referência gravada
    e nunca sobre chave remontada, produtor alheio e aula inexistente
    indistinguíveis, visitante `401`, consumidor `403`, ausência de tentativa e
    cada estado fora de `ready` em `409`, `ready` sem referência em `409`,
    nenhum caminho negativo tocando o armazenamento, e falha ao assinar virando
    `503` sem vazamento. `ConsumerPlaybackTest` é preservado integralmente e
    continua verde depois da extração.

    Teste de componente cobrindo botão ausente fora de `ready`, presente em
    `ready` tanto em rascunho quanto publicada, ausência de chamada antes do
    clique, endereço correto no clique, estado de carregamento, player inline no
    sucesso, erro visível com repetição pedindo URL nova, descarte ao sair de
    `ready`, e publicação continuando disponível e independente.

    Três cenários usam **promessas controladas pelo teste**, porque o descarte do
    que já está na tela é o caso fácil: sucesso tardio, falha tardia e troca de
    aula com solicitação em voo. Em cada um a resposta antiga chega depois da
    mudança de estado, e o que se afirma é que ela não reaparece, que o botão
    volta utilizável e que uma solicitação nova ocupa o lugar. Verificado por
    regressão em duas metades: sem o incremento de geração os três reprovam com o
    estado antigo de volta na tela; com a geração, mas sem o encerramento do
    carregamento, os três reprovam com o botão preso em `disabled`.

    `HttpSurfaceTest` ganha a entrada na *allowlist* e as contagens novas.
    Verificado por regressão: com a rota registrada e ausente da lista, o teste
    reprova apontando exatamente `GET /api/lessons/{lesson}/video/playback` e a
    contagem 23 contra 22 — e volta a passar depois de declarada.
  - **O E2E não é ampliado, e isso é decisão.** A jornada existente já prova URL
    assinada e elemento `<video>` pelo lado do consumidor. O que esta tarefa
    acrescenta — autorização por propriedade, independência de publicação e
    apresentação inline — é afirmado por feature no backend e por componente no
    frontend, com menos custo e menos instabilidade do que um segundo percurso em
    navegador. Cenário, fixture, `scripts/e2e.sh`, número de jornadas e pipeline
    ficam intocados.
  - **Depende de:** T070, T088. A reprodução autorizada e a emissão de URL curta
    nascem em T070; a tela do produtor com painel do vídeo e ação de publicar,
    em T088. Esta tarefa se apoia nas duas e não refaz nenhuma.
  - **Critério de conclusão:** rota registrada e coberta pela suíte de feature
    própria; `ConsumerPlaybackTest` verde sem alteração de comportamento;
    superfície HTTP declarada e afirmada; contrato atualizado, válido em
    `make openapi-lint` e com tipos regerados pelo comando oficial; componente
    coberto; spec, plano e este documento atualizados na mesma leva; suítes
    completas de backend e frontend, Pint, ESLint, verificação de tipos, build e
    `make e2e` sem modificação da jornada.

- [x] **T111** Porta de entrada da aplicação
  - **Alteração de escopo aprovada depois da baseline.** Não renumera nem
    reaproveita identificador: entra como o próximo número livre, e a
    decomposição consolidada passa de 25 para 26 tarefas.
  - **Classificação: `[DECISÃO]` do projeto, e não exigência do desafio.** A
    seção §8 do desafio enumera as duas jornadas e não menciona endereço raiz,
    página inicial ou encaminhamento. A decisão nasce de uma observação de uso: a
    avaliação começa digitando o endereço da plataforma, e uma resposta de rota
    inexistente ali é indistinguível de aplicação fora do ar. Nenhum requisito
    obrigatório depende desta tarefa, e removê-la devolveria a entrega ao estado
    anterior sem violar o desafio.
  - **Objetivo:** o endereço raiz encaminha conforme a sessão, e trata
    indisponibilidade da API como indisponibilidade — nunca como ausência de
    sessão.
  - **Arquivos previstos:** `frontend/app/pages/index.vue`,
    `frontend/tests/pages/entrada.spec.ts`, `specs/001-video-platform/spec.md`,
    `specs/001-video-platform/plan.md`, `specs/001-video-platform/tasks.md`,
    `README.md`, `docs/demonstracao.md`.
  - **Requisitos:** RF-UI-018, AC-UI-005; RF-UI-001, RF-UI-011, RF-UI-012,
    RF-UI-014, RF-UI-017.
  - **Nada muda no backend.** Nenhuma rota, nenhum contrato, nenhum endpoint e
    nenhum schema do OpenAPI. A tarefa vive inteira na camada de apresentação, e
    o número de requisições de quem entra pela raiz é o mesmo de quem entra
    direto na área do perfil: a verificação de sessão é a que `middleware/auth`
    já faria no destino, e `useAuth` só pergunta enquanto não há resposta.
  - **Implementação — frontend.** `pages/index.vue` responde por `/` e decide um
    destino a partir do estado da sessão. Quatro desfechos: perfil `producer`
    para a área de gestão, perfil `consumer` para o catálogo, ausência
    confirmada — `401`, `anonima` ou `expirada` — para a autenticação, e
    indisponibilidade permanecendo na própria rota, com `UiEstadoDeFalha` e nova
    tentativa.

    O encaminhamento por perfil **não é recalculado**: vem de `destinoDoPerfil`,
    o mesmo mapa que `useAuth` aplica depois de autenticar. A navegação usa
    `replace`, para a raiz não ficar no histórico e prender quem usa o botão de
    voltar.

    A distinção entre os dois últimos desfechos é feita pelo **status**, e não
    pela existência da exceção: `recuperarSessao` lança nas duas situações, e
    apenas o `401` afirma algo sobre a sessão. Tratar rede e `5xx` como ausência
    anunciaria uma perda que não houve e mandaria a pessoa tentar entrar de novo,
    com a mesma indisponibilidade recusando o login em seguida — agora sem
    explicação (RF-UI-011, RF-UI-012, RF-UI-014).

    Uma guarda impede a segunda tentativa em voo. Sem ela o desfecho seria
    errado, e não apenas uma chamada a mais: `recuperarSessao` se recusa a
    começar enquanto a anterior não terminou, então a segunda passagem chegaria à
    decisão com a sessão ainda em verificação — nem autenticada, nem ausente — e
    encaminharia para a autenticação alguém que pode ter sessão válida.
  - **Decisão na página, e não em middleware de rota.** `middleware/auth` existe
    para *proteger* telas: evitar que alguém sem sessão aterrisse num conteúdo
    que só mostraria erros. A raiz não tem conteúdo a proteger, e tem um desfecho
    que precisa de tela — um middleware que não encaminha deixa a navegação
    parada, sem nada renderizado, que é exatamente o carregamento indefinido que
    RF-UI-012 proíbe. Na página, o estado de indisponibilidade e a nova tentativa
    aparecem no lugar em que a pessoa já está.
  - **Testes.** `tests/pages/entrada.spec.ts` cobre os quatro desfechos com
    `useAuth` substituído — o que também prova que o destino por perfil não é
    recalculado pela tela: um mapa próprio ignoraria o dublê e reprovaria.

    Os três primeiros desfechos são diretos. O quarto é afirmado em cinco
    cenários, e é onde está o valor da suíte: para rede, `5xx` e resposta fora do
    contrato, que **não há navegação**, que o estado de indisponibilidade aparece
    com a situação correta, que o carregamento não permanece, que a nova
    tentativa chama `recuperarSessao` outra vez, e que uma tentativa
    bem-sucedida encaminha ao destino do perfil. Um sexto cenário dispara dois
    cliques no mesmo elemento sem esperar entre eles, que é como a segunda
    tentativa encontra a primeira em voo.

    Verificado por regressão: uma página escrita pela negação — "se não está
    autenticado, vá para o login" — passa nos três primeiros desfechos e reprova
    nos cinco cenários do quarto.
  - **O E2E não é ampliado, e isso é decisão.** A jornada existente entra por
    `/login` e continua entrando: o que esta tarefa acrescenta é encaminhamento e
    tratamento de indisponibilidade, afirmados por teste de página com menos
    custo e menos instabilidade do que um percurso adicional em navegador.
    Cenário, fixture, `scripts/e2e.sh`, número de jornadas e pipeline ficam
    intocados.
  - **Depende de:** T078, T093. `useAuth`, o estado de sessão e os painéis de
    estado nascem em T078; a segunda área de destino — o catálogo do
    consumidor — fecha em T093. Sem as duas não haveria o que consultar nem para
    onde encaminhar.
  - **Critério de conclusão:** os quatro desfechos cobertos por teste de página;
    RF-UI-018 e AC-UI-005 definidos na spec e mapeados na matriz; plano e este
    documento atualizados na mesma leva; documentação de execução e roteiro
    apontando o endereço raiz como entrada; suítes completas de backend e
    frontend, ESLint, verificação de tipos, build e `make e2e` sem modificação da
    jornada.

- [ ] **T109** Revisão final de rastreabilidade e ausência de segredos
  - **Objetivo:** fechar a entrega conferindo coerência e higiene.
  - **Arquivos previstos:** `specs/001-video-platform/spec.md`,
    `specs/001-video-platform/plan.md`, `specs/001-video-platform/tasks.md`.
    A revisão acrescentou `backend/config/filesystems.php` e
    `backend/tests/Feature/Shared/HttpSurfaceTest.php`, pelo motivo registrado
    abaixo.
  - **Requisitos:** RNF-010, RNF-012, RNF-018.
  - **Implementação:** conferir que nenhum comportamento implementado diverge da
    spec sem que ela tenha sido atualizada, que todo critério de aceitação tem
    tarefa e teste, e que não há segredo real, chave de produção ou dado pessoal
    no repositório. Se a implementação tiver mudado algum comportamento descrito,
    atualizar a spec **na mesma leva**.

    É aqui que as **validações externas** são reunidas e cobradas: a evidência
    remota da pipeline (T104), a reprodução a partir de clone limpo (T105) e a
    conferência visual em largura reduzida (T093). A entrega não fecha com
    qualquer uma delas pendente.

    **Achado da revisão — duas rotas registradas sem caso de uso.** A auditoria
    da superfície HTTP encontrou `GET /storage/{path}` e `PUT /storage/{path}`
    entre as rotas da aplicação. Elas não foram escritas por nenhuma tarefa: o
    esqueleto do framework traz `serve => true` no disco local do
    `config/filesystems.php`, e essa opção as registra sozinha.

    Nesta arquitetura elas não têm caso de uso. Nenhum vídeo é gravado no disco
    local — todo objeto vive no armazenamento compatível com S3, alcançado pela
    porta `ObjectStorage`, e o navegador recebe URL pré-assinada em vez de buscar
    arquivo num endereço da aplicação. As duas ficavam fora do contrato OpenAPI,
    fora dos testes de autorização e fora da documentação.

    **Decisão aprovada:** `serve => false`. A motivação é superfície mínima e
    coerência contratual — a lista de rotas registradas passa a ser exatamente a
    do contrato mais o endpoint de prontidão. Registro em `spec.md` §9.3 e
    `plan.md` §§10.4 e 11.1. Nada no envio multipart, na reprodução, nos assets
    públicos ou no adapter S3 depende da opção, e a suíte completa confirma.

    **Prova da ausência:** `Tests\Feature\Shared\HttpSurfaceTest` afirma a
    superfície por igualdade contra uma lista fechada escrita no próprio teste —
    22 operações, sendo `GET /up` a única fora de `/api` e `/sanctum`. Verifica
    também que nenhuma URI começa por `storage/`, que os nomes de rota
    `storage.local` e `storage.local.upload` não existem, que os dois endereços
    não respondem, que os pontos de entrada oficiais continuam registrados e que
    `HEAD` segue derivado de cada `GET`. Verificado por regressão: com
    `serve => true`, oito dos catorze testes de então reprovaram, e a diferença
    relatada eram exatamente as duas rotas.

    **O teste não lê o `docs/openapi.yaml`.** A correspondência entre a lista
    fechada e as 21 operações do contrato foi conferida **manualmente** nesta
    revisão, e é reconferida sempre que um dos dois lados mudar. Um mecanismo
    automático ligando os dois seria uma segunda validação de contrato, com
    política própria de sincronização — a duplicação que `plan.md` §10.5
    descarta. A validação do documento em si continua sendo `make openapi-lint`,
    que roda separado.
  - **Testes/validação:** três frentes, porque nenhuma sozinha basta.

    1. **Varredura textual**, ignorando binários e tratando nomes com espaço:

    ```
    git ls-files -z | xargs -0 grep -InIE '(password|secret|token|api[_-]?key)[[:space:]]*[=:][[:space:]]*.+'
    ```

    `-z` e `-0` lidam com espaços no nome; `-I` faz o `grep` pular arquivos
    binários, incluindo o fixture MP4. O resultado **não** é uma lista de
    problemas: é uma lista para inspecionar, na qual placeholders de
    `.env.example` são esperados e qualquer valor que pareça real é investigado.
    2. **Revisão das variáveis de ambiente** — os três `.env.example` lidos linha
    a linha, confirmando que nenhum traz valor funcional.
    3. **Revisão dos arquivos de configuração versionados** — `docker-compose.yml`,
    workflow e configs do backend, procurando credencial embutida.

    O `grep` é heurística, não garantia; as três frentes juntas é que sustentam a
    conclusão. Além disso, a matriz de cobertura deste documento é revisada linha
    a linha.
  - **Depende de:** T104, T105, T110, T111. As duas alterações de escopo
    aprovadas depois da baseline entraram pela T110 e pela T111, e a revisão
    final de coerência só fecha depois de as duas estarem concluídas.
  - **Critério de conclusão:** coerência confirmada, nenhum segredo encontrado, e
    as três validações externas registradas como concluídas.

> **Checkpoint da Fase 18**
> **Passa a funcionar:** a entrega está completa e reproduzível, com documentação,
> roteiros e rastreabilidade conferidas.
> **Testes verdes:** toda a suíte, mais os dois roteiros executados manualmente.
> **Comandos:** `make up`, os comandos de teste de cada camada, `make e2e` e o
> roteiro do README.
> **Pendências de validação externa, reunidas em T109:** evidência remota da
> pipeline (T104), reprodução a partir de clone limpo (T105) e conferência visual
> em largura reduzida (T093). A entrega não fecha com qualquer uma delas em
> aberto.
> **Alterações de escopo posteriores:** a T110 acrescentou a conferência do
> próprio vídeo pelo produtor, e a T111, a porta de entrada da aplicação — esta
> como `[DECISÃO]` do projeto, e não como exigência do desafio. A T109 passou a
> depender das duas.
> **Ainda não iniciado:** nada dentro do escopo. Os itens fora do MVP permanecem
> apenas documentados como limitação.

---

## IDs absorvidos ou retirados da baseline

A decomposição original tinha 109 pacotes. Uma revisão de escopo consolidou as
tarefas pendentes em **24** — **26** depois das alterações de escopo que criaram
a T110 e a T111 —, sem remover nenhum requisito obrigatório do desafio: cada
critério de aceitação continua com tarefa de implementação e validação
planejadas, conforme a matriz de cobertura abaixo.

Os identificadores **não foram renumerados nem reaproveitados**. Os que saíram da
lista de pendências estão aqui, com destino e motivo, para que o histórico mostre
o que aconteceu com cada um. Eles **não** são checkbox pendente e **não** estão
concluídos: foram absorvidos por outra tarefa ou retirados do escopo.

Dois motivos organizam a tabela. **Absorvido** significa que o trabalho continua
previsto, dentro de uma tarefa maior — a granularidade diminuiu, o escopo não.
**Retirado** significa que o item deixou de existir, porque produzia complexidade
sem benefício proporcional ao recorte funcional que o desafio pede.

**Uma ressalva sobre os testes de corrida retirados.** Os testes que restam para
conclusão de upload, publicação e callback são **sequenciais**: repetem a mesma
operação e verificam que o efeito não se duplica. Isso comprova **idempotência**,
e é o que se afirma sobre eles. **Não** comprova concorrência: eles não colocam
duas execuções em disputa pelo mesmo recurso, e nenhuma passagem deste documento
deve tratá-los como prova disso. Os testes de corrida dedicados foram retirados
por priorização, não porque a corrida deixou de existir. Os mecanismos que a
contêm — lock da linha do pai, lock atômico por tentativa, transações curtas,
índices UNIQUE e a reserva do `event_id` — **continuam obrigatórios**, e a única
prova direcionada de simultaneidade preservada é a entrega concorrente do mesmo
`event_id`, dentro de T059.

| ID | Destino | Motivo |
| --- | --- | --- |
| T013 | Absorvido em T012 | Arquivos de exemplo e comando único só ficam corretos depois que o Compose declara todas as variáveis; separá-los obrigava a reabrir os mesmos arquivos |
| T015 | Absorvido em T014 | Ferramental de qualidade nasce com a árvore que ele verifica |
| T016 | **Retirado** | Um value object por tipo de identificador seriam cinco classes quase idênticas e seus testes de formato. O custo assumido é real: com todos os identificadores como `string`, o compilador deixa de distinguir o de um recurso do de outro. A correção passa a depender de repositórios e consultas específicos por recurso, nomes explícitos, verificação de existência e da autorização por propriedade ou concessão no backend — **UUID não substitui autorização**. UUIDv7 e `CHAR(36)` permanecem |
| T018 | **Retirado** | Sem inserção em posição arbitrária, a regra de ordem cabe no cálculo da próxima posição sob lock; não sobra invariante para um value object carregar |
| T019 | Absorvido em T014 | O catálogo de falhas públicas virou enumeração fechada, declarada junto do contrato HTTP que a expõe |
| T020 | Absorvido em T014 | O contrato de resposta é pré-requisito da mesma fase e não tem entrega própria demonstrável |
| T021 | Absorvido em T076 | Base e consolidação do OpenAPI viraram uma entrega só, feita depois dos endpoints |
| T023 a T027 | Absorvidos em T022 | As migrations seguem uma ordem única de chave estrangeira e são aplicadas e revertidas em conjunto; separá-las multiplicava commits sem separar risco |
| T028, T029 | Absorvidos em T022 | Os seeds descrevem o mesmo cenário de avaliação que o esquema sustenta |
| T031 a T033 | Absorvidos em T030 | Sessão, endpoints e mapeamento de erro de autenticação formam uma configuração única e são testados juntos |
| T035, T036 | Absorvidos em T034 | Isolamento por propriedade é a regra que dá sentido à listagem e ao detalhe do curso |
| T038 | Absorvido em T037 | Módulo e aula têm a mesma regra de ordem e o mesmo formato de caso de uso |
| T039 | **Retirado** | O que deixou de existir é a corrida específica do deslocamento de várias posições, e o teste dedicado a ela. A disputa por uma mesma posição **continua possível**: duas inclusões simultâneas podem ler o mesmo `MAX(position)` e tentar gravar o mesmo valor. Por isso o lock da linha do pai, a transação e o índice UNIQUE permanecem obrigatórios no código e no banco |
| T040, T041 | Absorvidos em T037 | As leituras ordenadas e a estrutura completa consultam exatamente o que a mesma tarefa persistiu |
| T044 a T048 | Absorvidos em T043 | Abertura, URL de parte, lock, conclusão e resultado ambíguo são um único fluxo com um único agregado |
| T049 | **Retirado** | A idempotência da conclusão é provada pelo teste de conclusão repetida; o lock atômico e a reavaliação sob `FOR UPDATE` permanecem no código |
| T050, T051 | Absorvidos em T043 | Consulta de estado e novo envio após falha são casos do mesmo agregado |
| T052 | **Retirado** | O envio interrompido passa a ser coberto pelo teste da tela de upload em T088: falha de transferência não chama a conclusão e não apresenta o vídeo como pronto |
| T054 | **Retirado** | A idempotência da publicação é provada pela republicação sem efeito; a transação e o lock permanecem no código |
| T056 a T058 | Absorvidos em T055 | Enfileiramento atômico, job retomável e identificador de evento estável são a mesma configuração de fila |
| T060 | Absorvido em T059 | A verificação de origem é a primeira etapa do mesmo endpoint |
| T061 | **Retirado** | O `event_id` passa a ser a única chave de idempotência; a reentrega repete o desfecho armazenado sem comparar conteúdo |
| T062 a T066 | Absorvidos em T059 | Reserva, desfechos, códigos de resposta, efeitos e a prova de entrega concorrente pertencem ao mesmo caso de uso |
| T068, T069 | Absorvidos em T067 | O comando de falha e a retomada do processamento usam o mesmo componente do simulador |
| T071 a T074 | Absorvidos em T070 | Catálogo, reprodução e negativas do consumidor decorrem da mesma regra de concessão |
| T075 | **Retirado** | O endpoint de prontidão padrão do Laravel e os healthchecks atuais do Compose passam a ser a solução definitiva, e não há endpoint de saúde adicional. As anotações que tratavam `/up` como provisório — em T010 e no `docker-compose.yml` — foram removidas junto: o endpoint deixa de ser temporário e nada o substitui |
| T077 | **Retirado** | O OpenAPI continua completo e validado por sintaxe; comparar campo a campo cada resposta com o schema exigiria manter um segundo modelo do contrato dentro da suíte, e o formato de cada resposta já é afirmado pelo teste de feature do endpoint |
| T079 a T083 | Absorvidos em T078 | Ferramental, tipos, cliente HTTP, sessão e componentes de estado são a fundação que nenhuma tela usa isoladamente |
| T084 | Absorvido em T078 | O login é o fluxo de formulário que prova a fundação |
| T086, T087 | Absorvidos em T085 | Detalhe do curso e criação de módulo e aula são a mesma tela |
| T089 a T092 | Absorvidos em T088 | Upload, progresso, acompanhamento do estado e publicação são a mesma jornada, na mesma tela |
| T094 a T097 | Absorvidos em T093 | Navegação, reprodução, negativas, responsividade e acessibilidade são a mesma área do consumidor |
| T098, T099 | Absorvidos em T100 | Orquestração, fixture e jornada só se provam juntas; o teste de fumaça existia para validar a orquestração antes da jornada existir |
| T101 | **Retirado** | Os erros obrigatórios permanecem cobertos pelos testes de backend e de frontend, cada um no nível em que a regra vive; repeti-los em navegador real acrescentava minutos e instabilidade sem acrescentar garantia |
| T103 | Absorvido em T102 | A validação do OpenAPI é uma etapa do job de backend, não um job nem uma tarefa |
| T106 a T108 | Absorvidos em T105 | README, arquitetura, limitações, guia da API e roteiros são um único corpo de documentação, revisado de uma vez |

---

## Dependências e paralelismo

### Ordem crítica das fases

```
Fase 0  spike do storage
Fase 1  ambiente containerizado
Fase 2  dominio + contrato HTTP
Fase 3  persistencia e dados de avaliacao
Fase 4  autenticacao e autorizacao
Fase 5  catalogo do produtor
Fase 6  upload multipart
Fase 7  publicacao
Fase 8  fila e processamento
Fase 9  webhook
Fase 10 simulador
Fase 11 consumo e reproducao
Fase 12 contrato publicavel
Fase 13 fundacao do frontend
Fase 14 jornada do produtor
Fase 15 jornada do consumidor
Fase 16 E2E
Fase 17 pipeline
Fase 18 documentacao e fechamento
```

A cadeia das tarefas ativas é linear até T093, e ali abre em dois ramos
independentes que voltam a se encontrar na documentação:

```
T012 → T014 → T017 → T022 → T030 → T034 → T037 → T042 → T043 → T053
     → T055 → T059 → T067 → T070 → T076 → T078 → T085 → T088 → T093

         ┌─▶ T100 ─────────────────┐        T100  E2E local
         │                         │        T102  pipeline: backend e frontend
T093 ────┤                         ├─▶ T105 ──┐   T104  evidencia remota
         │                         │          ├─▶ T109
         └─▶ T102 ────┬────────────┘          │
                      │                       │
                      └─▶ T104 ───────────────┤
                                              │
T070 ──┐                                      │
       ├─▶ T110 ──────────────────────────────┤   T110  reproducao pelo produtor
T088 ──┘                                      │
                                              │
T078 ──┐                                      │
       ├─▶ T111 ──────────────────────────────┘   T111  porta de entrada
T093 ──┘
```

T100 e T102 não dependem uma da outra: a pipeline não executa o E2E, e o E2E não
espera a pipeline. T105 depende das duas porque documenta tanto a execução local
da jornada quanto a pipeline.

T110 e T111 são as duas alterações de escopo aprovadas depois da baseline, e
nenhuma delas toca o ramo do E2E ou o da pipeline. T110 pende de T070, que trouxe
a reprodução autorizada e a emissão de URL curta, e de T088, que trouxe a tela do
produtor com painel do vídeo e ação de publicar. T111 pende de T078, que trouxe a
sessão e os painéis de estado, e de T093, que fechou a segunda área de destino —
sem as duas não haveria o que consultar nem para onde encaminhar. T109 fecha com
T104, T105, T110 e T111.

### Dependências que não podem ser invertidas

| Restrição | Motivo |
| --- | --- |
| Tudo depende de T001 | O RustFS é a decisão de maior risco do plano. Só T001 tem `Depende de: nenhuma` |
| Comandos precedem o Compose | T005, T006 e T009 usam `docker build` e `docker run`, porque o `docker-compose.yml` só nasce em T007 |
| Bucket é do `setup` | T008 apenas deixa o `rustfs` saudável; bucket e CORS são criados em T011 |
| Rollback antes de trocar migration | T022 desfaz o lote do scaffold enquanto os arquivos originais e seus métodos `down` ainda existem, e só então substitui as migrations |
| Contrato antes do primeiro endpoint | T014 vem antes de T030, para nenhuma rota nascer num formato que precise ser reescrito |
| Agregado antes do repositório | `Course` em T034, `Module` e `Lesson` em T037, `VideoAttempt` em T043, `AccessGrant` em T070, `WebhookEvent` em T059 — cada um com seu repositório na mesma tarefa |
| Vídeo antes da publicação | T053 depende de T043, porque publicar exige a tentativa de vídeo como agregado |
| Fila configurada antes de existir trabalho | T012 configura a conexão em banco e sobe os dois processos, que passam a consultar `default` e `simulator`. Em T055 o `worker` recebe o primeiro job da aplicação e uma entrega é agendada para `simulator`. Em T067 o `simulator-worker` ganha o componente que processa essa entrega e chama o webhook — antes disso, nenhuma entrega do simulador é consumida |
| Webhook antes do simulador | T067 depende de T059: o endpoint que o simulador chama precisa existir antes dele |
| Contrato completo antes do frontend | T078 depende de T076, a consolidação que cobre upload, webhook, publicação, consumo e reprodução |
| E2E depende da pilha completa | T100 exige backend, frontend, storage, fila e simulador rodando juntos |
| Pipeline e E2E são independentes | T102 não executa nem orquestra o E2E; as duas partem de T093 e só se reencontram na documentação, em T105 |
| Reprodução e tela do produtor antes da conferência | T110 reaproveita a emissão de URL curta nascida em T070 e o item de aula nascido em T088; sem as duas não haveria o que extrair nem onde apresentar |
| Sessão e áreas de destino antes da porta de entrada | T111 encaminha conforme o perfil e apresenta indisponibilidade: precisa da sessão e dos painéis de estado de T078, e das duas áreas de destino, a segunda delas fechada em T093 |

### Paralelismo

Nenhuma tarefa pendente é marcada `[P]`. A única que foi, T009, já está
concluída. A cadeia acima é sequencial porque cada tarefa consome o que a
anterior persistiu, expôs ou documentou.

O paralelismo exigido pelo plano é entre **jobs** da pipeline — `backend` e
`frontend` sem dependência entre si, em T102 —, não entre tarefas de
implementação.

---

## Matriz de cobertura

Nenhuma tarefa exige um arquivo de teste por critério: um teste pode cobrir
vários, e a coluna de validação nomeia a tarefa em que a prova é executada.

### Critérios de aceitação

| Grupo | Critério | Implementação | Validação |
| --- | --- | --- | --- |
| Produtor | AC-PROD-001 | T034, T085 | T034, T085 |
| Produtor | AC-PROD-002 | T034 | T034 |
| Produtor | AC-PROD-003 | T037, T085 | T037, T085 |
| Produtor | AC-PROD-004 | T053, T088 | T053, T088 |
| Produtor | AC-PROD-005 | T053, T088 | T053, T088 |
| Produtor | AC-PROD-006 | T053 | T053 |
| Produtor | AC-PROD-007 | T034 | T034 |
| Produtor | AC-PROD-008 | T110 | T110 |
| Vídeo | AC-VID-001 | T042, T043, T088 | T042, T088, T100 |
| Vídeo | AC-VID-002 | T043 | T043 |
| Vídeo | AC-VID-003 | T043 | T043 |
| Vídeo | AC-VID-004 | T059 | T059 |
| Vídeo | AC-VID-005 | T059 | T059 |
| Vídeo | AC-VID-006 | T059 | T059 |
| Vídeo | AC-VID-007 | T059, T088 | T059, T088 |
| Vídeo | AC-VID-008 | T017 | T017 |
| Vídeo | AC-VID-009 | T059 | T059 |
| Vídeo | AC-VID-010 | T043 | T043 |
| Vídeo | AC-VID-011 | T043 | T043 |
| Vídeo | AC-VID-012 | T043, T088 | T043, T088 |
| Vídeo | AC-VID-013 | T059 | T059 |
| Consumidor | AC-CONS-001 | T070, T093 | T070, T093, T100 |
| Consumidor | AC-CONS-002 | T070, T093 | T070, T093 |
| Consumidor | AC-CONS-003 | T070, T093 | T070, T093 |
| Consumidor | AC-CONS-004 | T070, T093 | T070, T093 |
| Consumidor | AC-CONS-005 | T070 | T070 |
| Interface | AC-UI-001 | T078, T085 | T078, T085 |
| Interface | AC-UI-002 | T078 | T078 |
| Interface | AC-UI-003 | T030, T078 | T030, T078 |
| Interface | AC-UI-004 | T088 | T088 |
| Interface | AC-UI-005 | T111 | T111 |
| Integrado | AC-E2E-001 | T022, T100 | T100 |

### Requisitos não funcionais

| Grupo | RNF | Implementação | Validação |
| --- | --- | --- | --- |
| Ambiente | RNF-009, RNF-020 | T012, T022 | T012, T105 |
| Desempenho do envio | RNF-001, RNF-002 | T042, T043, T055 | T042, T088 |
| Concorrência | RNF-003 | T037, T043, T053, T059 | T043, T059 |
| Domínio | RNF-004 | T014, T017 | T014, T017 |
| Testes | RNF-005, RNF-006, RNF-007 | T014, T078, T100 | T100, T105 |
| Pipeline | RNF-008, RNF-017 | T102 | T102, T104 |
| Segurança | RNF-010, RNF-018 | T012, T014, T030, T059 | T059, T109 |
| Documentação | RNF-011 | T076, T105 | T076, T105 |
| Especificação e Git | RNF-012, RNF-014, RNF-015 | T109 | T109 |
| Entrega | RNF-016, RNF-019 | T105 | T105, T109 |
| Simplicidade | RNF-013 | Toda a decomposição | T109 |

### Requisitos com destino transitivo

A revisão final conferiu, identificador por identificador, os **218** definidos
na `spec.md`:

| Família | Definidos |
| --- | --- |
| `RF` | 116 |
| `RN` | 36 |
| `AC` | 32 |
| `RNF` | 20 |
| `ABERTO` | 14 |

Eram 214 na baseline. Os quatro acrescentados vieram das duas alterações de
escopo posteriores: RF-PLB-009 e AC-PROD-008 pela T110, e RF-UI-018 e AC-UI-005
pela T111. Os quatro aparecem nominalmente na lista de requisitos da tarefa
correspondente — não entram, portanto, entre os de destino transitivo.

**Todos têm destino, depois de expandidas as notações de intervalo.** Boa parte
não é citada uma a uma: as tarefas escrevem `RF-CUR-001 a 005` ou
`RN-PROP-001 a 003`, e a conferência expandiu cada intervalo antes de comparar.
Aparecer dentro de um intervalo conta como destino.

Sobraram **catorze** que não apareciam individualmente nem por intervalo em
nenhuma lista de requisitos. Esta seção dá a cada um deles um mapeamento
explícito, para que a ausência do nome não seja lida como ausência de cobertura.
Nenhum dos catorze fica sem tarefa: mesmo os que são decisões de *não* oferecer
uma operação têm tarefa responsável e teste que afirma a ausência.

**Requisitos de ausência.** São decisões de *não* oferecer uma operação. Cada um
tem tarefa e tem prova: o teste afirma que a rota não existe, e é ele que faz uma
rota acrescentada por descuido reprovar em vez de passar despercebida.

| ID | Classificação | Tarefa que a afirma | Prova da ausência |
| --- | --- | --- | --- |
| RF-CUR-005 | `[DECISÃO]` sem atualização nem exclusão de curso | T034 | `CourseRoutesTest::test_nao_existe_rota_de_atualizacao_nem_de_exclusao` e `::test_a_colecao_tambem_nao_aceita_verbos_alem_de_get_e_post` |
| RF-AUL-007 | `[DECISÃO]` sem CRUD adicional de aula | T037 | `CatalogAccessTest::test_nao_existe_atualizacao_nem_exclusao` e `::test_nao_existe_listagem_separada_de_aulas_do_modulo` |
| RF-MOD-005 | `[OPCIONAL]` reordenação por arrastar e soltar | T037 | `CatalogAccessTest::test_nao_existe_rota_de_reordenacao` |

A superfície HTTP inteira é afirmada por igualdade em
`HttpSurfaceTest::test_a_superficie_registrada_e_exatamente_a_declarada`, que
cobre as três ausências acima de uma vez só e acrescenta a do sistema de
arquivos local (T109).

**Requisito obrigatório que se materializa como limite do simulador.**

| ID | Classificação | Tarefa que o cumpre | Como se cumpre |
| --- | --- | --- | --- |
| RF-PROC-005 | `[OBRIGATÓRIO]` não há transcodificação real | T067 | O simulador representa o provedor externo e devolve o desfecho por callback assinado, sem tocar no arquivo. O desafio dispensa a transcodificação explicitamente, e a documentação final a apresenta como limite assumido |

**Requisitos funcionais cobertos transitivamente.** O comportamento é implementado
e testado sob outro identificador; o que falta é apenas a citação nominal na
lista de requisitos da tarefa.

| ID | Onde se resolve |
| --- | --- |
| RN-PROP-004 | T034 e T037 — módulos e aulas herdam a propriedade do curso, e é o que o isolamento da árvore exercita |
| RF-AUT-002 | T034, pela verificação de propriedade nas operações de gestão |
| RF-AUT-003 | T070, pela verificação de concessão nas operações de consumo |
| RF-AUT-004 | T030 e T070 — a decisão de autorização é sempre refeita no backend |
| RF-AUL-006 | T053 — a publicação é rota própria, separada da criação da aula |
| RN-CUR-002 | T053, provado por AC-PROD-006 |
| RN-CUR-003 | T053 — não existe rota de publicação de curso; o estado é consequência |
| RF-ERR-012 | T078, sob RF-UI-011 |
| RF-ERR-013 | T043, T053 e T059 tornam repetíveis, respectivamente, a conclusão do envio, a publicação e a entrega do callback, sem duplicar efeito. São as três operações que RF-ERR-013 nomeia |

**Decisão técnica sem tarefa própria.**

| ID | Onde se resolve |
| --- | --- |
| ABERTO-014 | `plan.md` §§11.2 e 19.1. A parte decidida como implementável — recuperação limitada à tentativa atual, com reenvio de parte e renovação de URL — é entregue por T043 no backend e por T088 na interface. Cancelamento, descarte, expiração e retomada entre sessões ficam registrados como limitação consciente |

Duas observações de anotação, sem efeito sobre a cobertura: **AC-PROD-002** e
**AC-PROD-007** são provados por testes de isolamento de curso, módulo, aula e
estrutura, mas os arquivos de teste não citam os identificadores no cabeçalho,
como os demais fazem. A prova existe; a etiqueta, não.

### Cobertura mínima de testes

O que a entrega precisa provar, independentemente de como os arquivos de teste
sejam organizados:

**Backend** — tabela de transições do vídeo; criação, listagem, detalhe e
isolamento de cursos; ordem de módulos e aulas; autorização entre produtores;
abertura de upload e conclusão válida e inválida; conclusão repetida sem
processamento duplicado; HMAC inválido; callback de sucesso e de falha; callback
duplicado sem efeito duplicado; evento fora do estado esperado sem regressão;
publicação bloqueada e publicação idempotente; reprodução autorizada e negada nos
dois caminhos — o do consumidor, por concessão e publicação, e o do produtor, por
propriedade e independente de publicação.

**Frontend** — ao menos um formulário cobrindo sucesso, validação e erro; sessão
expirada; API indisponível; upload com progresso; falha de transferência sem
conclusão indevida; vídeo processando, pronto e com falha; conferência do próprio
vídeo sob demanda, sem requisição antes do clique; conteúdo indisponível ou não
autorizado.

**Integrado** — uma jornada crítica real, em navegador, atravessando interface e
backend.

### Fora de escopo

Reordenação de módulos e aulas, cancelamento de upload, expiração automática,
limpeza de partes órfãs, retomada entre sessões, envio de partes em paralelo,
WebSocket ou SSE, APM, métricas, checksum ponta a ponta, transcodificação e
provedor externo real **não possuem tarefa de implementação**, por decisão
registrada em `plan.md` §19. Eles aparecem apenas na documentação de limitações,
produzida em T105.

---

## Definição global de pronto

Uma tarefa está pronta para revisão quando **todas** as condições abaixo valem:

1. **Spec e plano respeitados.** O comportamento implementado corresponde ao que a
   `spec.md` descreve e à forma que o `plan.md` decidiu. Se a implementação mudou
   algum comportamento descrito, a spec foi atualizada na mesma leva.
2. **Comportamento implementado por inteiro.** Nada do escopo da tarefa ficou pela
   metade sem estar dito.
3. **Testes verdes.** Os testes que a tarefa declara foram executados e passaram.
4. **Verificação adicional da camada verde.** Pint no backend, ESLint e
   verificação de tipos no frontend, conforme a camada tocada.
5. **Documentação atualizada.** README, OpenAPI ou roteiros ajustados quando a
   tarefa mudou algo que eles descrevem.
6. **Ambiente reproduzível.** O trabalho roda em container, sem depender de
   ferramenta instalada no host.
7. **Sem segredos.** Nenhuma credencial real, chave de produção ou dado pessoal
   entrou no repositório.
8. **Nenhum item fora de escopo.** Nada da lista de limitações conscientes foi
   implementado por iniciativa própria.
9. **Verificações locais suficientes para fechar a tarefa.** Uma tarefa de
   implementação fecha com o que pode ser verificado em container. O que exige
   `push`, execução de workflow ou clone limpo **não a bloqueia**: a implementação
   não executa esses passos, reporta a pendência nomeando quem a executa, e ela é
   cobrada nas verificações externas — T104 e T109. Essas duas, sim, permanecem
   `[ ]` até a confirmação externa, e são o que impede a entrega de fechar sem
   evidência.
10. **Pronta para revisão técnica.** Com o commit sugerido, os arquivos
    específicos listados, e o relatório no formato acordado — incluindo o que
    ficou ruim ou incompleto.

`[x]` significa que essas dez condições valem. Não significa aprovado: a
aprovação é da revisão.
