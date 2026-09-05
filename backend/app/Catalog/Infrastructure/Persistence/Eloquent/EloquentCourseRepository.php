<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Persistence\Eloquent;

use App\Catalog\Application\Port\CourseRepository;
use App\Catalog\Domain\Course;
use App\Shared\Application\Page;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * A porta de cursos, sobre Eloquent.
 *
 * Este e o unico arquivo do catalogo que conhece tabela, coluna e SQL. Ele traduz
 * nos dois sentidos — agregado para linha, linha para agregado — e **nunca deixa
 * um model atravessar**: todo metodo publico devolve `Course`, `Page` ou `null`.
 *
 * O filtro por dono aparece em toda leitura, e sempre dentro da consulta. Nao ha
 * caminho aqui que carregue registros para filtrar depois em PHP: alem de
 * desperdicio, isso faria `total` e numero de paginas contarem curso alheio, e a
 * contagem sozinha ja revelaria quantos cursos os outros produtores tem
 * (RN-PROP-002, RN-PROP-005).
 *
 * **Sem global scope de dono**, embora fosse mais curto. O mesmo armazenamento
 * sera lido pelo catalogo do consumidor, cuja autorizacao e por concessao e nao
 * por propriedade; um escopo global obrigaria aquele caso a desliga-lo, e um
 * filtro de seguranca que precisa ser desligado deixa de ser garantia e vira
 * lembrete.
 */
final class EloquentCourseRepository implements CourseRepository
{
    /**
     * UUID versao 7, pelo proprio framework.
     *
     * A versao 7 foi escolhida pela localidade de insercao: o componente temporal
     * no prefixo evita que a chave primaria caia no meio do indice agrupado do
     * InnoDB a cada insercao, como aconteceria com um identificador inteiramente
     * aleatorio (plan §7.1).
     *
     * **Nao ha garantia de ordem estrita entre identificadores gerados no mesmo
     * instante** — isso depende de como o gerador preenche os bits aleatorios, e
     * a especificacao nao promete. Este projeto nao depende disso: a ordem de
     * exibicao vem de `created_at`, nunca do identificador.
     */
    public function nextIdentity(): string
    {
        return (string) Str::uuid7();
    }

    public function save(Course $course): void
    {
        CourseModel::query()->updateOrCreate(
            ['id' => $course->id()],
            [
                'owner_id' => $course->ownerId(),
                'title' => $course->title(),
                'description' => $course->description(),
                'state' => $course->state()->value,
                // A data vem do relogio da aplicacao, e nao do banco nem do
                // carimbo automatico do framework. Informando o valor, ele passa
                // a estar "sujo" e o framework nao o sobrescreve — que e o que
                // permite ao teste fixar o instante e afirmar igualdade.
                'created_at' => $course->createdAt(),
            ],
        );
    }

    public function findOwned(string $courseId, string $ownerId): ?Course
    {
        $linha = $this->doDono($ownerId)
            ->where('id', $courseId)
            ->first();

        return $linha === null ? null : $this->paraDominio($linha);
    }

    public function pageOfOwner(string $ownerId, int $page, int $perPage): Page
    {
        // A ordem funcional e a de criacao, e quem a define e `created_at`.
        //
        // A coluna tem precisao de segundo, entao dois cursos criados no mesmo
        // segundo empatam — e um empate sem criterio de desempate faz o banco
        // devolver ordens diferentes para a mesma consulta, o que colocaria um
        // registro em duas paginas ou em nenhuma.
        //
        // O `id` entra como segundo criterio apenas para tornar o resultado
        // **deterministico**. Ele nao carrega significado: entre dois cursos com
        // o mesmo `created_at`, o identificador maior nao e necessariamente o
        // mais recente (plan §7.1). A ordem entre eles e estavel, e so.
        $pagina = $this->doDono($ownerId)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(perPage: $perPage, page: $page);

        return Page::of(
            items: array_map(
                fn (CourseModel $linha): Course => $this->paraDominio($linha),
                array_values($pagina->items()),
            ),
            currentPage: $pagina->currentPage(),
            perPage: $pagina->perPage(),
            total: $pagina->total(),
        );
    }

    public function lockOwned(string $courseId, string $ownerId): ?Course
    {
        $linha = $this->doDono($ownerId)
            ->where('id', $courseId)
            ->lockForUpdate()
            ->first();

        return $linha === null ? null : $this->paraDominio($linha);
    }

    /**
     * O ponto unico onde o dono entra na consulta.
     *
     * Toda leitura publica desta classe comeca por aqui. Concentrar assim
     * significa que acrescentar um metodo sem o filtro exige nao usar este
     * ajudante — o que e visivel em revisao, ao contrario de esquecer um `where`
     * no meio de uma cadeia.
     *
     * @return Builder<CourseModel>
     */
    private function doDono(string $ownerId): Builder
    {
        return CourseModel::query()->where('owner_id', $ownerId);
    }

    private function paraDominio(CourseModel $linha): Course
    {
        return Course::reconstitute(
            id: $linha->getAttribute('id'),
            ownerId: $linha->getAttribute('owner_id'),
            title: $linha->getAttribute('title'),
            description: $linha->getAttribute('description'),
            state: $linha->getAttribute('state'),
            // Convertido para o tipo nativo: o dominio declara
            // `DateTimeImmutable`, e entregar o tipo de data do framework faria a
            // API dele depender de uma biblioteca que ele nao deveria conhecer.
            createdAt: DateTimeImmutable::createFromInterface($linha->getAttribute('created_at')),
        );
    }
}
