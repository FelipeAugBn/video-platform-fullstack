# Spike de validação do storage — RustFS

**Data:** 2026-09-03
**Escopo:** validação de infraestrutura, sem código de aplicação
**Resultado:** aprovado — RustFS atende à arquitetura planejada

---

## 1. Objetivo

O plano técnico apoia quatro comportamentos no storage de objetos, e nenhum
deles é opcional para a solução:

| Capacidade | Onde o plano depende dela |
| --- | --- |
| Upload multipart | §11 — o navegador envia os bytes direto ao storage, em partes |
| CORS | §11.3 — sem preflight e sem cabeçalhos na resposta real, o navegador recusa o envio |
| `HeadObject` | §12 — é a prova independente de que o objeto existe e confere |
| URLs pré-assinadas | §11.3 e §14.2 — autorizam envio e reprodução sem entregar credencial permanente ao navegador e sem fazer a API transmitir os bytes do vídeo |

RustFS foi escolhido por ser compatível com a API do S3 e por manter o adapter
substituível por um provedor real. É também a decisão de maior risco técnico do
plano (§19.1): trata-se de um projeto novo, e se qualquer uma das quatro
capacidades não se comportasse como esperado, a escolha voltaria a ser decisão
arquitetural.

Este experimento existe para responder a essa pergunta **antes** que qualquer
código de domínio passasse a depender da resposta.

---

## 2. Conclusão

**RustFS aprovado para a arquitetura planejada.** As quatro capacidades
funcionam, e foram exercidas nas mesmas condições em que a aplicação vai usá-las:
as operações de SDK partindo de dentro da rede do ambiente, e as URLs assinadas
consumidas de fora dela, pelo endereço público.

Nenhuma alteração de plano é necessária. As limitações da seção 9 são delimitações
conscientes do experimento, não defeitos encontrados no storage.

---

## 3. Comando principal

O experimento é reproduzível por dois comandos. A máquina precisa de Docker e
Docker Compose; nada além disso. Não é necessário instalar localmente Python,
`boto3`, `curl`, PHP ou Node, porque os dois clientes são containers
descartáveis que trazem o que usam:

```bash
docker compose -f docker/spike/compose.rustfs.yml up -d
docker/spike/executar.sh
```

O primeiro sobe apenas o storage. O segundo executa as quatro etapas em ordem,
interrompe na primeira que falhar e termina afirmando os catorze pontos de
verificação. A execução completa leva poucos segundos depois que as imagens
estão em cache.

O diretório de troca entre os clientes é criado com `mktemp -d` fora do
repositório e removido ao final, inclusive quando uma etapa falha. Tudo que a
execução produz — arquivo de amostra, URLs assinadas e dados temporários de
autorização — vive só ali, e nada disso é escrito na árvore versionada.

Nenhum segredo ou credencial real é usado em momento algum. O arquivo de
ambiente do experimento traz apenas valores locais e fictícios, que existem para
inicializar o storage isolado e não têm significado fora dele; por isso não são
reproduzidos neste relatório.

---

## 4. As quatro etapas

Um upload multipart não se fecha num sentido só: quem assina a URL da parte não
é quem recebe o `ETag`, e quem conclui o upload precisa desse `ETag`. Os dois
clientes portanto se alternam, e é essa alternância que torna a distinção entre
endereço interno e endereço público verificável em vez de presumida.

### Etapa 1 — preparação, pelo cliente interno

Ligado à rede do ambiente, o cliente interno cria o bucket, aplica a
configuração de CORS, relê essa configuração para confirmar que foi aceita, gera
o arquivo de amostra, abre o upload multipart e assina a URL `PUT` da parte 1.
`UploadId`, chave do objeto e URL assinada ficam disponíveis para a etapa
seguinte.

Vale explicitar os papéis, porque eles não são os mesmos aqui e na aplicação.
Na solução real, quem deriva a chave do objeto e os metadados é o **backend**: o
navegador não pode escolhê-los, e é esse vínculo que a verificação de §12.3
confere na conclusão do envio. No experimento, o cliente interno **representa
esse backend** — é ele que informa chave e metadados ao storage ao abrir o
multipart. O storage, por sua vez, gera o `UploadId`. O cliente externo, que faz
o papel do navegador, não escolhe nenhum desses valores: recebe apenas a URL já
assinada.

### Etapa 2 — envio, pelo cliente externo

Sem acesso à rede do ambiente, o cliente externo alcança o storage apenas pelo
endereço publicado. Executa o preflight `OPTIONS` declarando a origem do
frontend, envia a parte pela URL assinada, confere os cabeçalhos de CORS **na
resposta do próprio `PUT`** e guarda o `ETag` devolvido.

### Etapa 3 — conclusão, pelo cliente interno

De volta à rede do ambiente, o cliente interno conclui o upload multipart usando
o `ETag` que o cliente externo produziu, executa `HeadObject` sobre o objeto
resultante e assina a URL `GET` de reprodução.

### Etapa 4 — leitura, pelo cliente externo

