#!/usr/bin/env python3
"""
Extrator de cotacoes IndicAgro - saida compacta para SMS.
Sem variacao (alta/baixa), sem emojis.
"""

import re
import requests
from bs4 import BeautifulSoup
from decimal import Decimal, InvalidOperation
from dataclasses import dataclass
from typing import List, Optional

URL = "https://indicagro.com.br/cotacoes"
HEADERS = {"User-Agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36"}
TIMEOUT = 20

LIMITE_SMS = 160
MAX_COTACOES_SMS = 10


@dataclass
class Cotacao:
    produto: str
    variedade: str
    categoria: str
    valor: Optional[Decimal]
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

        resultado.append(Cotacao(produto, variedade, categoria, valor, unidade, data))

    return resultado


def formatar_valor(v: Optional[Decimal]) -> str:
    if v is None:
        return "-"
    s = f"{v:,.2f}".replace(",", "X").replace(".", ",").replace("X", ".")
    return f"R${s}"


def linha_sms(c: Cotacao) -> str:
    return f"{c.produto} {formatar_valor(c.valor)} {c.unidade}"


def gerar_sms(cotacoes: List[Cotacao], max_itens: int = MAX_COTACOES_SMS) -> List[str]:
    mensagens: List[str] = []
    buffer = ""

    for c in cotacoes[:max_itens]:
        linha = linha_sms(c)
        tentativa = (buffer + "\n" + linha).strip() if buffer else linha
        if len(tentativa) > LIMITE_SMS:
            if buffer:
                mensagens.append(buffer)
            buffer = linha
        else:
            buffer = tentativa

    if buffer:
        mensagens.append(buffer)

    return mensagens


def main():
    try:
        resp = requests.get(URL, headers=HEADERS, timeout=TIMEOUT)
        resp.raise_for_status()
    except requests.RequestException as e:
        print(f"Erro na consulta: {e}")
        return

    cotacoes = extrair_cotacoes(resp.text)

    if not cotacoes:
        print("Nenhuma cotacao encontrada.")
        return

    print("IndicAgro - Cotacoes")
    print("")

    for c in cotacoes:
        print(linha_sms(c))

    print("")

if __name__ == "__main__":
    main()
