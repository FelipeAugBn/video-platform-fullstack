# Guia da API

Como ler, validar e executar o contrato desta API sem abrir o código.

O documento OpenAPI 3.1 está em [`openapi.yaml`](openapi.yaml) e é a referência
normativa: se este guia e ele divergirem, vale o `openapi.yaml`.

---

## Endereço local

| Item | Valor |
| --- | --- |
| API | `http://localhost:8080` |
| Interface | `http://localhost:3000` |

O ambiente sobe com `make up`, na raiz do repositório.

**O que a máquina precisa ter:** para a execução normal, **Docker Engine, Docker
Compose e GNU Make**. Os exemplos de terminal deste guia são opcionais e pedem
também um shell com `curl`, `grep` e `cut`.

Nada além disso: tudo o que exige um interpretador é executado **dentro dos
containers**. PHP, Node, Python, bancos de dados e os linters continuam
dispensados no host.

---

## Validar o contrato

    make openapi-lint

Roda o linter em container descartável, com a imagem fixada por tag **e** digest
— não é preciso ter Node nem o linter instalados. Sai com código diferente de
zero quando o contrato é inválido, e é assim que a pipeline o usa.

As regras estão em [`redocly.yaml`](redocly.yaml). Além da validação estrutural,
elas conferem **os exemplos contra os schemas** que eles ilustram: um exemplo que
contradiz o próprio schema quebraria justamente quem importasse o contrato numa
ferramenta de requisições.

---

## Importar numa ferramenta de requisições

O arquivo é um OpenAPI 3.1 de arquivo único, sem referências externas — qualquer
ferramenta compatível o lê direto do disco.

- **Postman** — *Import* → *File* → `docs/openapi.yaml`. Gera uma coleção com as
  21 operações. Deixe **Automatically follow redirects** ligado e, em
  *Settings*, mantenha o cookie jar ativo.
- **Insomnia** — *Import from File* → `docs/openapi.yaml`.
- **Bruno**, **Hoppscotch**, **Swagger UI local** — mesma coisa: importam OpenAPI
  3.1 sem conversão.

Depois de importar, aponte a variável de servidor para `http://localhost:8080`.

---

## Autenticar e preservar a sessão

A API usa **sessão em cookie `HttpOnly`**, não token no cabeçalho. Não existe
`Authorization: Bearer`.

### 1. Obter o cookie de proteção

```bash
curl -c cookies.txt -i \
  -H "Origin: http://localhost:3000" \
  http://localhost:8080/sanctum/csrf-cookie
```

Responde `204` e planta dois cookies: `XSRF-TOKEN`, legível, e o de sessão.

> **O cabeçalho `Origin` é obrigatório fora do navegador.** A sessão só é
> aplicada a requisições reconhecidas como *first-party*, e o reconhecimento
> compara o `Origin` com os domínios declarados em `SANCTUM_STATEFUL_DOMAINS`
> (`localhost:3000` no ambiente local). O navegador envia esse cabeçalho
> sozinho; `curl`, Postman e Insomnia **não**. Sem ele não há sessão, e o login
> responde `500` em vez de `200` — é a primeira pedra do caminho.
>
> Envie `Origin: http://localhost:3000` em **todas** as chamadas dos exemplos
> abaixo, inclusive nas leituras.

### 2. Autenticar

O valor de `XSRF-TOKEN` chega **codificado como URL** e precisa ser decodificado
antes de ir no cabeçalho. A decodificação usa o PHP que já roda no container da
API — nada é instalado no host:

```bash
TOKEN=$(grep XSRF-TOKEN cookies.txt | cut -f7 \
  | docker compose exec -T api php -r 'echo rawurldecode(trim(stream_get_contents(STDIN)));')

curl -b cookies.txt -c cookies.txt \
  -H "Origin: http://localhost:3000" \
  -H "Content-Type: application/json" \
  -H "X-XSRF-TOKEN: $TOKEN" \
  -d '{"email":"producer@video-platform.test","password":"VideoDemo2026!"}' \
  http://localhost:8080/api/auth/login
```

Os comandos deste guia são para copiar e colar a partir da **raiz do
repositório**, com o ambiente no ar (`make up`).

> **O login regenera a sessão — e com ela o `XSRF-TOKEN`.** Releia o cookie
> depois de autenticar. Não fazer isso produz `419` na primeira operação mutante
> seguinte, que é o erro mais comum ao percorrer o fluxo pela primeira vez.

### 3. Seguir enviando os cookies

Toda chamada posterior usa `-b cookies.txt -c cookies.txt` e o mesmo cabeçalho
`Origin`. Operações **mutantes** (`POST`) levam também `X-XSRF-TOKEN`; leituras
(`GET`) dispensam o token, mas não o `Origin`.

No Postman e no Insomnia os cookies são automáticos desde que o cookie jar
esteja ligado. `Origin` e `X-XSRF-TOKEN` precisam ser configurados à mão — vale
defini-los como cabeçalhos padrão da coleção, e atualizar o token a cada
renovação.

