<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Identity\Domain\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Usuarios para os testes.
 *
 * **Nao declara `id`.** A geracao do identificador pertence ao model, via
 * `HasUuids` — se a factory tambem gerasse, existiriam duas fontes para o mesmo
 * valor, livres para divergir sem que nada acusasse.
 *
 * O perfil e obrigatorio no esquema e restrito a dois valores, entao o padrao
 * ja e um deles e os dois estados nomeados tornam a intencao do teste explicita:
 * `User::factory()->producer()` diz o que esta sendo montado melhor do que um
 * array de atributos no meio do teste.
 *
 * @extends Factory<User>
 */
final class UserFactory extends Factory
{
    protected $model = User::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => $this->faker->name(),
            'email' => $this->faker->unique()->safeEmail(),
            // O cast `hashed` do model aplica o hash na atribuicao.
            'password' => 'senha-de-teste',
            'role' => Role::PRODUCER->value,
        ];
    }

    public function producer(): static
    {
        return $this->state(fn (): array => ['role' => Role::PRODUCER->value]);
    }

    public function consumer(): static
    {
        return $this->state(fn (): array => ['role' => Role::CONSUMER->value]);
    }
}
