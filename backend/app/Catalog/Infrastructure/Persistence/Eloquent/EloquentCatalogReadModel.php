<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Persistence\Eloquent;

use App\Catalog\Application\Port\CatalogReadModel;
use App\Catalog\Application\Port\CourseRepository;
use App\Catalog\Application\View\CourseStructureView;
use App\Catalog\Application\View\LessonView;
use App\Catalog\Application\View\ModuleView;
use App\Video\Domain\VideoState;
use DateTimeImmutable;
use Illuminate\Contracts\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Eloquent\Builder;

/**
 * As duas leituras compostas do catalogo, sobre Eloquent.
 *
 * Este arquivo existe por causa de duas contas.
 *
 * **A primeira e o estado do video.** Ele vive em `video_attempts`, e a aula
 * guarda apenas o identificador da tentativa atual. Buscar a tentativa depois de
 * carregar a aula custaria uma consulta por aula; aqui a juncao a esquerda
 * resolve as duas coisas de uma vez. E juncao **a esquerda** porque a ausencia e
 * o caso comum: uma aula nasce sem video, e uma juncao interna simplesmente a
 * faria sumir da estrutura.
 *
 * **A segunda e o N+1 da arvore.** A estrutura sai em **tres consultas fixas** —
 * o curso, os modulos, as aulas de todos os modulos — independentemente de
 * quantos modulos o curso tenha. Percorrer modulos buscando as aulas de cada um
 * daria uma consulta por modulo, e o custo cresceria com o tamanho do curso
 * exatamente onde a estrutura e mais util.
 *
 * O aninhamento acontece em PHP, sobre resultados ja ordenados pelo banco: e
 * agrupamento de linhas em memoria, e nao consulta adicional. A **ordem** vem
 * sempre do SQL (RF-EST-002).
 *
 * O dono entra em todas as consultas, e a checagem completa e a mesma dos
 * repositorios: `lesson -> module -> course -> owner_id`. Ela e mantida ate nas
 * consultas que rodam depois de o curso ja ter sido resolvido — redundancia
 * deliberada, para que nenhuma consulta deste arquivo seja capaz de devolver
 * conteudo alheio se for lida ou reaproveitada isoladamente.
 */
final class EloquentCatalogReadModel implements CatalogReadModel
{
    /**
     * O curso e resolvido pelo repositorio do agregado, e nao por uma consulta
     * propria: a resposta da estrutura repete os mesmos seis campos de
     * `GET /api/courses/{course}`, e escrever a leitura de novo aqui daria duas
     * consultas para a mesma pergunta, livres para divergir no filtro por dono.
     */
    public function __construct(private readonly CourseRepository $courses) {}

    public function lessonOfOwner(string $lessonId, string $ownerId): ?LessonView
    {
        $linha = $this->aulasDoDono($ownerId)
            ->where('lessons.id', $lessonId)
            ->first();

        return $linha === null ? null : $this->paraVisaoDeAula($linha);
    }

    public function structureOfOwner(string $courseId, string $ownerId): ?CourseStructureView
    {
        $curso = $this->courses->findOwned($courseId, $ownerId);

        if ($curso === null) {
            return null;
        }

        $modulos = ModuleModel::query()
            ->whereExists(
                fn (QueryBuilder $dono) => $dono->from('courses')
                    ->whereColumn('courses.id', 'modules.course_id')
                    ->where('courses.owner_id', $ownerId),
            )
            ->where('course_id', $curso->id())
            ->orderBy('position')
            ->get();

        // Todas as aulas do curso, de uma vez. O agrupamento por modulo acontece
        // logo abaixo, sobre esta lista ja ordenada.
        $aulas = $this->aulasDoDono($ownerId)
            ->whereExists(
                fn (QueryBuilder $modulo) => $modulo->from('modules')
                    ->whereColumn('modules.id', 'lessons.module_id')
                    ->where('modules.course_id', $curso->id()),
            )
            ->orderBy('lessons.position')
            ->get();

        $porModulo = [];

        foreach ($aulas as $linha) {
            $porModulo[(string) $linha->getAttribute('module_id')][] = $this->paraVisaoDeAula($linha);
        }

        return new CourseStructureView(
            course: $curso,
            modules: array_map(
                fn (ModuleModel $modulo): ModuleView => new ModuleView(
                    id: $modulo->getAttribute('id'),
                    courseId: $modulo->getAttribute('course_id'),
                    title: $modulo->getAttribute('title'),
                    position: $modulo->getAttribute('position'),
                    // Modulo sem aula devolve lista vazia, e nao ausencia: o
                    // cliente distingue "ainda nao tem aula" sem testar a
                    // presenca da chave (RF-EST-001).
                    lessons: $porModulo[(string) $modulo->getAttribute('id')] ?? [],
                ),
                array_values($modulos->all()),
            ),
        );
    }

    /**
     * A base das duas leituras de aula: recorte por dono e estado do video.
     *
     * O `select` lista `lessons.*` mais uma unica coluna da tentativa. Trazer a
     * linha inteira de `video_attempts` colocaria no model campos que a resposta
     * nao declara — chave no storage, tamanho verificado, mensagem de falha — e
     * um deles acabaria exposto no dia em que alguem montasse a resposta a partir
     * dos atributos em vez do recurso.
     *
     * @return Builder<LessonModel>
     */
    private function aulasDoDono(string $ownerId): Builder
    {
        return LessonModel::query()
            ->select('lessons.*', 'video_attempts.state as video_state')
            ->leftJoin('video_attempts', 'video_attempts.id', '=', 'lessons.current_video_attempt_id')
            ->whereExists(
                fn (QueryBuilder $modulo) => $modulo->from('modules')
                    ->whereColumn('modules.id', 'lessons.module_id')
                    ->whereExists(
                        fn (QueryBuilder $curso) => $curso->from('courses')
                            ->whereColumn('courses.id', 'modules.course_id')
                            ->where('courses.owner_id', $ownerId),
                    ),
            );
    }

    private function paraVisaoDeAula(LessonModel $linha): LessonView
    {
        $publicadaEm = $linha->getAttribute('published_at');
        $estado = $linha->getAttribute('video_state');

        return new LessonView(
            id: $linha->getAttribute('id'),
            moduleId: $linha->getAttribute('module_id'),
            title: $linha->getAttribute('title'),
            position: $linha->getAttribute('position'),
            publishedAt: $publicadaEm === null
                ? null
                : DateTimeImmutable::createFromInterface($publicadaEm),
            // `from`, e nao `tryFrom`: um valor gravado que nao esteja na
            // enumeracao e dado corrompido, e falhar alto e melhor do que
            // devolver `null` fingindo que a aula nao tem video.
            videoState: $estado === null ? null : VideoState::from((string) $estado),
        );
    }
}
