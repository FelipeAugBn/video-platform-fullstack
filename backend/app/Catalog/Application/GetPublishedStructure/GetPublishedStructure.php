<?php

declare(strict_types=1);

namespace App\Catalog\Application\GetPublishedStructure;

use App\Catalog\Application\Port\ConsumerCatalogReadModel;
use App\Catalog\Application\View\ConsumerStructureView;
use App\Identity\Application\Port\AccessGrantRepository;
use App\Shared\Domain\Exception\DomainException;
use App\Shared\Domain\Failure\Failure;

/**
 * A arvore de um curso concedido, com somente as aulas publicadas
 * (RF-CONS-003, RN-AUT-004).
 *
 * ## Autorizar primeiro, ler depois
 *
 * A concessao e verificada antes de qualquer consulta de conteudo. Nao e
 * economia de codigo: o caminho negativo termina numa unica leitura de indice, e
 * a arvore de um curso nao concedido nunca chega a ser montada. A decisao de
 * recusar tambem fica onde da para ler — uma linha, com nome —, em vez de ser
 * deduzida de uma consulta que por acaso nao trouxe nada.
 *
 * A leitura seguinte recebe o consumidor mesmo assim, e resolve a concessao de
 * novo ao buscar o curso. A repeticao e sobre a **raiz**, e nao sobre a arvore
 * inteira: uma vez resolvido o curso, os modulos e as aulas dele sao lidos pelo
 * identificador ja autorizado. O que se ganha repetindo e uma porta de leitura
 * que continua segura se alguem a chamar de outro lugar amanha, sem este portao
 * na frente.
 *
 * ## A mesma resposta para as duas negativas
 *
 * Curso inexistente e curso sem concessao respondem `404`, com o mesmo corpo.
 * Distinguir os dois transformaria a rota num verificador de identificadores:
 * bastaria comparar as respostas para descobrir quais cursos existem fora da
 * concessao (RN-PROP-005, RF-CONS-005, plan §9.3).
 *
 * O `null` que chega da porta ja e indistinguivel na origem — ela nao devolve
 * informacao suficiente para responder aos dois casos de forma diferente, nem
 * por engano.
 */
final class GetPublishedStructure
{
    public function __construct(
        private readonly AccessGrantRepository $grants,
        private readonly ConsumerCatalogReadModel $catalog,
    ) {}

    /**
     * @throws DomainException quando o curso nao existe ou nao foi concedido
     */
    public function __invoke(GetPublishedStructureQuery $consulta): ConsumerStructureView
    {
        if (! $this->grants->grantsCourse($consulta->consumerId, $consulta->courseId)) {
            throw new DomainException(Failure::NOT_FOUND);
        }

        $estrutura = $this->catalog->structureOfConsumer($consulta->courseId, $consulta->consumerId);

        if ($estrutura === null) {
            throw new DomainException(Failure::NOT_FOUND);
        }

        return $estrutura;
    }
}
