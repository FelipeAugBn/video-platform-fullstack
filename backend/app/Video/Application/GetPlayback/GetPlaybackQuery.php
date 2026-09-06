<?php

declare(strict_types=1);

namespace App\Video\Application\GetPlayback;

/**
 * Qual aula, para qual consumidor.
 *
 * O consumidor vem da sessao. Nao ha parametro de curso: ele e alcancado pela
 * aula dentro da propria consulta, e recebe-lo do cliente permitiria pedir a
 * reproducao de uma aula declarando um curso que nao e o dela.
 */
final class GetPlaybackQuery
{
    public function __construct(
        public readonly string $lessonId,
        public readonly string $consumerId,
    ) {}
}
