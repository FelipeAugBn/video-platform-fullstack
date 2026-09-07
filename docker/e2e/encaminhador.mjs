#!/usr/bin/env node

/**
 * Encaminhamento local das origens publicas dentro do container de E2E.
 *
 * ## O problema
 *
 * A aplicacao tem tres enderecos publicos, e eles nao sao um detalhe de
 * configuracao — sao parte do comportamento que este teste precisa exercitar:
 *
 *   http://localhost:3000    a interface
 *   http://localhost:8080    a API
 *   http://localhost:19000   o armazenamento de objetos
 *
 * O cookie de sessao e host-only, entao o navegador so o devolve ao host que o
 * emitiu; o Sanctum trata `localhost:3000` como origem first-party; a politica
 * de CORS autoriza exatamente `http://localhost:3000`; e a URL pre-assinada do
 * envio e assinada sobre o cabecalho `Host: localhost:19000`. Trocar qualquer um
 * desses enderecos por um nome de servico da rede interna quebraria uma dessas
 * quatro coisas — e a que quebrasse primeiro esconderia as outras.
 *
 * Dentro do container, porem, `localhost` e o proprio container: nada escuta
 * nessas portas.
 *
 * ## A solucao
 *
 * Um encaminhamento **de TCP**, e nao de HTTP. Cada porta local aceita a conexao
 * e a repassa byte a byte ao servico correspondente da rede interna. Nada da
 * requisicao e lido, reescrito ou interpretado: a linha de requisicao, o
 * cabecalho `Host`, o `Origin`, os cookies e a assinatura da URL chegam ao
 * destino exatamente como o navegador os produziu.
 *
 * E por isso que o teste prova o comportamento real. Um proxy HTTP que
 * reescrevesse o `Host` faria a assinatura da URL pre-assinada deixar de bater;
 * um `MAP` no resolvedor de nomes do navegador teria o mesmo efeito util, mas
 * dependeria de o Chromium aplicar a regra tambem ao tratamento especial que ele
 * da a `localhost`. O encaminhamento de TCP nao depende de nada disso.
 *
 * As duas familias de endereco sao atendidas: `localhost` resolve para
 * `127.0.0.1` e para `::1`, e o navegador pode escolher qualquer uma das duas.
 * Escutar so numa delas produziria uma recusa intermitente, dificil de
 * distinguir de um servico fora do ar.
 *
 * ## Por que ele tambem lanca o comando
 *
 * Este processo e o ponto de entrada da imagem: prepara as escutas e so entao
 * executa o que veio na linha de comando, repassando o codigo de saida. Assim
 * nao existe janela entre "o navegador comecou" e "o encaminhamento ficou
 * pronto" — e nenhuma espera fixa e necessaria para cobri-la.
 */

import net from 'node:net'
import os from 'node:os'
import process from 'node:process'
import { spawn } from 'node:child_process'

const VARIAVEL = 'E2E_ENCAMINHAMENTOS'

/** `localhost` resolve para as duas, e o navegador escolhe. */
const ENDERECOS_LOCAIS = ['127.0.0.1', '::1']

/** Desfechos rotineiros de uma conexao encerrada por qualquer um dos lados. */
const ENCERRAMENTOS_NORMAIS = new Set(['ECONNRESET', 'EPIPE', 'ERR_STREAM_WRITE_AFTER_END'])

/**
 * Le as regras do ambiente no formato `porta=servico:porta,...`.
 *
 * Toda entrada malformada aborta a execucao. Ignorar uma regra invalida
 * produziria uma recusa de conexao no meio da jornada, com o teste falhando por
 * um sintoma a tres camadas de distancia da causa.
 */
