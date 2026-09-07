<?php

declare(strict_types=1);

namespace Tests\Feature\Shared;

use Illuminate\Routing\Route as RotaRegistrada;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * O inventario do que esta aplicacao expoe por HTTP — e o que ela nao expoe.
 *
 * ## Por que uma lista fechada
 *
 * Os demais testes de rota provam que **cada** endpoint se comporta como deve.
 * Nenhum deles percebe um endpoint **a mais**: uma rota que nasca por descuido —
 * copiada de outra tarefa, herdada de um pacote ou registrada por um padrao do
 * framework — passa por toda a suite sem ser notada, justamente porque ninguem
 * escreveu um teste para ela. Uma rota assim fica fora do contrato publicado,
 * fora da revisao de autorizacao e fora da documentacao.
 *
 * Aqui a afirmacao e a igualdade: a superficie registrada e **exatamente** a
 * lista abaixo. Endpoint novo quebra este teste, e a correcao e deliberada —
 * declara-lo aqui e no `docs/openapi.yaml`, ou nao registra-lo.
 *
 * ## O que esta classe prova, e o que ela nao prova
 *
 * **Prova, automaticamente:** que as rotas registradas sao **exatamente** a lista
 * `SUPERFICIE` declarada abaixo. E uma igualdade contra uma allowlist fechada,
 * escrita a mao neste arquivo.
 *
 * **Nao prova:** que essa lista corresponde ao `docs/openapi.yaml`. Nada aqui le
 * o contrato. A correspondencia entre as 22 operacoes documentadas e as 22
 * operacoes desta lista foi conferida **manualmente**, na revisao final, e e
 * reconferida sempre que uma das duas mudar.
 *
 * Essa separacao e deliberada. Um segundo mecanismo lendo o YAML seria uma
 * segunda validacao do contrato, com politica propria de sincronizacao, para
 * afirmar o que a revisao ja afirma — o plano descarta essa duplicacao em §10.5.
 * Quem valida o documento em si e `make openapi-lint`, que roda separado, na
 * pipeline e localmente.
 *
 * A vigesima terceira operacao e `GET /up`, fora do contrato de proposito: e o
 * sinal de prontidao consultado pelo healthcheck do servico `web`, e nao uma
 * operacao de negocio (plan §§16.2 e 18.2).
 *
 * ## `HEAD` nao aparece na lista
 *
 * O roteador aceita `HEAD` em toda rota `GET`, e o aceita **de fato** — a
 * aplicacao responde a ele. Ele fica fora de `SUPERFICIE` porque e derivado, e
 * nao declarado: lista-lo dobraria a tabela sem acrescentar uma decisao sequer.
 * `test_o_head_acompanha_cada_get` afirma essa derivacao, para que a exclusao
 * nao seja confundida com ausencia.
 *
 * ## O filesystem local nao e servido por HTTP
 *
 * O esqueleto do framework traz `serve => true` no disco local, e isso registra
 * sozinho `GET /storage/{path}` e `PUT /storage/{path}`. Esta aplicacao nao
 * guarda video no disco local: todo objeto vive no armazenamento compativel com
 * S3 e e alcancado por URL pre-assinada, pela porta `ObjectStorage` (plan §§11,
 * 12 e 14.2). As duas rotas nao teriam caso de uso — e superficie sem caso de
 * uso e superficie que ninguem revisa.
 *
 * `config/filesystems.php` desliga `serve`, e os testes abaixo provam a ausencia
 * pelos dois lados: o registro de rotas nao as tem, e o endereco nao responde.
 * Verificado por regressao: com `serve => true`, varios testes desta classe
 * reprovam, e a diferenca relatada e exatamente `GET /storage/{path}` e
 * `PUT /storage/{path}`.
 */
