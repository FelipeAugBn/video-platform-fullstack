<?php

declare(strict_types=1);

namespace Tests\Unit\Catalog;

use App\Catalog\Domain\Course;
use App\Catalog\Domain\CourseState;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * O agregado `Course`, sozinho.
 *
 * Estende o `TestCase` do PHPUnit diretamente, e nao o da aplicacao: sem
 * framework, sem banco, sem variavel de ambiente. Isso nao e detalhe de
 * organizacao — e a verificacao pratica de que a regra de negocio de fato nao
 * depende de infraestrutura. Se um dia o dominio passar a precisar do Laravel,
 * este arquivo para de rodar, e a falha aponta a causa.
 */
final class CourseTest extends TestCase
{
    private const DONO = '01936f1a-7c00-7000-8000-000000000001';

    private const ID = '01936f1a-7c00-7000-8000-0000000000aa';

    // -----------------------------------------------------------------------
    // Estados possiveis
    // -----------------------------------------------------------------------

    public function test_o_curso_tem_exatamente_dois_estados(): void
    {
        // A lista e escrita aqui de forma independente da enumeracao. Comparar a
        // enumeracao com ela mesma nao provaria nada; escrita a parte, qualquer
        // estado acrescentado sem passar por uma decisao faz este teste falhar.
        $this->assertSame(
            ['draft', 'available'],
            array_map(fn (CourseState $estado): string => $estado->value, CourseState::cases()),
        );
    }

    #[DataProvider('estadosInexistentes')]
    public function test_estado_fora_do_conjunto_nao_existe(string $valor): void
    {
        $this->assertNull(CourseState::tryFrom($valor));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function estadosInexistentes(): iterable
    {
        // `published` e `archived` sao os dois nomes que alguem escreveria por
        // habito de outros sistemas. Nenhum existe aqui: um curso fica disponivel
        // como consequencia da publicacao da primeira aula, e nao ha arquivamento.
        yield 'published' => ['published'];
        yield 'archived' => ['archived'];
        yield 'pending' => ['pending'];
        yield 'vazio' => [''];
    }

    // -----------------------------------------------------------------------
    // Criacao
    // -----------------------------------------------------------------------

    public function test_curso_novo_nasce_em_rascunho(): void
    {
        $curso = $this->cursoNovo();

        $this->assertSame(CourseState::DRAFT, $curso->state());
    }

    public function test_o_estado_inicial_nao_pode_ser_escolhido_por_quem_cria(): void
    {
        // A garantia acima nao vem de disciplina: nao existe parametro de estado
        // em `create`. Um caso de uso futuro nao consegue criar um curso ja
        // disponivel nem por engano nem de proposito.
        $parametros = array_map(
            fn (\ReflectionParameter $p): string => $p->getName(),
            (new \ReflectionMethod(Course::class, 'create'))->getParameters(),
        );

        $this->assertSame(['id', 'ownerId', 'title', 'description', 'createdAt'], $parametros);
        $this->assertNotContains('state', $parametros);
    }

    public function test_o_dono_informado_e_preservado(): void
    {
        $curso = $this->cursoNovo();

        $this->assertSame(self::DONO, $curso->ownerId());
    }

    public function test_identificador_titulo_descricao_e_data_sao_preservados(): void
    {
        $criadoEm = new DateTimeImmutable('2026-09-05T13:45:12+00:00');

        $curso = Course::create(
            id: self::ID,
            ownerId: self::DONO,
            title: 'Fundamentos de PHP',
            description: 'Uma introducao pratica a linguagem.',
            createdAt: $criadoEm,
        );

        $this->assertSame(self::ID, $curso->id());
        $this->assertSame('Fundamentos de PHP', $curso->title());
        $this->assertSame('Uma introducao pratica a linguagem.', $curso->description());
        $this->assertEquals($criadoEm, $curso->createdAt());
    }

    public function test_a_data_recebida_nao_e_alterada_pelo_agregado(): void
    {
        $criadoEm = new DateTimeImmutable('2026-01-02T03:04:05', new DateTimeZone('UTC'));

        $curso = Course::create(self::ID, self::DONO, 'T', 'D', $criadoEm);

        // Mesmo instante e mesmo fuso: guardar em fuso diferente faria a data
        // exibida mudar conforme onde o processo rodou.
        $this->assertSame(
            $criadoEm->format(DATE_ATOM),
            $curso->createdAt()->format(DATE_ATOM),
        );
    }

    // -----------------------------------------------------------------------
    // Reconstituicao
    // -----------------------------------------------------------------------

    public function test_reconstituir_preserva_o_estado_que_o_curso_tinha(): void
    {
        // O par negativo de `create`: aqui o estado vem de fora de proposito,
        // porque reler um curso publicado nao pode rebaixa-lo a rascunho.
        $curso = Course::reconstitute(
            id: self::ID,
            ownerId: self::DONO,
            title: 'Fundamentos de PHP',
            description: 'Descricao.',
            state: CourseState::AVAILABLE,
            createdAt: new DateTimeImmutable('2026-09-05T13:45:12+00:00'),
        );

        $this->assertSame(CourseState::AVAILABLE, $curso->state());
    }

    // -----------------------------------------------------------------------
    // Propriedade
    // -----------------------------------------------------------------------

    public function test_o_curso_reconhece_o_proprio_dono(): void
    {
        $curso = $this->cursoNovo();

        $this->assertTrue($curso->isOwnedBy(self::DONO));
    }

    public function test_o_curso_nao_reconhece_outro_produtor_como_dono(): void
    {
        $curso = $this->cursoNovo();

        $this->assertFalse($curso->isOwnedBy('01936f1a-7c00-7000-8000-000000000002'));
    }

    // -----------------------------------------------------------------------
    // A regra nao conhece infraestrutura
    // -----------------------------------------------------------------------

    #[DataProvider('classesDoDominio')]
    public function test_o_dominio_nao_importa_framework_nem_persistencia(string $classe): void
    {
        $arquivo = (new ReflectionClass($classe))->getFileName();
        $this->assertIsString($arquivo);

        $codigo = file_get_contents($arquivo);
        $this->assertIsString($codigo);

        // A verificacao e sobre o codigo-fonte, e nao sobre o comportamento: uma
        // dependencia pode existir sem ser exercida pelos outros testes e passar
        // despercebida ate alguem tentar rodar o dominio isolado.
        foreach (['Illuminate\\', 'App\\Models\\', 'Infrastructure', 'Interfaces'] as $proibido) {
            $this->assertStringNotContainsString(
                $proibido,
                $codigo,
                "{$classe} nao pode depender de {$proibido}",
            );
        }
    }

    /**
     * @return iterable<string, array{class-string}>
     */
    public static function classesDoDominio(): iterable
    {
        yield 'Course' => [Course::class];
        yield 'CourseState' => [CourseState::class];
    }

    public function test_este_teste_roda_sem_a_aplicacao_do_framework(): void
    {
        // A afirmacao e sobre esta propria suite: se alguem trocar a classe base
        // por `Tests\TestCase`, o container passaria a existir e a garantia de
        // isolamento se perderia em silencio.
        $this->assertFalse(function_exists('app') && app() !== null && app()->bound('db'));
    }

    // -----------------------------------------------------------------------
    // Apoio
    // -----------------------------------------------------------------------

    private function cursoNovo(): Course
    {
        return Course::create(
            id: self::ID,
            ownerId: self::DONO,
            title: 'Fundamentos de PHP',
            description: 'Uma introducao pratica a linguagem.',
            createdAt: new DateTimeImmutable('2026-09-05T13:45:12+00:00'),
        );
    }
}
