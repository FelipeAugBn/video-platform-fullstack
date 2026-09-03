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
   PHPStan não roda, o problema é o PHPStan — não é motivo para remover a análise
   estática da tarefa.
3. **Decisão arquitetural imprevista interrompe a tarefa.** Ela não é resolvida
   dentro do commit: para, explica alternativas, custos e impactos, e aguarda
   aprovação.
4. **Testes não são adiados para o fim.** Cada tarefa carrega os seus. Uma fase
   não fecha com implementação verde e teste pendente.

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

- [ ] **T007** Base do Compose com MySQL
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

- [ ] **T008** Serviço `rustfs`
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

- [ ] **T009** `[P]` Imagem e scaffolding do Nuxt 4
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

- [ ] **T010** Serviços `api` e `web`, com prontidão temporária
  - **Objetivo:** a API respondendo HTTP através de um servidor web à frente do
    PHP-FPM.
  - **Arquivos previstos:** `docker-compose.yml`, `docker/web/nginx.conf`.
  - **Requisitos:** RNF-020; plan §16.2.
  - **Implementação:** `api` roda PHP-FPM e seu healthcheck verifica o
    **processo** — PHP-FPM fala FastCGI, não HTTP. O healthcheck HTTP fica no
    `web`. **Estado temporário e declarado:** o `web` aponta para o endpoint de
    prontidão padrão do Laravel, porque `/api/health` só existe em T075. A
    substituição é responsabilidade daquela tarefa e está registrada nela.
  - **Testes/validação:** `docker compose up -d web` e
    `curl -fsS http://localhost:8080/up` retornando sucesso.
  - **Depende de:** T008.
  - **Critério de conclusão:** `api` e `web` saudáveis, com o caráter temporário
    do endpoint anotado no `docker-compose.yml`.

- [ ] **T011** Serviço `setup`
  - **Objetivo:** preparar banco e storage antes de a aplicação subir, e então
    encerrar.
  - **Arquivos previstos:** `docker-compose.yml`, `docker/backend/setup.sh`.
  - **Requisitos:** RNF-009, RNF-019, RNF-020; plan §16.2.
  - **Implementação:** aguarda `mysql` e `rustfs` saudáveis; cria o bucket e
    aplica o CORS com os arquivos promovidos em T003; roda as migrations
    disponíveis; sai com código zero. **Neste momento só existem as migrations
    padrão do Laravel** — as de domínio chegam na Fase 3, e os seeds em T028. O
    script é escrito para evoluir sem mudar de forma.
  - **Testes/validação:** `docker compose up setup` termina com código zero, e o
    bucket passa a existir — o que não acontecia ao fim de T008.
  - **Depende de:** T010.
  - **Critério de conclusão:** bucket e CORS criados pelo `setup`, migrations
    padrão aplicadas.

- [ ] **T012** Serviços `worker`, `simulator-worker` e `frontend`
  - **Objetivo:** completar os oito serviços do ambiente.
  - **Arquivos previstos:** `docker-compose.yml`.
  - **Requisitos:** ABERTO-004, ABERTO-005, ABERTO-012; plan §16.1.
  - **Implementação:** `worker` e `simulator-worker` reutilizam a imagem de `api`,
    mudando apenas o comando e a fila. `frontend` depende de `web` saudável.
    **Nenhum Redis.** Os dois workers ficam **definidos e sobem**, mas ainda não
    têm o que consumir: a fila em banco só é configurada em T055, e as tabelas
    chegam em T022. Isso está declarado no checkpoint.
  - **Testes/validação:** `docker compose up -d` sobe os oito serviços;
    `docker compose ps` não mostra reinício em laço;
    `docker compose config | grep -i redis` não retorna nada.
  - **Depende de:** T009, T011.
  - **Critério de conclusão:** oito serviços definidos e em execução, sem Redis.

- [ ] **T013** `.env.example` e comando único de inicialização
  - **Objetivo:** quem clona o repositório sobe tudo com um comando, sem receber
    segredo real.
  - **Arquivos previstos:** `.env.example`, `backend/.env.example`,
    `frontend/.env.example`, `Makefile`.
  - **Requisitos:** RNF-010, RNF-018, RNF-020; plan §16.
  - **Implementação:** apenas placeholders. Documentar as variáveis de endereço
    interno e público do storage e o segredo do webhook.
  - **Testes/validação:** `cp .env.example .env && make up` sobe o ambiente;
    `grep -rIn "password\|secret\|key" .env.example` mostra apenas placeholders.
  - **Depende de:** T012.
  - **Critério de conclusão:** ambiente sobe com um comando, sem edição manual
    além da cópia do exemplo.

> **Checkpoint da Fase 1**
> **Serviços definidos:** os oito do plano — `mysql`, `rustfs`, `setup`, `api`,
> `web`, `worker`, `simulator-worker` e `frontend`.
> **Efetivamente prontos:** `mysql`, `rustfs`, `setup`, `api`, `web` e
> `frontend`. O `setup` já cria bucket e CORS e aplica as migrations padrão.
> **Definidos mas ainda sem função:** `worker` e `simulator-worker` sobem e
> permanecem ociosos — a fila em banco só é configurada em T055 e suas tabelas em
> T022. Não afirmamos que estão funcionais.
> **Testes verdes:** nenhum de aplicação; não há código de negócio ainda.
> **Comandos:** `docker compose up -d`, `docker compose ps`,
> `docker compose run --rm frontend npm run build`.
> **Ainda não iniciado:** domínio, migrations de domínio, seeds, autenticação,
> endpoints e telas.

---

## Fase 2 — Fundação do backend e contrato HTTP

O contrato de resposta é estabelecido **antes** do primeiro endpoint, para que
nenhuma rota nasça num formato que depois precise ser reescrito.

- [ ] **T014** Estrutura das quatro camadas e das três áreas
  - **Objetivo:** a árvore do backend refletindo a arquitetura decidida, com
    autoload configurado.
  - **Arquivos previstos:** `backend/app/Identity/`, `backend/app/Catalog/`,
    `backend/app/Video/`, `backend/app/Shared/`, cada uma com `Domain/`,
    `Application/`, `Infrastructure/` e `Interfaces/`; `backend/composer.json`.
  - **Requisitos:** ABERTO-011; RNF-004; plan §§5.1, 5.2.
  - **Implementação:** criar a árvore e ajustar o autoload PSR-4. Nenhuma classe
    de negócio ainda. Sem repositório genérico, CQRS ou event sourcing.
  - **Testes/validação:**
    `docker compose run --rm api composer dump-autoload` conclui sem aviso.
  - **Depende de:** T013.
  - **Critério de conclusão:** árvore criada e autoload funcionando.

- [ ] **T015** Ferramental de qualidade do backend
  - **Objetivo:** formatação, análise estática e testes disponíveis por comando
    desde o início.
  - **Arquivos previstos:** `backend/pint.json`, `backend/phpstan.neon`,
    `backend/phpunit.xml`.
  - **Requisitos:** ABERTO-013; RNF-005, RNF-008; plan §17.1.
  - **Implementação:** Pint com preset adotado, PHPStan com Larastan em nível
    declarado, PHPUnit apontando para o banco de testes criado em T007.
  - **Testes/validação:**
    `docker compose run --rm api ./vendor/bin/pint --test`,
    `docker compose run --rm api ./vendor/bin/phpstan analyse`,
    `docker compose run --rm api php artisan test` — os três verdes numa base
    vazia.
  - **Depende de:** T014.
  - **Critério de conclusão:** os três comandos executam e passam.

- [ ] **T016** Value objects de identificador, com UUIDv7
  - **Objetivo:** identificadores tipados que impedem passar o id de um recurso
    onde se espera o de outro.
  - **Arquivos previstos:** `backend/app/Shared/Domain/Identifier/` com
    `CourseId`, `ModuleId`, `LessonId`, `VideoAttemptId`, `UserId`;
    `backend/tests/Unit/Shared/IdentifierTest.php`.
  - **Requisitos:** RN-PROP-005; plan §7.1.
  - **Implementação:** geração UUIDv7, validação de formato, comparação por valor,
    tipos distintos por recurso.
  - **Testes/validação:** o teste cobre **formato** UUID válido, **versão** 7 nos
    bits corretos, **rejeição** de entrada inválida, **igualdade por valor**,
    **incompatibilidade** entre tipos diferentes de identificador, e **ausência de
    colisão** num conjunto grande gerado no teste. **Não** testar ordenação
    temporal entre gerações sucessivas: o `plan.md` §7.1 registra que a ordem
    estrita dentro do mesmo instante depende do gerador e não é usada pelo
    projeto. `docker compose run --rm api php artisan test --filter=Identifier`.
  - **Depende de:** T015.
  - **Critério de conclusão:** os seis aspectos cobertos e verdes, sem teste de
    ordenação estrita.

- [ ] **T017** `VideoState` e a tabela de transições
  - **Objetivo:** um único lugar que responde se uma transição é permitida.
  - **Arquivos previstos:** `backend/app/Video/Domain/VideoState.php`,
    `backend/tests/Unit/Video/VideoStateTest.php`.
  - **Requisitos:** RF-VID-001; RN-VID-001 a 003; AC-VID-008; plan §6.2.
  - **Implementação:** os seis estados e exatamente as transições da tabela do
    plano. Saltos e regressões rejeitados. `ready` e `failed` terminais.
  - **Testes/validação:** teste percorrendo a matriz completa de origem e destino.
    `docker compose run --rm api php artisan test --filter=VideoState`.
  - **Depende de:** T016.
  - **Critério de conclusão:** AC-VID-008 coberto e verde.

- [ ] **T018** `Position` e a regra de ordenação
  - **Objetivo:** a regra de posição isolada e testável sem banco.
  - **Arquivos previstos:** `backend/app/Shared/Domain/Position.php`,
    `backend/tests/Unit/Shared/PositionTest.php`.
  - **Requisitos:** RN-ORD-001 a 004; plan §6.2.
  - **Implementação:** inteiro positivo, comparável, com a regra de deslocamento
    no domínio. A garantia estrutural no banco chega na Fase 3.
  - **Testes/validação:** inserção ao fim, inserção no meio com deslocamento e
    rejeição de valor inválido.
    `docker compose run --rm api php artisan test --filter=Position`.
  - **Depende de:** T017.
  - **Critério de conclusão:** testes verdes.

- [ ] **T019** `FailureInfo` e vocabulário de erros de domínio
  - **Objetivo:** garantir por construção que nenhuma mensagem interna chegue ao
    usuário.
  - **Arquivos previstos:** `backend/app/Shared/Domain/FailureInfo.php`,
    `backend/app/Shared/Domain/Exception/`.
  - **Requisitos:** RN-AUT-005; RF-WHK-011; plan §§6.2, 13.4.
  - **Implementação:** `FailureInfo` só aceita código e mensagem de um conjunto
    controlado pela aplicação. Não existe construtor público para texto livre
    externo. Exceções de domínio carregam código funcional estável.
  - **Testes/validação:**
    `docker compose run --rm api php artisan test --filter=FailureInfo`.
  - **Depende de:** T018.
  - **Critério de conclusão:** testes verdes e ausência de construtor permissivo.

- [ ] **T020** Infraestrutura de contrato HTTP
  - **Objetivo:** envelope, erro e paginação prontos **antes** do primeiro
    endpoint.
  - **Arquivos previstos:** `backend/app/Shared/Interfaces/Http/` com resource
    base, trait de paginação, handler de exceções e catálogo de códigos
    funcionais.
  - **Requisitos:** ABERTO-010; RN-AUT-005; RF-UI-009, RF-UI-010, RF-UI-017;
    plan §§10.1, 10.2, 10.3.
  - **Implementação:** recurso e coleção sob `data`; coleção paginada acrescenta
    `meta` e `links`, com `per_page` padrão 15 e teto 50 — valor acima é limitado,
    não aceito. Erros em `application/problem+json` com `type`, `title`,
    `status`, `detail`, `code` e, na validação, `errors` por campo. Nenhum rastro
    de execução no corpo.
  - **Testes/validação:** rota de teste temporária exercitando envelope,
    paginação e os formatos de erro.
    `docker compose run --rm api php artisan test --filter=HttpContract`.
  - **Depende de:** T019.
  - **Critério de conclusão:** formatos disponíveis e testados antes de existir
    endpoint de negócio.

- [ ] **T021** Base do documento OpenAPI e teste de contrato
  - **Objetivo:** o contrato nasce junto com o formato, e cresce endpoint a
    endpoint.
  - **Arquivos previstos:** `docs/openapi.yaml`,
    `backend/tests/Feature/Contract/OpenApiContractTest.php`.
  - **Requisitos:** RNF-011; ABERTO-010; plan §10.5.
  - **Implementação:** estrutura do documento, schemas compartilhados de envelope,
    paginação e problema. **A partir daqui, toda tarefa que cria ou altera
    endpoint atualiza o trecho correspondente do OpenAPI e estende este teste** —
    a consolidação final é conferência, não a primeira vez que o documento fica
    completo. Sem prefixo de versão.
  - **Testes/validação:** linter de OpenAPI em container, e
    `docker compose run --rm api php artisan test --filter=OpenApiContract`.
  - **Depende de:** T020.
  - **Critério de conclusão:** documento válido, teste verde, mecanismo de
    crescimento estabelecido.

> **Checkpoint da Fase 2**
> **Passa a funcionar:** o domínio existe em PHP puro e responde pelas regras de
> estado, ordenação e erro seguro; o contrato HTTP e o OpenAPI base existem antes
> do primeiro endpoint.
> **Testes verdes:** identificadores, `VideoState`, `Position`, `FailureInfo`,
> contrato HTTP e contrato OpenAPI.
> **Comandos:** `docker compose run --rm api php artisan test`,
> `docker compose run --rm api ./vendor/bin/pint --test`,
> `docker compose run --rm api ./vendor/bin/phpstan analyse`.
> **Ainda não iniciado:** persistência, endpoints, autenticação, frontend.

---

## Fase 3 — Persistência e dados de avaliação

A ordem das migrations abaixo é executável e reversível. A referência entre aula
e tentativa de vídeo é circular por natureza, então é resolvida em dois passos:
`lessons` nasce sem a chave estrangeira da tentativa, e ela é acrescentada depois
que `video_attempts` existe.

- [ ] **T022** Migrations de infraestrutura
  - **Objetivo:** as tabelas que sessão, fila, cache e lock exigem.
  - **Arquivos previstos:** migrations de `sessions`, `jobs`, `failed_jobs`,
    `cache` e `cache_locks`.
  - **Requisitos:** ABERTO-001, ABERTO-003, ABERTO-004; plan §§7.1, 7.2, 8.2.
  - **Implementação:** esquema padrão do framework. O driver de cache é
    `database`, não `file`, porque o lock de conclusão precisa ser enxergado por
    `api`, `worker` e `simulator-worker` — containers distintos. **`job_batches`
    não é criada:** batching não é usado por decisão alguma do plano, e tabela de
    infraestrutura sem necessidade é peso morto.
  - **Testes/validação:**
    `docker compose run --rm api php artisan migrate` e
    `docker compose run --rm api php artisan migrate:rollback` — ambos sem erro.
  - **Depende de:** T021.
  - **Critério de conclusão:** cinco tabelas aplicadas e revertidas; nenhuma
    tabela extra.

