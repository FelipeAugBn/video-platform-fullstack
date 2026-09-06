<?php

declare(strict_types=1);

namespace App\Video\Interfaces\Http\Request;

use App\Video\Domain\VideoState;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A carga oficial do callback, validada **antes** de qualquer escrita
 * (plan §8.4, §13.4).
 *
 * A ordem e a regra: carga malformada responde `422` **sem gastar uma reserva de
 * idempotencia**. Um emissor com defeito nao pode consumir `event_id` — se
 * consumisse, a correcao do defeito nao adiantaria: a reentrega da carga agora
 * correta encontraria o evento ja registrado.
 *
 * ## As duas exigencias cruzadas
 *
 * | `status` | `playback_reference`                                   |
 * | -------- | ------------------------------------------------------ |
 * | `ready`  | **obrigatorio** — sem ele o video ficaria inassistivel  |
 * | `failed` | **proibido** — a falha nao tem o que reproduzir         |
 *
 * A segunda linha e recusa ativa, e nao tolerancia: aceitar e ignorar deixaria
 * uma referencia chegar junto de uma falha sem que nada denunciasse a
 * incoerencia do emissor.
 *
 * ## `video_id` malformado e `422`, nao `409`
 *
 * Um `video_id` que nao e UUID e carga malformada — o emissor tem um defeito, e a
 * resposta diz isso sem gastar reserva. Um UUID bem formado que nao corresponde a
 * tentativa alguma e outra coisa: e um evento legitimo sobre algo que nao existe,
 * que nunca podera ser aplicado, e por isso vira rejeicao permanente registrada
 * com `409` (plan §13.4). A distincao decide se o emissor reentrega para sempre
 * ou para de vez.
 */
final class ProcessingCallbackRequest extends FormRequest
{
    /**
     * O limite da coluna `event_id`.
     */
    public const LIMITE_EVENTO = 128;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'event_id' => ['required', 'string', 'max:'.self::LIMITE_EVENTO],
            'video_id' => ['required', 'uuid'],
            'status' => ['required', Rule::in([VideoState::READY->value, VideoState::FAILED->value])],
            'playback_reference' => [
                'nullable',
                'string',
                'max:255',
                Rule::requiredIf(fn (): bool => $this->input('status') === VideoState::READY->value),
                Rule::prohibitedIf(fn (): bool => $this->input('status') === VideoState::FAILED->value),
            ],
        ];
    }
}
