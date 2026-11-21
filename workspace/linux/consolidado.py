import json
from datetime import datetime
from pathlib import Path

MASTER_FILE = Path("cotacoes_master.json")
AGRO_FILE = Path("cotacoes.json")
MERCADO_FILE = Path("quotes_cache.json")


def carregar_master():
    if MASTER_FILE.exists():
        with open(MASTER_FILE, "r", encoding="utf-8") as f:
            return json.load(f)
    return {"ultima_atualizacao": None, "historico": {}}


def salvar_master(data):
    tmp = MASTER_FILE.with_suffix(".tmp")
    with open(tmp, "w", encoding="utf-8") as f:
        json.dump(data, f, ensure_ascii=False, indent=2)
    tmp.replace(MASTER_FILE)


def consolidar():
    with open(AGRO_FILE, "r", encoding="utf-8") as f:
        agro = json.load(f)
    with open(MERCADO_FILE, "r", encoding="utf-8") as f:
        mercado = json.load(f)

    agora = datetime.now().isoformat(timespec="seconds")

    master = carregar_master()

    mercado.pop("last_update", None)

    master["historico"][agora] = {
        "cotacoes_agro": agro,
        "indicadores_mercado": mercado,
    }
    master["ultima_atualizacao"] = agora

    salvar_master(master)
    print(f"Consolidacao em {agora} - total de snapshots: {len(master['historico'])}")


def ultimo_snapshot():
    master = carregar_master()
    if not master["ultima_atualizacao"]:
        return None
    return master["historico"][master["ultima_atualizacao"]]


def serie_indicador(campo):
    master = carregar_master()
    return {
        ts: snap["indicadores_mercado"].get(campo)
        for ts, snap in sorted(master["historico"].items())
    }


if __name__ == "__main__":
    consolidar()
