// https://nuxt.com/docs/api/configuration/nuxt-config
export default defineNuxtConfig({
  compatibilityDate: '2025-07-15',

  // SPA. Toda jornada da aplicacao exige login e nenhuma precisa de SEO, entao
  // a renderizacao no servidor so acrescentaria um segundo contexto de execucao
  // e o repasse do cookie de sessao entre o servidor Nuxt e o Laravel.
  //
  // O custo aceito e depender de JavaScript na primeira renderizacao, sem HTML
  // pronto — irrelevante em telas privadas.
  //
  // Decisao ja aprovada no plano (§15.1), nao escolha desta etapa.
  ssr: false
})
