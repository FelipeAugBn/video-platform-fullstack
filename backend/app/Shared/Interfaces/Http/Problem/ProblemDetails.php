<?php

declare(strict_types=1);

namespace App\Shared\Interfaces\Http\Problem;

use App\Shared\Domain\Failure\Failure;
use Illuminate\Http\JsonResponse;

/**
 * Corpo de erro da API, no formato `application/problem+json` (RFC 9457).
 *
 * Unico lugar que monta uma resposta de erro. Concentrar aqui e o que permite
 * afirmar, com um teste so, que nenhuma resposta de falha carrega rastro de
 * execucao: nao existe um segundo caminho por onde uma mensagem de excecao
 * possa escapar para o corpo.
 *
 * Todo campo vem do caso declarado no catalogo. Nada vem da excecao — nem
 * mensagem, nem classe, nem arquivo, nem linha, nem consulta.
 */
final class ProblemDetails
{
    /**
     * Espaco de nomes dos tipos de problema.
     *
     * E um identificador, nao um endereco: o RFC nao exige que resolva, e o
     * valor precisa ser o mesmo em qualquer ambiente. Derivar da URL da
     * aplicacao daria ao mesmo problema identidades diferentes em
     * desenvolvimento e em producao, e quem consome o contrato passaria a
     * comparar strings que mudam de host — exatamente o que um identificador
     * estavel existe para evitar (plan §10.2).
     */
    private const TYPE_NAMESPACE = 'https://api.example.test/problems/';

    /**
     * @param  array<string, array<int, string>>|null  $errors  Erros por campo; apenas na validacao.
     * @param  array<string, string>  $headers  Cabecalhos que a resposta precisa preservar, ja filtrados na origem.
     * @param  array<string, string|int|bool|null>  $extensions  Membros de extensao do RFC 9457, escolhidos pelo dominio.
     */
    public static function response(
        Failure $failure,
        ?array $errors = null,
        array $headers = [],
        array $extensions = [],
    ): JsonResponse {
        $status = ProblemStatus::of($failure);

        $body = [
            'type' => self::type($failure),
            'title' => $failure->title(),
            'status' => $status,
            'detail' => $failure->detail(),
            'code' => $failure->code(),
        ];

        // `errors` so aparece quando ha erro por campo. Devolver a chave vazia
        // em toda falha obrigaria o cliente a distinguir "sem erros de campo" de
        // "nao e uma falha de validacao", que sao coisas diferentes.
        if ($errors !== null) {
            $body['errors'] = $errors;
        }

        // Extensoes entram **depois** dos campos do RFC e nao podem sobrescrever
        // nenhum deles: um membro chamado `status` ou `code` mudaria o
        // significado da resposta em vez de acrescentar informacao a ela.
        foreach ($extensions as $nome => $valor) {
            if (! array_key_exists($nome, $body)) {
                $body[$nome] = $valor;
            }
        }

        // O tipo de conteudo vem por ultimo e nao e negociavel: um cabecalho
        // preservado da excecao nunca pode trocar o formato do corpo, que e o
        // que o contrato promete.
        return new JsonResponse(
            $body,
            $status,
            [...$headers, 'Content-Type' => 'application/problem+json'],
        );
    }

    /**
     * URI do tipo, derivada do caso declarado — nunca da mensagem de uma
     * excecao. `VALIDATION_FAILED` vira `.../problems/validation-failed`.
     */
    public static function type(Failure $failure): string
    {
        return self::TYPE_NAMESPACE.strtolower(str_replace('_', '-', $failure->code()));
    }
}
