/**
 * O tema da biblioteca de componentes.
 *
 * So as cores sao declaradas aqui, e o motivo e a legibilidade dos estados: no
 * padrao, `primary` e `success` sao a mesma cor, e um botao de acao fica
 * indistinguivel de uma etiqueta "Disponivel" ou de uma confirmacao. Com a acao
 * fora da faixa reservada aos estados — sucesso, atencao, erro e informacao —,
 * quem usa reconhece o que **pode fazer** sem precisar ler o rotulo.
 *
 * As demais cores continuam nos padroes da biblioteca, que ja sao semanticos. O
 * restante do acabamento vive no CSS de entrada e nos proprios componentes:
 * classe escrita neste arquivo nao e varrida pelo gerador de utilitarios.
 */
export default defineAppConfig({
  ui: {
    colors: {
      primary: 'violet',
      neutral: 'slate',
    },
  },
})
