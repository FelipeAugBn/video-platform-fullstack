<script setup lang="ts">
import type { Aula, AulaEnvelope, NovaAula } from '~/types/catalogo'
import { ErroDeApi } from '~/utils/erroDeApi'

/**
 * Criacao de aula, um formulario por modulo (RF-AUL-002, AC-PROD-003).
 *
 * ## Um campo, e nada sobre publicacao
 *
 * O corpo e `{ title }`. `position` nao entra, pela mesma razao do modulo; e
 * `published_at` e o estado do video tambem nao: a aula nasce em rascunho e sem
 * video, e publicar exige um video pronto — decisao do backend, verificada na
 * publicacao (RN-PUB-001).
 *
 * ## Por que um por modulo, e nao um so na pagina
 *
 * Cada instancia tem seus proprios `enviando` e `erro`. Um formulario unico com
 * um seletor de modulo compartilharia esse estado: um `422` na aula do modulo 3
 * apareceria tambem no modulo 1, e um envio em curso desabilitaria a criacao nos
 * demais. Os identificadores dos campos carregam o modulo justamente para que
 * `label for` e `aria-describedby` continuem apontando para um unico elemento
 * com a lista inteira renderizada.
 */
const props = defineProps<{ moduloId: string }>()

const emit = defineEmits<{ criada: [Aula] }>()

const { requisitar } = useApi()

const titulo = ref('')
const enviando = ref(false)
const erro = ref<ErroDeApi | null>(null)

const idTitulo = computed(() => `campo-titulo-aula-${props.moduloId}`)
const idErroTitulo = computed(() => `erro-titulo-aula-${props.moduloId}`)
const idResumo = computed(() => `resumo-do-erro-aula-${props.moduloId}`)

const errosDeTitulo = computed(() => erro.value?.errors?.title ?? [])
const erroGeral = computed(() => (erro.value !== null && errosDeTitulo.value.length === 0 ? erro.value : null))

async function focarPrimeiroProblema(): Promise<void> {
  await nextTick()

  document.getElementById(errosDeTitulo.value.length > 0 ? idTitulo.value : idResumo.value)?.focus()
}

async function submeter(): Promise<void> {
  if (enviando.value) {
    return
  }

  enviando.value = true
  erro.value = null

  let criada: Aula | null = null

  try {
    const resposta = await requisitar<AulaEnvelope>(`/api/modules/${props.moduloId}/lessons`, {
      method: 'POST',
      body: { title: titulo.value } satisfies NovaAula,
    })

    criada = resposta.data
  }
  catch (causa) {
    erro.value = ErroDeApi.de(causa)
  }
  finally {
    enviando.value = false
  }

  if (criada !== null) {
    titulo.value = ''
    emit('criada', criada)

    return
  }

  await focarPrimeiroProblema()
}
</script>

<template>
  <form
    novalidate
    data-formulario="aula"
    :data-formulario-modulo="moduloId"
    class="flex flex-col gap-3 border-t border-default pt-3"
    @submit.prevent="submeter"
  >
    <div
      v-if="erroGeral"
      :id="idResumo"
      tabindex="-1"
    >
      <UiEstadoDeFalha
        :erro="erroGeral"
        titulo="Nao foi possivel criar a aula"
        @nova-tentativa="submeter"
      />
    </div>

    <div>
      <label
        :for="idTitulo"
        class="mb-1 block text-sm font-medium"
      >
        Nova aula
      </label>

      <UInput
        :id="idTitulo"
        v-model="titulo"
        name="title"
        :disabled="enviando"
        :aria-invalid="errosDeTitulo.length > 0"
        :aria-describedby="errosDeTitulo.length > 0 ? idErroTitulo : undefined"
        class="w-full"
      />

      <UiErroDeCampo
        :id="idErroTitulo"
        :mensagens="errosDeTitulo"
      />
    </div>

    <UiBotaoDeEnvio
      :pendente="enviando"
      rotulo="Criar aula"
      rotulo-pendente="Criando..."
    />
  </form>
</template>
