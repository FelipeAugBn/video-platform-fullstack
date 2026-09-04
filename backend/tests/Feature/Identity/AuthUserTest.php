<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Identity\Domain\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

/**
 * O model e a factory contra o esquema real.
 *
 * A T022 gravou a tabela definitiva e as duas classes vinham do esqueleto do
 * framework, desalinhadas dela. Estes testes existem para que esse desalinhamento
 * nao volte em silencio: eles criam usuarios de verdade, e uma coluna que deixe
 * de existir ou um perfil invalido falham aqui, e nao na primeira tarefa que
 * precisar de um usuario.
 */
final class AuthUserTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_factory_cria_os_dois_perfis(): void
    {
        $produtor = User::factory()->producer()->create();
        $consumidor = User::factory()->consumer()->create();

        $this->assertSame(Role::PRODUCER, $produtor->role);
        $this->assertSame(Role::CONSUMER, $consumidor->role);

        $this->assertDatabaseHas('users', ['id' => $produtor->id, 'role' => 'producer']);
        $this->assertDatabaseHas('users', ['id' => $consumidor->id, 'role' => 'consumer']);
    }

    public function test_o_identificador_e_gerado_pelo_model_como_uuid_versao_7(): void
    {
        foreach ([User::factory()->producer(), User::factory()->consumer()] as $factory) {
            // A factory nao declara `id`: o valor abaixo so pode ter vindo do
            // trait do model. Duas fontes para o mesmo identificador divergiriam
            // em silencio.
            $this->assertArrayNotHasKey('id', $factory->raw());

            $usuario = $factory->create();

            $this->assertSame(7, Uuid::fromString($usuario->id)->getFields()->getVersion());
            $this->assertSame(36, strlen($usuario->id));
        }
    }

    public function test_a_chave_e_string_e_nao_incremental(): void
    {
        $usuario = User::factory()->create();

        $this->assertFalse($usuario->getIncrementing());
        $this->assertSame('string', $usuario->getKeyType());
    }

    public function test_a_senha_e_gravada_como_hash(): void
    {
        $usuario = User::factory()->create(['password' => 'segredo-de-teste']);

        $bruto = DB::table('users')->where('id', $usuario->id)->value('password');

        $this->assertNotSame('segredo-de-teste', $bruto);
        $this->assertTrue(Hash::check('segredo-de-teste', (string) $bruto));
    }

    public function test_o_model_nao_expoe_a_senha_ao_serializar(): void
    {
        $serializado = User::factory()->create()->toArray();

        $this->assertArrayNotHasKey('password', $serializado);
        $this->assertArrayNotHasKey('remember_token', $serializado);
    }

    public function test_o_esquema_nao_ganhou_tabela_de_tokens_pessoais(): void
    {
        // O Sanctum roda em modo SPA, com sessao. A tabela de tokens pessoais
        // pertence ao outro modo do pacote e nao foi publicada — se aparecesse,
        // seria superficie de autenticacao que ninguem decidiu abrir.
        $tabelas = array_map(
            static fn (object $linha): string => $linha->TABLE_NAME,
            DB::select(
                'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = ?',
                [DB::getDatabaseName()]
            )
        );

        $this->assertNotContains('personal_access_tokens', $tabelas);
        $this->assertCount(13, $tabelas);
    }
}
