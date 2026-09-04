<?php

declare(strict_types=1);

use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Laravel\Sanctum\Http\Middleware\AuthenticateSession;

return [

    /*
    |--------------------------------------------------------------------------
    | Dominios first-party
    |--------------------------------------------------------------------------
    |
    | Requisicoes vindas destes hosts sao tratadas como da propria aplicacao e
    | autenticam pela sessao, e nao por token. E o que caracteriza o modo SPA do
    | Sanctum (plan §9.1).
    |
    | A lista e explicita e vem do ambiente. O padrao do pacote inclui varios
    | hosts de conveniencia; aqui declaramos apenas o que este projeto usa, para
    | que a fronteira do que e "first-party" seja legivel e nao herdada.
    |
    */

    'stateful' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('SANCTUM_STATEFUL_DOMAINS', 'localhost:3000')),
    ))),

    /*
    |--------------------------------------------------------------------------
    | Guard consultado
    |--------------------------------------------------------------------------
    |
    | Apenas o guard de sessao. Nao ha emissao de token pessoal nesta entrega, e
    | portanto nao ha um segundo caminho de autenticacao a consultar.
    |
    */

    'guard' => ['web'],

    /*
    |--------------------------------------------------------------------------
    | Expiracao de token
    |--------------------------------------------------------------------------
    |
    | Nulo porque nao existem tokens. A validade da autenticacao e a da sessao,
    | configurada em `config/session.php`.
    |
    */

    'expiration' => null,

    'token_prefix' => '',

    /*
    |--------------------------------------------------------------------------
    | Middleware do fluxo stateful
    |--------------------------------------------------------------------------
    |
    | `PreventRequestForgery` e o nome atual do middleware de CSRF do framework.
    | Declara-lo aqui e o que faz a protecao valer para as rotas de API tratadas
    | como first-party — e o que produz o `419` quando o token falta ou nao
    | confere (RF-AUT-009).
    |
    */

    'middleware' => [
        'authenticate_session' => AuthenticateSession::class,
        'encrypt_cookies' => EncryptCookies::class,
        'validate_csrf_token' => PreventRequestForgery::class,
    ],

];
