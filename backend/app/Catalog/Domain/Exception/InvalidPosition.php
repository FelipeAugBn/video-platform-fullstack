<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Exception;

use InvalidArgumentException;

/**
 * Posicao fora do conjunto valido dentro do pai.
 *
 * **Nao estende `DomainException` de proposito**, e a diferenca e a que importa
 * neste arquivo. `DomainException` carrega um caso publico do catalogo e
 * descreve um desfecho que a aplicacao escolheu produzir — um recurso que nao
 * existe para aquele produtor, uma aula que ainda nao pode ser publicada. Esses
 * desfechos sao esperados, viram resposta e nao vao para o log de erro.
 *
 * Uma posicao invalida nao e nada disso. O cliente nao informa posicao: ela e
 * calculada pelo backend a partir do maior valor existente no pai (RN-ORD-002).
 * Um valor abaixo de um so pode chegar aqui por defeito de codigo ou por linha
 * adulterada no banco — e as duas coisas precisam ser **registradas** e virar
 * `500` generico, e nao ser silenciadas como se fossem um desfecho previsto.
 *
 * Estendendo `InvalidArgumentException`, ela segue o caminho das excecoes
 * inesperadas: o log recebe o registro completo, e o cliente recebe a resposta
 * generica que nao revela nada (RN-AUT-005).
 *
 * PHP puro, como todo o `Domain`.
 */
final class InvalidPosition extends InvalidArgumentException
{
    public static function below(int $position): self
    {
        return new self("A posicao precisa ser maior ou igual a 1; recebido {$position}.");
    }
}
