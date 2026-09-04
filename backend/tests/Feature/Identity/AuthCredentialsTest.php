<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * A recusa de credenciais, e por que ela e sempre a mesma.
 *
 * Uma API que responde diferente para "e-mail nao cadastrado" e para "senha
 * errada" e um verificador de cadastro: basta varrer uma lista de enderecos para
 * descobrir quem tem conta na plataforma. RF-AUT-008 fecha isso exigindo que as
 * duas respostas sejam indistinguiveis — status, codigo, campo e texto.
 *
 * O `422` tambem separa dois significados que a interface trata de formas
 * diferentes: aqui a requisicao chegou e foi recusada pelo conteudo; o `401`
 * fica reservado a ausencia de sessao, que reconduz ao login.
 */
final class AuthCredentialsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withHeader('Origin', 'http://localhost:3000');
    }

    public function test_email_inexistente_e_senha_errada_produzem_a_mesma_resposta(): void
    {
        $usuario = User::factory()->create(['password' => 'senha-correta']);

        $inexistente = $this->postJson('/api/auth/login', [
            'email' => 'ninguem@video-platform.test',
            'password' => 'qualquer-senha',
        ]);

        $senhaErrada = $this->postJson('/api/auth/login', [
            'email' => $usuario->email,
            'password' => 'senha-errada',
        ]);

        foreach ([$inexistente, $senhaErrada] as $resposta) {
            $resposta->assertStatus(422)
                ->assertHeader('content-type', 'application/problem+json')
                ->assertJsonPath('code', 'VALIDATION_FAILED')
                ->assertJsonPath('status', 422);
        }

        // Indistinguiveis: mesmo corpo, byte a byte.
        $this->assertSame($inexistente->json(), $senhaErrada->json());
        $this->assertSame($inexistente->getContent(), $senhaErrada->getContent());
    }

    public function test_a_mensagem_de_recusa_nao_revela_qual_campo_falhou(): void
    {
        $resposta = $this->postJson('/api/auth/login', [
            'email' => 'ninguem@video-platform.test',
            'password' => 'qualquer-senha',
        ])->assertStatus(422);

        $mensagem = $resposta->json('errors.email.0');

        $this->assertIsString($mensagem);

        // Nada que diferencie os dois casos: nem "nao encontrado", nem "senha".
        foreach (['nao existe', 'nao encontrado', 'inexistente', 'cadastr', 'senha incorreta'] as $vazamento) {
            $this->assertStringNotContainsStringIgnoringCase($vazamento, $mensagem);
        }
    }

    public function test_credencial_recusada_nao_autentica_ninguem(): void
    {
        $usuario = User::factory()->create(['password' => 'senha-correta']);

        $this->postJson('/api/auth/login', [
            'email' => $usuario->email,
            'password' => 'senha-errada',
        ])->assertStatus(422);

        $this->assertGuest();

        // Uma linha de sessao existe — ela guarda o token CSRF e e criada para
        // qualquer requisicao, autenticada ou nao. O que nao pode existir e
        // vinculo com usuario: nenhuma sessao passa a pertencer a alguem.
        $this->assertSame(0, DB::table('sessions')->whereNotNull('user_id')->count());
    }

    public function test_a_validacao_de_entrada_responde_em_portugues(): void
    {
        $resposta = $this->postJson('/api/auth/login', [])->assertStatus(422);

        $this->assertSame('VALIDATION_FAILED', $resposta->json('code'));

        $email = $resposta->json('errors.email.0');
        $senha = $resposta->json('errors.password.0');

        $this->assertSame('O campo e-mail e obrigatorio.', $email);
        $this->assertSame('O campo senha e obrigatorio.', $senha);
    }

    public function test_email_malformado_e_recusado_com_mensagem_em_portugues(): void
    {
        $resposta = $this->postJson('/api/auth/login', [
            'email' => 'nao-e-um-email',
            'password' => 'qualquer-senha',
        ])->assertStatus(422);

        $this->assertSame(
            'O campo e-mail precisa ser um endereco de e-mail valido.',
            $resposta->json('errors.email.0')
        );
    }

    public function test_a_recusa_nao_devolve_a_senha_enviada(): void
    {
        $resposta = $this->postJson('/api/auth/login', [
            'email' => 'ninguem@video-platform.test',
            'password' => 'senha-secreta-do-teste',
        ])->assertStatus(422);

        $this->assertStringNotContainsString('senha-secreta-do-teste', (string) $resposta->getContent());
    }
}
