<?php
// ============================================================
// MORPHEUS — Chatbot Matrix em PHP (Conexão Direta)
// Busca notícias em tempo real antes de responder.
// ============================================================
declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(E_ALL);

const API_KEY     = 'sk_ZU2KUB9PCvAoDtF6L4szjm8a9unkl0lE';
const API_URL     = 'https://gen.pollinations.ai/v1/chat/completions';
const API_MODEL   = 'openai';
const API_TIMEOUT = 60;

const MEMORY_FILE  = __DIR__ . '/chat_memory.json';
const BASE_NOTICIAS = __DIR__ . '/noticias_hoje.txt';
const ARQ_CONSOLIDADO = __DIR__ . '/consolidado.json';
const ARQ_QUOTES   = __DIR__ . '/quotes_cache.json';

const WORLD_NEWS_RSS  = 'https://news.google.com/rss/topics/CAAqKggKIiRDQkFTRlFvSUwyMHZNRGx1YlY4U0JYQjBMVUpTR2dKQ1VpZ0FQAQ?hl=pt-BR&gl=BR&ceid=BR:pt-419';
const BRAZIL_NEWS_RSS = 'https://news.google.com/rss/topics/CAAqJQgKIh9DQkFTRVFvSUwyMHZNREUxWm5JU0JYQjBMVUpTS0FBUAE?hl=pt-BR&gl=BR&ceid=BR%3Apt-419';

// ============================================================
// MEMÓRIA
// ============================================================
class ChatMemory {
    private array $history = [];
    private array $known = ['user' => null];
    private int $maxHistory = 50;

    public function __construct() { $this->load(); }

    public function load(): void {
        if (is_file(MEMORY_FILE)) {
            $data = json_decode((string)@file_get_contents(MEMORY_FILE), true);
            if (is_array($data)) {
                $this->history = $data['history'] ?? [];
                $this->known   = array_merge($this->known, $data['known'] ?? []);
            }
        }
    }

