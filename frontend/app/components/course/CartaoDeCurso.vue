<script setup lang="ts">
import type { Curso } from '~/types/catalogo'

/**
 * Um curso numa listagem (RF-CUR-002, RF-CONS-001).
 *
 * Os cinco campos que o contrato promete estao aqui — titulo, descricao, estado
 * e data de criacao, mais o identificador que vira destino do link. O
 * proprietario nao aparece: nenhuma das duas listagens mistura donos, e repetir
 * "seu" em cada linha nao informa nada.
 *
 * O titulo **e** o link. Um link cujo texto e o nome do curso ja se anuncia
 * sozinho em leitor de tela; "ver detalhes" repetido dez vezes numa lista nao
 * distingue um item do outro.
 *
 * ## Por que o destino vem de fora
 *
 * As duas jornadas listam o mesmo `Curso` e levam a telas diferentes: o produtor
 * abre a estrutura que edita, o consumidor abre a arvore que assiste. A unica
 * diferenca entre os dois cartoes seria o prefixo do endereco — e um segundo
 * componente identico existiria so para guardar essa string, com as duas copias
 * divergindo na primeira mudanca de estilo.
 *
 * `base` e **obrigatoria** de proposito. Um valor padrao apontando para a area
 * do produtor faria o cartao do consumidor levar para la em silencio caso a
 * propriedade fosse esquecida; sem padrao, o esquecimento e erro de compilacao.
 */
defineProps<{ curso: Curso, base: string }>()
</script>

<template>
  <li
    data-curso
    :data-curso-id="curso.id"
    class="flex flex-col gap-2 rounded-lg border border-default p-4"
  >
    <div class="flex flex-wrap items-center gap-2">
      <h3 class="text-base font-semibold">
        <NuxtLink
          :to="`${base}/${curso.id}`"
          data-acao="abrir-curso"
          class="underline-offset-4 hover:underline focus-visible:underline"
        >
          {{ curso.title }}
        </NuxtLink>
      </h3>

      <CourseEtiquetaDeEstado :estado="curso.state" />
    </div>

    <p class="text-sm text-muted">
      {{ curso.description }}
    </p>

    <!--
      O instante legivel por maquina fica em `datetime`, e o texto formatado no
      corpo: o primeiro nao depende do fuso de quem le, o segundo depende.
    -->
    <p class="text-xs text-muted">
      Criado em
      <time :datetime="curso.created_at">{{ formatarDataHora(curso.created_at) }}</time>
    </p>
  </li>
</template>
