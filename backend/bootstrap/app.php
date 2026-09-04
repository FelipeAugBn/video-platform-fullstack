<?php

declare(strict_types=1);

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
        //
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
