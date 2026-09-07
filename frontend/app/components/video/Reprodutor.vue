<script setup lang="ts">
import type { Reproducao } from '~/types/catalogo'

/**
 * O elemento de video do navegador, alimentado pela URL temporaria
 * (RF-PLB-005, AC-CONS-001).
 *
 * Um `<video controls>` e nada alem disso. Sem player proprio, sem biblioteca,
 * sem DRM e sem renovacao automatica da URL: o desafio pede que o conteudo seja
 * disponibilizado, e a estrategia escolhida — objeto privado com URL assinada de
 * cinco minutos — ja resolve isso com o que o navegador tem de fabrica. Os bytes
 * vao do armazenamento direto ao elemento, sem passar pela API nem pelo servidor
 * Nuxt (plan §14.2).
 *
 * ## Os tres campos do contrato, cada um no seu lugar
 *
 *   `playback_url`   o `src` da fonte.
 *   `content_type`   o `type` da fonte, para o navegador decidir se sabe tocar
 *                    aquilo antes de baixar.
 *   `expires_at`     exibido, porque a permissao acaba: sem o aviso, o video
 *                    pararia no meio sem explicacao, e a pessoa concluiria que o
 *                    conteudo esta quebrado.
 *
 * A URL **nao** aparece como texto e nao ha link de download. Ela precisa estar
 * no atributo para o video tocar — nao ha como esconde-la do DOM —, mas exibi-la
 * ou oferece-la para copia seria transformar a limitacao assumida em convite.
 *
 * Os controles nativos vem com foco, teclado e rotulos que o proprio navegador
 * mantem. `aria-label` acrescenta o que falta: um nome para a regiao, ja que o
 * contrato de reproducao nao devolve o titulo da aula.
 */
defineProps<{ reproducao: Reproducao }>()
</script>

<template>
  <figure
    data-reproducao
    class="flex flex-col gap-3"
  >
    <!--
      A moldura fixa a proporcao antes de o video carregar: sem ela a tela salta
      quando os metadados chegam e o elemento assume a altura real. O fundo
      escuro e constante nos dois temas porque ele e o vazio da imagem, e nao uma
      superficie da interface.
    -->
    <div class="overflow-hidden rounded-xl border border-default bg-neutral-950">
      <video
        controls
        preload="metadata"
        playsinline
        aria-label="Video da aula"
        data-reprodutor
        class="block aspect-video w-full"
      >
        <source
          :src="reproducao.playback_url"
          :type="reproducao.content_type"
          data-fonte
        >

        <!--
          O texto so aparece onde o elemento nao existe. Ele nao repete a URL: um
          navegador sem `<video>` tambem nao deveria receber um link direto para o
          objeto assinado.
        -->
        Este navegador nao consegue reproduzir video.
      </video>
    </div>

    <!--
      O prazo e informacao de apoio: ele explica uma interrupcao futura sem
      disputar atencao com o video, que e o motivo da tela.
    -->
    <figcaption class="text-xs leading-relaxed text-muted">
      O acesso a este video expira em
      <time :datetime="reproducao.expires_at">{{ formatarDataHora(reproducao.expires_at) }}</time>.
      Depois disso, recarregue a pagina para continuar assistindo.
    </figcaption>
  </figure>
</template>