O cliente externo baixa o objeto pela URL `GET`, novamente pelo endereço
publicado, e compara o conteúdo com o arquivo de amostra byte a byte.

Antes de baixar, o cliente externo confirma que **não** alcança o storage pelo
nome interno do serviço. Sem esse controle negativo, o ponto 14 não provaria
nada sobre o endereço público.

---

## 5. Componentes validados

Toda referência de imagem carrega tag de versão completa **e** digest. Tag de
registry é mutável; apenas o digest identifica um conteúdo. A regra vale para as
três e é verificada automaticamente a cada execução.

| Papel | Referência |
| --- | --- |
| Storage | `rustfs/rustfs:1.0.0-rc.5@sha256:c36b3efea3d1e503f1a2581abd0e7611e0e5820dd30e1850a52384b3fc52bda4` |
| Cliente interno | `python:3.13.15-slim-trixie@sha256:9d2e5553305c7c7b0097999bb17187c69b921ccd6bc9d40e4bb5ebe652c00285` |
| Cliente externo | `curlimages/curl:8.11.1@sha256:c1fe1679c34d9784c1b0d1e5f62ac0a79fca01fb6377cdd33e90473c6f9f9a69` |

**SDK do cliente interno:** `boto3` versão `1.40.24`, com assinatura `s3v4` e
endereçamento por caminho.

O cliente interno usa o SDK, e não uma ferramenta de linha de comando, por um
motivo prático: assinar a URL de uma **parte** de multipart é uma operação que a
CLI oficial não expõe. Usar o SDK também aproxima o experimento do que o adapter
da aplicação fará.

---

## 6. Endereços testados

O storage tem dois endereços, e tratá-los como um só é a origem mais provável de
uma URL que a aplicação considera válida e o navegador não alcança.

| Papel | Endereço | Quem usa |
| --- | --- | --- |
| Interno | `http://rustfs:9000` | O cliente interno, para as chamadas de API |
| Público | `http://localhost:19000` | O cliente externo, ao consumir as URLs assinadas |
| Origem do frontend | `http://localhost:3000` | Declarada no preflight e na política de CORS |

As portas são deliberadamente diferentes — 9000 dentro da rede, 19000 no host —
para que confundir os dois endereços seja um erro visível, e não um acerto por
coincidência.

As URLs são assinadas para o endereço **público**, porque é quem vai consumi-las.
A assinatura não faz chamada de rede: o cliente interno assina para um endereço
que ele próprio não usa.

---

## 7. Configuração de CORS comprovada

| Parâmetro | Valor aplicado |
| --- | --- |
| Origem permitida | `http://localhost:3000` |
| Métodos permitidos | `PUT`, `GET`, `HEAD` |
| Cabeçalhos permitidos | `*` |
| Cabeçalhos expostos | `ETag` |
| Tempo de cache do preflight | 3000 segundos |

A configuração foi aplicada, relida e comparada com o que se pretendia gravar.
Depois, foi exercida de fora: o preflight respondeu liberando a origem e o
método, e a resposta do envio real trouxe os mesmos cabeçalhos.

**A exposição do `ETag` não é detalhe.** Sem ela, o storage devolve o `ETag` no
cabeçalho e o código do navegador simplesmente não consegue lê-lo — o envio
retorna sucesso e a conclusão do multipart quebra depois, sem uma causa óbvia.

**A política é dirigida pela configuração do bucket**, aplicada por chamada de
API. Verificou-se que nenhuma variável de ambiente adicional é necessária no
serviço de storage, e que uma origem não autorizada é recusada com `403`.

---

## 8. Os catorze pontos de verificação

Cada ponto está preso a uma etapa e a uma evidência objetiva. De cada URL
assinada registram-se somente esquema, host, porta, caminho, validade
configurada e resultado: a query string é inteiramente omitida, porque é ela que
carrega os dados temporários de autorização.

