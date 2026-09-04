<?php

declare(strict_types=1);

namespace App\Identity\Interfaces\Http\Request;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Entrada do login.
 *
 * Valida apenas forma — presenca, tipo e formato. Se as credenciais conferem e
 * pergunta do caso de uso, nao do validador: responder aqui exigiria consultar
 * o banco durante a validacao e produziria uma mensagem diferente para "e-mail
 * nao existe", que e exatamente a distincao que RF-AUT-008 elimina.
 */
final class LoginRequest extends FormRequest
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
        return [
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'email' => 'e-mail',
            'password' => 'senha',
        ];
    }
}
