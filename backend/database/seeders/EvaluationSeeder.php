<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Cenario de avaliacao: as contas, os cursos e a tentativa de video que a
 * demonstracao e os testes de jornada precisam encontrar prontos.
 *
 * Tres propriedades governam este arquivo.
 *
 * **Escreve pelo query builder, direto nas tabelas.** Nesta altura da ordem de
 * implementacao nao existem repositorios de `Course`, `Module`, `Lesson` ou
 * `VideoAttempt`, e antecipa-los aqui inverteria a dependencia: o seed passaria
 * a exigir codigo que as tarefas seguintes ainda vao escrever. Nenhum model,
 * factory ou caso de uso e usado.
 *
 * **Insere o que falta e nao toca no que existe.** O `setup` roda este seeder a
 * cada subida do ambiente (plan §16.2). Um seeder que sobrescrevesse apagaria,
 * a cada `make up`, o trabalho de quem estava usando a plataforma. A consequencia
 * assumida e que ele **prepara, mas nao repara**: se a tentativa do cenario de
 * falha ja tiver sido consumida por uma demonstracao, ela permanece como ficou —
 * restaurar e trabalho de `migrate:fresh --seed`, e o roteiro de demonstracao diz
 * isso.
 *
 * **Identificadores fixos.** Todos os registros preparados tem UUIDv7 literal,
 * nao gerado em execucao. E o que torna a segunda execucao inofensiva e o que
 * permite ao comando de falha de T067 e ao roteiro de T105 citarem um
 * identificador que existe de verdade. Sao valores ficticios: nao ha dado
 * pessoal nem segredo real neste arquivo — a senha e local, ficticia e igual
 * para as tres contas de demonstracao, e chega ao banco como hash.
 */
final class EvaluationSeeder extends Seeder
{
    /**
     * Senha das tres contas de demonstracao. Ficticia, local e documentada em
     * `docs/demonstracao.md` — quem avalia precisa conseguir entrar.
     */
    private const SENHA = 'VideoDemo2026!';

    private const PRODUTOR = '01a06e16-10ac-721b-9e80-fb8e67bb563f';

    private const CONSUMIDOR = '01a06e16-10ae-73fc-a2d5-3e29c3e6a61f';

    private const SEGUNDO_PRODUTOR = '01a06e16-10ae-73fc-a2d5-3e29c4d7b8c2';

    private const CURSO_PRINCIPAL = '01a06e16-10ae-73fc-a2d5-3e29c4f41112';

    private const CONCESSAO = '01a06e16-10ae-73fc-a2d5-3e29c55e8998';

    private const CURSO_ALHEIO = '01a06e16-10ae-73fc-a2d5-3e29c60ffc47';

    private const CURSO_FALHA = '01a06e16-10ae-73fc-a2d5-3e29c70f40d5';

    private const MODULO_FALHA = '01a06e16-10ae-73fc-a2d5-3e29c7dce499';

    private const AULA_FALHA = '01a06e16-10ae-73fc-a2d5-3e29c822bc32';

    /**
     * Tentativa dedicada a demonstracao de falha.
     *
     * Este valor e citado **literalmente** pelo comando de T067 e pelo roteiro
     * de T105. Gera-lo a cada execucao tornaria a demonstracao irreproduzivel:
     * o roteiro nao teria o que escrever.
     */
    public const TENTATIVA_FALHA = '01936f1a-7c00-7a3e-9b7d-2f5c8e4a1d60';

