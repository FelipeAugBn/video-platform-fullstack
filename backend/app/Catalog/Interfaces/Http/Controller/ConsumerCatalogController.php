<?php

declare(strict_types=1);

namespace App\Catalog\Interfaces\Http\Controller;

use App\Catalog\Application\GetPublishedStructure\GetPublishedStructure;
use App\Catalog\Application\GetPublishedStructure\GetPublishedStructureQuery;
use App\Catalog\Application\ListGrantedCourses\ListGrantedCourses;
use App\Catalog\Application\ListGrantedCourses\ListGrantedCoursesQuery;
use App\Catalog\Interfaces\Http\Request\ListCoursesRequest;
use App\Catalog\Interfaces\Http\Resource\ConsumerStructureResource;
use App\Catalog\Interfaces\Http\Resource\CourseResource;
use App\Shared\Interfaces\Http\Controller\IdentifiesTheConsumer;
use App\Shared\Interfaces\Http\Resource\PaginatedResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A fronteira HTTP do catalogo do consumidor.
 *
 * Um controller proprio, e nao acoes a mais em `CourseController`. As duas
 * superficies leem os mesmos cursos por regras de acesso diferentes —
 * propriedade de um lado, concessao do outro —, e junta-las obrigaria cada acao
 * a escolher qual regra aplicar a partir do perfil de quem chamou. Uma escolha
 * dessas dentro do controller e onde uma rota de consumo acabaria resolvida pela
 * regra do produtor.
 *
 * Cada acao le a entrada validada, descobre quem esta autenticado, chama o caso
 * de uso e devolve o recurso. Consulta ao banco, decisao sobre concessao e
 * calculo de paginacao ja tem lugar, e nenhum deles e aqui.
 */
final class ConsumerCatalogController
{
    use IdentifiesTheConsumer;

    public function __construct(
        private readonly ListGrantedCourses $listarConcedidos,
        private readonly GetPublishedStructure $obterEstrutura,
    ) {}

    /**
     * A entrada e a mesma da listagem do produtor — `page` e `per_page`, com o
     * mesmo padrao e o mesmo teto (plan §10.1). Um segundo Form Request com as
     * mesmas duas regras seria uma copia livre para divergir, e paginacao que
     * muda de contrato conforme o perfil e paginacao que o cliente precisa
     * aprender duas vezes.
     */
    public function index(ListCoursesRequest $request): PaginatedResponse
    {
        $pagina = ($this->listarConcedidos)(new ListGrantedCoursesQuery(
            consumerId: $this->consumidorAutenticado($request),
            page: $request->pagina(),
            perPage: $request->porPagina(),
        ));

        return PaginatedResponse::ofPage($pagina, CourseResource::class);
    }

    /**
     * O identificador chega como texto, e nao como model.
     *
     * Sem route model binding, pela mesma razao das rotas do produtor: ele
     * carregaria o curso **antes** de saber se ha concessao, e um curso alheio ja
     * teria sido lido do banco quando alguem fosse recusar o acesso. A
     * autorizacao acontece antes de qualquer leitura de conteudo.
     */
    public function show(Request $request, string $course): JsonResponse
    {
        $estrutura = ($this->obterEstrutura)(new GetPublishedStructureQuery(
            courseId: $course,
            consumerId: $this->consumidorAutenticado($request),
        ));

        return ConsumerStructureResource::make($estrutura)->response();
    }
}
