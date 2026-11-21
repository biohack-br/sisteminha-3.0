# -*- coding: utf-8 -*-
import os
import sys
import json
import pickle
import re
import logging
import threading
import time
import warnings
import urllib.parse
from datetime import datetime, timedelta

warnings.filterwarnings("ignore")

import requests
from bs4 import BeautifulSoup as soup
from urllib.request import urlopen, Request
import telebot
from telebot import types

try:
    from googlenewsdecoder import gnewsdecoder
    _HAS_DECODER = True
except ImportError:
    _HAS_DECODER = False

# ===== CONFIGURAÇÕES (embutidas no arquivo) =====
TELEGRAM_TOKEN = "9103818483:AAEag_uxqxHDdtb4txFSN6PdraT4NUabRcE"

API_KEY = "UPER/zP1vJUBX64liMh/GNomfoPwU1MmFHM8Im9mw/wO4lM1CoVis/P8zdz4Yve99YUkO6I6I1VBRb4rN7UynCLDTrArkUPuR+f7dZWLFnXaT8grfgedcngxzbN467RUY/bfgwPSQYwjtxnWLNXc"
LLM7_BASE_URL = "https://api.llm7.io/v1"
API_URL = LLM7_BASE_URL + "/chat/completions"
MODEL = "fast"
LLM7_TIMEOUT = 90

CYCLE_SECONDS = 900  # 15 minutos
DEBUG = os.environ.get("DEBUG") == "1"

# ===== URLS =====
WORLD_NEWS_URL = (
    "https://news.google.com/rss/topics/"
    "CAAqKggKIiRDQkFTRlFvSUwyMHZNRGx1YlY4U0JYQjBMVUpTR2dKQ1VpZ0FQAQ?"
    "hl=pt-BR&gl=BR&ceid=BR:pt-419"
)
BRAZIL_NEWS_URL = (
    "https://news.google.com/rss/topics/"
    "CAAqJQgKIh9DQkFTRVFvSUwyMHZNREUxWm5JU0JYQjBMVUpTS0FBUAE?"
    "hl=pt-BR&gl=BR&ceid=BR%3Apt-419"
)

# ===== TOPICOS FIXOS =====
TOPICS = {
    "crise_alimentar": "falta de alimentos escassez fome",
    "crise_energia": "apagao falta de energia racionamento",
    "crise_agua": "racionamento falta de agua seca",
    "guerras": "guerra conflito ataque tensao",
    "terrorismo": "ataque terrorista atentado",
    "pandemia": "surto doenca epidemia virus",
    "desastre_natural": "terremoto furacao inundacao tornado",
    "colapso_economico": "crise bancaria hiperinflacao recessao",
    "ciberataque": "ataque cibernetico ransomware hacker",
    "refugiados": "refugiados migracao crise humanitaria",
    "greve": "greve paralisacao manifestacao",
    "nuclear": "acidente nuclear radioativo usina",
    "climatico": "mudanca climatica evento extremo temperatura",
    "petroleo": "petroleo preco opep corte",
    "greve_caminhoneiros": "greve caminhoneiro paralisacao bloqueio",
    "virus": "covid h1n1 h5n1 ebola hantavirus surto",
    "secas": "seca estiagem escassez agua reservatorio",
    "tempestades": "tempestade alagamento chuva enchente",
    "terremotos": "terremoto furacao tufao tsunami",
    "incendios": "incendio queimada fogo florestal",
    "inundacoes": "inundacao enchente alagamento",
    "desabamento": "desabamento deslizamento barreira mina",
    "acidentes": "acidente tragedia explosao incendio",
    "seguranca": "seguranca ataque roubo assalto violencia",
    "saude": "saude sus medicamento vacina doenca",
    "educacao": "educacao escola universidade ensino",
    "transporte": "transporte onibus metro ferrovia acidente",
}

