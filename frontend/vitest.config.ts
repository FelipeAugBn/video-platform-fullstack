import { defineVitestConfig } from '@nuxt/test-utils/config'

/*
 * Ambiente Nuxt nos testes: os auto-imports, o `runtimeConfig` e o contexto da
 * aplicacao ficam disponiveis, e o que se testa e o codigo como ele roda — nao
 * uma copia adaptada para o teste.
 *
 * DOM leve em vez do completo: nenhum teste desta etapa depende de layout,
 * medicao ou navegacao real, e o mais pesado custaria segundos por arquivo sem
 * mudar nenhuma afirmacao.
 */
export default defineVitestConfig({
  test: {
    environment: 'nuxt',
    environmentOptions: {
      nuxt: {
        domEnvironment: 'happy-dom',
      },
    },
  },
})
