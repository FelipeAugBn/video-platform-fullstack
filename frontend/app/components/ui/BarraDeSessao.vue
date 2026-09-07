<script setup lang="ts">
import type { Usuario } from '~/composables/useSessionState'
import { ErroDeApi } from '~/utils/erroDeApi'

/**
 * Quem esta autenticado, para onde ir e como sair.
 *
 * A barra existe por uma exigencia concreta da avaliacao: a jornada completa —
 * do gerenciamento do conteudo ate assisti-lo — precisa ser percorrida **pela
 * interface**, e trocar de produtor para consumidor sem uma acao de sair
 * obrigaria a apagar cookie na mao.
 *
 * ## O que ela comunica
 *
 * Identidade da plataforma, a area em que a pessoa esta, quem esta autenticado e
 * a saida. Nada alem disso: com duas areas no total — uma por perfil —, um menu
 * lateral guardaria um unico destino atras de mais um clique.
 *
 * ## O link do perfil e conveniencia, nao autorizacao
 *
 * Mostrar "Meus cursos" para quem produz e "Catalogo" para quem consome poupa
 * um endereco digitado. Nao protege nada: quem alcancar a area do outro perfil
 * recebe a negativa do backend na primeira requisicao, exatamente como
 * receberia se esta barra nao existisse (RN-AUT-002, RF-UI-017).
 *
 * ## Sair e um desfecho do servidor, nao da tela
 *
 * A navegacao para o login acontece **depois** da confirmacao. `useAuth.sair`
 * so limpa o estado quando a API responde; se a chamada falhar, a sessao visual
 * permanece — e permanece verdadeira, porque a sessao do outro lado tambem
 * continua de pe. Limpar antes deixaria a interface deslogada com a sessao viva,
 * e a proxima tela pareceria expirada sem ter expirado.
 */
const { usuario, autenticado, sair, destinoDoPerfil } = useAuth()

const saindo = ref(false)
const erro = ref<ErroDeApi | null>(null)

const ROTULO_DA_AREA = {
  producer: 'Meus cursos',
  consumer: 'Catalogo',
} as const satisfies Record<Usuario['role'], string>

/*
| O perfil escrito por extenso, ao lado do nome.
|
| Nomeia a atividade, e nao a pessoa: "Producao" e "Consumo" descrevem o que a
| conta faz na plataforma sem atribuir genero a quem esta autenticado.
*/
const ROTULO_DO_PERFIL = {
  producer: 'Producao',
  consumer: 'Consumo',
} as const satisfies Record<Usuario['role'], string>

async function encerrar(): Promise<void> {
  // A guarda vale alem do botao desabilitado: o acionamento por teclado dispara
  // o mesmo manipulador, e um `Enter` repetido enviaria dois logouts.
  if (saindo.value) {
    return
  }

  saindo.value = true
  erro.value = null

  try {
    await sair()
    await navigateTo('/login')
  }
  catch (causa) {
    erro.value = ErroDeApi.de(causa)
  }
  finally {
    // O botao volta a ficar disponivel mesmo apos a falha: ele continua sendo o
    // caminho de saida, e some-lo deslocaria o foco de quem acabou de aciona-lo.
    saindo.value = false
  }
}
</script>

<template>
  <header
    v-if="autenticado && usuario"
    data-barra-de-sessao
    class="sticky top-0 z-30 border-b border-default bg-default"
  >
    <div class="mx-auto flex w-full max-w-5xl flex-wrap items-center gap-x-4 gap-y-3 px-4 py-3 sm:px-6">
      <UiMarca class="shrink-0" />

      <span
        aria-hidden="true"
        class="hidden h-5 w-px shrink-0 bg-accented sm:block"
      />

      <!--
        A area atual e o unico destino do perfil, entao o marcador de "voce esta
        aqui" e o proprio link: um segundo rotulo repetiria a mesma palavra ao
        lado dela.
      -->
      <nav aria-label="Principal">
        <NuxtLink
          :to="destinoDoPerfil(usuario.role)"
          data-acao="ir-para-minha-area"
          class="inline-flex items-center rounded-md bg-primary/10 px-3 py-1.5 text-sm font-medium text-primary transition-colors hover:bg-primary/15"
        >
          {{ ROTULO_DA_AREA[usuario.role] }}
        </NuxtLink>
      </nav>

      <div class="ms-auto flex min-w-0 items-center gap-3">
        <div class="min-w-0 text-right leading-tight">
          <p
            data-usuario
            class="truncate text-sm font-medium text-highlighted"
          >
            {{ usuario.name }}
          </p>

          <p class="truncate text-xs text-muted">
            {{ ROTULO_DO_PERFIL[usuario.role] }}
          </p>
        </div>

        <UButton
          type="button"
          color="neutral"
          variant="outline"
          icon="i-lucide-log-out"
          :disabled="saindo"
          :aria-busy="saindo"
          data-acao="sair"
          class="shrink-0"
          @click="encerrar"
        >
          {{ saindo ? 'Saindo...' : 'Sair' }}
        </UButton>
      </div>
    </div>

    <!--
      A falha do logout fica **dentro** da barra, junto do botao que a produziu:
      anunciada no corpo da pagina, ela pareceria falha do conteudo que esta
      sendo lido.
    -->
    <div
      v-if="erro"
      class="mx-auto w-full max-w-5xl px-4 pb-4 sm:px-6"
    >
      <UiEstadoDeFalha
        :erro="erro"
        titulo="Nao foi possivel encerrar a sessao"
        @nova-tentativa="encerrar"
      />
    </div>
  </header>
</template>