---

## Credenciais de demonstração

Preparadas pelo seed de avaliação (`backend/database/seeders/EvaluationSeeder.php`)
e aplicadas na subida do ambiente. São **locais e fictícias**, e as três
compartilham a mesma senha, declarada no próprio seed.

| Perfil | E-mail | Serve para |
| --- | --- | --- |
| `producer` | `producer@video-platform.test` | Jornada de gestão: criar, enviar, publicar |
| `consumer` | `consumer@video-platform.test` | Jornada de consumo: catálogo concedido e reprodução |
| `producer` | `other-producer@video-platform.test` | Comprovar o isolamento: o que ele vê não é o do primeiro |

O seed também deixa pronta uma concessão de acesso do consumidor ao curso da
jornada principal, e — num curso **separado**, dedicado à demonstração de falha —
uma tentativa de vídeo parada em `processing`.

Ela fica nesse estado porque `processing` é o único a partir do qual um callback
de falha é uma transição válida: a tentativa está pronta para **receber** o
desfecho negativo, e não já em posse dele. Quem o entrega é o comando de
demonstração, pelo endpoint HTTP real e assinado, sem que nada precise ser
quebrado à mão:

```bash
ATTEMPT_ID="01936f1a-7c00-7a3e-9b7d-2f5c8e4a1d60"
docker compose run --rm api php artisan demo:simulate-video-failure "$ATTEMPT_ID"
```

O roteiro completo, com o que observar na interface, está no
[guia de demonstração](demonstracao.md).

---

## Ordem mínima da jornada principal

A jornada integrada parte do curso **"Fundamentos de Producao de Video"**,
preparado pelo `EvaluationSeeder` e aplicado na subida do ambiente. Ele nasce
**vazio** — sem módulo e sem aula — e já vem com a concessão de acesso ao
consumidor de demonstração.

> **Por que não começar criando um curso.** `POST /api/courses` funciona e pode
> ser exercitado à parte, mas um curso recém-criado **não aparece para o
> consumidor**: o acesso depende de uma concessão, e não existe endpoint para
> administrá-la nesta entrega — a concessão é preparada por seed, por decisão
> registrada na spec. Uma jornada que criasse o curso do zero terminaria num
> catálogo vazio do lado do consumidor.

Cada passo depende do anterior. Os identificadores saem sempre da resposta do
passo que os criou.

**Como produtor**

1. `GET /sanctum/csrf-cookie`
2. `POST /api/auth/login` — produtor
3. `GET /api/courses` → localize na lista o curso **"Fundamentos de Producao de
   Video"** e guarde o `id`. É o curso concedido; os outros dois do seed servem a
   outros propósitos (isolamento e cenário de falha).
4. `GET /api/courses/{course}/structure` → num ambiente recém-semeado ele vem
   vazio: `state: "draft"` e `modules: []`. Se a jornada já tiver sido percorrida
   antes, o curso conserva o que foi criado — o seed é idempotente e não apaga
   conteúdo. Nesse caso, siga assim mesmo: os passos abaixo acrescentam um novo
   módulo, e é ele que a verificação final usa.
5. `POST /api/courses/{course}/modules` → guarde `data.id`
6. `POST /api/modules/{module}/lessons` → guarde `data.id` (nasce em rascunho, sem vídeo)
7. `POST /api/lessons/{lesson}/video/uploads` → guarde `attempt_id`, `part_size` e `part_count`
8. Para cada parte, `POST /api/video-uploads/{attempt}/parts/{n}/url` e depois um
   **`PUT` na URL devolvida**, com os bytes daquela parte — essa requisição vai
   direto ao armazenamento, e não à API. Guarde o `ETag` de cada resposta.
9. `POST /api/video-uploads/{attempt}/complete` com todos os `part_number` e
   `etag` → `202`
10. `GET /api/lessons/{lesson}/video` em intervalos até `state` chegar a `ready`
11. `POST /api/lessons/{lesson}/publish` → a aula é publicada. Se esta for a
    primeira publicação do curso, ele passa de `draft` para `available`; se já
    estiver `available` por uma execução anterior, permanece nesse estado.

**Troca de sessão**

12. `POST /api/auth/logout`
13. `GET /sanctum/csrf-cookie` — a sessão anterior foi invalidada, e o token
    precisa ser obtido de novo antes do próximo login
14. `POST /api/auth/login` — consumidor

**Como consumidor**

15. `GET /api/catalog/courses` → o curso aparece, e é o **único** da lista: os
    outros dois cursos do seed não têm concessão para este consumidor. Num
    ambiente recém-semeado ele não apareceria antes do passo 11 — a listagem só
    traz cursos em `available`, e é a primeira publicação que promove o curso.
16. `GET /api/catalog/courses/{course}` → a árvore, com o módulo e a aula
    criados nos passos 5 e 6, e sem rascunhos
17. `GET /api/lessons/{lesson}/playback` → `playback_url`, `expires_at` e
    `content_type` para a mesma aula publicada no passo 11