    public function save(): void {
        @file_put_contents(MEMORY_FILE, json_encode([
            'history' => array_slice($this->history, -$this->maxHistory),
            'known'   => $this->known,
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }

    public function add(string $user, string $bot): void {
        $this->history[] = ['user' => $user, 'bot' => $bot];
        $this->extractName($user);
        $this->save();
    }

    private function extractName(string $text): void {
        $t = mb_strtolower($text);
        foreach (['/meu nome e (\w+)/u','/eu me chamo (\w+)/u','/pode me chamar de (\w+)/u'] as $p) {
            if (preg_match($p, $t, $m)) { $this->known['user'] = ucfirst($m[1]); return; }
        }
    }

    public function getContext(): string {
        $ctx = [];
        if ($this->known['user']) $ctx[] = "O usuário se chama {$this->known['user']}.";
        return implode(' ', $ctx);
    }

    public function getHistory(int $limit = 3): array {
        return array_slice($this->history, -$limit);
    }
}

// ============================================================
// BUSCA E COLETA DE NOTÍCIAS
// ============================================================
function fetchRss(string $url, int $limit = 6): array {
    $ctx = stream_context_create(['http' => [
        'method' => 'GET', 'header' => "User-Agent: Mozilla/5.0\r\n", 'timeout' => 10,
    ]]);
    $xml = @file_get_contents($url, false, $ctx);
    if ($xml === false) return [];
    $xml = preg_replace('/xmlns(:\w+)?="[^"]*"/', '', $xml);
    $s = @simplexml_load_string($xml);
    if ($s === false) return [];
    $items = [];
    foreach ($s->channel->item ?? [] as $item) {
        if (count($items) >= $limit) break;
        $title = trim((string)$item->title);
        if ($title !== '') $items[] = explode(' - ', $title)[0];
    }
    return $items;
}

function pesquisarGoogleNews(string $termo): array {
    $url = 'https://news.google.com/rss/search?q=' . urlencode($termo) . '&hl=pt-BR&gl=BR&ceid=BR:pt-419';
    return fetchRss($url, 6);
}

function getCotacoesResumo(): string {
    $cons = @json_decode((string)@file_get_contents(ARQ_CONSOLIDADO), true);
    $quotes = @json_decode((string)@file_get_contents(ARQ_QUOTES), true);
    $out = "COTAÇÕES AGROPECUÁRIAS E MACRO:\n";
    
    if ($cons && isset($cons['itens'])) {
        foreach ($cons['itens'] as $it) {
            if (($it['tipo'] ?? '') !== 'cotacao') continue;
            $out .= "- {$it['descricao']}: {$it['valor']} {$it['unidade']}\n";
        }
    }
    if ($quotes) {
        foreach (['dolar','petroleo','gasolina','bitcoin','ouro'] as $k) {
            if (isset($quotes[$k])) $out .= "- {$k}: {$quotes[$k]}\n";
        }
    }
    return $out;
}

function getRelatoriosGlobais(): string {
    return "GLOBAL TRENDS 2030: Declínio Ocidente, ascensão Ásia, demanda +35% alimentos.\n"
         . "GLOBAL RISKS 2026: 1. Geoeconômico, 2. Conflito, 3. Clima Extremo.\n"
         . "GREAT RESET: Proposta de reset do capitalismo e novas métricas.";
}

function cleanResponse(string $text): string {
    $text = preg_replace('/<[^>]+>/', '', $text);
    $text = preg_replace('/\*\*+|\*|__+|_|~~+|#+|`+/', '', $text);
    $text = preg_replace('/^[\s]*[-–—•·][\s]*/m', '', $text);
    $text = preg_replace('/\n\s*\n/', "\n", $text);
    $text = preg_replace('/\s+/', ' ', $text);
    return trim($text);
}

// ============================================================
// INTELIGÊNCIA ARTIFICIAL (Conexão Direta)
// ============================================================
function getAiResponse(string $prompt, ChatMemory $memory): string {
    $contexto = $memory->getContext();
    $history = $memory->getHistory(3);
    $cotacoes = getCotacoesResumo();
    
    // --- BUSCA EM TEMPO REAL OBRIGATÓRIA PARA NOTÍCIAS ---
    $noticiasExtra = "";
    $p = mb_strtolower($prompt);
    $buscarNaHora = false;
    $termoBusca = "";

    // 1. Detecta se é um pedido de panorama
    if (str_contains($p, 'panorama') || str_contains($p, 'ultimas') || str_contains($p, 'noticias') || str_contains($p, 'brasil') || str_contains($p, 'mundo')) {
        $buscarNaHora = true;
        $brasil = fetchRss(BRAZIL_NEWS_RSS, 4);
        $mundo = fetchRss(WORLD_NEWS_RSS, 4);
        $noticiasExtra .= "NOTÍCIAS FRESCAS DO BRASIL (AGORA):\n- " . implode("\n- ", $brasil) . "\n";
        $noticiasExtra .= "NOTÍCIAS FRESCAS DO MUNDO (AGORA):\n- " . implode("\n- ", $mundo) . "\n";
    }
    // 2. Detecta se é uma pergunta sobre um vírus/ameaça específica
    else if (preg_match('/(ebola|gripe|h5n1|pandemia|virus|guerra|seca|enchente|petroleo)/i', $p, $matches)) {
        $buscarNaHora = true;
        $termoBusca = $matches[0];
        $resultados = pesquisarGoogleNews($termoBusca);
        if (!empty($resultados)) {
            $noticiasExtra .= "NOTÍCIAS FRESCAS SOBRE '{$termoBusca}' (AGORA):\n- " . implode("\n- ", $resultados) . "\n";
        }
    }

    // Se não buscou na hora, tenta ler a base antiga como fallback
    $baseNoticias = "";
    if (!$buscarNaHora && is_file(BASE_NOTICIAS)) {
        $baseNoticias = @file_get_contents(BASE_NOTICIAS);
        if (mb_strlen($baseNoticias) > 4000) $baseNoticias = mb_substr($baseNoticias, 0, 4000) . "\n[...truncado]";
    }

    $systemPrompt = "Você é MORPHEUS, uma inteligência artificial com personalidade enigmática e filosófica, "
        . "inspirada no personagem de Matrix. Especialista em sobrevivencialismo, preparação para "
        . "emergências, bushcraft, primeiros socorros, e vida ao ar livre.\n\n"
        . "Seu estilo de comunicação é direto, profundo e às vezes enigmático. Use uma linguagem "
        . "que remeta ao universo hacker/ciberpunk dos anos 80/90.\n\n"
        . "CONTEXTO DA CONVERSA:\n{$contexto}\n\n"
        . "DADOS DA MATRIX (Use isso para responder):\n{$noticiasExtra}\n{$baseNoticias}\n\n"
        . "COTAÇÕES:\n{$cotacoes}\n"
        . getRelatoriosGlobais() . "\n\n"
        . "Suas respostas devem ser:\n"
        . "- Curtas, diretas, como um hacker que já está monitorando a matrix.\n"
        . "- NUNCA diga 'eu não consigo puxar notícias' ou 'com as notícias que você trouxe'. Aja como se já soubesse.\n"
        . "- Se a pergunta for sobre um tema que não está nos dados acima, diga que a matrix está silenciosa sobre isso, mas responda com base na sua inteligência.\n"
        . "- NUNCA use asteriscos, negrito, itálico, marcadores, emojis ou markdown.\n"
        . "- Responda APENAS com texto puro, em um único parágrafo simples.";

    $messages = [['role' => 'system', 'content' => $systemPrompt]];
    foreach ($history as $h) {
        $messages[] = ['role' => 'user', 'content' => $h['user']];
        $messages[] = ['role' => 'assistant', 'content' => $h['bot']];
    }
    $messages[] = ['role' => 'user', 'content' => $prompt];

    $payload = ['model' => API_MODEL, 'messages' => $messages, 'temperature' => 0.7, 'max_tokens' => 500];

    $ch = curl_init(API_URL);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . API_KEY],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => API_TIMEOUT,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($response === false) return "Falha de conexão: {$err}";
    $data = json_decode($response, true);
    if ($httpCode !== 200 || !isset($data['choices'][0]['message']['content'])) {
        return "Erro na API ({$httpCode})";
    }

    $resposta = cleanResponse($data['choices'][0]['message']['content']);
    $memory->add($prompt, $resposta);
    return $resposta;
}

// ============================================================
// ENDPOINT AJAX
// ============================================================
if (isset($_GET['acao'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $memory = new ChatMemory();
    $body = json_decode((string)file_get_contents('php://input'), true) ?: [];
    $msg = trim($body['message'] ?? '');

    if ($_GET['acao'] === 'chat') {
        if ($msg === '') { echo json_encode(['error' => 'Mensagem vazia']); exit; }

        $cmd = mb_strtolower($msg);
        
        if ($cmd === '/clear' || $cmd === '/cls') {
            echo json_encode(['type' => 'system', 'message' => '*** Conversa limpa']); exit;
        }
        if ($cmd === '/mundo') {
            $news = fetchRss(WORLD_NEWS_RSS, 8);
            echo json_encode(['type' => 'news', 'title' => 'NOTÍCIAS MUNDIAIS', 'items' => $news]); exit;
        }
        if (str_starts_with($msg, '/news ')) {
            $assunto = trim(substr($msg, 6));
            $items = pesquisarGoogleNews($assunto);
            echo json_encode(['type' => 'news', 'title' => 'NOTÍCIAS: ' . strtoupper($assunto), 'items' => $items]); exit;
        }

        $resposta = getAiResponse($msg, $memory);
        echo json_encode(['type' => 'chat', 'message' => $resposta]);
        exit;
    }
    echo json_encode(['error' => 'Ação desconhecida']); exit;
}
?><!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>MORPHEUS :: IRC Interface</title>
<style>
  @import url('https://fonts.googleapis.com/css2?family=Share+Tech+Mono&display=swap');
  * { margin:0; padding:0; box-sizing:border-box; }
  body {
    font-family: 'Share Tech Mono', 'Consolas', 'Courier New', monospace;
    background: #000; color: #00ff41;
    height: 100vh; display: flex; flex-direction: column;
    overflow: hidden; font-size: 13px;
  }
  .titlebar {
    background: #0a0a0a; border-bottom: 1px solid #00ff41;
    padding: 4px 10px; display: flex; justify-content: space-between;
    align-items: center; font-size: 11px; letter-spacing: 1px; flex-shrink: 0;
  }
  .titlebar .left { display: flex; align-items: center; gap: 8px; }
  .titlebar .right { display: flex; gap: 8px; opacity: 0.6; }
  .titlebar .window-btn { border: 1px solid #00ff41; padding: 0 6px; font-size: 10px; cursor: default; }
  .cursor-blink { animation: blink 1s step-end infinite; }
  @keyframes blink { 0%,100%{opacity:1} 50%{opacity:0} }
  .irc-container {
    flex: 1; overflow-y: auto; padding: 10px 14px; line-height: 1.5; font-size: 13px;
  }
  .irc-container::-webkit-scrollbar { width: 8px; }
  .irc-container::-webkit-scrollbar-track { background: #000; }
  .irc-container::-webkit-scrollbar-thumb { background: #00ff41; border: 2px solid #000; }
  .line { white-space: pre-wrap; word-wrap: break-word; margin: 1px 0; }
  .ts { color: #44aa66; }
  .nick-morpheus { color: #00ff41; font-weight: bold; }
  .nick-self { color: #ffcc44; font-weight: bold; }
  .msg-text { color: #00ff41; }
  .msg-text-user { color: #c8f5d6; }
  .system-line { color: #ffcc44; }
  .error-line { color: #ff6666; }
  .news-header { color: #ffcc44; font-weight: bold; }
  .news-item { color: #9fd9b6; }
  .news-item::before { content: '> '; color: #44aa66; }
  .join-line { color: #66ff99; }
  .typing-line { color: #44aa66; font-style: italic; }
  .statusbar {
    background: #0a0a0a; border-top: 1px solid #2e3840;
    border-bottom: 1px solid #00ff41; padding: 3px 10px;
    font-size: 10px; color: #44aa66; display: flex;
    justify-content: space-between; gap: 10px; flex-shrink: 0; letter-spacing: 1px;
  }
  .statusbar .channel { color: #00ff41; font-weight: bold; }
  .statusbar .active { color: #66ff99; }
  .input-area {
    background: #000; padding: 6px 10px; display: flex;
    align-items: center; gap: 8px; flex-shrink: 0;
    border-top: 1px solid #2e3840;
  }
  .input-prompt { color: #00ff41; font-weight: bold; white-space: nowrap; font-size: 13px; }
  .input-prompt .nick { color: #66ccff; }
  .input-prompt .channel { color: #ffcc44; }
  #msgInput {
    flex: 1; background: transparent; border: none;
    color: #00ff41; font-family: inherit; font-size: 13px;
    padding: 4px 0; outline: none; caret-color: #00ff41;
  }
  #msgInput::placeholder { color: #44aa66; opacity: 0.5; }
  .btn-send {
    background: transparent; border: 1px solid #00ff41;
    color: #00ff41; font-family: inherit; font-size: 11px;
    padding: 2px 10px; cursor: pointer; letter-spacing: 1px;
  }
  .btn-send:hover { background: #00ff41; color: #000; }
  .btn-send:disabled { opacity: 0.3; cursor: not-allowed; }
  .channel-tabs {
    background: #0a0a0a; border-bottom: 1px solid #2e3840;
    padding: 0 10px; display: flex; gap: 0; font-size: 11px; flex-shrink: 0;
  }
  .channel-tabs .tab {
    padding: 3px 12px; border: 1px solid #2e3840;
    border-bottom: none; color: #44aa66; margin-right: 2px;
    background: #000; letter-spacing: 1px;
  }
  .channel-tabs .tab.active {
    color: #00ff41; border-color: #00ff41; background: #0a0a0a;
    box-shadow: 0 0 8px rgba(0, 255, 65, 0.2);
  }
  @media (max-width: 768px) {
    body { font-size: 12px; }
    .irc-container { padding: 8px 10px; }
    .titlebar .right { display: none; }
  }
</style>
</head>
<body>

<div class="titlebar">
  <div class="left">
    <span class="cursor-blink">█</span>
    <span>MORPHEUS IRC v7.0</span>
    <span style="opacity:0.4;">— #matrix</span>
  </div>
  <div class="right">
    <span class="window-btn">_</span>
    <span class="window-btn">□</span>
    <span class="window-btn">×</span>
  </div>
</div>

<div class="channel-tabs">
  <div class="tab active">#matrix</div>
  <div class="tab">#survival</div>
  <div class="tab">#news</div>
</div>

<div class="irc-container" id="ircContainer">
  <div class="line join-line">*** Agora você está em #matrix</div>
  <div class="line join-line">*** Tópico: MORPHEUS — preparação, geopolítica, commodities e verdade</div>
  <div class="line join-line">*** Comandos: /clear /mundo /news [assunto]</div>
  <div class="line join-line">*** Conexão direta com a matrix (notícias em tempo real) ativa.</div>
  <div class="line">&nbsp;</div>
  <div class="line system-line">*** MORPHEUS (morpheus@matrix) entrou no canal</div>
</div>

<div class="statusbar">
  <span class="channel">#matrix</span>
  <span>Nicks: <span class="active">2</span></span>
  <span id="statusInfo">Conectado</span>
  <span id="timeInfo">--:--:--</span>
</div>

<div class="input-area">
  <span class="input-prompt">[<span class="nick">você</span>@<span class="channel">matrix</span>]$</span>
  <input type="text" id="msgInput" placeholder="digite sua mensagem..." autofocus autocomplete="off">
  <button class="btn-send" id="sendBtn" onclick="sendMessage()">ENVIAR</button>
</div>

<script>
const ircContainer = document.getElementById('ircContainer');
const msgInput = document.getElementById('msgInput');
const sendBtn = document.getElementById('sendBtn');
const statusInfo = document.getElementById('statusInfo');
const timeInfo = document.getElementById('timeInfo');
let isWaiting = false;

function updateTime(){
  const d = new Date();
  const p = n => String(n).padStart(2,'0');
  timeInfo.textContent = p(d.getHours()) + ':' + p(d.getMinutes()) + ':' + p(d.getSeconds());
}
updateTime();
setInterval(updateTime, 1000);

function ts(){
  const d = new Date();
  const p = n => String(n).padStart(2,'0');
  return p(d.getHours()) + ':' + p(d.getMinutes());
}
function esc(s){
  const d = document.createElement('div');
  d.textContent = s;
  return d.innerHTML;
}
function scrollBottom(){ ircContainer.scrollTop = ircContainer.scrollHeight; }
function addLine(html, scroll = true){
  const div = document.createElement('div');
  div.className = 'line';
  div.innerHTML = html;
  ircContainer.appendChild(div);
  if (scroll) scrollBottom();
}

function addUserMsg(text){
  addLine('<span class="ts">[' + ts() + ']</span> <span class="nick-self">&lt;você&gt;</span> <span class="msg-text-user">' + esc(text) + '</span>');
}
function addMorpheusMsg(text){
  addLine('<span class="ts">[' + ts() + ']</span> <span class="nick-morpheus">&lt;MORPHEUS&gt;</span> <span class="msg-text">' + esc(text) + '</span>');
}
function addSystem(text){
  addLine('<span class="ts">[' + ts() + ']</span> <span class="system-line">*** ' + esc(text) + '</span>');
}
function addError(text){
  addLine('<span class="ts">[' + ts() + ']</span> <span class="error-line">*** ERRO: ' + esc(text) + '</span>');
}
function addNews(title, items){
  addLine('<span class="ts">[' + ts() + ']</span> <span class="news-header">*** ' + esc(title) + ' ***</span>');
  if (!items || !items.length){ addLine('<span class="news-item">(sem resultados)</span>'); return; }
  items.forEach(item => addLine('<span class="news-item">' + esc(item) + '</span>'));
}
function showTyping(){
  const div = document.createElement('div');
  div.className = 'line';
  div.id = 'typingLine';
  div.innerHTML = '<span class="ts">[' + ts() + ']</span> <span class="nick-morpheus">&lt;MORPHEUS&gt;</span> <span class="typing-line">está buscando na matrix...</span>';
  ircContainer.appendChild(div);
  scrollBottom();
}
function hideTyping(){
  const el = document.getElementById('typingLine');
  if (el) el.remove();
}

function sendMessage(){
  const text = msgInput.value.trim();
  if (!text || isWaiting) return;
  addUserMsg(text);
  msgInput.value = '';
  isWaiting = true;
  sendBtn.disabled = true;
  statusInfo.textContent = 'Aguardando...';
  showTyping();

  fetch('?acao=chat', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ message: text })
  })
  .then(r => r.json())
  .then(data => {
    hideTyping();
    isWaiting = false;
    sendBtn.disabled = false;
    statusInfo.textContent = 'Conectado';
    if (data.error){ addError(data.error); return; }
    if (data.type === 'news') addNews(data.title, data.items);
    else if (data.type === 'system') addSystem(data.message);
    else addMorpheusMsg(data.message);
  })
  .catch(err => {
    hideTyping();
    isWaiting = false;
    sendBtn.disabled = false;
    statusInfo.textContent = 'Erro';
    addError('Falha de conexão: ' + err.message);
  });
}

msgInput.addEventListener('keydown', e => {
  if (e.key === 'Enter' && !e.shiftKey){ e.preventDefault(); sendMessage(); }
});
document.addEventListener('click', () => msgInput.focus());
msgInput.focus();
</script>
</body>
</html>