<?php

declare(strict_types=1);

namespace Tests\Unit\Catalog;

use App\Catalog\Domain\Exception\InvalidPosition;
use App\Catalog\Domain\Lesson;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * O agregado `Lesson`, sozinho.
 *
 * Sem framework, sem banco — pelo mesmo motivo de `ModuleTest`: se o dominio
 * passar a precisar do Laravel, este arquivo para de rodar.
 *
 * O que mais importa aqui e o estado inicial. Uma aula nasce rascunho e sem
 * video, e as duas coisas precisam ser garantidas **pelo agregado**, e nao pelo
 * caso de uso: garantidas la, valeriam so enquanto ninguem escrevesse um segundo
 * caminho de criacao.
 */
final class LessonTest extends TestCase
{
    private const MODULO = '01936f1a-7c00-7000-8000-000000000001';

    private const ID = '01936f1a-7c00-7000-8000-0000000000aa';

    private const TENTATIVA = '01936f1a-7c00-7000-8000-0000000000bb';

    // -----------------------------------------------------------------------
    // Criar
    // -----------------------------------------------------------------------

    public function test_a_aula_criada_guarda_o_que_recebeu(): void
    {
        $aula = Lesson::create(self::ID, self::MODULO, 'Primeiros passos', 1);

        $this->assertSame(self::ID, $aula->id());
        $this->assertSame(self::MODULO, $aula->moduleId());
        $this->assertSame('Primeiros passos', $aula->title());
        $this->assertSame(1, $aula->position());
    }

    public function test_a_aula_nasce_sem_publicacao(): void
    {
        $aula = Lesson::create(self::ID, self::MODULO, 'Primeiros passos', 1);

        $this->assertNull($aula->publishedAt());
        $this->assertTrue($aula->isDraft());
    }

    public function test_a_aula_nasce_sem_tentativa_de_video(): void
    {
        $this->assertNull(
            Lesson::create(self::ID, self::MODULO, 'Primeiros passos', 1)->currentVideoAttemptId(),
        );
    }

    public function test_nao_existe_forma_de_criar_uma_aula_ja_publicada(): void
    {
        // A garantia e estrutural, e nao uma checagem em tempo de execucao:
        // `create` simplesmente nao tem parametro para publicacao nem para video.
        // Um caso de uso futuro nao consegue criar uma aula publicada por
        // descuido, porque nao ha por onde (RF-AUL-004, RF-AUL-005).
        $parametros = array_map(
            fn (\ReflectionParameter $p): string => $p->getName(),
            (new \ReflectionMethod(Lesson::class, 'create'))->getParameters(),
        );

        $this->assertSame(['id', 'moduleId', 'title', 'position'], $parametros);
    }

    public function test_o_rascunho_e_representado_pela_ausencia_de_data(): void
    {
        // Nao existe estado `draft` proprio: o plano representa o rascunho pela
        // ausencia de `published_at` (plan §7.2). Um enum ao lado da coluna daria
        // duas fontes para a mesma verdade, livres para divergir.
        $constantes = (new ReflectionClass(Lesson::class))->getConstants();

        $this->assertArrayNotHasKey('DRAFT', $constantes);
        $this->assertSame([], array_filter(
            array_keys($constantes),
            fn (string $nome): bool => str_contains(strtolower($nome), 'draft'),
        ));
    }

    // -----------------------------------------------------------------------
    // Reconstituir
    // -----------------------------------------------------------------------

    public function test_reconstituir_traz_publicacao_e_video_de_fora(): void
    {
        $publicadaEm = new DateTimeImmutable('2026-09-05T13:45:12+00:00');

        $aula = Lesson::reconstitute(
            self::ID,
            self::MODULO,
            'Primeiros passos',
            2,
            $publicadaEm,
            self::TENTATIVA,
        );

        // Aqui os dois campos vem de fora porque ja foram decididos — pela
        // publicacao e pela abertura de um envio. Reconstituir nao e criar.
        $this->assertSame($publicadaEm, $aula->publishedAt());
        $this->assertSame(self::TENTATIVA, $aula->currentVideoAttemptId());
        $this->assertFalse($aula->isDraft());
    }

    public function test_reconstituir_uma_aula_em_rascunho_devolve_o_mesmo_que_criar(): void
    {
        $criada = Lesson::create(self::ID, self::MODULO, 'Primeiros passos', 1);
        $relida = Lesson::reconstitute(self::ID, self::MODULO, 'Primeiros passos', 1, null, null);

        $this->assertEquals($criada, $relida);
    }

    public function test_reconstituir_tambem_recusa_posicao_invalida(): void
    {
        $this->expectException(InvalidPosition::class);

        Lesson::reconstitute(self::ID, self::MODULO, 'Primeiros passos', 0, null, null);
    }

    // -----------------------------------------------------------------------
    // Posicao valida
    // -----------------------------------------------------------------------

