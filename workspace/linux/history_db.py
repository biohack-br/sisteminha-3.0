# history_db.py
import json, os, threading
from datetime import datetime

DB_FILE = os.path.join(os.path.dirname(__file__), "master_history.json")
LOCK = threading.Lock()

def _empty():
    return {
        "meta": {"created": datetime.now().isoformat(), "version": 1},
        "quotes": {},     # {"2026-10-29T13:00:00": {"dolar": "...", ...}}
        "commodities": {},# {"2026-10-29T13:00:00": {"Arroz": 82.28, ...}}
        "news": {}        # {"2026-10-29T13:00:00": {"topic": [...], ...}}
    }

def _load():
    if not os.path.exists(DB_FILE):
        return _empty()
    with open(DB_FILE, "r", encoding="utf-8") as f:
        return json.load(f)

def _save(data):
    tmp = DB_FILE + ".tmp"
    with open(tmp, "w", encoding="utf-8") as f:
        json.dump(data, f, ensure_ascii=False, indent=2)
    os.replace(tmp, DB_FILE)

def append_quotes(snapshot: dict):
    """snapshot = {'dolar': 'R$ 5,41', 'gasolina': 'R$ 6,35', ...}"""
    with LOCK:
        data = _load()
        key = datetime.now().strftime("%Y-%m-%dT%H:%M:00")
        data["quotes"][key] = snapshot
        _save(data)
    return key

def append_commodities(snapshot: dict):
    """snapshot = {'Arroz': 82.28, 'Boi Gordo': 390.65, ...}"""
    with LOCK:
        data = _load()
        key = datetime.now().strftime("%Y-%m-%dT%H:%M:00")
        data["commodities"][key] = snapshot
        _save(data)
    return key

def append_news(snapshot: dict):
    """snapshot = {'crise_alimentar': [...], ...}"""
    with LOCK:
        data = _load()
        key = datetime.now().strftime("%Y-%m-%dT%H:%M:00")
        data["news"][key] = snapshot
        # Retém apenas 72h de notícias para não crescer indefinidamente
        cutoff = (datetime.now().timestamp() - 72*3600)
        data["news"] = {
            k: v for k, v in data["news"].items()
            if datetime.fromisoformat(k).timestamp() >= cutoff
        }
        _save(data)
    return key

def get_series_commodity(produto: str, limit=30):
    """Retorna [(timestamp_str, valor), ...] ordenado cronologicamente."""
    with LOCK:
        data = _load()
    series = []
    for ts, snap in sorted(data["commodities"].items()):
        if produto in snap and snap[produto] not in (None, 0, "N/A"):
            series.append((ts, float(snap[produto])))
    return series[-limit:]

def list_commodities():
    with LOCK:
        data = _load()
    names = set()
    for snap in data["commodities"].values():
        names.update(snap.keys())
    return sorted(names)

def get_latest(key_type="quotes"):
    with LOCK:
        data = _load()
    if not data[key_type]:
        return None
    last_ts = max(data[key_type].keys())
    return last_ts, data[key_type][last_ts]
