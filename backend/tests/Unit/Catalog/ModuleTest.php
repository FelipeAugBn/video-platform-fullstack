<?php

declare(strict_types=1);

namespace Tests\Unit\Catalog;

use App\Catalog\Domain\Exception\InvalidPosition;
use App\Catalog\Domain\Module;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * O agregado `Module`, sozinho.
 *
 * Estende o `TestCase` do PHPUnit diretamente, e nao o da aplicacao: sem
 * framework, sem banco, sem variavel de ambiente. Isso nao e detalhe de
 * organizacao — e a verificacao pratica de que a regra de negocio nao depende de
 * infraestrutura. Se um dia o dominio passar a precisar do Laravel, este arquivo
 * para de rodar, e a falha aponta a causa.
 */
final class ModuleTest extends TestCase
{
    private const CURSO = '01936f1a-7c00-7000-8000-000000000001';

    private const ID = '01936f1a-7c00-7000-8000-0000000000aa';

    // -----------------------------------------------------------------------
    // Criar
    // -----------------------------------------------------------------------

    public function test_o_modulo_criado_guarda_o_que_recebeu(): void
    {
        $modulo = Module::create(self::ID, self::CURSO, 'Introducao', 1);

        $this->assertSame(self::ID, $modulo->id());
        $this->assertSame(self::CURSO, $modulo->courseId());
        $this->assertSame('Introducao', $modulo->title());
        $this->assertSame(1, $modulo->position());
    }

    public function test_o_modulo_nao_calcula_a_propria_posicao(): void
    {
        // A posicao chega pronta, e o agregado a aceita como veio. Quem calcula e
        // o caso de uso, sob a trava do curso, porque a resposta depende dos
        // outros modulos — informacao que este objeto nao tem (plan §8.1).
        $this->assertSame(7, Module::create(self::ID, self::CURSO, 'Sete', 7)->position());
    }

    // -----------------------------------------------------------------------
    // Reconstituir
    // -----------------------------------------------------------------------

    public function test_reconstituir_devolve_o_mesmo_conteudo_que_criar(): void
    {
        $criado = Module::create(self::ID, self::CURSO, 'Introducao', 3);
        $relido = Module::reconstitute(self::ID, self::CURSO, 'Introducao', 3);

        $this->assertEquals($criado, $relido);
    }

    public function test_reconstituir_tambem_recusa_posicao_invalida(): void
    {
        // Poderia parecer redundante: a posicao ja passou pela regra quando o
        // modulo foi criado. Mas reconstituir le uma linha que pode ter sido
        // escrita por qualquer caminho — um seed, uma correcao manual —, e uma
        // posicao invalida vinda de la deve falhar na leitura em vez de virar uma
        // ordem silenciosamente errada na tela.
        $this->expectException(InvalidPosition::class);

        Module::reconstitute(self::ID, self::CURSO, 'Introducao', 0);
    }

    // -----------------------------------------------------------------------
    // Posicao valida
    // -----------------------------------------------------------------------

    public function test_a_primeira_posicao_e_um(): void
    {
        $this->assertSame(1, Module::PRIMEIRA_POSICAO);
        $this->assertSame(1, Module::create(self::ID, self::CURSO, 'Primeiro', 1)->position());
    }

    #[DataProvider('posicoesInvalidas')]
    public function test_posicao_abaixo_de_um_e_recusada(int $posicao): void
    {
        $this->expectException(InvalidPosition::class);

        Module::create(self::ID, self::CURSO, 'Introducao', $posicao);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function posicoesInvalidas(): iterable
    {
        // O zero e o buraco que `UNSIGNED` deixa no banco: ele passa pela coluna
        // e so o `CHECK` o recusa. O negativo nem chega la.
        yield 'zero' => [0];
        yield 'negativa' => [-1];
        yield 'muito negativa' => [PHP_INT_MIN];
    }

    public function test_a_mensagem_da_recusa_nao_expoe_detalhe_interno(): void
    {
        try {
            Module::create(self::ID, self::CURSO, 'Introducao', 0);
            $this->fail('a posicao invalida deveria ter sido recusada');
        } catch (InvalidPosition $e) {
            foreach (['SELECT', 'modules', 'App\\', '/app/'] as $vazamento) {
                $this->assertStringNotContainsString($vazamento, $e->getMessage());
            }
        }
    }

    public function test_posicao_invalida_nao_e_falha_de_dominio_publica(): void
    {
        // A distincao esta documentada em `InvalidPosition`, e este teste a fixa:
        // o cliente nao informa posicao, entao um valor invalido so pode vir de
        // defeito de codigo ou linha adulterada. Como `DomainException`, ela
        // deixaria de ser registrada no log — e um defeito de verdade sumiria.
        $this->assertFalse(
            is_a(InvalidPosition::class, \App\Shared\Domain\Exception\DomainException::class, true),
        );
    }

    // -----------------------------------------------------------------------
    // Propriedade herdada
    // -----------------------------------------------------------------------

    public function test_o_modulo_sabe_a_que_curso_pertence(): void
    {
        $modulo = Module::create(self::ID, self::CURSO, 'Introducao', 1);

        $this->assertTrue($modulo->belongsToCourse(self::CURSO));
        $this->assertFalse($modulo->belongsToCourse('01936f1a-7c00-7000-8000-0000000000ff'));
    }

    public function test_o_modulo_nao_conhece_produtor(): void
    {
        // A propriedade de um modulo e herdada do curso (RN-PROP-004). Um campo
        // de dono aqui seria uma segunda verdade sobre a mesma coisa, livre para
        // divergir do curso no dia em que algo mudasse.
        $metodos = array_map(
            fn (\ReflectionMethod $m): string => $m->getName(),
            (new ReflectionClass(Module::class))->getMethods(),
        );

        foreach ($metodos as $metodo) {
            $this->assertStringNotContainsStringIgnoringCase('owner', $metodo);
        }
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
            (string) file_get_contents((new ReflectionClass(Module::class))->getFileName()),
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

    public function test_o_agregado_e_imutavel_e_so_nasce_pelas_duas_fabricas(): void
    {
        $classe = new ReflectionClass(Module::class);

        $this->assertTrue($classe->isFinal());
        $this->assertTrue($classe->getConstructor()?->isPrivate());

        foreach ($classe->getProperties() as $propriedade) {
            $this->assertTrue($propriedade->isReadOnly(), $propriedade->getName());
        }
    }
}
