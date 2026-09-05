<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Catalog\Application\Port\CourseRepository;
use App\Catalog\Application\Port\LessonRepository;
use App\Catalog\Application\Port\ModuleRepository;
use App\Catalog\Domain\Course;
use App\Catalog\Domain\Lesson;
use App\Catalog\Domain\Module;
use App\Models\User;
use App\Video\Domain\VideoState;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Monta cenarios de catalogo para os testes, pelos caminhos de escrita reais.
 *
 * Curso, modulo e aula sao criados pelos **repositorios da aplicacao**, e nao por
 * `insert` direto. A diferenca importa: um cenario montado a mao passaria a
 * existir mesmo que a traducao entre agregado e linha estivesse errada, e o teste
 * verificaria a leitura contra dados que a aplicacao nunca teria escrito.
 *
 * A excecao e `video_attempts`, gravada por `insert`. O agregado de video ainda
 * nao existe — ele chega na T043 — e antecipa-lo aqui criaria uma segunda
 * definicao do ciclo de vida do video, que teria de ser jogada fora depois. O
 * que os testes desta tarefa precisam da tentativa e apenas a linha com um
 * estado, para provar que a leitura o reflete.
 *
 * As posicoes vem do proprio repositorio, e nao sao escolhidas aqui: o cenario
 * usa a mesma regra que a aplicacao usa.
 */
trait CatalogoDeTeste
{
    protected function umCurso(User $dono, string $titulo = 'Curso de teste'): Course
    {
        $repositorio = $this->app->make(CourseRepository::class);

        $curso = Course::create(
            id: $repositorio->nextIdentity(),
            ownerId: (string) $dono->getKey(),
            title: $titulo,
            description: 'Descricao do curso.',
            createdAt: new DateTimeImmutable('2026-09-05T13:45:12+00:00'),
        );

        $repositorio->save($curso);

        return $curso;
    }

    protected function umModulo(Course $curso, string $titulo = 'Modulo'): Module
    {
        $repositorio = $this->app->make(ModuleRepository::class);

        $modulo = Module::create(
            id: $repositorio->nextIdentity(),
            courseId: $curso->id(),
            title: $titulo,
            position: $repositorio->nextPosition($curso->id()),
        );

        $repositorio->save($modulo);

        return $modulo;
    }

    protected function umaAula(Module $modulo, string $titulo = 'Aula'): Lesson
    {
        $repositorio = $this->app->make(LessonRepository::class);

        $aula = Lesson::create(
            id: $repositorio->nextIdentity(),
            moduleId: $modulo->id(),
            title: $titulo,
            position: $repositorio->nextPosition($modulo->id()),
        );

        $repositorio->save($aula);

        return $aula;
    }

    /**
     * Uma tentativa de video no estado pedido, ja apontada pela aula.
     *
     * Os campos declarados recebem valores ficticios: nenhum teste desta tarefa
     * os le, e eles existem so porque as colunas nao aceitam nulo.
     */
    protected function umaTentativaDeVideo(Lesson $aula, VideoState $estado): string
    {
        $id = (string) Str::uuid7();

        DB::table('video_attempts')->insert([
            'id' => $id,
            'lesson_id' => $aula->id(),
            'state' => $estado->value,
            'declared_filename' => 'aula.mp4',
            'declared_content_type' => 'video/mp4',
            'declared_size' => 1024,
            'storage_key' => 'videos/'.$id.'.mp4',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('lessons')
            ->where('id', $aula->id())
            ->update(['current_video_attempt_id' => $id]);

        return $id;
    }

    /**
     * Marca a aula como publicada, sem passar por um caso de uso que ainda nao
     * existe.
     *
     * A publicacao chega na T053, com regra propria — video pronto e referencia
     * presente. Aqui o unico objetivo e ter uma aula **nao rascunho** para provar
     * que a estrutura do produtor inclui as duas (RF-EST-004).
     */
    protected function publicadaEm(Lesson $aula, string $instante): void
    {
        DB::table('lessons')
            ->where('id', $aula->id())
            ->update(['published_at' => new DateTimeImmutable($instante)]);
    }
}
