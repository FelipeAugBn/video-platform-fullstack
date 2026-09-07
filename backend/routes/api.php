<?php

declare(strict_types=1);

use App\Catalog\Interfaces\Http\Controller\ConsumerCatalogController;
use App\Catalog\Interfaces\Http\Controller\CourseController;
use App\Catalog\Interfaces\Http\Controller\LessonController;
use App\Catalog\Interfaces\Http\Controller\ModuleController;
use App\Identity\Interfaces\Http\Controller\AuthController;
use App\Video\Infrastructure\Webhook\VerifyWebhookSignature;
use App\Video\Interfaces\Http\Controller\OwnedPlaybackController;
use App\Video\Interfaces\Http\Controller\PlaybackController;
use App\Video\Interfaces\Http\Controller\ProcessingCallbackController;
use App\Video\Interfaces\Http\Controller\VideoUploadController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Rotas da API
|--------------------------------------------------------------------------
|
| Carregadas com o prefixo `api` e o grupo de middleware de mesmo nome.
|
| O endpoint que planta o cookie CSRF — `GET /sanctum/csrf-cookie` — nao esta
| aqui: ele e registrado pelo proprio Sanctum, fora do prefixo `api`, e ja
| chega com o middleware de sessao. Redeclara-lo criaria uma segunda rota para
| o mesmo trabalho.
|
| O endpoint de saude tambem fica de fora: `/up` e servido fora deste prefixo, e
| e o que o healthcheck do servico web consulta.
|
| As rotas que exercitam contrato e autorizacao nos testes sao registradas pela
| propria suite e nao existem na aplicacao em execucao. Endpoint ficticio em
| arquivo de rotas de producao e superficie que ninguem pediu.
|
*/

Route::prefix('auth')->name('auth.')->group(function (): void {
    Route::post('login', [AuthController::class, 'login'])->name('login');

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::get('me', [AuthController::class, 'me'])->name('me');
        Route::post('logout', [AuthController::class, 'logout'])->name('logout');
    });
});

