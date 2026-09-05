<?php

declare(strict_types=1);

namespace App\Catalog\Application\Port;

use App\Catalog\Application\View\CourseStructureView;
use App\Catalog\Application\View\LessonView;

/**
 * As duas leituras compostas do catalogo do produtor.
 *
 * Existe porque duas respostas desta entrega precisam de algo que nenhum
 * agregado tem sozinho:
 *
 *   - a consulta de aula devolve o **estado do video**, que vive em
 *     `video_attempts` e nao dentro de `Lesson` (plan §6.1);
 *   - a estrutura devolve curso, modulos e aulas **aninhados e ordenados**, o
 *     que atravessa tres tabelas (RF-EST-001, RF-EST-002).
 *
 * Montar isso a partir dos repositorios de agregado custaria uma consulta por
 * modulo para buscar aulas, e outra por aula para buscar o estado do video — o
 * N+1 que a task proibe. Aqui as duas leituras sao resolvidas por consultas
 * escritas para elas.
 *
 * **Nao e um repositorio generico, e nao substitui os que existem.** E uma porta
 * somente de leitura: nao tem operacao de gravacao nem de trava, nunca expoe
 * model Eloquent ou qualquer outro detalhe de persistencia, e suas operacoes
 * devolvem DTOs de leitura imutaveis. Quem vai mudar um modulo ou uma aula
 * continua passando pelo repositorio do agregado.
 *
 * `CourseStructureView` **reaproveita o agregado imutavel `Course`** para os
 * dados do curso: ele ja e exatamente o que a resposta declara, e copia-lo campo
 * a campo daria uma terceira descricao do mesmo curso para manter em sincronia.
 * Modulos e aulas entram como projecoes proprias da leitura, porque carregam o
 * que nenhum agregado tem — o aninhamento e o estado do video. Reaproveitar o
 * `Course` nao transforma a arvore num agregado unico: `Course`, `Module` e
 * `Lesson` continuam sendo raizes separadas (plan §6.1).
 *
 * O dono e parametro obrigatorio das duas operacoes, e entra na clausula SQL,
 * atravessando `lesson -> module -> course -> owner_id` (RN-PROP-002). Recurso
 * inexistente e recurso alheio produzem o mesmo `null`.
 */
interface CatalogReadModel
{
    /**
     * A aula, com o estado do video atual, se ela pertencer a este dono.
     *
     * `videoState` e nulo quando a aula nao tem tentativa atual — que e o estado
     * em que ela nasce (RF-AUL-004). Havendo tentativa, o valor e o que esta
     * gravado em `video_attempts`, e nao uma copia guardada na aula.
     */
    public function lessonOfOwner(string $lessonId, string $ownerId): ?LessonView;

    /**
     * O curso com modulos e aulas, ordenados por posicao, se ele for deste dono.
     *
     * Inclui aulas em rascunho, porque a visao do produtor precisa acompanhar o
     * que ainda nao publicou (RF-EST-004). Curso sem modulos devolve a lista
     * vazia, e modulo sem aulas tambem — ausencia de conteudo nao e ausencia de
     * curso.
     */
    public function structureOfOwner(string $courseId, string $ownerId): ?CourseStructureView;
}