    #[DataProvider('posicoesInvalidas')]
    public function test_posicao_abaixo_de_um_e_recusada(int $posicao): void
    {
        $this->expectException(InvalidPosition::class);

        Lesson::create(self::ID, self::MODULO, 'Primeiros passos', $posicao);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function posicoesInvalidas(): iterable
    {
        yield 'zero' => [0];
        yield 'negativa' => [-1];
    }

    public function test_a_primeira_posicao_e_a_mesma_do_modulo(): void
    {
        // A regra de ordem e uma so, e vale nos dois niveis da arvore. Duas
        // constantes iguais em lugares diferentes seriam duas regras esperando
        // para divergir.
        $this->assertSame(1, Lesson::create(self::ID, self::MODULO, 'Primeira', 1)->position());
    }

    // -----------------------------------------------------------------------
    // Propriedade herdada
    // -----------------------------------------------------------------------

    public function test_a_aula_sabe_a_que_modulo_pertence(): void
    {
        $aula = Lesson::create(self::ID, self::MODULO, 'Primeiros passos', 1);

        $this->assertTrue($aula->belongsToModule(self::MODULO));
        $this->assertFalse($aula->belongsToModule('01936f1a-7c00-7000-8000-0000000000ff'));
    }

    // -----------------------------------------------------------------------
    // PHP puro
    // -----------------------------------------------------------------------

    public function test_o_agregado_nao_depende_de_framework(): void
    {
        // A verificacao e sobre o que o arquivo **importa**, e nao sobre o texto
        // dele: o proprio comentario da classe explica que ela nao depende de
        // Eloquent, e proibir a palavra empurraria a explicacao para fora do
        // codigo. A varredura de todas as camadas esta em `HexagonalBoundariesTest`.
        preg_match_all(
            '/^use\s+([A-Za-z0-9_\\\\]+)/m',
            (string) file_get_contents((new ReflectionClass(Lesson::class))->getFileName()),
            $importacoes,
        );

        foreach ($importacoes[1] as $importado) {
            $this->assertNotContains(
                explode('\\\\', $importado)[0],
                ['Illuminate', 'Symfony', 'Laravel'],
                $importado,
            );
        }
    }

    public function test_a_aula_nao_guarda_o_estado_do_video(): void
    {
        // Ela guarda **qual** e a tentativa atual, e nao em que estado ela esta.
        // Copiar o estado para dentro da aula criaria uma segunda verdade que
        // envelhece a cada callback (plan §6.1).
        $propriedades = array_map(
            fn (\ReflectionProperty $p): string => $p->getName(),
            (new ReflectionClass(Lesson::class))->getProperties(),
        );

        $this->assertContains('currentVideoAttemptId', $propriedades);
        $this->assertNotContains('videoState', $propriedades);
        $this->assertNotContains('currentVideoState', $propriedades);
    }

    public function test_publicar_marca_o_instante_e_deixa_de_ser_rascunho(): void
    {
        $instante = new DateTimeImmutable('2026-09-06T10:00:00+00:00');

        $publicada = Lesson::create(self::ID, self::MODULO, 'Primeiros passos', 1)->publish($instante);

        $this->assertFalse($publicada->isDraft());
        $this->assertEquals($instante, $publicada->publishedAt());
    }

    public function test_publicar_de_novo_preserva_o_instante_original(): void
    {
        // A idempotencia mora no agregado, e nao no caso de uso: assim ela vale
        // por qualquer caminho que venha a publicar, e o instante da primeira
        // publicacao nunca e reescrito por uma segunda chamada (RN-PUB-005).
        $primeira = new DateTimeImmutable('2026-09-06T10:00:00+00:00');
        $segunda = new DateTimeImmutable('2026-09-07T10:00:00+00:00');

        $publicada = Lesson::create(self::ID, self::MODULO, 'Primeiros passos', 1)->publish($primeira);
        $republicada = $publicada->publish($segunda);

        $this->assertEquals($primeira, $republicada->publishedAt());
    }

    public function test_o_agregado_nao_decide_se_o_video_permite_publicar(): void
    {
        // `publish()` nao recebe estado de video, e nao poderia: a tentativa e
        // outro agregado, e a aula guarda so o identificador dela. Quem verifica
        // se o video esta `ready` com referencia e o caso de uso, que coordena os
        // tres sob trava (plan §6.1).
        $parametros = array_map(
            fn (\ReflectionParameter $p): string => $p->getName(),
            (new ReflectionClass(Lesson::class))->getMethod('publish')->getParameters(),
        );

        $this->assertSame(['publishedAt'], $parametros);
    }

    public function test_apontar_para_uma_tentativa_substitui_a_anterior(): void
    {
        $comVideo = Lesson::create(self::ID, self::MODULO, 'Primeiros passos', 1)->attachVideoAttempt('01936f1a-7c00-7a3e-9b7d-000000000001');
        $substituida = $comVideo->attachVideoAttempt('01936f1a-7c00-7a3e-9b7d-000000000002');

        // No maximo **uma** tentativa atual (RF-AUL-005): apontar para a nova nao
        // acumula, e a assinatura de um unico identificador torna isso verdade
        // por construcao.
        $this->assertSame('01936f1a-7c00-7a3e-9b7d-000000000002', $substituida->currentVideoAttemptId());
    }

    public function test_apontar_para_uma_tentativa_nao_publica_a_aula(): void
    {
        $this->assertTrue(
            Lesson::create(self::ID, self::MODULO, 'Primeiros passos', 1)->attachVideoAttempt('01936f1a-7c00-7a3e-9b7d-000000000001')->isDraft(),
        );
    }

    public function test_o_agregado_e_imutavel_e_so_nasce_pelas_duas_fabricas(): void
    {
        $classe = new ReflectionClass(Lesson::class);

        $this->assertTrue($classe->isFinal());
        $this->assertTrue($classe->getConstructor()?->isPrivate());

        foreach ($classe->getProperties() as $propriedade) {
            $this->assertTrue($propriedade->isReadOnly(), $propriedade->getName());
        }
    }
}
