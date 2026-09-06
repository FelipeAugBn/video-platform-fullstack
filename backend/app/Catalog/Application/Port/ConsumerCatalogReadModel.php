<?php

declare(strict_types=1);

namespace App\Catalog\Application\Port;

use App\Catalog\Application\View\ConsumerStructureView;
use App\Catalog\Domain\Lesson;
use App\Shared\Application\Page;

/**
 * As tres leituras do catalogo do consumidor (RF-CONS-001 a 004).
 *
 * Porta de leitura, como {@see CatalogReadModel}, e pequena de proposito: tres
 * operacoes, uma por rota de consumo. Nao tem gravacao, nao tem trava, nao expoe
 * model e devolve agregados imutaveis ou projecoes proprias.
 *
 * **As tres operacoes exigem o consumidor, e nenhuma delas o trata como filtro
 * opcional.** Ele nao e um parametro a mais: e o que decide o que a consulta
 * pode alcancar, e o adapter o resolve dentro do SQL — na pagina de cursos, na
 * raiz da arvore e no caminho ate a aula.
 *
 * A estrutura tem uma particularidade que vale declarar na porta, porque e
 * contrato e nao detalhe: a concessao decide **qual curso** e alcancado, e
 * modulos e aulas sao lidos a partir do curso ja resolvido. Quem implementar
 * este contrato de outra forma precisa preservar essa ordem — autorizar a raiz,
 * e so entao descer.
 *
 * O que a porta deliberadamente nao tem e um metodo que alcance uma aula ou um
 * curso **sem** o consumidor. Um `find($id)` inocente seria o caminho por onde a
 * concessao vazaria na proxima tarefa, e vazaria em silencio.
 */
interface ConsumerCatalogReadModel
{
    /**
     * Uma pagina dos cursos concedidos a este consumidor e em `available`.
     *
     * As duas condicoes valem **dentro da consulta**, e nao sobre o resultado:
     * `total` e numero de paginas precisam contar somente o que o consumidor
     * pode ver. Filtrar depois de carregar faria a contagem revelar quantos
     * cursos existem fora da concessao dele (RF-CONS-001, RF-CONS-002).
     *
     * @return Page Itens do tipo {@see \App\Catalog\Domain\Course}.
     */
    public function pageOfConsumer(string $consumerId, int $page, int $perPage): Page;

    /**
     * A arvore do curso concedido, com **somente aulas publicadas**, em ordem.
     *
     * Devolve `null` para curso inexistente e para curso sem concessao, sem
     * distincao — quem chama nao recebe informacao suficiente para responder aos
     * dois de forma diferente por engano (RN-PROP-005, RF-PLB-008).
     *
     * Modulo sem aula publicada aparece com a lista vazia, e curso sem modulo
     * aparece com a lista vazia: ausencia de conteudo nao e ausencia de curso.
     */
    public function structureOfConsumer(string $courseId, string $consumerId): ?ConsumerStructureView;

    /**
     * A aula, se o curso dela estiver concedido a este consumidor.
     *
     * Devolve o **agregado**, e nao uma projecao, porque quem a pede em seguida
     * decide sobre ela: a reproducao precisa saber se a aula esta publicada e
     * qual e a tentativa de video atual (RF-PLB-003, RF-PLB-004).
     *
     * Traz tambem aulas em rascunho, e isso e deliberado. A publicacao e
     * verificada **depois** da autorizacao e produz uma negativa diferente —
     * `409` de indisponibilidade, e nao `404` de acesso —, e para distinguir as
     * duas e preciso ter chegado a aula (plan §14.2).
     */
    public function lessonOfConsumer(string $lessonId, string $consumerId): ?Lesson;
}
