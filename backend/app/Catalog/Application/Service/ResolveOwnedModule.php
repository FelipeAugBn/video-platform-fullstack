<?php

declare(strict_types=1);

namespace App\Catalog\Application\Service;

use App\Catalog\Application\Port\ModuleRepository;
use App\Catalog\Domain\Module;
use App\Shared\Domain\Exception\DomainException;
use App\Shared\Domain\Failure\Failure;

/**
 * "Me da este modulo, se ele for meu" — em um lugar so.
 *
 * O par de `ResolveOwnedCourse`, um nivel abaixo na arvore. A propriedade de um
 * modulo nao esta nele: ela e herdada do curso (RN-PROP-004), e quem a resolve e
 * a consulta, atravessando `module -> course -> owner_id` dentro do SQL.
 *
 * **Por que `404` e nao `403`** (RN-PROP-005, RN-AUT-006): a mesma razao da
 * resolucao de curso. `403` diria "existe, e voce nao pode", e quem varresse
 * identificadores separando `403` de `404` obteria a lista do que existe. Com a
 * mesma resposta para modulo alheio e modulo inexistente, tentar nao ensina
 * nada.
 *
 * A distincao que continua valendo: **perfil errado e `403`** — o middleware
 * recusa o consumidor antes de chegar aqui, e isso nao revela recurso nenhum.
 *
 * Nao usa Policy do Laravel, pelo mesmo motivo: Policy decide sobre um objeto
 * **ja carregado**, e carregar o modulo alheio para so entao recusar e o oposto
 * do que RN-PROP-002 pede.
 */
final class ResolveOwnedModule
{
    public function __construct(private readonly ModuleRepository $modules) {}

    /**
     * O modulo deste dono, com a linha travada ate o fim da transacao.
     *
     * Nesta etapa so existe a forma travada, e nao ha versao sem trava ao lado
     * dela: a unica operacao que resolve um modulo e a criacao de aula, que
     * precisa da trava para calcular a posicao (plan §8.1). Uma leitura sem trava
     * declarada aqui seria uma segunda porta de entrada sem chamador — e a
     * primeira a ser usada por engano.
     */
    public function locked(string $moduleId, string $ownerId): Module
    {
        $modulo = $this->modules->lockOwned($moduleId, $ownerId);

        if ($modulo === null) {
            throw new DomainException(Failure::NOT_FOUND);
        }

        return $modulo;
    }
}
