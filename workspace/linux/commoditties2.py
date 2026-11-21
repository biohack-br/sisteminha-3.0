#!/usr/bin/env python3
"""
Extrator de cotacoes IndicAgro - JSON limpo.
Sem variacao (alta/baixa), sem emojis.
Com delay de 5s entre requisicoes.
"""

import re
import json
import time
import requests
from bs4 import BeautifulSoup
from decimal import Decimal, InvalidOperation
from dataclasses import dataclass, asdict
from typing import List, Optional

URL = "https://indicagro.com.br/cotacoes"
HEADERS = {"User-Agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36"}
TIMEOUT = 20
DELAY_SEGUNDOS = 5  # <-- delay entre blocos/requisicoes

ARQUIVO_JSON = "cotacoes.json"


@dataclass
class Cotacao:
    produto: str
    variedade: str
    categoria: str
    valor: Optional[float]
    unidade: str
    data: str


def parse_valor(texto: str) -> Optional[Decimal]:
    if not texto:
        return None
    m = re.search(r"R\$\s*([\d.,]+)", texto)
    if not m:
        return None
    try:
        return Decimal(m.group(1).replace(".", "").replace(",", "."))
    except InvalidOperation:
        return None


def limpar(txt: str) -> str:
    return re.sub(r"\s+", " ", (txt or "")).strip()


def extrair_cotacoes(html: str) -> List[Cotacao]:
    soup = BeautifulSoup(html, "html.parser")
    resultado: List[Cotacao] = []
    vistos = set()

    for link in soup.select('a[href^="/cotacoes/"]'):
        card = link.select_one('[data-slot="card"]')
        if not card:
            continue

        h3 = card.select_one("h3")
        if not h3:
            continue
        produto = limpar(h3.get_text())

        header = card.select_one('[data-slot="card-header"]')
        variedade = ""
        categoria = ""
        if header:
            p = header.select_one("p")
            if p:
                variedade = limpar(p.get_text())
            badge = header.select_one('span[class*="bg-chart-"]')
            if badge:
                categoria = limpar(badge.get_text())

        content = card.select_one('[data-slot="card-content"]')
        if not content:
            continue

        valor_texto = ""
        unidade = ""
        baseline = content.select_one("div.items-baseline")
        if baseline:
            spans = baseline.find_all("span", recursive=False)
            if spans:
                valor_texto = limpar(spans[0].get_text())
            if len(spans) > 1:
                unidade = limpar(spans[1].get_text()).lstrip("/").strip()

        valor = parse_valor(valor_texto)

        data = ""
        info = content.select_one("div.justify-between")
        if info:
            data_tag = info.select_one("span.text-xs")
            if data_tag:
                data = limpar(data_tag.get_text())

        chave = (produto, link.get("href", ""))
        if chave in vistos:
            continue
        vistos.add(chave)

        resultado.append(
            Cotacao(
                produto=produto,
                variedade=variedade,
                categoria=categoria,
                valor=float(valor) if valor is not None else None,
                unidade=unidade,
                data=data,
            )
        )

    return resultado


def fetch_pagina(url: str) -> Optional[str]:
    """Faz uma requisicao com delay de 5s antes de executar."""
    print(f"[delay] Aguardando {DELAY_SEGUNDOS}s antes de requisitar {url}...")
    time.sleep(DELAY_SEGUNDOS)  # <-- DELAY DE 5 SEGUNDOS AQUI
    try:
        resp = requests.get(url, headers=HEADERS, timeout=TIMEOUT)
        resp.raise_for_status()
        return resp.text
    except requests.RequestException as e:
        print(f"Erro na consulta: {e}")
        return None


def montar_json(cotacoes: List[Cotacao]) -> dict:
    """Monta a estrutura do JSON."""
    return {
        "fonte": URL,
        "total": len(cotacoes),
        "cotacoes": [asdict(c) for c in cotacoes],
    }


def salvar_json(dados: dict, caminho: str = ARQUIVO_JSON) -> None:
    with open(caminho, "w", encoding="utf-8") as f:
        json.dump(dados, f, ensure_ascii=False, indent=2)
    print(f"Arquivo salvo: {caminho}")


def main():
    html = fetch_pagina(URL)
    if html is None:
        return

    cotacoes = extrair_cotacoes(html)

    if not cotacoes:
        print("Nenhuma cotacao encontrada.")
        return

    dados = montar_json(cotacoes)

    # Imprime o JSON no terminal
    print(json.dumps(dados, ensure_ascii=False, indent=2))

    # Salva em arquivo
    salvar_json(dados)


if __name__ == "__main__":
    main()
