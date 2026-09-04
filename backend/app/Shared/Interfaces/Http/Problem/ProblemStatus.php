<?php

declare(strict_types=1);

namespace App\Shared\Interfaces\Http\Problem;

use App\Shared\Domain\Failure\Failure;

/**
 * Traducao entre falha de negocio e status HTTP.
 *
 * Mora na camada de interface de proposito. O dominio decide *o que* falhou; o
 * protocolo pelo qual isso e comunicado e uma decisao de fronteira, e mistura-la
 * ao dominio significaria uma regra de negocio conhecendo HTTP (plan §5.4).
 *
 * Como a enumeracao e fechada, o `match` abaixo e exaustivo por construcao:
 * acrescentar um caso ao catalogo sem decidir o status correspondente vira erro
 * de execucao imediato, e nao um `500` silencioso descoberto em producao.
 *
 * Os valores seguem a tabela de codigos ja aprovada (plan §10.3).
 */
final class ProblemStatus
{
    public static function of(Failure $failure): int
    {
        return match ($failure) {
            Failure::VALIDATION_FAILED => 422,
            Failure::UNAUTHENTICATED => 401,
            Failure::FORBIDDEN => 403,
            Failure::NOT_FOUND => 404,
            Failure::METHOD_NOT_ALLOWED => 405,
            Failure::CSRF_TOKEN_MISMATCH => 419,
            Failure::CONFLICT => 409,
            Failure::SERVICE_UNAVAILABLE => 503,
            Failure::INTERNAL_ERROR => 500,
        };
    }

    /**
     * Caminho inverso: o status que uma excecao HTTP do framework carrega,
     * traduzido para o caso equivalente do catalogo.
     *
     * Serve as excecoes que nascem fora do dominio — rota inexistente, metodo
     * nao permitido, aplicacao em manutencao. Sem isto elas cairiam em `500`, e
     * um erro de quem chamou apareceria como defeito de quem responde.
     *
     * `404` e `405` sao respostas diferentes porque significam coisas
     * diferentes: uma diz que o endereco nao existe, a outra que ele existe e
     * nao aceita aquele metodo. Preservar a distincao segue o significado
     * padrao do HTTP e permite diagnosticar a chamada errada sem adivinhacao —
     * e o cabecalho `Allow`, devolvido junto, ja diz o que fazer.
     *
     * Um status sem caso declarado **nao e preservado**: vira `INTERNAL_ERROR`,
     * e portanto `500`. E a escolha conservadora, e o preco esta a vista — o
     * contrato so promete os codigos da tabela aprovada, e inventar um codigo
     * funcional para um status imprevisto abriria no catalogo exatamente a
     * porta que ele existe para fechar. Quando um novo status entrar no
     * contrato, ele entra aqui e no catalogo, junto.
     */
    public static function fromHttpStatus(int $status): Failure
    {
        return match ($status) {
            401 => Failure::UNAUTHENTICATED,
            403 => Failure::FORBIDDEN,
            404 => Failure::NOT_FOUND,
            405 => Failure::METHOD_NOT_ALLOWED,
            419 => Failure::CSRF_TOKEN_MISMATCH,
            409 => Failure::CONFLICT,
            422 => Failure::VALIDATION_FAILED,
            503 => Failure::SERVICE_UNAVAILABLE,
            default => Failure::INTERNAL_ERROR,
        };
    }
}
