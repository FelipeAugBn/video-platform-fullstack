# Especificação Funcional — Plataforma de Conteúdo em Vídeo

**Feature:** 001-video-platform
**Estado:** aprovada como baseline funcional. Evolui apenas por mudança
explicitamente revisada e aprovada, mantida coerente com `plan.md` e com os testes
**Escopo deste documento:** comportamento do produto integrado (backend + frontend)

---

## 1. Contexto e objetivo

Uma plataforma de conteúdo digital, no formato de marketplace de cursos online.
Produtores criam cursos, organizam módulos e aulas, enviam vídeos e disponibilizam
o conteúdo para usuários autorizados. Consumidores autenticados navegam pela
estrutura publicada e solicitam a reprodução das aulas às quais têm acesso.

O objetivo da entrega é um recorte funcional, consistente e demonstrável das
jornadas descritas — não uma plataforma comercial completa. A avaliação percorre
a jornada inteira pela interface Nuxt, do gerenciamento do conteúdo até seu
consumo, com integração real contra a API Laravel.

Este documento descreve **o que** o produto faz e sob quais regras. As escolhas de
**como** implementar pertencem ao `plan.md` e estão listadas na seção 17.

---

## 2. Fonte oficial e classificação dos requisitos

A fonte primária de requisitos é `private/desafio-tecnico-fullstack-auge.md`,
transcrição do desafio fornecido pela empresa. O PDF original é a fonte
documental definitiva em caso de divergência na transcrição.

Cada item deste documento carrega uma classificação explícita, porque tratar
diferencial como obrigação distorce a prioridade da entrega:

| Marca | Significado |
| --- | --- |
| `[OBRIGATÓRIO]` | Exigido textualmente pelo desafio |
| `[DECISÃO]` | Decisão funcional adotada pelo projeto dentro do espaço aberto pelo desafio |
| `[ABERTO]` | Decisão técnica ainda não tomada; pertence ao `plan.md` |
| `[RESOLVIDO]` | Decisão técnica que esteve aberta e foi fixada no `plan.md` |
| `[DIFERENCIAL]` | Listado pelo desafio como diferencial, não como requisito mínimo |
| `[OPCIONAL]` | Listado pelo desafio como escopo opcional |
| `[FORA]` | Explicitamente não obrigatório |

Não há decisões técnicas ainda abertas nesta feature: as catorze levantadas como
`ABERTO-001` a `ABERTO-014` foram decididas e estão registradas na seção 17.
`[ABERTO]` permanece na tabela porque é um estado válido do ciclo documental — uma
abertura futura será marcada assim antes de ser resolvida.

Uma regra `[DECISÃO]` **não é opcional**. A marca indica a origem da regra, não
seu peso: depois de adotada, ela precisa ser implementada e testada como qualquer
`[OBRIGATÓRIO]`. Quando uma regra mistura uma obrigação da empresa com uma
escolha deste projeto, ela aparece separada em duas regras, para que a revisão
consiga distinguir o que foi exigido do que foi decidido.

### 2.1 Stack — o que é imposto e o que não é

`[OBRIGATÓRIO]` PHP 8.4 ou 8.5, Laravel 13, API REST/JSON, DDD e Arquitetura
Hexagonal no backend. Nuxt 4, Vue 3, TypeScript e Composition API no frontend,
consumindo a API real. MySQL 8 com migrations. Testes automatizados nas duas
camadas. Pipeline automatizada. Histórico Git preservado.

`[DECISÃO]` `[DIFERENCIAL]` Docker Compose para tornar o ambiente reproduzível.
O desafio exige um ambiente reproduzível **ou** um ambiente público funcional;
Docker é o meio escolhido por este projeto e aparece na lista de diferenciais,
não na de requisitos mínimos.

`[RESOLVIDO]` Estratégia de autenticação, formato dos contratos, estratégia de
renderização do Nuxt, organização de estado, biblioteca de UI, mecanismo de
storage, mecanismo de fila e simulador de processamento. O desafio deixa todos
esses pontos a critério do candidato, exigindo apenas que sejam documentados.
Todos foram decididos e justificados no `plan.md`; a seção 17 lista cada um com
sua seção de destino.

Nenhuma tecnologia específica de storage, cache ou fila é requisito. MinIO,
Redis, Sanctum, JWT e equivalentes **não** são exigidos pelo desafio e não
aparecem neste documento como requisitos.

---

## 3. Escopo da entrega

### 3.1 Dentro do escopo

`[OBRIGATÓRIO]` Gestão de cursos, módulos e aulas pelo produtor, com isolamento
por propriedade. Consulta da estrutura ordenada do curso. Início e conclusão do
envio de vídeo sem trafegar o arquivo pela aplicação. Processamento assíncrono
conceitual com sucesso e falha reproduzíveis. Callback de processamento
idempotente. Publicação condicionada de aulas. Reprodução autorizada pelo
consumidor. Interface Nuxt cobrindo as duas jornadas. Testes nas duas camadas
mais ao menos uma jornada integrada. Documentação de execução, de API e roteiro
de demonstração.

### 3.2 Fronteira

A entrega é um recorte demonstrável. Não há transcodificação real de vídeo, não
há serviço externo real de processamento e não há operação de produção. Os dados
são fictícios e preparados por seed.

---

## 4. Atores e perfis

| Ator | Responsabilidades |
| --- | --- |
| **Produtor** `[OBRIGATÓRIO]` | Gerencia os próprios cursos, módulos e aulas; inicia o envio de vídeo; acompanha o processamento; publica conteúdo elegível |
| **Consumidor** `[OBRIGATÓRIO]` | Visualiza cursos autorizados; navega pela estrutura; solicita a reprodução de aulas disponíveis |
| **Serviço de processamento** `[OBRIGATÓRIO]` | Ator externo simulado; consome o vídeo enviado e devolve o resultado por callback |

`[DECISÃO]` Um usuário tem exatamente um perfil nesta entrega. O desafio não pede
acúmulo de papéis, e permitir que um produtor também consuma cursos de terceiros
ampliaria a matriz de autorização sem benefício demonstrável.

`[DECISÃO]` As credenciais de avaliação e as concessões de acesso do consumidor
são preparadas por seed. O desafio permite isso explicitamente e dispensa
cadastro público.

`[DECISÃO]` O seed prepara, no mínimo:

- um produtor de demonstração;
- um consumidor de demonstração;
- um curso vazio pertencente ao produtor de demonstração, em `draft` por ainda
  não possuir aula publicada;
- uma concessão de acesso do consumidor de demonstração a esse curso.

Não existe interface nem operação de API para administrar concessões de acesso
nesta entrega. O desafio não pede gestão de matrículas, e criar esse fluxo
ampliaria o escopo sem servir a nenhuma das duas jornadas exigidas. A consequência
é que a jornada demonstrável parte de um curso já autorizado — ver AC-E2E-001.

---

## 5. Modelo conceitual do domínio

```
Produtor ─── possui ──▶ Curso ─── contém ──▶ Módulo ─── contém ──▶ Aula
                          │                                          │
                          │                                          └── tem ──▶ Vídeo
                          │
                          └── concede acesso a ──▶ Consumidor
```

| Conceito | Descrição funcional |
| --- | --- |
| **Curso** `[OBRIGATÓRIO]` | Unidade de conteúdo de um produtor. Possui identificador, título, descrição, proprietário, estado e data de criação |
| **Módulo** `[OBRIGATÓRIO]` | Agrupamento ordenado de aulas dentro de um curso |
| **Aula** `[OBRIGATÓRIO]` | Unidade de consumo, ordenada dentro de um módulo. Pode ter um vídeo associado e um estado de publicação |
| **Vídeo** `[OBRIGATÓRIO]` | Mídia associada a uma aula, com ciclo de vida próprio |
| **Concessão de acesso** `[DECISÃO]` | Vínculo que autoriza um consumidor a acessar um curso |

`[DECISÃO]` A concessão de acesso é modelada como conceito explícito do domínio.
O desafio exige que o consumidor veja apenas cursos autorizados (§4, §10) mas não
nomeia o mecanismo. Sem um conceito explícito, "autorizado" viraria uma regra
implícita espalhada por consultas, e a autorização deixaria de ser testável de
forma isolada.

`[DECISÃO]` Nesta entrega, concessões de acesso são criadas exclusivamente por
seed. Não há operação de criação, revogação ou listagem de concessões.

`[RESOLVIDO]` `Course`, `Module`, `Lesson` e `VideoAttempt` são agregados
separados, cada um com sua própria raiz; `AccessGrant` é vínculo de autorização
imutável e `WebhookEvent` é registro de idempotência. A coordenação entre
agregados pertence aos casos de uso, com lock e transação explícitos. Modelagem,
value objects e limites transacionais em `plan.md` §§5, 6, 7.4 e 8 (ABERTO-011).

---

## 6. Regras de negócio

### 6.1 Propriedade e isolamento

