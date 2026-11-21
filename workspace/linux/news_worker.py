# news_worker.py
import os, threading, time
from history_db import append_news

DIR = os.path.dirname(os.path.abspath(__file__))

# Importa o coletor de notícias do doomsday.py
import importlib.util
spec = importlib.util.spec_from_file_location("doomsday", os.path.join(DIR, "doomsday.py"))
doomsday = importlib.util.module_from_spec(spec)
spec.loader.exec_module(doomsday)

def loop(interval_seconds=3600, stop_event=None):
    while not (stop_event and stop_event.is_set()):
        try:
            news = doomsday.get_all_news()
            append_news(news)
        except Exception as e:
            print(f"[news_worker] erro: {e}")
        if stop_event:
            stop_event.wait(interval_seconds)
        else:
            time.sleep(interval_seconds)

def start():
    t = threading.Thread(target=loop, daemon=True)
    t.start()
    return t
