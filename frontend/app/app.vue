<template>
  <!--
    `UApp` e a raiz exigida pela biblioteca de componentes: e ela que fornece o
    contexto de sobreposicoes — modais, tooltips e notificacoes — e as
    preferencias globais. Sem ela, esses componentes montam sem destino.
  -->
  <UApp>
    <!--
      O involucro so organiza a altura: a barra fica no topo, a pagina ocupa o
      que sobra, e o fundo da aplicacao continua visivel quando o conteudo e
      curto. Ele nao acrescenta marco de regiao nenhum.
    -->
    <div class="flex min-h-dvh flex-col">
      <!--
        A barra e o unico elemento fora das paginas, e ela mesma se esconde
        enquanto nao ha sessao — o login nao tem de onde sair. Cada pagina traz o
        seu proprio `main`, entao os dois marcos de regiao ficam irmaos: cabecalho
        da aplicacao e conteudo da tela.
      -->
      <UiBarraDeSessao />

      <NuxtPage />
    </div>
  </UApp>
</template>
