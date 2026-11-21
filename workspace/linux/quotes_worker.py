# quotes_worker.py
import subprocess, json, os, time, threading
from history_db import append_quotes, append_commodities

DIR = os.path.dirname(os.path.abspath(__file__))

def run(cmd, timeout=30):
    try:
        r = subprocess.run(cmd, shell=True, capture_output=True, text=True,
                           timeout=timeout, cwd=DIR)
        out = r.stdout.strip()
        return out if out else "N/A"
    except Exception:
        return "N/A"

def collect_quotes():
    return {
        "dolar":     run("bash dolar.sh"),
        "gasolina":  run("bash gasolina.sh"),
        "petroleo":  run("bash petroleo.sh"),
        "prata":     run("bash prata.sh"),
        "ouro":      run("bash ouro.sh"),
        "bitcoin":   run("bash bitcoin.sh"),
        "clima":     run("bash clima.sh"),
    }

def collect_commodities():
    """Usa commoditties.py — parseia a saída 'Produto R$X unidade'."""
    out = run("python3 commoditties.py", timeout=60)
    snap = {}
    for line in out.splitlines():
        # Ex.: "Arroz R$82,28 sc 50kg"
        if "R$" not in line:
            continue
        try:
            nome, resto = line.split(" R$", 1)
            valor_str = resto.split()[0]
            valor = float(valor_str.replace(".", "").replace(",", "."))
            snap[nome.strip()] = valor
        except Exception:
            continue
    return snap

def loop(interval_seconds=3*3600, stop_event=None):
    while not (stop_event and stop_event.is_set()):
        try:
            q = collect_quotes()
            append_quotes(q)
            c = collect_commodities()
            if c:
                append_commodities(c)
        except Exception as e:
            print(f"[quotes_worker] erro: {e}")
        if stop_event:
            stop_event.wait(interval_seconds)
        else:
            time.sleep(interval_seconds)

def start():
    t = threading.Thread(target=loop, daemon=True)
    t.start()
    return t
