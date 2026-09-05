<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;

/**
 * A linha da tabela `modules` — e so isso.
 *
 * Mora em `Infrastructure` pelo mesmo motivo de `CourseModel`: e detalhe de
 * armazenamento, e nenhuma classe de `Application` ou `Domain` pode importa-lo
 * (plan §5.1).
 *
 * **Sem relacionamentos declarados.** Nao ha `belongsTo` para o curso nem
 * `hasMany` para as aulas, e a ausencia e deliberada: relacionamento e um convite
 * a navegar do modulo ate o curso em PHP para so entao conferir o dono — o
 * oposto de RN-PROP-002 — e a montar a estrutura percorrendo objetos, que e o
 * N+1 que a leitura da arvore existe para evitar. As junções que este projeto
 * precisa estao escritas nos adapters, filtradas pelo dono.
 *
 * Nao usa `HasUuids`: o identificador ja chega pronto do repositorio, gerado
 * antes de o agregado existir.
 */
final class ModuleModel extends Model
{
    protected $table = 'modules';

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * Sem lista de campos protegidos, porque nao ha entrada externa a proteger.
     *
     * A protecao nao vem do model: vem de onde o array e montado. O adapter
     * escreve a lista de campos explicitamente, um a um, a partir de um agregado
     * ja construido — nenhum array vindo da requisicao chega aqui, nem o
     * validado. Um campo novo so passa a ser persistido quando alguem o
     * acrescenta ao adapter.
     */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            // Sem isto, `position` volta do MySQL como string e o agregado
            // recusaria o valor no construtor tipado.
            'position' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
