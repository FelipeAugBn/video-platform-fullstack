<?php

declare(strict_types=1);

namespace App\Catalog\Interfaces\Http\Request;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A entrada de `POST /api/courses/{course}/modules`.
 *
 * Um campo, e apenas ele e lido adiante. O controller monta o comando a partir de
 * `validated()`, entao `position`, `id` ou `course_id` enviados no corpo
 * simplesmente nao existem para o resto do fluxo — nao ha regra recusando cada
 * um, porque nao ha caminho por onde eles cheguem (RF-MOD-007, RN-ORD-002).
 *
 * Recusar explicitamente esses campos seria uma decisao de contrato nova, com
 * mensagem propria, e nao ha requisito pedindo isso. Ignora-los produz o mesmo
 * resultado observavel com menos superficie — a mesma escolha registrada em
 * `CreateCourseRequest`.
 *
 * `authorize()` devolve `true` porque a autorizacao ja aconteceu: a rota exige
 * sessao valida e perfil de produtor. A propriedade do curso e verificada no caso
 * de uso, dentro da consulta, e nao aqui.
 */
final class CreateModuleRequest extends FormRequest
{
    /**
     * O titulo cabe em `VARCHAR(255)`, e no MySQL esse limite conta **caracteres**.
     */
    public const LIMITE_TITULO = 255;

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
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'title' => 'titulo',
        ];
    }
}
