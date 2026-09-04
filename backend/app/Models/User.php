<?php

declare(strict_types=1);

namespace App\Models;

use App\Identity\Domain\Role;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * Usuario autenticavel.
 *
 * O model existe para a autenticacao e para a persistencia; as regras de
 * identidade que forem alem disso vivem em `App\Identity`. Ele nao carrega
 * comportamento de negocio.
 *
 * **`HasUuids` e a unica fonte do identificador.** Nesta versao do framework o
 * trait gera UUIDv7 e ja declara a chave como string nao incremental — por isso
 * nao ha gerador proprio aqui, nem redeclaracao de `$keyType` e
 * `$incrementing`: uma segunda fonte para o mesmo identificador diverge em
 * silencio, e a que estiver errada so aparece quando alguem compara os valores.
 *
 * O que **nao** existe neste model, porque nao existe no esquema (plan §7.2):
 * verificacao de e-mail e token de "lembrar de mim". Cadastro publico e
 * recuperacao de senha estao fora de escopo (spec §16), e uma coluna sem regra
 * que a use e esquema morto.
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasUuids;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
    ];

    /**
     * O hash nunca sai em resposta nem em log de model.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            // `hashed` faz o framework aplicar o hash ao atribuir, o que impede
            // uma senha em texto puro chegar a coluna por descuido.
            'password' => 'hashed',
            'role' => Role::class,
        ];
    }
}