/*
| Catalogo do produtor.
|
| As rotas deste grupo compartilham as mesmas duas exigencias, declaradas uma
| unica vez no grupo: sessao valida e perfil de produtor. Repeti-las rota a rota
| abriria a chance de uma nova nascer sem uma delas — e a que faltasse seria
| justamente a que ninguem testaria.
|
| A ordem importa: `auth:sanctum` responde `401` a quem nao esta autenticado, e
| so entao `role:producer` responde `403` a quem esta autenticado com o perfil
| errado. Invertida, um visitante anonimo receberia `403` e saberia que existe
| algo ali para um perfil especifico.
|
| Nao existe `PUT`, `PATCH` nem `DELETE`: alterar e excluir curso nao fazem parte
| desta entrega, e uma rota declarada sem caso de uso e superficie exposta sem
| comportamento definido.
*/
Route::middleware(['auth:sanctum', 'role:producer'])->group(function (): void {
    Route::post('courses', [CourseController::class, 'store'])->name('courses.store');
    Route::get('courses', [CourseController::class, 'index'])->name('courses.index');

    // A restricao de formato faz o roteador recusar um identificador malformado
    // antes de qualquer codigo da aplicacao rodar. O resultado e o mesmo `404`
    // generico do curso inexistente, o que e desejavel: um identificador
    // invalido nao merece uma resposta diferente de um identificador que apenas
    // nao existe.
    Route::get('courses/{course}', [CourseController::class, 'show'])
        ->whereUuid('course')
        ->name('courses.show');

    /*
    | A arvore do curso, e as duas colecoes que a compoem.
    |
    | `structure` e uma leitura so, e nao a soma de tres chamadas do cliente: o
    | frontend precisa da arvore inteira para desenhar a tela do produtor, e
    | montá-la a partir de uma listagem de modulos mais uma de aulas por modulo
    | seria o mesmo N+1, transferido para a rede (RF-EST-001).
    |
    | O parametro de rota e restrito ao formato UUID nas cinco rotas. O roteador
    | recusa um identificador malformado antes de qualquer codigo da aplicacao
    | rodar, e o resultado e o mesmo `404` generico do recurso inexistente — o
    | que e desejavel: um identificador invalido nao merece resposta diferente de
    | um identificador que apenas nao existe.
    */
    Route::get('courses/{course}/structure', [CourseController::class, 'structure'])
        ->whereUuid('course')
        ->name('courses.structure');

    Route::post('courses/{course}/modules', [ModuleController::class, 'store'])
        ->whereUuid('course')
        ->name('courses.modules.store');

    Route::get('courses/{course}/modules', [ModuleController::class, 'index'])
        ->whereUuid('course')
        ->name('courses.modules.index');

    /*
    | Aulas entram pelo modulo e saem pela propria identidade.
    |
    | Nao existe `GET /api/modules/{module}/lessons`. As aulas de um modulo ja
    | aparecem na estrutura, e uma aula isolada e consultavel pelo proprio
    | endereco: uma terceira forma de ler a mesma lista seria superficie a mais
    | com o mesmo conteudo.
    |
    | Tambem nao existem `PUT`, `PATCH`, `DELETE` nem reordenacao. Alterar,
    | excluir e reordenar nao fazem parte desta entrega — reordenacao e escopo
    | opcional pelo proprio desafio (RF-MOD-005) —, e uma rota declarada sem caso
    | de uso e superficie exposta sem comportamento definido.
    */
    Route::post('modules/{module}/lessons', [LessonController::class, 'store'])
        ->whereUuid('module')
        ->name('modules.lessons.store');

    Route::get('lessons/{lesson}', [LessonController::class, 'show'])
        ->whereUuid('lesson')
        ->name('lessons.show');

    /*
    | Publicacao da aula.
    |
    | `POST`, e nao `PUT` sobre um campo: publicar e uma acao explicita do
    | produtor com regra propria — exige video pronto e referencia de reproducao
    | presentes (RN-PUB-006) —, e nao a atribuicao de um valor. Um `PATCH` que
    | aceitasse `published_at` convidaria o cliente a escolher o instante.
    |
    | Nao existe rota para despublicar: ela nao esta no desafio, e uma rota
    | declarada sem caso de uso e superficie exposta sem comportamento definido.
    */
    Route::post('lessons/{lesson}/publish', [LessonController::class, 'publish'])
        ->whereUuid('lesson')
        ->name('lessons.publish');

    /*
    | Envio de video.
    |
    | Duas rotas entram pela **aula** e duas pela **tentativa**, e a diferenca
    | nao e estilistica. Abrir um envio e consultar o estado sao operacoes sobre
    | a aula: quem as chama sabe qual aula quer, e nao qual tentativa existe.
    | Pedir a URL de uma parte e concluir sao operacoes sobre a tentativa, que ja
    | foi identificada na resposta da abertura — enderecá-las pela aula obrigaria
    | o servidor a redescobrir a tentativa atual a cada parte, e uma substituicao
    | no meio do envio faria as partes seguintes irem para o objeto errado.
    |
    | As quatro exigem sessao e perfil de produtor, herdados deste grupo, e as
    | quatro resolvem a propriedade dentro da consulta.
    |
    | **Nao ha rota que receba bytes.** O conteudo vai do navegador direto ao
    | armazenamento, com as URLs pre-assinadas que a rota de parte emite — e e
    | assim que RNF-001 e AC-VID-001 sao atendidos.
    */
    Route::post('lessons/{lesson}/video/uploads', [VideoUploadController::class, 'store'])
        ->whereUuid('lesson')
        ->name('lessons.video.uploads.store');

    Route::get('lessons/{lesson}/video', [VideoUploadController::class, 'show'])
        ->whereUuid('lesson')
        ->name('lessons.video.show');

    // O numero da parte e restrito a digitos pelo roteador. Um valor nao
    // numerico recebe o mesmo `404` de rota inexistente, antes de qualquer
    // codigo da aplicacao rodar — e a conversao no controller deixa de poder
    // esconder entrada invalida.
    Route::post('video-uploads/{attempt}/parts/{part}/url', [VideoUploadController::class, 'partUrl'])
        ->whereUuid('attempt')
        ->whereNumber('part')
        ->name('video-uploads.parts.url');

    Route::post('video-uploads/{attempt}/complete', [VideoUploadController::class, 'complete'])
        ->whereUuid('attempt')
        ->name('video-uploads.complete');

    /*
    | Conferencia do proprio video, antes de decidir publicar (RF-PLB-009).
    |
    | Devolve o mesmo corpo de `GET /api/lessons/{lesson}/playback`, e mesmo
    | assim e outro endereco. O que separa as duas e a **politica**: la a
    | exigencia e perfil de consumidor, concessao ao curso e aula publicada;
    | aqui e perfil de produtor e propriedade da aula, e o rascunho e justamente
    | o caso que importa. Um endereco unico obrigaria a rota a escolher essa
    | politica pelo perfil de quem chamou — o mesmo motivo pelo qual o catalogo
    | do consumidor vive sob `catalog/` em vez de dividir `GET /api/courses` com
    | o produtor.
    |
    | Fora da politica, os dois caminhos sao o mesmo: mesma exigencia de video em
    | `ready` com referencia, mesma emissao por `IssuePlayback`, mesma porta
    | `ObjectStorage`, mesmo prazo, mesma traducao de falha ao assinar e mesmo
    | formato de resposta. Duas rotas e duas autorizacoes, **uma** implementacao
    | que assina a URL.
    |
    | Fica sob `lessons/{lesson}/video/` porque e o video da aula que se
    | reproduz, ao lado da consulta de estado e da abertura de envio. Reunidas,
    | as tres operacoes de video do produtor tem o mesmo prefixo e a mesma
    | resolucao de propriedade dentro da consulta.
    */
    Route::get('lessons/{lesson}/video/playback', OwnedPlaybackController::class)
        ->whereUuid('lesson')
        ->name('lessons.video.playback');
});

