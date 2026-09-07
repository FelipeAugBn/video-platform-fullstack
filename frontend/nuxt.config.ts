// https://nuxt.com/docs/api/configuration/nuxt-config
export default defineNuxtConfig({
  modules: [
    // Biblioteca principal de componentes, sobre Tailwind CSS 4 (plan §15.5).
    // Uma so, de proposito: duas bibliotecas com a mesma responsabilidade
    // acabam disputando estilo, foco e acessibilidade nos mesmos elementos.
    '@nuxt/ui',
    // Gera o flat config do ESLint a partir da configuracao do projeto, para o
    // lint conhecer os auto-imports em vez de acusar cada um como indefinido.
    '@nuxt/eslint',
  ],

  // O CSS global carrega Tailwind e os estilos da biblioteca. E o unico ponto
  // de entrada de estilo da aplicacao.
  css: ['~/assets/css/main.css'],

  compatibilityDate: '2025-07-15',

  // SPA. Toda jornada da aplicacao exige login e nenhuma precisa de SEO, entao
  // a renderizacao no servidor so acrescentaria um segundo contexto de execucao
  // e o repasse do cookie de sessao entre o servidor Nuxt e o Laravel.
  //
  // O custo aceito e depender de JavaScript na primeira renderizacao, sem HTML
  // pronto — irrelevante em telas privadas.
  //
  // Decisao ja aprovada no plano (§15.1), nao escolha desta etapa.
  ssr: false,

  runtimeConfig: {
    public: {
      /*
      | Endereco publico da API, preenchido por `NUXT_PUBLIC_API_BASE`.
      |
      | Vazio por padrao, e nao com um endereco embutido: um valor plausivel
      | escrito aqui sobreviveria a uma variavel esquecida, e a aplicacao
      | apontaria em silencio para o lugar errado. Vazio, ela falha na primeira
      | requisicao, que e onde o descuido fica visivel.
      |
      | Esta chave e **publica**: ela viaja no pacote entregue ao navegador.
      | Nenhum segredo entra aqui (RF-AUT-006).
      */
      apiBase: '',
    },
  },

  typescript: {
    strict: true,
    // A verificacao roda por comando proprio (`npm run typecheck`), e nao a
    // cada build: ligada aqui, ela custaria tempo em toda subida do servidor de
    // desenvolvimento sem acrescentar garantia — a pipeline executa o comando.
    typeCheck: false,
  },
})
