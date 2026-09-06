<?php

declare(strict_types=1);

namespace App\Video\Domain\Exception;

use App\Shared\Domain\Exception\DomainException;
use App\Shared\Domain\Failure\Failure;
use App\Video\Domain\VideoState;

/**
 * Uma transicao fora da tabela de `VideoState` foi solicitada ao agregado.
 *
 * Esta excecao **nao e o mecanismo normal de recusa** de nenhum fluxo desta
 * entrega. Todo caminho que pode encontrar um estado inelegivel — publicar uma
 * aula, aplicar um callback fora de ordem, iniciar o processamento de uma
 * tentativa ja encerrada — pergunta a `canTransitionTo()` antes e decide o que
 * responder, porque cada um responde uma coisa diferente. Chegar aqui significa
 * que alguem mudou o agregado sem perguntar: e defeito de programacao, nao
 * desfecho de negocio.
 *
 * E por isso que o caso do catalogo e o `CONFLICT` generico, e nao um codigo
 * proprio. Um codigo proprio anunciaria a interface um estado que ela deveria
 * saber tratar, quando na verdade este `409` so aparece se houver um bug no
 * chamador.
 *
 * A guarda permanece mesmo com todos os chamadores perguntando antes: e ela que
 * faz a tabela de transicoes valer para o agregado inteiro, e nao apenas para os
 * caminhos que alguem lembrou de proteger.
 *
 * PHP puro, como todo o `Domain`.
 */
final class InvalidVideoTransition extends DomainException
{
    public function __construct(
        public readonly VideoState $from,
        public readonly VideoState $to,
    ) {
        parent::__construct(Failure::CONFLICT);
    }
}
