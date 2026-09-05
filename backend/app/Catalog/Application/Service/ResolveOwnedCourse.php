<?php

declare(strict_types=1);

namespace App\Catalog\Application\Service;

use App\Catalog\Application\Port\CourseRepository;
use App\Catalog\Domain\Course;
use App\Shared\Domain\Exception\DomainException;
use App\Shared\Domain\Failure\Failure;

/**
 * "Me da este curso, se ele for meu" — em um lugar so.
 *
 * Tres casos de uso desta tarefa e varios das proximas precisam da mesma
 * sequencia: buscar pelo par identificador/dono e, quando nao houver resultado,
 * recusar. Repetir isso em cada um faria a decisao mais delicada do isolamento
 * existir em copias, livres para divergir — e bastaria uma delas responder `403`
 * para que a existencia de curso alheio passasse a ser detectavel.
 *
 * **Por que `404` e nao `403`** (RN-PROP-005, RN-AUT-006): `403` significa
 * "existe, e voce nao pode". Quem varre identificadores separando `403` de `404`
 * obtem exatamente a lista do que existe. Com a mesma resposta para curso alheio
 * e curso inexistente, tentar nao ensina nada.
 *
 * A distincao que continua valendo: **perfil errado e `403`** — o middleware de
 * perfil recusa o consumidor antes de chegar aqui, e isso nao revela recurso
 * nenhum. Recurso de terceiro e `404`.
 *
 * Nao usa Policy do Laravel de proposito. Policy responde "pode ou nao pode"
 * sobre um objeto **ja carregado**, e carregar o curso alheio para so entao
 * recusar e o oposto do que RN-PROP-002 pede.
 */
final class ResolveOwnedCourse
{
    public function __construct(private readonly CourseRepository $courses) {}

    public function __invoke(string $courseId, string $ownerId): Course
    {
        $curso = $this->courses->findOwned($courseId, $ownerId);

        if ($curso === null) {
            throw new DomainException(Failure::NOT_FOUND);
        }

        return $curso;
    }
}
