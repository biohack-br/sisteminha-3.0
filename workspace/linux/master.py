# master.py
import os, logging, threading
from telegram import Update
from telegram.ext import Updater, CommandHandler, MessageHandler, Filters, CallbackContext

from quotes_worker import start as start_quotes, collect_quotes, collect_commodities
from news_worker import start as start_news
from history_db import append_quotes, append_commodities, list_commodities, get_latest
from trend_analyzer import analyze
import doomsday  # reaproveita get_all_news

logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(message)s")
log = logging.getLogger("master")

TOKEN = os.environ.get("TELEGRAM_TOKEN", "COLOQUE_SEU_TOKEN_AQUI")

# ---------- comandos ----------
def start_cmd(update, ctx):
    update.message.reply_text(
        "🌿 Bot de Monitoramento\n\n"
        "/cotacoes - últimas cotações\n"
        "/comprar <produto> - analisa se é bom momento para comprar\n"
        "/commodities - lista produtos monitorados\n"
        "/noticias - resumo de notícias\n"
        "/atualizar - força coleta agora\n"
        "/historico <produto> - série histórica recente"
    )

def cotacoes_cmd(update, ctx):
    from history_db import get_latest
    ts, snap = get_latest("quotes") or (None, {})
    if not snap:
        update.message.reply_text("Sem dados ainda. Use /atualizar.")
        return
    txt = f"💱 Cotações ({ts})\n\n"
    for k, v in snap.items():
        txt += f"{k.capitalize()}: {v}\n"
    update.message.reply_text(txt)

def comprar_cmd(update, ctx):
    if not ctx.args:
        update.message.reply_text("Uso: /comprar Arroz")
        return
    produto = " ".join(ctx.args)
    # tenta casar com nome exato ou aproximado
    disponiveis = list_commodities()
    match = next((c for c in disponiveis if produto.lower() in c.lower()), None)
    if not match:
        update.message.reply_text(
            f"Não achei '{produto}'.\nDisponíveis: {', '.join(disponiveis[:15])}..."
        )
        return
    r = analyze(match)
    txt = (
        f"📊 {match}\n"
        f"Tendência: {r['tendencia']}\n"
        f"Atual: R$ {r['atual']}\n"
        f"Mín: R$ {r['min']} | Máx: R$ {r['max']}\n"
        f"Variação média: {r['variacao_pct']}%\n"
        f"Amostras: {r['amostras']}\n\n"
        f"{r['recomendacao']}"
    )
    update.message.reply_text(txt)

def commodities_cmd(update, ctx):
    lst = list_commodities()
    if not lst:
        update.message.reply_text("Nenhuma commodity coletada ainda.")
        return
    update.message.reply_text("📦 Monitoradas:\n" + "\n".join(f"• {c}" for c in lst))

def noticias_cmd(update, ctx):
    from history_db import _load
    data = _load()
    if not data["news"]:
        update.message.reply_text("Sem notícias ainda. Use /atualizar.")
        return
    last_ts = max(data["news"].keys())
    snap = data["news"][last_ts]
    txt = f"📰 Notícias ({last_ts})\n\n"
    for tema, items in snap.items():
        if items:
            txt += f"• {tema.replace('_',' ').title()}: {items[0][:70]}...\n"
    update.message.reply_text(txt[:4000])

def atualizar_cmd(update, ctx):
    update.message.reply_text("🔄 Coletando agora...")
    append_quotes(collect_quotes())
    append_commodities(collect_commodities())
    update.message.reply_text("✅ Cotações atualizadas. Notícias rodam em background.")

def historico_cmd(update, ctx):
    if not ctx.args:
        update.message.reply_text("Uso: /historico Arroz")
        return
    from history_db import get_series_commodity
    serie = get_series_commodity(" ".join(ctx.args), limit=20)
    if not serie:
        update.message.reply_text("Sem histórico para esse produto.")
        return
    txt = "\n".join(f"{ts}: R$ {v}" for ts, v in serie)
    update.message.reply_text(txt[:4000])

def handle_msg(update, ctx):
    txt = (update.message.text or "").lower()
    if "bom momento" in txt or "comprar" in txt:
        # extrai o produto depois de "comprar"
        for p in list_commodities():
            if p.lower() in txt:
                ctx.args = p.split()
                comprar_cmd(update, ctx)
                return
    update.message.reply_text("Use /cotacoes, /comprar <produto> ou /noticias.")

# ---------- main ----------
def main():
    # primeiro snapshot síncrono (para já ter dados ao subir)
    append_quotes(collect_quotes())
    append_commodities(collect_commodities())

    # workers em background
    start_quotes()   # 3h
    start_news()     # 1h

    updater = Updater(TOKEN, use_context=True)
    d = updater.dispatcher
    d.add_handler(CommandHandler("start", start_cmd))
    d.add_handler(CommandHandler("cotacoes", cotacoes_cmd))
    d.add_handler(CommandHandler("comprar", comprar_cmd))
    d.add_handler(CommandHandler("commodities", commodities_cmd))
    d.add_handler(CommandHandler("noticias", noticias_cmd))
    d.add_handler(CommandHandler("atualizar", atualizar_cmd))
    d.add_handler(CommandHandler("historico", historico_cmd))
    d.add_handler(MessageHandler(Filters.text & ~Filters.command, handle_msg))

    log.info("Bot iniciado.")
    updater.start_polling()
    updater.idle()

if __name__ == "__main__":
    main()