- [ ] **T023** Migration de `users`
  - **Objetivo:** a primeira tabela de domínio, base de todas as demais.
  - **Arquivos previstos:** migration de `users`.
  - **Requisitos:** spec seção 4; plan §7.2.
  - **Implementação:** `id` em `CHAR(36)` com charset `ascii` e collation
    `ascii_bin`; `role` restrito a produtor ou consumidor; e-mail único.
  - **Testes/validação:** teste contra MySQL real provando a unicidade do e-mail.
    `docker compose run --rm api php artisan test --filter=UsersSchema`.
  - **Depende de:** T022.
  - **Critério de conclusão:** migration aplica, reverte, e a constraint é
    comprovada.

- [ ] **T024** Migrations de `courses`, `modules` e `lessons`
  - **Objetivo:** o catálogo persistido, com a ordem garantida pelo banco.
  - **Arquivos previstos:** migrations das três tabelas, nesta ordem.
  - **Requisitos:** RF-CUR-001; RN-ORD-003; RN-CUR-001; plan §7.2.
  - **Implementação:** `courses` referencia `users`; `modules` referencia
    `courses`; `lessons` referencia `modules`. **`lessons` nasce sem
    `current_video_attempt_id`** — essa coluna e sua chave estrangeira chegam em
    T025, depois de `video_attempts` existir. Estado do curso restrito a `draft` e
    `available`; unicidade de curso mais posição e de módulo mais posição;
    `published_at` anulável; índices para leitura ordenada e listagem por dono.
  - **Testes/validação:** teste contra MySQL real provando que duas posições
    iguais no mesmo pai são rejeitadas, e `migrate:rollback` desfazendo na ordem
    inversa.
    `docker compose run --rm api php artisan test --filter=CatalogSchema`.
  - **Depende de:** T023.
  - **Critério de conclusão:** três migrations aplicam e revertem; constraint de
    posição comprovada.

- [ ] **T025** Migration de `video_attempts` e fechamento da referência circular
  - **Objetivo:** o ciclo de vida do vídeo persistido, e a aula apontando para a
    tentativa atual.
  - **Arquivos previstos:** migration de `video_attempts` e migration de
    alteração de `lessons`.
  - **Requisitos:** RN-VID-002; RF-AUL-005; plan §7.2.
  - **Implementação:** `video_attempts` referencia `lessons`, com `storage_key`
    única e os campos declarados separados dos verificados. Em seguida, uma
    **segunda** migration acrescenta `current_video_attempt_id` a `lessons`, com
    chave estrangeira anulável e `ON DELETE SET NULL`. A reversão remove primeiro a
    coluna, depois a tabela.
  - **Testes/validação:** teste contra MySQL real inserindo aula, tentativa e o
    vínculo; `migrate:rollback` desfazendo os dois passos na ordem correta.
    `docker compose run --rm api php artisan test --filter=VideoAttemptSchema`.
  - **Depende de:** T024.
  - **Critério de conclusão:** ciclo aplicado e revertido sem violação de chave
    estrangeira.

- [ ] **T026** Migration de `course_access_grants`
  - **Objetivo:** o vínculo que autoriza um consumidor a um curso.
  - **Arquivos previstos:** migration correspondente.
  - **Requisitos:** RN-AUT-003; RF-CONS-006; plan §7.2.
  - **Implementação:** depende de `users` e `courses`, que já existem. Unicidade
    de curso mais consumidor.
  - **Testes/validação:** teste contra MySQL real provando que a segunda inserção
    do mesmo par é rejeitada.
    `docker compose run --rm api php artisan test --filter=AccessGrantSchema`.
  - **Depende de:** T025.
  - **Critério de conclusão:** constraint comprovada.

- [ ] **T027** Migration de `webhook_events`
  - **Objetivo:** o registro de idempotência do callback.
  - **Arquivos previstos:** migration correspondente.
  - **Requisitos:** RN-IDM-002, RN-IDM-003; RN-VID-006 a 008; plan §7.2.
  - **Implementação:** `event_id` único; **`received_video_id` sem chave
    estrangeira**, porque um evento válido pode apontar para tentativa
    inexistente e ainda assim precisa ser registrado; **`video_attempt_id`
    anulável com chave estrangeira** para `video_attempts`, preenchido só quando a
    tentativa é encontrada; `payload_fingerprint`; `outcome` anulável, para
    permitir a reserva.
  - **Testes/validação:** teste contra MySQL real aceitando evento cujo
    `received_video_id` não existe em `video_attempts`, e rejeitando `event_id`
    repetido.
    `docker compose run --rm api php artisan test --filter=WebhookEventSchema`.
  - **Depende de:** T026.
  - **Critério de conclusão:** os dois comportamentos comprovados.

- [ ] **T028** Seeds do cenário de avaliação
  - **Objetivo:** o ambiente sobe com tudo o que a demonstração e o teste
    integrado exigem.
  - **Arquivos previstos:** `backend/database/seeders/`,
    `docker/backend/setup.sh` atualizado.
  - **Requisitos:** RF-CONS-006; AC-E2E-001; RNF-019; spec seção 4.
  - **Implementação:** produtor e consumidor de demonstração com credenciais
    fictícias; um curso vazio do produtor, em `draft`; a concessão desse curso ao
    consumidor; um segundo produtor com curso próprio, para provar isolamento.

    **Os seeds escrevem pelo query builder do Laravel, diretamente nas tabelas.**
    Nesta altura da ordem não existem modelos Eloquent nem repositórios de
    `Course`, `Module`, `Lesson` ou `VideoAttempt` — eles chegam nas fases 5 e 6.
    Antecipar adapters aqui inverteria a ordem de dependência. Os seeds respeitam
    o schema de T023 a T027 e geram identificadores UUIDv7 conforme o plano §7.1.

    **O curso concedido ao consumidor permanece vazio**, sem módulo nem aula: é
    esse vazio que a jornada AC-E2E-001 preenche.

    O `setup` passa a executar os seeds.
  - **Testes/validação:**
    `docker compose run --rm api php artisan migrate:fresh --seed` e
    `docker compose run --rm api php artisan test --filter=EvaluationSeed`.
  - **Depende de:** T027.
  - **Critério de conclusão:** seed idempotente, executado pelo `setup`, e
    verificado por teste.

- [ ] **T029** Seed do cenário dedicado de falha
  - **Objetivo:** preparar o alvo da demonstração de falha **sem contaminar** o
    curso vazio da jornada principal.
  - **Arquivos previstos:** `backend/database/seeders/`, `docs/demonstracao.md`
    iniciado com o identificador.
  - **Requisitos:** RF-WHK-008; RF-PROC-006; AC-E2E-001; plan §13.3.
  - **Implementação:** um cenário **separado**, também pertencente ao produtor de
    demonstração: um curso próprio para a demonstração de falha, com módulo e aula
    próprios, e uma tentativa de vídeo em `processing`. O curso concedido ao
    consumidor em T028 **não é tocado** e continua vazio, porque AC-E2E-001 depende
    disso.

    O identificador da tentativa é este UUIDv7 **fixo, fictício e documentado**,
    não gerado a cada execução:

    ```
    01936f1a-7c00-7a3e-9b7d-2f5c8e4a1d60
    ```

    É ele que o comando de T068, a preparação do cenário de falha em T101 e o
    roteiro de T108 citam **literalmente**, e um valor aleatório tornaria a
    demonstração irreproduzível. É dado fictício de seed, não segredo: pode ser
    versionado e impresso à vontade. Escrito pelo query builder, como em T028.

    Nenhum arquivo real precisa existir no storage para este cenário.
  - **Testes/validação:**
    `docker compose run --rm api php artisan test --filter=FailureScenarioSeed`,
    afirmando o estado da tentativa, a estabilidade do identificador entre duas
    execuções do seed, e que o curso concedido ao consumidor continua **sem
    módulos e sem aulas**.
  - **Depende de:** T028.
  - **Critério de conclusão:** cenário de falha isolado, identificador estável, e
    curso da jornada principal ainda vazio.

> **Checkpoint da Fase 3**
> **Passa a funcionar:** o esquema completo aplica e reverte na ordem correta, o
> `setup` popula os dados de avaliação na subida, e as tabelas de fila e de lock
> existem — embora a fila ainda não esteja configurada.
> **Testes verdes:** unitários da Fase 2, mais os testes de esquema e de seed
> contra MySQL real.
> **Comandos:** `docker compose up setup`,
> `docker compose run --rm api php artisan migrate:fresh --seed`,
> `docker compose run --rm api php artisan test`.
> **Ainda não iniciado:** agregados, repositórios, endpoints, autenticação,
> upload, fila configurada, frontend.

---

## Fase 4 — Autenticação

- [ ] **T030** Sanctum em modo SPA, sessão no MySQL
  - **Objetivo:** autenticação por cookie funcionando entre Nuxt e Laravel.
  - **Arquivos previstos:** `backend/config/sanctum.php`,
    `backend/config/session.php`, `backend/config/cors.php`, `.env.example`.
  - **Requisitos:** ABERTO-001; RF-AUT-001, RF-AUT-006; plan §9.
  - **Implementação:** driver de sessão em banco; cookie `HttpOnly`,
    `SameSite=Lax`, `Secure` sob HTTPS. Três configurações distintas, não uma
    lista repetida: `SANCTUM_STATEFUL_DOMAINS` com hosts e porta,
    `SESSION_DOMAIN` com o domínio do cookie, e `allowed_origins` do CORS com
    origens completas. `supports_credentials` verdadeiro, nunca curinga.
  - **Testes/validação:**
    `docker compose run --rm api php artisan test --filter=SanctumSession`.
  - **Depende de:** T029.
  - **Critério de conclusão:** sessão persistida no MySQL e teste verde.

- [ ] **T031** Endpoints de autenticação
  - **Objetivo:** login, logout e usuário atual, já no formato do contrato.
  - **Arquivos previstos:** rotas, controller e requests em
    `Identity/Interfaces/Http/`; trecho correspondente em `docs/openapi.yaml`.
  - **Requisitos:** RF-AUT-001; RF-UI-014; plan §10.4.
  - **Implementação:** `POST /api/auth/login`, `POST /api/auth/logout`,
    `GET /api/auth/me`, usando o envelope e o `problem+json` estabelecidos em
    T020. Credencial inválida não revela se o e-mail existe. Atualizar o OpenAPI e
    estender o teste de contrato.
  - **Testes/validação:**
    `docker compose run --rm api php artisan test --filter=Auth`,
    `docker compose run --rm api php artisan test --filter=OpenApiContract`.
  - **Depende de:** T030.
  - **Critério de conclusão:** três endpoints cobertos, contrato atualizado.

- [ ] **T032** Autorização por perfil na fronteira HTTP
  - **Objetivo:** rotas de gestão exigindo produtor e rotas de consumo exigindo
    consumidor.
  - **Arquivos previstos:** middleware de perfil, registro em rotas.
  - **Requisitos:** RN-AUT-001, RN-AUT-002; plan §9.3.
  - **Implementação:** falha de perfil resulta em `403`. A checagem de perfil não
    substitui a de propriedade, que vive no caso de uso e chega em T035.
  - **Testes/validação:**
    `docker compose run --rm api php artisan test --filter=RoleAuthorization`.
  - **Depende de:** T031.
  - **Critério de conclusão:** os dois sentidos cobertos e verdes.

- [ ] **T033** Distinção entre não autenticado e sem permissão
  - **Objetivo:** o frontend consegue diferenciar reautenticar de não pode.
  - **Arquivos previstos:** handler de exceções em `Shared/Interfaces/Http/`.
  - **Requisitos:** RF-AUT-005; RF-UI-013, RF-UI-014; AC-UI-003; plan §10.3.
  - **Implementação:** ausência ou expiração de sessão produz `401`; perfil sem
    permissão produz `403`. Cada um com código funcional estável. O `404` de
    recurso alheio é acrescentado em T035, quando existir recurso para ocultar.
  - **Testes/validação:**
    `docker compose run --rm api php artisan test --filter=AuthErrorMapping`.
  - **Depende de:** T032.
  - **Critério de conclusão:** `401` e `403` distinguíveis por status e código.

> **Checkpoint da Fase 4**
> **Passa a funcionar:** login e logout pela API com sessão em cookie, rotas
> separadas por perfil, e `401` distinto de `403`.
> **Testes verdes:** sessão, endpoints de autenticação, perfil e mapeamento de
> erro; contrato OpenAPI atualizado.
> **Comandos:** `docker compose run --rm api php artisan test --testsuite=Feature`.
> **Ainda não iniciado:** catálogo, propriedade, upload, fila, webhook, frontend.

---

## Fase 5 — Catálogo do produtor

Cada agregado chega junto do seu repositório e dos seus endpoints — decomposição
vertical. Nenhum adapter é criado antes do agregado que ele persiste.

- [ ] **T034** Agregado `Course`, repositório e criação
  - **Objetivo:** produtor cria curso, que nasce `draft` e com ele como dono.
  - **Arquivos previstos:** `Catalog/Domain/Course.php`,
    `Catalog/Application/Port/CourseRepository.php`,
    `Catalog/Infrastructure/Persistence/Eloquent/`,
    `Catalog/Application/CreateCourse/`, controller, request, resource, trecho do
    OpenAPI.
  - **Requisitos:** RF-CUR-001; RN-PROP-001; RN-CUR-001; AC-PROD-001;
    plan §§5.3, 6.1.
  - **Implementação:** `POST /api/courses` com título e descrição. Estado inicial
    `draft`, dono vindo da sessão — nunca do corpo. O repositório expõe apenas o
    que este e os próximos casos de uso precisam, incluindo leitura travada para
    deslocamento. Sem repositório genérico.
  - **Testes/validação:** unitário do agregado, feature do endpoint com `422` em
    entrada inválida, e integração do repositório contra MySQL real.
    `docker compose run --rm api php artisan test --filter=Course`.
  - **Depende de:** T033.
  - **Critério de conclusão:** parte de AC-PROD-001 coberta e verde.

- [ ] **T035** Propriedade e ocultação de existência
  - **Objetivo:** produtor não alcança recurso de outro produtor, e não descobre
    se ele existe.
  - **Arquivos previstos:** serviço de autorização em `Catalog/Application/`,
    resolução de recurso nas rotas, handler de exceções ajustado.
  - **Requisitos:** RN-PROP-002, RN-PROP-003, RN-PROP-005; RN-AUT-006;
    AC-PROD-002, AC-PROD-007; plan §9.3.
  - **Implementação:** filtro por dono **na consulta SQL**, não após carregar.
    Recurso de terceiro e recurso inexistente produzem a mesma resposta `404`,
    com o mesmo corpo.
  - **Testes/validação:** feature comparando a resposta para identificador de
    outro produtor e para identificador inexistente — indistinguíveis.
    `docker compose run --rm api php artisan test --filter=Ownership`.
  - **Depende de:** T034.
  - **Critério de conclusão:** AC-PROD-002 e AC-PROD-007 verdes.

