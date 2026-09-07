<?php

declare(strict_types=1);

namespace App\Video\Application\GetOwnedPlayback;

use App\Catalog\Application\Port\LessonRepository;
use App\Shared\Domain\Exception\DomainException;
use App\Shared\Domain\Failure\Failure;
use App\Video\Application\IssuePlayback\IssuePlayback;
use App\Video\Application\IssuePlayback\Playback;

/**
 * O produtor proprietario reproduz o video da propria aula (RF-PLB-009,
 * AC-PROD-008; plan §14.2).
 *
 * ## Por que existe, ao lado de {@see \App\Video\Application\GetPlayback\GetPlayback}
 *
 * Quem envia um video precisa conferir o que enviou antes de decidir publicar.
 * A rota do consumidor nao serve para isso, e nao por falta de vontade: ela
 * exige concessao de acesso ao curso e aula publicada — duas condicoes que o
 * produtor, por definicao, nao cumpre sobre o proprio rascunho.
 *
 * ## Duas politicas, dois casos de uso
 *
 * |               | Consumidor                  | Produtor                    |
 * | ------------- | --------------------------- | --------------------------- |
 * | Perfil        | `consumer`                  | `producer`                   |
 * | Autorizacao   | concessao ao curso          | propriedade da aula          |
 * | Publicacao    | exigida                     | indiferente                  |
 *
 * A tabela e a lista inteira do que difere. Fora dela, os dois caminhos sao o
 * mesmo, e por construcao: a exigencia de video em `ready` com referencia, a
 * emissao da URL, a porta `ObjectStorage`, o prazo, a traducao da falha ao
 * assinar e o formato da resposta vivem todos em `IssuePlayback`. Dois casos de
 * uso de autorizacao, **uma** implementacao que assina.
 *
 * O que **nao** foi feito e juntar as politicas num caso de uso so com um `if`
 * por perfil: seria uma regra de autorizacao escolhida pelo tipo de quem chama,
 * no ponto exato em que a aplicacao assina uma credencial temporaria.
 *
 * ## A propriedade e resolvida dentro da consulta
 *
 * `findOwned` percorre `lesson -> module -> course -> owner_id` no SQL e devolve
 * `null` tanto para aula inexistente quanto para aula alheia. As duas recusas
 * sao o mesmo `404`, pelo motivo de sempre: `403` diria "existe, e nao e sua", e
 * quem varresse identificadores separando os dois codigos obteria a lista do que
 * existe (RN-PROP-005, RN-AUT-006, AC-PROD-007).
 *
 * ## Leitura pura
 *
 * Sem transacao e sem trava: nada e decidido sobre o estado, nada e gravado.
 * Conferir o proprio video nao muda a aula, nao muda a tentativa e nao publica
 * nada — publicar continua sendo uma acao explicita e separada (RF-AUL-006).
 */
final class GetOwnedPlayback
{
    public function __construct(
        private readonly LessonRepository $lessons,
        private readonly IssuePlayback $emitirReproducao,
    ) {}

    /**
     * @throws DomainException quando a aula nao existe, nao e deste produtor ou
     *                         nao tem video reproduzivel
     */
    public function __invoke(GetOwnedPlaybackQuery $consulta): Playback
    {
        $aula = $this->lessons->findOwned($consulta->lessonId, $consulta->ownerId);

        if ($aula === null) {
            throw new DomainException(Failure::NOT_FOUND);
        }

        // Nao ha verificacao de publicacao aqui, e a ausencia e a decisao: o
        // caso de uso existe justamente para a aula em rascunho.
        return ($this->emitirReproducao)($aula);
    }
}
