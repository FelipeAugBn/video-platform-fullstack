<?php

declare(strict_types=1);

namespace Tests\Unit\Shared;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A regra de dependencia, verificada sobre os arquivos (plan §5.1).
 *
 * Toda a arquitetura hexagonal deste projeto se apoia numa afirmacao simples:
 * `Domain` e `Application` nao conhecem o framework. Ela e facil de escrever num
 * documento e facil de quebrar sem querer — um `use Illuminate\Support\Str` num
 * caso de uso passa em qualquer teste de comportamento, porque o codigo funciona
 * perfeitamente. So para de funcionar quando alguem tenta testar a regra sem
 * subir o Laravel, e ai o custo ja foi pago.
 *
 * Este teste transforma a afirmacao em falha automatica. Ele **le os arquivos**,
 * e nao o comportamento: e a unica forma de pegar a dependencia no momento em que
 * ela e escrita.
 *
 * Comentarios sao removidos antes da verificacao, de proposito. Varios arquivos
 * dessas camadas **explicam** por que nao importam `Illuminate` — a explicacao e
 * o oposto da violacao, e um teste que a proibisse empurraria a documentacao
 * para fora do codigo.
 *
 * Sem framework aqui tambem: PHP puro, lendo o disco.
 */
final class HexagonalBoundariesTest extends TestCase
{
    /**
     * Raizes de namespace que nao podem aparecer em `Domain` nem em
     * `Application`.
     *
     * `Aws`, `GuzzleHttp` e `Psr` entraram junto com o adapter de storage: um SDK
     * vaza para dentro exatamente pelo mesmo caminho que um framework, e a porta
     * de armazenamento so vale alguma coisa enquanto nenhum tipo do SDK
     * atravessa a assinatura dela.
     */
    private const FRAMEWORK = ['Illuminate', 'Symfony', 'Laravel', 'Aws', 'GuzzleHttp', 'Psr'];

    /**
     * @return iterable<string, array{string}>
     */
    public static function arquivosDeDominioEAplicacao(): iterable
    {
        foreach (self::arquivos(['Domain', 'Application']) as $caminho) {
            yield self::relativo($caminho) => [$caminho];
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function arquivosDeDominio(): iterable
    {
        foreach (self::arquivos(['Domain']) as $caminho) {
            yield self::relativo($caminho) => [$caminho];
        }
    }

    public function test_existem_arquivos_a_verificar(): void
    {
        // O par positivo do resto do arquivo. Sem ele, um erro no caminho faria a
        // busca devolver lista vazia e todos os testes abaixo passariam sem
        // verificar coisa nenhuma.
        $this->assertGreaterThan(20, count(self::arquivos(['Domain', 'Application'])));
    }

    #[DataProvider('arquivosDeDominioEAplicacao')]
    public function test_nao_importa_framework(string $caminho): void
    {
        // As violacoes sao acumuladas e afirmadas de uma vez, em vez de uma
        // afirmacao por importacao. Um arquivo sem nenhuma importacao nao
        // executaria afirmacao alguma, e o PHPUnit o marcaria como duvidoso —
        // um aviso que nao diz nada sobre a arquitetura e esconde os que dizem.
        $violacoes = array_values(array_filter(
            self::importacoes($caminho),
            fn (string $importado): bool => in_array(explode('\\', $importado)[0], self::FRAMEWORK, true),
        ));

        $this->assertSame([], $violacoes, self::relativo($caminho).' importa framework');
    }

    #[DataProvider('arquivosDeDominioEAplicacao')]
    public function test_nao_menciona_framework_no_codigo(string $caminho): void
    {
        // A verificacao complementar da anterior: um nome totalmente qualificado
        // escrito no meio do codigo — `\Illuminate\Support\Facades\DB::table(...)`
        // — nao aparece em nenhuma importacao.
        $codigo = self::semComentarios($caminho);

        foreach (self::FRAMEWORK as $proibido) {
            $this->assertStringNotContainsString(
                $proibido.'\\',
                $codigo,
                self::relativo($caminho),
            );
        }

        $this->assertStringNotContainsString('DB::', $codigo, self::relativo($caminho));
    }

    #[DataProvider('arquivosDeDominio')]
    public function test_dominio_nao_conhece_as_camadas_de_fora(string $caminho): void
    {
        // A regra de dependencia aponta para dentro. `Application` pode falar de
        // `Domain`; o contrario, nunca — e nem `Domain` nem `Application` podem
        // conhecer `Infrastructure` ou `Interfaces`.
        $this->assertSame(
            [],
            self::importacoesDeCamadas($caminho, ['Application', 'Infrastructure', 'Interfaces']),
            self::relativo($caminho).' importa camada de fora',
        );
    }

    #[DataProvider('arquivosDeDominioEAplicacao')]
    public function test_nao_conhece_infraestrutura_nem_interface(string $caminho): void
    {
        $this->assertSame(
            [],
            self::importacoesDeCamadas($caminho, ['Infrastructure', 'Interfaces']),
            self::relativo($caminho).' importa camada de fora',
        );
    }

    // -----------------------------------------------------------------------
    // Apoio
    // -----------------------------------------------------------------------

    /**
     * @param  list<string>  $camadas
     * @return list<string>
     */
    private static function arquivos(array $camadas): array
    {
        $encontrados = [];

        foreach (glob(self::raiz().'/*', GLOB_ONLYDIR) ?: [] as $area) {
            foreach ($camadas as $camada) {
                $pasta = $area.'/'.$camada;

                if (! is_dir($pasta)) {
                    continue;
                }

                $iterador = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($pasta));

                foreach ($iterador as $arquivo) {
                    if ($arquivo instanceof \SplFileInfo && $arquivo->getExtension() === 'php') {
                        $encontrados[] = $arquivo->getPathname();
                    }
                }
            }
        }

        sort($encontrados);

        return $encontrados;
    }

    /**
     * As classes efetivamente importadas pelo arquivo.
     *
     * @return list<string>
     */
    private static function importacoes(string $caminho): array
    {
        preg_match_all(
            '/^use\s+(?:function\s+|const\s+)?([A-Za-z0-9_\\\\]+)/m',
            self::semComentarios($caminho),
            $encontrados,
        );

        return $encontrados[1];
    }

    /**
     * As importacoes que apontam para uma das camadas informadas.
     *
     * @param  list<string>  $camadas
     * @return list<string>
     */
    private static function importacoesDeCamadas(string $caminho, array $camadas): array
    {
        return array_values(array_filter(
            self::importacoes($caminho),
            function (string $importado) use ($camadas): bool {
                foreach ($camadas as $camada) {
                    if (str_contains($importado, '\\'.$camada.'\\')) {
                        return true;
                    }
                }

                return false;
            },
        ));
    }

    /**
     * O arquivo sem comentarios.
     *
     * Usa o proprio analisador da linguagem, e nao uma expressao regular: uma
     * regex nao distingue um `//` dentro de uma string de um comentario de
     * verdade, e o falso positivo apareceria justamente num arquivo que menciona
     * uma URL.
     */
    private static function semComentarios(string $caminho): string
    {
        $codigo = '';

        foreach (token_get_all((string) file_get_contents($caminho)) as $token) {
            if (is_array($token)) {
                if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }

                $codigo .= $token[1];

                continue;
            }

            $codigo .= $token;
        }

        return $codigo;
    }

    private static function raiz(): string
    {
        return dirname(__DIR__, 3).'/app';
    }

    private static function relativo(string $caminho): string
    {
        return str_replace(dirname(self::raiz()).'/', '', $caminho);
    }
}
