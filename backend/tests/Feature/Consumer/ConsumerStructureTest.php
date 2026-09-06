<?php

declare(strict_types=1);

namespace Tests\Feature\Consumer;

use App\Catalog\Domain\Course;
use App\Models\User;
use App\Video\Domain\VideoState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\CatalogoDeTeste;
use Tests\TestCase;

/**
 * `GET /api/catalog/courses/{course}`.
 *
 * A arvore que o consumidor navega difere da do produtor em duas coisas, e as
 * duas sao verificadas aqui: ela nao contem rascunho (RN-AUT-004) e nao contem
 * estado de video (RF-EST-004). A ordem, essa e a mesma — e continua vindo do
 * SQL (RF-EST-002, RF-CONS-003).
 *
 * A terceira afirmacao e sobre a negativa: curso sem concessao e curso
 * inexistente precisam ser **indistinguiveis**, e nao apenas ambos negados. A
 * comparacao e feita campo a campo, porque uma diferenca de titulo ou de codigo
 * ja bastaria para transformar a rota num verificador de identificadores
 * (RN-PROP-005, RF-CONS-005).
 */
final class ConsumerStructureTest extends TestCase
{
    use CatalogoDeTeste, RefreshDatabase;

    private User $consumidor;

    private User $produtor;

    private Course $curso;

    protected function setUp(): void
    {
        parent::setUp();

        $this->consumidor = User::factory()->consumer()->create();
        $this->produtor = User::factory()->producer()->create();
        $this->curso = $this->umCurso($this->produtor, 'Curso concedido');

        $this->concedidoA($this->curso, $this->consumidor);
    }

    // -----------------------------------------------------------------------
    // A arvore
    // -----------------------------------------------------------------------

    public function test_a_arvore_preserva_a_ordem_de_modulos_e_aulas(): void
    {
        foreach (['Primeiro', 'Segundo', 'Terceiro'] as $tituloModulo) {
            $modulo = $this->umModulo($this->curso, $tituloModulo);

            foreach (['A', 'B'] as $tituloAula) {
                $this->publicadaEm(
                    $this->umaAula($modulo, $tituloModulo.'-'.$tituloAula),
                    '2026-09-06T10:00:00+00:00',
                );
            }
        }

        $resposta = $this->actingAs($this->consumidor)
            ->getJson('/api/catalog/courses/'.$this->curso->id());

        $resposta->assertOk();

        $this->assertSame([1, 2, 3], array_column($resposta->json('data.modules'), 'position'));
        $this->assertSame(
            ['Primeiro', 'Segundo', 'Terceiro'],
            array_column($resposta->json('data.modules'), 'title'),
        );

        foreach ($resposta->json('data.modules') as $indice => $modulo) {
            $this->assertSame([1, 2], array_column($modulo['lessons'], 'position'), 'modulo '.$indice);
        }

        $this->assertSame(
            ['Primeiro-A', 'Primeiro-B'],
            array_column($resposta->json('data.modules.0.lessons'), 'title'),
        );
    }

    public function test_a_arvore_exclui_aulas_nao_publicadas(): void
    {
        $modulo = $this->umModulo($this->curso);

        $publicada = $this->umaAula($modulo, 'Publicada');
        $rascunho = $this->umaAula($modulo, 'Rascunho');

        $this->publicadaEm($publicada, '2026-09-06T10:00:00+00:00');

        $resposta = $this->actingAs($this->consumidor)
            ->getJson('/api/catalog/courses/'.$this->curso->id());

        $resposta->assertOk();
        $this->assertSame([$publicada->id()], array_column($resposta->json('data.modules.0.lessons'), 'id'));

        // Nem o identificador, nem o titulo: nada da aula em rascunho atravessa.
        $corpo = (string) $resposta->getContent();
        $this->assertStringNotContainsString($rascunho->id(), $corpo);
        $this->assertStringNotContainsString('Rascunho', $corpo);
    }

    public function test_modulo_sem_aula_publicada_aparece_com_a_lista_vazia(): void
    {
        $modulo = $this->umModulo($this->curso, 'So rascunhos');
        $this->umaAula($modulo, 'Rascunho');

        $resposta = $this->actingAs($this->consumidor)
            ->getJson('/api/catalog/courses/'.$this->curso->id());

        // O modulo continua na arvore: o que o consumidor deixa de ver e o
        // conteudo nao publicado, e nao a organizacao do curso.
        $resposta->assertOk();
        $resposta->assertJsonPath('data.modules.0.title', 'So rascunhos');
        $resposta->assertJsonPath('data.modules.0.lessons', []);
    }