- **RN-PROP-001** `[OBRIGATÓRIO]` Todo curso pertence a exatamente um produtor.
- **RN-PROP-002** `[OBRIGATÓRIO]` Um produtor só consulta e gerencia cursos,
  módulos e aulas dos quais é proprietário.
- **RN-PROP-003** `[OBRIGATÓRIO]` A tentativa de consultar ou gerenciar conteúdo
  privado pertencente a outro produtor é negada e não retorna o conteúdo do
  recurso.
- **RN-PROP-004** `[OBRIGATÓRIO]` Módulos e aulas herdam a propriedade do curso.
- **RN-PROP-005** `[DECISÃO]` Para recursos pertencentes a outro produtor, a
  resposta não permite distinguir um recurso existente de um recurso inexistente,
  evitando revelar sua existência.

O desafio exige a negação (§5.5) mas não determina se a resposta pode admitir que
o recurso existe. Ocultar a existência é decisão deste projeto: uma resposta que
diferencia "existe mas não é seu" de "não existe" transforma a API em um oráculo
de enumeração de identificadores alheios. A forma concreta dessa resposta — qual
status HTTP, qual corpo — está definida no contrato de erros do `plan.md` §10
(ABERTO-010).

### 6.2 Ordenação

- **RN-ORD-001** `[OBRIGATÓRIO]` Módulos têm ordem definida dentro do curso;
  aulas têm ordem definida dentro do módulo.
- **RN-ORD-002** `[DECISÃO]` A posição é atribuída pelo backend: cada módulo
  nasce na próxima posição livre do curso e cada aula na próxima posição livre do
  módulo. A ordem corresponde à ordem de criação, e o cliente não informa posição.
- **RN-ORD-003** `[DECISÃO]` A sequência resultante nunca contém posições
  duplicadas nem ordem ambígua. Não existe inserção em posição já ocupada e,
  portanto, nenhum item já criado é deslocado.
- **RN-ORD-004** `[DECISÃO]` A ordem é total e determinística: duas leituras
  consecutivas sem escrita retornam a mesma sequência.

O desafio exige definir e preservar a ordem (§5.2, §5.3); não exige escolher o
ponto de inserção. Atribuir a próxima posição cumpre o requisito com uma regra
que cabe em uma frase, enquanto a inserção arbitrária acrescentaria reescrita em
massa das posições seguintes, uma janela de concorrência própria e um vocabulário
de erro adicional — sem tornar possível nenhuma jornada exigida. A posição
continua persistida e é ela que ordena todas as consultas. Reordenação permanece
fora de escopo (RF-MOD-005).

### 6.3 Estado do curso

- **RN-CUR-001** `[DECISÃO]` Um curso está em `draft` enquanto não possuir
  nenhuma aula publicada.
- **RN-CUR-002** `[DECISÃO]` Um curso passa a `available` quando sua primeira
  aula é publicada.
- **RN-CUR-003** `[DECISÃO]` Não existe operação separada de publicação de curso
  nesta fase. O estado do curso é consequência da publicação de aulas.

O desafio exige que o curso possua um estado (§5.1) mas não define quais estados
nem sua semântica. `draft`/`available` derivado da publicação de aulas é uma
decisão deste projeto: evita um segundo fluxo de publicação sem requisito e
elimina o estado inconsistente de um curso anunciado como disponível sem nenhuma
aula assistível.

### 6.4 Publicação de aula

- **RN-PUB-001** `[OBRIGATÓRIO]` Apenas o produtor proprietário publica a aula.
- **RN-PUB-002** `[DECISÃO]` Uma aula só pode ser publicada quando possui vídeo
  atual no estado `ready`.
- **RN-PUB-003** `[DECISÃO]` A referência de reprodução do vídeo precisa existir
  no momento da publicação.
- **RN-PUB-004** `[OBRIGATÓRIO]` Tentativa de publicar aula inelegível é
  rejeitada com resposta compreensível para o frontend, indicando a condição não
  satisfeita.
- **RN-PUB-005** `[DECISÃO]` Publicar uma aula já publicada não produz efeito
  adicional nem erro: a operação é idempotente.
- **RN-PUB-006** `[DECISÃO]` A publicação é uma ação explícita do produtor. Uma
  aula nunca é publicada automaticamente ao ficar elegível.

### 6.5 Autorização de consumo

- **RN-AUT-001** `[OBRIGATÓRIO]` Operações de gestão exigem produtor autenticado
  e operações de consumo exigem consumidor autenticado. O callback de
  processamento não representa um usuário e não é abrangido por esta regra: sua
  legitimidade é estabelecida por validação de origem (RF-WHK-001). Endpoints
  operacionais eventualmente públicos, como verificação de saúde, também ficam
  fora do alcance desta regra. A estratégia técnica de autenticação está definida
  em `plan.md` §9 (ABERTO-001).
- **RN-AUT-002** `[OBRIGATÓRIO]` O backend é a autoridade de autorização. A
  interface pode ocultar ações indisponíveis, mas isso nunca constitui proteção.
- **RN-AUT-003** `[OBRIGATÓRIO]` Um consumidor só acessa cursos para os quais
  possui concessão de acesso.
- **RN-AUT-004** `[DECISÃO]` O consumidor enxerga apenas aulas publicadas.
  Rascunhos não aparecem em sua navegação nem são contados na estrutura.
- **RN-AUT-005** `[OBRIGATÓRIO]` Respostas de erro não expõem segredos, rastros
  de execução, mensagens de exceção nem quaisquer detalhes internos da solução.
- **RN-AUT-006** `[DECISÃO]` Recursos privados de terceiros são tratados de forma
  a não revelar sua existência, pelo mesmo motivo registrado em RN-PROP-005.

### 6.6 Idempotência

- **RN-IDM-001** `[OBRIGATÓRIO]` Conclusão de upload repetida para o mesmo vídeo
  não inicia processamentos duplicados.
- **RN-IDM-002** `[OBRIGATÓRIO]` O `event_id` é a única chave de idempotência do
  callback. O primeiro desfecho definitivo de um `event_id` vence, e qualquer
  reentrega dele é reconhecida sem produzir efeito adicional.
- **RN-IDM-003** `[DECISÃO]` A reentrega de um `event_id` já concluído repete o
  desfecho armazenado. O corpo da reentrega não é usado para alterar, reavaliar
  ou sobrescrever o evento já concluído.
- **RN-IDM-004** `[OBRIGATÓRIO]` Publicação repetida da mesma aula não produz
  efeitos duplicados.

O desafio exige idempotência (§7.2, §10) mas não define como comparar duas
entregas do mesmo `event_id`. Este projeto não as compara: o identificador do
evento é a chave, e o desfecho registrado na primeira conclusão é o que toda
reentrega recebe. Comparar o conteúdo exigiria uma normalização canônica da carga
e um vocabulário de conflito próprio para proteger contra um emissor que muda o
significado de um evento já entregue — cenário que o desafio não descreve e que o
simulador desta entrega não produz.

---

## 7. Jornada do produtor

`[OBRIGATÓRIO]` O produtor autentica-se com um usuário de avaliação, visualiza e
cria cursos, abre o detalhe de um curso e sua estrutura, cria módulos e aulas,
inicia e acompanha o envio de um vídeo, visualiza o estado atual do vídeo e
eventuais falhas, e publica uma aula quando as regras permitirem.

### 7.1 Cursos

- **RF-CUR-001** `[OBRIGATÓRIO]` O produtor cria um curso informando título e
  descrição. O curso nasce com ele como proprietário, estado `draft` e data de
  criação registrada.
- **RF-CUR-002** `[OBRIGATÓRIO]` O produtor lista seus cursos. A lista contém
  apenas cursos dos quais é proprietário.
- **RF-CUR-003** `[OBRIGATÓRIO]` O produtor consulta os detalhes de um curso
  próprio: identificador, título, descrição, proprietário, estado e data de
  criação.
- **RF-CUR-004** `[OBRIGATÓRIO]` A consulta a curso de outro produtor é negada.
- **RF-CUR-005** `[DECISÃO]` Atualização e exclusão de cursos não fazem parte do
  escopo obrigatório. O desafio pede criar, listar e visualizar (§5.1); introduzir
  CRUD completo sem requisito adicionaria superfície e regras de invalidação
  (o que acontece com uma aula publicada de um curso excluído?) sem benefício
  para a avaliação.

### 7.2 Módulos

- **RF-MOD-001** `[OBRIGATÓRIO]` Um curso comporta múltiplos módulos.
- **RF-MOD-002** `[OBRIGATÓRIO]` O produtor cria um módulo em um curso próprio.
- **RF-MOD-006** `[DECISÃO]` O título é o campo funcional mínimo do módulo. O
  desafio exige a operação de criação e a preservação da ordem (§5.2), mas não
  determina os campos do recurso.
- **RF-MOD-007** `[DECISÃO]` A posição do módulo é atribuída pelo backend na
  criação, conforme RN-ORD-002. O corpo da requisição não a contém.
- **RF-MOD-003** `[OBRIGATÓRIO]` O produtor lista os módulos de um curso próprio
  na ordem definida.
