<script setup lang="ts">
import type { Curso, CursoEnvelope, NovoCurso } from '~/types/catalogo'
import { ErroDeApi } from '~/utils/erroDeApi'

/**
 * Criacao de curso (RF-CUR-001, AC-PROD-001).
 *
 * ## Dois campos, e so dois
 *
 * O corpo enviado e `{ title, description }`. `owner_id` nao entra: o
 * proprietario vem da sessao, e um campo aqui sugeriria que a escolha e do
 * cliente — sugestao que o backend desmentiria ignorando o valor. `state` e as
 * datas tambem nao entram, pela mesma razao. O `satisfies` abaixo faz o
 * compilador recusar qualquer campo a mais.
 *
 * ## O que ele nao faz
 *
 * Ele **nao recarrega a lista**. Emite `criado` e para. A separacao existe para
 * o caso em que a criacao funciona e a releitura falha: quem sabe que o `201`
 * chegou e este componente, quem sabe que o `GET` falhou e a pagina, e so
 * mantendo as duas coisas separadas a tela consegue dizer "o curso foi criado" e
 * "nao consegui atualizar a lista" ao mesmo tempo — em vez de anunciar um
 * fracasso que nao houve e convidar a criar o curso de novo.
 */
const emit = defineEmits<{ criado: [Curso] }>()

const { requisitar } = useApi()

const titulo = ref('')
const descricao = ref('')
const enviando = ref(false)
const erro = ref<ErroDeApi | null>(null)

const ID_TITULO = 'campo-titulo-curso'
const ID_DESCRICAO = 'campo-descricao-curso'
const ID_ERRO_TITULO = 'erro-titulo-curso'
const ID_ERRO_DESCRICAO = 'erro-descricao-curso'
const ID_RESUMO = 'resumo-do-erro-curso'

const errosDeTitulo = computed(() => erro.value?.errors?.title ?? [])
const errosDeDescricao = computed(() => erro.value?.errors?.description ?? [])

/**
 * A falha que nao pertence a nenhum campo — rede, indisponibilidade, resposta
 * fora do contrato. O `422` tem onde ancorar; estes nao.
 */
const erroGeral = computed(() => {
  if (erro.value === null) {
    return null
  }

  return errosDeTitulo.value.length === 0 && errosDeDescricao.value.length === 0
    ? erro.value
    : null
})

async function focarPrimeiroProblema(): Promise<void> {
  await nextTick()

  const alvo = errosDeTitulo.value.length > 0
    ? ID_TITULO
    : errosDeDescricao.value.length > 0 ? ID_DESCRICAO : ID_RESUMO

  document.getElementById(alvo)?.focus()
}

async function submeter(): Promise<void> {
  // O botao desabilitado nao cobre a submissao por `Enter`, que nao passa pelo
  // estado dele. Sem esta guarda, dois `Enter` seguidos criam dois cursos.
  if (enviando.value) {
    return
  }

  enviando.value = true
  erro.value = null

  let criado: Curso | null = null

  try {
    const resposta = await requisitar<CursoEnvelope>('/api/courses', {
      method: 'POST',
      // `satisfies` e a prova de contrato: um campo a mais aqui — `position`,
      // `owner_id`, `state` — nao compila.
      body: {
        title: titulo.value,
        description: descricao.value,
      } satisfies NovoCurso,
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
    // Limpar so depois do `201`. Limpo antes, um `422` de titulo apagaria a
    // descricao inteira que a pessoa acabou de escrever (AC-UI-001).
    titulo.value = ''
    descricao.value = ''
    emit('criado', criado)

    return
  }

  // Depois de `finally`, com os campos ja reabilitados: um campo desabilitado
  // nao aceita foco, e pedi-lo antes nao moveria nada.
  await focarPrimeiroProblema()
}
</script>

<template>
  <form
    novalidate
    data-formulario="curso"
    class="flex flex-col gap-5 rounded-xl border border-default bg-default p-5 shadow-xs"
    @submit.prevent="submeter"
  >
    <div class="flex flex-col gap-1.5">
      <h2 class="text-base font-semibold text-highlighted">
        Novo curso
      </h2>

      <p class="text-sm leading-relaxed text-muted">
        Comece pelo titulo e uma descricao curta. Os modulos e as aulas sao montados depois, dentro do curso.
      </p>
    </div>

    <div
      v-if="erroGeral"
      :id="ID_RESUMO"
      tabindex="-1"
    >
      <UiEstadoDeFalha
        :erro="erroGeral"
        titulo="Nao foi possivel criar o curso"
        @nova-tentativa="submeter"
      />
    </div>

    <UiCampo
      :campo="ID_TITULO"
      rotulo="Titulo"
      :erro-id="ID_ERRO_TITULO"
      :mensagens="errosDeTitulo"
    >
      <UInput
        :id="ID_TITULO"
        v-model="titulo"
        name="title"
        :disabled="enviando"
        :aria-invalid="errosDeTitulo.length > 0"
        :aria-describedby="errosDeTitulo.length > 0 ? ID_ERRO_TITULO : undefined"
        class="w-full"
      />
    </UiCampo>

    <UiCampo
      :campo="ID_DESCRICAO"
      rotulo="Descricao"
      :erro-id="ID_ERRO_DESCRICAO"
      :mensagens="errosDeDescricao"
    >
      <UTextarea
        :id="ID_DESCRICAO"
        v-model="descricao"
        name="description"
        :rows="3"
        :disabled="enviando"
        :aria-invalid="errosDeDescricao.length > 0"
        :aria-describedby="errosDeDescricao.length > 0 ? ID_ERRO_DESCRICAO : undefined"
        class="w-full"
      />
    </UiCampo>

    <UiBotaoDeEnvio
      :pendente="enviando"
      rotulo="Criar curso"
      rotulo-pendente="Criando..."
    />
  </form>
</template>
