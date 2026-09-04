<?php

declare(strict_types=1);

namespace App\Identity\Interfaces\Http\Controller;

use App\Identity\Interfaces\Http\Request\LoginRequest;
use App\Identity\Interfaces\Http\Resource\AuthenticatedUserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * Entrada e saida da sessao.
 *
 * Tres operacoes e nenhuma regra de negocio: autenticar, dizer quem esta
 * autenticado e encerrar. O que existe de decisao aqui e de fronteira — formato
 * da resposta e ciclo de vida da sessao.
 */
final class AuthController
{
    /**
     * Autentica pela sessao do navegador.
     *
     * **A mesma resposta para e-mail inexistente e para senha errada**
     * (RF-AUT-008). Um `404` para o primeiro e um `422` para o segundo
     * transformariam a tela de login num verificador de cadastro: bastaria
     * varrer uma lista de e-mails para descobrir quem tem conta. A mensagem e
     * unica, generica, e nao diz qual dos dois falhou.
     *
     * `Auth::attempt` compara o hash mesmo quando o e-mail nao existe? Nao — e
     * por isso a mensagem precisa ser identica de qualquer forma. Diferencas de
     * tempo entre os dois caminhos sao ruido de rede neste contexto e nao
     * abrem, sozinhas, a enumeracao que a mensagem unica fecha.
     *
     * @throws ValidationException
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $credenciais = $request->only('email', 'password');

        if (! Auth::guard('web')->attempt($credenciais)) {
            throw ValidationException::withMessages([
                'email' => [__('auth.failed')],
            ]);
        }

        // Sem isto, um identificador de sessao obtido antes do login continuaria
        // valido depois dele — e um atacante que tivesse plantado esse
        // identificador no navegador da vitima passaria a compartilhar a sessao
        // autenticada. E a defesa contra fixacao de sessao, e ela precisa
        // acontecer no instante em que o nivel de privilegio muda.
        $request->session()->regenerate();

        $usuario = $request->user();
        assert($usuario instanceof User);

        return AuthenticatedUserResource::make($usuario)->response();
    }

    /**
     * Quem esta autenticado nesta sessao.
     */
    public function me(Request $request): JsonResponse
    {
        $usuario = $request->user();
        assert($usuario instanceof User);

        return AuthenticatedUserResource::make($usuario)->response();
    }

    /**
     * Encerra a sessao.
     *
     * As tres etapas sao necessarias e fazem coisas diferentes: `logout`
     * esquece o usuario no guard, `invalidate` descarta a linha da sessao no
     * banco — o que impede reaproveitar o cookie antigo — e
     * `regenerateToken` emite um token CSRF novo, para a proxima requisicao
     * mutante nao ser recusada com o token da sessao ja encerrada.
     *
     * `204` porque nao ha corpo a devolver: quem chamou sabe o que pediu, e
     * inventar um objeto de confirmacao so daria ao cliente algo a interpretar.
     */
    public function logout(Request $request): Response
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->noContent();
    }
}
