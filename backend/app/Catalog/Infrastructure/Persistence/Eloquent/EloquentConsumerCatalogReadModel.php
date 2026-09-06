<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Persistence\Eloquent;

use App\Catalog\Application\Port\ConsumerCatalogReadModel;
use App\Catalog\Application\View\ConsumerLessonView;
use App\Catalog\Application\View\ConsumerModuleView;
use App\Catalog\Application\View\ConsumerStructureView;
use App\Catalog\Domain\Course;
use App\Catalog\Domain\CourseState;
use App\Catalog\Domain\Lesson;
use App\Shared\Application\Page;
use DateTimeImmutable;
use Illuminate\Contracts\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Eloquent\Builder;

/**
 * As leituras do catalogo do consumidor, sobre Eloquent.
 *
 * O arquivo espelha `EloquentCatalogReadModel`, trocando a regra de acesso: la o
 * recorte e `owner_id`; aqui e a existencia de uma linha em
 * `course_access_grants`. As duas nunca se misturam — propriedade e concessao
 * sao regras diferentes, e um filtro que servisse as duas teria de aceitar
 * "qualquer um dos dois", que e o mesmo que nao filtrar.
 *
 * ## Onde a concessao entra, exatamente
 *
 * Ela entra na consulta que **resolve o ponto de entrada** de cada leitura, e e
 * la que a autorizacao acontece:
 *
 *   - na listagem, no `EXISTS` que recorta a pagina de cursos — junto com o
 *     `state`, e antes do `paginate`, para que `total` e numero de paginas
 *     contem somente o conjunto autorizado;
 *   - na arvore, no `EXISTS` que resolve **o curso**;
 *   - na aula, nas subconsultas encadeadas `lessons -> modules -> concessao`,
 *     que sao o proprio caminho pelo qual ela e alcancada.
 *
 * **Na arvore, modulos e aulas sao consultados pelo identificador do curso ja
 * autorizado** — sem repetir o `EXISTS` da concessao. Nao e descuido, e a
 * diferenca em relacao ao catalogo do produtor merece ser dita: o
 * `course_id` usado ali nao e o parametro da rota, e sim o da linha que a
 * consulta anterior devolveu — e ela so devolve linha havendo concessao. Repetir
 * a subconsulta acrescentaria duas juncoes para reafirmar o que a primeira
 * consulta ja decidiu.
 *
 * O que sustenta isso e a concessao ser **imutavel** nesta entrega: ela vem do
 * seed, e nao ha conceder nem revogar (RF-CONS-006). Nao existe janela entre a
 * primeira consulta e as duas seguintes em que ela possa desaparecer. No dia em
 * que revogar existir, este e o ponto a revisitar — ou repetindo o recorte, ou
 * lendo as tres sob a mesma transacao.
 *
 * **A arvore sai em tres consultas fixas** — o curso, os modulos, as aulas
 * publicadas de todos os modulos —, independentemente do tamanho do curso.
 * Percorrer modulos buscando as aulas de cada um daria uma consulta por modulo.
 * O aninhamento acontece em PHP, sobre resultados ja ordenados pelo banco; a
 * ordem vem sempre do SQL (RF-EST-002).
 *
 * O rascunho e excluido na clausula, e nao depois: `published_at IS NOT NULL`
 * entra na consulta das aulas. Trazer tudo e descartar em PHP faria o titulo de
 * uma aula nao publicada atravessar a aplicacao, e bastaria alguem montar a
 * resposta a partir da lista bruta para ele aparecer (RN-AUT-004).
 */
final class EloquentConsumerCatalogReadModel implements ConsumerCatalogReadModel
{
    public function pageOfConsumer(string $consumerId, int $page, int $perPage): Page
    {
        // A mesma ordem da listagem do produtor: criacao decrescente, com o
        // identificador como criterio de desempate apenas para tornar o
        // resultado deterministico quando dois cursos compartilham o segundo de
        // `created_at` (plan §7.1). Sem desempate, um curso poderia aparecer em
        // duas paginas ou em nenhuma.
        $pagina = $this->concedidos($consumerId)
            ->where('state', CourseState::AVAILABLE->value)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(perPage: $perPage, page: $page);

        return Page::of(
            items: array_map(
                fn (CourseModel $linha): Course => $this->paraCurso($linha),
                array_values($pagina->items()),
            ),
            currentPage: $pagina->currentPage(),
            perPage: $pagina->perPage(),
            total: $pagina->total(),
        );
    }

