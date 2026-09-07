<script setup lang="ts">
import type { Modulo, ModuloEnvelope, NovoModulo } from '~/types/catalogo'
import { ErroDeApi } from '~/utils/erroDeApi'

/**
 * Criacao de modulo (RF-MOD-002, AC-PROD-003).
 *
 * ## Um campo. Nenhum de posicao
 *
 * O corpo e `{ title }`, e a ausencia e o ponto: a posicao e a proxima livre no
 * curso, calculada pelo backend sob trava (RN-ORD-002). Um campo de posicao aqui
 * pediria ao produtor um numero que o servidor ignoraria — e, pior, deixaria a
 * tela responsavel por uma ordem que ela nao controla, com duas criacoes
 * simultaneas escolhendo o mesmo valor.
 *
 * O modulo novo aparece no fim da lista porque a resposta seguinte o traz la, e
 * nao porque este componente o empurrou para o final.
 */
const props = defineProps<{ cursoId: string }>()

const emit = defineEmits<{ criado: [Modulo] }>()

const { requisitar } = useApi()

const titulo = ref('')
const enviando = ref(false)
const erro = ref<ErroDeApi | null>(null)

const ID_TITULO = 'campo-titulo-modulo'
const ID_ERRO_TITULO = 'erro-titulo-modulo'
const ID_RESUMO = 'resumo-do-erro-modulo'

const errosDeTitulo = computed(() => erro.value?.errors?.title ?? [])
const erroGeral = computed(() => (erro.value !== null && errosDeTitulo.value.length === 0 ? erro.value : null))

async function focarPrimeiroProblema(): Promise<void> {
  await nextTick()

  document.getElementById(errosDeTitulo.value.length > 0 ? ID_TITULO : ID_RESUMO)?.focus()
}

async function submeter(): Promise<void> {
  if (enviando.value) {
    return
  }

  enviando.value = true
  erro.value = null

  let criado: Modulo | null = null

  try {
    const resposta = await requisitar<ModuloEnvelope>(`/api/courses/${props.cursoId}/modules`, {
      method: 'POST',
      body: { title: titulo.value } satisfies NovoModulo,
    })

    criado = resposta.data
  }
  catch (causa) {
    erro.value = ErroDeApi.de(causa)
  }
  finally {
    enviando.value = false
  }

  if (criado !== null) {
    titulo.value = ''
    emit('criado', criado)

    return
  }

  await focarPrimeiroProblema()
}
</script>

<template>
  <form
    novalidate
    data-formulario="modulo"
    class="flex flex-col gap-3 rounded-lg border border-dashed border-default p-4"
    @submit.prevent="submeter"
  >
    <h3 class="text-sm font-semibold">
      Novo modulo
    </h3>

    <div
      v-if="erroGeral"
      :id="ID_RESUMO"
      tabindex="-1"
    >
      <UiEstadoDeFalha
        :erro="erroGeral"
        titulo="Nao foi possivel criar o modulo"
        @nova-tentativa="submeter"
      />
    </div>

    <div>
      <label
        :for="ID_TITULO"
        class="mb-1 block text-sm font-medium"
      >
        Titulo do modulo
      </label>

      <UInput
        :id="ID_TITULO"
        v-model="titulo"
        name="title"
        :disabled="enviando"
        :aria-invalid="errosDeTitulo.length > 0"
        :aria-describedby="errosDeTitulo.length > 0 ? ID_ERRO_TITULO : undefined"
        class="w-full"
      />

      <UiErroDeCampo
        :id="ID_ERRO_TITULO"
        :mensagens="errosDeTitulo"
      />
    </div>

    <UiBotaoDeEnvio
      :pendente="enviando"
      rotulo="Criar modulo"
      rotulo-pendente="Criando..."
    />
  </form>
</template>