    public function run(): void
    {
        DB::transaction(function (): void {
            $agora = now();
            $senha = Hash::make(self::SENHA);

            // ---------------------------------------------------------------
            // Contas.
            // ---------------------------------------------------------------
            $this->garantir('users', self::PRODUTOR, [
                'name' => 'Produtora de Demonstracao',
                'email' => 'producer@video-platform.test',
                'password' => $senha,
                'role' => 'producer',
                'created_at' => $agora,
                'updated_at' => $agora,
            ]);

            $this->garantir('users', self::CONSUMIDOR, [
                'name' => 'Consumidor de Demonstracao',
                'email' => 'consumer@video-platform.test',
                'password' => $senha,
                'role' => 'consumer',
                'created_at' => $agora,
                'updated_at' => $agora,
            ]);

            $this->garantir('users', self::SEGUNDO_PRODUTOR, [
                'name' => 'Outro Produtor',
                'email' => 'other-producer@video-platform.test',
                'password' => $senha,
                'role' => 'producer',
                'created_at' => $agora,
                'updated_at' => $agora,
            ]);

            // ---------------------------------------------------------------
            // Cenario principal: o curso que a jornada integrada preenche.
            //
            // Ele nasce e permanece VAZIO — sem modulo e sem aula. E esse vazio
            // que AC-E2E-001 preenche pela interface. A jornada parte de um
            // curso ja concedido porque nao existe operacao para conceder
            // acesso a um curso recem-criado (spec §4).
            // ---------------------------------------------------------------
            $this->garantir('courses', self::CURSO_PRINCIPAL, [
                'owner_id' => self::PRODUTOR,
                'title' => 'Fundamentos de Producao de Video',
                'description' => 'Curso preparado para a jornada de demonstracao. '
                    .'Comeca vazio: modulo, aula e video sao criados pela interface durante a avaliacao.',
                'state' => 'draft',
                'created_at' => $agora,
                'updated_at' => $agora,
            ]);

            $this->garantir('course_access_grants', self::CONCESSAO, [
                'course_id' => self::CURSO_PRINCIPAL,
                'consumer_id' => self::CONSUMIDOR,
                'granted_at' => $agora,
            ]);

            // ---------------------------------------------------------------
            // Cenario de isolamento: curso de outro produtor, sem concessao.
            //
            // Existe para que a prova de isolamento tenha alvo real. Sem um
            // segundo dono, "nao acessa curso alheio" nao teria como ser
            // demonstrado na interface (RN-PROP-003, AC-PROD-002).
            // ---------------------------------------------------------------
            $this->garantir('courses', self::CURSO_ALHEIO, [
                'owner_id' => self::SEGUNDO_PRODUTOR,
                'title' => 'Curso de Outro Produtor',
                'description' => 'Pertence a outro produtor e nao esta concedido ao consumidor de '
                    .'demonstracao. Serve para comprovar o isolamento entre contas.',
                'state' => 'draft',
                'created_at' => $agora,
                'updated_at' => $agora,
            ]);

            // ---------------------------------------------------------------
            // Cenario dedicado de falha.
            //
            // Curso, modulo e aula SEPARADOS do cenario principal, de proposito:
            // acionar a falha nao pode sujar o curso que a jornada usa. A
            // tentativa fica em `processing`, que e o unico estado a partir do
            // qual um callback de falha e uma transicao valida (T017).
            //
            // Nenhum objeto existe no storage para esta tentativa, e nao precisa
            // existir: o callback de falha nao le o arquivo.
            // ---------------------------------------------------------------
            $this->garantir('courses', self::CURSO_FALHA, [
                'owner_id' => self::PRODUTOR,
                'title' => 'Cenario de Falha de Processamento',
                'description' => 'Curso separado, dedicado a demonstrar o desfecho de falha do '
                    .'processamento. Nao e o curso da jornada principal.',
                'state' => 'draft',
                'created_at' => $agora,
                'updated_at' => $agora,
            ]);

            $this->garantir('modules', self::MODULO_FALHA, [
                'course_id' => self::CURSO_FALHA,
                'title' => 'Modulo de Demonstracao',
                'position' => 1,
                'created_at' => $agora,
                'updated_at' => $agora,
            ]);

            $this->garantir('lessons', self::AULA_FALHA, [
                'module_id' => self::MODULO_FALHA,
                'title' => 'Aula com Video em Processamento',
                'position' => 1,
                'current_video_attempt_id' => null,
                'published_at' => null,
                'created_at' => $agora,
                'updated_at' => $agora,
            ]);

            $this->garantir('video_attempts', self::TENTATIVA_FALHA, [
                'lesson_id' => self::AULA_FALHA,
                'state' => 'processing',
                'declared_filename' => 'aula-demonstracao.mp4',
                'declared_content_type' => 'video/mp4',
                'declared_size' => 15_728_640,
                'storage_key' => 'videos/'.self::TENTATIVA_FALHA.'/original.mp4',
                'multipart_upload_id' => null,
                'verified_size' => 15_728_640,
                'verified_content_type' => 'video/mp4',
                'playback_reference' => null,
                'failure_code' => null,
                'failure_message' => null,
                'created_at' => $agora,
                'updated_at' => $agora,
            ]);

            // O ponteiro e gravado depois porque a tentativa precisa existir
            // antes de a chave estrangeira aceita-lo. Atualiza apenas enquanto
            // a aula ainda nao aponta para nada, preservando a regra de nao
            // sobrescrever estado ja existente.
            DB::table('lessons')
                ->where('id', self::AULA_FALHA)
                ->whereNull('current_video_attempt_id')
                ->update(['current_video_attempt_id' => self::TENTATIVA_FALHA]);
        });
    }

    /**
     * Insere a linha somente quando ela ainda nao existe.
     *
     * A verificacao e por chave primaria, que e fixa neste seeder. Uma segunda
     * execucao encontra tudo no lugar e nao escreve nada — sem duplicar, sem
     * alterar identificador e sem reescrever estado que a demonstracao possa ter
     * mudado de proposito.
     *
     * @param  array<string, mixed>  $dados
     */
    private function garantir(string $tabela, string $id, array $dados): void
    {
        if (DB::table($tabela)->where('id', $id)->exists()) {
            return;
        }

        DB::table($tabela)->insert(['id' => $id] + $dados);
    }
}
