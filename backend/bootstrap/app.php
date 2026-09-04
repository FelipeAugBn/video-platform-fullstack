<?php

declare(strict_types=1);

use App\Identity\Interfaces\Http\Middleware\EnsureUserHasRole;
use App\Shared\Interfaces\Http\Problem\ApiExceptionHandler;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Modo SPA do Sanctum: requisicoes vindas dos dominios declarados em
        // `config/sanctum.php` recebem sessao, cookies e protecao CSRF, e
        // autenticam pelo cookie em vez de token (plan §9.1). Sem esta linha as
        // rotas de API seriam stateless e o cookie de sessao nao seria lido.
        $middleware->statefulApi();

        // Perfil na fronteira HTTP. A propriedade do recurso e decidida no caso
        // de uso, e nao aqui (plan §9.3).
        $middleware->alias([
            'role' => EnsureUserHasRole::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // O bootstrap declara que existe tratamento de erro para a API; o
        // formato dele mora na classe, e nao aqui. Concentrar a montagem do
        // corpo neste arquivo transformaria a inicializacao da aplicacao no
        // lugar onde o contrato de resposta e definido — dificil de encontrar e
        // impossivel de testar isoladamente.
        ApiExceptionHandler::register($exceptions);
    })->create();
