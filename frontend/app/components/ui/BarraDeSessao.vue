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
    class="border-b border-default"
  >
    <div class="mx-auto flex w-full max-w-3xl flex-wrap items-center gap-x-4 gap-y-2 p-4">
      <nav
        aria-label="Principal"
        class="grow"
      >
        <NuxtLink
          :to="destinoDoPerfil(usuario.role)"
          data-acao="ir-para-minha-area"
          class="text-sm font-medium underline-offset-4 hover:underline focus-visible:underline"
        >
          {{ ROTULO_DA_AREA[usuario.role] }}
        </NuxtLink>
      </nav>

      <p
        data-usuario
        class="text-sm text-muted"
      >
        {{ usuario.name }}
      </p>

      <UButton
        type="button"
        color="neutral"
        variant="outline"
        size="sm"
        icon="i-lucide-log-out"
        :disabled="saindo"
        :aria-busy="saindo"
        data-acao="sair"
        @click="encerrar"
      >
        {{ saindo ? 'Saindo...' : 'Sair' }}
      </UButton>
    </div>

    <!--
      A falha do logout fica **dentro** da barra, junto do botao que a produziu:
      anunciada no corpo da pagina, ela pareceria falha do conteudo que esta
      sendo lido.
    -->
    <div
      v-if="erro"
      class="mx-auto w-full max-w-3xl px-4 pb-4"
    >
      <UiEstadoDeFalha
        :erro="erro"
        titulo="Nao foi possivel encerrar a sessao"
        @nova-tentativa="encerrar"
      />
    </div>
  </header>
</template>