- **RF-MOD-004** `[OBRIGATÓRIO]` Criar módulo em curso de outro produtor é negado.
- **RF-MOD-005** `[OPCIONAL]` Reordenação por arrastar e soltar.

### 7.3 Aulas

- **RF-AUL-001** `[OBRIGATÓRIO]` Um módulo comporta múltiplas aulas.
- **RF-AUL-002** `[OBRIGATÓRIO]` O produtor cria uma aula em um módulo próprio.
- **RF-AUL-008** `[DECISÃO]` O título é o campo funcional mínimo da aula, pela
  mesma razão registrada em RF-MOD-006 — o desafio exige a operação (§5.3) sem
  determinar os campos.
- **RF-AUL-009** `[DECISÃO]` A posição da aula é atribuída pelo backend na
  criação, conforme RN-ORD-002. O corpo da requisição não a contém.
- **RF-AUL-003** `[OBRIGATÓRIO]` O produtor consulta e lista aulas dos próprios
  cursos, na ordem definida.
- **RF-AUL-004** `[DECISÃO]` Uma aula nasce como rascunho, sem vídeo associado.
- **RF-AUL-005** `[DECISÃO]` Uma aula possui no máximo um vídeo atual.
- **RF-AUL-006** `[OBRIGATÓRIO]` A publicação é uma ação explícita e separada da
  criação.
- **RF-AUL-007** `[DECISÃO]` Não há CRUD adicional de aulas sem requisito, pela
  mesma razão de RF-CUR-005.

### 7.4 Estrutura do curso

- **RF-EST-001** `[OBRIGATÓRIO]` Existe uma operação que retorna o curso, seus
  módulos e suas aulas de forma organizada.
- **RF-EST-002** `[OBRIGATÓRIO]` A ordem definida de módulos e aulas é preservada
  no retorno.
- **RF-EST-003** `[OBRIGATÓRIO]` A estrutura nunca expõe conteúdo privado de
  outro produtor.
- **RF-EST-004** `[DECISÃO]` A estrutura vista pelo produtor inclui aulas em
  rascunho e o estado do vídeo de cada aula, porque a jornada exige acompanhar o
  processamento. A estrutura vista pelo consumidor inclui apenas aulas
  publicadas, conforme RN-AUT-004.

---

## 8. Jornada do consumidor

`[OBRIGATÓRIO]` O consumidor autentica-se com um usuário autorizado, visualiza ao
menos um curso disponível, navega por módulos e aulas na ordem definida, abre uma
aula publicada e solicita os dados de reprodução, e recebe um estado claro quando
o conteúdo não estiver disponível ou autorizado.

- **RF-CONS-001** `[OBRIGATÓRIO]` O consumidor lista os cursos aos quais tem
  acesso concedido.
- **RF-CONS-002** `[DECISÃO]` A listagem do consumidor traz apenas cursos em
  `available`. Um curso autorizado mas sem nenhuma aula publicada não oferece
  nada a consumir, e exibi-lo produziria uma tela vazia sem explicação.
- **RF-CONS-003** `[OBRIGATÓRIO]` O consumidor navega pela estrutura do curso
  autorizado, na ordem definida, vendo somente aulas publicadas.
- **RF-CONS-004** `[OBRIGATÓRIO]` O consumidor abre uma aula publicada e solicita
  seus dados de reprodução.
- **RF-CONS-005** `[OBRIGATÓRIO]` Curso não autorizado não aparece na listagem e
  seu acesso direto é negado com estado claro na interface.
- **RF-CONS-006** `[DECISÃO]` A concessão de acesso usada na demonstração é a
  preparada por seed. O consumidor não solicita acesso e o produtor não concede
  acesso pela interface.

---

## 9. Upload e processamento de vídeo

### 9.1 Condições

`[OBRIGATÓRIO]` O arquivo pode ter vários gigabytes. A transferência não pode
depender do envio integral do arquivo através da requisição convencional da
aplicação Laravel nem do servidor Nuxt.

### 9.2 Iniciar envio

- **RF-UPL-001** `[OBRIGATÓRIO]` O produtor inicia o envio de um vídeo para uma
  aula informando a aula, o nome do arquivo, o tipo de conteúdo e o tamanho.
- **RF-UPL-002** `[OBRIGATÓRIO]` Somente o produtor proprietário da aula inicia
  um envio para ela.
- **RF-UPL-003** `[OBRIGATÓRIO]` A resposta fornece ao cliente os dados
  necessários para realizar a transferência direta, sem passar pela aplicação.
- **RF-UPL-004** `[DECISÃO]` Iniciar o envio cria ou substitui o vídeo atual da
  aula, que passa a `pending`.
- **RF-UPL-005** `[DECISÃO]` Um novo envio pode substituir uma tentativa anterior
  que terminou em `failed`, criando uma nova tentativa em `pending`.
- **RF-UPL-011** `[DECISÃO]` Um novo envio é rejeitado enquanto o vídeo atual da
  aula estiver em `pending`, `uploading`, `uploaded` ou `processing`. A rejeição
  informa o estado que impede o novo envio.
- **RF-UPL-012** `[DECISÃO]` Substituir um vídeo já em `ready` não faz parte do
  escopo desta entrega.

Não existe operação de descarte, cancelamento ou expiração de tentativas nesta
entrega — nenhuma delas é exigida pelo desafio. A consequência assumida é que uma
tentativa que ficar presa em `uploading`, porque o cliente nunca solicitou a
conclusão, bloqueia novos envios para aquela aula.

A estratégia foi decidida em ABERTO-014: a recuperação se limita à tentativa
atual, com reenvio de parte e renovação de URL. Cancelamento, descarte, expiração
automática e retomada entre sessões ficaram documentados como limitação
consciente do MVP, em `plan.md` §§11.2 e 19.1.
- **RF-UPL-006** `[DECISÃO]` O tipo de conteúdo e o tamanho declarados são
  validados na abertura do envio. Rejeitar cedo evita gastar uma transferência de
  gigabytes para descobrir no fim que o arquivo não era aceitável.

### 9.3 Concluir envio

- **RF-UPL-007** `[OBRIGATÓRIO]` Após a transferência, o cliente solicita a
  conclusão da etapa.
- **RF-UPL-008** `[OBRIGATÓRIO]` A aplicação não confia exclusivamente na
  declaração do cliente como prova de que o objeto está disponível e válido. A
  existência e os metadados básicos do objeto são verificados pelo backend.
- **RF-UPL-009** `[OBRIGATÓRIO]` Quando a verificação não confirma o objeto, o
  vídeo não avança para `uploaded` e a falha é comunicada de forma compreensível.

  **Não avançar e encerrar a tentativa são desfechos diferentes**, e o que os
  separa é a qualidade da evidência:

  - o armazenamento **confirma de forma confiável** que o objeto está ausente ou
    incompatível com o declarado → a tentativa passa para `failed` (RF-UPL-013);
  - a aplicação **não consegue avaliar** o objeto — indisponibilidade, falha de
    rede, credencial, permissão, assinatura, configuração incorreta ou resposta
    que ela não reconheça com segurança → a tentativa **permanece em
    `uploading`** e a API comunica falha de infraestrutura, não falha do vídeo.

  Nenhuma dessas segundas situações é prova sobre o arquivo do produtor. Tratá-las
  como se fossem faria um problema do ambiente destruir um envio legítimo de
  vários gigabytes, e o produtor pagaria por uma falha que não é dele.
- **RF-UPL-013** `[DECISÃO]` Quando a conclusão é solicitada e o armazenamento
  confirma de forma confiável que o objeto está ausente ou incompatível com o que
  foi declarado, a tentativa passa para `failed`. Encerrar a tentativa em vez de
  deixá-la pendente é o que permite ao produtor iniciar um novo envio pela regra
  RF-UPL-005, sem depender de uma operação de descarte.

  A confirmação confiável é condição, e não formalidade: sem ela vale a segunda
  regra de RF-UPL-009, e a tentativa continua em `uploading` aguardando nova
  solicitação de conclusão.
- **RF-UPL-010** `[OBRIGATÓRIO]` Conclusão repetida do mesmo envio não inicia
  processamentos duplicados (RN-IDM-001).

`[RESOLVIDO]` Storage de objetos compatível com S3 e upload multipart direto do
navegador, com URLs pré-assinadas por parte emitidas pelo backend; a conclusão é
verificada no servidor antes de qualquer transição de estado. Parâmetros e fluxo
em `plan.md` §11 (ABERTO-002); verificação em §12 (ABERTO-003).

### 9.4 Ciclo de vida do vídeo

```
pending ──▶ uploading ──▶ uploaded ──▶ processing ──▶ ready
                                                  └─▶ failed
```

- **RF-VID-001** `[OBRIGATÓRIO]` O sistema representa explicitamente o ciclo de
  vida do vídeo e impede transições incompatíveis com as regras definidas.

Transições permitidas `[DECISÃO]`:

| De | Para | Gatilho |
| --- | --- | --- |
| `pending` | `uploading` | Início da transferência pelo cliente |
| `uploading` | `uploaded` | Conclusão verificada pelo backend |
| `uploading` | `failed` | Conclusão solicitada e armazenamento confirmando de forma confiável que o objeto está ausente ou incompatível (RF-UPL-013) |
| `uploaded` | `processing` | Início do processamento assíncrono |
| `processing` | `ready` | Callback de sucesso com referência de reprodução |
| `processing` | `failed` | Callback de falha |

- **RN-VID-001** `[DECISÃO]` Qualquer transição fora da tabela é inválida e
  rejeitada, incluindo saltos (`pending` para `ready`) e regressões (`ready` para
  `processing`).
- **RN-VID-002** `[DECISÃO]` `ready` e `failed` são estados finais para aquela
  tentativa de envio. Sair de `failed` exige um novo envio (RF-UPL-005), que
  produz uma nova tentativa em `pending`. `ready` não é substituível nesta
  entrega (RF-UPL-012).
- **RN-VID-005** `[DECISÃO]` Enquanto o cliente não solicitar a conclusão, o
  backend pode manter a tentativa em `uploading` por tempo indeterminado. A
  ausência de expiração automática é limitação assumida do MVP, decidida em
  ABERTO-014 e registrada em `plan.md` §19.1.
- **RN-VID-003** `[DECISÃO]` Repetir a operação que produz uma transição já
  ocorrida é reconhecido sem erro e sem efeito adicional. Repetir uma operação
  incompatível com o estado atual é rejeitado.
- **RN-VID-004** `[OBRIGATÓRIO]` Um callback repetido, atrasado ou fora da ordem
  esperada não pode corromper nem regredir o estado atual do vídeo. O desafio
  exige considerar essas entregas (§7.2, §10), o que torna a proteção obrigatória.
- **RN-VID-006** `[DECISÃO]` Um evento novo incompatível com o estado atual —
  seja porque a tentativa referenciada não existe, seja porque o estado não admite
  a transição pedida, como um callback de falha para um vídeo já em `ready` ou um
  callback `ready` para um vídeo ainda em `uploaded` — preserva o estado, não
  provoca regressão e é registrado como rejeitado em definitivo. A resposta indica
  resultado permanente, para que o emissor não continue reentregando um evento que
  nunca será aceito.
- **RN-VID-007** `[DECISÃO]` Uma falha transitória de banco ou de infraestrutura
  durante o processamento do callback não produz desfecho: a transação é desfeita,
  nada é registrado e a resposta indica falha temporária. A reentrega posterior do
  mesmo `event_id` é avaliada normalmente, do zero.

A distinção entre RN-VID-006 e RN-VID-007 é a distinção entre "este evento nunca
será aceito" e "este evento não pôde ser avaliado agora". Confundir as duas custa
caro nos dois sentidos: responder falha temporária a um evento incompatível
condena o emissor a reentregar para sempre algo que jamais será aceito, e
registrar um desfecho permanente diante de uma indisponibilidade momentânea
descarta um evento legítimo por causa de um problema que se resolveria repetindo
a chamada.

Os desfechos possíveis na avaliação de um callback:

| Situação | Efeito no vídeo | Registro do evento | Resposta ao emissor |
| --- | --- | --- | --- |
| `event_id` já concluído, em qualquer reentrega | Nenhum | Desfecho anterior preservado | Repete o desfecho anterior (RN-IDM-002, RN-IDM-003, RN-VID-008) |
| Evento novo, válido para o estado atual | Aplicado atomicamente | Aceito | Aceito |
| Evento novo, tentativa inexistente ou estado incompatível | Preservado | Rejeitado em definitivo | Permanente (RN-VID-006) |
| Falha transitória ao avaliar o evento | Preservado | Nada registrado | Temporária, admite nova tentativa (RN-VID-007) |

- **RN-VID-008** `[DECISÃO]` O registro de um evento guarda o desfecho que ele
  teve, não apenas o fato de ter sido visto. A reentrega de um `event_id` repete o
  desfecho definitivo anteriormente registrado: eventos aceitos continuam aceitos
  sem novo efeito, e eventos rejeitados em definitivo recebem novamente a resposta
  permanente. Um evento que terminou em falha transitória não deixou desfecho
  registrado, e por isso sua reentrega é avaliada do zero.

`[RESOLVIDO]` A resposta distingue os três desfechos por status HTTP, com corpo
em formato de problema estruturado. Contrato completo em `plan.md` §10 e o
mapeamento em §13.4 (ABERTO-010).
- **RF-VID-002** `[OBRIGATÓRIO]` O estado atual do vídeo e a informação de falha,
  quando houver, são visíveis para o produtor na interface.

### 9.5 Processamento

- **RF-PROC-001** `[OBRIGATÓRIO]` Após a conclusão válida do envio, o vídeo passa
  por uma etapa conceitual de processamento.
- **RF-PROC-002** `[OBRIGATÓRIO]` O processamento ocorre de forma assíncrona. A
  requisição HTTP que o dispara não aguarda sua conclusão.
- **RF-PROC-003** `[OBRIGATÓRIO]` A integração com o serviço de processamento é
  representada por um contrato substituível, permitindo evolução futura para um
  provedor real sem reescrever o domínio.
- **RF-PROC-004** `[OBRIGATÓRIO]` Sucesso e falha do processamento são
  reproduzíveis localmente.
- **RF-PROC-006** `[DECISÃO]` O simulador responde de forma determinística: o
  mesmo cenário acionado duas vezes produz o mesmo desfecho. Sem isso, os testes
  de falha de processamento ficariam intermitentes e a demonstração deixaria de
  ser confiável.
- **RF-PROC-005** `[OBRIGATÓRIO]` Não há transcodificação real de vídeo.

`[RESOLVIDO]` Fila persistida no próprio banco obrigatório, consumida por
workers em processos separados, e um simulador isolado que representa o provedor
externo chamando o callback real. Detalhes em `plan.md` §§13.1 e 13.2
(ABERTO-004) e §13.3 (ABERTO-005).

### 9.6 Callback de processamento

`[OBRIGATÓRIO]` O endpoint oficial é `POST /api/webhooks/video-processing`, com
carga contendo `event_id`, `video_id`, `status` e `playback_reference`.

- **RF-WHK-001** `[OBRIGATÓRIO]` A origem do callback é validada. Requisições sem
  origem legítima são recusadas sem produzir efeito.
- **RF-WHK-002** `[OBRIGATÓRIO]` O `event_id` identifica o evento para fins de
  idempotência.
- **RF-WHK-003** `[OBRIGATÓRIO]` O mesmo `event_id` reentregue é reconhecido e
  não duplica efeitos (RN-IDM-002).
- **RF-WHK-004** `[DECISÃO]` A reentrega de um `event_id` já concluído repete o
  desfecho armazenado, qualquer que seja o corpo recebido (RN-IDM-003).
- **RF-WHK-005** `[OBRIGATÓRIO]` Callbacks podem chegar repetidos, atrasados ou
  fora da ordem esperada, e nenhum deles regride o vídeo (RN-VID-004). Um evento
  novo incompatível com o estado atual é rejeitado em definitivo (RN-VID-006).
- **RF-WHK-006** `[OBRIGATÓRIO]` Um callback de sucesso registra a referência de
  reprodução e leva o vídeo a `ready`.
- **RF-WHK-007** `[OBRIGATÓRIO]` Um callback de falha leva o vídeo de
  `processing` para `failed`.
- **RF-WHK-010** `[OBRIGATÓRIO]` A falha fica visível para o produtor com uma
  informação compreensível sobre ela, conforme RF-VID-002 e RF-UI-007.
- **RF-WHK-011** `[DECISÃO]` O backend mantém, para o vídeo em `failed`, um
  código ou mensagem pública e segura, adequada para exibição. Quando o provedor
  não informar um motivo, uma mensagem genérica segura é utilizada. Em nenhum
  caso a mensagem expõe detalhes internos (RN-AUT-005).

A carga oficial do callback apresentada no desafio (§7.2) traz `event_id`,
`video_id`, `status` e `playback_reference`, sem campo para o motivo da falha.
Este documento não altera esse payload — e a decisão de ABERTO-005 preservou essa
carga: a mensagem é derivada internamente pelo backend, sem campo adicional. Ver
`plan.md` §13.4.
- **RF-WHK-008** `[OBRIGATÓRIO]` Um evento de falha pode ser simulado para
  demonstração.
- **RF-WHK-009** `[DECISÃO]` O callback tolera novas tentativas de entrega pelo
  emissor. A resposta permite distinguir três desfechos funcionais: **aceito**;
  **rejeição permanente**, que não deve ser reentregue; e **falha temporária de
  infraestrutura**, que admite nova tentativa. O desafio pede considerar novas
  tentativas de entrega, mas não define esse vocabulário de resposta; é uma
  escolha de resiliência deste projeto, porque um emissor que não distingue
  rejeição permanente de falha temporária reentrega indefinidamente um evento que
  nunca será aceito.