final class HttpSurfaceTest extends TestCase
{
    /**
     * Toda a superficie HTTP da aplicacao, uma entrada por operacao.
     *
     * `HEAD` fica fora porque o roteador o deriva de cada `GET`, e nao porque
     * seja recusado: listar os dois dobraria a tabela sem acrescentar uma decisao
     * sequer. A aplicacao responde a `HEAD` normalmente.
     *
     * @var list<string>
     */
    private const SUPERFICIE = [
        // Sessao: quatro operacoes, incluindo o cookie de protecao.
        'GET /sanctum/csrf-cookie',
        'POST /api/auth/login',
        'GET /api/auth/me',
        'POST /api/auth/logout',

        // Catalogo do produtor.
        'GET /api/courses',
        'POST /api/courses',
        'GET /api/courses/{course}',
        'GET /api/courses/{course}/structure',
        'GET /api/courses/{course}/modules',
        'POST /api/courses/{course}/modules',
        'POST /api/modules/{module}/lessons',
        'GET /api/lessons/{lesson}',
        'POST /api/lessons/{lesson}/publish',

        // Video do produtor: o envio, a consulta de estado e a conferencia do
        // que ficou pronto.
        'POST /api/lessons/{lesson}/video/uploads',
        'POST /api/video-uploads/{attempt}/parts/{part}/url',
        'POST /api/video-uploads/{attempt}/complete',
        'GET /api/lessons/{lesson}/video',
        'GET /api/lessons/{lesson}/video/playback',

        // Consumo.
        'GET /api/catalog/courses',
        'GET /api/catalog/courses/{course}',
        'GET /api/lessons/{lesson}/playback',

        // Callback do processamento, sem sessao e isento de CSRF (plan §13.4).
        'POST /api/webhooks/video-processing',

        // Prontidao. Unica operacao fora do contrato OpenAPI.
        'GET /up',
    ];

    // -----------------------------------------------------------------------
    // A superficie inteira
    // -----------------------------------------------------------------------

    public function test_a_superficie_registrada_e_exatamente_a_declarada(): void
    {
        $registradas = $this->operacoesRegistradas();
        $declaradas = self::SUPERFICIE;

        sort($registradas);
        sort($declaradas);

        // `assertSame` sobre as duas listas ordenadas: a mensagem de falha mostra
        // de um lado o que sobrou e do outro o que faltou, que e a informacao de
        // que alguem precisa ao ver este teste vermelho.
        $this->assertSame($declaradas, $registradas);
    }

    /**
     * A prontidao e a **unica** operacao fora do prefixo da API e da sessao.
     *
     * Este teste nao consulta o `docs/openapi.yaml` — nada nesta classe consulta.
     * Ele afirma a forma da superficie: 23 operacoes, das quais exatamente uma
     * fica fora de `/api` e de `/sanctum`, e essa uma e `GET /up`.
     *
     * As 22 restantes correspondem, uma a uma, as operacoes do contrato. Essa
     * correspondencia foi conferida **a mao**; nao ha mecanismo automatico
     * ligando os dois lados, por decisao registrada em plan §10.5.
     */
    public function test_a_prontidao_e_a_unica_operacao_fora_da_api_e_da_sessao(): void
    {
        $registradas = $this->operacoesRegistradas();

        $foraDaApi = array_values(array_filter(
            $registradas,
            static fn (string $operacao): bool => ! str_contains($operacao, ' /api/')
                && ! str_contains($operacao, ' /sanctum/'),
        ));

        $this->assertSame(['GET /up'], $foraDaApi);
        $this->assertCount(23, $registradas);
    }

    /**
     * Nenhum verbo de escrita parcial ou de remocao chega a ser declarado.
     *
     * Atualizacao e exclusao estao fora do escopo desta entrega (RF-CUR-005,
     * RF-AUL-007), e o `PUT` que existia era o do filesystem local. Sem ele, o
     * roteador declara dois verbos e nada mais.
     *
     * **`HEAD` nao entra nesta contagem, e nao por ser recusado.** Ele e
     * derivado de cada `GET` pelo proprio roteador, e a aplicacao responde a ele
     * normalmente — `test_o_head_acompanha_cada_get` prova isso. O que este
     * teste afirma e o conjunto **declarado**, que e onde uma decisao de
     * contrato aparece.
     */
    public function test_os_verbos_declarados_sao_apenas_get_e_post(): void
    {
        $verbos = array_values(array_unique(array_map(
            static fn (string $operacao): string => strtok($operacao, ' '),
            $this->operacoesRegistradas(),
        )));

        sort($verbos);

        $this->assertSame(['GET', 'POST'], $verbos);
    }

    /**
     * O `HEAD` derivado existe, e a suite diz isso em voz alta.
     *
     * Sem este teste, a exclusao de `HEAD` do inventario poderia ser lida como
     * "a aplicacao nao aceita `HEAD`" — o que seria falso, e faria a proxima
     * pessoa procurar um bloqueio que nao existe.
     *
     * A contagem entra junto porque e ela que fecha a aritmetica da superficie:
     * 13 `GET` e 10 `POST` declarados, mais os 13 `HEAD` que o roteador deriva,
     * dao os 36 pares metodo-endereco que a aplicacao aceita.
     */
    public function test_o_head_acompanha_cada_get(): void
    {
        $semHead = [];
        $gets = 0;
        $heads = 0;

        foreach (Route::getRoutes()->getRoutes() as $rota) {
            /** @var RotaRegistrada $rota */
            $metodos = $rota->methods();

            if (in_array('GET', $metodos, true)) {
                $gets++;
            }

            if (in_array('HEAD', $metodos, true)) {
                $heads++;
            }

            if (in_array('GET', $metodos, true) && ! in_array('HEAD', $metodos, true)) {
                $semHead[] = (string) $rota->uri();
            }
        }

        $this->assertSame([], $semHead);
        $this->assertSame(13, $gets);
        $this->assertSame($gets, $heads);
    }

