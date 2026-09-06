<?php

declare(strict_types=1);

namespace App\Video\Infrastructure\Persistence\Eloquent;

use App\Shared\Domain\Failure\Failure;
use App\Video\Application\Port\VideoAttemptRepository;
use App\Video\Domain\VideoAttempt;
use App\Video\Domain\VideoState;
use Illuminate\Contracts\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * A porta de tentativas de video, sobre Eloquent.
 *
 * A cadeia da propriedade e a mais longa da solucao —
 * `video_attempt -> lesson -> module -> course -> owner_id` — e ela e percorrida
 * **dentro do SQL**, por subconsultas encadeadas. Uma tentativa de outro
 * produtor nunca chega a ser carregada (RN-PROP-002, RN-PROP-004).
 *
 * As leituras **sem** dono existem ao lado das com dono e nao sao um descuido:
 * o job de processamento e o callback do webhook nao rodam em nome de ninguem
 * (ver a porta). Os nomes dizem qual e qual, e nao ha um metodo unico que aceite
 * dono nulo — `null` viraria a forma de pular a checagem.
 *
 * Como nas outras portas, nenhum model atravessa: os metodos devolvem
 * `VideoAttempt` ou `null`.
 */
final class EloquentVideoAttemptRepository implements VideoAttemptRepository
{
    public function nextIdentity(): string
    {
        return (string) Str::uuid7();
    }

    public function save(VideoAttempt $attempt): void
    {
        VideoAttemptModel::query()->updateOrCreate(
            ['id' => $attempt->id()],
            [
                'lesson_id' => $attempt->lessonId(),
                'state' => $attempt->state()->value,
                'declared_filename' => $attempt->declaredFilename(),
                'declared_content_type' => $attempt->declaredContentType(),
                'declared_size' => $attempt->declaredSize(),
                'storage_key' => $attempt->storageKey(),
                'multipart_upload_id' => $attempt->multipartUploadId(),
                // Os campos anulaveis sao escritos explicitamente, e nao omitidos
                // quando nulos: omitir faria uma tentativa que perdeu a
                // referencia de reproducao — ou que teve a falha limpa —
                // preservar o valor antigo, e o agregado deixaria de ser a fonte
                // da verdade.
                'verified_size' => $attempt->verifiedSize(),
                'verified_content_type' => $attempt->verifiedContentType(),
                'playback_reference' => $attempt->playbackReference(),
                'failure_code' => $attempt->failure()?->code(),
                // Codigo e mensagem sao gravados **juntos**, e os dois saem do
                // mesmo caso do catalogo. Escritos de forma independente, um
                // poderia acabar descrevendo a falha do outro.
                'failure_message' => $attempt->failure()?->detail(),
            ],
        );
    }

    public function findOwned(string $attemptId, string $ownerId): ?VideoAttempt
    {
        return $this->primeira($this->doDono($ownerId)->where('video_attempts.id', $attemptId));
    }

    public function lockOwned(string $attemptId, string $ownerId): ?VideoAttempt
    {
        return $this->primeira(
            $this->doDono($ownerId)->where('video_attempts.id', $attemptId)->lockForUpdate(),
        );
    }

    public function findCurrentOfLesson(string $lessonId): ?VideoAttempt
    {
        return $this->primeira($this->atualDaAula($lessonId));
    }

    public function lockCurrentOfLesson(string $lessonId): ?VideoAttempt
    {
        return $this->primeira($this->atualDaAula($lessonId)->lockForUpdate());
    }

    public function lock(string $attemptId): ?VideoAttempt
    {
        return $this->primeira(
            VideoAttemptModel::query()->where('id', $attemptId)->lockForUpdate(),
        );
    }

    /**
     * A tentativa para a qual a aula aponta — e nao a mais recente dela.
     *
     * A diferenca importa: ordenar por data devolveria a ultima linha criada,
     * que nao e necessariamente a atual se uma abertura tiver falhado depois de
     * gravar. O ponteiro em `lessons.current_video_attempt_id` e a fonte da
     * verdade (RF-AUL-005).
     *
     * @return Builder<VideoAttemptModel>
     */
    private function atualDaAula(string $lessonId): Builder
    {
        return VideoAttemptModel::query()->whereExists(
            fn (QueryBuilder $aula) => $aula->from('lessons')
                ->where('lessons.id', $lessonId)
                ->whereColumn('lessons.current_video_attempt_id', 'video_attempts.id'),
        );
    }

    /**
     * O ponto unico onde o dono entra na consulta.
     *
     * Tres subconsultas encadeadas em vez de tres juncoes, pela mesma razao das
     * portas do catalogo: `EXISTS` mantem o recorte na clausula sem trazer
     * coluna alheia para o model, e uma leitura travada sobre esta base trava
     * apenas a linha de `video_attempts`.
     *
     * @return Builder<VideoAttemptModel>
     */
    private function doDono(string $ownerId): Builder
    {
        return VideoAttemptModel::query()->whereExists(
            fn (QueryBuilder $aula) => $aula->from('lessons')
                ->whereColumn('lessons.id', 'video_attempts.lesson_id')
                ->whereExists(
                    fn (QueryBuilder $modulo) => $modulo->from('modules')
                        ->whereColumn('modules.id', 'lessons.module_id')
                        ->whereExists(
                            fn (QueryBuilder $curso) => $curso->from('courses')
                                ->whereColumn('courses.id', 'modules.course_id')
                                ->where('courses.owner_id', $ownerId),
                        ),
                ),
        );
    }

    /**
     * @param  Builder<VideoAttemptModel>  $consulta
     */
    private function primeira(Builder $consulta): ?VideoAttempt
    {
        $linha = $consulta->first();

        return $linha === null ? null : $this->paraDominio($linha);
    }

    private function paraDominio(VideoAttemptModel $linha): VideoAttempt
    {
        $codigo = $linha->getAttribute('failure_code');

        return VideoAttempt::reconstitute(
            id: $linha->getAttribute('id'),
            lessonId: $linha->getAttribute('lesson_id'),
            state: VideoState::from($linha->getAttribute('state')),
            declaredFilename: $linha->getAttribute('declared_filename'),
            declaredContentType: $linha->getAttribute('declared_content_type'),
            declaredSize: $linha->getAttribute('declared_size'),
            storageKey: $linha->getAttribute('storage_key'),
            multipartUploadId: $linha->getAttribute('multipart_upload_id'),
            verifiedSize: $linha->getAttribute('verified_size'),
            verifiedContentType: $linha->getAttribute('verified_content_type'),
            playbackReference: $linha->getAttribute('playback_reference'),
            // A mensagem gravada **nao** e lida de volta: ela e derivada do caso
            // do catalogo, que e a fonte. Ler a coluna daria ao texto do banco a
            // chance de divergir do catalogo sem que ninguem percebesse — e a
            // coluna existe para quem inspeciona a tabela, nao para a aplicacao.
            failure: $codigo === null ? null : Failure::from($codigo),
        );
    }
}
