<?php

declare(strict_types=1);

namespace App\Catalog\Interfaces\Http\Request;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A entrada de `POST /api/courses`.
 *
 * Dois campos, e apenas eles sao lidos adiante. O controller monta o comando a
 * partir de `validated()`, entao `owner_id`, `state`, `id` ou datas enviados no
 * corpo simplesmente nao existem para o resto do fluxo — nao ha regra recusando
 * cada um, porque nao ha caminho por onde eles cheguem (RN-PROP-001).
 *
 * Recusar explicitamente esses campos seria uma decisao de contrato nova, com
 * mensagem propria, e nao ha requisito pedindo isso. Ignora-los produz o mesmo
 * resultado observavel com menos superficie.
 *
 * `authorize()` devolve `true` porque a autorizacao ja aconteceu: a rota exige
 * sessao valida e perfil de produtor antes de chegar aqui. Repetir a checagem
 * neste ponto criaria um segundo lugar decidindo a mesma coisa, com risco de
 * responder num formato diferente.
 */
final class CreateCourseRequest extends FormRequest
{
    /**
     * O titulo cabe em `VARCHAR(255)`, e no MySQL esse limite conta **caracteres**.
     */
    public const LIMITE_TITULO = 255;

    /**
     * A descricao cabe em `TEXT`, cujo limite sao 65.535 **bytes** — e nao
     * caracteres, ao contrario do `VARCHAR`.
     *
     * Em `utf8mb4` um caractere ocupa ate 4 bytes, entao 16.383 caracteres nao
     * podem, em nenhuma combinacao, ultrapassar a coluna. Validar por caractere
     * mantem a mensagem compreensivel para quem preenche o formulario — dizer
     * "65535 bytes" nao ajudaria ninguem — e o teto conservador nao atrapalha:
     * sao dezesseis mil caracteres de descricao de curso.
     *
     * Sem esta conta, uma descricao longa com acentos passaria pela validacao e
     * seria recusada pelo banco em modo estrito, virando erro interno no lugar de
     * uma mensagem de campo.
     */
    public const LIMITE_DESCRICAO = 16383;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:'.self::LIMITE_TITULO],
            'description' => ['required', 'string', 'max:'.self::LIMITE_DESCRICAO],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'title' => 'titulo',
            'description' => 'descricao',
        ];
    }
}
