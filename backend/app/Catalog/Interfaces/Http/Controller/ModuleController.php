<?php

declare(strict_types=1);

namespace App\Catalog\Interfaces\Http\Controller;

use App\Catalog\Application\CreateModule\CreateModule;
use App\Catalog\Application\CreateModule\CreateModuleCommand;
use App\Catalog\Application\ListModules\ListModules;
use App\Catalog\Application\ListModules\ListModulesQuery;
use App\Catalog\Interfaces\Http\Request\CreateModuleRequest;
use App\Catalog\Interfaces\Http\Resource\ModuleResource;
use App\Shared\Interfaces\Http\Controller\IdentifiesTheOwner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * Os modulos de um curso, na fronteira HTTP.
 *
 * Cada acao faz quatro coisas e para: le a entrada ja validada, descobre quem
 * esta autenticado, chama o caso de uso e devolve o resultado como recurso.
 * Consulta ao banco, geracao de identificador, decisao de propriedade e — o que
 * mais importaria aqui — **calculo da posicao** ja tiveram um lugar escolhido, e
 * nenhum deles e este.
 *
 * O identificador do curso chega como texto, e nao como model. Nao ha route model
 * binding de proposito: ele carregaria o curso pelo identificador **antes** de
 * saber de quem ele e, e um curso alheio ja teria sido lido do banco quando
 * alguem fosse recusar o acesso (RN-PROP-002).
 */
final class ModuleController
{
    use IdentifiesTheOwner;

    public function __construct(
        private readonly CreateModule $criarModulo,
        private readonly ListModules $listarModulos,
    ) {}

    public function store(CreateModuleRequest $request, string $course): JsonResponse
    {
        $modulo = ($this->criarModulo)(new CreateModuleCommand(
            courseId: $course,
            // Da sessao, sempre. O corpo da requisicao nao participa desta linha.
            ownerId: $this->donoAutenticado($request),
            title: $request->validated('title'),
        ));

        return ModuleResource::make($modulo)
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * A listagem **nao** e paginada, e por isso nao passa por `PaginatedResponse`.
     *
     * A resposta traz `data` e nada mais: sem `meta` e sem `links`. Modulos sao a
     * ordem de um curso, e paginar a ordem quebraria o que a spec exige preservar
     * (plan §10.1). Curso sem modulos devolve `data: []`, que e uma colecao vazia
     * e nao um erro.
     */
    public function index(Request $request, string $course): AnonymousResourceCollection
    {
        $modulos = ($this->listarModulos)(new ListModulesQuery(
            courseId: $course,
            ownerId: $this->donoAutenticado($request),
        ));

        return ModuleResource::collection($modulos);
    }
}
