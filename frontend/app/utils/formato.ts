/*
| O formatador e criado uma vez, e nao a cada chamada: montar um
| `Intl.DateTimeFormat` custa mais que formatar com ele, e uma lista de cursos
| chamaria o construtor uma vez por linha.
*/
const DATA_E_HORA = new Intl.DateTimeFormat('pt-BR', {
  dateStyle: 'short',
  timeStyle: 'short',
})

/**
 * Um instante ISO 8601 do contrato, no formato local de quem le.
 *
 * A API devolve sempre com deslocamento explicito, e a conversao para o fuso do
 * navegador e feita aqui — nunca fatiando a string, que trataria o horario de
 * outro fuso como se fosse local.
 *
 * Valor irreconhecivel volta como veio. `Invalid Date` na tela nao explica nada
 * a quem le, e esconderia do responsavel pelo repositorio o valor que a API
 * realmente mandou.
 */
export function formatarDataHora(iso: string): string {
  const instante = new Date(iso)

  return Number.isNaN(instante.getTime()) ? iso : DATA_E_HORA.format(instante)
}
