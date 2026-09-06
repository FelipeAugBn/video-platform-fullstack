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

    /**
     * Membros de extensao do corpo do problema (RFC 9457).
     *
     * Vazio por padrao, e quase sempre e o que se quer: o codigo do catalogo ja
     * identifica a falha, e cada campo a mais e um campo que o contrato passa a
     * manter. Uma subclasse o sobrescreve quando a regra exige informar algo
     * **alem** de qual falha ocorreu — a recusa de um novo envio precisa dizer
     * qual estado a impede (RF-UPL-011, AC-VID-011), e sem isso a interface
     * teria de fazer uma segunda requisicao para descobrir.
     *
     * O que pode entrar aqui e o mesmo que ja poderia sair pela API por outro
     * caminho: valor de enumeracao, identificador publico. Nunca texto vindo de
     * excecao, driver ou servico externo — a garantia de RN-AUT-005 continua
     * valendo, e continua valendo por construcao, porque o tipo de retorno so
     * admite escalares e quem os escolhe e a subclasse do dominio.
     *
     * @return array<string, string|int|bool|null>
     */
    public function extensions(): array
    {
        return [];
    }
}
