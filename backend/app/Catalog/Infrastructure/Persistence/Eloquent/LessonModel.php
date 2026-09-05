<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;

/**
 * A linha da tabela `lessons` — e so isso.
 *
 * Mesmas escolhas de `ModuleModel`: vive em `Infrastructure`, nao declara
 * relacionamento, nao gera identificador e nao protege campo, porque nada
 * externo chega ate aqui.
 *
 * `published_at` e `current_video_attempt_id` sao anulaveis e continuam
 * anulaveis na leitura: nulo e rascunho e nulo e sem video, e converter isso em
 * qualquer outro valor apagaria a informacao (plan §7.2).
 */
final class LessonModel extends Model
{
    protected $table = 'lessons';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'published_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
