<?php

declare(strict_types=1);

use App\Identity\Interfaces\Http\Middleware\EnsureUserHasRole;
use App\Shared\Domain\Exception\DomainException;
use App\Shared\Interfaces\Http\Problem\ApiExceptionHandler;
use App\Video\Interfaces\Console\SimulateVideoFailureCommand;
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
    /*
    | Comandos declarados um a um, e nao descobertos por diretorio.
    |
    | A descoberta automatica do framework varre `app/Console/Commands`, que nao
    | existe nesta organizacao: cada area guarda os proprios pontos de entrada em
    | `Interfaces`, e o console e um deles tanto quanto o HTTP (plan §5.2). Mover
    | o comando para o diretorio convencional o separaria do dominio que ele
    | aciona.
    |
    | A lista explicita tambem diz, em um lugar so, qual e a superficie de
    | console desta entrega: um comando.
    */
    ->withCommands([
        SimulateVideoFailureCommand::class,
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        // Modo SPA do Sanctum: requisicoes vindas dos dominios declarados em
        // `config/sanctum.php` recebem sessao, cookies e protecao CSRF, e
        // autenticam pelo cookie em vez de token (plan §9.1). Sem esta linha as
        // rotas de API seriam stateless e o cookie de sessao nao seria lido.
        $middleware->statefulApi();

        // Visitante nao autenticado nao e redirecionado para lugar nenhum.
        //
        // O middleware de autenticacao do framework monta, por padrao, um
        // redirecionamento para uma rota chamada `login` — e so deixa de faze-lo
        // quando a requisicao declara esperar JSON. Esta aplicacao nao tem tela
        // de login: ela e uma API, e a rota com esse nome nao existe. O efeito
        // era um `RouteNotFoundException` lancado **dentro do middleware**, antes
        // de qualquer tratamento, que o handler classificava como defeito
        // generico: `500` no lugar do `401` previsto.
        //
        // A condicao que disparava isso era o cabecalho `Accept`. Um cliente que
        // pedisse JSON recebia `401`; um navegador, apontado para a mesma URL,
        // recebia `500`. O contrato da API nao pode depender de um cabecalho que
        // quem chama controla.
        //
        // Devolvendo `null`, a excecao de autenticacao nasce sem destino de
        // redirecionamento, segue para o tratamento centralizado e vira a
        // resposta declarada no catalogo (RN-AUT-005, plan §10.3). Nenhuma rota
        // ficticia e criada, e nenhum cabecalho `Location` e emitido.
        $middleware->redirectGuestsTo(null);

        // O callback de processamento nao valida token CSRF.
        //
        // Ele nao vem de um navegador: quem o emite e um servico, e a origem e
        // provada por assinatura HMAC (plan §13.4). Um token CSRF exige sessao,
        // e nao ha sessao do lado de la para conter um.
        //
        // A dispensa e **explicita**, com o endereco escrito. Sem ela, a rota ja
        // funcionaria — a protecao de sessao do Sanctum so age sobre origens
        // reconhecidas como first-party, e o simulador nao e uma delas —, mas
        // isso dependeria de um cabecalho `Origin` que quem chama controla. Uma
        // garantia que o proprio requisitante pode mudar nao e garantia.
        $middleware->validateCsrfTokens(except: [
            'api/webhooks/video-processing',
        ]);

        // Perfil na fronteira HTTP. A propriedade do recurso e decidida no caso
        // de uso, e nao aqui (plan §9.3).
        $middleware->alias([
            'role' => EnsureUserHasRole::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Desfecho funcional conhecido nao e defeito, e nao vai para o log de
        // erro.
        //
        // `DomainException` carrega um caso do catalogo fechado — `NOT_FOUND`,
        // `CONFLICT` — que a aplicacao **escolheu** produzir. Registra-la com
        // pilha completa encheria o log de ocorrencias esperadas: cada consulta
        // a um curso que nao existe viraria uma entrada de erro, e as falhas de
        // verdade ficariam soterradas nelas.
        //
        // A conversao em resposta continua acontecendo normalmente; o que muda e
        // apenas o relato. Excecao inesperada segue sendo reportada por inteiro,
        // e continua respondendo `500` generico sem vazar nada ao cliente.
        $exceptions->dontReport(DomainException::class);

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
