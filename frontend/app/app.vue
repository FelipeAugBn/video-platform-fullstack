<template>
  <!--
    `UApp` e a raiz exigida pela biblioteca de componentes: e ela que fornece o
    contexto de sobreposicoes — modais, tooltips e notificacoes — e as
    preferencias globais. Sem ela, esses componentes montam sem destino.
  -->
  <UApp>
    <!--
      A barra e o unico elemento fora das paginas, e ela mesma se esconde
      enquanto nao ha sessao — o login nao tem de onde sair. Cada pagina traz o
      seu proprio `main`, entao os dois marcos de regiao ficam irmaos: cabecalho
      da aplicacao e conteudo da tela.
    -->
    <UiBarraDeSessao />

    <NuxtPage />
  </UApp>
</template>