TOPIC_NAMES = {
    "crise_alimentar": "Crise Alimentar",
    "crise_energia": "Crise Energetica",
    "crise_agua": "Crise Hidrica",
    "guerras": "Guerras e Conflitos",
    "terrorismo": "Terrorismo",
    "pandemia": "Pandemias",
    "desastre_natural": "Desastres Naturais",
    "colapso_economico": "Colapso Economico",
    "ciberataque": "Ciberataques",
    "refugiados": "Refugiados",
    "greve": "Greves",
    "nuclear": "Acidentes Nucleares",
    "climatico": "Mudancas Climaticas",
    "petroleo": "Petroleo",
    "greve_caminhoneiros": "Greve dos Caminhoneiros",
    "virus": "Virus",
    "secas": "Secas e Estiagem",
    "tempestades": "Tempestades e Alagamentos",
    "terremotos": "Terremotos e Furacoes",
    "incendios": "Incendios Florestais",
    "inundacoes": "Inundacoes",
    "desabamento": "Desabamentos",
    "acidentes": "Acidentes",
    "seguranca": "Seguranca",
    "saude": "Saude",
    "educacao": "Educacao",
    "transporte": "Transporte",
}

WATCHED_KEYWORDS = [
    "Ebola", "pandemia", "guerra", "crise alimentar", "petroleo",
    "H5N1", "Nipah", "hantavirus", "Marburg", "dengue",
]

# ===== ARQUIVOS =====
MEMORY_FILE = "Gabriel_memory.brain"
CONTEXT_FILE = "Gabriel_context.json"
NEWS_CACHE_FILE = "Gabriel_news_cache.json"
DYNAMIC_CACHE_FILE = "Gabriel_dynamic_cache.json"

# ===== LOGGING =====
logging.basicConfig(
    format='%(asctime)s - %(levelname)s - %(message)s',
    level=logging.DEBUG if DEBUG else logging.WARNING
)
logger = logging.getLogger(__name__)
logger.setLevel(logging.DEBUG if DEBUG else logging.INFO)
logging.getLogger("urllib3").setLevel(logging.WARNING)


# ===== HELPERS =====
_EMOJI_RE = re.compile(
    "[\U0001F000-\U0001FAFF\U00002600-\U000027BF\U0001F1E6-\U0001F1FF"
    "\U00002190-\U000021FF\U00002B00-\U00002BFF\uFE0F\u200D]"
)

def strip_emoji(s):
    return _EMOJI_RE.sub("", s or "").strip()


def _now():
    return datetime.now().strftime("%Y-%m-%d %H:%M:%S")


def _next_run(seconds):
    return (datetime.now() + timedelta(seconds=seconds)).strftime("%Y-%m-%d %H:%M:%S")


def decode_google_link(google_link):
    """Decodifica link do Google News para a URL do site externo."""
    if not google_link or "news.google.com" not in google_link:
        return google_link
    if not _HAS_DECODER:
        return google_link
    try:
        result = gnewsdecoder(google_link, interval=1)
        if result.get("success") and result.get("decoded_url"):
            return result["decoded_url"]
    except Exception as e:
        logger.debug("Falha ao decodificar %s: %s", google_link, e)
    return google_link


