<?php

declare(strict_types=1);

namespace App\Catalog\Application\View;

use App\Catalog\Domain\Course;

/**
 * O curso inteiro, como o produtor o consulta de uma vez (RF-EST-001).
 *
 * O curso entra como o proprio agregado, e nao copiado campo a campo: ele ja e
 * um objeto de dominio imutavel com exatamente os dados da resposta, e duplica-lo
 * num terceiro formato daria duas descricoes do mesmo curso para manter em
 * sincronia. Modulos e aulas entram como projecoes porque, ao contrario do curso,
 * carregam algo que o agregado nao tem: o aninhamento e o estado do video.
 *
 * **Nao ha paginacao aqui, e isso e decisao registrada** (plan §10.1): a
 * estrutura e uma arvore, e paginar uma arvore quebraria a ordem que RF-EST-002
 * exige preservar. Por isso esta classe nao tem pagina, tamanho nem total.
 */
final class CourseStructureView
{
    /**
     * @param  list<ModuleView>  $modules
     */
    public function __construct(
        public readonly Course $course,
        public readonly array $modules,
    ) {}
}
