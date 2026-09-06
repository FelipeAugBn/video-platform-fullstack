<?php

declare(strict_types=1);

namespace App\Shared\Interfaces\Http\Controller;

use App\Models\User;
use Illuminate\Http\Request;

/**
 * "Quem esta consumindo" — escrito uma vez, para os controllers de consumo.
 *
 * Separado de {@see IdentifiesTheOwner} de proposito, embora as duas linhas de
 * codigo sejam iguais. Elas respondem perguntas diferentes: uma diz de quem e o
 * recurso, a outra diz a quem ele foi concedido — propriedade e concessao sao
 * regras distintas (plan §9.3), e um nome so para as duas faria a proxima leitura
 * do codigo confundi-las.
 *
 * O consumidor e sempre quem esta autenticado, **nunca quem a requisicao diz
 * ser**. Nao ha caminho aqui que leia identificador do corpo ou da query string.
 *
 * A afirmacao interna existe pela mesma razao da outra: toda rota que usa este
 * metodo exige `auth:sanctum`, entao chegar aqui sem usuario significaria
 * middleware ausente — nao requisicao anonima.
 */
trait IdentifiesTheConsumer
{
    private function consumidorAutenticado(Request $request): string
    {
        $usuario = $request->user();
        assert($usuario instanceof User);

        return (string) $usuario->getKey();
    }
}
