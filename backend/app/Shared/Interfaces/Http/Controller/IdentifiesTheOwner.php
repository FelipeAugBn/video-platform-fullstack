<?php

declare(strict_types=1);

namespace App\Shared\Interfaces\Http\Controller;

use App\Models\User;
use Illuminate\Http\Request;

/**
 * "Quem esta autenticado" — escrito uma vez, para todos os controllers.
 *
 * O dono de uma operacao e sempre quem esta autenticado, **nunca quem a
 * requisicao diz ser**. A regra e curta, mas ela aparece em todas as acoes do
 * catalogo, e copia-la em cada controller significaria que uma delas poderia
 * passar a ler o dono do corpo sem que a diferenca chamasse atencao na revisao.
 *
 * A afirmacao interna existe pela mesma razao. Toda rota que usa este metodo
 * exige `auth:sanctum`, entao chegar aqui sem usuario significaria middleware
 * ausente — nao requisicao anonima. Falhar alto em desenvolvimento e melhor do
 * que gravar um recurso sem dono no banco.
 */
trait IdentifiesTheOwner
{
    private function donoAutenticado(Request $request): string
    {
        $usuario = $request->user();
        assert($usuario instanceof User);

        return (string) $usuario->getKey();
    }
}