# ===== GERENCIADOR DE NOTICIAS =====
class NewsManager:
    def __init__(self, cache_file=NEWS_CACHE_FILE):
        self.cache_file = cache_file
        self.news_data = {}
        self.last_update = None
        self.update_interval = CYCLE_SECONDS
        self.load_cache()

    def load_cache(self):
        try:
            if os.path.exists(self.cache_file):
                with open(self.cache_file, 'r', encoding='utf-8') as f:
                    data = json.load(f)
                    self.news_data = data.get('news', {})
                    last_update_str = data.get('last_update')
                    if last_update_str:
                        self.last_update = datetime.fromisoformat(last_update_str)
                logger.debug("Cache carregado: %d topicos", len(self.news_data))
        except Exception as e:
            logger.error("Erro ao carregar cache: %s", e)
            self.news_data = {}
            self.last_update = None

    def save_cache(self):
        try:
            with open(self.cache_file, 'w', encoding='utf-8') as f:
                json.dump({
                    'news': self.news_data,
                    'last_update': self.last_update.isoformat() if self.last_update else None
                }, f, indent=2, ensure_ascii=False)
        except Exception as e:
            logger.error("Erro ao salvar cache: %s", e)

    def needs_update(self):
        if self.last_update is None:
            return True
        return (datetime.now() - self.last_update).total_seconds() >= self.update_interval

    def _extract_items(self, xml_bytes, limit=10):
        s = soup(xml_bytes, "xml")
        items = []
        for it in s.findAll("item")[:limit]:
            title = ""
            if it.title and it.title.text:
                title = strip_emoji(it.title.text.split(" - ")[0])
            link = it.link.text if it.link and it.link.text else ""
            if title:
                items.append((title, link))
        return items

    def _fetch_rss(self, query, limit=10):
        encoded = urllib.parse.quote(query)
        url = "https://news.google.com/rss/search?q=%s&hl=pt-BR&gl=BR&ceid=BR:pt-419" % encoded
        req = Request(url, headers={"User-Agent": "Mozilla/5.0"})
        xml = urlopen(req, timeout=15).read()
        return self._extract_items(xml, limit)

    def search_news(self, query, limit=10):
        try:
            return self._fetch_rss(query, limit)
        except Exception as e:
            logger.error("Erro ao buscar noticias: %s", e)
            return []

    def update_all_news(self):
        logger.debug("Atualizando base de topicos...")
        for topic_key, query in TOPICS.items():
            try:
                items = self._fetch_rss(query, 10)
                if not items:
                    items = [("Noticia sobre %s" % TOPIC_NAMES.get(topic_key, topic_key), "")]
                self.news_data[topic_key] = items
                logger.debug("Atualizado: %s - %d noticias", topic_key, len(items))
            except Exception as e:
                logger.error("Erro ao atualizar %s: %s", topic_key, e)
                if topic_key not in self.news_data:
                    self.news_data[topic_key] = [("Erro ao carregar noticias", "")]
        self.last_update = datetime.now()
        self.save_cache()
        logger.debug("Base de topicos atualizada.")

    def count_failures(self):
        erros = 0
        for items in self.news_data.values():
            if not items or (items[0][0] and "Erro" in items[0][0]):
                erros += 1
        return erros

    def get_world_news(self, limit=10):
        try:
            req = Request(WORLD_NEWS_URL, headers={"User-Agent": "Mozilla/5.0"})
            xml = urlopen(req, timeout=15).read()
            return self._extract_items(xml, limit)
        except Exception as e:
            logger.error("Erro ao buscar noticias mundiais: %s", e)
            return [("Erro ao buscar noticias mundiais.", "")]

    def get_brazil_news(self, limit=10):
        try:
            req = Request(BRAZIL_NEWS_URL, headers={"User-Agent": "Mozilla/5.0"})
            xml = urlopen(req, timeout=15).read()
            return self._extract_items(xml, limit)
        except Exception as e:
            logger.error("Erro ao buscar noticias do Brasil: %s", e)
            return [("Erro ao buscar noticias do Brasil.", "")]

    def get_news_summary(self, limit=5):
        if self.needs_update():
            self.update_all_news()
        world = self.get_world_news(limit)
        brazil = self.get_brazil_news(limit)
        summary = "NOTICIAS ATUAIS (%s)\n\n" % datetime.now().strftime('%d/%m/%Y %H:%M')
        summary += "MUNDO:\n"
        for i, (t, _) in enumerate(world[:limit], 1):
            summary += "%d. %s\n" % (i, t)
        summary += "\nBRASIL:\n"
        for i, (t, _) in enumerate(brazil[:limit], 1):
            summary += "%d. %s\n" % (i, t)
        return summary