    public function test_curso_sem_modulo_aparece_com_a_lista_vazia(): void
    {
        $resposta = $this->actingAs($this->consumidor)
            ->getJson('/api/catalog/courses/'.$this->curso->id());

        $resposta->assertOk();
        $resposta->assertJsonPath('data.modules', []);
    }

    // -----------------------------------------------------------------------
    // O que a arvore nao expoe
    // -----------------------------------------------------------------------

    public function test_a_aula_do_consumidor_nao_carrega_estado_de_video(): void
    {
        $modulo = $this->umModulo($this->curso);
        $aula = $this->umaAula($modulo);

        $this->umaTentativaDeVideo($aula, VideoState::PROCESSING);
        $this->publicadaEm($aula, '2026-09-06T10:00:00+00:00');

        $resposta = $this->actingAs($this->consumidor)
            ->getJson('/api/catalog/courses/'.$this->curso->id());

        $resposta->assertOk();

        // Cinco campos, lista fechada: um campo novo aqui e um campo que o
        // contrato passa a manter, e `video_state` em particular seria estado
        // interno de processamento exposto a quem so assiste.
        $this->assertSame(
            ['id', 'module_id', 'title', 'position', 'published_at'],
            array_keys($resposta->json('data.modules.0.lessons.0')),
        );

        $corpo = (string) $resposta->getContent();

        foreach (['video_state', 'storage_key', 'failure_code', 'processing', 'multipart'] as $interno) {
            $this->assertStringNotContainsString($interno, $corpo);
        }
    }

    // -----------------------------------------------------------------------
    // A negativa
    // -----------------------------------------------------------------------

    public function test_curso_sem_concessao_e_curso_inexistente_respondem_o_mesmo(): void
    {
        $alheio = $this->umCurso($this->produtor, 'Sem concessao');
        $this->publicadaEm($this->umaAula($this->umModulo($alheio)), '2026-09-06T10:00:00+00:00');

        $semConcessao = $this->actingAs($this->consumidor)
            ->getJson('/api/catalog/courses/'.$alheio->id());

        $inexistente = $this->actingAs($this->consumidor)
            ->getJson('/api/catalog/courses/'.(string) Str::uuid7());

        $semConcessao->assertNotFound();
        $inexistente->assertNotFound();

        $this->assertSame($inexistente->json(), $semConcessao->json());
        $this->assertSame(
            $inexistente->headers->get('Content-Type'),
            $semConcessao->headers->get('Content-Type'),
        );
        $semConcessao->assertHeader('Content-Type', 'application/problem+json');
    }

    public function test_a_negativa_nao_devolve_conteudo_do_curso(): void
    {
        $alheio = $this->umCurso($this->produtor, 'Titulo secreto');
        $this->publicadaEm(
            $this->umaAula($this->umModulo($alheio, 'Modulo secreto'), 'Aula secreta'),
            '2026-09-06T10:00:00+00:00',
        );

        $resposta = $this->actingAs($this->consumidor)
            ->getJson('/api/catalog/courses/'.$alheio->id());

        $resposta->assertNotFound();

        $corpo = (string) $resposta->getContent();

        foreach ([$alheio->id(), 'Titulo secreto', 'Modulo secreto', 'Aula secreta'] as $vazamento) {
            $this->assertStringNotContainsString($vazamento, $corpo);
        }
    }

    public function test_curso_concedido_em_rascunho_continua_navegavel(): void
    {
        // A listagem esconde o rascunho porque nao ha o que consumir nele
        // (RF-CONS-002). O acesso direto ao curso concedido nao e negado: ele
        // devolve a arvore, que estara vazia enquanto nada tiver sido publicado.
        $resposta = $this->actingAs($this->consumidor)
            ->getJson('/api/catalog/courses/'.$this->curso->id());

        $resposta->assertOk();
        $resposta->assertJsonPath('data.state', 'draft');
        $resposta->assertJsonPath('data.modules', []);
    }
}