`[RESOLVIDO]` Assinatura HMAC sobre timestamp e corpo bruto, com segredo
compartilhado, comparação em tempo constante e janela de validade. Mecanismo e
headers em `plan.md` §13.4 (ABERTO-006).

---

## 10. Publicação e reprodução

### 10.1 Publicação

- **RF-PUB-001** `[OBRIGATÓRIO]` O produtor proprietário publica uma aula que
  cumpre as condições de RN-PUB-002 e RN-PUB-003.
- **RF-PUB-002** `[OBRIGATÓRIO]` Tentativa de publicação antes do vídeo estar
  pronto é rejeitada com erro funcional que informa a condição não satisfeita,
  em formato consumível pelo frontend.
- **RF-PUB-003** `[DECISÃO]` Publicar a primeira aula de um curso leva o curso de
  `draft` para `available` (RN-CUR-002).

### 10.2 Reprodução

`[OBRIGATÓRIO]` O endpoint oficial é `GET /api/lessons/{lesson}/playback`.

- **RF-PLB-001** `[OBRIGATÓRIO]` A reprodução exige consumidor autenticado.
- **RF-PLB-002** `[OBRIGATÓRIO]` A reprodução exige concessão de acesso ao curso
  ao qual a aula pertence.
- **RF-PLB-003** `[OBRIGATÓRIO]` A reprodução exige que a aula esteja publicada.
- **RF-PLB-004** `[OBRIGATÓRIO]` A reprodução exige que o vídeo esteja `ready`.
- **RF-PLB-005** `[DECISÃO]` O retorno contém os dados necessários para
  reprodução, não o arquivo bruto.
- **RF-PLB-006** `[OBRIGATÓRIO]` Consumidor sem autorização tem a reprodução
  negada, e a interface apresenta um estado claro de acesso negado.
- **RF-PLB-008** `[DECISÃO]` A resposta a um consumidor sem autorização não
  permite distinguir conteúdo inexistente de conteúdo existente e não autorizado,
  pelo mesmo motivo registrado em RN-PROP-005.
- **RF-PLB-007** `[OBRIGATÓRIO]` Conteúdo não publicado, ainda em processamento
  ou com falha não é reproduzível, e o motivo é comunicado de forma distinguível
  da negativa por autorização.

`[RESOLVIDO]` Objeto privado no storage e URL de leitura pré-assinada de curta
duração, emitida somente depois de a autorização e a disponibilidade serem
verificadas. Estratégia e prazo em `plan.md` §14.2 (ABERTO-007).

---

## 11. Autenticação, autorização e isolamento

- **RF-AUT-001** `[OBRIGATÓRIO]` Existe autenticação para produtor e consumidor,
  demonstrável com usuários preparados por seed.
- **RF-AUT-002** `[OBRIGATÓRIO]` Operações de gestão exigem produtor autenticado
  e proprietário do recurso (RN-PROP-002).
- **RF-AUT-003** `[OBRIGATÓRIO]` Operações de consumo exigem consumidor
  autenticado e autorizado (RN-AUT-003).
- **RF-AUT-004** `[OBRIGATÓRIO]` O backend decide a autorização em todas as
  operações, independentemente do que a interface exibe (RN-AUT-002).
- **RF-AUT-005** `[DECISÃO]` Sessão expirada produz um estado próprio na
  interface, distinto de acesso negado: o usuário é informado e conduzido de
  volta à autenticação, sem perder a navegação corrente de forma abrupta.
- **RF-AUT-006** `[OBRIGATÓRIO]` Credenciais e segredos não são expostos no
  código cliente.
- **RF-AUT-007** `[OBRIGATÓRIO]` Entradas são validadas nas fronteiras, e erros
  não expõem informação sensível nem detalhes internos.
- **RF-AUT-008** `[DECISÃO]` Uma tentativa de autenticação com credenciais
  inválidas é recusada como erro de validação, com mensagem genérica associada ao
  campo de identificação. A resposta é **idêntica** para credencial de um usuário
  inexistente e para senha incorreta de um usuário existente, e não permite
  determinar qual das duas ocorreu.

O desafio exige negar o acesso, mas não determina se a negativa pode revelar que
a conta existe. Ocultar essa diferença é decisão deste projeto: uma resposta que
distingue "não há conta com este e-mail" de "a senha está errada" transforma a
tela de autenticação num verificador de cadastro, e basta percorrer uma lista de
endereços para descobrir quem usa a plataforma. É a mesma razão de RN-PROP-005,
aplicada à fronteira de autenticação.

A negativa por credencial é distinta da ausência de sessão: a primeira é uma
requisição recebida e recusada pelo conteúdo; a segunda pede reautenticação e
tem estado próprio na interface (RF-AUT-005, RF-UI-014).
- **RF-AUT-009** `[DECISÃO]` Operações que alteram estado exigem, além da sessão,
  um token de proteção contra requisição forjada por terceiro site. Token ausente
  ou que não confere faz a operação ser recusada **sem ser executada**, com
  resposta em formato próprio e código estável, distinguível de falha de
  autenticação e de erro de validação.

O desafio exige tratar CSRF de forma coerente e documentada, sem fixar o
mecanismo nem a resposta. Dar à falha um código próprio é escolha deste projeto:
sem ele, a interface não teria como distinguir "seu token expirou, peça outro e
repita" de "suas credenciais não servem" — e trataria como erro definitivo algo
que se resolve sozinho com uma nova tentativa.

`[RESOLVIDO]` Sessão em cookie `HttpOnly`, sem credencial acessível ao
JavaScript, com proteção CSRF e CORS restrito a origens explícitas com
credenciais. Configuração e justificativa em `plan.md` §9 (ABERTO-001). O desafio
exige apenas que sejam coerentes e documentados. Os códigos de resposta de
RF-AUT-008 e RF-AUT-009 estão fixados na tabela de `plan.md` §10.3.

---

## 12. Estados obrigatórios da interface

`[OBRIGATÓRIO]` As telas representam de forma consistente os estados relevantes
do produto. Cada estado abaixo é observável e verificável.

| ID | Estado | Comportamento esperado |
| --- | --- | --- |
| **RF-UI-001** | Carregando | Indicação visível enquanto os dados são obtidos; a tela não apresenta conteúdo vazio como se fosse resultado |
| **RF-UI-002** | Lista vazia | Mensagem que distingue "não há nada aqui" de "ainda carregando", com a próxima ação sugerida quando houver |
| **RF-UI-003** | Ação em andamento | Controle desabilitado e sinal de progresso durante submissões; envio duplicado por clique repetido é impedido |
| **RF-UI-004** | Upload em andamento | Progresso da transferência ou, quando a estratégia não permitir progresso real, um estado de envio inequívoco |
| **RF-UI-005** | Processando | O vídeo aparece como em processamento, e a publicação permanece indisponível |
| **RF-UI-006** | Vídeo pronto | O vídeo aparece como pronto, e a publicação fica disponível |
| **RF-UI-007** | Falha do vídeo | A falha é exibida com informação compreensível e o caminho para nova tentativa |
| **RF-UI-008** | Sucesso | Confirmação explícita de ações relevantes: criação, conclusão de envio e publicação |
| **RF-UI-009** | Erro de validação | Mensagens vindas da API associadas aos campos correspondentes, preservando o que o usuário digitou |
| **RF-UI-010** | Conflito de regra | Rejeição por regra de negócio, como publicar aula sem vídeo pronto, exibida como condição não satisfeita e não como erro genérico |
| **RF-UI-011** | API indisponível | Estado próprio para indisponibilidade, distinto de lista vazia, com possibilidade de nova tentativa |
| **RF-UI-012** | Erro de rede | Tratamento equivalente a RF-UI-011, sem deixar a tela em carregamento indefinido |
| **RF-UI-013** | Acesso negado | Estado claro de não autorizado, sem revelar a existência do recurso |
| **RF-UI-014** | Sessão expirada | Estado distinto de acesso negado, conduzindo à reautenticação (RF-AUT-005) |
| **RF-UI-015** | Conteúdo indisponível | Aula não publicada ou vídeo não pronto comunicados de forma distinta da negativa por autorização |

- **RF-UI-016** `[OBRIGATÓRIO]` A interface é clara, responsiva e utilizável, com
  componentes organizados, formulários acessíveis e navegação consistente. Não se
  exige acabamento visual comercial.
- **RF-UI-017** `[OBRIGATÓRIO]` Regras de negócio não são duplicadas no frontend.
  A interface reflete as decisões do backend em vez de recalculá-las.

---

## 13. Cenários de erro e falhas parciais

`[OBRIGATÓRIO]` O cenário previsto pelo desafio inclui múltiplos usuários
simultâneos, comunicações externas que falham, clientes que repetem requisições
após falhas de rede e callbacks que chegam repetidos ou fora do fluxo esperado.

