<?php

declare(strict_types=1);

namespace Tests\Feature\Consumer;

use App\Catalog\Domain\Course;
use App\Models\User;
use App\Video\Domain\VideoState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CatalogoDeTeste;
use Tests\Support\Video\ObjectStorageFake;
use Tests\Support\Video\VideoDeTeste;
use Tests\TestCase;

/**
 * As tres rotas de consumo contra as quatro situacoes de acesso.
 *
 * As duas camadas de plan §9.3 sao verificadas separadas, porque respondem
 * coisas diferentes:
 *
 *   **perfil**, na fronteira HTTP — `401` sem sessao, `403` para o produtor;
 *   **concessao**, no caso de uso — `404` para o consumidor sem acesso.
 *
 * A ordem entre as duas primeiras tambem e afirmada: um visitante anonimo recebe
 * `401`, e nao `403`. Invertida, a resposta diria a um desconhecido que existe
 * ali algo reservado a um perfil especifico.
 *
 * E o `403` do produtor e sobre o perfil, nao sobre o conteudo: **o produtor dono
 * do curso tambem recebe `403`** nas rotas de consumo. Ser dono nao e ser
 * consumidor, e a rota de consumo nao e um segundo caminho para a jornada de
 * gestao.
 */
final class ConsumerAccessTest extends TestCase
{
    use CatalogoDeTeste, RefreshDatabase, VideoDeTeste;

    private User $consumidor;

    private User $produtor;

    private Course $curso;

    private string $aulaId;

    private ObjectStorageFake $storage;

    protected function setUp(): void
    {
        parent::setUp();

        $this->consumidor = User::factory()->consumer()->create();
        $this->produtor = User::factory()->producer()->create();

        $this->curso = $this->umCurso($this->produtor);
        $aula = $this->umaAula($this->umModulo($this->curso));

        $this->umaTentativa($aula, VideoState::READY);
        $this->publicadaEm($aula, '2026-09-06T10:00:00+00:00');

        $this->aulaId = $aula->id();
        $this->storage = $this->storageFalso();
    }

    // -----------------------------------------------------------------------
    // Perfil
    // -----------------------------------------------------------------------

    #[DataProvider('rotasDeConsumo')]
    public function test_visitante_nao_autenticado_recebe_401(string $rota): void
    {
        $resposta = $this->getJson($this->enderecar($rota));

        $resposta->assertUnauthorized();
        $resposta->assertHeader('Content-Type', 'application/problem+json');
        $resposta->assertJsonPath('code', 'UNAUTHENTICATED');
        $this->assertSame([], $this->storage->leituras);
    }

    #[DataProvider('rotasDeConsumo')]
    public function test_produtor_autenticado_recebe_403(string $rota): void
    {
        // O produtor deste teste e o **dono** do curso. Mesmo assim a rota de
        // consumo o recusa: perfil e propriedade sao perguntas diferentes, e
        // esta rota so responde a primeira.
        $resposta = $this->actingAs($this->produtor)->getJson($this->enderecar($rota));

        $resposta->assertForbidden();
        $resposta->assertJsonPath('code', 'FORBIDDEN');
        $this->assertSame([], $this->storage->leituras);
    }

