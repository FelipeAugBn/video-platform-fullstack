<?php

declare(strict_types=1);

namespace App\Video\Interfaces\Http\Request;

use App\Video\Domain\UploadPolicy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A entrada de `POST /api/lessons/{lesson}/video/uploads` (RF-UPL-001).
 *
 * Tres campos, e os limites de dois deles **nao estao escritos aqui**: eles sao
 * perguntados a {@see UploadPolicy}, que e a unica definicao deles na solucao.
 * A alternativa — repetir `video/mp4` e o tamanho maximo nas regras — daria duas
 * fontes para a mesma verdade, e elas divergiriam no dia em que alguem mudasse
 * so uma.
 *
 * Rejeitar cedo e a razao de estas regras existirem na fronteira em vez de so no
 * caso de uso (RF-UPL-006): descobrir que o arquivo nao era aceitavel **depois**
 * de transferir gigabytes seria caro para o produtor e inutil para todo mundo.
 * O caso de uso reverifica, porque a regra e dele — a fronteira apenas a expressa
 * no vocabulario do validador, e com erro por campo.
 *
 * O que o cliente **nao** escolhe nao aparece em `rules()` e por isso nao
 * sobrevive a `validated()`: chave de armazenamento, metadados, tamanho de parte
 * e estado inicial sao definidos pelo servidor (plan §11.3), e o comando montado
 * adiante nao tem sequer propriedade para recebe-los.
 */
final class OpenUploadRequest extends FormRequest
{
    /**
     * O nome cabe em `VARCHAR(255)`, como o titulo da aula.
     *
     * Ele e guardado **apenas para exibicao**: a chave no armazenamento vem do
     * identificador da tentativa, e nunca do nome informado (plan §11.3).
     */
    public const LIMITE_NOME = 255;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $politica = app(UploadPolicy::class);

        return [
            'filename' => ['required', 'string', 'max:'.self::LIMITE_NOME],
            'content_type' => ['required', 'string', Rule::in([$politica->contentType])],
            // `integer` antes dos limites: sem ele, um tamanho enviado como texto
            // seria comparado como texto, e a comparacao de grandeza nao valeria.
            'size' => ['required', 'integer', 'min:1', 'max:'.$politica->maxSize],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'filename' => 'nome do arquivo',
            'content_type' => 'tipo de conteudo',
            'size' => 'tamanho',
        ];
    }
}
