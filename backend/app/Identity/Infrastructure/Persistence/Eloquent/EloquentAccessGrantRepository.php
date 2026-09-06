<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Persistence\Eloquent;

use App\Identity\Application\Port\AccessGrantRepository;
use Illuminate\Support\Facades\DB;

/**
 * A concessao, sobre a tabela `course_access_grants`.
 *
 * Consulta pelo query builder, e nao por um model: a tabela nao e um agregado de
 * negocio — nesta entrega ela so e escrita pelo seed, e nao ha conceder nem
 * revogar (RF-CONS-006) —, e um model traria eventos, casts e escopos que nada
 * aqui usa. E a mesma escolha de `EloquentWebhookEventStore`.
 *
 * `exists()` em vez de `first()` ou `count()`: a consulta responde uma pergunta
 * de sim ou nao, e o par `(course_id, consumer_id)` e UNIQUE — o banco para na
 * primeira linha do indice e nenhuma coluna precisa ser trazida para a
 * aplicacao. Nao existe metodo aqui que devolva a concessao em si, porque nada
 * no fluxo precisa dela: o que se decide e acesso, nao o registro.
 */
final class EloquentAccessGrantRepository implements AccessGrantRepository
{
    private const TABELA = 'course_access_grants';

    public function grantsCourse(string $consumerId, string $courseId): bool
    {
        return DB::table(self::TABELA)
            ->where('consumer_id', $consumerId)
            ->where('course_id', $courseId)
            ->exists();
    }
}