| # | Etapa | Verificação | Resultado | Evidência sanitizada |
| --- | --- | --- | --- | --- |
| 1 | pré-condição | Imagens com versão fixada | Aprovado | Três referências com tag de versão completa e digest, nenhuma móvel; a do cliente externo, que não vive no arquivo do ambiente, passa pela mesma regra |
| 2 | pré-condição | Configuração resolve | Aprovado | A configuração é gerada com o serviço do cliente interno incluído, para que a imagem dele também entre na verificação anterior |
| 3 | pré-condição | Inicialização e saúde | Aprovado | Container em execução e verificação de saúde reportando `healthy` |
| 4 | 1 — interno | Bucket e CORS por script | Aprovado | Bucket `spike-videos` presente na listagem; política de CORS aplicada e relida idêntica |
| 5 | 1 — interno | Endereço interno | Aprovado | Nome de serviço `rustfs` resolvido dentro da rede do ambiente e respondendo às chamadas de API |
| 6 | 1 — interno | Abertura do multipart | Aprovado | `UploadId` gerado pelo storage; chave e metadados fornecidos pelo cliente interno, que representa o backend. O cliente externo não escolheu nenhum desses valores |
| 7 | 1 — interno | URL `PUT` de parte | Aprovado | `http://localhost:19000/spike-videos/videos/spike-parte-unica.mp4` — validade configurada de 900 segundos |
| 8 | 2 — externo | Preflight de CORS | Aprovado | `OPTIONS` declarando a origem do frontend respondeu `200` liberando `PUT, GET, HEAD` |
| 9 | 2 — externo | Envio real da parte | Aprovado | `PUT` de 8 388 608 bytes pelo endereço público respondeu `200` e devolveu `ETag` |
| 10 | 2 — externo | CORS na operação real | Aprovado | A resposta do próprio `PUT` trouxe a origem liberada e o `ETag` entre os cabeçalhos expostos |
| 11 | 3 — interno | Conclusão do multipart | Aprovado | Conclusão aceita usando o `ETag` produzido na etapa 2; o objeto passou a existir |
| 12 | 3 — interno | `HeadObject` | Aprovado | 8 388 608 bytes, tipo `video/mp4` e metadado com o identificador da tentativa — os três conferem com o declarado na abertura |
| 13 | 3 — interno | URL `GET` de reprodução | Aprovado | `http://localhost:19000/spike-videos/videos/spike-parte-unica.mp4` — validade configurada de 300 segundos |
| 14 | 4 — externo | Endereço público e integridade | Aprovado | Download pelo endereço publicado, sem resolver o nome interno do serviço; 8 388 608 bytes idênticos byte a byte à amostra |

O arranjo foi verificado também pelo avesso: storage fora do ar, origem de CORS
incorreta, imagem sem digest, imagem em referência móvel e configuração gerada
sem o serviço atrás do perfil. Nos cinco casos a execução falhou com mensagem
específica e código de saída diferente de zero. Catorze aprovações só significam
alguma coisa porque a reprovação foi demonstrada.

---

## 9. Limitações conhecidas

Nenhuma delas bloqueou a validação. Duas seguem em aberto, e o texto abaixo
distingue o que já está exigido em tarefa do que é apenas recomendação.

**Uma única parte — coberto depois, em T042.** O experimento exerceu o protocolo
multipart com uma parte, o que prova abertura, assinatura, envio, conclusão e
verificação, mas não a montagem de várias partes.

A lacuna foi fechada: **T042 implementou a porta de storage e seu adapter, e o
teste de integração executa um multipart real de duas partes** — a primeira com
os 5 MiB mínimos do protocolo, a segunda menor — enviadas pelas URLs assinadas,
concluídas pelo adapter, inspecionadas e lidas de volta com comparação byte a
byte. A montagem de várias partes deixou de ser presumida.

**T088** cobre, no lado do navegador, o particionamento, o progresso e a
renovação de URL. Ela envia **uma parte por vez**: o plano abandonou a ideia de
transferências simultâneas, e o envio sequencial é o que está registrado em
plan §11.2 — com um único envio em voo, a parte que falhou é sempre a última, e
retomar é reenviá-la.

**A expiração não foi aguardada, e continua sem ser.** As validades configuradas
— 900 segundos para a URL de parte, 300 para a de reprodução — foram conferidas
na URL gerada, mas não se esperou o prazo vencer para observar a recusa. Se o
storage aceitasse o valor e ignorasse o prazo, nem o experimento nem o teste de
integração de T042 perceberiam. Um caso com validade curta o bastante para
expirar durante a execução permanece **cobertura recomendada**, e não algo que
alguma tarefa exija.

**A versão validada é release candidate.** `1.0.0-rc.5` não é uma versão
estável. A referência está fixada por tag e digest, então o ambiente é
reprodutível, mas a superfície de API pode mudar antes da versão final. Migrar
para o `1.0.0` estável exige repetir este experimento.

**O escopo é de infraestrutura.** Nada aqui exercita regra de negócio,
autorização, estados do vídeo ou idempotência. Essas propriedades pertencem ao
domínio e são provadas pelos testes das fases seguintes.

---

## 10. O que sobreviveu deste experimento

A consolidação foi realizada em **T003**. O estado atual:

**O andaime foi removido.** O arquivo de ambiente isolado e os três scripts de
validação não existem mais; o papel deles passa para os testes de integração do
upload.

**Duas coisas foram promovidas** para `docker/rustfs/`: a **política de CORS**,
que se provou suficiente sem configuração adicional no serviço, e o **script de
criação do bucket**, que aplica essa política e confere o resultado. A política
promovida é idêntica, byte a byte, à que este relatório documenta.

O script de bootstrap usa o SDK S3 para PHP, e não uma ferramenta de linha de
comando, porque quem vai executá-lo é o serviço de inicialização do ambiente,
que roda dentro da imagem do backend — a mesma biblioteca que o adapter de
storage da aplicação utiliza. **Ele ainda não foi executado contra o storage:**
isso depende da aplicação PHP existir, e acontece em **T011**.

**Este relatório permaneceu** — a evidência de que a decisão de maior risco do
plano foi verificada, e não presumida.
