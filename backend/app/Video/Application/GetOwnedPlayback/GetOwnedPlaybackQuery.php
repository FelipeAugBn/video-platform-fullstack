<?php

declare(strict_types=1);

namespace App\Video\Application\GetOwnedPlayback;

/**
 * Qual aula, para qual produtor.
 *
 * O produtor vem da sessao, nunca do corpo ou da query string. Nao ha parametro
 * de curso nem de tentativa: os dois sao alcancados pela aula dentro da propria
 * consulta, e recebe-los do cliente permitiria pedir a reproducao de uma aula
 * declarando um curso que nao e o dela.
 */
final class GetOwnedPlaybackQuery
{
    public function __construct(
        public readonly string $lessonId,
        public readonly string $ownerId,
    ) {}
}
