<?php

declare(strict_types=1);

namespace App\Catalog\Application\View;

use App\Catalog\Domain\Course;

/**
 * O curso concedido, como o consumidor navega por ele (RF-CONS-003).
 *
 * O curso entra como o proprio agregado, pela mesma razao de
 * {@see CourseStructureView}: ele ja e um objeto imutavel com exatamente os
 * dados da resposta, e copia-lo daria uma terceira descricao do mesmo curso para
 * manter em sincronia.
 *
 * O que muda em relacao a arvore do produtor esta um nivel abaixo: os modulos
 * sao {@see ConsumerModuleView}, cujas aulas nao carregam estado de video nem
 * rascunhos. Duas arvores com tipos diferentes, e nao uma arvore com campos
 * condicionais — assim nao existe combinacao de parametros capaz de entregar a
 * visao do produtor a quem consome.
 *
 * **Sem paginacao** (plan §10.1): a arvore e ordenada, e paginar a ordem e o que
 * RF-EST-002 exige nao fazer.
 */
final class ConsumerStructureView
{
    /**
     * @param  list<ConsumerModuleView>  $modules
     */
    public function __construct(
        public readonly Course $course,
        public readonly array $modules,
    ) {}
}
