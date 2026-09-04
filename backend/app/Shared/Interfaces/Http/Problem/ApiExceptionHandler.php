<?php

declare(strict_types=1);

namespace App\Shared\Interfaces\Http\Problem;

use App\Shared\Domain\Exception\DomainException;
use App\Shared\Domain\Failure\Failure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Tratamento centralizado das falhas da API.
 *
 * Registrado a partir do bootstrap da aplicacao, que apenas delega: a
 * inicializacao diz *que* existe um tratamento de erros; o formato dele mora
 * aqui, onde pode ser lido e testado sem abrir o arquivo de bootstrap.
 *
 * Atua somente sobre `api/*`. O endpoint de saude e qualquer resposta fora do
 * prefixo seguem o comportamento padrao do framework — devolver `null` no
 * callback e o que deixa a corrente de tratamento continuar.
 */
final class ApiExceptionHandler
{
    public static function register(Exceptions $exceptions): void
    {
        $exceptions->render(
            static fn (Throwable $e, Request $request): ?JsonResponse => $request->is('api/*')
                ? self::render($e)
                : null
        );
    }

    /**
     * Traduz a excecao em um caso declarado do catalogo.
     *
     * A ordem importa. Quando este callback executa, o framework ja normalizou
     * parte das excecoes: `ModelNotFoundException` chega como excecao HTTP de
     * `404` e falha de autorizacao chega como `403`. As duas verificacoes
     * explicitas permanecem porque este metodo tambem e alcancado por quem
     * chama fora desse caminho, e depender da normalizacao seria depender de um
     * detalhe interno do framework.
     */
    private static function render(Throwable $e): JsonResponse
    {
        return match (true) {
            // Unico caso com erros por campo. Os textos vem do validador, que
            // trabalha sobre a entrada da requisicao, e nao sobre estado interno.
            $e instanceof ValidationException => ProblemDetails::response(
                Failure::VALIDATION_FAILED,
                $e->errors(),
            ),

            // Falha de regra de negocio: o caso ja veio escolhido do dominio.
            $e instanceof DomainException => ProblemDetails::response($e->failure()),

            $e instanceof AuthenticationException => ProblemDetails::response(Failure::UNAUTHENTICATED),
            $e instanceof ModelNotFoundException => ProblemDetails::response(Failure::NOT_FOUND),

            // Excecoes do proprio protocolo — rota inexistente, metodo nao
            // permitido, manutencao. Cada uma vira o caso do catalogo
            // equivalente ao status que ela carrega.
            //
            // E aqui que `MethodNotAllowedHttpException` e atendida: alem do
            // `405`, ela traz em `Allow` os metodos aceitos naquele endereco, e
            // devolver essa lista e o que transforma a resposta em algo
            // acionavel em vez de apenas negativo.
            $e instanceof HttpExceptionInterface => ProblemDetails::response(
                ProblemStatus::fromHttpStatus($e->getStatusCode()),
                null,
                self::safeHeaders($e),
            ),

            // Qualquer outra coisa e defeito, e defeito nao se descreve para
            // quem chamou. A resposta e a mesma sempre, inclusive com depuracao
            // ligada: nada do objeto original — mensagem, classe, arquivo,
            // linha, consulta ou pilha — atravessa esta fronteira. O registro
            // completo continua indo para o log, que e onde ele serve a alguem
            // (RN-AUT-005).
            default => ProblemDetails::response(Failure::INTERNAL_ERROR),
        };
    }

    /**
     * Cabecalhos que a resposta de erro pode carregar da excecao.
     *
     * Lista fechada, pelo mesmo motivo do catalogo de falhas: uma excecao HTTP
     * pode trazer qualquer cabecalho, e repassar todos abriria um caminho para
     * detalhe interno sair pela porta dos metadados em vez de pela do corpo.
     *
     *   Allow        metodos aceitos no endereco, no `405`
     *   Retry-After  quando tentar de novo, na indisponibilidade transitoria
     *
     * @return array<string, string>
     */
    private static function safeHeaders(HttpExceptionInterface $e): array
    {
        $permitidos = ['allow', 'retry-after'];
        $headers = [];

        foreach ($e->getHeaders() as $nome => $valor) {
            if (in_array(strtolower((string) $nome), $permitidos, true)) {
                $headers[(string) $nome] = (string) $valor;
            }
        }

        return $headers;
    }
}
