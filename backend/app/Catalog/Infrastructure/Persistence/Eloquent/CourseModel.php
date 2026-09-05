<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Persistence\Eloquent;

use App\Catalog\Domain\CourseState;
use Illuminate\Database\Eloquent\Model;

/**
 * A linha da tabela `courses` — e so isso.
 *
 * Mora em `Infrastructure`, e nao em `App\Models`, porque e detalhe de
 * armazenamento e nao modelo do negocio (plan §5.1). A distincao tem consequencia
 * pratica: nenhuma classe de `Application` ou `Domain` pode importar este arquivo,
 * e a busca por `CourseModel` fora desta pasta e a forma direta de conferir isso.
 *
 * Nao usa `HasUuids`. O identificador ja chega pronto, gerado pelo repositorio
 * antes de o agregado existir — a geracao precisa acontecer no dominio da
 * operacao, e nao no momento do `INSERT`. Duas fontes de identificador seriam uma
 * a mais, e a que perdesse seria descoberta tarde.
 *
 * O nome da tabela e declarado porque a convencao do framework derivaria
 * `course_models` do nome da classe.
 */
final class CourseModel extends Model
{
    protected $table = 'courses';

    /**
     * Chave textual e nao incremental: a coluna e `CHAR(36)`, e sem isto o
     * framework trataria o identificador como inteiro na leitura.
     */
    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * Sem lista de campos protegidos, porque nao ha entrada externa a proteger.
     *
     * `$guarded = []` de fato **libera** a atribuicao em massa, e o adapter usa
     * `updateOrCreate` com arrays. A protecao aqui nao vem do model: vem de onde
     * esse array e montado.
     *
     * O adapter escreve a lista de campos **explicitamente**, um a um, a partir
     * de um agregado ja construido. Nenhum array vindo da requisicao chega a este
     * model — nem o validado. Um campo novo so passa a ser persistido quando
     * alguem o acrescenta ao adapter, e nao quando um cliente o inclui no corpo.
     *
     * Uma lista de `fillable` seria uma segunda defesa para uma porta que nao
     * existe, e daria a impressao de que arrays externos chegam ate aqui.
     */
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'state' => CourseState::class,
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
