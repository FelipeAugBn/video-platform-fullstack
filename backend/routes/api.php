<?php

declare(strict_types=1);

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
