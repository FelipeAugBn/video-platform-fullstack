<?php

declare(strict_types=1);

namespace App\Video\Interfaces\Http\Request;

use App\Video\Domain\UploadPolicy;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A entrada de `POST /api/video-uploads/{attempt}/complete` (RF-UPL-007).
 *
 * A lista de partes que o navegador enviou, cada uma com o numero e o
 * comprovante que o **armazenamento** devolveu ao recebe-la.
 *
 * **O comprovante nao e validado por formato.** Ele e opaco: seu conteudo, seu
 * tamanho e ate as aspas ao redor variam por provedor e por configuracao
 * (plan §12.5). Uma regra de formato aqui recusaria comprovantes legitimos no
 * dia em que o armazenamento mudasse, e nao acrescentaria seguranca nenhuma —
 * ele nao e prova de coisa alguma nesta solucao, e a verificacao de verdade e
 * outra (plan §12.3).
 *
 * O teto de partes vem da politica, pela mesma razao do tamanho maximo: um so
 * lugar define os limites do envio.
 */
final class CompleteUploadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        $politica = app(UploadPolicy::class);

        return [
            'parts' => ['required', 'array', 'min:1', 'max:'.$politica->maxParts],
            'parts.*.part_number' => ['required', 'integer', 'min:1', 'max:'.$politica->maxParts],
            'parts.*.etag' => ['required', 'string'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'parts' => 'partes',
            'parts.*.part_number' => 'numero da parte',
            'parts.*.etag' => 'comprovante da parte',
        ];
    }
}
