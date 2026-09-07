import withNuxt from './.nuxt/eslint.config.mjs'

/*
 * Flat config gerado a partir da propria configuracao do projeto: o lint passa
 * a conhecer os auto-imports do Nuxt, e deixa de acusar `useState` ou
 * `navigateTo` como identificadores indefinidos.
 */
export default withNuxt({
  ignores: [
    // Arquivo gerado a partir do contrato OpenAPI. Reescreve-lo para satisfazer
    // regras de estilo o faria divergir do gerador na proxima execucao, e o
    // conteudo dele nao e escrito por ninguem — e derivado.
    'app/types/api.ts',
  ],
})
