<script setup lang="ts">
/**
 * A escolha do arquivo de video.
 *
 * `accept="video/mp4"` e **ajuda visual**, e nada mais: ele filtra o que o
 * seletor do sistema mostra e nao impede escolher outra coisa. Quem decide se o
 * tipo e o tamanho servem e o backend, na abertura do envio — repetir aqui o
 * limite de 10 GiB criaria uma segunda regra para manter em sincronia, e a tela
 * recusaria arquivos que a API aceita no dia em que o limite mudasse.
 */
defineProps<{
  id: string
  desabilitado?: boolean
}>()

const emit = defineEmits<{ escolhido: [File] }>()

function aoMudar(evento: Event): void {
  const alvo = evento.target as HTMLInputElement
  const arquivo = alvo.files?.[0]

  if (arquivo === undefined) {
    return
  }

  emit('escolhido', arquivo)
}
</script>

<template>
  <div class="flex flex-col gap-2 rounded-lg border border-dashed border-accented bg-default p-3">
    <label
      :for="id"
      class="text-sm font-medium text-highlighted"
    >
      Arquivo de video
    </label>

    <input
      :id="id"
      type="file"
      accept="video/mp4"
      :disabled="desabilitado"
      data-acao="escolher-video"
      class="min-w-0 text-sm text-muted file:mr-3 file:cursor-pointer file:rounded-md file:border-0 file:bg-primary file:px-3 file:py-1.5 file:text-sm file:font-medium file:text-inverted disabled:opacity-60"
      @change="aoMudar"
    >

    <p class="text-xs text-muted">
      O envio comeca assim que voce escolher o arquivo. O tipo e o tamanho aceitos sao verificados pela API.
    </p>
  </div>
</template>
