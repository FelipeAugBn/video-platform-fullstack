<?php

declare(strict_types=1);

namespace App\Catalog\Interfaces\Http\Request;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A entrada de `POST /api/modules/{module}/lessons`.
 *
 * Um campo. Os quatro que o cliente nao escolhe — `position`, `published_at`,
 * `current_video_attempt_id` e `video_state` — nao aparecem em `rules()`, e por
 * isso nao sobrevivem a `validated()`: o comando montado adiante nao tem sequer
 * propriedade para recebe-los (RF-AUL-004, RF-AUL-009).
 */
final class CreateLessonRequest extends FormRequest
{
    /**
     * O titulo cabe em `VARCHAR(255)`, contados em caracteres pelo MySQL.
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
