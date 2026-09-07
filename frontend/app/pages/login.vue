<script setup lang="ts">
import { ErroDeApi } from '~/utils/erroDeApi'

/**
 * Autenticacao — o primeiro formulario completo da aplicacao.
 *
 * Ele estabelece o padrao que as telas seguintes repetem: sucesso, validacao por
 * campo e falha, cada um com estado proprio, foco tratado e nada digitado
 * perdido no caminho.
 *
 * ## O que **nao** e decidido aqui
 *
 * A interface nao valida credencial, nao descobre se o e-mail existe e nao
 * escolhe para onde a pessoa vai — as tres coisas vem da API e de `useAuth`
 * (RF-UI-017). Uma validacao local de "e-mail cadastrado" seria, alem de
 * duplicacao, exatamente o oraculo de cadastro que RF-AUT-008 fecha.
 */
const { entrar, autenticado, expirada } = useAuth()
const rota = useRoute()

const email = ref('')
const senha = ref('')
const enviando = ref(false)
const erro = ref<ErroDeApi | null>(null)

const ID_EMAIL = 'campo-email'
const ID_SENHA = 'campo-senha'
const ID_ERRO_EMAIL = 'erro-email'
const ID_ERRO_SENHA = 'erro-senha'
const ID_RESUMO = 'resumo-do-erro'

const errosDeEmail = computed(() => erro.value?.errors?.email ?? [])
const errosDeSenha = computed(() => erro.value?.errors?.password ?? [])

/**
 * A falha que nao pertence a nenhum campo.
 *
 * Credencial recusada chega como `422` **no campo `email`**, com a mensagem
 * generica que a API escreveu — e por isso ela aparece junto do campo, e nao
 * aqui. O resumo fica para o que nao tem onde ancorar: rede, indisponibilidade,
 * resposta inesperada.
 */
const erroGeral = computed(() => {
  if (erro.value === null) {
    return null
  }

  return errosDeEmail.value.length === 0 && errosDeSenha.value.length === 0
    ? erro.value
    : null
})

/**
 * Leva o foco para onde esta o problema.
 *
 * Sem isto, quem navega por teclado submete o formulario e o foco continua no
 * botao, sem nenhum sinal de que algo foi recusado mais acima.
 */
async function focarPrimeiroProblema(): Promise<void> {
  await nextTick()

  const alvo = errosDeEmail.value.length > 0
    ? ID_EMAIL
    : errosDeSenha.value.length > 0 ? ID_SENHA : ID_RESUMO

  document.getElementById(alvo)?.focus()
}

async function submeter(): Promise<void> {
  // A guarda existe alem do botao desabilitado: um formulario tambem e
  // submetido por `Enter`, e essa via nao passa pelo estado do botao.
  if (enviando.value) {
    return
  }

  enviando.value = true
  erro.value = null

  try {
    // O que foi digitado **nao** e limpo aqui. Uma senha apagada a cada recusa
    // obrigaria a redigitar tudo por causa de um erro de digitacao no e-mail.
    await entrar({ email: email.value, password: senha.value }, rota.query.redirect)
  }
  catch (causa) {
    erro.value = ErroDeApi.de(causa)
  }
  finally {
    enviando.value = false
  }

  // O foco vai **depois** de reabilitar os campos. Um campo desabilitado nao
  // aceita foco, e pedi-lo antes nao move nada — quem navega por teclado ficaria
  // no botao, sem sinal de que algo foi recusado acima.
  if (erro.value !== null) {
    await focarPrimeiroProblema()
  }
}
</script>

<template>
  <UiPagina
    largura="estreita"
    centralizada
  >
    <!--
      Marca, titulo, explicacao e formulario dentro da mesma superficie: a
      entrada da plataforma e uma composicao unica, e nao um cartao solto sob um
      titulo. Os estados entram entre a explicacao e os campos, que e onde eles
      sao lidos antes de a pessoa comecar a digitar.
    -->
    <div class="flex flex-col gap-6 rounded-xl border border-default bg-default p-6 shadow-xs sm:p-8">
      <div class="flex flex-col gap-5">
        <UiMarca tamanho="grande" />

        <header class="flex flex-col gap-2 border-t border-default pt-5">
          <h1 class="titulo-de-pagina">
            Entrar
          </h1>

          <p class="text-[0.9375rem] leading-relaxed text-toned">
            Acesse com o e-mail e a senha da sua conta para publicar ou assistir aos cursos.
          </p>
        </header>
      </div>

      <!--
        Sessao expirada e um estado proprio, e nao acesso negado (RF-UI-014): quem
        chega aqui desviado de outra tela precisa saber que **tinha** acesso e
        apenas precisa entrar de novo.
      -->
      <UiPainelDeEstado
        v-if="expirada && erro === null"
        tom="atencao"
        titulo="Sua sessao expirou"
        descricao="Entre novamente para continuar de onde parou."
        data-estado="sessao-expirada"
      />

      <UiEstadoDeSucesso
        v-if="autenticado"
        titulo="Sessao iniciada"
        descricao="Abrindo a sua area..."
      />

      <form
        novalidate
        class="flex flex-col gap-5"
        @submit.prevent="submeter"
      >
        <!--
          `tabindex="-1"` para o resumo poder receber foco por codigo sem entrar na
          ordem de tabulacao: ele e destino de foco depois de uma falha, nao uma
          parada normal do teclado.
        -->
        <div
          v-if="erroGeral"
          :id="ID_RESUMO"
          tabindex="-1"
        >
          <UiEstadoDeFalha
            :erro="erroGeral"
            @nova-tentativa="submeter"
          />
        </div>

        <UiCampo
          :campo="ID_EMAIL"
          rotulo="E-mail"
          :erro-id="ID_ERRO_EMAIL"
          :mensagens="errosDeEmail"
        >
          <UInput
            :id="ID_EMAIL"
            v-model="email"
            type="email"
            name="email"
            autocomplete="email"
            :disabled="enviando"
            :aria-invalid="errosDeEmail.length > 0"
            :aria-describedby="errosDeEmail.length > 0 ? ID_ERRO_EMAIL : undefined"
            class="w-full"
          />
        </UiCampo>

        <UiCampo
          :campo="ID_SENHA"
          rotulo="Senha"
          :erro-id="ID_ERRO_SENHA"
          :mensagens="errosDeSenha"
        >
          <UInput
            :id="ID_SENHA"
            v-model="senha"
            type="password"
            name="password"
            autocomplete="current-password"
            :disabled="enviando"
            :aria-invalid="errosDeSenha.length > 0"
            :aria-describedby="errosDeSenha.length > 0 ? ID_ERRO_SENHA : undefined"
            class="w-full"
          />
        </UiCampo>

        <UiBotaoDeEnvio
          :pendente="enviando"
          rotulo="Entrar"
          rotulo-pendente="Entrando..."
        />
      </form>
    </div>
  </UiPagina>
</template>
