<?php

declare(strict_types=1);

namespace App\Video\Interfaces\Http\Resource;

use App\Shared\Interfaces\Http\Resource\ApiResource;
use App\Video\Domain\VideoAttempt;
use Illuminate\Http\Request;

/**
 * O estado do video da aula, como o produtor o ve (RF-VID-002, RF-UI-007).
 *
 * Os mesmos campos na conclusao do envio e na consulta, pelo mesmo motivo de
 * `LessonResource`: um recurso que mudasse de forma conforme o endereco de onde
 * foi lido obrigaria o cliente a ter dois modelos para a mesma coisa.
 *
 * **`failure_code` e `failure_message` saem do catalogo fechado.** Nao ha
 * caminho por onde a mensagem de uma excecao, a resposta bruta de um provedor ou
 * o retorno de um driver chegue a estes dois campos: o agregado so aceita um caso
 * da enumeracao, e o texto e o `detail()` dele (RN-AUT-005, RF-WHK-011).
 *
 * **O que deliberadamente nao sai:** a chave do objeto, o identificador do envio
 * em partes e os tamanhos verificados. Nenhum deles serve a interface, e a chave
 * em particular e o insumo de uma URL assinada — publicar o que a autorizacao
 * protege seria estranho. A reproducao tem endpoint proprio, e ele autoriza antes
 * de emitir (plan §14.2).
 */
final class VideoAttemptResource extends ApiResource
{
    /**
     * @return array<string, string|null>
     */
    public function toArray(Request $request): array
    {
        $tentativa = $this->resource;
        assert($tentativa instanceof VideoAttempt);

        return [
            'id' => $tentativa->id(),
            'lesson_id' => $tentativa->lessonId(),
            'state' => $tentativa->state()->value,
            'filename' => $tentativa->declaredFilename(),
            // Os dois saem como `null` e nao ausentes: o cliente distingue "sem
            // falha" de "falha que o servidor esqueceu de mandar" sem precisar
            // testar a presenca da chave.
            'failure_code' => $tentativa->failure()?->code(),
            'failure_message' => $tentativa->failure()?->detail(),
        ];
    }
}