- [ ] **T036** Listagem e detalhe de cursos
  - **Objetivo:** produtor enxerga apenas os próprios cursos, com os campos que a
    spec exige.
  - **Arquivos previstos:** casos de uso de listagem e consulta, controller,
    resource, trecho do OpenAPI.
  - **Requisitos:** RF-CUR-002, RF-CUR-003, RF-CUR-004; AC-PROD-001, AC-PROD-002.
  - **Implementação:** `GET /api/courses` paginado com o formato de T020, e
    `GET /api/courses/{course}` trazendo identificador, título, descrição,
    proprietário, estado e data de criação.
  - **Testes/validação:** feature confirmando o isolamento na lista e no detalhe,
    o padrão de 15 e o teto de 50.
    `docker compose run --rm api php artisan test --filter=CourseListing`.
  - **Depende de:** T035.
  - **Critério de conclusão:** AC-PROD-001 completo e verde.

- [ ] **T037** Agregado `Module`, repositório e criação com posição
  - **Objetivo:** módulos criados na ordem correta, com deslocamento.
  - **Arquivos previstos:** `Catalog/Domain/Module.php`,
    `Catalog/Application/Port/ModuleRepository.php`, adapter,
    `Catalog/Application/CreateModule/`, controller, request, trecho do OpenAPI.
  - **Requisitos:** RF-MOD-001 a 004, RF-MOD-006, RF-MOD-007; RN-ORD-002,
    RN-ORD-003; AC-PROD-003; plan §§6.1, 8.1.
  - **Implementação:** `POST /api/courses/{course}/modules`. Posição opcional;
    omitida, vai ao fim. O caso de uso **trava a linha do curso** e desloca em
    ordem decrescente antes de inserir.
  - **Testes/validação:** feature para criação ao fim, inserção no meio com
    deslocamento, e módulo em curso alheio respondendo `404`.
    `docker compose run --rm api php artisan test --filter=Module`.
  - **Depende de:** T036.
  - **Critério de conclusão:** deslocamento comprovado por teste.

- [ ] **T038** Agregado `Lesson`, repositório e criação com posição
  - **Objetivo:** o mesmo comportamento de ordem, dentro do módulo.
  - **Arquivos previstos:** `Catalog/Domain/Lesson.php`,
    `Catalog/Application/Port/LessonRepository.php`, adapter,
    `Catalog/Application/CreateLesson/`, controller, request, trecho do OpenAPI.
  - **Requisitos:** RF-AUL-001 a 004, RF-AUL-008, RF-AUL-009; RN-ORD-002,
    RN-ORD-003; AC-PROD-003.
  - **Implementação:** `POST /api/modules/{module}/lessons`. A aula nasce
    rascunho, sem vídeo. O caso de uso trava a linha do módulo.
  - **Testes/validação:** feature equivalente à de módulos, mais a confirmação de
    que a aula nasce sem `published_at`.
    `docker compose run --rm api php artisan test --filter=Lesson`.
  - **Depende de:** T037.
  - **Critério de conclusão:** AC-PROD-003 coberto para módulos e aulas.

- [ ] **T039** Teste de concorrência do deslocamento
  - **Objetivo:** duas criações simultâneas na mesma posição não produzem
    duplicata nem ordem ambígua.
  - **Arquivos previstos:**
    `backend/tests/Feature/Catalog/PositionConcurrencyTest.php`.
  - **Requisitos:** RN-ORD-003, RN-ORD-004; RNF-003; plan §8.1.
  - **Implementação:** duas transações concorrentes contra o MySQL real pedindo a
    mesma posição; o lock da linha do pai precisa serializar.
  - **Testes/validação:**
    `docker compose run --rm api php artisan test --filter=PositionConcurrency`.
  - **Depende de:** T038.
  - **Critério de conclusão:** teste verde, sem posição duplicada.

- [ ] **T040** Consulta e listagem de aulas e módulos
  - **Objetivo:** produtor consulta e lista o conteúdo próprio, na ordem.
  - **Arquivos previstos:** casos de uso, endpoints, trechos do OpenAPI.
  - **Requisitos:** RF-AUL-003; RF-MOD-003; RN-ORD-001.
  - **Implementação:** `GET /api/lessons/{lesson}` e
    `GET /api/courses/{course}/modules`, sempre ordenados por posição.
  - **Testes/validação:**
    `docker compose run --rm api php artisan test --filter=CatalogRead`.
  - **Depende de:** T039.
  - **Critério de conclusão:** ordem e isolamento verdes.

- [ ] **T041** Estrutura completa do curso para o produtor
  - **Objetivo:** uma operação devolve curso, módulos e aulas organizados.
  - **Arquivos previstos:** `Catalog/Application/GetCourseStructure/`, resource,
    trecho do OpenAPI.
  - **Requisitos:** RF-EST-001 a 004; AC-PROD-003; plan §10.4.
  - **Implementação:** `GET /api/courses/{course}/structure`. A visão do produtor
    inclui rascunhos e o estado do vídeo de cada aula. Não paginar: a árvore
    perderia a ordem que a spec exige preservar.
  - **Testes/validação:**
    `docker compose run --rm api php artisan test --filter=CourseStructure`.
  - **Depende de:** T040.
  - **Critério de conclusão:** RF-EST coberto e verde.

> **Checkpoint da Fase 5**
> **Passa a funcionar:** o produtor cria e consulta cursos, módulos e aulas, com
> ordem preservada sob concorrência, isolamento entre produtores e estrutura
> completa. Publicação ainda **não existe** como operação — ela chega em T053,
> depois de o agregado de vídeo existir.
> **Testes verdes:** agregados, repositórios, endpoints de catálogo, ocultação de
> existência e concorrência de posição; OpenAPI atualizado a cada endpoint.
> **Comandos:** `docker compose run --rm api php artisan test`,
> `docker compose run --rm api ./vendor/bin/phpstan analyse`.
> **Ainda não iniciado:** vídeo, upload, publicação, fila, webhook, frontend.

---

## Fase 6 — Upload multipart

- [ ] **T042** Porta `ObjectStorage` e adapter do RustFS
  - **Objetivo:** isolar o storage atrás de um contrato do problema, não da AWS.
  - **Arquivos previstos:** `Video/Application/Port/ObjectStorage.php`,
    `Video/Infrastructure/Storage/`, configuração de endereço interno e público.
  - **Requisitos:** ABERTO-002; RF-PROC-003; plan §5.3.
  - **Implementação:** a porta fala em criar envio, emitir URL de parte, concluir,
    inspecionar e emitir URL de leitura. O adapter traduz para o SDK. Endereço
    interno para as chamadas do backend, endereço público para as URLs entregues
    ao navegador — assinar com o host errado gera URL que a API aceita e o
    navegador não alcança.
  - **Testes/validação:** integração contra o RustFS do Compose, exercitando cada
    operação — é o teste que substitui os scripts de validação removidos em T003.
    `docker compose run --rm api php artisan test --filter=ObjectStorage`.
  - **Depende de:** T041.
  - **Critério de conclusão:** integração verde contra storage real.

- [ ] **T043** Agregado `VideoAttempt` e repositório
  - **Objetivo:** o ciclo de vida do vídeo como agregado próprio, persistido.
  - **Arquivos previstos:** `Video/Domain/VideoAttempt.php`,
    `Video/Application/Port/VideoAttemptRepository.php`, adapter Eloquent.
  - **Requisitos:** RN-VID-001, RN-VID-002; RF-AUL-005; plan §6.1.
  - **Implementação:** o agregado usa `VideoState` de T017 para validar transição.
    O repositório expõe busca por identificador, por aula, leitura travada e
    gravação. Um repositório por raiz.
  - **Testes/validação:** unitário do agregado e integração do repositório contra
    MySQL real.
    `docker compose run --rm api php artisan test --filter=VideoAttempt`.
  - **Depende de:** T042.
  - **Critério de conclusão:** agregado e repositório verdes.

- [ ] **T044** Abertura do envio
  - **Objetivo:** produtor inicia o envio e recebe o plano de partes.
  - **Arquivos previstos:** `Video/Application/OpenUpload/`, controller, request,
    trecho do OpenAPI.
  - **Requisitos:** RF-UPL-001 a 004, RF-UPL-006, RF-UPL-011, RF-UPL-012;
    AC-VID-010, AC-VID-011; plan §11.3.
  - **Implementação:** `POST /api/lessons/{lesson}/video/uploads` com nome, tipo e
    tamanho. Autoriza o dono da aula; valida tipo e tamanho contra limites
    configurados **no backend**, nunca informados pelo cliente; rejeita se houver
    tentativa ativa; deriva a `storage_key` do identificador da tentativa; grava
    metadados controlados com esse identificador; cria o envio multipart e
    persiste a tentativa em `pending`.
  - **Testes/validação:** feature para aula de outro produtor com `404`, tipo e
    tamanho inválidos com `422`, e tentativa ativa com `409`.
    `docker compose run --rm api php artisan test --filter=OpenUpload`.
  - **Depende de:** T043.
  - **Critério de conclusão:** AC-VID-010 verde e o bloqueio de AC-VID-011
    coberto.

- [ ] **T045** URL de parte e transição para `uploading`
  - **Objetivo:** emitir URLs temporárias e marcar o início real do envio.
  - **Arquivos previstos:** `Video/Application/IssuePartUrl/`, controller, trecho
    do OpenAPI.
  - **Requisitos:** RF-UPL-003; RN-VID-005; AC-VID-012; plan §11.3.
  - **Implementação:** `POST /api/video-uploads/{attempt}/parts/{n}/url`, validade
    de 15 minutos, renovação pela mesma rota. O **primeiro** pedido move de
    `pending` para `uploading`; os seguintes não mudam estado. Sem endpoint
    adicional de início.
  - **Testes/validação:**
    `docker compose run --rm api php artisan test --filter=IssuePartUrl`.
  - **Depende de:** T044.
  - **Critério de conclusão:** transição comprovada por teste.

- [ ] **T046** Porta de lock atômico por tentativa
  - **Objetivo:** uma trava compartilhada entre containers.
  - **Arquivos previstos:** `Video/Application/Port/AttemptLock.php`,
    `Video/Infrastructure/Lock/`.
  - **Requisitos:** RNF-003; plan §8.2.
  - **Implementação:** chave `video-upload-complete:{videoAttemptId}`, lease maior
    que o timeout do cliente S3, liberação em `finally`. Driver de cache
    `database` sobre as tabelas de T022 — um lock em arquivo não é visto pelos
    outros containers.
  - **Testes/validação:**
    `docker compose run --rm api php artisan test --filter=AttemptLock`.
  - **Depende de:** T045.
  - **Critério de conclusão:** exclusão mútua comprovada.

- [ ] **T047** Conclusão do envio com verificação no servidor
  - **Objetivo:** concluir o multipart, conferir o objeto e só então mudar o
    estado.
  - **Arquivos previstos:** `Video/Application/CompleteUpload/`, controller,
    trecho do OpenAPI.
  - **Requisitos:** RF-UPL-007 a 010, RF-UPL-013; RN-IDM-001; AC-VID-002;
    plan §12.2.
  - **Implementação:** dentro do lock, a sequência do plano — transação curta que
    valida, commit, `CompleteMultipartUpload`, `HeadObject`, e nova transação que
    reavalia e transiciona. **Nenhuma transação MySQL aberta enquanto o storage
    responde.** Verificar chave esperada, tamanho declarado, `Content-Type`
    aceito e metadados com o identificador da tentativa. Falha leva a `failed`.
    O `ETag` **não** é usado como checksum.
  - **Testes/validação:** feature para objeto ausente, tamanho divergente, tipo
    divergente e metadados ausentes — todos em `failed` com mensagem segura.
    `docker compose run --rm api php artisan test --filter=CompleteUpload`.
  - **Depende de:** T046.
  - **Critério de conclusão:** AC-VID-002 verde nas quatro variações.

- [ ] **T048** Conclusão repetida e resultado ambíguo
  - **Objetivo:** repetição sem trabalho duplicado, e falha transitória sem
    destruir o envio.
  - **Arquivos previstos:** mesmo caso de uso de T047.
  - **Requisitos:** RN-IDM-001; RF-ERR-003; AC-VID-003; plan §12.4.
  - **Implementação:** tentativa fora de `uploading` devolve o desfecho já obtido.
    Diante de `NoSuchUpload` ou resposta ambígua, o `HeadObject` decide: objeto
    válido reconcilia como conclusão anterior bem-sucedida; ausência definitiva
    leva a `failed`; indisponibilidade transitória **preserva `uploading`** e
    responde erro temporário.
  - **Testes/validação:**
    `docker compose run --rm api php artisan test --filter=CompleteIdempotency`.
  - **Depende de:** T047.
  - **Critério de conclusão:** AC-VID-003 verde e os três desfechos cobertos.

- [ ] **T049** Teste de concorrência da conclusão
  - **Objetivo:** duas conclusões simultâneas não duplicam nada.
  - **Arquivos previstos:**
    `backend/tests/Feature/Video/CompleteConcurrencyTest.php`.
  - **Requisitos:** RN-IDM-001; RNF-003; plan §8.2.
  - **Implementação:** duas conclusões da mesma tentativa em paralelo contra o
    storage real. Uma transiciona; a outra recebe o desfecho.
  - **Testes/validação:**
    `docker compose run --rm api php artisan test --filter=CompleteConcurrency`.
  - **Depende de:** T048.
  - **Critério de conclusão:** teste verde, um único efeito.

- [ ] **T050** Consulta do estado da tentativa
  - **Objetivo:** o produtor acompanha o vídeo pela API.
  - **Arquivos previstos:** `Video/Application/GetLessonVideo/`, controller,
    resource, trecho do OpenAPI.
  - **Requisitos:** RF-VID-002; RF-UI-005 a 007; plan §10.4.
  - **Implementação:** `GET /api/lessons/{lesson}/video` devolve o estado e, quando
    houver, a informação pública de falha.
  - **Testes/validação:**
    `docker compose run --rm api php artisan test --filter=LessonVideo`.
  - **Depende de:** T049.
  - **Critério de conclusão:** todos os estados representados e verdes.

- [ ] **T051** Substituição de tentativa após `failed`
  - **Objetivo:** um envio novo sucede uma tentativa que falhou, e só então.
  - **Arquivos previstos:** ajuste no caso de uso de abertura.
  - **Requisitos:** RF-UPL-005, RF-UPL-011; RN-VID-002; RF-ERR-005; AC-VID-011.
  - **Implementação:** abertura sobre tentativa em `failed` cria nova tentativa em
    `pending` e aponta a aula para ela. Sobre tentativa ativa, `409`.
  - **Testes/validação:**
    `docker compose run --rm api php artisan test --filter=ReplaceAttempt`.
  - **Depende de:** T050.
  - **Critério de conclusão:** AC-VID-011 completo e verde.

- [ ] **T052** Teste do envio interrompido
  - **Objetivo:** transferência sem conclusão nunca vira concluída.
  - **Arquivos previstos:**
    `backend/tests/Feature/Video/InterruptedUploadTest.php`.
  - **Requisitos:** RF-ERR-001; RN-VID-005; AC-VID-012.
  - **Implementação:** abrir envio, pedir a URL da primeira parte, não concluir.
    A tentativa permanece em `uploading` e nunca alcança `uploaded` ou `ready`.
  - **Testes/validação:**
    `docker compose run --rm api php artisan test --filter=InterruptedUpload`.
  - **Depende de:** T051.
  - **Critério de conclusão:** AC-VID-012 verde.

