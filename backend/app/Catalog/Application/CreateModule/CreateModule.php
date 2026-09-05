<?php

declare(strict_types=1);

namespace App\Catalog\Application\CreateModule;

use App\Catalog\Application\Port\ModuleRepository;
use App\Catalog\Application\Service\ResolveOwnedCourse;
use App\Catalog\Domain\Module;
use App\Shared\Application\Port\TransactionManager;

/**
 * Cria um modulo no fim de um curso proprio.
 *
 * As quatro etapas formam **uma operacao so**, e e este caso de uso que declara
 * isso (plan §7.4). Nao ha estado intermediario valido: um curso travado sem
 * modulo gravado nao e um resultado, e uma posicao calculada e nao usada e um
 * numero que outra requisicao ja pode ter consumido.
 *
 *   1. trava a linha do curso, ja filtrada pelo dono;
 *   2. le a maior posicao existente entre os modulos daquele curso;
 *   3. cria o modulo na posicao seguinte;
 *   4. grava.
 *
 * **A trava e o que serializa duas criacoes simultaneas no mesmo curso.** Sem
 * ela, as duas leriam o mesmo maximo e tentariam gravar a mesma posicao. A
 * UNIQUE `(course_id, position)` recusaria a segunda — a trava transforma essa
 * colisao em espera, e a segunda requisicao le o maximo ja atualizado
 * (plan §8.1).
 *
 * **Nenhum modulo existente e tocado.** A posicao nova e sempre a proxima livre,
 * nunca uma insercao no meio: nao ha reescrita em massa das posicoes seguintes,
 * e por isso nao ha como um modulo ja criado mudar de lugar (RN-ORD-003).
 *
 * A transacao entra pela porta `TransactionManager`, e nao por `DB::transaction`:
 * o limite e decisao deste arquivo, mas a execucao pertence ao adapter — e assim
 * `Application` continua sem importar Laravel (plan §§5.1, 7.4).
 */
final class CreateModule
{
    public function __construct(
        private readonly TransactionManager $transactions,
        private readonly ResolveOwnedCourse $resolverCurso,
        private readonly ModuleRepository $modules,
    ) {}

    public function __invoke(CreateModuleCommand $comando): Module
    {
        return $this->transactions->transactional(function () use ($comando): Module {
            // Curso alheio e curso inexistente saem daqui como a mesma recusa. A
            // trava so acontece depois de a propriedade ser confirmada: a
            // consulta leva identificador e dono juntos, entao nunca se trava a
            // linha de um curso de outro produtor.
            $curso = $this->resolverCurso->locked($comando->courseId, $comando->ownerId);

            $modulo = Module::create(
                id: $this->modules->nextIdentity(),
                courseId: $curso->id(),
                title: $comando->title,
                // Lida **dentro** da transacao e depois da trava. Fora dessa
                // ordem, o numero seria apenas uma leitura otimista.
                position: $this->modules->nextPosition($curso->id()),
            );

            $this->modules->save($modulo);

            return $modulo;
        });
    }
}
