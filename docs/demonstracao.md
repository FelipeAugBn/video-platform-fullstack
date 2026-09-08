# Guia de demonstração

Roteiro para reproduzir as duas jornadas da plataforma pela interface, do login à
reprodução do vídeo, e para acionar o cenário de falha de processamento.

Tudo aqui é ficcional e local. Não há dado pessoal, chave de produção ou segredo
real neste repositório.

---

## Antes de começar

O ambiente precisa estar de pé. Na raiz do repositório:

```bash
cp .env.example .env   # apenas na primeira vez
make up
```

| O quê | Endereço |
| --- | --- |
| Interface | <http://localhost:3000> |
| API | <http://localhost:8080> |
| Armazenamento de objetos | <http://localhost:19000> |

A demonstração acontece **pela interface**. A API pode ser exercitada
separadamente, e para isso existem o [guia da API](api.md) e o
[contrato OpenAPI](openapi.yaml) — mas nenhum passo deste roteiro depende de
chamada manual.

### Preparar o cenário

O seed **insere o que falta e não altera o que já existe**: subir o ambiente de
novo não duplica registros nem desfaz o que foi feito numa demonstração
anterior. A contrapartida é que ele prepara, mas não repara.

Se o cenário já tiver sido exercitado — pela demonstração ou por `make e2e` —,
devolva-o ao estado inicial antes de recomeçar:

```bash
docker compose stop worker simulator-worker
docker compose run --rm api php artisan migrate:fresh --seed --force
docker compose up --detach worker simulator-worker
```

> **Comando destrutivo.** `migrate:fresh` apaga todas as tabelas e todos os dados
> antes de recriá-los. Use-o apenas neste ambiente local de avaliação.

Os consumidores da fila param antes e voltam depois porque recriar o esquema no
meio de um `queue:work` ativo é uma corrida: o consumidor pode ler ou gravar numa
tabela que está sendo derrubada.

---

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

As credenciais completas de demonstração são apresentadas nesta seção, e os
demais documentos apontam para cá em vez de repeti-las. O único outro literal da
senha aparece no **exemplo de requisição** do `POST /api/auth/login` em
[`openapi.yaml`](openapi.yaml), onde existe para que o contrato importado numa
ferramenta de requisições autentique sem edição.

---

## Os três cenários preparados

O seed monta três situações independentes. Elas não se tocam de propósito:
exercitar uma não interfere nas outras.

### 1. Curso da jornada principal — começa vazio

**`Fundamentos de Producao de Video`**, do produtor de demonstração, em
rascunho, com acesso já concedido ao consumidor.

O curso não tem nenhum módulo e nenhuma aula, e é assim que ele deve estar quando
a demonstração começa. É esse vazio que a jornada preenche: criar o módulo, criar
a aula, enviar o vídeo, publicar e então consumir como o outro perfil.

A jornada parte de um curso **já concedido** porque não existe operação para
conceder acesso a um curso recém-criado — concessões são criadas apenas por seed
nesta entrega. Criar um curso do zero funciona e pode ser demonstrado à parte,
mas ele não apareceria para o consumidor, e a jornada terminaria num catálogo
vazio.

### 2. Curso de outro produtor — para provar isolamento

**`Curso de Outro Produtor`**, pertencente à terceira conta, sem concessão para o
consumidor de demonstração.

Serve de alvo real para verificar duas coisas: que um produtor não alcança o
conteúdo de outro, e que o consumidor não vê cursos que não lhe foram concedidos.
Entrando como `producer@video-platform.test`, ele **não** aparece na lista; e
como `consumer@video-platform.test`, tampouco aparece no catálogo.

### 3. Cenário dedicado de falha de processamento

Um curso **separado** — `Cenario de Falha de Processamento` —, também do produtor
de demonstração, com módulo e aula próprios e uma tentativa de vídeo parada no
estado `processing`.

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
- **nenhum arquivo existe no armazenamento** para ela, e não precisa existir — o
  callback de falha não lê o vídeo.

O curso da jornada principal não é tocado por esse cenário. Acionar a falha não
suja o caminho da demonstração de sucesso.

---

## Roteiro 1 — a jornada principal

Do login do produtor à reprodução pelo consumidor. Leva poucos minutos e não
exige nenhum comando durante o percurso.

**Arquivo de vídeo.** Qualquer MP4 pequeno serve. O repositório já traz um:
`e2e/fixtures/video-curto.mp4` — 1,7 KB, válido, e menor que uma parte do envio
multipart, então a transferência acontece numa única parte.

