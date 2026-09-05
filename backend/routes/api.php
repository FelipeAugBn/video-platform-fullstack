<?php

declare(strict_types=1);

use App\Catalog\Interfaces\Http\Controller\CourseController;
use App\Identity\Interfaces\Http\Controller\AuthController;
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
| As tres rotas compartilham as mesmas duas exigencias, declaradas uma unica vez
| no grupo: sessao valida e perfil de produtor. Repeti-las rota a rota abriria a
| chance de uma nova nascer sem uma delas — e a que faltasse seria justamente a
| que ninguem testaria.
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
});