function interpretarRegras(texto) {
  const entradas = texto.split(',').map(parte => parte.trim()).filter(parte => parte !== '')

  if (entradas.length === 0) {
    throw new Error(`${VARIAVEL} esta vazia: nenhuma origem publica seria alcancavel de dentro do container.`)
  }

  return entradas.map((entrada) => {
    const correspondencia = /^(\d+)=([^:]+):(\d+)$/.exec(entrada)

    if (correspondencia === null) {
      throw new Error(`Regra invalida em ${VARIAVEL}: "${entrada}". Formato esperado: porta=servico:porta.`)
    }

    return {
      portaLocal: Number(correspondencia[1]),
      hospedeiro: correspondencia[2],
      porta: Number(correspondencia[3]),
    }
  })
}

/**
 * Abre uma escuta local e conecta cada cliente ao destino interno.
 *
 * O erro de um lado derruba o outro: um socket meio aberto deixaria o navegador
 * esperando por uma resposta que nunca viria, e o teste falharia por tempo
 * esgotado em vez de pelo erro real.
 */
function escutar(endereco, regra) {
  return new Promise((resolver, rejeitar) => {
    const servidor = net.createServer((cliente) => {
      const destino = net.connect({ host: regra.hospedeiro, port: regra.porta })

      const encerrar = (causa) => {
        // Fim de conexao nao e defeito: o navegador fecha um lado, a escrita
        // pendente do outro falha, e isso acontece dezenas de vezes numa
        // navegacao normal. Relatadas, essas linhas encheriam a saida do teste
        // com ruido — e esconderiam a unica que importaria, como uma recusa de
        // conexao com um servico que nao subiu.
        if (causa !== undefined && !ENCERRAMENTOS_NORMAIS.has(causa.code)) {
          process.stderr.write(
            `[encaminhador] ${endereco}:${regra.portaLocal} -> ${regra.hospedeiro}:${regra.porta}: ${causa.message}\n`,
          )
        }

        cliente.destroy()
        destino.destroy()
      }

      cliente.on('error', encerrar)
      destino.on('error', encerrar)

      cliente.pipe(destino)
      destino.pipe(cliente)
    })

    servidor.on('error', rejeitar)

    // `ipv6Only` explicito: sem ele, a escuta em `::1` poderia reivindicar
    // tambem a porta IPv4 em sistemas com pilha dupla, e a escuta seguinte
    // falharia com a porta ja em uso.
    servidor.listen({ host: endereco, port: regra.portaLocal, ipv6Only: endereco === '::1' }, () => {
      resolver(servidor)
    })
  })
}

async function principal() {
  const regras = interpretarRegras(process.env[VARIAVEL] ?? '')

  for (const regra of regras) {
    for (const endereco of ENDERECOS_LOCAIS) {
      await escutar(endereco, regra)
    }

    process.stdout.write(
      `[encaminhador] localhost:${regra.portaLocal} -> ${regra.hospedeiro}:${regra.porta}\n`,
    )
  }

  const [comando, ...argumentos] = process.argv.slice(2)

  if (comando === undefined) {
    throw new Error('Nenhum comando informado ao ponto de entrada da imagem de E2E.')
  }

  const filho = spawn(comando, argumentos, { stdio: 'inherit' })

  // O sinal chega ao ponto de entrada e precisa alcancar quem faz o trabalho:
  // sem o repasse, uma interrupcao deixaria o navegador orfao ate o container
  // ser derrubado.
  for (const sinal of ['SIGINT', 'SIGTERM']) {
    process.on(sinal, () => filho.kill(sinal))
  }

  filho.on('exit', (codigo, sinal) => {
    // Encerrado por sinal nao tem codigo de saida: a convencao de shell soma 128
    // ao numero do sinal, e e o que quem chamou espera ler.
    process.exit(sinal === null ? (codigo ?? 0) : 128 + (os.constants.signals[sinal] ?? 0))
  })
}

principal().catch((causa) => {
  process.stderr.write(`[encaminhador] ${causa instanceof Error ? causa.message : String(causa)}\n`)
  process.exit(1)
})
