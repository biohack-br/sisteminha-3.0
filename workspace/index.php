<?php
// ============================================================
// B10H4CK SISTEMAS — hub com abas + Morpheus inline
// ============================================================
declare(strict_types=1);

$pagina = $_GET['p'] ?? 'consolidado';
$paginasValidas = [
    'consolidado' => 'consolidado.php',
    'ia'          => 'ia.php',
    'noticias'    => 'noticias.php',
    'cotacoes'    => 'index.html',
    'doomsday'    => 'doomsday.php',
    'morpheus'    => 'morpheus.php',
];
if (!isset($paginasValidas[$pagina])) $pagina = 'consolidado';
$arquivo = $paginasValidas[$pagina];

$menu = [
    'consolidado' => ['rotulo' => 'CONSOLIDADO', 'icone' => '▤'],
    'ia'          => ['rotulo' => 'ANÁLISE IA',  'icone' => '◆'],
    'noticias'    => ['rotulo' => 'NOTÍCIAS',    'icone' => '☷'],
    'cotacoes'    => ['rotulo' => 'COTAÇÕES',    'icone' => '≡'],
    'doomsday'    => ['rotulo' => 'DOOMSDAY',    'icone' => '⚠'],
    'morpheus'    => ['rotulo' => 'MORPHEUS',    'icone' => '◈'],
];

$inlineMorpheus = ($pagina === 'morpheus');
?><!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>B10H4CK SISTEMAS :: <?= htmlspecialchars($menu[$pagina]['rotulo']) ?></title>

<!-- ===== OPEN GRAPH / FACEBOOK / WHATSAPP / LINKEDIN ===== -->
<meta property="og:type" content="website">
<meta property="og:url" content="https://cotacao.infinityfree.io/">
<meta property="og:title" content="B10H4CK SISTEMAS :: Cotações, Alertas e Sobrevivência">
<meta property="og:description" content="Monitoramento de cotações agro, análise de risco geopolítico, alertas do Doomsday e IA especializada em preparação.">
<meta property="og:image" content="https://cotacao.infinityfree.io/thumb.jpg">
<meta property="og:image:secure_url" content="https://cotacao.infinityfree.io/thumb.jpg">
<meta property="og:image:type" content="image/jpeg">
<meta property="og:image:width" content="1200">
<meta property="og:image:height" content="630">
<meta property="og:image:alt" content="Logo BIOHACK com ferramentas cruzadas e DNA central">
<meta property="og:site_name" content="B10H4CK">
<meta property="og:locale" content="pt_BR">

<!-- ===== TWITTER / X CARD ===== -->
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="B10H4CK SISTEMAS :: Cotações, Alertas e Sobrevivência">
<meta name="twitter:description" content="Monitoramento de cotações agro, análise de risco geopolítico, alertas do Doomsday e IA especializada em preparação.">
<meta name="twitter:image" content="https://cotacao.infinityfree.io/thumb.jpg">
<meta name="twitter:image:alt" content="Logo BIOHACK com ferramentas cruzadas e DNA central">

