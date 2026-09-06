<?php

declare(strict_types=1);

use App\Catalog\Interfaces\Http\Controller\CourseController;
use App\Catalog\Interfaces\Http\Controller\LessonController;
use App\Catalog\Interfaces\Http\Controller\ModuleController;
use App\Identity\Interfaces\Http\Controller\AuthController;
use App\Video\Infrastructure\Webhook\VerifyWebhookSignature;
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
