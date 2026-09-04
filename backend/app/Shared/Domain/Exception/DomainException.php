<?php

declare(strict_types=1);

namespace App\Shared\Domain\Exception;

use App\Shared\Domain\Failure\Failure;
use RuntimeException;

/**
 * Falha de regra de negocio, carregando um caso declarado do catalogo.
 *
 * O construtor aceita apenas um caso da enumeracao. Nao ha parametro de
 * mensagem, e isso e o ponto: uma excecao de dominio nao pode carregar texto
 * livre ate a resposta HTTP, porque e exatamente assim que detalhe interno
 * vaza — o retorno de um driver, o trecho de uma consulta, o caminho de um
 * arquivo. Quem lanca escolhe o que ja foi declarado como publico.
 *
 * A mensagem entregue ao `RuntimeException` e o proprio codigo funcional. Ela
 * nao chega ao corpo da resposta em nenhuma situacao; existe para o log e para
 * o rastreamento durante o desenvolvimento.
 *
 * PHP puro, como todo o `Domain`: estende a hierarquia de excecoes da linguagem
 * e nao conhece framework, HTTP nem persistencia (plan §5.1).
 *
 * As areas estendem esta classe quando precisam de uma falha propria com nome
 * de dominio — uma aula que nao pode ser publicada, um envio ja concluido. O
 * caso do catalogo continua sendo obrigatorio.
 */
class DomainException extends RuntimeException
{
    public function __construct(private readonly Failure $failure)
    {
        parent::__construct($failure->code());
    }

    public function failure(): Failure
    {
        return $this->failure;
    }
}
