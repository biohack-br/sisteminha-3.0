#!/usr/bin/env bash
# consolida.sh — gera consolidado.json a partir de cotacoes.json e quotes_cache.json
set -euo pipefail

DIR="${1:-.}"
COTACOES="$DIR/cotacoes.json"
CACHE="$DIR/quotes_cache.json"
SAIDA="$DIR/consolidado.json"

command -v jq >/dev/null 2>&1 || { echo "Erro: jq não encontrado. Instale com: apt install jq"; exit 1; }
[ -f "$COTACOES" ] || { echo "Erro: $COTACOES não encontrado"; exit 1; }
[ -f "$CACHE" ]    || { echo "Erro: $CACHE não encontrado"; exit 1; }

echo ">> Lendo $COTACOES e $CACHE"

# Converte data dd/mm/aaaa -> aaaa-mm-dd para ordenação correta.
# Itens do quotes_cache usam a data de last_update (dd/mm/aaaa HH:MM:SS).
jq -s '
  # Mapa chave -> {descricao, unidade} para os indicadores do quotes_cache.
  ([
    {chave:"temperatura", descricao:"Indaiatuba / SP", unidade:"Temperatura"},
    {chave:"gasolina",    descricao:"Gasolina",        unidade:"1 Lt"},
    {chave:"petroleo",    descricao:"Petróleo",        unidade:"1 Barril tipo Brent"},
    {chave:"dolar",       descricao:"Dólar",           unidade:"1 Dólar"},
    {chave:"prata",       descricao:"Prata",           unidade:"Onça de prata"},
    {chave:"ouro",        descricao:"Ouro",            unidade:"Onça de Ouro"},
    {chave:"bitcoin",     descricao:"Bitcoin",         unidade:"Unidade Bitcoin"}
  ] | map({key: .chave, value: {descricao: .descricao, unidade: .unidade}}) | from_entries)
  as $mapa

  | .[0] as $cot
  | .[1] as $cache
  | ($cache.last_update // "" | capture("^(?<d>[0-9]{2})/(?<m>[0-9]{2})/(?<y>[0-9]{4})") // {d:"00",m:"00",y:"0000"}) as $lu
  | ($lu.y + "-" + $lu.m + "-" + $lu.d) as $dataCache

  # Bloco 1: cotações (cada item tem data própria)
  | ($cot.cotacoes // [])
    | map({
        data: (
          (.data // "00/00/0000")
          | capture("^(?<d>[0-9]{2})/(?<m>[0-9]{2})/(?<y>[0-9]{4})$")
          | (.y + "-" + .m + "-" + .d)
        ),
        descricao: (
          (.produto // "") +
          (if (.variedade // "") != "" and .variedade != "N/A"
           then " (" + .variedade + ")" else "" end) +
          (if (.categoria // "") != "" then " [" + .categoria + "]" else "" end)
        ),
        valor: (
          if (.valor == null) then null
          else (.valor | tostring) end
        ),
        unidade: (.unidade // ""),
        tipo: "cotacao",
        fonte: ($cot.fonte // "")
      })
  as $itensCot

  # Bloco 2: indicadores do cache (sem data própria -> usam last_update)
  | (
      $cache
      | del(.last_update)
      | to_entries
      | map(
          .key as $k
          | ($mapa[$k] // {descricao:$k, unidade:""}) as $m
          | {
              data: $dataCache,
              descricao: $m.descricao,
              valor: (.value | tostring),
              unidade: $m.unidade,
              tipo: "indicador",
              fonte: "quotes_cache"
            }
        )
    ) as $itensCache

  | ($itensCot + $itensCache)
  | sort_by(.data, .descricao)
  | {
      gerado_em: (now | strftime("%Y-%m-%dT%H:%M:%SZ")),
      total: length,
      itens: .
    }
' "$COTACOES" "$CACHE" > "$SAIDA"

# Verificação rápida
TOTAL=$(jq '.total' "$SAIDA")
echo ">> Consolidado gerado: $SAIDA ($TOTAL itens)"
echo ">> Indicadores:"
jq -r '.itens[] | select(.tipo=="indicador") | "\(.descricao): \(.valor) - \(.unidade)"' "$SAIDA"
echo ">> Primeiros 3 registros:"
jq -r '.itens[:3][] | "\(.data) | \(.descricao) | \(.valor) \(.unidade)"' "$SAIDA"