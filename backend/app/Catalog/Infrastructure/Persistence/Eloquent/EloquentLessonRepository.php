<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Persistence\Eloquent;

use App\Catalog\Application\Port\LessonRepository;
use App\Catalog\Domain\Lesson;
use App\Catalog\Domain\Module;
use DateTimeImmutable;
use Illuminate\Contracts\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * A porta de aulas, sobre Eloquent.
 *
 * A cadeia da propriedade e a mais longa do catalogo —
 * `lesson -> module -> course -> owner_id` — e ela e percorrida **dentro do
 * SQL**, por subconsultas encadeadas. Uma aula de outro produtor nunca chega a
 * ser carregada (RN-PROP-002, RN-PROP-004).
 *
 * Como nas outras portas, nenhum model atravessa: os metodos devolvem `Lesson`,
 * lista de `Lesson` ou tipos da linguagem. O estado do video **nao** sai daqui —
 * ele pertence a outro agregado, e quem precisa dele junto usa a porta de
 * leitura (`EloquentCatalogReadModel`).
 */
final class EloquentLessonRepository implements LessonRepository
{
    public function nextIdentity(): string
    {
        return (string) Str::uuid7();
    }

    public function save(Lesson $lesson): void
    {
        LessonModel::query()->updateOrCreate(
            ['id' => $lesson->id()],
            [
                'module_id' => $lesson->moduleId(),
                'title' => $lesson->title(),
                'position' => $lesson->position(),
                // Os dois campos sao escritos explicitamente, e nao omitidos
                // quando nulos: omitir faria a gravacao de uma aula que perdeu a
                // publicacao — ou a tentativa atual — preservar o valor antigo,
                // e o agregado deixaria de ser a fonte da verdade.
                'published_at' => $lesson->publishedAt(),
                'current_video_attempt_id' => $lesson->currentVideoAttemptId(),
            ],
        );
    }

    public function findOwned(string $lessonId, string $ownerId): ?Lesson
    {
        $linha = $this->doDono($ownerId)
            ->where('id', $lessonId)
            ->first();

        return $linha === null ? null : $this->paraDominio($linha);
    }

    public function lockOwned(string $lessonId, string $ownerId): ?Lesson
    {
        $linha = $this->doDono($ownerId)
            ->where('id', $lessonId)
            // Trava apenas a linha de `lessons`. O recorte por dono vem de
            // subconsultas `EXISTS` e nao de `JOIN` exatamente por isso: no
            // MySQL, uma leitura travada nao propaga a trava para as linhas da
            // subconsulta, enquanto um `JOIN` travaria tambem modulo e curso — e
            // publicar uma aula passaria a serializar o curso inteiro.
            ->lockForUpdate()
            ->first();

        return $linha === null ? null : $this->paraDominio($linha);
    }

    public function nextPosition(string $moduleId): int
    {
        $maior = LessonModel::query()
            ->where('module_id', $moduleId)
            ->max('position');

        return $maior === null ? Module::PRIMEIRA_POSICAO : ((int) $maior) + 1;
    }

    /**
     * @return list<Lesson>
     */
    public function listOfOwnedModule(string $moduleId, string $ownerId): array
    {
        $linhas = $this->doDono($ownerId)
            ->where('module_id', $moduleId)
            ->orderBy('position')
            ->get();

        return array_map(
            fn (LessonModel $linha): Lesson => $this->paraDominio($linha),
            array_values($linhas->all()),
        );
    }

    /**
     * O ponto unico onde o dono entra na consulta.
     *
     * Duas subconsultas encadeadas em vez de duas juncoes. A escolha e a mesma de
     * `EloquentModuleRepository`: `EXISTS` mantem o recorte na clausula sem
     * trazer coluna alheia para o model, e uma leitura travada sobre esta base
     * trava apenas a linha de `lessons`.
     *
     * @return Builder<LessonModel>
     */
    private function doDono(string $ownerId): Builder
    {
        return LessonModel::query()->whereExists(
            fn (QueryBuilder $modulo) => $modulo->from('modules')
                ->whereColumn('modules.id', 'lessons.module_id')
                ->whereExists(
                    fn (QueryBuilder $curso) => $curso->from('courses')
                        ->whereColumn('courses.id', 'modules.course_id')
                        ->where('courses.owner_id', $ownerId),
                ),
        );
    }

    private function paraDominio(LessonModel $linha): Lesson
    {
        $publicadaEm = $linha->getAttribute('published_at');

        return Lesson::reconstitute(
            id: $linha->getAttribute('id'),
            moduleId: $linha->getAttribute('module_id'),
            title: $linha->getAttribute('title'),
            position: $linha->getAttribute('position'),
            // Convertida para o tipo nativo, como em `EloquentCourseRepository`:
            // o dominio declara `DateTimeImmutable`, e entregar o tipo de data do
            // framework faria a API dele depender de uma biblioteca que ele nao
            // deveria conhecer. Nulo continua nulo — e o que representa rascunho.
            publishedAt: $publicadaEm === null
                ? null
                : DateTimeImmutable::createFromInterface($publicadaEm),
            currentVideoAttemptId: $linha->getAttribute('current_video_attempt_id'),
        );
    }
}