> **Checkpoint da Fase 6**
> **Passa a funcionar:** o produtor abre um envio, recebe URLs de parte, envia
> direto ao storage e conclui; o servidor verifica o objeto antes de transicionar,
> e a conclusão é serializada e idempotente.
> **Testes verdes:** integração do adapter, abertura, URL de parte, conclusão,
> repetição, ambiguidade, substituição, interrupção e concorrência.
> **Comandos:** `docker compose up -d`,
> `docker compose run --rm api php artisan test`.
> **Ainda não iniciado:** publicação, fila configurada, webhook, simulador,
> reprodução, frontend.

---

## Fase 7 — Publicação

- [ ] **T053** Caso de uso de publicação de aula
  - **Objetivo:** publicar quando as condições são satisfeitas, de forma
    idempotente, coordenando três agregados.
  - **Arquivos previstos:** `Catalog/Application/PublishLesson/`, controller,
    trecho do OpenAPI.
  - **Requisitos:** RF-PUB-001 a 003; RN-PUB-001 a 006; RN-IDM-004; AC-PROD-004,
    AC-PROD-005, AC-PROD-006; plan §§8.3, 14.1.
  - **Implementação:** `POST /api/lessons/{lesson}/publish`. Numa transação, trava
    a aula e a tentativa atual e o domínio decide. Sem vídeo `ready` com
    referência presente, `409` com código que identifica a condição. Publicar de
    novo devolve `200` sem efeito. Primeira publicação promove o curso a
    `available`, na mesma transação. Esta é a **única** tarefa de publicação — não
    há segunda parte depois.
  - **Testes/validação:** feature cobrindo **todos** os estados não elegíveis
    (`pending`, `uploading`, `uploaded`, `processing`, `failed`) com `409`; a
    aceitação em `ready` com referência, montada pelo teste que grava a tentativa
    nesse estado pelo repositório; a republicação sem efeito; e a promoção do
    curso.
    `docker compose run --rm api php artisan test --filter=PublishLesson`.
  - **Depende de:** T052.
  - **Critério de conclusão:** AC-PROD-004, AC-PROD-005 e AC-PROD-006 verdes.

- [ ] **T054** Teste de concorrência da publicação
  - **Objetivo:** duas publicações simultâneas não promovem o curso duas vezes.
  - **Arquivos previstos:**
    `backend/tests/Feature/Catalog/PublishConcurrencyTest.php`.
  - **Requisitos:** RN-IDM-004; RNF-003; plan §8.3.
  - **Implementação:** duas transações concorrentes publicando a mesma aula.
  - **Testes/validação:**
    `docker compose run --rm api php artisan test --filter=PublishConcurrency`.
  - **Depende de:** T053.
  - **Critério de conclusão:** teste verde, curso promovido uma única vez.

> **Checkpoint da Fase 7**
> **Passa a funcionar:** publicação completa — rejeitada em todos os estados não
> elegíveis, aceita em `ready` com referência, idempotente, e promovendo o curso a
> `available` na primeira vez.
> **Testes verdes:** publicação em todos os estados e concorrência.
> **Comandos:** `docker compose run --rm api php artisan test --filter=Publish`.
> **Ainda não iniciado:** fila configurada, webhook, simulador, reprodução,
> frontend.

---

## Fase 8 — Fila e processamento

- [ ] **T055** Configuração da fila em banco
  - **Objetivo:** trabalho assíncrono persistido no MySQL, com tentativas e
    registro de falha.
  - **Arquivos previstos:** `backend/config/queue.php`, comandos de `worker` e
    `simulator-worker` no `docker-compose.yml`.
  - **Requisitos:** ABERTO-004; RF-PROC-002; RNF-002; plan §13.1.
  - **Implementação:** driver `database` sobre as tabelas de T022; filas `default`
    e `simulator`; três tentativas; backoff de 10, 30 e 60 segundos; timeout de 60
    segundos; falha final em `failed_jobs`. Sem Redis, sem Horizon. É aqui que os
    dois workers definidos em T012 passam a ter função.
  - **Testes/validação:**
    `docker compose run --rm api php artisan test --filter=QueueConfiguration` e
    `docker compose logs worker` mostrando consumo.
  - **Depende de:** T054.
  - **Critério de conclusão:** as duas filas consumidas pelos workers corretos.

- [ ] **T056** Despacho após o commit
  - **Objetivo:** nenhum worker enxerga estado que ainda não foi confirmado.
  - **Arquivos previstos:** ajuste no caso de uso de conclusão de envio.
  - **Requisitos:** RNF-003; plan §7.4.
  - **Implementação:** o job de processamento é despachado depois do commit da
    transação que transiciona para `uploaded`, nunca dentro dela.
  - **Testes/validação:**
    `docker compose run --rm api php artisan test --filter=DispatchAfterCommit`.
  - **Depende de:** T055.
  - **Critério de conclusão:** rollback não deixa job enfileirado.

- [ ] **T057** `ProcessVideoJob` retomável
  - **Objetivo:** o job decide pelo estado travado e sobrevive a ser repetido.
  - **Arquivos previstos:** `Video/Infrastructure/Queue/ProcessVideoJob.php`,
    `Video/Application/StartProcessing/`.
  - **Requisitos:** RF-PROC-001, RF-PROC-002; RN-VID-001; plan §13.2.
  - **Implementação:** recebe **apenas** o `videoAttemptId` e recarrega o estado.
    Em `uploaded`, transiciona para `processing` e enfileira a entrega na fila
    `simulator`; em `processing`, trata como retomada e também enfileira; em
    `ready` ou `failed`, encerra sem efeito; em `pending` ou `uploading`, não
    inicia. O enfileiramento ocorre após o commit. O consumidor dessa fila só
    existe em T067 — nesta tarefa o teste afirma que a mensagem foi enfileirada.
  - **Testes/validação:**
    `docker compose run --rm api php artisan test --filter=ProcessVideoJob`,
    cobrindo os cinco estados de entrada.
  - **Depende de:** T056.
  - **Critério de conclusão:** os cinco caminhos cobertos e verdes.

- [ ] **T058** `event_id` estável por tentativa e cenário
  - **Objetivo:** entrega duplicada não vira evento novo.
  - **Arquivos previstos:** `Video/Domain/ProcessingEventId.php`,
    `backend/tests/Unit/Video/ProcessingEventIdTest.php`.
  - **Requisitos:** RN-IDM-002; plan §§13.2, 13.3.
  - **Implementação:** derivar de forma determinística do identificador da
    tentativa e do cenário — sucesso ou falha. Nunca aleatório. Cenários
    diferentes da mesma tentativa produzem identificadores diferentes.
  - **Testes/validação:**
    `docker compose run --rm api php artisan test --filter=ProcessingEventId`.
  - **Depende de:** T057.
  - **Critério de conclusão:** estabilidade e distinção comprovadas.

> **Checkpoint da Fase 8**
> **Passa a funcionar:** concluir um envio enfileira o processamento; o job
> transiciona para `processing` e coloca a entrega na fila do simulador, de forma
> retomável e com identificador de evento estável.
> **Testes verdes:** fila, despacho pós-commit, os cinco caminhos do job e a
> estabilidade do `event_id`.
> **Comandos:** `docker compose up -d`,
> `docker compose run --rm api php artisan test`,
> `docker compose logs worker`.
> **Ainda não iniciado:** o endpoint de callback, o simulador que o chama, o
> comando de falha, reprodução e frontend. **Nada consome a fila `simulator`
> ainda** — isso é esperado e chega em T067.

---

## Fase 9 — Webhook

O endpoint que o simulador vai chamar precisa existir **antes** do simulador.
Esta fase inteira roda com o callback sendo exercido diretamente pelos testes.

- [ ] **T059** Endpoint de callback e validação estrutural
  - **Objetivo:** a rota oficial existindo e rejeitando carga malformada antes de
    qualquer escrita.
  - **Arquivos previstos:** rota `POST /api/webhooks/video-processing`,
    controller e request em `Video/Interfaces/Http/`, trecho do OpenAPI.
  - **Requisitos:** RF-WHK-002; plan §13.4.
  - **Implementação:** a rota fica fora de sessão e de CSRF — o emissor é um
    serviço, não um navegador. Validação estrutural primeiro: campos presentes,
    `status` entre os aceitos, `video_id` sintaticamente UUID. Carga inválida
    responde `422` **sem criar reserva**.
  - **Testes/validação:**
    `docker compose run --rm api php artisan test --filter=WebhookValidation`.
  - **Depende de:** T058.
  - **Critério de conclusão:** validação estrutural verde e sem efeito colateral.

- [ ] **T060** Verificação de assinatura HMAC
  - **Objetivo:** só quem tem o segredo consegue mudar estado por esta rota.
  - **Arquivos previstos:** middleware de assinatura em
    `Video/Infrastructure/Webhook/`.
  - **Requisitos:** ABERTO-006; RF-WHK-001; AC-VID-009; plan §13.4.
  - **Implementação:** headers `X-Webhook-Timestamp` e `X-Webhook-Signature` no
    formato `v1=<hex>`. String assinada é `timestamp + "." + corpo bruto`. A
    verificação usa o **corpo bruto**, antes de qualquer desserialização.
    Comparação com `hash_equals`. Janela de cinco minutos. Falha responde `401`
    sem efeito.
  - **Testes/validação:**
    `docker compose run --rm api php artisan test --filter=WebhookSignature`,
    cobrindo assinatura ausente, inválida, corpo alterado e timestamp fora da
    janela.
  - **Depende de:** T059.
  - **Critério de conclusão:** AC-VID-009 verde nos quatro casos.

- [ ] **T061** Fingerprint semântica do payload
  - **Objetivo:** comparar significado, não formatação, ao decidir repetição.
  - **Arquivos previstos:** `Video/Domain/PayloadFingerprint.php`,
    `backend/tests/Unit/Video/PayloadFingerprintTest.php`.
  - **Requisitos:** RN-IDM-002, RN-IDM-003; plan §§7.2, 13.4.
  - **Implementação:** normalização determinística de `video_id`, `status` e
    `playback_reference`, seguida de SHA-256. `event_id` fica fora — é a chave,
    não conteúdo. Não é o hash do corpo bruto: o corpo bruto serve só ao HMAC.
  - **Testes/validação:**
    `docker compose run --rm api php artisan test --filter=PayloadFingerprint`,
    provando que dois JSONs com mesma semântica e formatação diferente produzem a
    mesma fingerprint, e que trocar `status` a altera.
  - **Depende de:** T060.
  - **Critério de conclusão:** testes verdes.

- [ ] **T062** `WebhookEventStore`, reserva e os cinco casos
  - **Objetivo:** o algoritmo de idempotência do plano, implementado.
  - **Arquivos previstos:** `Video/Application/Port/WebhookEventStore.php`,
    adapter Eloquent, `Video/Application/HandleProcessingCallback/`.
  - **Requisitos:** RN-IDM-002, RN-IDM-003; RN-VID-004, RN-VID-006 a 008;
    RF-WHK-003 a 006; AC-VID-004, AC-VID-005, AC-VID-006, AC-VID-013;
    plan §8.4.
  - **Implementação:** tentar inserir a reserva com `outcome` nulo dentro da
    transação. Colisão na unicidade leva à leitura do desfecho registrado —
    fingerprint igual repete o desfecho (A1), divergente é conflito (A2).
    Inserção bem-sucedida resolve a tentativa e avalia: transição válida aplica e
    grava `accepted` (B); obsoleto ou incompatível preserva o estado e grava
    `rejected_permanent` (C); prematuro faz **rollback**, liberando o `event_id`
    (D). Nenhuma linha commitada sem `outcome`.
  - **Testes/validação:**
    `docker compose run --rm api php artisan test --filter=WebhookIdempotency`,
    incluindo prematuro seguido de reentrega bem-sucedida depois de a tentativa
    alcançar `processing`.
  - **Depende de:** T061.
  - **Critério de conclusão:** AC-VID-004, 005, 006 e 013 verdes.

- [ ] **T063** Callback para vídeo inexistente
  - **Objetivo:** evento bem formado que aponta para nada recebe desfecho
    definitivo.
  - **Arquivos previstos:** mesmo caso de uso de T062.
  - **Requisitos:** RN-VID-006; plan §§7.2, 13.4.
  - **Implementação:** `received_video_id` guarda o identificador recebido, sem
    chave estrangeira; `video_attempt_id` fica nulo quando a tentativa não existe.
    O evento é registrado como rejeição permanente e responde `409`. A reentrega
    do mesmo `event_id` repete o `409`.
  - **Testes/validação:**
    `docker compose run --rm api php artisan test --filter=WebhookUnknownVideo`,
    contrastando UUID válido inexistente (`409` com linha registrada) e UUID
    inválido (`422` sem reserva).
  - **Depende de:** T062.
  - **Critério de conclusão:** os dois casos distinguíveis e verdes.

- [ ] **T064** Códigos de resposta do callback
  - **Objetivo:** o emissor sabe se deve parar ou tentar de novo.
  - **Arquivos previstos:** controller e handler de erros do webhook, trecho do
    OpenAPI.
  - **Requisitos:** RF-WHK-009; plan §§10.3, 13.4.
  - **Implementação:** `200` para aceito e para repetição de aceito; `409` para
    conflito e rejeição permanente; `503` com `Retry-After` para prematuro; `401`
    para assinatura inválida; `422` para carga inválida. **Sem `202`** — o
    callback é aplicado sincronamente.
  - **Testes/validação:**
    `docker compose run --rm api php artisan test --filter=WebhookStatus`.
  - **Depende de:** T063.
  - **Critério de conclusão:** nenhum `202` nesta rota e os cinco status corretos.

- [ ] **T065** Efeitos de sucesso e de falha
  - **Objetivo:** o callback produz o estado e a informação corretos.
  - **Arquivos previstos:** mesmo caso de uso de T062.
  - **Requisitos:** RF-WHK-006, RF-WHK-007, RF-WHK-010, RF-WHK-011; AC-VID-007;
    plan §13.4.
  - **Implementação:** `ready` exige `playback_reference` presente e grava a
    referência; `failed` exige `playback_reference` nulo e grava
    `VIDEO_PROCESSING_FAILED` com mensagem pública genérica, **derivada
    internamente**. A carga oficial não ganha campo novo.
  - **Testes/validação:**
    `docker compose run --rm api php artisan test --filter=WebhookEffects`,
    confirmando também que aula com vídeo em `failed` continua não publicável.
  - **Depende de:** T064.
  - **Critério de conclusão:** AC-VID-007 verde.

- [ ] **T066** Teste de concorrência do callback
  - **Objetivo:** duas entregas simultâneas do mesmo evento não produzem efeito
    duplo.
  - **Arquivos previstos:**
    `backend/tests/Feature/Video/WebhookConcurrencyTest.php`.
  - **Requisitos:** RN-IDM-002; RNF-003; plan §8.4.
  - **Implementação:** disparar o mesmo `event_id` em paralelo. A segunda espera a
    primeira e lê o desfecho, ou avalia do zero se houve rollback.
  - **Testes/validação:**
    `docker compose run --rm api php artisan test --filter=WebhookConcurrency`.
  - **Depende de:** T065.
  - **Critério de conclusão:** teste verde, um único efeito aplicado.