| ID | Situação | Comportamento esperado |
| --- | --- | --- |
| **RF-ERR-001** | Transferência interrompida | O vídeo nunca é tratado como concluído: não avança para `uploaded` nem `ready`. Sem solicitação de conclusão, a tentativa permanece em `uploading` (RN-VID-005). A interface exibe a falha da transferência e não presume sucesso. A recuperação decidida em ABERTO-014 se limita à tentativa atual |
| **RF-ERR-002** | Conclusão com objeto confirmadamente ausente ou incompatível | Quando o armazenamento **confirma de forma confiável** a ausência ou a incompatibilidade, o vídeo não avança para `uploaded`, o produtor recebe motivo compreensível (RF-UPL-009) e a tentativa passa para `failed` (RF-UPL-013). Quando a aplicação **não consegue avaliar** o objeto — indisponibilidade, credencial, permissão, assinatura, configuração ou resposta desconhecida —, não há evidência sobre ele: a tentativa permanece em `uploading`, nenhum processamento é iniciado e a resposta comunica falha de infraestrutura, deixando a conclusão repetível (RF-UPL-009) |
| **RF-ERR-003** | Conclusão repetida | Reconhecida sem duplicar processamento (RN-IDM-001) |
| **RF-ERR-004** | Falha de processamento | O vídeo vai a `failed` com informação compreensível; a aula não se torna publicável |
| **RF-ERR-005** | Nova tentativa após falha | Um novo envio substitui a tentativa em `failed` e cria uma nova tentativa em `pending` (RF-UPL-005). Enquanto a tentativa não estiver em `failed`, o novo envio é rejeitado (RF-UPL-011) |
| **RF-ERR-006** | Callback duplicado | Idempotente (RN-IDM-002) |
| **RF-ERR-007** | Reentrega de evento já concluído | O desfecho armazenado é repetido, sem reaplicar efeitos e sem reavaliar o corpo recebido (RN-IDM-003) |
| **RF-ERR-008** | Callback incompatível com o estado ou para tentativa inexistente | O estado atual é preservado, o evento é rejeitado em definitivo e a resposta indica resultado permanente, dispensando novo envio (RN-VID-006) |
| **RF-ERR-014** | Falha transitória ao processar o callback | O estado atual é preservado, nada é registrado e a resposta indica falha temporária; a reentrega do mesmo `event_id` é avaliada normalmente (RN-VID-007) |
| **RF-ERR-009** | Callback de origem não confiável | Recusado sem efeito (RF-WHK-001) |
| **RF-ERR-010** | Publicação antecipada | Rejeitada com a condição não satisfeita (RF-PUB-002) |
| **RF-ERR-011** | Acesso a recurso de terceiro | O acesso é negado e nenhum conteúdo é retornado (RN-PROP-003); a resposta não permite determinar se o recurso existe (RN-PROP-005) |
| **RF-ERR-012** | API indisponível para o frontend | Estado próprio na interface, sem carregamento indefinido (RF-UI-011) |
| **RF-ERR-013** | Requisição repetida por falha de rede | Não produz efeito duplicado nas operações de conclusão, callback e publicação |

---

## 14. Requisitos não funcionais

- **RNF-001** `[OBRIGATÓRIO]` A transferência do vídeo não ocupa a aplicação
  Laravel nem o servidor Nuxt, sustentando arquivos de vários gigabytes.
- **RNF-002** `[OBRIGATÓRIO]` Operações de longa duração não bloqueiam a
  requisição HTTP (RF-PROC-002).
- **RNF-003** `[OBRIGATÓRIO]` A solução opera com múltiplos usuários e
  requisições simultâneas sem corromper estado.
- **RNF-004** `[OBRIGATÓRIO]` Regras de negócio residem no domínio do backend,
  não em controllers nem em componentes de interface.
- **RNF-005** `[OBRIGATÓRIO]` Testes automatizados cobrem, no backend, transições
  de domínio, fluxos críticos da API, autorização, idempotência e falha de
  processamento; no frontend, componentes ou composables relevantes, um fluxo de
  formulário com sucesso, validação e erro, e os estados de vídeo e de
  indisponibilidade da API.
- **RNF-006** `[OBRIGATÓRIO]` Existe ao menos um teste de jornada crítica ponta a
  ponta atravessando a interface com integração real ao backend.
- **RNF-007** `[OBRIGATÓRIO]` Todos os testes são executáveis por comandos
  documentados, com substitutos para dependências externas.
- **RNF-008** `[OBRIGATÓRIO]` Existe pipeline automatizada que instala
  dependências das duas camadas, executa os testes, executa o build do Nuxt,
  falha quando algo falha, e roda ao menos uma verificação adicional de qualidade
  por camada.
- **RNF-009** `[OBRIGATÓRIO]` Persistência em MySQL 8, com migrations e
  preparação de dados para avaliação.
- **RNF-010** `[OBRIGATÓRIO]` Não há segredos reais, chaves de produção ou dados
  pessoais no repositório; a configuração de exemplo não contém segredos.
- **RNF-011** `[OBRIGATÓRIO]` Existe documentação de instalação, execução,
  qualidade, arquitetura e transparência, além de documentação executável da API
  e roteiro de demonstração.
- **RNF-012** `[OBRIGATÓRIO]` As especificações são versionadas e evoluem junto
  com o código, mantendo coerência entre spec, implementação e testes.
- **RNF-013** `[DECISÃO]` Complexidade só entra quando o requisito a justifica. O
  desafio afirma que infraestrutura complexa sem necessidade clara e quantidade
  de padrões não são diferenciais.

### 14.1 Entrega, histórico e pipeline

- **RNF-014** `[OBRIGATÓRIO]` A entrega ocorre em repositório GitHub, com o
  histórico de desenvolvimento preservado e sem squash antes da avaliação.
- **RNF-015** `[OBRIGATÓRIO]` Os commits são granulares, coerentes e possuem
  mensagens compreensíveis, tornando visível a evolução de especificações,
  backend, frontend e testes.
- **RNF-016** `[OBRIGATÓRIO]` O repositório é disponibilizado com antecedência
  mínima de 24 horas em relação à entrevista.
- **RNF-017** `[OBRIGATÓRIO]` A pipeline é executada automaticamente em
  alterações relevantes do repositório.
- **RNF-018** `[OBRIGATÓRIO]` A entrega inclui arquivos de configuração de
  ambiente de exemplo, sem segredos reais.
- **RNF-019** `[OBRIGATÓRIO]` A entrega contém o conteúdo mínimo exigido: código
  do backend e do frontend, repositório com histórico preservado, especificações
  versionadas, migrations e dados de avaliação, testes das duas camadas e do
  fluxo integrado, pipeline com build do frontend, documentação da API e roteiro
  de demonstração.
- **RNF-020** `[OBRIGATÓRIO]` O ambiente de avaliação é reproduzível localmente
  com instruções claras para iniciar backend, frontend, banco, filas e
  simuladores; alternativamente, é disponibilizado ambiente público funcional.

---

## 15. Critérios de aceitação

Formato Dado / Quando / Então. Cada critério é verificável por teste automatizado.

### 15.1 Produtor

**AC-PROD-001 — cria e consulta o próprio curso**
Dado um produtor autenticado
Quando ele cria um curso com título e descrição válidos
Então o curso é criado com ele como proprietário, estado `draft` e data de criação
E o curso aparece na sua listagem
E sua consulta de detalhe retorna identificador, título, descrição, proprietário, estado e data de criação

**AC-PROD-002 — não acessa curso de outro produtor** (RN-PROP-003)
Dado dois produtores, cada um com um curso
Quando o primeiro tenta consultar ou gerenciar o curso do segundo
Então a operação é negada
E nenhum conteúdo do recurso é retornado

**AC-PROD-007 — a existência de recurso alheio não é revelada** (RN-PROP-005)
Dado um produtor autenticado
Quando ele solicita um identificador pertencente a outro produtor
E quando ele solicita um identificador que não existe
Então as duas respostas são indistinguíveis entre si
E não é possível determinar se o identificador pertence a outro produtor

**AC-PROD-003 — módulos e aulas recebem a posição na ordem de criação**
Dado um produtor autenticado com um curso próprio
Quando ele cria três módulos nesse curso
Então eles ocupam as posições 1, 2 e 3, na ordem de criação
Quando ele cria um quarto módulo
Então esse módulo ocupa a posição 4
E nenhum módulo existente tem sua posição alterada
E não há posições duplicadas nem ordem ambígua
E o mesmo comportamento se aplica às aulas dentro de um módulo
E duas consultas consecutivas sem escrita retornam a mesma sequência

**AC-PROD-004 — aula não é publicada antes do vídeo pronto**
Dado uma aula cujo vídeo está em `pending`, `uploading`, `uploaded`, `processing` ou `failed`
Quando o produtor tenta publicá-la
Então a publicação é rejeitada
E a resposta informa a condição não satisfeita, em formato que a interface exibe como conflito de regra

**AC-PROD-005 — aula pronta pode ser publicada**
Dado uma aula cujo vídeo está em `ready` com referência de reprodução registrada
Quando o produtor proprietário a publica
Então a aula passa a publicada
E publicá-la novamente não produz efeito adicional nem erro

