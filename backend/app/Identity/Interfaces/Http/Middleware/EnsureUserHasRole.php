<?php

declare(strict_types=1);

namespace App\Identity\Interfaces\Http\Middleware;

use App\Identity\Domain\Role;
use App\Models\User;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Restringe uma rota a um perfil.
 *
 * E a primeira das duas camadas de autorizacao do plano (§9.3). Esta decide
 * "que tipo de usuario pode chamar esta rota"; a segunda — "este recurso e
 * seu?" — vive nos casos de uso e chega a partir de T034. Uma nao substitui a
 * outra: passar por aqui nao concede acesso a recurso nenhum.
 *
 * A distincao entre as duas respostas e deliberada:
 *
 *   401  nao ha sessao, ou ela expirou. Ha saida: autenticar de novo.
 *   403  ha sessao valida, mas o perfil nao serve para esta rota. Nao ha saida
 *        pela mesma sessao.
 *
 * O frontend precisa distinguir os dois para escolher entre reconduzir ao login
 * e mostrar acesso negado (RF-UI-013, RF-UI-014).
 *
 * As duas excecoes sao lancadas em vez de respondidas aqui: o formato do corpo
 * pertence ao tratamento central de erros, que ja as converte no
 * `problem+json` do contrato.
 */
final class EnsureUserHasRole
{
    /**
     * @param  string  ...$perfis  valores de `Role`, aceitos na definicao da rota
     *
     * @throws AuthenticationException
     * @throws AccessDeniedHttpException
     */
    public function handle(Request $request, Closure $next, string ...$perfis): Response
    {
        $usuario = $request->user();

        if (! $usuario instanceof User) {
            throw new AuthenticationException;
        }

        $permitidos = array_map(
            static fn (string $perfil): Role => Role::from($perfil),
            $perfis,
        );

        if (! in_array($usuario->role, $permitidos, true)) {
            throw new AccessDeniedHttpException;
        }

        return $next($request);
    }
}