    #[DataProvider('rotasDeConsumo')]
    public function test_consumidor_concedido_e_atendido_nas_tres_rotas(string $rota): void
    {
        // O par positivo dos dois testes acima: sem ele, uma rota quebrada
        // recusaria todo mundo e as afirmacoes de `401` e `403` continuariam
        // passando.
        $this->concedidoA($this->curso, $this->consumidor);

        $this->actingAs($this->consumidor)
            ->getJson($this->enderecar($rota))
            ->assertOk();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function rotasDeConsumo(): iterable
    {
        yield 'listagem' => ['/api/catalog/courses'];
        yield 'estrutura' => ['/api/catalog/courses/{curso}'];
        yield 'reproducao' => ['/api/lessons/{aula}/playback'];
    }

    // -----------------------------------------------------------------------
    // Concessao
    // -----------------------------------------------------------------------

    public function test_consumidor_sem_concessao_recebe_404_nas_rotas_de_recurso(): void
    {
        foreach (['/api/catalog/courses/{curso}', '/api/lessons/{aula}/playback'] as $rota) {
            $resposta = $this->actingAs($this->consumidor)->getJson($this->enderecar($rota));

            // `404`, e nao `403`: o consumidor tem o perfil certo, e a negativa
            // aqui nao pode revelar que o recurso existe (RN-PROP-005,
            // RF-PLB-008).
            $resposta->assertNotFound($rota);
            $resposta->assertJsonPath('code', 'NOT_FOUND');
        }

        $this->assertSame([], $this->storage->leituras);
    }

    public function test_o_backend_recusa_mesmo_quando_a_interface_nao_recusaria(): void
    {
        // A autorizacao nao depende de nada que o cliente possa mudar: repetir a
        // requisicao anunciando outro consumidor no corpo e na query string nao
        // altera a resposta, porque o consumidor vem da sessao (RN-AUT-002).
        $outro = User::factory()->consumer()->create();
        $this->concedidoA($this->curso, $outro);

        $this->actingAs($this->consumidor)
            ->getJson('/api/catalog/courses/'.$this->curso->id().'?consumer_id='.$outro->getKey())
            ->assertNotFound();

        $this->actingAs($this->consumidor)
            ->json('GET', '/api/lessons/'.$this->aulaId.'/playback', [
                'consumer_id' => (string) $outro->getKey(),
            ])
            ->assertNotFound();

        $this->assertSame([], $this->storage->leituras);
    }

    // -----------------------------------------------------------------------
    // A matriz minima, sobre uma aula nao publicada
    // -----------------------------------------------------------------------

    /**
     * A ordem entre as tres decisoes, provada num cenario so.
     *
     * A aula usada aqui esta em **rascunho**, e essa e a escolha que faz o teste
     * valer alguma coisa: se autenticacao, perfil e concessao fossem avaliados
     * depois da disponibilidade, todos os atores receberiam o mesmo `409` — e a
     * existencia de uma aula nao publicada passaria a ser detectavel por quem nem
     * sessao tem.
     *
     * O que a matriz afirma e a hierarquia: `401` antes de `403`, `403` antes de
     * `404`, e `404` antes de `409`. Somente o consumidor **concedido** chega
     * longe o bastante para saber que a aula existe e ainda nao foi publicada.
     */
    #[DataProvider('matrizDeAcessoNaReproducao')]
    public function test_a_matriz_de_acesso_do_playback(string $ator, int $status, string $codigo): void
    {
        $aulaId = $this->umaAulaEmRascunhoComVideoPronto();

        if ($ator === 'consumidor concedido') {
            $this->concedidoA($this->curso, $this->consumidor);
        }

        $usuario = match ($ator) {
            'visitante' => null,
            'produtor dono' => $this->produtor,
            'produtor alheio' => User::factory()->producer()->create(),
            default => $this->consumidor,
        };

        if ($usuario !== null) {
            $this->actingAs($usuario);
        }

        $resposta = $this->getJson('/api/lessons/'.$aulaId.'/playback');

        $resposta->assertStatus($status);
        $resposta->assertHeader('Content-Type', 'application/problem+json');
        $resposta->assertJsonPath('code', $codigo);

        // Nenhum dos cinco caminhos e positivo, entao nenhum deles pode ter
        // chegado ao armazenamento.
        $this->assertStringNotContainsString('playback_url', (string) $resposta->getContent());
        $this->assertSame([], $this->storage->leituras);
    }

    /**
     * @return iterable<string, array{string, int, string}>
     */
    public static function matrizDeAcessoNaReproducao(): iterable
    {
        yield 'visitante' => ['visitante', 401, 'UNAUTHENTICATED'];
        yield 'produtor dono' => ['produtor dono', 403, 'FORBIDDEN'];
        yield 'produtor alheio' => ['produtor alheio', 403, 'FORBIDDEN'];
        yield 'consumidor sem concessao' => ['consumidor sem concessao', 404, 'NOT_FOUND'];
        yield 'consumidor concedido' => ['consumidor concedido', 409, 'LESSON_NOT_PUBLISHED'];
    }

    // -----------------------------------------------------------------------
    // Apoio
    // -----------------------------------------------------------------------

    /**
     * Uma segunda aula no mesmo curso: video pronto, publicacao ausente.
     *
     * O video em `ready` e proposital — assim a unica coisa que impede a
     * reproducao e a falta de publicacao, e o `409` do consumidor concedido
     * aponta para ela sem ambiguidade.
     */
    private function umaAulaEmRascunhoComVideoPronto(): string
    {
        $aula = $this->umaAula($this->umModulo($this->curso, 'Modulo em preparo'), 'Rascunho');

        $this->umaTentativa($aula, VideoState::READY);

        return $aula->id();
    }

    private function enderecar(string $rota): string
    {
        return str_replace(
            ['{curso}', '{aula}'],
            [$this->curso->id(), $this->aulaId],
            $rota,
        );
    }
}