### Como produtor

1. **Entrar.** Abra <http://localhost:3000> — a plataforma leva à tela de
   entrada porque ainda não há sessão. Preencha **E-mail** com
   `producer@video-platform.test` e **Senha** com `VideoDemo2026!`, e clique em
   **Entrar**.

   A tela **Meus cursos** aparece com os dois cursos deste produtor:
   `Fundamentos de Producao de Video` e `Cenario de Falha de Processamento`. O
   curso do outro produtor **não** está na lista — é o isolamento em ação.

2. **Abrir o curso.** Clique em `Fundamentos de Producao de Video`.

   O curso abre com a etiqueta **Rascunho** e sem nenhum módulo.

3. **Criar o módulo.** No campo **Titulo do modulo**, escreva um título — por
   exemplo `Modulo 1 - Fundamentos` — e clique em **Criar modulo**.

   Ele aparece na posição 1. A posição é do servidor: o formulário nem tem esse
   campo.

4. **Criar a aula.** Dentro do módulo recém-criado, no campo **Nova aula**,
   escreva um título — por exemplo `Aula 1 - Enquadramento` — e clique em
   **Criar aula**.

   A aula nasce **rascunho e sem vídeo**.

5. **Enviar o vídeo.** Na aula, use **Arquivo de video** e selecione o MP4.

   Selecionar o arquivo é a única ação: a interface abre o envio, pede uma URL
   assinada por parte, envia cada parte **direto ao armazenamento** e só então
   pede a conclusão à API. O painel mostra o progresso real, em partes
   concluídas sobre o total.

6. **Acompanhar até o fim.** O painel percorre, sem intervenção:

   | O que aparece | O que está acontecendo |
   | --- | --- |
   | *Transferindo o video* | As partes estão indo para o armazenamento |
   | *Envio concluido* | O servidor verificou o objeto por conta própria |
   | *Enviado, aguardando processamento* | O trabalho está na fila |
   | *Processando o video* | O simulador recebeu a tarefa |
   | **Video pronto** | O callback assinado chegou e o vídeo está `ready` |

   A transição de *Processando* para **Video pronto** costuma levar poucos
   segundos. Nada é clicado nesse intervalo: a tela consulta o estado a cada 3
   segundos e para sozinha ao chegar num estado terminal.

7. **Conferir o vídeo — opcional.** Com **Video pronto** na tela, clique em
   **Visualizar video**.

   O player abre **na própria aula**, sem sair da estrutura do curso, e carrega
   o arquivo que acabou de ser enviado. É o produtor conferindo o resultado
   antes de decidir publicar — e funciona com a aula ainda em **Rascunho**,
   porque aqui a regra é propriedade, e não concessão mais publicação.

   Com o fixture de 1,7 KB sugerido acima há pouco o que assistir; com um MP4 de
   verdade, o vídeo toca normalmente.

   A URL só é pedida no clique, e vale cinco minutos como a do consumidor. É
   outro endereço, com outra regra: `GET /api/lessons/{lesson}/video/playback`.
   A rota nomeada pelo desafio continua sendo a do consumidor, exigindo o que
   sempre exigiu.

   O passo é opcional: pular direto para a publicação não muda nada do que vem
   depois.

8. **Publicar.** Clique em **Publicar aula**.

   Duas coisas mudam ao mesmo tempo: a aula passa a **publicada**, e o curso
   deixa de ser **Rascunho** e passa a **Disponivel** — é a primeira publicação
   do curso que o torna disponível, na mesma transação.

   O botão só existe a partir de `ready`: fora dele a interface não o mostra, e
   o backend recusaria a operação de qualquer forma, com um conflito de regra.
   Esconder é conveniência de tela; quem decide é o servidor.

9. **Sair.** Clique em **Sair**, na barra de sessão.

   É o backend que invalida a sessão; a interface volta ao login.

### Como consumidor

10. **Entrar.** Preencha **E-mail** com `consumer@video-platform.test` e a mesma
    senha, e clique em **Entrar**.

    O **Catalogo** aparece com **um único curso**: `Fundamentos de Producao de
    Video`. Os outros dois cursos do seed não têm concessão para esta conta, e o
    curso só apareceu porque o passo 8 o tornou disponível.

11. **Abrir o curso.** Clique nele.

    A árvore mostra o módulo e a aula criados nos passos 3 e 4 — e **somente**
    conteúdo publicado. Rascunhos não aparecem para o consumidor.