> **Checkpoint da Fase 9**
> **Passa a funcionar:** o endpoint de callback existe, verifica origem, é
> idempotente por evento, distingue os cinco casos e produz os efeitos corretos —
> exercido pelos testes, ainda sem emissor real.
> **Testes verdes:** validação estrutural, HMAC, fingerprint, reserva e casos,
> vídeo inexistente, códigos, efeitos e concorrência.
> **Comandos:** `docker compose run --rm api php artisan test --filter=Webhook`.
> **Ainda não iniciado:** o simulador que chamará este endpoint, o comando de
> falha, reprodução e frontend.

---

## Fase 10 — Simulador

- [ ] **T067** Componente do simulador e worker dedicado
  - **Objetivo:** o fornecedor externo simulado, chamando o callback real.
  - **Arquivos previstos:** `Video/Infrastructure/Simulator/`, job da fila
    `simulator`.
  - **Requisitos:** RF-PROC-003, RF-PROC-004, RF-PROC-006; plan §13.3.
  - **Implementação:** consome a fila `simulator`, alimentada por T057. O fluxo
    normal produz **sucesso determinístico**. A entrega é um `POST` HTTP assinado
    ao endpoint de T059, alcançado pelo nome do serviço na rede do Compose.
    **Proibido escrever nas tabelas de domínio** — a única forma de afetar o
    estado é o callback. Reutiliza o `event_id` estável de T058 ao reentregar;
    repete diante de rede, `5xx` e `503`; para diante de `200` ou `409`.
  - **Testes/validação:**
    `docker compose run --rm api php artisan test --filter=Simulator`,
    confirmando que o vídeo chega a `ready` pelo caminho HTTP e que o componente
    não referencia repositórios de domínio.
  - **Depende de:** T066.
  - **Critério de conclusão:** sucesso determinístico verde e restrição de escrita
    comprovada.

- [ ] **T068** Comando `demo:simulate-video-failure {videoAttemptId}`
  - **Objetivo:** provocar o cenário de falha de forma explícita e reprodutível.
  - **Arquivos previstos:**
    `Video/Interfaces/Console/SimulateVideoFailureCommand.php`.
  - **Requisitos:** RF-WHK-008; RF-PROC-006; plan §13.3.
  - **Implementação:** o argumento indica qual tentativa preparada recebe o
    callback de falha. Usa o mesmo componente do simulador, o mesmo HMAC e o mesmo
    contrato oficial. `event_id` estável do cenário de falha. Nenhuma escrita
    direta em tabela de domínio.
  - **Testes/validação:** o identificador é o UUID fixo do cenário de falha
    preparado em T029 — a tentativa **dedicada**, não a do curso da jornada
    principal. Com ele numa variável de shell, para evitar interpretar
    caracteres:

    ```
    ATTEMPT_ID="01936f1a-7c00-7a3e-9b7d-2f5c8e4a1d60"
    docker compose run --rm api php artisan demo:simulate-video-failure "$ATTEMPT_ID"
    ```

    E `docker compose run --rm api php artisan test --filter=SimulateFailure`,
    incluindo a execução repetida sem efeito novo.
  - **Depende de:** T067, T029.
  - **Critério de conclusão:** cenário de falha reprodutível e idempotente.

- [ ] **T069** Retry do job e entrega duplicada
  - **Objetivo:** provar que repetir o processamento não duplica efeito.
  - **Arquivos previstos:** `backend/tests/Feature/Video/ProcessRetryTest.php`.
  - **Requisitos:** RN-IDM-002; plan §§8.6, 13.2.
  - **Implementação:** executar o `ProcessVideoJob` sobre uma tentativa já em
    `processing`, confirmando que ele retoma e entrega; depois provocar entrega
    duplicada e confirmar, pelo mesmo `event_id`, que não há efeito adicional.
    Cobrir também que job esgotado vai para `failed_jobs` **sem** alterar o estado
    do vídeo, com `queue:retry` como recuperação.
  - **Testes/validação:**
    `docker compose run --rm api php artisan test --filter=ProcessRetry`.
  - **Depende de:** T068.
  - **Critério de conclusão:** retomada e entrega duplicada cobertas e verdes.

> **Checkpoint da Fase 10**
> **Passa a funcionar:** o ciclo do vídeo fecha ponta a ponta no backend — enviar,
> concluir, processar, receber o callback e chegar a `ready` ou `failed`, com o
> cenário de falha acionável por comando.
> **Testes verdes:** toda a suíte de backend até aqui, mais simulador, comando de
> falha e retry.
> **Comandos:** `docker compose up -d`,
> `docker compose run --rm api php artisan test`, e o comando de falha com o
> identificador literal do cenário dedicado de T029:
> `ATTEMPT_ID="01936f1a-7c00-7a3e-9b7d-2f5c8e4a1d60"` seguido de
> `docker compose run --rm api php artisan demo:simulate-video-failure "$ATTEMPT_ID"`.
> **Ainda não iniciado:** catálogo do consumidor, reprodução, frontend.

---

## Fase 11 — Consumo e reprodução

- [ ] **T070** `AccessGrantRepository` e autorização por concessão
  - **Objetivo:** consumidor só alcança curso ao qual recebeu acesso.
  - **Arquivos previstos:** `Identity/Application/Port/AccessGrantRepository.php`,
    adapter Eloquent, serviço de autorização.
  - **Requisitos:** RN-AUT-003, RN-AUT-004; AC-CONS-002, AC-CONS-005; plan §9.3.
  - **Implementação:** a concessão entra na cláusula da consulta, não em filtro
    posterior. Curso sem concessão responde `404`, indistinguível de inexistente.
    O repositório chega junto da regra que o usa.
  - **Testes/validação:**
    `docker compose run --rm api php artisan test --filter=AccessGrant`,
    comparando a resposta de curso não concedido com a de curso inexistente.
  - **Depende de:** T069.
  - **Critério de conclusão:** AC-CONS-002 e AC-CONS-005 verdes no backend.

- [ ] **T071** Catálogo do consumidor
  - **Objetivo:** consumidor lista cursos concedidos e navega pela estrutura
    publicada.
  - **Arquivos previstos:** `Catalog/Application/ListGrantedCourses/`,
    `GetPublishedStructure/`, controllers, resources, trechos do OpenAPI.
  - **Requisitos:** RF-CONS-001 a 003; RN-AUT-004; AC-CONS-004; plan §10.4.
  - **Implementação:** `GET /api/catalog/courses` paginado, apenas cursos
    concedidos **e** em `available`. `GET /api/catalog/courses/{course}` devolve a
    árvore com **somente aulas publicadas**, na ordem.
  - **Testes/validação:**
    `docker compose run --rm api php artisan test --filter=ConsumerCatalog`.
  - **Depende de:** T070.
  - **Critério de conclusão:** AC-CONS-004 verde.

- [ ] **T072** Endpoint de reprodução
  - **Objetivo:** entregar dados de reprodução a quem tem direito.
  - **Arquivos previstos:** `Video/Application/GetPlayback/`, controller,
    resource, trecho do OpenAPI.
  - **Requisitos:** RF-PLB-001 a 005; AC-CONS-001; plan §14.2.
  - **Implementação:** `GET /api/lessons/{lesson}/playback`. Quatro verificações
    antes de emitir qualquer coisa — autenticado, concessão, aula publicada,
    vídeo `ready`. Só então uma URL `GET` pré-assinada de cinco minutos, gerada
    com o endereço público. O retorno traz dados de reprodução, nunca o arquivo.
  - **Testes/validação:**
    `docker compose run --rm api php artisan test --filter=Playback`.
  - **Depende de:** T071.
  - **Critério de conclusão:** AC-CONS-001 verde.

- [ ] **T073** Estados de negativa da reprodução
  - **Objetivo:** distinguir não é para você de ainda não está pronto.
  - **Arquivos previstos:** mesmo caso de uso de T072.
  - **Requisitos:** RF-PLB-006, RF-PLB-007, RF-PLB-008; RF-UI-015; AC-CONS-002,
    AC-CONS-003; plan §14.2.
  - **Implementação:** sem concessão, `404` indistinguível de inexistente. Aula
    não publicada ou vídeo fora de `ready`, `409` com código próprio.
  - **Testes/validação:**
    `docker compose run --rm api php artisan test --filter=PlaybackDenied`.
  - **Depende de:** T072.
  - **Critério de conclusão:** AC-CONS-002 e AC-CONS-003 verdes.

- [ ] **T074** Teste de autorização cruzada da reprodução
  - **Objetivo:** garantir que nenhuma combinação de perfil e recurso vaze.
  - **Arquivos previstos:**
    `backend/tests/Feature/Video/PlaybackAuthorizationTest.php`.
  - **Requisitos:** RN-AUT-002 a 004; RN-PROP-005; RNF-003.
  - **Implementação:** matriz — produtor dono, produtor alheio, consumidor
    concedido, consumidor sem concessão, não autenticado — contra aula publicada
    e não publicada.
  - **Testes/validação:**
    `docker compose run --rm api php artisan test --filter=PlaybackAuthorization`.
  - **Depende de:** T073.
  - **Critério de conclusão:** matriz completa e verde.

> **Checkpoint da Fase 11**
> **Passa a funcionar:** o backend cobre a jornada inteira — criar, enviar,
> processar, publicar e reproduzir, com autorização em cada passo.
> **Testes verdes:** toda a suíte de backend.
> **Comandos:** `docker compose run --rm api php artisan test`,
> `docker compose run --rm api ./vendor/bin/phpstan analyse`,
> `docker compose run --rm api ./vendor/bin/pint --test`.
> **Ainda não iniciado:** `/api/health` definitivo, consolidação do OpenAPI e todo
> o frontend.

---

## Fase 12 — Consolidação do contrato

- [ ] **T075** Endpoint de saúde definitivo
  - **Objetivo:** substituir a prontidão temporária de T010 pelo `/api/health` do
    plano.
  - **Arquivos previstos:** rota e controller de `/api/health`,
    `docker-compose.yml`, trecho do OpenAPI.
  - **Requisitos:** RNF-020; plan §§16.2, 18.2.
  - **Implementação:** verifica conexão com o banco e alcançabilidade do storage,
    respondendo veredito e estado de cada dependência. Sem versões, hosts
    internos, credenciais ou mensagens de exceção. O healthcheck do `web` passa a
    apontar para cá, e a anotação de temporariedade sai do compose.
  - **Testes/validação:**
    `docker compose run --rm api php artisan test --filter=Health` e
    `docker compose ps` com `web` reportando `healthy` pelo novo endpoint.
  - **Depende de:** T074.
  - **Critério de conclusão:** endpoint verde, healthcheck migrado, nenhum campo
    sensível.

- [ ] **T076** Consolidação do OpenAPI completo
  - **Objetivo:** conferir e fechar o contrato que veio crescendo desde T021.
  - **Arquivos previstos:** `docs/openapi.yaml`.
  - **Requisitos:** RNF-011; ABERTO-010; plan §10.5.
  - **Implementação:** revisar cobertura de **todas** as rotas do backend —
    autenticação, catálogo do produtor, upload, publicação, webhook, catálogo do
    consumidor, reprodução e saúde —, uniformizar exemplos e nomes de schema. Não
    é a primeira vez que o documento fica completo: cada endpoint já atualizou seu
    trecho. Aqui é conferência e fechamento.
  - **Testes/validação:** linter de OpenAPI em container, e comparação entre a
    lista de rotas registradas
    (`docker compose run --rm api php artisan route:list --path=api`) e as rotas
    documentadas.
  - **Depende de:** T075.
  - **Critério de conclusão:** nenhuma rota da API ausente do documento.

- [ ] **T077** Teste de contrato abrangente
  - **Objetivo:** impedir que código e documento divirjam em silêncio.
  - **Arquivos previstos:**
    `backend/tests/Feature/Contract/OpenApiContractTest.php` estendido.
  - **Requisitos:** RNF-011; plan §10.5.
  - **Implementação:** as respostas reais de todos os endpoints conferidas contra
    os schemas declarados, inclusive os corpos de erro.
  - **Testes/validação:**
    `docker compose run --rm api php artisan test --filter=OpenApiContract`.
  - **Depende de:** T076.
  - **Critério de conclusão:** teste verde cobrindo todas as rotas; divergência
    quebra a suíte.

> **Checkpoint da Fase 12**
> **Passa a funcionar:** o backend está completo, saudável pelo endpoint
> definitivo, e com contrato documentado e verificado por teste em todas as rotas.
> **Testes verdes:** toda a suíte de backend, incluindo o contrato abrangente.
> **Comandos:** `docker compose run --rm api php artisan test`,
> `docker compose run --rm api php artisan route:list --path=api`.
> **Ainda não iniciado:** o frontend, que a partir daqui deriva seus tipos do
> contrato completo.

---

## Fase 13 — Fundação do frontend

- [ ] **T078** Configuração do Nuxt, Nuxt UI e Tailwind
  - **Objetivo:** base do frontend pronta, com a biblioteca de componentes
    integrada.
  - **Arquivos previstos:** `frontend/nuxt.config.ts`, `frontend/app.vue`,
    `frontend/assets/css/`.
  - **Requisitos:** ABERTO-008, ABERTO-009; RF-UI-016; plan §§15.1, 15.5.
  - **Implementação:** `ssr: false` já definido em T009, TypeScript estrito,
    Composition API. Nuxt UI v4 sobre Tailwind CSS 4, como única biblioteca
    principal de componentes.
  - **Testes/validação:**
    `docker compose run --rm frontend npm run build` e a aplicação carregando
    pelo serviço `frontend`.
  - **Depende de:** T077.
  - **Critério de conclusão:** build verde e página inicial renderizando.

- [ ] **T079** Ferramental de qualidade do frontend
  - **Objetivo:** lint, tipos e testes disponíveis por comando.
  - **Arquivos previstos:** `frontend/eslint.config.mjs`,
    `frontend/vitest.config.ts`, `frontend/tsconfig.json`.
  - **Requisitos:** ABERTO-013; RNF-005, RNF-008; plan §17.1.
  - **Implementação:** ESLint, verificação de tipos e Vitest com Nuxt e Vue Test
    Utils.
  - **Testes/validação:**
    `docker compose run --rm frontend npm run lint`,
    `docker compose run --rm frontend npm run typecheck`,
    `docker compose run --rm frontend npm run test` — os três verdes numa base
    vazia.
  - **Depende de:** T078.
  - **Critério de conclusão:** os três comandos executam e passam.

- [ ] **T080** Tipos derivados do contrato completo
  - **Objetivo:** o frontend usa exatamente os formatos que o backend declara.
  - **Arquivos previstos:** `frontend/types/api.ts`.
  - **Requisitos:** ABERTO-010; RF-UI-017; plan §15.3.
  - **Implementação:** tipos para envelope, paginação, corpo de problema e todos
    os recursos, derivados do **OpenAPI consolidado em T076** — que já cobre
    upload, webhook, publicação, catálogo do consumidor e reprodução. Nenhuma
    regra de negócio replicada aqui, apenas formatos.
  - **Testes/validação:**
    `docker compose run --rm frontend npm run typecheck`.
  - **Depende de:** T079.
  - **Critério de conclusão:** tipos cobrindo todo o contrato, sem erro de tipo.

