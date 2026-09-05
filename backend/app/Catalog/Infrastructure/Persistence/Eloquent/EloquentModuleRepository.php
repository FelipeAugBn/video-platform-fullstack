<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Persistence\Eloquent;

use App\Catalog\Application\Port\ModuleRepository;
use App\Catalog\Domain\Module;
use Illuminate\Contracts\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * A porta de modulos, sobre Eloquent.
 *
 * Traduz nos dois sentidos e **nunca deixa um model atravessar**: todo metodo
 * publico devolve `Module`, uma lista de `Module` ou tipos da linguagem.
 *
 * O dono nao esta na tabela `modules`. Ele mora em `courses.owner_id`, e por isso
 * toda leitura direta de modulo alcanca o curso **dentro da propria consulta**
 * (RN-PROP-004). Carregar o modulo primeiro e comparar o dono depois seria ler
 * recurso alheio antes de recusa-lo, e transformaria qualquer contagem futura num
 * informante sobre o catalogo dos outros.
 *
 * **Sem global scope de dono**, pelo mesmo motivo registrado em
 * `EloquentCourseRepository`: o catalogo do consumidor autoriza por concessao, e
 * nao por propriedade; um escopo global obrigaria aquele caso a desliga-lo, e um
 * filtro de seguranca que precisa ser desligado deixa de ser garantia.
 */
final class EloquentModuleRepository implements ModuleRepository
{
    public function nextIdentity(): string
    {
        return (string) Str::uuid7();
    }

    public function save(Module $module): void
    {
        ModuleModel::query()->updateOrCreate(
            ['id' => $module->id()],
            [
                'course_id' => $module->courseId(),
                'title' => $module->title(),
                'position' => $module->position(),
            ],
        );
    }

    public function lockOwned(string $moduleId, string $ownerId): ?Module
    {
        $linha = $this->doDono($ownerId)
            ->where('id', $moduleId)
            // Trava a linha de `modules`, e nao a de `courses`.
            //
            // E o pai da aula que precisa ficar estavel enquanto a proxima
            // posicao e calculada (plan §8.5). Travar o curso aqui serializaria
            // criacoes de aula em modulos diferentes do mesmo curso, que nao
            // disputam sequencia nenhuma.
            //
            // O recorte por dono vem de uma subconsulta `EXISTS`, e nao de um
            // `JOIN`, exatamente por isso: no MySQL, uma leitura travada **nao**
            // propaga a trava para as linhas da subconsulta, enquanto um `JOIN`
            // travaria tambem a linha do curso.
            ->lockForUpdate()
            ->first();

        return $linha === null ? null : $this->paraDominio($linha);
    }

    public function nextPosition(string $courseId): int
    {
        // `MAX(position)` em vez de `COUNT(*)`.
        //
        // A contagem daria o mesmo numero enquanto nada fosse removido — e
        // passaria a repetir uma posicao ja usada no instante em que um modulo do
        // meio sumisse, por exclusao em cascata ou por correcao manual. O maximo
        // nao tem esse modo de falhar.
        $maior = ModuleModel::query()
            ->where('course_id', $courseId)
            ->max('position');

        return $maior === null ? Module::PRIMEIRA_POSICAO : ((int) $maior) + 1;
    }

    /**
     * @return list<Module>
     */
    public function listOfOwnedCourse(string $courseId, string $ownerId): array
    {
        $linhas = $this->doDono($ownerId)
            ->where('course_id', $courseId)
            // A ordem faz parte do contrato (RF-MOD-003), e por isso ela vem da
            // consulta. Sem `ORDER BY`, o MySQL nao promete ordem nenhuma: duas
            // leituras da mesma tabela podem devolver sequencias diferentes, e
            // RN-ORD-004 exige o contrario.
            //
            // Ordenar depois, em PHP, produziria o mesmo resultado — o recorte ja
            // e o dos modulos deste curso. A escolha e de responsabilidade:
            // ordenacao pedida ao banco vale para qualquer chamador desta porta,
            // enquanto ordenar no consumidor dependeria de cada um lembrar. A
            // UNIQUE `(course_id, position)` ja e o indice que serve esta leitura,
            // entao a ordenacao nao custa uma varredura extra.
            ->orderBy('position')
            ->get();

        return array_map(
            fn (ModuleModel $linha): Module => $this->paraDominio($linha),
            array_values($linhas->all()),
        );
    }

    /**
     * O ponto unico onde o dono entra na consulta.
     *
     * Concentrar assim significa que acrescentar uma leitura sem o filtro exige
     * nao usar este ajudante — visivel em revisao, ao contrario de esquecer um
     * `where` no meio de uma cadeia.
     *
     * @return Builder<ModuleModel>
     */
    private function doDono(string $ownerId): Builder
    {
        return ModuleModel::query()->whereExists(
            fn (QueryBuilder $curso) => $curso->from('courses')
                ->whereColumn('courses.id', 'modules.course_id')
                ->where('courses.owner_id', $ownerId),
        );
    }

    private function paraDominio(ModuleModel $linha): Module
    {
        return Module::reconstitute(
            id: $linha->getAttribute('id'),
            courseId: $linha->getAttribute('course_id'),
            title: $linha->getAttribute('title'),
            position: $linha->getAttribute('position'),
        );
    }
}