    // -----------------------------------------------------------------------
    // O filesystem local nao aparece
    // -----------------------------------------------------------------------

    public function test_nao_existe_rota_servindo_o_filesystem_local(): void
    {
        $doFilesystem = array_filter(
            $this->urisRegistradas(),
            static fn (string $uri): bool => str_starts_with($uri, 'storage/'),
        );

        $this->assertSame([], array_values($doFilesystem));
    }

    /**
     * O nome com que o framework registra cada uma das duas.
     *
     * Verificar pelo nome, e nao so pela URI, prende o teste ao mecanismo:
     * mudar o prefixo do disco no `config/filesystems.php` mudaria a URI, mas
     * `storage.local` e `storage.local.upload` continuariam sendo os nomes.
     *
     * @return iterable<string, array{string}>
     */
    public static function nomesDoFilesystemLocal(): iterable
    {
        yield 'leitura' => ['storage.local'];
        yield 'escrita' => ['storage.local.upload'];
    }

    #[DataProvider('nomesDoFilesystemLocal')]
    public function test_o_framework_nao_registra_as_rotas_do_disco_local(string $nome): void
    {
        $this->assertNull(Route::getRoutes()->getByName($nome));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function metodosDoFilesystemLocal(): iterable
    {
        yield 'get' => ['get'];
        yield 'put' => ['put'];
    }

    #[DataProvider('metodosDoFilesystemLocal')]
    public function test_o_endereco_do_disco_local_nao_responde(string $metodo): void
    {
        // O caminho e o que o framework serviria: um arquivo qualquer sob a raiz
        // do disco. A resposta precisa ser a de rota inexistente — e nao a de
        // rota existente que recusou a requisicao.
        $resposta = $this->{$metodo}('/storage/arquivo-que-nunca-existiu.txt');

        $resposta->assertNotFound();
    }

    // -----------------------------------------------------------------------
    // O que precisa continuar existindo
    // -----------------------------------------------------------------------

    /**
     * @return iterable<string, array{string}>
     */
    public static function pontosDeEntrada(): iterable
    {
        yield 'prontidao' => ['GET /up'];
        yield 'cookie de protecao' => ['GET /sanctum/csrf-cookie'];
        yield 'login' => ['POST /api/auth/login'];
        yield 'catalogo do consumidor' => ['GET /api/catalog/courses'];
        yield 'reproducao' => ['GET /api/lessons/{lesson}/playback'];
        yield 'conferencia do produtor' => ['GET /api/lessons/{lesson}/video/playback'];
        yield 'callback' => ['POST /api/webhooks/video-processing'];
    }

    /**
     * A contraparte das negativas acima.
     *
     * Desligar `serve` nao pode ter levado nada junto: um teste que so afirma
     * ausencias passaria igual numa aplicacao sem rota nenhuma.
     */
    #[DataProvider('pontosDeEntrada')]
    public function test_os_pontos_de_entrada_oficiais_continuam_registrados(string $operacao): void
    {
        $this->assertContains($operacao, $this->operacoesRegistradas());
    }

    // -----------------------------------------------------------------------

    /**
     * @return list<string>
     */
    private function operacoesRegistradas(): array
    {
        $operacoes = [];

        foreach (Route::getRoutes()->getRoutes() as $rota) {
            /** @var RotaRegistrada $rota */
            foreach ($rota->methods() as $metodo) {
                // Derivado de `GET` pelo roteador, e nao declarado por ninguem.
                // Fica fora do inventario de decisoes; `test_o_head_acompanha_cada_get`
                // afirma que ele continua sendo aceito.
                if ($metodo === 'HEAD') {
                    continue;
                }

                $operacoes[] = $metodo.' /'.ltrim((string) $rota->uri(), '/');
            }
        }

        return array_values(array_unique($operacoes));
    }

    /**
     * @return list<string>
     */
    private function urisRegistradas(): array
    {
        return array_values(array_map(
            static fn (RotaRegistrada $rota): string => (string) $rota->uri(),
            Route::getRoutes()->getRoutes(),
        ));
    }
}