- [ ] **T081** Composable `useApi`
  - **Objetivo:** um único ponto que fala HTTP, com credenciais e CSRF.
  - **Arquivos previstos:** `frontend/composables/useApi.ts`.
  - **Requisitos:** ABERTO-001; RF-AUT-006; RF-UI-009 a 012; plan §15.3.
  - **Implementação:** envia credenciais, obtém o cookie CSRF antes da primeira
    requisição mutante, reenvia o valor no header, e converte
    `application/problem+json` num erro tipado com status e código. Nenhum segredo
    no código cliente.
  - **Testes/validação:**
    `docker compose run --rm frontend npm run test -- useApi`, cobrindo sucesso,
    `422`, `409`, `401` e falha de rede.
  - **Depende de:** T080.
  - **Critério de conclusão:** os cinco cenários cobertos e verdes.

- [ ] **T082** Composable `useAuth` e estado de sessão
  - **Objetivo:** sessão e usuário como único estado global.
  - **Arquivos previstos:** `frontend/composables/useAuth.ts`,
    `frontend/middleware/auth.ts`.
  - **Requisitos:** ABERTO-008; RF-AUT-005; RF-UI-014; AC-UI-003; plan §15.2.
  - **Implementação:** `useState` guarda **apenas** sessão e usuário. Sem Pinia.
    `401` em qualquer chamada dispara o estado de sessão expirada, distinto de
    acesso negado.
  - **Testes/validação:**
    `docker compose run --rm frontend npm run test -- useAuth`.
  - **Depende de:** T081.
  - **Critério de conclusão:** AC-UI-003 coberto no nível de composable.

- [ ] **T083** Tratamento uniforme de erro e estados de tela
  - **Objetivo:** componentes que traduzem erro em estado visível, reutilizados
    por todas as telas.
  - **Arquivos previstos:** `frontend/components/ui/`, utilitário de mapeamento de
    erro.
  - **Requisitos:** RF-UI-001 a 003, RF-UI-008 a 013, RF-UI-015; AC-UI-002.
  - **Implementação:** carregamento, lista vazia, ação em andamento, sucesso,
    validação por campo, conflito de regra, indisponibilidade da API, erro de
    rede, acesso negado e conteúdo indisponível. Lista vazia e falha precisam ser
    visualmente distintas.
  - **Testes/validação:**
    `docker compose run --rm frontend npm run test -- ui`.
  - **Depende de:** T082.
  - **Critério de conclusão:** AC-UI-002 coberto e os estados testados.

> **Checkpoint da Fase 13**
> **Passa a funcionar:** o frontend sobe, fala com a API com sessão e CSRF, tem
> tipos derivados do contrato completo e vocabulário de estados pronto.
> **Testes verdes:** composables de API e autenticação, componentes de estado.
> **Comandos:** `docker compose run --rm frontend npm run lint`,
> `docker compose run --rm frontend npm run typecheck`,
> `docker compose run --rm frontend npm run test`,
> `docker compose run --rm frontend npm run build`.
> **Ainda não iniciado:** telas das jornadas, upload no navegador, E2E.

---

## Fase 14 — Jornada do produtor

- [ ] **T084** Tela de login
  - **Objetivo:** as duas personas entram pela interface.
  - **Arquivos previstos:** `frontend/pages/login.vue`.
  - **Requisitos:** RF-AUT-001; RF-UI-003, RF-UI-009; AC-UI-001.
  - **Implementação:** formulário acessível, com rótulos e foco. Erro de
    credencial sem revelar se o e-mail existe. Após entrar, encaminha conforme o
    perfil.
  - **Testes/validação:**
    `docker compose run --rm frontend npm run test -- login`.
  - **Depende de:** T083.
  - **Critério de conclusão:** AC-UI-001 coberto neste formulário.

- [ ] **T085** Lista e criação de cursos
  - **Objetivo:** o produtor vê os próprios cursos e cria um novo.
  - **Arquivos previstos:** `frontend/pages/producer/courses/index.vue`,
    componentes de lista e formulário.
  - **Requisitos:** RF-CUR-001, RF-CUR-002; RF-UI-001, RF-UI-002, RF-UI-008,
    RF-UI-009; AC-PROD-001, AC-UI-001.
  - **Implementação:** carregamento, lista vazia com próxima ação, confirmação de
    criação, validação por campo vinda da API.
  - **Testes/validação:**
    `docker compose run --rm frontend npm run test -- courses`.
  - **Depende de:** T084.
  - **Critério de conclusão:** os quatro estados testados.

- [ ] **T086** Detalhe do curso e sua estrutura
  - **Objetivo:** a árvore de módulos e aulas visível e ordenada.
  - **Arquivos previstos:** `frontend/pages/producer/courses/[id].vue`,
    componentes de módulo e aula.
  - **Requisitos:** RF-EST-001, RF-EST-002, RF-EST-004; RF-UI-001, RF-UI-002.
  - **Implementação:** exibe estado do curso, módulos e aulas na ordem, com o
    estado do vídeo de cada aula.
  - **Testes/validação:**
    `docker compose run --rm frontend npm run test -- course-detail`.
  - **Depende de:** T085.
  - **Critério de conclusão:** ordem e estados verdes.

- [ ] **T087** Criação de módulos e aulas com posição
  - **Objetivo:** criar respeitando a ordem, com posição opcional.
  - **Arquivos previstos:** componentes de formulário de módulo e de aula.
  - **Requisitos:** RF-MOD-002, RF-AUL-002; RN-ORD-002; RF-UI-003, RF-UI-008,
    RF-UI-009; AC-PROD-003.
  - **Implementação:** posição opcional no formulário; a árvore reflete o
    deslocamento devolvido pela API. **A ordem não é recalculada no cliente.**
  - **Testes/validação:**
    `docker compose run --rm frontend npm run test -- structure-forms`.
  - **Depende de:** T086.
  - **Critério de conclusão:** deslocamento refletido corretamente.

- [ ] **T088** Composable `useMultipartUpload`
  - **Objetivo:** a lógica de envio em partes, isolada e testável.
  - **Arquivos previstos:** `frontend/composables/useMultipartUpload.ts`.
  - **Requisitos:** RF-UPL-003; RF-UI-004; RNF-001; AC-VID-001; plan §§11.2, 11.4.
  - **Implementação:** particiona em 64 MiB, mantém **até três** transferências
    concorrentes, pede a URL de cada parte à API, envia direto ao storage e guarda
    o `ETag` de cada uma. Renova URL expirada e reenvia **apenas** a parte que
    falhou. Os bytes nunca passam pelo servidor Nuxt.
  - **Testes/validação:**
    `docker compose run --rm frontend npm run test -- multipart`, cobrindo
    particionamento, concorrência limitada a três, falha de uma parte com reenvio
    isolado e renovação de URL.
  - **Depende de:** T087.
  - **Critério de conclusão:** AC-VID-001 coberto no nível de composable.

- [ ] **T089** Tela de envio de vídeo, com progresso
  - **Objetivo:** o produtor envia o vídeo e vê o andamento.
  - **Arquivos previstos:** componente de upload na página de curso.
  - **Requisitos:** RF-UI-003, RF-UI-004; RF-ERR-001; AC-VID-012.
  - **Implementação:** progresso real, por partes concluídas sobre o total. Falha
    de transferência exibida com opção de retomar dentro da mesma tentativa.
    **Nunca** apresentar o vídeo como pronto sem conclusão verificada.
  - **Testes/validação:**
    `docker compose run --rm frontend npm run test -- upload-panel`.
  - **Depende de:** T088.
  - **Critério de conclusão:** progresso e falha testados.

- [ ] **T090** Composable `useVideoStatus` com polling
  - **Objetivo:** a tela acompanha o processamento sem conexão permanente.
  - **Arquivos previstos:** `frontend/composables/useVideoStatus.ts`.
  - **Requisitos:** RF-VID-002; RF-UI-005 a 007; AC-UI-004; plan §15.2.
  - **Implementação:** consulta a cada **três segundos** enquanto o estado é
    transitório — `pending`, `uploading`, `uploaded`, `processing`. Para ao
    alcançar `ready` ou `failed`, e ao desmontar a tela. Sem WebSocket, sem SSE.
  - **Testes/validação:**
    `docker compose run --rm frontend npm run test -- video-status`, com relógio
    controlado.
  - **Depende de:** T089.
  - **Critério de conclusão:** início e parada do polling comprovados.

- [ ] **T091** Estados do vídeo e falha visível
  - **Objetivo:** cada estado do ciclo de vida tem representação clara na tela.
  - **Arquivos previstos:** componente de estado do vídeo.
  - **Requisitos:** RF-UI-004 a 007; AC-UI-004; AC-VID-007.
  - **Implementação:** enviando, processando, pronto e falhou, cada um distinto. A
    falha mostra a mensagem pública devolvida pela API e o caminho para nova
    tentativa.
  - **Testes/validação:**
    `docker compose run --rm frontend npm run test -- video-state`.
  - **Depende de:** T090.
  - **Critério de conclusão:** AC-UI-004 verde.

- [ ] **T092** Ação de publicar aula
  - **Objetivo:** publicar pela interface, com o conflito de regra bem
    apresentado.
  - **Arquivos previstos:** componente de publicação.
  - **Requisitos:** RF-PUB-001, RF-PUB-002; RF-UI-008, RF-UI-010; AC-PROD-004,
    AC-PROD-005.
  - **Implementação:** o botão só aparece com o vídeo `ready` — conveniência
    visual, **nunca** proteção; o backend decide de novo. Rejeição `409` vira
    mensagem de condição não satisfeita, derivada do código funcional.
  - **Testes/validação:**
    `docker compose run --rm frontend npm run test -- publish`.
  - **Depende de:** T091.
  - **Critério de conclusão:** AC-PROD-004 coberto na interface.

> **Checkpoint da Fase 14**
> **Passa a funcionar:** a jornada do produtor inteira pela interface — entrar,
> criar curso, módulo e aula, enviar vídeo com progresso, acompanhar o
> processamento, ver falha e publicar.
> **Testes verdes:** componentes e composables de cada entrega acima.
> **Comandos:** `docker compose up -d`,
> `docker compose run --rm frontend npm run test`,
> `docker compose run --rm frontend npm run lint`,
> `docker compose run --rm frontend npm run typecheck`.
> **Ainda não iniciado:** telas do consumidor, E2E, pipeline.

---

## Fase 15 — Jornada do consumidor

- [ ] **T093** Lista de cursos concedidos
  - **Objetivo:** o consumidor vê o que pode assistir.
  - **Arquivos previstos:** `frontend/pages/catalog/index.vue`.
  - **Requisitos:** RF-CONS-001, RF-CONS-002, RF-CONS-005; RF-UI-001, RF-UI-002.
  - **Implementação:** apenas cursos concedidos e disponíveis. Lista vazia com
    mensagem própria, distinta de erro.
  - **Testes/validação:**
    `docker compose run --rm frontend npm run test -- catalog-list`.
  - **Depende de:** T092.
  - **Critério de conclusão:** os três estados testados.

- [ ] **T094** Navegação pela estrutura publicada
  - **Objetivo:** percorrer módulos e aulas na ordem, sem ver rascunho.
  - **Arquivos previstos:** `frontend/pages/catalog/courses/[id].vue`.
  - **Requisitos:** RF-CONS-003; RN-AUT-004; AC-CONS-004.
  - **Implementação:** renderiza apenas o que a API devolve, que já exclui
    rascunhos. Nada de filtrar no cliente.
  - **Testes/validação:**
    `docker compose run --rm frontend npm run test -- catalog-structure`.
  - **Depende de:** T093.
  - **Critério de conclusão:** AC-CONS-004 coberto na interface.

- [ ] **T095** Tela de reprodução
  - **Objetivo:** abrir a aula e assistir.
  - **Arquivos previstos:** `frontend/pages/catalog/lessons/[id].vue`.
  - **Requisitos:** RF-CONS-004; RF-PLB-005; AC-CONS-001.
  - **Implementação:** solicita os dados de reprodução e alimenta um elemento de
    vídeo do navegador com a URL temporária. Sem player avançado — fora do escopo.
  - **Testes/validação:**
    `docker compose run --rm frontend npm run test -- playback-page`.
  - **Depende de:** T094.
  - **Critério de conclusão:** AC-CONS-001 coberto na interface.

- [ ] **T096** Estados de negativa e indisponibilidade
  - **Objetivo:** o consumidor entende por que não consegue assistir.
  - **Arquivos previstos:** componentes de estado na área do consumidor.
  - **Requisitos:** RF-CONS-005; RF-PLB-006, RF-PLB-007, RF-PLB-008; RN-PROP-005;
    RF-UI-013, RF-UI-015; AC-CONS-002, AC-CONS-003; plan §9.3.
  - **Implementação:** a interface tem **três** estados públicos, não quatro, e
    isso é deliberado:

    | Resposta | Estado exibido |
    | --- | --- |
    | `401` | Sessão expirada, conduzindo à reautenticação |
    | `403` e `404` | **O mesmo** estado de acesso negado |
    | `409` | Conteúdo conhecido, ainda indisponível para reprodução |

    `403` e `404` colapsam num único estado de propósito. O backend já responde
    `404` de forma indistinguível para recurso inexistente e para recurso
    existente sem propriedade ou concessão (RN-PROP-005, RF-PLB-008); se a tela
    dissesse "não encontrado" num caso e "sem permissão" no outro, ela desfaria no
    cliente a ocultação que o servidor construiu. **A interface nunca afirma se o
    recurso existe.** O `409` é diferente: ali o consumidor tem acesso, e a
    indisponibilidade é do conteúdo, não do direito.
  - **Testes/validação:**
    `docker compose run --rm frontend npm run test -- playback-denied`,
    afirmando que `403` e `404` produzem **o mesmo texto e o mesmo estado**, e que
    nenhum deles menciona existência ou inexistência do recurso.
  - **Depende de:** T095.
  - **Critério de conclusão:** AC-CONS-002 e AC-CONS-003 cobertos sem que a
    interface revele existência.

- [ ] **T097** Responsividade e acessibilidade das duas jornadas
  - **Objetivo:** interface utilizável em telas pequenas e navegável por teclado.
  - **Arquivos previstos:** ajustes nos componentes e páginas existentes.
  - **Requisitos:** RF-UI-016; plan §15.5.
  - **Implementação:** formulários com rótulos associados, ordem de foco coerente,
    contraste adequado, navegação consistente. Não é exigido acabamento
    comercial.
  - **Testes/validação:**
    `docker compose run --rm frontend npm run test -- a11y` para rótulos e
    navegação por teclado, mais asserções de layout nos pontos de quebra
    definidos, feitas no próprio teste de componente.
  - **Depende de:** T096.
  - **Critério de conclusão:** testes verdes. A conferência visual em largura
    reduzida é validação externa, cobrada em T109 — **não bloqueia esta
    tarefa**.