# ===== CACHE DINAMICO =====
class DynamicCache:
    def __init__(self, cache_file=DYNAMIC_CACHE_FILE):
        self.cache_file = cache_file
        self.data = {}
        self.lock = threading.Lock()
        self.load()

    def load(self):
        try:
            if os.path.exists(self.cache_file):
                with open(self.cache_file, 'r', encoding='utf-8') as f:
                    self.data = json.load(f)
        except Exception as e:
            logger.error("Erro ao carregar cache dinamico: %s", e)
            self.data = {}

    def save(self):
        try:
            with open(self.cache_file, 'w', encoding='utf-8') as f:
                json.dump(self.data, f, ensure_ascii=False, indent=2)
        except Exception as e:
            logger.error("Erro ao salvar cache dinamico: %s", e)

    def get(self, query, max_age_seconds=CYCLE_SECONDS):
        key = query.lower().strip()
        with self.lock:
            entry = self.data.get(key)
        if not entry:
            return None
        try:
            ts = datetime.fromisoformat(entry["timestamp"])
        except Exception:
            return None
        if (datetime.now() - ts).total_seconds() > max_age_seconds:
            return None
        return [tuple(x) for x in entry["items"]]

    def set(self, query, items):
        key = query.lower().strip()
        with self.lock:
            self.data[key] = {
                "timestamp": datetime.now().isoformat(),
                "items": [list(x) for x in items]
            }
        self.save()

    def count_failures(self):
        erros = 0
        with self.lock:
            for entry in self.data.values():
                items = entry.get("items", [])
                if not items or (items[0][0] and "Erro" in items[0][0]):
                    erros += 1
        return erros


# ===== MEMORIA =====
class ChatMemory:
    def __init__(self, memory_file=MEMORY_FILE, context_file=CONTEXT_FILE):
        self.memory_file = memory_file
        self.context_file = context_file
        self.conversation_history = {}
        self.known_names = {}
        self.active_topics = {}
        self.max_history_length = 50
        self.load_memory()

    def load_memory(self):
        try:
            if os.path.exists(self.memory_file):
                with open(self.memory_file, "rb") as f:
                    data = pickle.load(f)
                self.conversation_history = data.get("history", {})
                self.known_names = data.get("known_names", {})
            if os.path.exists(self.context_file):
                with open(self.context_file, "r", encoding="utf-8") as f:
                    ctx = json.load(f)
                    self.active_topics = ctx.get("active_topics", {})
        except Exception as e:
            logger.error("Erro ao carregar memoria: %s", e)
            self.conversation_history = {}
            self.known_names = {}
            self.active_topics = {}

    def save_memory(self):
        try:
            with open(self.memory_file, "wb") as f:
                pickle.dump({"history": self.conversation_history, "known_names": self.known_names}, f)
            with open(self.context_file, "w", encoding="utf-8") as f:
                json.dump({"active_topics": self.active_topics}, f, indent=2, ensure_ascii=False)
        except Exception as e:
            logger.error("Erro ao salvar memoria: %s", e)

    def add_interaction(self, user_id, user_msg, bot_msg):
        uid = str(user_id)
        if uid not in self.conversation_history:
            self.conversation_history[uid] = []
        self.conversation_history[uid].append({
            "user": user_msg, "bot": bot_msg, "timestamp": datetime.now().isoformat()
        })
        if len(self.conversation_history[uid]) > self.max_history_length:
            self.conversation_history[uid] = self.conversation_history[uid][-self.max_history_length:]
        self.extract_name(uid, user_msg)
        self.save_memory()

    def extract_name(self, user_id, text):
        padroes = [r"meu nome e (\w+)", r"eu me chamo (\w+)", r"pode me chamar de (\w+)",
                   r"eu sou o (\w+)", r"eu sou a (\w+)", r"me chamo (\w+)"]
        t = text.lower()
        for p in padroes:
            m = re.search(p, t)
            if m:
                nome = m.group(1).capitalize()
                self.known_names.setdefault(str(user_id), {})["name"] = nome
                return

    def get_context(self, user_id):
        uid = str(user_id)
        ctx = []
        if uid in self.known_names and "name" in self.known_names[uid]:
            ctx.append("O usuario se chama %s." % self.known_names[uid]['name'])
        if uid in self.active_topics and self.active_topics[uid]:
            ctx.append("Topicos de interesse: %s." % ", ".join(self.active_topics[uid]))
        return " ".join(ctx)

    def get_history_for_context(self, user_id, limit=5):
        return self.conversation_history.get(str(user_id), [])[-limit:]