12. **Reproduzir.** Clique na aula.

    A tela pede os dados de reprodução, e o backend refaz as quatro verificações
    antes de assinar: consumidor autenticado, concessão para o curso, aula
    publicada e vídeo `ready`. O player recebe uma **URL assinada válida por
    cinco minutos**, apontando para o armazenamento — a API entrega dados de
    reprodução, nunca o arquivo.

    O vídeo toca. A tela informa até quando a permissão vale.

Fim da jornada: o mesmo conteúdo que o produtor criou e publicou é o que o
consumidor autorizado assiste.

> **A mesma jornada, automatizada.** `make e2e` percorre estes passos em
> navegador real, com títulos fixos — todos menos o passo 7, que é opcional e
> ficou de fora do percurso automatizado de propósito. Ele recria e semeia a base
> antes de começar, então descarta o que estiver criado.

---

## Roteiro 2 — a falha de processamento

Separado do roteiro anterior de propósito. Ele age sobre a tentativa **dedicada**
ao cenário de falha, que pertence ao curso `Cenario de Falha de Processamento` —
e **não** à aula da jornada principal.

```bash
ATTEMPT_ID="01936f1a-7c00-7a3e-9b7d-2f5c8e4a1d60"
docker compose run --rm api php artisan demo:simulate-video-failure "$ATTEMPT_ID"
```

O terminal confirma:

```
INFO  Callback de falha entregue para a tentativa 01936f1a-7c00-7a3e-9b7d-2f5c8e4a1d60.
```

### O que observar

Entre como `producer@video-platform.test`, abra o curso
`Cenario de Falha de Processamento` e olhe a aula
`Aula com Video em Processamento`:

- o painel do vídeo, que antes dizia *Processando o video*, passa a **Falha no
  video**, com a mensagem que a API decidiu — *Nao foi possivel processar o
  video. Envie o arquivo novamente.*;
- o seletor de arquivo **volta a ser oferecido** — `failed` é o único estado a
  partir do qual o backend aceita um envio novo para aquela aula;
- a aula continua **sem poder ser publicada**, porque publicar exige vídeo
  `ready`;
- o curso permanece em **Rascunho**.

**Deixe a tela aberta enquanto executa o comando.** A mudança aparece sozinha em
poucos segundos, na consulta seguinte do acompanhamento — sem recarregar e sem
clicar em nada. O próprio painel avisa disso antes: enquanto está em
`processing`, ele diz *"Esta tela se atualiza sozinha."*

### Por que a falha é acionada assim

O desfecho não é sorteado, e não há gatilho escondido no nome do arquivo — um
gatilho desses é invisível para quem lê o código e frágil para quem escreve o
teste.

O comando **não escreve em tabela de domínio**. Ele aciona o mesmo componente que
o `simulator-worker` usa, com o mesmo HMAC, o mesmo contrato e o mesmo endpoint
HTTP. A única forma de afetar o estado continua sendo o callback assinado — é o
caminho que um provedor externo de verdade usaria.

### Repetir é inofensivo, e é uma demonstração à parte

Execute o mesmo comando uma segunda vez:

```bash
docker compose run --rm api php artisan demo:simulate-video-failure "$ATTEMPT_ID"
```

Ele responde com sucesso outra vez, e **nada muda**. O `event_id` do cenário de
falha é estável — derivado da tentativa e do cenário —, então a segunda entrega
carrega o **mesmo** evento. O webhook reconhece a repetição, repete o desfecho já
registrado e não cria um segundo registro.

É a idempotência do callback demonstrada em duas execuções seguidas: o estado
continua `failed`, com o mesmo código de falha, e a contagem de eventos recebidos
não sobe.

---

## Conferir o cenário sem abrir a interface

Para inspecionar o que o seed preparou, ou o resultado de um dos roteiros:

```bash
docker compose run --rm api php artisan db:table users
docker compose run --rm api php artisan db:table courses
docker compose run --rm api php artisan db:table video_attempts
```

---

## Executar as requisições diretamente

A demonstração acima usa a interface. Quem quiser exercitar a API por fora — para
conferir contratos, códigos de erro ou o isolamento entre contas — encontra o
caminho pronto em:

| Documento | Para quê |
| --- | --- |
| [`api.md`](api.md) | Como autenticar, preservar a sessão e a ordem mínima da jornada, com exemplos executáveis |
| [`openapi.yaml`](openapi.yaml) | O contrato completo, importável em Postman, Insomnia, Bruno ou Swagger UI |