**AC-PROD-006 — a primeira publicação torna o curso disponível**
Dado um curso em `draft` sem nenhuma aula publicada
Quando sua primeira aula elegível é publicada
Então o curso passa a `available`

### 15.2 Vídeo, upload e processamento

**AC-VID-010 — produtor não inicia upload em aula de outro produtor** (RF-UPL-002)
Dado dois produtores, o segundo com uma aula em um curso próprio
Quando o primeiro tenta iniciar o envio de um vídeo para essa aula
Então a operação é negada
E nenhum vídeo é criado para a aula
E a resposta não revela a existência da aula (RN-PROP-005)

**AC-VID-001 — o upload não atravessa integralmente Laravel nem Nuxt**
Dado um produtor que inicia o envio de um vídeo de vários gigabytes
Quando ele recebe a resposta de início
Então a resposta fornece os dados para transferência direta ao destino de armazenamento
E o conteúdo do arquivo não trafega pela aplicação Laravel nem pelo servidor Nuxt

**AC-VID-002 — a conclusão falha quando o objeto é confirmadamente inválido**
Dado um envio iniciado cujo objeto o destino de armazenamento confirma, de forma confiável, não existir ou não corresponder ao declarado
E que essa confirmação não decorre de indisponibilidade, credencial, permissão, assinatura, configuração ou resposta desconhecida
Quando o cliente solicita a conclusão
Então a conclusão é rejeitada
E o vídeo não avança para `uploaded`
E a tentativa passa para `failed` (RF-UPL-013)
E o produtor recebe motivo compreensível

**AC-VID-011 — novo envio é permitido após falha e bloqueado durante uma tentativa ativa**
Dado uma aula cuja tentativa de envio terminou em `failed`
Quando o produtor inicia um novo envio para essa aula
Então o novo envio é aceito
E uma nova tentativa é criada em `pending`
Mas dado uma aula cujo vídeo esteja em `pending`, `uploading`, `uploaded` ou `processing`
Quando o produtor tenta iniciar um novo envio
Então o envio é rejeitado (RF-UPL-011)
E a resposta informa o estado que impede o novo envio

**AC-VID-012 — upload interrompido nunca é tratado como concluído**
Dado um envio iniciado cuja transferência foi interrompida
E nenhuma solicitação de conclusão recebida
Então o vídeo não avança para `uploaded` nem para `ready`
E a tentativa permanece em `uploading` (RN-VID-005)
E a interface exibe a falha da transferência, sem presumir sucesso

**AC-VID-003 — conclusão repetida não duplica processamento**
Dado um envio já concluído com sucesso e processamento iniciado
Quando o cliente solicita a conclusão novamente
Então a resposta é reconhecida sem erro
E nenhum processamento adicional é iniciado

**AC-VID-004 — callback repetido é idempotente**
Dado um callback com `event_id` já concluído
Quando o mesmo `event_id` é reentregue
Então o evento é reconhecido
E o desfecho registrado anteriormente é repetido
E nenhum efeito adicional é produzido

**AC-VID-005 — o corpo da reentrega não altera um evento já concluído**
Dado um callback com `event_id` já concluído
Quando o mesmo `event_id` é reentregue com um corpo diferente
Então o desfecho registrado anteriormente é repetido
E o corpo recebido não é aplicado
E o efeito anterior é preservado

**AC-VID-006 — callback incompatível não regride o vídeo** (RN-VID-006)
Dado um vídeo em `ready`
Quando chega um novo callback de falha para esse vídeo, com `event_id` ainda não conhecido
Então o vídeo permanece em `ready`
E o evento é tratado como permanentemente incompatível
E a resposta indica resultado permanente, dispensando nova entrega
E o mesmo vale para um callback cujo `video_id` não corresponde a tentativa alguma
E nenhuma regressão ocorre

**AC-VID-013 — falha transitória não consome o evento** (RN-VID-007)
Dado um vídeo em `processing`
Quando o processamento do callback é interrompido por uma falha transitória de infraestrutura
Então o vídeo permanece em `processing`
E nenhum desfecho é registrado para aquele `event_id`
E a resposta indica falha temporária, admitindo nova tentativa
Quando o mesmo `event_id` é reentregue e a infraestrutura responde normalmente
Então o callback é processado
E o vídeo passa para `ready`

**AC-VID-007 — falha de processamento é visível e bloqueia a publicação**
Dado um vídeo em `processing`
Quando chega um callback de falha
Então o vídeo passa a `failed` com informação compreensível e sem detalhes internos
E o produtor vê o estado de falha na interface
E a aula correspondente não pode ser publicada

**AC-VID-008 — transição inválida é rejeitada**
Dado um vídeo em qualquer estado
Quando é solicitada uma transição fora das permitidas
Então a transição é rejeitada
E o estado permanece inalterado

**AC-VID-009 — origem não confiável é recusada**
Dado um callback sem origem legítima
Quando ele é recebido
Então é recusado
E nenhum efeito é produzido sobre o vídeo

### 15.3 Consumidor

**AC-CONS-001 — consumidor autorizado recebe dados de reprodução**
Dado um consumidor autenticado com acesso concedido a um curso
E uma aula publicada nesse curso, com vídeo em `ready`
Quando ele solicita a reprodução da aula
Então recebe os dados necessários para reprodução
E não recebe o arquivo bruto

**AC-CONS-002 — consumidor não autorizado não recebe dados de reprodução** (RF-PLB-006)
Dado um consumidor autenticado sem acesso concedido ao curso
Quando ele solicita a reprodução de uma aula desse curso
Então a solicitação é negada
E nenhum dado de reprodução é retornado
E a interface apresenta estado claro de acesso negado

**AC-CONS-005 — a existência de conteúdo não autorizado não é revelada** (RF-PLB-008)
Dado um consumidor autenticado sem acesso concedido ao curso
Quando ele solicita a reprodução de uma aula existente desse curso
E quando ele solicita a reprodução de uma aula que não existe
Então as duas respostas são indistinguíveis entre si

**AC-CONS-003 — conteúdo indisponível não é reproduzido**
Dado um consumidor autorizado
E uma aula não publicada, ou publicada com vídeo fora de `ready`
Quando ele solicita a reprodução
Então a solicitação é negada
E o motivo é distinguível da negativa por autorização

**AC-CONS-004 — consumidor navega apenas o conteúdo publicado**
Dado um curso autorizado contendo aulas publicadas e aulas em rascunho
Quando o consumidor navega pela estrutura
Então vê apenas as aulas publicadas, na ordem definida

### 15.4 Interface

**AC-UI-001 — a interface trata erro de validação**
Dado um formulário de criação com dados inválidos
Quando o produtor submete
Então as mensagens de validação retornadas pela API são exibidas junto aos campos correspondentes
E o conteúdo já digitado é preservado
E nenhum recurso é criado

**AC-UI-002 — a interface trata indisponibilidade da API**
Dado que a API está indisponível ou a rede falha
Quando o usuário abre uma tela que depende de dados
Então é apresentado um estado de indisponibilidade, distinto de lista vazia
E a tela não permanece em carregamento indefinido
E é oferecida nova tentativa

**AC-UI-003 — a interface trata sessão expirada**
Dado um usuário cuja sessão expirou
Quando ele executa uma ação que exige autenticação
Então recebe um estado de sessão expirada, distinto de acesso negado
E é conduzido à reautenticação

**AC-UI-004 — a interface reflete os estados do vídeo**
Dado uma aula com vídeo em cada um dos estados do ciclo de vida
Quando o produtor visualiza a aula
Então a interface apresenta o estado correspondente
E a ação de publicar só está disponível quando o vídeo está `ready`

### 15.5 Jornada integrada

**AC-E2E-001 — a jornada atravessa frontend e backend reais**
Dado a aplicação Nuxt e a API Laravel em execução com os dados de avaliação
E um curso vazio em `draft` pertencente ao produtor de demonstração, preparado por seed
E uma concessão de acesso do consumidor de demonstração a esse curso, preparada por seed
Quando o produtor autentica-se, abre esse curso, cria um módulo e uma aula, envia um vídeo, tem o processamento concluído com sucesso e publica a aula
Então o curso passa a `available`
E quando o consumidor autentica-se, navega até essa aula e solicita a reprodução
Então ele obtém os dados de reprodução
E cada etapa ocorreu pela interface, com integração real ao backend

A criação de um curso novo é validada separadamente em AC-PROD-001. A jornada
integrada parte de um curso já preparado porque não existe operação para conceder
acesso a um curso recém-criado, conforme registrado na seção 4.

---

## 16. Fora de escopo

`[FORA]` Registrados como não obrigatórios, por decisão explícita do desafio ou
deste projeto:

- cadastro público de usuários;
- recuperação de senha;
- pagamento e compra;
- transcodificação real de vídeo;
- infraestrutura de produção;
- atualização e exclusão gerais de cursos e aulas (RF-CUR-005, RF-AUL-007);
- retomada completa de upload — a estratégia é documentada mesmo que não seja
  totalmente implementada, conforme exige o desafio; implementá-la por inteiro
  depende de decisão futura.