> **Checkpoint da Fase 15**
> **Passa a funcionar:** as duas jornadas completas pela interface, do
> gerenciamento do conteúdo até assisti-lo como consumidor autorizado.
> **Testes verdes:** toda a suíte de frontend.
> **Comandos:** `docker compose run --rm frontend npm run test`,
> `docker compose run --rm frontend npm run lint`,
> `docker compose run --rm frontend npm run typecheck`,
> `docker compose run --rm frontend npm run build`.
> **Ainda não iniciado:** E2E, pipeline, documentação final.

---

## Fase 16 — E2E

O navegador do Playwright **não** é um nono serviço. Ele vive num profile
`e2e`, que `docker compose up` não sobe. Os oito serviços aprovados no plano
continuam sendo os únicos do comando normal.

- [ ] **T098** Playwright em profile isolado
  - **Objetivo:** um navegador real dentro do Compose, criado apenas quando os
    testes E2E são pedidos.
  - **Arquivos previstos:** `e2e/playwright.config.ts`, `docker/e2e/Dockerfile`,
    serviço `e2e` no `docker-compose.yml` **com `profiles: [e2e]`**,
    `e2e/tests/smoke.spec.ts`, `scripts/e2e.sh` e o alvo `make e2e`.
  - **Requisitos:** ABERTO-013; RNF-006, RNF-007; plan §§16.1, 17.2.
  - **Implementação:** o serviço aponta para o `frontend` pela rede interna e
    depende de `web` saudável.

    A reprodutibilidade exige uma **orquestração versionada**, porque
    `migrate:fresh` no meio de workers ativos gera corrida: um worker pode
    consumir ou gravar durante o reset. E porque o container de E2E **não tem
    Docker e não recebe o socket do Docker**: tudo que precisa do daemon —
    reset, seed e preparação de cenário — roda no **host**, pelo script, antes do
    navegador começar.

    `scripts/e2e.sh [smoke|jornada|erros|tudo]`, com `tudo` como padrão quando
    nenhum argumento é passado. Não há comportamento inferido de nome de arquivo:
    o conjunto é sempre um argumento explícito. Quatro passos comuns, iguais nos
    quatro modos:

    1. `docker compose stop worker simulator-worker` — os consumidores param antes
       do reset;
    2. `docker compose run --rm api php artisan migrate:fresh --seed` — base
       recriada e semeada;
    3. `docker compose up -d worker simulator-worker` — consumidores voltam;
    4. espera ativa até `docker compose ps` reportar `web`, `mysql` e `rustfs`
       como `healthy`.

    Depois deles, uma tabela escrita no próprio script decide preparação e alvo:

    | Modo | Preparação adicional no host | Playwright | Existe desde |
    | --- | --- | --- | --- |
    | `smoke` | nenhuma | `npx playwright test smoke` | T098 |
    | `jornada` | nenhuma | `npx playwright test jornada-completa` | T100 |
    | `erros` | comando Artisan de falha | `npx playwright test cenarios-erro` | T101 |
    | `tudo` | comando Artisan de falha | `npx playwright test` | T098 |

    O modo `smoke` existe para que **esta** tarefa se prove com um teste real, e
    não com uma execução vazia. O `e2e/tests/smoke.spec.ts` é mínimo de propósito:
    abre a aplicação Nuxt real em navegador real pelo endereço interno do serviço
    `web`, e afirma que a página de login carregou — título e campos presentes.
    Ele **não** toca nas jornadas de T100 e T101, não depende de seed de conteúdo
    e não conhece curso, aula nem vídeo, então continua verde quando aquelas
    mudarem. É também a primeira prova de que o profile, a rede interna e o
    `webServer` do Playwright estão corretos.

    A preparação adicional é uma linha só, com o UUID fixo de T029 literal no
    script:

    ```
    ATTEMPT_ID="01936f1a-7c00-7a3e-9b7d-2f5c8e4a1d60"
    docker compose run --rm api php artisan demo:simulate-video-failure "$ATTEMPT_ID"
    ```

    Ela é inofensiva para a jornada principal — incide sobre o curso **dedicado**
    de T029, que a jornada não visita —, e por isso `tudo` pode executá-la uma
    única vez, antes de abrir o navegador, e rodar todos os specs existentes numa
    só invocação. O Playwright é sempre chamado por
    `docker compose --profile e2e run --rm e2e ...`, e nunca executa `docker`
    de dentro do container.

    Rodar o script duas vezes seguidas produz o mesmo resultado, porque o passo 2
    descarta o estado da execução anterior. `make e2e` é apenas o atalho de
    `./scripts/e2e.sh tudo` — **esta é a entrada oficial do E2E**, e o checkpoint
    da fase, o README de T105 e o job `e2e` da pipeline apontam todos para ela.
    T100 e T101 usam os modos estreitos `jornada` e `erros`, que são a mesma
    entrada com o alvo restrito ao conjunto que cada uma acabou de escrever.
  - **Testes/validação:** duas conferências.
    `docker compose up -d && docker compose ps` mostrando **oito** serviços, sem
    o `e2e`; e `./scripts/e2e.sh smoke`, que executa os quatro passos comuns e
    fecha com o `smoke.spec.ts` **passando** em navegador real. `make e2e` produz
    o mesmo resultado nesta altura, porque o único spec existente é o de smoke.
    **"Nenhum teste encontrado" não conta como aprovação** — a orquestração só
    está provada quando um teste real termina verde através dela.
  - **Depende de:** T097.
  - **Critério de conclusão:** os oito serviços normais preservados, o Playwright
    disponível sob demanda, os quatro passos comuns executando na ordem, os
    quatro modos declarados explicitamente no script, e o `smoke.spec.ts` verde
    por `./scripts/e2e.sh smoke` — sem depender de nenhum spec de T100 ou T101.

- [ ] **T099** Fixture de vídeo curto
  - **Objetivo:** um arquivo pequeno, versionado, que o Git não descarte.
  - **Arquivos previstos:** `e2e/fixtures/video-curto.mp4`.
  - **Requisitos:** RNF-006; plan §17.2.
  - **Implementação:** um arquivo de poucos kilobytes, suficiente para caber em
    uma única parte do multipart. A exceção `!e2e/fixtures/**` foi acrescentada ao
    `.gitignore` em T004, justamente porque `*.mp4` é ignorado globalmente.
  - **Testes/validação:** duas conferências.
    `git check-ignore -q e2e/fixtures/video-curto.mp4; echo $?` precisa imprimir
    **1** — o status de saída é a prova, pelo mesmo motivo explicado em T004. E
    `git status --short --untracked-files=all -- e2e/fixtures/video-curto.mp4`
    precisa listar o arquivo, confirmando que ele é de fato versionável.
  - **Depende de:** T098.
  - **Critério de conclusão:** fixture presente, pequeno e efetivamente
    versionável.

- [ ] **T100** Jornada crítica ponta a ponta
  - **Objetivo:** provar AC-E2E-001 atravessando interface e backend reais.
  - **Arquivos previstos:** `e2e/tests/jornada-completa.spec.ts`.
  - **Requisitos:** RNF-006; AC-E2E-001; AC-VID-001; spec seção 15.5.
  - **Implementação:** o produtor entra, **abre o curso já preparado por seed** —
    não cria um curso novo, porque não existe operação para conceder acesso a um
    curso recém-criado —, cria módulo e aula, envia o fixture de T099 pelo upload
    multipart real, aguarda o processamento concluir e publica. Em seguida o
    consumidor entra, navega até a aula e obtém os dados de reprodução. Confirmar
    também que o curso passou a `available`. Um único arquivo de teste basta.
  - **Testes/validação:** `./scripts/e2e.sh jornada` — a entrada oficial de T098,
    nunca o Playwright direto, para o teste partir sempre da base recém-semeada e
    com os workers ativos.
  - **Depende de:** T099.
  - **Critério de conclusão:** AC-E2E-001 verde, com o upload real percorrido pela
    interface.

- [ ] **T101** Cenários críticos de erro no E2E
  - **Objetivo:** provar pela interface os caminhos que mais quebram.
  - **Arquivos previstos:** `e2e/tests/cenarios-erro.spec.ts`.
  - **Requisitos:** AC-PROD-002; AC-CONS-002; AC-UI-002, AC-UI-003; AC-VID-007;
    plan §15.
  - **Implementação:** cinco cenários, cada um com preparação determinística —
    nenhum depende de vencer uma corrida contra o simulador, e **nenhum executa
    `docker` de dentro do navegador**: o que precisa do daemon já foi feito pelo
    `scripts/e2e.sh` no host. Ao Playwright cabe autenticar, navegar e conferir a
    interface.

    1. **Publicação indisponível antes de `ready`** — o teste cria a aula e
       **não** envia vídeo, então ela fica sem tentativa em `ready` por
       construção. O plano determina que a ação de publicar só se apresenta com o
       vídeo em `ready` (plan §15), logo não existe botão para clicar: o cenário
       verifica **pela interface** que a publicação está indisponível — ausente ou
       desabilitada — e que o estado exibido explica o porquê. Este cenário **não**
       dispara publicação nem observa `409`; a rejeição HTTP continua provada por
       T053 e a apresentação do conflito pelo componente, por T092.
    2. **Consumidor sem concessão** — entra com o consumidor e tenta abrir o curso
       do **segundo** produtor, semeado em T028 justamente para isso.
    3. **Falha de processamento** — a preparação acontece **fora do navegador**:
       o `scripts/e2e.sh`, no modo `erros` ou `tudo`, executa o comando Artisan de
       falha com o UUID fixo de T029 antes de iniciar o Playwright. Ao teste resta
       autenticar como produtor, navegar até a aula do curso **dedicado** e
       confirmar o estado de falha exibido.
    4. **API indisponível** — interceptação de rede do próprio Playwright, que
       aborta as requisições da rota observada. **Sem montar o socket do Docker no
       container de E2E** e sem derrubar serviço.
    5. **Sessão expirada** — o teste limpa o cookie de sessão no contexto do
       navegador e dispara uma ação autenticada.

    **Nenhum endpoint de teste ou backdoor é criado na aplicação** para qualquer
    um dos cinco.
  - **Testes/validação:** `./scripts/e2e.sh erros` — o modo que executa a
    preparação da falha antes do navegador.
  - **Depende de:** T100.
  - **Critério de conclusão:** os cinco cenários verdes, com a preparação do
    cenário 3 feita pelo script e não pelo teste.

> **Checkpoint da Fase 16**
> **Passa a funcionar:** a jornada crítica e os principais caminhos de erro
> comprovados por navegador real contra a pilha completa, sem alterar a
> composição normal do ambiente.
> **Testes verdes:** backend, frontend e E2E.
> **Comandos:** `docker compose up -d` e, para o E2E, sempre a entrada oficial de
> T098 — `make e2e`, atalho de `./scripts/e2e.sh tudo` —, nunca o Playwright
> direto, que pularia o reset, o seed, a espera pelos healthchecks e a preparação
> do cenário de falha.
> **Ainda não iniciado:** pipeline e documentação final.

---

## Fase 17 — Pipeline

- [ ] **T102** Workflow único com jobs paralelos
  - **Objetivo:** um arquivo de pipeline que expressa o paralelismo exigido pelo
    plano — backend e frontend em paralelo, E2E dependendo dos dois.
  - **Arquivos previstos:** `.github/workflows/ci.yml`.
  - **Requisitos:** RNF-008, RNF-017; ABERTO-013; plan §17.4.
  - **Implementação:** disparo em `push` e `pull_request`. Três jobs:

    **`backend`** — a suíte de backend **não** depende só do MySQL: há integração
    contra o RustFS (T042, T047) e testes que exercem o callback HTTP real. Rodar
    apenas a parte que dispensa storage e anunciar "PHPUnit verde" seria uma
    afirmação falsa. Por isso o job **reutiliza o `docker-compose.yml` do
    projeto** em vez de declarar serviços soltos: sobe `mysql`, `rustfs`, `setup`
    — que cria bucket e CORS —, `api` e `web`, aguarda os healthchecks, e então
    executa `pint --test`, PHPStan e a **suíte completa** de PHPUnit dentro do
    container `api`, que alcança storage e API pela rede do Compose. Nenhum
    serviço novo; nenhum Redis.

    **`frontend`** — `npm ci`, ESLint, verificação de tipos, Vitest e build do
    Nuxt, em container, sem depender do backend.

    **`e2e`** — com `needs: [backend, frontend]`, sobe o ambiente e chama
    `./scripts/e2e.sh tudo`, a mesma entrada oficial usada localmente. O job **não
    reimplementa** reset, seed, espera por healthcheck nem preparação do cenário
    de falha: essa lógica pertence ao script e existe num lugar só, para a
    pipeline não passar a divergir do que o desenvolvedor executa na própria
    máquina.

    Um único arquivo, sem reusable workflow e sem `workflow_run`.
  - **Testes/validação:** **local, e é o que fecha esta tarefa** — validar a
    sintaxe com um linter de workflow em container, e executar cada comando do
    YAML localmente pelo mesmo caminho que o job usará, confirmando que a suíte
    completa de backend passa com storage e API disponíveis.
  - **Depende de:** T101.
  - **Critério de conclusão:** sintaxe válida, `needs` declarado, e todos os
    comandos do workflow verdes localmente. A execução remota é responsabilidade
    de T104 e **não bloqueia esta tarefa**.

- [ ] **T103** Validação do OpenAPI na pipeline
  - **Objetivo:** impedir que o contrato documentado se afaste do código sem
    alguém notar.
  - **Arquivos previstos:** `.github/workflows/ci.yml`, etapa no job `backend`.
  - **Requisitos:** RNF-011; plan §17.4.
  - **Implementação:** o job `backend` ganha duas etapas — validar o documento
    com o linter de OpenAPI e executar o teste de contrato de T077. Fica no job
    `backend` porque depende do mesmo ambiente PHP, sem criar dependência nova
    entre jobs.
  - **Testes/validação:** os dois comandos rodando verde em container, pelo mesmo
    caminho que o job usará.
  - **Depende de:** T102.
  - **Critério de conclusão:** etapas presentes no workflow e verdes localmente. A
    execução remota é responsabilidade de T104.

- [ ] **T104** Evidência remota da pipeline
  - **Objetivo:** fechar a única parte da verificação que a implementação não pode
    executar.
  - **Arquivos previstos:** nenhum — é uma tarefa de verificação.
  - **Requisitos:** RNF-008, RNF-017; a restrição de versionamento descrita
    nesta própria tarefa — a implementação não executa `commit`, `push` nem
    dispara workflow.
  - **Implementação:** esta é a **única** tarefa que depende de estado remoto, e
    ela existe justamente para não bloquear as demais. A implementação **não**
    executa `commit`, `push` nem dispara workflow: entrega os blocos de commit
    prontos e a lista do que precisa ser observado — os três jobs executando,
    `e2e` esperando `backend` e `frontend`, e a reprovação acontecendo quando uma
    etapa falha. **Não** será criada falha proposital no código, o que exigiria
    deixar o repositório quebrado ou fazer push temporário; a reprovação é
    observada naturalmente na primeira execução com problema, ou verificada num
    branch descartável, a critério do responsável pelo repositório.
  - **Testes/validação:** **validação externa** — `push` e conferência do
    resultado no GitHub Actions.
  - **Depende de:** T103.
  - **Critério de conclusão:** o responsável pelo repositório confirma que os três
    jobs executaram com a dependência correta. A tarefa permanece `[ ]` até lá, e
    a pendência é reportada. **As tarefas seguintes não esperam por ela** — apenas
    o fechamento da entrega, em T109, exige essa confirmação.