/*
| Catalogo do consumidor e reproducao.
|
| Mesma estrutura do grupo anterior, com o perfil trocado: sessao valida e
| perfil de consumidor, declarados uma vez para as tres rotas. A ordem tambem e
| a mesma — `auth:sanctum` responde `401` a quem nao esta autenticado, e so
| entao `role:consumer` responde `403` a um produtor autenticado.
|
| **Perfil nao e concessao.** Passar por `role:consumer` nao da acesso a curso
| nenhum: quem decide isso e o caso de uso, dentro da consulta, e a negativa la
| e `404` — indistinguivel da de um curso inexistente (plan §9.3). As duas
| camadas existem porque respondem perguntas diferentes, e a de fora nao
| substitui a de dentro.
|
| As rotas de catalogo ficam sob `catalog/` para nao colidirem com
| `GET /api/courses`, que e a listagem do produtor. Sao coleccoes diferentes,
| com regras de acesso diferentes, e um mesmo endereco servindo as duas
| obrigaria a rota a escolher a regra pelo perfil de quem chamou.
|
| Nao existe `POST`, `PUT` nem `DELETE`: o consumidor le. Conceder acesso nao e
| operacao desta entrega — a concessao vem do seed (RF-CONS-006).
*/
Route::middleware(['auth:sanctum', 'role:consumer'])->group(function (): void {
    Route::get('catalog/courses', [ConsumerCatalogController::class, 'index'])
        ->name('catalog.courses.index');

    Route::get('catalog/courses/{course}', [ConsumerCatalogController::class, 'show'])
        ->whereUuid('course')
        ->name('catalog.courses.show');

    /*
    | Reproducao — a segunda rota nomeada literalmente pelo desafio, preservada
    | como esta escrita la.
    |
    | Ela e um `GET` e nao muda nada: o que devolve sao **dados de reproducao**,
    | e nao o arquivo (RF-PLB-005). Os bytes vao do armazenamento direto ao
    | player, pela URL assinada de cinco minutos que a resposta carrega — a
    | aplicacao autoriza e sai do caminho.
    */
    Route::get('lessons/{lesson}/playback', PlaybackController::class)
        ->whereUuid('lesson')
        ->name('lessons.playback');
});

/*
| Callback de processamento — a rota nomeada literalmente pelo desafio.
|
| **Fora do grupo autenticado, e de proposito.** O emissor e um servico, nao um
| navegador: nao ha sessao a validar, nao ha perfil a exigir e nao ha token CSRF
| a conferir. Exigir `auth:sanctum` aqui obrigaria o simulador a manter uma
| sessao de usuario, que e exatamente o que um provedor externo nao tem.
|
| O que substitui a sessao e a assinatura HMAC, conferida pelo middleware antes
| do controller: sem ela, ou fora da janela de cinco minutos, a requisicao recebe
| `401` sem produzir efeito (RF-WHK-001, plan §13.4).
|
| A dispensa de CSRF e declarada tambem na inicializacao da aplicacao, com o
| endereco explicito. Depender apenas de a origem nao ser reconhecida como
| first-party seria depender de um cabecalho que quem chama controla.
*/
Route::post('webhooks/video-processing', ProcessingCallbackController::class)
    ->middleware(VerifyWebhookSignature::class)
    ->name('webhooks.video-processing');
