<?php

declare(strict_types=1);

namespace App\Catalog\Interfaces\Http\Controller;

use App\Catalog\Application\CreateCourse\CreateCourse;
use App\Catalog\Application\CreateCourse\CreateCourseCommand;
use App\Catalog\Application\GetCourse\GetCourse;
use App\Catalog\Application\GetCourse\GetCourseQuery;
use App\Catalog\Application\ListCourses\ListCourses;
use App\Catalog\Application\ListCourses\ListCoursesQuery;
use App\Catalog\Interfaces\Http\Request\CreateCourseRequest;
use App\Catalog\Interfaces\Http\Request\ListCoursesRequest;
use App\Catalog\Interfaces\Http\Resource\CourseResource;
use App\Models\User;
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
    public function __construct(
        private readonly CreateCourse $criarCurso,
        private readonly ListCourses $listarCursos,
        private readonly GetCourse $obterCurso,
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
     * O dono da operacao e quem esta autenticado — nunca quem a requisicao diz
     * ser.
     *
     * A rota exige `auth:sanctum`, entao chegar aqui sem usuario significaria
     * middleware ausente, e nao requisicao anonima. A afirmacao existe para que
     * essa hipotese falhe alto em desenvolvimento, em vez de virar um curso sem
     * dono no banco.
     */
    private function donoAutenticado(Request $request): string
    {
        $usuario = $request->user();
        assert($usuario instanceof User);

        return (string) $usuario->getKey();
    }
}
