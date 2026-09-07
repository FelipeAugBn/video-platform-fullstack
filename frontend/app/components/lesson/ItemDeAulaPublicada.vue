<script setup lang="ts">
import type { AulaDoConsumidor } from '~/types/catalogo'

/**
 * Uma aula na arvore do consumidor (RF-CONS-003, AC-CONS-004).
 *
 * Nao e o `LessonItemDeAula` do produtor com menos coisas: e outro item. Aquele
 * compoe o envio do video, o acompanhamento do processamento e a publicacao, e
 * le `video_state` — um campo que **nao existe** em `AulaDoConsumidor`. O tipo e
 * o que garante isso: nao ha como esta tela ler o estado do video, porque a
 * resposta nunca o traz.
 *
 * Nao ha etiqueta de rascunho tampouco. Toda aula que chega aqui esta publicada
 * — `published_at` nao e nula no contrato do consumidor —, e um rotulo
 * "Publicada" repetido em todas as linhas nao distinguiria nada.
 *
 * O titulo **e** o link para a reproducao, pelo mesmo motivo do cartao de curso:
 * um link cujo texto nomeia o destino ja se anuncia sozinho.
 */
defineProps<{ aula: AulaDoConsumidor }>()
</script>

<template>
  <!--
    A linha inteira e o alvo, sem um segundo link: a camada absoluta do link do
    titulo cobre a linha, entao o alvo cresce sem repetir a aula na lista de
    links de um leitor de tela.
  -->
  <li
    data-aula
    :data-aula-id="aula.id"
    class="group relative -mx-2 flex gap-3 rounded-md border-t border-default px-2 py-3 transition-colors first:border-t-0 hover:bg-muted has-[a:focus-visible]:outline-2 has-[a:focus-visible]:outline-offset-2 has-[a:focus-visible]:outline-primary"
  >
    <!--
      A posicao exibida e a que veio na resposta, e nao o indice do laco: a
      arvore do consumidor omite rascunhos, entao as posicoes podem ter buracos —
      um contador local renumeraria as aulas e contradiria o que o produtor ve.
    -->
    <span
      data-aula-posicao
      class="w-8 shrink-0 text-xs text-muted tabular-nums"
    >
      {{ aula.position }}.
    </span>

    <!--
      Titulo e data dividem a coluna a direita da numeracao. Em telas estreitas a
      data desce **dentro** dela, alinhada ao titulo da aula a que pertence.
    -->
    <div class="flex min-w-0 grow flex-col gap-1 sm:flex-row sm:items-baseline sm:justify-between sm:gap-3">
      <h4 class="min-w-0 text-sm font-medium text-highlighted">
        <NuxtLink
          :to="`/catalog/lessons/${aula.id}`"
          data-acao="abrir-aula"
          class="after:absolute after:inset-0 group-hover:text-primary group-hover:underline focus-visible:outline-none"
        >
          {{ aula.title }}
        </NuxtLink>
      </h4>

      <p class="shrink-0 text-xs text-muted">
        Publicada em
        <time :datetime="aula.published_at">{{ formatarDataHora(aula.published_at) }}</time>
      </p>
    </div>
  </li>
</template>
