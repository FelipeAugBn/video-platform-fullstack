<?php

declare(strict_types=1);

namespace App\Video\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;

/**
 * A linha da tabela `video_attempts` — e so isso.
 *
 * Mesmas escolhas dos models do catalogo: vive em `Infrastructure`, nao declara
 * relacionamento, nao gera identificador e nao protege campo, porque nada
 * externo chega ate aqui.
 *
 * Sem cast de `state` para `VideoState` nem de `failure_code` para `Failure`: a
 * traducao acontece no repositorio, onde ela e visivel, e um cast lancaria a
 * partir de dentro do Eloquent — longe do lugar onde a linha invalida poderia
 * ser diagnosticada.
 */
final class VideoAttemptModel extends Model
{
    protected $table = 'video_attempts';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'declared_size' => 'integer',
            'verified_size' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