<!-- ===== FAVICON ===== -->
<link rel="icon" type="image/jpeg" href="https://cotacao.infinityfree.io/thumb.jpg">
<link rel="apple-touch-icon" href="https://cotacao.infinityfree.io/thumb.jpg">

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
  :root{
    --bg:#0a0d0f; --bg-alt:#0f1316; --card:#181d22; --border:#2e3840;
    --verde:#00ff66; --verde-suave:#66ff99; --verde-dim:#44aa66;
    --amarelo:#ffcc44; --vermelho:#ff6666; --azul:#66ccff;
  }
  html, body{
    background:
      repeating-linear-gradient(0deg,
        rgba(0,255,102,.015) 0px, rgba(0,255,102,.015) 1px,
        transparent 1px, transparent 3px),
      var(--bg);
    color:var(--verde);
    font-family:'Consolas','Courier New',monospace;
    margin:0;
    overflow-x:hidden;
  }
  body.morpheus-mode{ height:100vh; overflow:hidden; }
  body.morpheus-mode .conteudo-sistema{ height:calc(100vh - 128px); }

  .topo-sistema{
    background:linear-gradient(180deg,#0a0d0f 0%, #0f1316 100%);
    border-bottom:2px solid var(--verde);
    box-shadow:0 0 20px rgba(0,255,102,.15);
    position:sticky; top:0; z-index:1000;
  }
  .topo-titulo{
    display:flex; align-items:center; justify-content:space-between;
    padding:10px 20px 6px; border-bottom:1px solid var(--border);
  }
  .logo{
    font-size:1rem; font-weight:bold; letter-spacing:3px;
    color:var(--verde-suave);
    text-shadow:0 0 8px rgba(0,255,102,.5), 0 0 2px var(--verde);
    display:flex; align-items:center; gap:10px;
  }
  .logo .cursor{
    display:inline-block; width:10px; height:18px;
    background:var(--verde); animation:piscar 1s steps(2,start) infinite;
    box-shadow:0 0 6px var(--verde);
  }
  @keyframes piscar{ to{ visibility:hidden; } }
  .relogio{ font-size:.72rem; color:var(--verde-dim); letter-spacing:2px; }

  .menu-hacker{ display:flex; flex-wrap:wrap; gap:0; padding:0 12px; background:#0a0d0f; }
  .menu-hacker a{
    position:relative; padding:8px 18px 7px;
    color:var(--verde-dim); text-decoration:none;
    font-size:.72rem; letter-spacing:2px;
    border:1px solid transparent; border-bottom:none;
    border-radius:6px 6px 0 0; transition:all .15s;
    display:flex; align-items:center; gap:6px;
    text-transform:uppercase;
  }
  .menu-hacker a .icone{ font-size:.9rem; }
  .menu-hacker a:hover{ color:var(--verde-suave); background:rgba(0,255,102,.05); }
  .menu-hacker a.ativo{
    color:var(--verde); background:var(--bg-alt);
    border-color:var(--verde) var(--verde) transparent;
    box-shadow:inset 0 0 12px rgba(0,255,102,.15), 0 0 8px rgba(0,255,102,.2);
    text-shadow:0 0 8px rgba(0,255,102,.7);
  }
  .menu-hacker a.ativo::before{
    content:''; position:absolute; left:0; right:0; bottom:-2px;
    height:2px; background:var(--verde); box-shadow:0 0 8px var(--verde);
  }
  .menu-hacker a[href="?p=doomsday"] .icone{ color:var(--vermelho); text-shadow:0 0 6px var(--vermelho); }
  .menu-hacker a[href="?p=doomsday"].ativo{
    color:var(--vermelho);
    border-color:var(--vermelho) var(--vermelho) transparent;
    box-shadow:inset 0 0 12px rgba(255,102,102,.18), 0 0 8px rgba(255,102,102,.25);
    text-shadow:0 0 8px rgba(255,102,102,.7);
  }
  .menu-hacker a[href="?p=doomsday"].ativo::before{ background:var(--vermelho); box-shadow:0 0 8px var(--vermelho); }

  .status-bar{
    background:#0a0d0f; border-top:1px solid var(--border);
    font-size:.62rem; color:var(--verde-dim);
    padding:3px 20px; letter-spacing:1.5px;
    display:flex; justify-content:space-between; flex-wrap:wrap; gap:8px;
  }
  .status-bar .verde{ color:var(--verde); }
  .status-bar .vermelho{ color:var(--vermelho); }

  .conteudo-sistema{ padding:0; display:block; }

  .frame-wrapper{
    width:100%;
    height:calc(100vh - 128px);
    min-height:400px;
    border:none;
    display:block;
    background:var(--bg);
    overflow:hidden;
  }
  .frame-wrapper::-webkit-scrollbar{ display:none; }

  .morpheus-wrapper{
    display:flex; flex-direction:column;
    height:calc(100vh - 128px);
    background:#000;
    font-family:'Share Tech Mono','Consolas',monospace;
  }
  .mirc-titlebar{
    background:#0a0a0a; border-bottom:1px solid var(--verde);
    padding:3px 10px; display:flex; justify-content:space-between; align-items:center;
    font-size:11px; letter-spacing:1px; flex-shrink:0;
  }
  .mirc-titlebar .left{ display:flex; align-items:center; gap:8px; }
  .mirc-titlebar .right{ display:flex; gap:8px; opacity:.6; }
  .mirc-titlebar .window-btn{ border:1px solid var(--verde); padding:0 6px; font-size:10px; }

  .channel-tabs{
    background:#0a0a0a; border-bottom:1px solid var(--border);
    padding:0 10px; display:flex; gap:0; font-size:11px; flex-shrink:0;
  }
  .channel-tabs .tab{
    padding:3px 12px; border:1px solid var(--border); border-bottom:none;
    color:var(--verde-dim); margin-right:2px; background:#000; letter-spacing:1px;
  }
  .channel-tabs .tab.active{
    color:var(--verde); border-color:var(--verde); background:#0a0a0a;
    box-shadow:0 0 8px rgba(0,255,65,.2);
  }

  .irc-container{
    flex:1; overflow-y:auto; padding:10px 14px;
    line-height:1.5; font-size:13px;
  }
  .irc-container::-webkit-scrollbar{ width:8px; }
  .irc-container::-webkit-scrollbar-track{ background:#000; }
  .irc-container::-webkit-scrollbar-thumb{ background:var(--verde); border:2px solid #000; }

  .line{ white-space:pre-wrap; word-wrap:break-word; margin:1px 0; }
  .ts{ color:var(--verde-dim); }
  .nick-morpheus{ color:var(--verde); font-weight:bold; }
  .nick-self{ color:var(--amarelo); font-weight:bold; }
  .msg-text{ color:var(--verde); }
  .msg-text-user{ color:#c8f5d6; }
  .system-line{ color:var(--amarelo); }
  .error-line{ color:var(--vermelho); }
  .news-header{ color:var(--amarelo); font-weight:bold; }
  .news-item{ color:#9fd9b6; }
  .news-item::before{ content:'> '; color:var(--verde-dim); }
  .join-line{ color:var(--verde-suave); }
  .typing-line{ color:var(--verde-dim); font-style:italic; }

  .irc-statusbar{
    background:#0a0a0a; border-top:1px solid var(--border);
    border-bottom:1px solid var(--verde); padding:2px 10px;
    font-size:10px; color:var(--verde-dim);
    display:flex; justify-content:space-between; gap:10px;
    flex-shrink:0; letter-spacing:1px;
  }
  .irc-statusbar .channel{ color:var(--verde); font-weight:bold; }
  .irc-statusbar .active{ color:var(--verde-suave); }

  .irc-input{
    background:#000; padding:6px 10px;
    display:flex; align-items:center; gap:8px;
    flex-shrink:0; border-top:1px solid var(--border);
  }
  .irc-input .prompt{ color:var(--verde); font-weight:bold; white-space:nowrap; font-size:13px; }
  .irc-input .prompt .nick{ color:var(--azul); }
  .irc-input .prompt .channel{ color:var(--amarelo); }
  #msgInput{
    flex:1; background:transparent; border:none;
    color:var(--verde); font-family:inherit; font-size:13px;
    padding:4px 0; outline:none; caret-color:var(--verde);
  }
  #msgInput::placeholder{ color:var(--verde-dim); opacity:.5; }
  .btn-irc{
    background:transparent; border:1px solid var(--verde);
    color:var(--verde); font-family:inherit;
    font-size:11px; padding:2px 10px; cursor:pointer; letter-spacing:1px;
  }
  .btn-irc:hover{ background:var(--verde); color:#000; }
  .btn-irc:disabled{ opacity:.3; cursor:not-allowed; }

  .erro-404{ padding:40px; color:var(--vermelho); }
  .erro-404 code{ color:var(--amarelo); }
</style>
</head>
<body class="<?= $inlineMorpheus ? 'morpheus-mode' : '' ?>">

<header class="topo-sistema">
  <div class="topo-titulo">
    <div class="logo">
      <span>[</span>
      <span>B10H4CK SISTEMAS</span>
      <span>]</span>
      <span class="cursor"></span>
    </div>
    <div class="relogio" id="relogio">--:--:--</div>
  </div>

  <nav class="menu-hacker">
    <?php foreach ($menu as $slug => $info): ?>
      <a href="?p=<?= $slug ?>" class="<?= $pagina === $slug ? 'ativo' : '' ?>">
        <span class="icone"><?= $info['icone'] ?></span>
        <?= $info['rotulo'] ?>
      </a>
    <?php endforeach; ?>
  </nav>

  <div class="status-bar">
    <span>
      <span class="verde">● ONLINE</span>
      &nbsp;|&nbsp; módulo: <span class="<?= $pagina === 'doomsday' ? 'vermelho' : 'verde' ?>"><?= $menu[$pagina]['rotulo'] ?></span>
      &nbsp;|&nbsp; arquivo: <?= htmlspecialchars($arquivo) ?>
    </span>
    <span id="statusDireita">carregando…</span>
  </div>
</header>

<main class="conteudo-sistema">

<?php if ($inlineMorpheus): ?>

  <!-- ========== MORPHEUS INLINE (mIRC) ========== -->
  <div class="morpheus-wrapper">
    <div class="mirc-titlebar">
      <div class="left">
        <span class="cursor" style="display:inline-block;width:8px;height:14px;background:var(--verde);"></span>
        <span>MORPHEUS IRC v5.2</span>
        <span style="opacity:.4;">— #matrix</span>
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
      <div class="line join-line">*** Tópico: MORPHEUS — preparação, sobrevivência e verdade</div>
      <div class="line join-line">*** Comandos: /clear /mundo /news [assunto]</div>
      <div class="line">&nbsp;</div>
      <div class="line system-line">*** MORPHEUS (morpheus@matrix) entrou no canal</div>
    </div>

    <div class="irc-statusbar">
      <span class="channel">#matrix</span>
      <span>Nicks: <span class="active">2</span></span>
      <span id="ircStatus">Conectado</span>
      <span id="ircTime">--:--:--</span>
    </div>

    <div class="irc-input">
      <span class="prompt">[<span class="nick">você</span>@<span class="channel">matrix</span>]$</span>
      <input type="text" id="msgInput" placeholder="digite sua mensagem..." autofocus autocomplete="off">
      <button class="btn-irc" id="sendBtn">ENVIAR</button>
    </div>
  </div>

<?php elseif (is_file(__DIR__ . '/' . $arquivo)): ?>

  <!-- ========== IFRAME PARA OUTRAS PÁGINAS ========== -->
  <iframe
    id="framePagina"
    class="frame-wrapper"
    src="<?= htmlspecialchars($arquivo) ?>"
    title="<?= htmlspecialchars($menu[$pagina]['rotulo']) ?>"
    scrolling="no"
    loading="eager">
  </iframe>

<?php else: ?>
  <div class="erro-404">
    <strong>ERRO 404:</strong> arquivo <code><?= htmlspecialchars($arquivo) ?></code> não encontrado.
  </div>
<?php endif; ?>

</main>

<script>
function atualizarRelogio(){
  const d = new Date();
  const p = n => String(n).padStart(2,'0');
  const t = p(d.getHours())+':'+p(d.getMinutes())+':'+p(d.getSeconds());
  document.getElementById('relogio').textContent = t;
  const sd = document.getElementById('statusDireita');
  if (sd) sd.textContent = 'ÚLTIMA ATUALIZAÇÃO :: ' + t;
  const it = document.getElementById('ircTime');
  if (it) it.textContent = t;
}
atualizarRelogio();
setInterval(atualizarRelogio, 1000);

document.addEventListener('keydown', function(e){
  const map = { '1':'consolidado', '2':'ia', '3':'noticias', '4':'cotacoes', '5':'doomsday', '6':'morpheus' };
  if (map[e.key] && !['INPUT','TEXTAREA'].includes(document.activeElement.tagName)){
    window.location.href = '?p=' + map[e.key];
  }
});

(function(){
  const frame = document.getElementById('framePagina');
  if (!frame) return;
  let ultimaAltura = 0;
  function ajustarAltura(){
    try {
      const doc = frame.contentDocument || frame.contentWindow.document;
      if (!doc || !doc.documentElement) return;
      const h = Math.max(
        doc.body ? doc.body.scrollHeight : 0,
        doc.documentElement ? doc.documentElement.scrollHeight : 0
      );
      if (Math.abs(h - ultimaAltura) > 5){
        ultimaAltura = h;
        frame.style.height = h + 'px';
      }
    } catch (_) {}
  }
  frame.addEventListener('load', function(){
    ajustarAltura();
    setTimeout(ajustarAltura, 200);
    setTimeout(ajustarAltura, 800);
    try {
      const doc = frame.contentDocument || frame.contentWindow.document;
      if (doc && window.MutationObserver){
        new MutationObserver(ajustarAltura).observe(doc.body || doc.documentElement, {
          childList: true, subtree: true, attributes: true
        });
      }
      if (doc && doc.body && window.ResizeObserver){
        new ResizeObserver(ajustarAltura).observe(doc.body);
      }
    } catch (_) {}
  });
  window.addEventListener('resize', ajustarAltura);
  setInterval(ajustarAltura, 1500);
})();

<?php if ($inlineMorpheus): ?>
(function(){
  const ircContainer = document.getElementById('ircContainer');
  const msgInput = document.getElementById('msgInput');
  const sendBtn = document.getElementById('sendBtn');
  const ircStatus = document.getElementById('ircStatus');
  let isWaiting = false;

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
  function addLine(html){
    const div = document.createElement('div');
    div.className = 'line';
    div.innerHTML = html;
    ircContainer.appendChild(div);
    scrollBottom();
  }

  function addUserMsg(text){
    addLine('<span class="ts">['+ts()+']</span> <span class="nick-self">&lt;você&gt;</span> <span class="msg-text-user">'+esc(text)+'</span>');
  }
  function addMorpheusMsg(text){
    addLine('<span class="ts">['+ts()+']</span> <span class="nick-morpheus">&lt;MORPHEUS&gt;</span> <span class="msg-text">'+esc(text)+'</span>');
  }
  function addSystem(text){
    addLine('<span class="ts">['+ts()+']</span> <span class="system-line">*** '+esc(text)+'</span>');
  }
  function addError(text){
    addLine('<span class="ts">['+ts()+']</span> <span class="error-line">*** ERRO: '+esc(text)+'</span>');
  }
  function addNews(title, items){
    addLine('<span class="ts">['+ts()+']</span> <span class="news-header">*** '+esc(title)+' ***</span>');
    if (!items || !items.length){ addLine('<span class="news-item">(sem resultados)</span>'); return; }
    items.forEach(item => addLine('<span class="news-item">'+esc(item)+'</span>'));
  }
  function showTyping(){
    const div = document.createElement('div');
    div.className = 'line';
    div.id = 'typingLine';
    div.innerHTML = '<span class="ts">['+ts()+']</span> <span class="nick-morpheus">&lt;MORPHEUS&gt;</span> <span class="typing-line">está digitando...</span>';
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
    ircStatus.textContent = 'Aguardando...';
    showTyping();

    fetch('morpheus.php?acao=chat', {
      method:'POST',
      headers:{'Content-Type':'application/json'},
      body: JSON.stringify({message:text})
    })
    .then(r => r.json())
    .then(data => {
      hideTyping();
      isWaiting = false;
      sendBtn.disabled = false;
      ircStatus.textContent = 'Conectado';
      if (data.error){ addError(data.error); return; }
      if (data.type === 'news') addNews(data.title, data.items);
      else if (data.type === 'system') addSystem(data.message);
      else addMorpheusMsg(data.message);
    })
    .catch(err => {
      hideTyping();
      isWaiting = false;
      sendBtn.disabled = false;
      ircStatus.textContent = 'Erro';
      addError('Falha de conexão: ' + err.message);
    });
  }

  msgInput.addEventListener('keydown', e => {
    if (e.key === 'Enter' && !e.shiftKey){ e.preventDefault(); sendMessage(); }
  });
  sendBtn.addEventListener('click', sendMessage);
  document.addEventListener('click', () => msgInput.focus());
  msgInput.focus();
})();
<?php endif; ?>
</script>
</body>
</html>