`[OPCIONAL]` Enriquecem a solução mas não substituem requisitos obrigatórios:

- reordenação por arrastar e soltar;
- atualização de status em tempo real;
- player avançado;
- modo escuro;
- design system.

`[DIFERENCIAL]` Considerados diferenciais pelo desafio quando coerentes e sem
comprometer a entrega mínima:

- Docker e Docker Compose;
- ambiente público funcional;
- serviços de nuvem bem abstraídos;
- CI/CD além do mínimo;
- filas e workers estruturados;
- observabilidade e rastreamento de erros;
- health checks;
- documentação OpenAPI;
- testes de acessibilidade ou de contrato;
- estratégias de retry, retomada de upload e resiliência;
- métricas de desempenho no frontend;
- preocupação operacional e segurança de supply chain.

---

## 17. Decisões técnicas resolvidas no plan.md

Nenhuma das escolhas abaixo foi imposta pelo desafio. Todas ficaram deliberadamente
em aberto nesta especificação, foram decididas pelo projeto antes do início da
implementação, e estão documentadas e justificadas no `plan.md`, cada uma com
contexto, alternativas descartadas e trade-off assumido.

Os identificadores são estáveis: continuam sendo a chave de rastreabilidade entre
esta especificação e o plano, e não são renomeados nem renumerados.

| ID | Decisão técnica | Estado | Resolvida em | Escolha resumida |
| --- | --- | --- | --- | --- |
| **ABERTO-001** | Autenticação, sessão, CORS, CSRF e armazenamento de credenciais no cliente | `[RESOLVIDO]` | plan §9 | Sanctum em modo SPA, sessão em MySQL, cookie `HttpOnly`, origens explícitas com credenciais |
| **ABERTO-002** | Mecanismo de storage e forma da transferência direta, incluindo envio em partes | `[RESOLVIDO]` | plan §11 | RustFS S3-compatible, multipart de 64 MiB, uma parte por vez, URL de parte de 15 minutos renovável |
| **ABERTO-003** | Verificação da existência e dos metadados do objeto enviado | `[RESOLVIDO]` | plan §12 | `CompleteMultipartUpload` e `HeadObject` sob lock atômico, validando chave, tamanho, tipo e metadados da tentativa |
| **ABERTO-004** | Mecanismo de fila e forma do worker | `[RESOLVIDO]` | plan §§13.1 e 13.2 | Database Queue sobre MySQL, workers separados, enfileiramento atômico na mesma transação MySQL, job de processamento retomável |
| **ABERTO-005** | Simulador de processamento, seus callbacks e a origem da informação de falha | `[RESOLVIDO]` | plan §§13.3 e 13.4 | `simulator-worker` isolado chamando o webhook real, sucesso determinístico e falha por comando Artisan; mensagem derivada internamente, carga oficial preservada |
| **ABERTO-006** | Mecanismo de validação de origem do callback | `[RESOLVIDO]` | plan §13.4 | HMAC-SHA256 sobre timestamp e corpo bruto, comparação em tempo constante, janela de 5 minutos |
| **ABERTO-007** | Estratégia de disponibilização do conteúdo para reprodução | `[RESOLVIDO]` | plan §14.2 | Objeto privado e URL `GET` pré-assinada de 5 minutos, emitida após a autorização |
| **ABERTO-008** | Estratégia de renderização do Nuxt e organização de estado | `[RESOLVIDO]` | plan §§15.1 e 15.2 | SPA com `ssr: false`, `useState` apenas para sessão e usuário, polling enquanto o estado é transitório |
| **ABERTO-009** | Biblioteca de UI, se houver | `[RESOLVIDO]` | plan §15.5 | Nuxt UI v4 com Tailwind CSS 4, como única biblioteca principal |
| **ABERTO-010** | Formato dos contratos da API, paginação e formato dos erros | `[RESOLVIDO]` | plan §10 | Envelope `data`, `meta` e `links`, paginação 15 por padrão e 50 no máximo, `application/problem+json`, OpenAPI 3.1 |
| **ABERTO-011** | Modelagem em agregados, entidades e value objects; limites transacionais | `[RESOLVIDO]` | plan §§5, 6, 7.4 e 8 | Quatro camadas e três áreas; `Course`, `Module`, `Lesson` e `VideoAttempt` como agregados separados, coordenados por caso de uso com lock explícito |
| **ABERTO-012** | Composição do ambiente containerizado e serviços que o integram | `[RESOLVIDO]` | plan §16 | Docker Compose com oito serviços, healthchecks, volumes nomeados, imagens fixadas, sem Redis |
| **ABERTO-013** | Ferramentas de teste, lint e verificação adicional de cada camada | `[RESOLVIDO]` | plan §17 | PHPUnit e Pint no backend; Vitest com Nuxt e Vue Test Utils, ESLint, typecheck e build no frontend; Playwright no fluxo integrado |
| **ABERTO-014** | Retomada, cancelamento, descarte e expiração de tentativas de upload abandonadas | `[RESOLVIDO]` | plan §§11.2 e 19.1 | Recuperação limitada à tentativa atual, com reenvio de parte e renovação de URL; cancelamento, descarte, expiração e retomada entre sessões documentados como limitação consciente do MVP |

---

## 18. Matriz de rastreabilidade

Relaciona os grupos de requisitos deste documento às seções do desafio oficial.

| Seção do desafio | Assunto | Requisitos correspondentes |
| --- | --- | --- |
| §2 | Stack e escopo obrigatórios | Seção 2; RNF-009, RNF-013 |
| §3 | Desenvolvimento orientado por especificações | RNF-012 |
| §4 | Domínio mínimo e perfis | Seções 4 e 5; RN-PROP-001 a 004 |
| §5.1 | Cursos | RF-CUR-001 a 005; RN-CUR-001 a 003; AC-PROD-001, AC-PROD-006 |
| §5.2 | Módulos | RF-MOD-001 a 007; RN-ORD-001 a 004; AC-PROD-003 |
| §5.3 | Aulas | RF-AUL-001 a 009; RN-ORD-001 a 004; AC-PROD-003 |
| §5.4 | Estrutura do curso | RF-EST-001 a 004; AC-PROD-003, AC-CONS-004 |
| §5.5 | Propriedade e isolamento | RN-PROP-002 a 005; RF-CUR-004, RF-MOD-004, RF-UPL-002; AC-PROD-002, AC-PROD-007, AC-VID-010 |
| §6.1 | Iniciar envio | RF-UPL-001 a 006, RF-UPL-011, RF-UPL-012; RNF-001; AC-VID-001, AC-VID-010, AC-VID-011 |
| §6.2 | Concluir envio | RF-UPL-007 a 010, RF-UPL-013; RN-IDM-001; AC-VID-002, AC-VID-003, AC-VID-012 |
| §6.3 | Processamento | RF-PROC-001 a 006; RNF-002 |
| §7.1 | Estados do vídeo | RF-VID-001, RF-VID-002; RN-VID-001 a 008; AC-VID-006, AC-VID-008, AC-VID-012, AC-VID-013 |
| §7.2 | Callback de processamento | RF-WHK-001 a 011; RN-IDM-002, RN-IDM-003; RN-VID-004, RN-VID-006 a 008; RF-ERR-008, RF-ERR-014; AC-VID-004 a 007, AC-VID-009, AC-VID-013 |
| §7.3 | Publicação | RF-PUB-001 a 003; RN-PUB-001 a 006; AC-PROD-004, AC-PROD-005 |
| §7.4 | Consumo | RF-PLB-001 a 008; AC-CONS-001 a 003, AC-CONS-005 |
| §8.1 | Jornada do produtor | Seção 7; AC-PROD-001 a 007 |
| §8.2 | Jornada do consumidor | Seção 8; RF-CONS-001 a 006; AC-CONS-001 a 005 |
| §9.1 | Estados de interface | RF-UI-001 a 015; AC-UI-001 a 004 |
| §9.2 | Upload de arquivos grandes | RF-UI-004; RF-UPL-005; RNF-001; ABERTO-014 |
| §9.3 | Qualidade da interface | RF-UI-016 |
| §9.4 | Contrato com a API | RF-UI-009, RF-UI-017; ABERTO-010 |
| §10 | Cenário, autenticação e segurança | Seções 11 e 13; RF-AUT-001 a 009; RN-AUT-001 a 006; RN-PROP-005; RN-VID-004, RN-VID-006, RN-VID-007; RF-ERR-001 a 014; RNF-003, RNF-010 |
| §11 | Testes automatizados | RNF-005 a 007; seção 15 inteira, incluindo AC-VID-013; AC-E2E-001 |
| §12 | Qualidade, pipeline, banco e Git | RNF-008, RNF-009, RNF-012, RNF-014 a 017 |
| §13 | Documentação obrigatória | RNF-011 |
| §14 | Execução e entrega mínima | RNF-007, RNF-009, RNF-011, RNF-018 a 020; ABERTO-012 |
