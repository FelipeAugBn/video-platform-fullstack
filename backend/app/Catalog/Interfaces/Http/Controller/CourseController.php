<?php

declare(strict_types=1);

namespace App\Catalog\Interfaces\Http\Controller;

use App\Catalog\Application\CreateCourse\CreateCourse;
use App\Catalog\Application\CreateCourse\CreateCourseCommand;
use App\Catalog\Application\GetCourse\GetCourse;
use App\Catalog\Application\GetCourse\GetCourseQuery;
use App\Catalog\Application\GetCourseStructure\GetCourseStructure;
use App\Catalog\Application\GetCourseStructure\GetCourseStructureQuery;
use App\Catalog\Application\ListCourses\ListCourses;
use App\Catalog\Application\ListCourses\ListCoursesQuery;
use App\Catalog\Interfaces\Http\Request\CreateCourseRequest;
use App\Catalog\Interfaces\Http\Request\ListCoursesRequest;
use App\Catalog\Interfaces\Http\Resource\CourseResource;
use App\Catalog\Interfaces\Http\Resource\CourseStructureResource;
use App\Shared\Interfaces\Http\Controller\IdentifiesTheOwner;
use App\Shared\Interfaces\Http\Resource\PaginatedResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * A fronteira HTTP do catalogo do produtor.
 *
 * Cada acao faz quatro coisas e para: le a entrada ja validada, descobre quem
 * esta autenticado, chama o caso de uso e devolve o resultado como recurso.
 *
 * O que **nao** acontece aqui, e nao por acaso: consulta ao banco, geracao de
 * identificador, decisao sobre propriedade e calculo de paginacao. Todas ja
 * tiveram um lugar escolhido, e a razao de manter o controller magro e que ele e
 * o arquivo que mais tende a crescer — cada regra que se instala aqui deixa de
 * ser testavel sem subir HTTP e passa a ser copiada para o proximo endpoint.
 */
final class CourseController
{
    use IdentifiesTheOwner;

    public function __construct(
        private readonly CreateCourse $criarCurso,
        private readonly ListCourses $listarCursos,
        private readonly GetCourse $obterCurso,
        private readonly GetCourseStructure $obterEstrutura,
    ) {}

    public function store(CreateCourseRequest $request): JsonResponse
    {
        $curso = ($this->criarCurso)(new CreateCourseCommand(
            // Da sessao, sempre. O corpo da requisicao nao participa desta
            // linha, e e por isso que `owner_id` enviado por um cliente nao tem
            // efeito nenhum (RN-PROP-001).
            ownerId: $this->donoAutenticado($request),
            title: $request->validated('title'),
            description: $request->validated('description'),
        ));

        return CourseResource::make($curso)
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function index(ListCoursesRequest $request): PaginatedResponse
    {
        $pagina = ($this->listarCursos)(new ListCoursesQuery(
            ownerId: $this->donoAutenticado($request),
            page: $request->pagina(),
            perPage: $request->porPagina(),
        ));

        return PaginatedResponse::ofPage($pagina, CourseResource::class);
    }

    /**
     * O identificador chega como texto, e nao como model.
     *
     * Nao ha route model binding aqui de proposito: ele carregaria o curso pelo
     * identificador **antes** de saber de quem ele e, e um curso alheio ja teria
     * sido lido do banco quando alguem fosse recusar o acesso. A consulta que
     * acontece adiante leva identificador e dono juntos (RN-PROP-002).
     */
    public function show(Request $request, string $course): JsonResponse
    {
        $curso = ($this->obterCurso)(new GetCourseQuery(
            courseId: $course,
            ownerId: $this->donoAutenticado($request),
        ));

        return CourseResource::make($curso)->response();
    }

    /**
     * A arvore do curso: ele, seus modulos e as aulas de cada modulo.
     *
     * Mesma recusa das demais acoes — curso alheio e curso inexistente respondem
     * o mesmo `404` — e mesma ausencia de route model binding, pelo mesmo motivo:
     * carregar o curso antes de saber de quem ele e seria ler recurso alheio para
     * so entao recusa-lo.
     *
     * A resposta **nao** e paginada. A estrutura e uma arvore ordenada, e paginar
     * a ordem quebraria o que RF-EST-002 exige preservar (plan §10.1) — por isso
     * ela nao passa por `PaginatedResponse`.
     */
    public function structure(Request $request, string $course): JsonResponse
    {
        $estrutura = ($this->obterEstrutura)(new GetCourseStructureQuery(
            courseId: $course,
            ownerId: $this->donoAutenticado($request),
        ));

        return CourseStructureResource::make($estrutura)->response();
    }
}
