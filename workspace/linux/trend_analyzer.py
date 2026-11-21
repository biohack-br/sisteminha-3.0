# trend_analyzer.py
from history_db import get_series_commodity

def analyze(produto: str, janela: int = 6):
    series = get_series_commodity(produto, limit=janela)
    if len(series) < 3:
        return {
            "produto": produto,
            "tendencia": "INDEFINIDA",
            "motivo": f"Apenas {len(series)} amostras; mínimo 3 para análise.",
            "recomendacao": "Aguarde mais coletas (o bot grava de 3 em 3 horas).",
            "variacao_pct": None,
            "atual": series[-1][1] if series else None,
        }

    valores = [v for _, v in series]
    atual = valores[-1]
    media_antiga = sum(valores[:-1]) / len(valores[:-1])
    variacao = ((atual - media_antiga) / media_antiga) * 100

    # Regressão linear simples para direção
    n = len(valores)
    xs = list(range(n))
    media_x = sum(xs) / n
    media_y = sum(valores) / n
    num = sum((xs[i]-media_x)*(valores[i]-media_y) for i in range(n))
    den = sum((xs[i]-media_x)**2 for i in range(n)) or 1
    slope = num / den
    slope_pct = (slope / media_y) * 100 if media_y else 0

    if slope_pct < -0.5:
        tendencia = "QUEDA"
        rec = "📉 Tendência de QUEDA — historicamente bom momento para comprar."
    elif slope_pct > 0.5:
        tendencia = "ALTA"
        rec = "📈 Tendência de ALTA — considere aguardar ou comprar só o necessário."
    else:
        tendencia = "ESTÁVEL"
        rec = "➖ Tendência ESTÁVEL — sem vantagem clara de timing."

    return {
        "produto": produto,
        "tendencia": tendencia,
        "variacao_pct": round(variacao, 2),
        "slope_pct": round(slope_pct, 3),
        "atual": atual,
        "min": min(valores),
        "max": max(valores),
        "amostras": n,
        "primeira": series[0][0],
        "ultima": series[-1][0],
        "recomendacao": rec,
    }