# ===== INSTANCIAS =====
chat_memory = ChatMemory()
news_manager = NewsManager()
dynamic_cache = DynamicCache()


# ===== CICLO DE ATUALIZACAO =====
def refresh_dynamic_cache():
    logger.debug("Atualizando palavras-chave monitoradas...")
    for kw in WATCHED_KEYWORDS:
        try:
            items = news_manager.search_news(kw, limit=10)
            if items:
                dynamic_cache.set(kw, items)
                logger.debug("Atualizado: %s (%d)", kw, len(items))
        except Exception as e:
            logger.error("Erro ao atualizar '%s': %s", kw, e)
    logger.debug("Refresh dinamico concluido.")


def run_cycle():
    """Executa um ciclo completo e imprime resumo legivel."""
    inicio = datetime.now()

    logger.info("=" * 62)
    logger.info("[%s] CICLO INICIADO (atualizacao a cada %d min)", _now(), CYCLE_SECONDS // 60)
    logger.info("=" * 62)

    logger.info("[%s] Atualizando %d topicos fixos...", _now(), len(TOPICS))
    try:
        news_manager.update_all_news()
    except Exception as e:
        logger.error("Falha nos topicos fixos: %s", e)

    logger.info("[%s] Atualizando %d palavras-chave...", _now(), len(WATCHED_KEYWORDS))
    try:
        refresh_dynamic_cache()
    except Exception as e:
        logger.error("Falha nas palavras-chave: %s", e)

    duracao = (datetime.now() - inicio).total_seconds()
    erros_fixos = news_manager.count_failures()
    erros_dinamicos = dynamic_cache.count_failures()

    logger.info("-" * 62)
    logger.info("[%s] CICLO CONCLUIDO em %.1fs", _now(), duracao)
    logger.info("  Topicos fixos:   %d total, %d com falha", len(TOPICS), erros_fixos)
    logger.info("  Palavras-chave:  %d total, %d com falha", len(WATCHED_KEYWORDS), erros_dinamicos)
    logger.info("  Proximo ciclo:   %s", _next_run(CYCLE_SECONDS))
    logger.info("-" * 62)


def scheduled_loop(interval_seconds=CYCLE_SECONDS):
    while True:
        try:
            run_cycle()
        except Exception as e:
            logger.error("Erro no loop agendado: %s", e)
        time.sleep(interval_seconds)


# ===== IA =====
SYSTEM_PROMPT_TEMPLATE = """
Voce e Gabriel, uma assistente de campo especializada em sobrevivencia, preparacao
para emergencias, bushcraft, primeiros socorros e monitoramento de riscos globais.

Sua personalidade e calma, direta e acolhedora. Voce conversa como um parceiro
experiente, que escuta antes de responder, explica com clareza e nunca inventa
fatos. Quando a informacao e incerta, voce diz que e incerta. Quando o assunto
envolve risco a vida, voce prioriza seguranca e recomenda fontes oficiais.

{user_context}

Historico recente da conversa com este usuario:
{history_text}

Noticias recentes que voce tem em cache:
{news_text}

Como responder:
- Use portugues do Brasil, tom natural e humano.
- Vá direto ao ponto, sem rodeios.
- Se o usuario pedir um plano, entregue passos concretos e na ordem.
- Se faltar dado, diga o que falta e como obter.
- Nunca use asteriscos, markdown, negrito ou listas com simbolos.
- Escreva em texto puro, em um ou dois paragrafos curtos.
- Termine com uma pergunta util quando fizer sentido.
"""


def get_ai_response(user_id, prompt, user_name=None):
    contexto = chat_memory.get_context(user_id)
    historico = chat_memory.get_history_for_context(user_id, 5)
    news_summary = news_manager.get_news_summary(5)

    if historico:
        hist_lines = []
        for h in historico:
            hist_lines.append("Usuario: %s" % h["user"][:200])
            hist_lines.append("Gabriel: %s" % h["bot"][:200])
        history_text = "\n".join(hist_lines)
    else:
        history_text = "(sem historico previo)"

    user_context = ""
    if user_name:
        user_context += "O usuario se chama %s.\n" % user_name
    if contexto:
        user_context += contexto

    system_prompt = SYSTEM_PROMPT_TEMPLATE.format(
        user_context=user_context,
        history_text=history_text,
        news_text=news_summary,
    )

    headers = {"Authorization": "Bearer %s" % API_KEY, "Content-Type": "application/json"}
    payload = {
        "model": MODEL,
        "messages": [
            {"role": "system", "content": system_prompt},
            {"role": "user", "content": prompt},
        ],
        "temperature": 0.6,
        "max_tokens": 800,
        "top_p": 1.0,
    }

    try:
        response = requests.post(API_URL, headers=headers, json=payload, timeout=LLM7_TIMEOUT)
        response.raise_for_status()
        data = response.json()
        resposta = (data.get("choices", [{}])[0].get("message", {}).get("content", "") or "").strip()
        if not resposta:
            return "Desculpe, a IA retornou uma resposta vazia."
        chat_memory.add_interaction(user_id, prompt, resposta)
        return resposta
    except Exception as e:
        logger.error("Erro na API LLM7: %s", e)
        return "Desculpe, estou com problemas tecnicos. Tente novamente."


# ===== BOT =====
bot = telebot.TeleBot(TELEGRAM_TOKEN)


def send_news_list(chat_id, titulo, items, max_items=5, decode=True):
    if not items:
        bot.send_message(chat_id, "%s\n\nNenhuma noticia encontrada." % titulo)
        return
    bot.send_message(chat_id, titulo)
    for i, (title, google_link) in enumerate(items[:max_items], 1):
        real_link = decode_google_link(google_link) if (decode and google_link) else google_link
        texto = "%d. %s" % (i, title)
        if real_link:
            texto += "\n%s" % real_link
        bot.send_message(chat_id, texto[:4096])


# ===== MENUS =====
def build_main_menu():
    markup = types.InlineKeyboardMarkup(row_width=2)
    markup.add(
        types.InlineKeyboardButton(text="Noticias mundiais", callback_data="menu_mundo"),
        types.InlineKeyboardButton(text="Noticias do Brasil", callback_data="menu_brasil"),
    )
    markup.add(
        types.InlineKeyboardButton(text="Topicos monitorados", callback_data="menu_topicos"),
        types.InlineKeyboardButton(text="Palavras-chave", callback_data="menu_keywords"),
    )
    markup.add(
        types.InlineKeyboardButton(text="Forcar atualizacao", callback_data="menu_atualizar"),
        types.InlineKeyboardButton(text="Limpar historico", callback_data="menu_clear"),
    )
    return markup


def build_topics_menu():
    markup = types.InlineKeyboardMarkup(row_width=2)
    keys = list(TOPIC_NAMES.keys())
    for i in range(0, len(keys), 2):
        row = []
        for k in keys[i:i+2]:
            row.append(types.InlineKeyboardButton(text=TOPIC_NAMES[k], callback_data="topic_%s" % k))
        markup.add(*row)
    markup.add(types.InlineKeyboardButton(text="Voltar", callback_data="menu_start"))
    return markup


def build_keywords_menu():
    markup = types.InlineKeyboardMarkup(row_width=2)
    for kw in WATCHED_KEYWORDS:
        markup.add(types.InlineKeyboardButton(text=kw, callback_data="kw_%s" % kw))
    markup.add(types.InlineKeyboardButton(text="Voltar", callback_data="menu_start"))
    return markup


# ===== HANDLERS =====
@bot.message_handler(commands=['start'])
def cmd_start(message):
    texto = (
        "Ola. Eu sou a Gabriel.\n\n"
        "Sou uma assistente de campo: monitoro noticias, acompanho riscos globais e "
        "ajudo em sobrevivencia, preparacao para emergencias, bushcraft e primeiros socorros.\n\n"
        "Como funciona:\n"
        "- /mundo mostra as principais noticias internacionais.\n"
        "- /brasil mostra as principais noticias nacionais.\n"
        "- /news <assunto> pesquisa em tempo real sobre qualquer tema.\n"
        "- /topicos lista os temas que eu monitoro em cache.\n"
        "- /keywords lista as palavras-chave atualizadas a cada 15 minutos.\n"
        "- /atualizar forca uma nova coleta agora.\n"
        "- /clear apaga o historico da nossa conversa.\n\n"
        "Tambem aceito comandos diretos, como /ebola ou /guerra, para buscar noticias "
        "sobre o tema na hora.\n\n"
        "Escolha uma opcao abaixo ou me envie uma pergunta."
    )
    bot.send_message(message.chat.id, texto, reply_markup=build_main_menu())


@bot.message_handler(commands=['ajuda', 'help'])
def cmd_help(message):
    cmd_start(message)


@bot.message_handler(commands=['mundo'])
def cmd_mundo(message):
    items = news_manager.get_world_news(limit=10)
    send_news_list(message.chat.id, "NOTICIAS MUNDIAIS", items, max_items=5)


@bot.message_handler(commands=['brasil'])
def cmd_brasil(message):
    items = news_manager.get_brazil_news(limit=10)
    send_news_list(message.chat.id, "NOTICIAS DO BRASIL", items, max_items=5)


@bot.message_handler(commands=['topicos'])
def cmd_topicos(message):
    bot.send_message(message.chat.id, "Escolha um topico monitorado:", reply_markup=build_topics_menu())


@bot.message_handler(commands=['keywords'])
def cmd_keywords(message):
    bot.send_message(
        message.chat.id,
        "Palavras-chave atualizadas a cada 15 minutos:",
        reply_markup=build_keywords_menu()
    )


@bot.message_handler(commands=['news'])
def cmd_news(message):
    args = message.text.split(maxsplit=1)
    if len(args) < 2:
        bot.reply_to(message, "Uso: /news ebola")
        return
    query = args[1].strip()
    bot.send_chat_action(message.chat.id, 'typing')
    items = dynamic_cache.get(query, max_age_seconds=CYCLE_SECONDS)
    if not items:
        items = news_manager.search_news(query, limit=10)
        if items:
            dynamic_cache.set(query, items)
    send_news_list(message.chat.id, "NOTICIAS: %s" % query, items, max_items=5)


@bot.message_handler(commands=['atualizar'])
def cmd_atualizar(message):
    bot.reply_to(message, "Atualizando base de topicos e palavras-chave...")
    run_cycle()
    bot.reply_to(message, "Base atualizada.")


@bot.message_handler(commands=['clear', 'limpar'])
def cmd_clear(message):
    uid = str(message.from_user.id)
    if uid in chat_memory.conversation_history:
        chat_memory.conversation_history[uid] = []
        chat_memory.save_memory()
        bot.reply_to(message, "Historico limpo.")
    else:
        bot.reply_to(message, "Voce nao tem historico.")


# ===== CALLBACKS =====
@bot.callback_query_handler(func=lambda call: True)
def handle_callback(call):
    bot.answer_callback_query(call.id)
    data = call.data

    if data == "menu_start":
        cmd_start(call.message)
        return

    if data == "menu_mundo":
        cmd_mundo(call.message)
        return

    if data == "menu_brasil":
        cmd_brasil(call.message)
        return

    if data == "menu_topicos":
        bot.send_message(call.message.chat.id, "Escolha um topico monitorado:", reply_markup=build_topics_menu())
        return

    if data == "menu_keywords":
        bot.send_message(
            call.message.chat.id,
            "Palavras-chave atualizadas a cada 15 minutos:",
            reply_markup=build_keywords_menu()
        )
        return

    if data == "menu_atualizar":
        bot.send_message(call.message.chat.id, "Atualizando base de topicos e palavras-chave...")
        run_cycle()
        bot.send_message(call.message.chat.id, "Base atualizada.")
        return

    if data == "menu_clear":
        uid = str(call.from_user.id)
        if uid in chat_memory.conversation_history:
            chat_memory.conversation_history[uid] = []
            chat_memory.save_memory()
            bot.send_message(call.message.chat.id, "Historico limpo.")
        else:
            bot.send_message(call.message.chat.id, "Voce nao tem historico.")
        return

    if data.startswith("topic_"):
        key = data[len("topic_"):]
        if news_manager.needs_update():
            news_manager.update_all_news()
        items = news_manager.news_data.get(key, [])
        send_news_list(call.message.chat.id, "NOTICIAS: %s" % TOPIC_NAMES.get(key, key), items, max_items=5)
        return

    if data.startswith("kw_"):
        kw = data[len("kw_"):]
        items = dynamic_cache.get(kw, max_age_seconds=CYCLE_SECONDS)
        if not items:
            items = news_manager.search_news(kw, limit=10)
            if items:
                dynamic_cache.set(kw, items)
        send_news_list(call.message.chat.id, "NOTICIAS: %s" % kw, items, max_items=5)
        return


# ===== COMANDOS DIRETOS =====
KNOWN_COMMANDS = {
    "start", "ajuda", "help", "mundo", "brasil", "topicos",
    "keywords", "news", "atualizar", "clear", "limpar",
}

@bot.message_handler(
    func=lambda m: m.text and m.text.startswith('/') and len(m.text.split()) == 1
)
def handle_direct_keyword(message):
    cmd = message.text[1:].lower()
    if cmd in KNOWN_COMMANDS:
        return
    bot.send_chat_action(message.chat.id, 'typing')
    items = dynamic_cache.get(cmd, max_age_seconds=CYCLE_SECONDS)
    if not items:
        items = news_manager.search_news(cmd, limit=10)
        if items:
            dynamic_cache.set(cmd, items)
    if items:
        send_news_list(message.chat.id, "NOTICIAS: %s" % cmd, items, max_items=5)
    else:
        bot.reply_to(message, "Nao encontrei noticias sobre '%s'." % cmd)


# ===== TEXTO LIVRE =====
@bot.message_handler(func=lambda m: True)
def handle_text(message):
    user_id = message.from_user.id
    user_name = message.from_user.first_name
    bot.send_chat_action(message.chat.id, 'typing')
    resposta = get_ai_response(user_id, message.text, user_name)
    bot.reply_to(message, resposta[:4000])


# ===== STARTUP =====
if __name__ == "__main__":
    logger.info("[%s] Gabriel iniciando. Modo DEBUG=%s", _now(), DEBUG)
    logger.info("[%s] Carga inicial em andamento...", _now())
    run_cycle()

    refresh_thread = threading.Thread(target=scheduled_loop, daemon=True)
    refresh_thread.start()

    logger.info("[%s] Bot conectado. Aguardando mensagens...", _now())
    bot.infinity_polling(timeout=60, long_polling_timeout=30)
