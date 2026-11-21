<?php
// ============================================================
// SISTEMA DOOMSDAY — Checklist em Bloco de Notas + IA
// PHP + Bootstrap 5 + jQuery AJAX + Pollinations API
// Exportação TXT estilo zine alinhado (72 colunas)
// ============================================================
declare(strict_types=1);

const API_KEY     = 'sk_ZU2KUB9PCvAoDtF6L4szjm8a9unkl0lE';
const API_URL     = 'https://gen.pollinations.ai/v1/chat/completions';
const API_MODEL   = 'openai';
const API_TIMEOUT = 120;

const ARQ_CONSOLIDADO = __DIR__ . '/consolidado.json';
const ARQ_HISTORICO   = __DIR__ . '/historico.json';
const ARQ_QUOTES      = __DIR__ . '/quotes_cache.json';
const ARQ_NOTICIAS    = __DIR__ . '/base_dados_deepseek.txt';
const ARQ_CACHE       = __DIR__ . '/doomsday_cache.json';

// ---------- Funções ----------
function lerJson(string $c): ?array {
    if (!is_file($c)) return null;
    $r = @file_get_contents($c);
    if ($r === false || trim($r) === '') return null;
    $j = json_decode($r, true);
    return is_array($j) ? $j : null;
}

function gravarJson(string $c, $d): bool {
    $t = $c . '.tmp';
    $ok = @file_put_contents($t, json_encode($d, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) !== false;
    if ($ok) @rename($t, $c);
    return $ok;
}

function parseValorNumerico($v): float {
    if (is_numeric($v)) return (float)$v;
    if (!is_string($v)) return 0.0;
    $v = preg_replace('/[R$\s]|USD|\$USD/i', '', $v);
    if (substr_count($v, ',') === 1 && substr_count($v, '.') === 0) $v = str_replace(',', '.', $v);
    elseif (substr_count($v, '.') > 0 && substr_count($v, ',') > 0) { $v = str_replace('.', '', $v); $v = str_replace(',', '.', $v); }
    return is_numeric($v) ? (float)$v : 0.0;
}

// ---------- Gerador do Zine TXT alinhado ----------
function gerarZine(string $texto, array $checks): string {
    // Largura interna fixa (entre as barras laterais)
    $W = 72;

    // --- Parse do checklist ---
    $itens = [];
    $total = 0; $marcados = 0;
    foreach (explode("\n", str_replace("\r\n", "\n", $texto)) as $raw) {
        $linha = rtrim($raw);
        if ($linha === '') continue;
        if (preg_match('/^#\s+(.+)$/', $linha, $m)) {
            $itens[] = ['tipo' => 'cat', 'texto' => trim($m[1])];
            continue;
        }
        if (preg_match('/^\[\s*\]\s*(.+)$/', $linha, $m)) {
            $idx = $total; $total++;
            $ok = !empty($checks[$idx]) || !empty($checks[(string)$idx]);
            if ($ok) $marcados++;
            $itens[] = ['tipo' => 'item', 'texto' => trim($m[1]), 'marcado' => $ok];
            continue;
        }
        $idx = $total; $total++;
        $ok = !empty($checks[$idx]) || !empty($checks[(string)$idx]);
        if ($ok) $marcados++;
        $itens[] = ['tipo' => 'item', 'texto' => $linha, 'marcado' => $ok];
    }

    // --- Nível e barra ---
    $pct = $total > 0 ? ($marcados / $total) * 100 : 0;
    if ($pct >= 70)      { $nivel = 'CRITICO';  $marca = '!!'; }
    elseif ($pct >= 50)  { $nivel = 'ALTO';     $marca = '! '; }
    elseif ($pct >= 30)  { $nivel = 'MODERADO'; $marca = '~ '; }
    else                 { $nivel = 'BAIXO';    $marca = '  '; }

    $barLen = 30;
    $preench = (int)round(($pct / 100) * $barLen);
    $barra = str_repeat('#', $preench) . str_repeat('.', $barLen - $preench);

    $dataHora = date('d/m/Y H:i:s');
    $data = date('d/m/Y');
    $hora = date('H:i:s');

    // Helpers de formatação (padding considera mb_strlen)
    $padD = function(string $s, int $len) { return $s . str_repeat(' ', max(0, $len - mb_strlen($s))); };
    $padE = function(string $s, int $len) { return str_repeat(' ', max(0, $len - mb_strlen($s))) . $s; };

    // --- Cabeçalho (banner simples, sem ASCII art) ---
    $out = [];
    $out[] = str_repeat('=', $W + 4);
    $out[] = '||' . $padD('', $W) . '||';
    $out[] = '||' . $padD('               B I O H A C K   S Y S   v 2 . 0', $W) . '||';
    $out[] = '||' . $padD('          "A PREPARACAO E A UNICA DEFESA"', $W) . '||';
    $out[] = '||' . $padD('', $W) . '||';
    $out[] = str_repeat('=', $W + 4);
    $out[] = '';

    // --- Bloco de status (moldura 72) ---
    $linhaBox = '+' . str_repeat('-', $W) . '+';
    $mk = function(string $txt) use ($W, $padD) {
        return '| ' . $padD($txt, $W - 1) . '|';
    };
    $out[] = ' ' . $linhaBox;
    $out[] = ' ' . $mk(' RELATORIO GERADO EM : ' . $dataHora);
    $out[] = ' ' . $mk(' NIVEL DE ALERTA     : [' . $marca . '] ' . $nivel);
    $out[] = ' ' . $mk(' PROGRESSO           : ' . $marcados . ' / ' . $total . ' ameacas marcadas (' . round($pct) . '%)');
    $out[] = ' ' . $mk(' BARRA               : [' . $barra . ']');
    $out[] = ' ' . $linhaBox;
    $out[] = '';

    // --- Título do checklist ---
    $titulo = 'C H E C K L I S T   D E   A M E A C A S';
    $linhaT = ' ' . '.' . str_repeat('-', $W) . '.';
    $out[] = $linhaT;
    $out[] = ' |' . $padE($titulo, (int)(($W + mb_strlen($titulo)) / 2)) . $padD('', (int)(($W - mb_strlen($titulo)) / 2)) . '|';
    $out[] = " '" . str_repeat('-', $W) . "'";

    // --- Corpo ---
    foreach ($itens as $it) {
        if ($it['tipo'] === 'cat') {
            $titulo = mb_strtoupper($it['texto']);
            $prefixo = '=== ' . $titulo . ' ';
            $resto = max(4, ($W + 4) - mb_strlen($prefixo));
            $out[] = '';
            $out[] = $prefixo . str_repeat('=', $resto);
            $out[] = '';
            continue;
        }
        $caixa = $it['marcado'] ? '[X]' : '[ ]';
        $out[] = '  ' . $caixa . ' ' . $it['texto'];
    }

    // --- Rodapé de ações ---
    $out[] = '';
    $out[] = ' ' . $linhaT;
    $tituloR = '>>  A C O E S   R E C O M E N D A D A S  <<';
    $out[] = ' |' . $padE($tituloR, (int)(($W + mb_strlen($tituloR)) / 2)) . $padD('', (int)(($W - mb_strlen($tituloR)) / 2)) . '|';
    $out[] = " '" . str_repeat('-', $W) . "'";
    $out[] = '';
    $out[] = '   [1] Estoques minimos: agua (14 dias), alimentos nao pereciveis (30 dias)';
    $out[] = '   [2] Combustivel e itens de primeira necessidade em reserva';
    $out[] = '   [3] Copia fisica deste relatorio no kit de emergencia';
    $out[] = '   [4] Monitoramento semanal de cotacoes e noticias de logistica/clima';
    $out[] = '   [5] Rede de contato de apoio definida e compartilhada';
    $out[] = '';

    // --- Rodapé final ---
    $out[] = str_repeat('=', $W + 4);
    $rodape = '[ BIOHACK SYS :: STAY PREPARED :: ' . $data . ' :: ' . $hora . ' ]';
    $out[] = str_repeat(' ', max(0, (int)((($W + 4) - mb_strlen($rodape)) / 2))) . $rodape;
    $out[] = str_repeat('=', $W + 4);
    $out[] = '';

    return implode("\n", $out);
}

// ============================================================
// ENDPOINT AJAX
// ============================================================
$acao = $_GET['acao'] ?? '';
if ($acao !== '') {

    // -------- EXPORTAR TXT (ZINE) --------
    if ($acao === 'exportar') {
        $cache = is_file(ARQ_CACHE) ? lerJson(ARQ_CACHE) : null;
        if (!$cache || empty($cache['texto'])) {
            header('Content-Type: text/plain; charset=utf-8');
            http_response_code(400);
            echo "Nenhum checklist gerado ainda. Gere primeiro no painel.";
            exit;
        }
        $zine = gerarZine($cache['texto'], $cache['checks'] ?? []);
        $nome = 'doomsday-' . date('Ymd-His') . '.txt';
        header('Content-Type: text/plain; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $nome . '"');
        header('Content-Length: ' . strlen($zine));
        echo $zine;
        exit;
    }

    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    try {
        // -------- ESTADO --------
        if ($acao === 'estado') {
            $cache = is_file(ARQ_CACHE) ? lerJson(ARQ_CACHE) : null;
            echo json_encode([
                'ok'       => true,
                'texto'    => $cache['texto']    ?? null,
                'checks'   => $cache['checks']   ?? [],
                'data'     => $cache['data']     ?? null,
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // -------- SALVAR MARCACOES --------
        if ($acao === 'salvar') {
            $body = file_get_contents('php://input');
            $json = json_decode($body, true);
            if (!is_array($json)) throw new RuntimeException('Dados inválidos.');
            $cache = is_file(ARQ_CACHE) ? (lerJson(ARQ_CACHE) ?? []) : [];
            $cache['checks'] = $json['checks'] ?? [];
            $cache['data']   = date('Y-m-d H:i:s');
            gravarJson(ARQ_CACHE, $cache);
            echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // -------- GERAR CHECKLIST COM IA --------
        if ($acao === 'gerar') {
            set_time_limit(180);
            $force = ($_GET['force'] ?? '') === '1';

            if (!$force && is_file(ARQ_CACHE)) {
                $cache = lerJson(ARQ_CACHE);
                if ($cache && ($cache['data_geracao'] ?? '') === date('Y-m-d') && !empty($cache['texto'])) {
                    echo json_encode(['ok' => true, 'cached' => true, 'texto' => $cache['texto'], 'checks' => $cache['checks'] ?? []], JSON_UNESCAPED_UNICODE);
                    exit;
                }
            }

            $consolidado = lerJson(ARQ_CONSOLIDADO);
            $historico   = lerJson(ARQ_HISTORICO);
            $quotes      = lerJson(ARQ_QUOTES);
            if (!$consolidado || !isset($consolidado['itens'])) throw new RuntimeException('consolidado.json inválido.');

            $cotacoes = [];
            foreach ($consolidado['itens'] as $item) {
                if (($item['tipo'] ?? '') !== 'cotacao') continue;
                $cotacoes[] = $item['descricao'] . ' | ' . $item['valor'] . ' ' . $item['unidade'];
            }

            $snapshots = $historico['snapshots'] ?? [];
            usort($snapshots, fn($a, $b) => strcmp($a['data'] ?? '', $b['data'] ?? ''));
            $anterior = count($snapshots) >= 2 ? $snapshots[count($snapshots) - 2] : null;
            $variacoes = [];
            if ($anterior) {
                $mapAnt = [];
                foreach ($anterior['itens'] ?? [] as $it) $mapAnt[$it['descricao'] ?? ''] = parseValorNumerico($it['valor'] ?? '');
                foreach ($consolidado['itens'] as $item) {
                    if (($item['tipo'] ?? '') !== 'cotacao') continue;
                    $nome = $item['descricao'];
                    $vAnt = null;
                    foreach ($mapAnt as $desc => $v) { if (str_starts_with($desc, explode(' (', $nome)[0])) { $vAnt = $v; break; } }
                    if ($vAnt !== null && $vAnt != 0.0) {
                        $atual = parseValorNumerico($item['valor']);
                        $variacoes[] = sprintf("%s: %.2f%%", $nome, (($atual - $vAnt) / abs($vAnt)) * 100);
                    }
                }
            }

            $noticiasTexto = '';
            if (is_file(ARQ_NOTICIAS) && filesize(ARQ_NOTICIAS) > 200) {
                $noticiasTexto = @file_get_contents(ARQ_NOTICIAS);
                if (mb_strlen($noticiasTexto) > 5000) $noticiasTexto = mb_substr($noticiasTexto, 0, 5000) . "\n[...truncado...]";
            }

            $indicadores = '';
            if ($quotes) {
                $p = [];
                foreach (['dolar','gasolina','petroleo','ouro','prata','bitcoin'] as $k) if (isset($quotes[$k])) $p[] = "{$k}: {$quotes[$k]}";
                $indicadores = "\n\nINDICADORES:\n" . implode("\n", $p);
            }

            $systemPrompt = <<<PROMPT
Você é um analista sênior de risco e segurança alimentar focado no Brasil.

Receberá: cotações agropecuárias, variações, indicadores macro e notícias recentes.

Sua tarefa: gerar um CHECKLIST em TEXTO PURO (markdown simples) sobre ameaças e riscos de desabastecimento.

FORMATO EXATO — siga à risca:

# Clima
[ ] Frase curta descrevendo a ameaça
[ ] Outra frase curta

# Logística
[ ] Frase curta
[ ] Frase curta

# Insumos
[ ] Frase curta

# Geopolítica
[ ] Frase curta

# Abastecimento
[ ] Frase curta

# Infraestrutura
[ ] Frase curta

REGRAS:
- Use EXATAMENTE os cabeçalhos: # Clima, # Logística, # Insumos, # Geopolítica, # Abastecimento, # Infraestrutura (nessa ordem).
- Cada ameaça é uma linha começando com "[ ] " (colchetes + espaço).
- Frases curtas (máximo 90 caracteres), diretas, sem ponto final.
- Entre 2 e 4 itens por categoria.
- Baseie-se APENAS nos dados fornecidos. Se uma notícia indicar risco (seca, greve, guerra, alta de preço, etc.), reflita no item correspondente.
- Não use numeração, nem emojis, nem negrito. Apenas texto puro.
- Não escreva introdução, conclusão, nem comentários fora do checklist.
PROMPT;

            $userContent = "COTAÇÕES ATUAIS:\n" . implode("\n", $cotacoes)
                         . ($variacoes ? "\n\nVARIAÇÕES:\n" . implode("\n", $variacoes) : "")
                         . $indicadores
                         . "\n\nNOTÍCIAS RECENTES:\n" . $noticiasTexto;

            $payload = [
                'model' => API_MODEL,
                'messages' => [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => $userContent],
                ],
                'temperature' => 0.4,
                'max_tokens' => 1500,
            ];

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
            $curlErr = curl_error($ch);
            curl_close($ch);

            if ($response === false) throw new RuntimeException("cURL: {$curlErr}");
            $jsonResp = json_decode($response, true);
            if ($httpCode !== 200 || !isset($jsonResp['choices'][0]['message']['content'])) {
                throw new RuntimeException("API HTTP {$httpCode}: " . substr($response, 0, 250));
            }

            $texto = trim($jsonResp['choices'][0]['message']['content']);
            $texto = preg_replace('/^```[a-z]*\s*/i', '', $texto);
            $texto = preg_replace('/\s*```\s*$/i', '', $texto);
            $texto = str_replace("\r\n", "\n", $texto);

            if (mb_strlen(trim($texto)) < 30) throw new RuntimeException('A IA não retornou um checklist válido.');

            $cache = is_file(ARQ_CACHE) ? (lerJson(ARQ_CACHE) ?? []) : [];
            $cache['texto']          = $texto;
            $cache['data_geracao']   = date('Y-m-d');
            $cache['data']           = date('Y-m-d H:i:s');
            $cache['checks']         = $cache['checks'] ?? [];
            gravarJson(ARQ_CACHE, $cache);

            echo json_encode(['ok' => true, 'cached' => false, 'texto' => $texto, 'checks' => $cache['checks']], JSON_UNESCAPED_UNICODE);
            exit;
        }

        throw new RuntimeException('Ação desconhecida.');
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'erro' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}
?><!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>SISTEMA DOOMSDAY :: Checklist</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<style>
  :root{
    --bg:#0a0d0f; --card:#181d22; --border:#2e3840;
    --verde:#00ff66; --verde-suave:#66ff99; --verde-dim:#44aa66;
    --vermelho:#ff6666; --amarelo:#ffcc44; --azul:#66ccff;
  }
  body{
    background:
      repeating-linear-gradient(0deg, rgba(0,255,102,.012) 0px, rgba(0,255,102,.012) 1px, transparent 1px, transparent 3px),
      var(--bg);
    color:var(--verde);
    font-family:'Consolas','Courier New',monospace;
    min-height:100vh; padding-bottom:60px;
  }
  h1{
    font-size:1.1rem; text-transform:uppercase; letter-spacing:3px;
    border-bottom:1px solid var(--verde); padding-bottom:8px; margin-bottom:6px;
    text-shadow:0 0 10px rgba(0,255,102,.5);
  }
  .subtitulo{ font-size:.72rem; color:var(--verde-dim); margin-bottom:20px; }

  .risk-bar{
    background:var(--card); border:1px solid var(--border);
    border-radius:8px; padding:16px 20px; margin-bottom:20px;
    display:flex; align-items:center; gap:20px; flex-wrap:wrap;
  }
  .risk-gauge{ flex:1; min-width:200px; }
  .risk-gauge .barra{
    height:22px; background:#0f1316; border-radius:4px;
    overflow:hidden; border:1px solid var(--border);
  }
  .risk-gauge .preenchimento{
    height:100%; transition:width .4s ease;
    background:linear-gradient(90deg,var(--verde-dim),var(--verde));
  }
  .risk-nivel{
    font-size:1.8rem; font-weight:bold; letter-spacing:4px;
    text-shadow:0 0 12px currentColor;
  }
  .risk-pct{ font-size:.85rem; color:var(--verde-dim); }

  .bloco{
    background:#12161a;
    border:1px solid var(--border);
    border-radius:8px;
    padding:20px 24px;
    font-family:'Consolas','Courier New',monospace;
    font-size:.88rem;
    line-height:1.8;
    color:var(--verde-suave);
    box-shadow:inset 0 0 30px rgba(0,0,0,.4);
    min-height:300px;
    position:relative;
  }
  .bloco::before{
    content:'';
    position:absolute; top:0; bottom:0; left:38px;
    border-left:1px dashed rgba(255,102,102,.15);
    pointer-events:none;
  }
  .bloco .cat{
    color:var(--amarelo);
    font-weight:bold;
    font-size:1rem;
    letter-spacing:2px;
    text-transform:uppercase;
    margin-top:18px;
    margin-bottom:4px;
    text-shadow:0 0 6px rgba(255,204,68,.35);
    padding-left:20px;
  }
  .bloco .cat:first-child{ margin-top:0; }

  .bloco .item{
    display:flex; align-items:flex-start; gap:10px;
    padding:3px 0 3px 20px;
    cursor:pointer;
    border-radius:3px;
    transition:background .1s;
    user-select:none;
  }
  .bloco .item:hover{ background:rgba(0,255,102,.04); }
  .bloco .item .caixa{
    flex-shrink:0;
    color:var(--verde-dim);
    font-weight:bold;
    transition:color .15s;
  }
  .bloco .item .texto{ flex:1; color:var(--verde-suave); }
  .bloco .item.marcado .caixa{ color:var(--vermelho); text-shadow:0 0 6px var(--vermelho); }
  .bloco .item.marcado .texto{
    color:var(--vermelho);
    text-decoration:line-through;
    opacity:.75;
  }

  .btn-terminal{
    background:#181d22; color:var(--verde); border:1px solid var(--verde);
    font-size:.75rem; letter-spacing:1px;
  }
  .btn-terminal:hover{ background:var(--verde); color:var(--bg); }
  .btn-terminal:disabled{ opacity:.5; }
  .btn-perigo{ color:var(--vermelho); border-color:var(--vermelho); }
  .btn-perigo:hover{ background:var(--vermelho); color:var(--bg); }
  .btn-exportar{ color:var(--amarelo); border-color:var(--amarelo); text-shadow:0 0 6px rgba(255,204,68,.4); }
  .btn-exportar:hover{ background:var(--amarelo); color:var(--bg); }

  .spinner{
    display:inline-block; width:14px; height:14px;
    border:2px solid var(--verde-dim); border-top-color:var(--verde);
    border-radius:50%; animation:girar .8s linear infinite;
    vertical-align:-2px; margin-right:6px;
  }
  @keyframes girar{ to{ transform:rotate(360deg); } }
  .status-msg{ font-size:.75rem; color:var(--verde-dim); }
  .vazio{ text-align:center; color:var(--verde-dim); padding:40px 0; font-size:.85rem; }
</style>
</head>
<body>
<div class="container-fluid px-3 py-3">

  <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
    <div>
      <h1>[&gt;] SISTEMA DOOMSDAY :: Checklist</h1>
      <div class="subtitulo">Ameaças de desabastecimento geradas por IA · clique nos itens para marcar</div>
    </div>
    <div class="d-flex gap-2 flex-wrap">
      <button id="btnGerar"  class="btn btn-terminal">▶ Gerar Checklist IA</button>
      <button id="btnForcar" class="btn btn-terminal" title="Ignora cache do dia">⟳ Forçar</button>
      <button id="btnSalvar" class="btn btn-terminal">💾 Salvar marcações</button>
      <a id="btnExportar" class="btn btn-terminal btn-exportar" href="?acao=exportar">⬇ Baixar TXT</a>
      <button id="btnLimpar" class="btn btn-terminal btn-perigo">🗑 Limpar marcações</button>
    </div>
  </div>

  <div class="risk-bar">
    <div>
      <div class="risk-nivel" id="riskNivel" style="color:var(--verde-suave);">—</div>
      <div class="risk-pct" id="riskPct">0 de 0 itens marcados</div>
    </div>
    <div class="risk-gauge">
      <div class="barra"><div class="preenchimento" id="riskBarra" style="width:0%;"></div></div>
    </div>
    <div style="font-size:.7rem;color:var(--verde-dim);max-width:280px;">
      Itens marcados indicam ameaças <strong style="color:var(--vermelho);">ativas ou iminentes</strong>.
    </div>
  </div>

  <div class="bloco" id="bloco">
    <div class="vazio">
      Nenhum checklist gerado ainda.<br>
      Clique em <b style="color:var(--verde);">▶ Gerar Checklist IA</b> para analisar cotações e notícias.
    </div>
  </div>

  <div class="text-center status-msg mt-3" id="statusMsg">Pronto.</div>
</div>

<script>
$(function(){
  let checks = {};
  let linhas = [];

  function setStatus(msg){ $('#statusMsg').html(msg); }

  function parseTexto(texto){
    const out = [];
    const linhasTexto = (texto || '').split('\n');
    let itemIdx = 0;
    linhasTexto.forEach(function(raw){
      const linha = raw.replace(/\s+$/, '');
      if (!linha) return;
      const mCat = linha.match(/^#\s+(.+)$/);
      if (mCat){
        out.push({ tipo: 'cat', texto: mCat[1].trim() });
        return;
      }
      const mItem = linha.match(/^\[\s*\]\s*(.+)$/);
      if (mItem){
        out.push({ tipo: 'item', texto: mItem[1].trim(), idx: itemIdx });
        itemIdx++;
        return;
      }
      out.push({ tipo: 'item', texto: linha.trim(), idx: itemIdx });
      itemIdx++;
    });
    return out;
  }

  function render(){
    const bloco = $('#bloco').empty();
    if (!linhas.length){
      bloco.html('<div class="vazio">Nenhum checklist gerado ainda.<br>Clique em <b style="color:var(--verde);">▶ Gerar Checklist IA</b> para analisar cotações e notícias.</div>');
      return;
    }
    linhas.forEach(function(l){
      if (l.tipo === 'cat'){
        bloco.append($('<div class="cat"></div>').text('# ' + l.texto));
        return;
      }
      const marcado = !!checks[l.idx];
      const el = $('<div class="item"></div>').attr('data-idx', l.idx);
      if (marcado) el.addClass('marcado');
      el.append($('<span class="caixa"></span>').text(marcado ? '[X]' : '[ ]'));
      el.append($('<span class="texto"></span>').text(l.texto));
      el.on('click', function(){
        const idx = $(this).data('idx');
        if (checks[idx]) { delete checks[idx]; $(this).removeClass('marcado'); $(this).find('.caixa').text('[ ]'); }
        else             { checks[idx] = true; $(this).addClass('marcado'); $(this).find('.caixa').text('[X]'); }
        atualizarRisco();
      });
      bloco.append(el);
    });
    atualizarRisco();
  }

  function atualizarRisco(){
    const total = linhas.filter(l => l.tipo === 'item').length;
    const marcados = Object.keys(checks).length;
    const pct = total > 0 ? (marcados / total) * 100 : 0;
    let nivel = 'BAIXO', cor = '#66ff99';
    if (pct >= 70) { nivel = 'CRÍTICO'; cor = '#ff6666'; }
    else if (pct >= 50) { nivel = 'ALTO'; cor = '#ffcc44'; }
    else if (pct >= 30) { nivel = 'MODERADO'; cor = '#66ccff'; }
    $('#riskNivel').text(nivel).css('color', cor);
    $('#riskPct').text(marcados + ' de ' + total + ' itens marcados (' + pct.toFixed(0) + '%)');
    $('#riskBarra').css({
      width: pct + '%',
      background: pct >= 50 ? 'linear-gradient(90deg,#ff6666,#ff9966)' : 'linear-gradient(90deg,var(--verde-dim),var(--verde))'
    });
  }

  function carregarTexto(texto, checksSalvos){
    linhas = parseTexto(texto);
    checks = checksSalvos || {};
    render();
  }

  function gerar(forcar){
    const $b = $('#btnGerar, #btnForcar'); $b.prop('disabled', true);
    setStatus('<span class="spinner"></span>Analisando cotações e notícias… pode levar até 90s.');
    const url = '?acao=gerar' + (forcar ? '&force=1' : '');
    $.ajax({ url, method:'GET', dataType:'json', timeout:180000 })
      .done(function(res){
        if (!res || !res.ok){ setStatus('❌ Erro: ' + (res?.erro || '?')); return; }
        carregarTexto(res.texto, res.checks || {});
        setStatus((res.cached ? '✔ Cache do dia' : '✔ Checklist gerado') + ' · ' + new Date().toLocaleTimeString('pt-BR'));
      })
      .fail(function(xhr){
        let d = xhr.status; try { d += ' ' + (xhr.responseJSON?.erro || xhr.responseText?.slice(0,150) || ''); } catch(_){}
        setStatus('❌ Falha: ' + d);
      })
      .always(function(){ $b.prop('disabled', false); });
  }

  function salvar(){
    setStatus('Salvando marcações…');
    $.ajax({
      url: '?acao=salvar',
      method: 'POST',
      contentType: 'application/json',
      dataType: 'json',
      data: JSON.stringify({ checks })
    }).done(function(res){
      setStatus(res && res.ok ? '✔ Marcações salvas.' : '❌ Erro ao salvar.');
    }).fail(function(xhr){
      setStatus('❌ Falha: ' + xhr.status);
    });
  }

  $('#btnGerar').on('click', function(){ gerar(false); });
  $('#btnForcar').on('click', function(){
    if (!confirm('Ignorar cache do dia e chamar a IA novamente?')) return;
    gerar(true);
  });
  $('#btnSalvar').on('click', salvar);
  $('#btnLimpar').on('click', function(){
    if (!confirm('Desmarcar todos os itens?')) return;
    checks = {};
    render();
    setStatus('Marcações limpas.');
  });

  $.getJSON('?acao=estado').done(function(res){
    if (res && res.ok && res.texto){
      carregarTexto(res.texto, res.checks || {});
      setStatus(res.data ? 'Último checklist de ' + res.data : 'Checklist carregado.');
    } else {
      setStatus('Clique em "Gerar Checklist IA" para analisar os dados.');
    }
  }).fail(function(){
    setStatus('Clique em "Gerar Checklist IA" para analisar os dados.');
  });
});
</script>
</body>
</html>