> **Checkpoint da Fase 17**
> **Passa a funcionar:** um workflow único instala, verifica e testa as duas
> camadas em paralelo, constrói o Nuxt, valida o contrato e roda o E2E depois dos
> dois.
> **Testes verdes:** sintaxe do workflow e todos os comandos que ele invoca,
> verificados localmente — inclusive a suíte completa de backend com MySQL,
> RustFS, `setup`, `api` e `web` disponíveis.
> **Comandos locais:** linter de workflow em container, mais os comandos de teste
> das fases anteriores.
> **Pendência remota:** a execução no GitHub Actions é validação externa e está
> concentrada em **T104**. T102 e T103 fecham com validação local; a documentação
> da Fase 18 não espera por essa evidência.
> **Ainda não iniciado:** documentação final.

---

## Fase 18 — Documentação final

- [ ] **T105** README executável
  - **Objetivo:** outro desenvolvedor compreende, executa e avalia a solução.
  - **Arquivos previstos:** `README.md`.
  - **Requisitos:** RNF-011, RNF-019, RNF-020; desafio §13.
  - **Implementação:** requisitos, variáveis de ambiente, comando único de subida,
    comando de reset, credenciais fictícias de avaliação, comandos de teste de
    cada camada e como acessar frontend e API. O E2E é documentado pela **entrada
    oficial de T098** — `make e2e`, com `./scripts/e2e.sh jornada` e
    `./scripts/e2e.sh erros` para os conjuntos isolados —, nunca pelo
    `npx playwright test` direto. Atualizar o checklist de progresso.
  - **Testes/validação:** executar cada comando documentado no ambiente já
    existente e confirmar que funciona, incluindo os três modos de E2E de T098.
  - **Depende de:** T103.
  - **Critério de conclusão:** todos os comandos verificados localmente. A
    reprodução a partir de clone limpo é validação externa e está concentrada
    em T109 — **não bloqueia esta tarefa**.

- [ ] **T106** Arquitetura, trade-offs e limitações no README
  - **Objetivo:** as decisões e seus custos legíveis sem abrir a spec inteira.
  - **Arquivos previstos:** `README.md`.
  - **Requisitos:** RNF-011; desafio §13.
  - **Implementação:** resumo das decisões — autenticação, upload, storage,
    processamento, renderização — com ponteiro para `plan.md`. Limitações
    conscientes: aula bloqueada por envio abandonado, partes órfãs, URL de
    reprodução compartilhável, tentativa presa em `processing` após esgotar a
    entrega, ausência de checksum ponta a ponta, de transcodificação, de
    cancelamento, de expiração automática, de retomada entre sessões, de tempo
    real, de APM e de métricas. **Nenhum desses itens tem tarefa de
    implementação** — eles existem só aqui.
  - **Testes/validação:** revisão cruzada contra `plan.md` §19.
  - **Depende de:** T105.
  - **Critério de conclusão:** limitações declaradas, sem promessa que o código
    não cumpre.

- [ ] **T107** Publicação da documentação da API
  - **Objetivo:** o avaliador executa as requisições sem ler o código.
  - **Arquivos previstos:** `docs/api.md`, revisão de `docs/openapi.yaml`.
  - **Requisitos:** RNF-011; desafio §13.
  - **Implementação:** revisar exemplos e publicar instruções de uso do contrato
    já consolidado em T076 — esta tarefa **não** é a primeira vez que o OpenAPI
    fica completo, apenas onde ele ganha exemplos revisados e um guia de leitura.
  - **Testes/validação:** importar o documento numa ferramenta de requisições e
    executar o fluxo principal contra o ambiente local.
  - **Depende de:** T106.
  - **Critério de conclusão:** requisições executáveis a partir do documento.

- [ ] **T108** Roteiros de demonstração
  - **Objetivo:** reproduzir as duas jornadas em poucos minutos.
  - **Arquivos previstos:** `docs/demonstracao.md`.
  - **Requisitos:** RNF-011; RF-WHK-008; desafio §13.
  - **Implementação:** roteiro de sucesso — entrar como produtor, abrir o curso
    preparado, criar módulo e aula, enviar vídeo, acompanhar o processamento,
    publicar, entrar como consumidor e reproduzir. Roteiro de falha — acionar o
    comando sobre a tentativa **dedicada** ao cenário de falha, que pertence a um
    curso separado e não é a do curso vazio da jornada principal:

    ```
    ATTEMPT_ID="01936f1a-7c00-7a3e-9b7d-2f5c8e4a1d60"
    docker compose run --rm api php artisan demo:simulate-video-failure "$ATTEMPT_ID"
    ```

    O UUID é o mesmo literal preparado por T029 e usado por T068 e por
    `scripts/e2e.sh`; o roteiro explica a qual cenário ele pertence, para o
    avaliador não confundir com o curso da jornada principal. Em seguida, observar
    o estado de falha na interface do produtor.
  - **Testes/validação:** executar os dois roteiros do começo ao fim no ambiente
    local.
  - **Depende de:** T107.
  - **Critério de conclusão:** os dois roteiros reproduzidos sem improviso.

- [ ] **T109** Revisão final de rastreabilidade e ausência de segredos
  - **Objetivo:** fechar a entrega conferindo coerência e higiene.
  - **Arquivos previstos:** `specs/001-video-platform/spec.md`,
    `specs/001-video-platform/plan.md`, `specs/001-video-platform/tasks.md`.
  - **Requisitos:** RNF-010, RNF-012, RNF-018.
  - **Implementação:** conferir que nenhum comportamento implementado diverge da
    spec sem que ela tenha sido atualizada, que todo critério de aceitação tem
    tarefa e teste, e que não há segredo real, chave de produção ou dado pessoal
    no repositório. Se a implementação tiver mudado algum comportamento descrito,
    atualizar a spec **na mesma leva**.

    É aqui que as **validações externas** são reunidas e cobradas: a evidência
    remota da pipeline (T104), a reprodução a partir de clone limpo (T105) e a
    conferência visual em largura reduzida (T097). A entrega não fecha com
    qualquer uma delas pendente.
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
  - **Depende de:** T104, T108.
  - **Critério de conclusão:** coerência confirmada, nenhum segredo encontrado, e
    as três validações externas registradas como concluídas.

> **Checkpoint da Fase 18**
> **Passa a funcionar:** a entrega está completa e reproduzível, com documentação,
> roteiros e rastreabilidade conferidas.
> **Testes verdes:** toda a suíte, mais os dois roteiros executados manualmente.
> **Comandos:** `docker compose up -d`, os comandos de teste de cada camada, e o
> roteiro do README.
> **Pendências de validação externa, reunidas em T109:** evidência remota da
> pipeline (T104), reprodução a partir de clone limpo (T105) e conferência visual
> em largura reduzida (T097). A entrega não fecha com qualquer uma delas em
> aberto.
> **Ainda não iniciado:** nada dentro do escopo. Os itens fora do MVP permanecem
> apenas documentados como limitação.

---

## Dependências e paralelismo

### Ordem crítica das fases

```
Fase 0  spike do storage
Fase 1  ambiente containerizado
Fase 2  dominio + contrato HTTP + base do OpenAPI
Fase 3  persistencia e dados de avaliacao
Fase 4  autenticacao
Fase 5  catalogo do produtor
Fase 6  upload multipart
Fase 7  publicacao
Fase 8  fila e processamento
Fase 9  webhook
Fase 10 simulador
Fase 11 consumo e reproducao
Fase 12 consolidacao do contrato
Fase 13 fundacao do frontend
Fase 14 jornada do produtor
Fase 15 jornada do consumidor
Fase 16 E2E
Fase 17 pipeline
Fase 18 documentacao
```

### Dependências que não podem ser invertidas

| Restrição | Motivo |
| --- | --- |
| Tudo depende de T001 | O RustFS é a decisão de maior risco do plano. Só T001 tem `Depende de: nenhuma`; T002 depende dele, T003 de T002, e a fundação começa em T004 |
| Comandos precedem o Compose | T005, T006 e T009 usam `docker build` e `docker run`, porque o `docker-compose.yml` só nasce em T007 |
| Bucket é do `setup` | T008 apenas deixa o `rustfs` saudável; bucket e CORS são criados em T011 |
| Prontidão temporária | T010 usa o endpoint padrão do Laravel, declaradamente temporário, porque `/api/health` só existe em T075 |
| Fila antes dos workers terem função | T012 define `worker` e `simulator-worker`, mas eles só passam a consumir algo em T055 |
| Migrations em ordem executável | T023 a T027 seguem a ordem de dependência de chave estrangeira, com a referência circular resolvida em dois passos dentro de T025 |
| Contrato antes do primeiro endpoint | T020 e T021 vêm antes de T031, para nenhuma rota nascer num formato que precise ser reescrito |
| Agregado antes do repositório | `Course` em T034, `Module` em T037, `Lesson` em T038, `VideoAttempt` em T043, `AccessGrant` em T070, `WebhookEvent` em T062 — cada um com seu repositório na mesma tarefa |
| Vídeo antes da publicação | T053 depende de T043, porque publicar exige a tentativa de vídeo como agregado |
| Webhook antes do simulador | T067 depende de T066: o endpoint que o simulador chama precisa existir antes dele |
| Comando de falha depois do webhook | T068 depende de T067 e do seed de T029 |
| OpenAPI completo antes do frontend | T080 depende de T076, a consolidação que cobre upload, webhook, publicação, consumo e reprodução |
| E2E depende da pilha completa | T100 exige backend, frontend, storage, fila e simulador rodando juntos |
| Pipeline depois do E2E | T102 orquestra o que T100 e T101 já provaram localmente |

### Tarefas realmente paralelizáveis

Apenas uma, marcada com `[P]`:

| Tarefa | Por que é segura em paralelo |
| --- | --- |
| **T009** — imagem e scaffolding do Nuxt | Depende só da preparação comum de T004 e toca exclusivamente `frontend/` e `docker/frontend/`. **Não edita** `docker-compose.yml` — o serviço `frontend` entra em T012 —, então pode correr ao lado de T005 a T008 sem conflito |

A pipeline do frontend **não** é marcada `[P]`: ela edita o mesmo
`.github/workflows/ci.yml` da tarefa anterior. O paralelismo exigido pelo plano é
entre **jobs** da pipeline, expresso por `needs: [backend, frontend]` em T102, não
entre tarefas de implementação.

As demais tarefas de uma mesma fase editam rotas, configuração ou compose em
comum. Foram mantidas em sequência para preservar commits granulares e
revisáveis.

---

## Matriz de cobertura

### Critérios de aceitação

| Grupo | Critério | Implementação | Validação |
| --- | --- | --- | --- |
| Produtor | AC-PROD-001 | T034, T036, T085 | T034, T036, T085 |
| Produtor | AC-PROD-002 | T035 | T035, T101 |
| Produtor | AC-PROD-003 | T037, T038, T041, T087 | T037, T038, T039, T087 |
| Produtor | AC-PROD-004 | T053, T092 | T053, T092 |
| Produtor | AC-PROD-005 | T053, T092 | T053, T054, T092 |
| Produtor | AC-PROD-006 | T053 | T053, T054 |
| Produtor | AC-PROD-007 | T035 | T035 |
| Vídeo | AC-VID-001 | T042, T044, T088 | T042, T088, T100 |
| Vídeo | AC-VID-002 | T047 | T047 |
| Vídeo | AC-VID-003 | T048 | T048, T049 |
| Vídeo | AC-VID-004 | T062 | T062, T066 |
| Vídeo | AC-VID-005 | T062 | T062 |
| Vídeo | AC-VID-006 | T062 | T062 |
| Vídeo | AC-VID-007 | T065, T091 | T065, T091, T101 |
| Vídeo | AC-VID-008 | T017 | T017 |
| Vídeo | AC-VID-009 | T060 | T060 |
| Vídeo | AC-VID-010 | T044 | T044 |
| Vídeo | AC-VID-011 | T044, T051 | T051 |
| Vídeo | AC-VID-012 | T045, T089 | T052, T089 |
| Vídeo | AC-VID-013 | T062 | T062 |
| Consumidor | AC-CONS-001 | T072, T095 | T072, T095, T100 |
| Consumidor | AC-CONS-002 | T070, T073, T096 | T070, T073, T096, T101 |
| Consumidor | AC-CONS-003 | T073, T096 | T073, T096 |
| Consumidor | AC-CONS-004 | T071, T094 | T071, T094 |
| Consumidor | AC-CONS-005 | T070, T073 | T070, T074 |
| Interface | AC-UI-001 | T083, T084, T085 | T084, T085 |
| Interface | AC-UI-002 | T083, T093 | T083, T093, T101 |
| Interface | AC-UI-003 | T033, T082 | T082, T101 |
| Interface | AC-UI-004 | T090, T091 | T090, T091 |
| Integrado | AC-E2E-001 | T098, T099, T100 | T100, T102 |

### Requisitos não funcionais

| Grupo | RNF | Implementação | Validação |
| --- | --- | --- | --- |
| Ambiente | RNF-009, RNF-020 | T007, T011, T013, T022 | T013, T105 |
| Desempenho do envio | RNF-001, RNF-002 | T042, T044, T055 | T042, T088 |
| Concorrência | RNF-003 | T039, T046, T049, T066 | T039, T049, T066, T074 |
| Domínio | RNF-004 | T014, T017, T018, T019 | T015, T017 |
| Testes | RNF-005, RNF-006, RNF-007 | T015, T079, T098 | T100, T101 |
| Pipeline | RNF-008, RNF-017 | T102, T103 | T102, T104 |
| Segurança | RNF-010, RNF-018 | T013, T019, T020, T060 | T020, T060, T109 |
| Documentação | RNF-011 | T021, T076, T105, T107, T108 | T077, T105, T108 |
| Especificação e Git | RNF-012, RNF-014, RNF-015 | T109 | T109 |
| Entrega | RNF-016, RNF-019 | T105, T107, T108 | T105, T109 |

### Fora de escopo

Cancelamento de upload, expiração automática, limpeza de partes órfãs, retomada
entre sessões, WebSocket ou SSE, APM, métricas, checksum ponta a ponta,
transcodificação e provedor externo real **não possuem tarefa de implementação**,
por decisão registrada em `plan.md` §19. Eles aparecem apenas na documentação de
limitações, produzida em T106.

---

## Definição global de pronto

Uma tarefa está pronta para revisão quando **todas** as condições abaixo valem:

1. **Spec e plano respeitados.** O comportamento implementado corresponde ao que a
   `spec.md` descreve e à forma que o `plan.md` decidiu. Se a implementação mudou
   algum comportamento descrito, a spec foi atualizada na mesma leva.
2. **Comportamento implementado por inteiro.** Nada do escopo da tarefa ficou pela
   metade sem estar dito.
3. **Testes verdes.** Os testes que a tarefa declara foram executados e passaram.
4. **Lint e análise estática verdes.** Pint e PHPStan no backend, ESLint e
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
