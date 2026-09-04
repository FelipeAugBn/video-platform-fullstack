#!/bin/sh
#
# Garante uma APP_KEY valida no arquivo de ambiente da raiz.
#
#     scripts/ensure-app-key.sh [caminho-do-.env]
#
# A chave assina o cookie de sessao e cifra o que o framework guarda nele. Sem
# ela nao ha autenticacao — e por isso ela passa a ser exigida a partir do
# momento em que existe login, e nao antes.
#
# Tres propriedades governam este script:
#
#   1. **Nao substitui chave existente.** Uma chave ja preenchida e a
#      configuracao real da maquina; troca-la invalidaria toda sessao ativa e
#      todo dado cifrado com ela. Rodar duas vezes seguidas nao muda nada.
#
#   2. **Nao imprime o valor.** Nem em sucesso, nem em erro, nem em modo
#      detalhado. Segredo que aparece no terminal acaba no historico do shell e
#      no log de quem executou.
#
#   3. **Nao deixa arquivo pela metade.** A escrita acontece num temporario e o
#      arquivo final e substituido de uma vez, com as permissoes preservadas.
#      Uma interrupcao no meio deixa o `.env` original intacto.
#
# A chave e gerada dentro da imagem do backend, por PHP: o host desta entrega
# nao tem PHP nem OpenSSL, e depender de qualquer um dos dois tornaria a
# preparacao dependente da maquina.

set -eu

AMBIENTE="${1:-.env}"
IMAGEM="${BACKEND_IMAGE:-video-platform-backend:dev}"

erro() {
  echo "app-key: $*" >&2
  exit 1
}

[ -f "$AMBIENTE" ] || erro "arquivo de ambiente nao encontrado em '$AMBIENTE'.
  Crie-o a partir do exemplo antes de subir o ambiente:

      cp .env.example .env
"

# ---------------------------------------------------------------------------
# 1. Quantas declaracoes de APP_KEY existem?
#
# Contar vem antes de ler, e nao e preciosismo. Com duas linhas ativas, qual
# delas vale depende de quem interpreta o arquivo — e as respostas divergem entre
# ferramentas. Preencher uma e deixar a outra produziria um ambiente cuja chave
# muda conforme quem le, o que e pior do que nao ter chave: falha
# intermitentemente, e nao de imediato.
#
# Comentario nao conta: linhas iniciadas por `#` nao casam com o padrao.
# ---------------------------------------------------------------------------
declaracoes="$(grep -cE '^[[:space:]]*APP_KEY[[:space:]]*=' "$AMBIENTE" || true)"

if [ "$declaracoes" -gt 1 ]; then
  erro "ha $declaracoes linhas APP_KEY ativas em '$AMBIENTE'.
  Qual delas vale depende de quem le o arquivo, e o ambiente ficaria com uma
  chave diferente conforme a ferramenta. Nenhum valor foi alterado e nenhum foi
  exibido: deixe uma unica linha APP_KEY e execute de novo."
fi

# ---------------------------------------------------------------------------
# 2. A unica declaracao ja esta preenchida?
#
# Presente e qualquer caractere depois do sinal de igual, ignorando espacos.
# ---------------------------------------------------------------------------
valor_atual="$(sed -n 's/^[[:space:]]*APP_KEY[[:space:]]*=[[:space:]]*//p' "$AMBIENTE" | head -n 1)"

if [ -n "$valor_atual" ]; then
  echo "app-key: ja definida em $AMBIENTE, preservada"
  exit 0
fi

# ---------------------------------------------------------------------------
# 3. Geracao.
#
# 32 bytes de `random_bytes`, a fonte criptografica do proprio PHP, no formato
# `base64:` que o framework espera. O container e descartavel e nao precisa de
# rede, banco nem da arvore da aplicacao: so do interpretador.
#
# `--no-deps` nao se aplica aqui porque nao usamos o Compose — `docker run`
# direto evita depender de um arquivo de servicos que pode nem ter sido lido
# ainda.
# ---------------------------------------------------------------------------
docker image inspect "$IMAGEM" >/dev/null 2>&1 \
  || erro "imagem '$IMAGEM' nao encontrada.
  Ela e construida junto do ambiente; rode a preparacao completa em vez deste
  script isoladamente."

chave="$(docker run --rm --network none "$IMAGEM" \
  php -r 'echo "base64:" . base64_encode(random_bytes(32));' 2>/dev/null)" \
  || erro "falha ao gerar a chave dentro da imagem do backend"

# Conferencia antes de escrever: uma chave malformada gravada no arquivo so
# apareceria adiante, como erro de inicializacao sem causa obvia.
case "$chave" in
  base64:*) ;;
  *) erro "a chave gerada nao tem o formato esperado" ;;
esac

[ "${#chave}" -eq 51 ] || erro "a chave gerada nao representa 32 bytes"

# ---------------------------------------------------------------------------
# 4. Escrita atomica.
#
# `sed` nao serve aqui: a chave contem `/` e `+` do alfabeto base64, que teriam
# de ser escapados, e um escape esquecido produz um valor silenciosamente
# errado. A substituicao e feita linha a linha, comparando o inicio da linha, e
# o valor entra por variavel de ambiente em vez de compor o programa.
# ---------------------------------------------------------------------------
temporario="$(mktemp "${TMPDIR:-/tmp}/app-key.XXXXXX")"
trap 'rm -f "$temporario"' EXIT INT TERM

if [ "$declaracoes" -eq 1 ]; then
  CHAVE="$chave" awk '
    !feito && /^[[:space:]]*APP_KEY[[:space:]]*=/ { print "APP_KEY=" ENVIRON["CHAVE"]; feito = 1; next }
    { print }
  ' "$AMBIENTE" > "$temporario"
else
  cat "$AMBIENTE" > "$temporario"
  # Uma linha em branco separa o acrescimo do bloco anterior quando o arquivo
  # nao termina em quebra de linha.
  [ -s "$temporario" ] && [ "$(tail -c 1 "$temporario" | wc -l)" -eq 0 ] && echo >> "$temporario"
  {
    echo ""
    echo "# Chave de criptografia da aplicacao, gerada localmente na primeira subida."
    echo "# Nao versionada e nao compartilhada: cada maquina tem a sua."
    echo "APP_KEY=$chave"
  } >> "$temporario"
fi

# Preserva o dono e as permissoes do arquivo original em vez de herdar as do
# temporario, que nasce restrito ao usuario atual.
if [ -r "$AMBIENTE" ]; then
  chmod --reference="$AMBIENTE" "$temporario" 2>/dev/null || chmod 600 "$temporario"
fi

mv "$temporario" "$AMBIENTE"
trap - EXIT INT TERM

echo "app-key: gerada e gravada em $AMBIENTE"
