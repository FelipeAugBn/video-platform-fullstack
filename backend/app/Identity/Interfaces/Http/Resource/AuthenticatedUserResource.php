<?php

declare(strict_types=1);

namespace App\Identity\Interfaces\Http\Resource;

use App\Models\User;
use App\Shared\Interfaces\Http\Resource\ApiResource;
use Illuminate\Http\Request;

/**
 * O usuario autenticado, como o cliente o ve.
 *
 * Lista fechada de quatro campos. Nao e economia de digitacao: um recurso que
 * devolvesse o model inteiro passaria a expor toda coluna nova acrescentada
 * depois, sem que ninguem decidisse isso — e a primeira delas seria o hash da
 * senha. Aqui, o que nao esta escrito nao sai.
 *
 * Nada de sessao, token, carimbo de tempo interno ou hash atravessa esta
 * fronteira (RF-AUT-006, RN-AUT-005).
 *
 * @property-read User $resource
 */
final class AuthenticatedUserResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'name' => $this->resource->name,
            'email' => $this->resource->email,
            'role' => $this->resource->role->value,
        ];
    }
}