O `{course}` do passo 16 é o mesmo do passo 3, e o `{lesson}` do passo 17 é o
mesmo do passo 6: a jornada fecha sobre o conteúdo que o produtor acabou de
criar, e não sobre dados pré-existentes.

O roteiro completo de demonstração, com o que observar em cada tela, não é este
documento.

---

## O upload não passa pela API

**Nenhuma rota recebe os bytes do vídeo.** É o que sustenta arquivos de vários
gigabytes sem ocupar o PHP nem o servidor da interface.

O que a API faz é entregar uma **estratégia**: a abertura devolve o plano
multipart (quantas partes, de que tamanho), e cada parte é autorizada
individualmente por uma URL temporária de 15 minutos. O cliente faz `PUT` dessas
partes **direto no armazenamento** e devolve à API apenas os comprovantes.

Três detalhes que costumam custar uma sessão de depuração:

- o `etag` é repassado **exatamente como o armazenamento o devolveu, aspas
  inclusive** — removê-las quebra a conclusão;
- a chave do objeto é escolhida pelo servidor, derivada do identificador da
  tentativa; o `filename` declarado é só informação;
- a conclusão **verifica o objeto de forma independente** — tamanho, tipo e
  metadados são observados no armazenamento, não aceitos do relato do cliente.

A reprodução segue a mesma ideia na direção contrária: devolve uma URL assinada
de **cinco minutos**, e nunca o arquivo.

---

## Webhook de processamento

`POST /api/webhooks/video-processing` é chamado por um **serviço**, não por um
navegador. Não tem sessão, não tem perfil e é **explicitamente isento de CSRF**.

O que substitui a sessão são dois cabeçalhos obrigatórios:

| Cabeçalho | Conteúdo |
| --- | --- |
| `X-Webhook-Timestamp` | segundos desde a época, somente dígitos |
| `X-Webhook-Signature` | `v1=` seguido do HMAC-SHA256 em hexadecimal minúsculo |

A assinatura é calculada sobre a cadeia `timestamp + "." + corpo bruto`, com o
segredo compartilhado (`WEBHOOK_SECRET`, definido no `.env` local e **nunca**
versionado), e comparada em tempo constante. O corpo assinado é o texto exato
transmitido: reserializar o JSON antes de assinar invalida a assinatura.

O `timestamp` precisa cair numa janela de cinco minutos em relação ao relógio do
servidor, conferida nos dois sentidos.

Como responder a cada resultado:

| Status | Significado | Reenviar? |
| --- | --- | --- |
| `200` | Aplicado, ou reentrega de um evento já concluído | Não |
| `409` | Rejeição **permanente** — nunca será aplicado | Não |
| `422` | Carga malformada; nenhum `event_id` foi consumido | Só depois de corrigir |
| `401` | Assinatura ausente, inválida ou fora da janela | Só depois de corrigir |
| `503` | Falha transitória; nada foi gravado | Sim, após `Retry-After` |

O simulador embutido já assina corretamente. Para vê-lo produzir uma falha:

    docker compose run --rm api php artisan demo:simulate-video-failure {attempt}

---

## Respostas de erro

Toda falha responde `application/problem+json` conforme a RFC 9457, com esta
forma:

```json
{
  "type": "https://api.example.test/problems/lesson-video-not-ready",
  "title": "Video ainda nao esta pronto",
  "status": 409,
  "detail": "O video desta aula ainda nao esta pronto para reproducao.",
  "code": "LESSON_VIDEO_NOT_READY"
}
```

**Use `code`, não `detail`.** O primeiro é um identificador estável; o segundo é
texto para humanos e pode ser reescrito.

| Status | Quando acontece |
| --- | --- |
| `401` | Sem sessão válida — reconduz à autenticação |
| `403` | Autenticado, **perfil errado para a rota** |
| `404` | Inexistente, de outro produtor, ou curso sem concessão |
| `405` | O endereço existe mas não aceita o método; `Allow` lista os aceitos |
| `409` | Conflito de regra — o `code` diz qual condição não foi satisfeita |
| `419` | Token CSRF ausente ou inválido; **a operação não foi executada** |
| `422` | Validação de entrada, com detalhamento por campo em `errors` |
| `500` | Falha inesperada; nada do erro original atravessa |
| `503` | Indisponibilidade transitória; a operação pode ser repetida |

Duas distinções valem ser lidas com atenção, porque são decisões e não acidentes:

**`403` não é `404`.** O perfil errado responde `403` e não revela recurso
nenhum. Já propriedade e concessão falham com `404` — recurso inexistente,
recurso de outro produtor e curso sem concessão devolvem **exatamente a mesma
resposta**. Comparar as respostas não permite descobrir o que existe.

**`419` não é `401`.** A sessão continua válida; o que faltou foi o token. O
tratamento correto é reler o cookie `XSRF-TOKEN` e repetir a requisição **uma
vez** — e não mandar o usuário autenticar de novo.