    public function structureOfConsumer(string $courseId, string $consumerId): ?ConsumerStructureView
    {
        $curso = $this->concedidos($consumerId)
            ->where('id', $courseId)
            ->first();

        if ($curso === null) {
            return null;
        }

        // Daqui para baixo o curso ja esta autorizado: a consulta acima so
        // devolveu linha porque existe concessao, e e o identificador **dela**
        // que as duas consultas seguintes usam. Nao ha entrada de cliente nesse
        // valor a partir deste ponto.
        $modulos = ModuleModel::query()
            ->where('course_id', $courseId)
            ->orderBy('position')
            ->get();

        // Todas as aulas publicadas do curso, de uma vez. O agrupamento por
        // modulo acontece logo abaixo, sobre esta lista ja ordenada.
        $aulas = LessonModel::query()
            ->whereNotNull('published_at')
            ->whereExists(
                fn (QueryBuilder $modulo) => $modulo->from('modules')
                    ->whereColumn('modules.id', 'lessons.module_id')
                    ->where('modules.course_id', $courseId),
            )
            ->orderBy('position')
            ->get();

        $porModulo = [];

        foreach ($aulas as $linha) {
            $porModulo[(string) $linha->getAttribute('module_id')][] = $this->paraVisaoDeAula($linha);
        }

        return new ConsumerStructureView(
            course: $this->paraCurso($curso),
            modules: array_map(
                fn (ModuleModel $modulo): ConsumerModuleView => new ConsumerModuleView(
                    id: $modulo->getAttribute('id'),
                    courseId: $modulo->getAttribute('course_id'),
                    title: $modulo->getAttribute('title'),
                    position: $modulo->getAttribute('position'),
                    lessons: $porModulo[(string) $modulo->getAttribute('id')] ?? [],
                ),
                array_values($modulos->all()),
            ),
        );
    }

    public function lessonOfConsumer(string $lessonId, string $consumerId): ?Lesson
    {
        $linha = LessonModel::query()
            ->where('id', $lessonId)
            ->whereExists(
                fn (QueryBuilder $modulo) => $modulo->from('modules')
                    ->whereColumn('modules.id', 'lessons.module_id')
                    ->whereExists(
                        fn (QueryBuilder $concessao) => $concessao->from('course_access_grants')
                            ->whereColumn('course_access_grants.course_id', 'modules.course_id')
                            ->where('course_access_grants.consumer_id', $consumerId),
                    ),
            )
            ->first();

        return $linha === null ? null : $this->paraAula($linha);
    }

    /**
     * O ponto unico onde a concessao entra nas consultas de curso.
     *
     * As duas leituras que partem de um curso — a pagina e a raiz da arvore —
     * comecam por aqui. Concentrar assim significa que acrescentar uma terceira
     * sem o recorte exige nao usar este ajudante, o que e visivel em revisao, ao
     * contrario de esquecer um `where` no meio de uma cadeia.
     *
     * A leitura de aula nao passa por este metodo: ela parte de `lessons`, e o
     * caminho ate a concessao e outro — declarado em `lessonOfConsumer()`.
     *
     * `EXISTS` em vez de `JOIN`: a UNIQUE `(course_id, consumer_id)` garante no
     * maximo uma concessao por par, mas um `JOIN` traria as colunas da concessao
     * para dentro do model, e uma delas acabaria exposta no dia em que alguem
     * montasse a resposta a partir dos atributos.
     *
     * @return Builder<CourseModel>
     */
    private function concedidos(string $consumerId): Builder
    {
        return CourseModel::query()->whereExists(
            fn (QueryBuilder $concessao) => $concessao->from('course_access_grants')
                ->whereColumn('course_access_grants.course_id', 'courses.id')
                ->where('course_access_grants.consumer_id', $consumerId),
        );
    }

    private function paraCurso(CourseModel $linha): Course
    {
        return Course::reconstitute(
            id: $linha->getAttribute('id'),
            ownerId: $linha->getAttribute('owner_id'),
            title: $linha->getAttribute('title'),
            description: $linha->getAttribute('description'),
            state: $linha->getAttribute('state'),
            createdAt: DateTimeImmutable::createFromInterface($linha->getAttribute('created_at')),
        );
    }

    private function paraAula(LessonModel $linha): Lesson
    {
        $publicadaEm = $linha->getAttribute('published_at');

        return Lesson::reconstitute(
            id: $linha->getAttribute('id'),
            moduleId: $linha->getAttribute('module_id'),
            title: $linha->getAttribute('title'),
            position: $linha->getAttribute('position'),
            publishedAt: $publicadaEm === null
                ? null
                : DateTimeImmutable::createFromInterface($publicadaEm),
            currentVideoAttemptId: $linha->getAttribute('current_video_attempt_id'),
        );
    }

    /**
     * A projecao da aula publicada.
     *
     * `published_at` e convertida sem checagem de nulo porque a consulta ja o
     * excluiu. A conversao para o tipo nativo segue a mesma razao das demais: a
     * projecao declara `DateTimeImmutable`, e entregar o tipo de data do
     * framework faria a camada Application depender de uma biblioteca que ela
     * nao deveria conhecer.
     */
    private function paraVisaoDeAula(LessonModel $linha): ConsumerLessonView
    {
        return new ConsumerLessonView(
            id: $linha->getAttribute('id'),
            moduleId: $linha->getAttribute('module_id'),
            title: $linha->getAttribute('title'),
            position: $linha->getAttribute('position'),
            publishedAt: DateTimeImmutable::createFromInterface($linha->getAttribute('published_at')),
        );
    }
}
