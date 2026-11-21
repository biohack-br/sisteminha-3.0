<?php
// ============================================================
// Painel Notícias + Previsão de Mercado — Pollinations API
// Arquivo único: PHP + Bootstrap 5 + jQuery AJAX
// ============================================================
declare(strict_types=1);

// ---------- Configuração Pollinations ----------
const API_KEY     = 'sk_ZU2KUB9PCvAoDtF6L4szjm8a9unkl0lE';
const API_URL     = 'https://gen.pollinations.ai/v1/chat/completions';
const API_MODEL   = 'openai';
const API_TIMEOUT = 120;

const ARQ_CONSOLIDADO = __DIR__ . '/consolidado.json';
const ARQ_HISTORICO   = __DIR__ . '/historico.json';
const ARQ_QUOTES      = __DIR__ . '/quotes_cache.json';
const ARQ_NOTICIAS    = __DIR__ . '/base_dados_deepseek.txt';
const ARQ_CACHE       = __DIR__ . '/previsao_cache.json';

// ---------- Categorias de notícias ----------
const TOPICS = [
    'crise_alimentar'       => 'escassez alimentos OR falta alimentos OR inseguranca alimentar',
    'agricultura_colheitas' => 'quebra safra OR seca agricultura OR pragas lavouras',
    'cadeia_abastecimento'  => 'desabastecimento OR falta produtos OR logistica cargas',
    'petroleo'              => 'preco petroleo OR crise combustiveis OR falta diesel',
    'crise_agua'            => 'racionamento agua OR crise hidrica OR reservatorios seca',
    'guerras'               => 'guerra OR conflito armado OR escalada militar',
    'colapso_economico'     => 'inflacao OR recessao economia OR crise bancaria',
    'greve_caminhoneiros'   => 'greve caminhoneiros OR bloqueio rodovias OR paralizacao cargas',
    'portos_comercio'       => 'porto congestionado OR fechamento porto OR frete',
    'climatico'             => 'onda de calor OR frio extremo OR tempestade severa',
    'doencas_animais'       => 'doenca gado OR gripe aviaria animais OR peste suina',
    'fertilizantes_insumos' => 'escassez fertilizantes OR preco fertilizantes',
    'fronteiras_sancoes'    => 'fechamento fronteiras OR sancoes comerciais',
    'meteorologia'          => 'alerta meteorologico OR alerta enchente OR alerta calor',
];

const WORLD_NEWS_URL = 'https://news.google.com/rss/topics/CAAqKggKIiRDQkFTRlFvSUwyMHZNRGx1YlY4U0JYQjBMVUpTR2dKQ1VpZ0FQAQ?hl=pt-BR&gl=BR&ceid=BR%3Apt-419';

// ---------- Funções auxiliares ----------
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

function fetchRss(string $url, int $limit = 5): array {
    $ctx = stream_context_create(['http' => [
        'method'  => 'GET',
        'header'  => "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36\r\nAccept: application/rss+xml, application/xml, text/xml\r\n",
        'timeout' => 15,
    ]]);
    $content = @file_get_contents($url, false, $ctx);
    if ($content === false || trim($content) === '') return [];
    $content = preg_replace('/xmlns(:\w+)?="[^"]*"/', '', $content);
    $xml = @simplexml_load_string($content);
    if ($xml === false) return [];
    $items = []; $count = 0;
    foreach ($xml->channel->item ?? [] as $item) {
        if ($count >= $limit) break;
        $title = trim((string)$item->title);
        if ($title === '' || mb_strlen($title) < 5) continue;
        $items[] = ['titulo' => mb_substr($title, 0, 250)];
        $count++;
    }
    return $items;
}

function getNewsByTopic(string $q, int $limit = 4): array {
    $p = http_build_query(['q' => $q, 'hl' => 'pt-BR', 'gl' => 'BR', 'ceid' => 'BR:pt-419']);
    return fetchRss('https://news.google.com/rss/search?' . $p, $limit);
}

// Repara JSON truncado fechando strings, colchetes e chaves abertas
function repararJson(string $s): ?array {
    $s = trim($s);
    // remove markdown
    $s = preg_replace('/^```(?:json)?\s*/i', '', $s);
    $s = preg_replace('/\s*```\s*$/i', '', $s);
    // pega do primeiro { ao último }
    $ini = strpos($s, '{');
    $fim = strrpos($s, '}');
    if ($ini !== false && $fim !== false && $fim > $ini) {
        $s = substr($s, $ini, $fim - $ini + 1);
    }
    $j = json_decode($s, true);
    if (is_array($j)) return $j;

    // Tenta reparar
    $r = $s;
    $r = preg_replace('/,\s*$/', '', $r);
    // se string aberta no fim
    $aspas = substr_count($r, '"');
    if ($aspas % 2 !== 0) $r .= '"';
    // fecha colchetes e chaves na ordem correta (LIFO aproximado)
    $abreC = substr_count($r, '{');
    $fechaC = substr_count($r, '}');
    $abreB = substr_count($r, '[');
    $fechaB = substr_count($r, ']');
    for ($i = 0; $i < ($abreB - $fechaB); $i++) $r .= ']';
    for ($i = 0; $i < ($abreC - $fechaC); $i++) $r .= '}';
    $j = json_decode($r, true);
    return is_array($j) ? $j : null;
}

// ============================================================
// ENDPOINT AJAX
// ============================================================
$acao = $_GET['acao'] ?? '';
if ($acao !== '') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    try {
        // ---------- COLETAR NOTÍCIAS ----------
        if ($acao === 'coletar') {
            set_time_limit(240);
            $data = [];
            foreach (TOPICS as $tema => $query) {
                $data[$tema] = getNewsByTopic($query, 4);
                usleep(250000);
            }
            $data['noticias_mundiais'] = fetchRss(WORLD_NEWS_URL, 8);

            $linhas = [];
            foreach ($data as $cat => $itens) {
                $linhas[] = strtoupper(str_replace('_', ' ', $cat));
                if ($itens) { foreach ($itens as $it) $linhas[] = $it['titulo']; }
                else $linhas[] = '(sem resultados)';
                $linhas[] = '';
            }
            @file_put_contents(ARQ_NOTICIAS, implode("\n", $linhas) . "\n");
            $totalT = 0; foreach ($data as $itens) $totalT += count($itens);
            echo json_encode(['ok' => true, 'total_titulos' => $totalT, 'categorias' => count($data)], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // ---------- ESTADO (cache) ----------
        if ($acao === 'estado') {
            $cache = is_file(ARQ_CACHE) ? lerJson(ARQ_CACHE) : null;
            echo json_encode([
                'ok' => true,
                'cache' => $cache,
                'noticias' => is_file(ARQ_NOTICIAS) ? ['existe' => true, 'tamanho' => filesize(ARQ_NOTICIAS)] : ['existe' => false]
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // ---------- PREVER COM IA ----------
        if ($acao === 'prever') {
            set_time_limit(180);
            $force = ($_GET['force'] ?? '') === '1';

            if (!$force && is_file(ARQ_CACHE)) {
                $cache = lerJson(ARQ_CACHE);
                if ($cache && ($cache['data'] ?? '') === date('Y-m-d')) {
                    echo json_encode(['ok' => true, 'cached' => true, 'result' => $cache['result'], 'meta' => $cache['meta'] ?? null], JSON_UNESCAPED_UNICODE);
                    exit;
                }
            }

            $consolidado = lerJson(ARQ_CONSOLIDADO);
            $historico   = lerJson(ARQ_HISTORICO);
            $quotes      = lerJson(ARQ_QUOTES);
            if (!$consolidado || !isset($consolidado['itens'])) {
                throw new RuntimeException('consolidado.json inválido ou ausente.');
            }

            $cotacoes = [];
            foreach ($consolidado['itens'] as $item) {
                if (($item['tipo'] ?? '') !== 'cotacao') continue;
                $cotacoes[] = ['produto' => $item['descricao'] ?? '', 'valor' => $item['valor'] ?? '', 'unidade' => $item['unidade'] ?? ''];
            }

            $snapshots = $historico['snapshots'] ?? [];
            usort($snapshots, fn($a, $b) => strcmp($a['data'] ?? '', $b['data'] ?? ''));
            $anterior = count($snapshots) >= 2 ? $snapshots[count($snapshots) - 2] : null;
            $variacoes = [];
            if ($anterior) {
                $mapAnt = [];
                foreach ($anterior['itens'] ?? [] as $it) $mapAnt[$it['descricao'] ?? ''] = parseValorNumerico($it['valor'] ?? '');
                foreach ($cotacoes as $c) {
                    $nome = $c['produto']; $vAnt = null;
                    foreach ($mapAnt as $desc => $v) { if (str_starts_with($desc, explode(' (', $nome)[0])) { $vAnt = $v; break; } }
                    if ($vAnt !== null && $vAnt != 0.0) {
                        $atual = parseValorNumerico($c['valor']);
                        $variacoes[] = sprintf("%s: %.2f%%", $nome, (($atual - $vAnt) / abs($vAnt)) * 100);
                    }
                }
            }

            $noticiasTexto = '';
            if (is_file(ARQ_NOTICIAS) && filesize(ARQ_NOTICIAS) > 200) {
                $noticiasTexto = @file_get_contents(ARQ_NOTICIAS);
                if (mb_strlen($noticiasTexto) > 3500) $noticiasTexto = mb_substr($noticiasTexto, 0, 3500) . "\n[...truncado...]";
            } else {
                $partes = [];
                foreach (array_keys(TOPICS) as $t) {
                    $its = getNewsByTopic(TOPICS[$t], 2);
                    if ($its) { $partes[] = strtoupper(str_replace('_', ' ', $t)); foreach ($its as $it) $partes[] = $it['titulo']; }
                }
                $noticiasTexto = implode("\n", $partes);
            }

            $indicadores = '';
            if ($quotes) {
                $p = [];
                foreach (['dolar','gasolina','petroleo','ouro','prata','bitcoin'] as $k) if (isset($quotes[$k])) $p[] = "{$k}: {$quotes[$k]}";
                $indicadores = "\n\nINDICADORES:\n" . implode("\n", $p);
            }

            $cotTxt = []; foreach ($cotacoes as $c) $cotTxt[] = "{$c['produto']} | {$c['valor']} {$c['unidade']}";

            $systemPrompt = <<<PROMPT
Você é analista sênior de commodities e segurança alimentar, focado no Brasil (interno e externo).
Receberá cotações, variações, indicadores e notícias por categoria.

Responda EXCLUSIVAMENTE com um objeto JSON válido, sem markdown, sem crases, sem texto antes ou depois. Formato exato:

{
  "resumo_executivo": "2-3 frases do cenário geral.",
  "mercado_interno": {"tendencia":"alta|queda|estavel","alimentos_alta":[{"produto":"","motivo":""}],"alimentos_queda":[{"produto":"","motivo":""}]},
  "mercado_externo": {"tendencia":"alta|queda|estavel","alimentos_alta":[{"produto":"","motivo":""}],"alimentos_queda":[{"produto":"","motivo":""}]},
  "produtos_destaque": [{"produto":"","motivo":""}],
  "maior_demanda": [{"produto":"","motivo":""}],
  "alertas_risco": [{"produto":"","motivo":""}],
  "base_noticias": ["Categoria: título resumido"]
}

Regras:
- Máximo 3 itens por lista (para caber no limite de tokens).
- Motivos com no máximo 20 palavras.
- Use produtos das cotações fornecidas.
- Cite pelo menos 3 notícias em base_noticias.
- Não invente números.
- Se faltar dado, escreva isso no resumo_executivo.
PROMPT;

            $userContent = "COTAÇÕES:\n" . implode("\n", $cotTxt)
                         . ($variacoes ? "\n\nVARIAÇÕES:\n" . implode("\n", $variacoes) : "")
                         . $indicadores . "\n\nNOTÍCIAS:\n" . $noticiasTexto;

            $payload = [
                'model' => API_MODEL,
                'messages' => [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => $userContent],
                ],
                'temperature' => 0.3,
                'max_tokens' => 1500,
            ];

            $ch = curl_init(API_URL);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . API_KEY,
                ],
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

            $conteudo = $jsonResp['choices'][0]['message']['content'];
            $result = repararJson($conteudo);

            if (!is_array($result)) {
                $result = [
                    'resumo_executivo' => 'Não foi possível interpretar a resposta da IA. Tente novamente.',
                    'mercado_interno' => ['tendencia'=>'estavel','alimentos_alta'=>[],'alimentos_queda'=>[]],
                    'mercado_externo' => ['tendencia'=>'estavel','alimentos_alta'=>[],'alimentos_queda'=>[]],
                    'produtos_destaque' => [], 'maior_demanda' => [], 'alertas_risco' => [], 'base_noticias' => []
                ];
            }

            // Garante estrutura completa
            $result += [
                'resumo_executivo' => '',
                'mercado_interno' => ['tendencia'=>'estavel','alimentos_alta'=>[],'alimentos_queda'=>[]],
                'mercado_externo' => ['tendencia'=>'estavel','alimentos_alta'=>[],'alimentos_queda'=>[]],
                'produtos_destaque' => [], 'maior_demanda' => [], 'alertas_risco' => [], 'base_noticias' => []
            ];
            if (!isset($result['mercado_interno']['tendencia'])) $result['mercado_interno']['tendencia'] = 'estavel';
            if (!isset($result['mercado_externo']['tendencia'])) $result['mercado_externo']['tendencia'] = 'estavel';

            $meta = ['cotacoes' => count($cotacoes), 'comparado_com' => $anterior['data'] ?? null, 'gerado_em' => date('c')];
            gravarJson(ARQ_CACHE, ['data' => date('Y-m-d'), 'result' => $result, 'meta' => $meta]);

            echo json_encode(['ok' => true, 'cached' => false, 'result' => $result, 'meta' => $meta], JSON_UNESCAPED_UNICODE);
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
<title>Painel Notícias + Previsão de Mercado</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<style>
  :root{--bg:#0f1316;--card:#181d22;--border:#2e3840;--verde:#00ff66;--verde-suave:#66ff99;
    --verde-dim:#44aa66;--vermelho:#ff6666;--amarelo:#ffcc44;--azul:#66ccff;}
  body{background:var(--bg);color:var(--verde);font-family:'Consolas','Courier New',monospace;
    min-height:100vh;padding-bottom:40px;}
  h1{font-size:1.2rem;text-transform:uppercase;letter-spacing:2px;border-bottom:1px solid var(--verde);
    padding-bottom:8px;margin-bottom:6px;text-shadow:0 0 8px rgba(0,255,102,.35);}
  .subtitulo{font-size:.75rem;color:var(--verde-dim);margin-bottom:16px;}
  .card-painel{background:var(--card);border:1px solid var(--border);border-radius:8px;overflow:hidden;}
  .card-painel .card-header{background:#12171b;border-bottom:1px solid var(--border);color:var(--verde-suave);
    font-weight:bold;font-size:.82rem;letter-spacing:1px;text-transform:uppercase;}
  .btn-terminal{background:#181d22;color:var(--verde);border:1px solid var(--verde);
    font-size:.78rem;letter-spacing:1px;transition:all .15s;}
  .btn-terminal:hover{background:var(--verde);color:var(--bg);}
  .btn-terminal:disabled{opacity:.5;}
  .btn-sec{color:var(--azul);border-color:var(--azul);}
  .btn-sec:hover{background:var(--azul);color:var(--bg);}
  .status{font-size:.76rem;color:var(--verde-dim);}
  .kpi{background:#12171b;border:1px solid var(--border);border-radius:6px;padding:8px 10px;font-size:.78rem;}
  .kpi .num{font-size:1.1rem;font-weight:bold;color:var(--verde-suave);}
  .item-analise{display:flex;gap:10px;align-items:flex-start;padding:8px 10px;
    border-bottom:1px dashed #20262c;font-size:.8rem;}
  .item-analise:last-child{border-bottom:none;}
  .item-analise:hover{background:#1c2228;}
  .item-analise .nome{font-weight:bold;color:var(--verde-suave);font-size:.85rem;}
  .item-analise .motivo{color:#9fd9b6;font-size:.75rem;margin-top:2px;line-height:1.35;}
  .icone-alta{color:var(--verde);text-shadow:0 0 6px var(--verde);}
  .icone-baixa{color:var(--vermelho);text-shadow:0 0 6px var(--vermelho);}
  .icone-alerta{color:var(--amarelo);text-shadow:0 0 6px var(--amarelo);}
  .icone-destaque{color:var(--azul);text-shadow:0 0 6px var(--azul);}
  .badge-tend{font-size:.7rem;padding:.2rem .55rem;border-radius:4px;}
  .tend-alta{background:#004d1a;color:var(--verde);border:1px solid #006622;}
  .tend-queda{background:#3a1010;color:var(--vermelho);border:1px solid #662222;}
  .tend-estavel{background:#2a2a12;color:var(--amarelo);border:1px solid #555522;}
  .resumo{background:#12171b;border:1px solid var(--border);border-left:3px solid var(--verde);
    padding:10px 14px;border-radius:4px;font-size:.82rem;color:#c8f5d6;line-height:1.5;}
  .spinner-terminal{display:inline-block;width:14px;height:14px;border:2px solid var(--verde-dim);
    border-top-color:var(--verde);border-radius:50%;animation:girar .8s linear infinite;
    vertical-align:-2px;margin-right:6px;}
  @keyframes girar{to{transform:rotate(360deg);}}
  .base-noticias li{font-size:.72rem;color:#9fd9b6;margin-bottom:2px;}
  .meta-rodape{font-size:.7rem;color:var(--verde-dim);border-top:1px solid var(--border);
    padding-top:10px;margin-top:18px;}
  .placeholder{color:var(--verde-dim);font-size:.78rem;padding:14px;text-align:center;}
</style>
</head>
<body>
<div class="container-fluid px-3 py-3">

  <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
    <div>
      <h1>[&gt;] Painel Notícias + Previsão de Mercado</h1>
      <div class="subtitulo" id="status">Pronto.</div>
    </div>
    <div class="d-flex gap-2 flex-wrap">
      <button id="btnColetar" class="btn btn-terminal">⬇ Coletar notícias</button>
      <button id="btnPrever"  class="btn btn-terminal">▶ Prever mercado</button>
      <button id="btnForcar"  class="btn btn-terminal btn-sec" title="Ignora cache do dia">⟳ Forçar previsão</button>
    </div>
  </div>

  <div class="row g-2 mb-3" id="kpis"></div>

  <div id="boxResumo" class="mb-3" style="display:none;">
    <div class="resumo"><strong>Resumo executivo:</strong> <span id="txtResumo"></span></div>
  </div>

  <div class="row g-3 mb-3">
    <div class="col-lg-6">
      <div class="card-painel h-100">
        <div class="card-header d-flex justify-content-between align-items-center">
          <span>🇧🇷 Mercado interno</span>
          <span class="badge-tend tend-estavel" id="tendInterno">—</span>
        </div>
        <div class="card-body p-0">
          <div class="px-3 pt-2 pb-1 small text-secondary">▲ Alimentos em alta</div>
          <div id="boxIntAlta"><div class="placeholder">aguardando…</div></div>
          <div class="px-3 pt-2 pb-1 small text-secondary">▼ Alimentos em queda</div>
          <div id="boxIntQueda"><div class="placeholder">aguardando…</div></div>
        </div>
      </div>
    </div>
    <div class="col-lg-6">
      <div class="card-painel h-100">
        <div class="card-header d-flex justify-content-between align-items-center">
          <span>🌎 Mercado externo</span>
          <span class="badge-tend tend-estavel" id="tendExterno">—</span>
        </div>
        <div class="card-body p-0">
          <div class="px-3 pt-2 pb-1 small text-secondary">▲ Alimentos em alta</div>
          <div id="boxExtAlta"><div class="placeholder">aguardando…</div></div>
          <div class="px-3 pt-2 pb-1 small text-secondary">▼ Alimentos em queda</div>
          <div id="boxExtQueda"><div class="placeholder">aguardando…</div></div>
        </div>
      </div>
    </div>
  </div>

  <div class="row g-3 mb-3">
    <div class="col-lg-4">
      <div class="card-painel h-100">
        <div class="card-header">⭐ Produtos em destaque</div>
        <div class="card-body p-0" id="boxDestaque"><div class="placeholder">aguardando…</div></div>
      </div>
    </div>
    <div class="col-lg-4">
      <div class="card-painel h-100">
        <div class="card-header">🔥 Maior demanda</div>
        <div class="card-body p-0" id="boxDemanda"><div class="placeholder">aguardando…</div></div>
      </div>
    </div>
    <div class="col-lg-4">
      <div class="card-painel h-100">
        <div class="card-header">⚠ Alertas de risco</div>
        <div class="card-body p-0" id="boxRisco"><div class="placeholder">aguardando…</div></div>
      </div>
    </div>
  </div>

  <div class="card-painel mb-3">
    <div class="card-header">📰 Base de notícias usada</div>
    <div class="card-body">
      <ul class="base-noticias mb-0" id="boxBase"><li class="placeholder">aguardando…</li></ul>
    </div>
  </div>

  <div class="meta-rodape" id="metaRodape" style="display:none;"></div>
</div>

<script>
$(function(){
  function setStatus(h){ $('#status').html(h); }
  function esc(s){ return String(s??'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

  function renderLista(sel, itens, icone, classeIcone){
    const box = $(sel).empty();
    if (!itens || !itens.length){ box.html('<div class="placeholder">Nenhum item.</div>'); return; }
    itens.forEach(function(it){
      box.append('<div class="item-analise"><div class="'+classeIcone+'" style="font-size:.95rem;">'+icone+'</div><div><div class="nome">'+esc(it.produto || '—')+'</div><div class="motivo">'+esc(it.motivo || '')+'</div></div></div>');
    });
  }

  function tendClasse(t){ return t === 'alta' ? 'tend-alta' : (t === 'queda' ? 'tend-queda' : 'tend-estavel'); }
  function tendLabel(t){ return t === 'alta' ? '▲ ALTA' : (t === 'queda' ? '▼ QUEDA' : '— ESTÁVEL'); }

  function renderKPIs(meta, res){
    const r = res || {};
    const mi = r.mercado_interno || {};
    const me = r.mercado_externo || {};
    const nAltas = (mi.alimentos_alta||[]).length + (me.alimentos_alta||[]).length;
    const nQuedas = (mi.alimentos_queda||[]).length + (me.alimentos_queda||[]).length;
    const nRiscos = (r.alertas_risco||[]).length;
    $('#kpis').html(
      '<div class="col-6 col-md-3"><div class="kpi"><div class="small text-secondary">Cotações usadas</div><div class="num">'+(meta?.cotacoes ?? '—')+'</div></div></div>' +
      '<div class="col-6 col-md-3"><div class="kpi"><div class="small text-secondary">Sinais de alta</div><div class="num" style="color:var(--verde)">'+nAltas+'</div></div></div>' +
      '<div class="col-6 col-md-3"><div class="kpi"><div class="small text-secondary">Sinais de queda</div><div class="num" style="color:var(--vermelho)">'+nQuedas+'</div></div></div>' +
      '<div class="col-6 col-md-3"><div class="kpi"><div class="small text-secondary">Alertas de risco</div><div class="num" style="color:var(--amarelo)">'+nRiscos+'</div></div></div>'
    );
  }

  function renderResultado(res){
    const r = res.result || {};
    const mi = r.mercado_interno || {};
    const me = r.mercado_externo || {};
    renderKPIs(res.meta, r);
    $('#txtResumo').text(r.resumo_executivo || '—'); $('#boxResumo').show();
    $('#tendInterno').attr('class','badge-tend '+tendClasse(mi.tendencia)).text(tendLabel(mi.tendencia));
    $('#tendExterno').attr('class','badge-tend '+tendClasse(me.tendencia)).text(tendLabel(me.tendencia));
    renderLista('#boxIntAlta', mi.alimentos_alta, '▲', 'icone-alta');
    renderLista('#boxIntQueda', mi.alimentos_queda, '▼', 'icone-baixa');
    renderLista('#boxExtAlta', me.alimentos_alta, '▲', 'icone-alta');
    renderLista('#boxExtQueda', me.alimentos_queda, '▼', 'icone-baixa');
    renderLista('#boxDestaque', r.produtos_destaque, '★', 'icone-destaque');
    renderLista('#boxDemanda', r.maior_demanda, '🔥', 'icone-destaque');
    renderLista('#boxRisco', r.alertas_risco, '⚠', 'icone-alerta');
    const base = r.base_noticias || [];
    const ul = $('#boxBase').empty();
    if (!base.length){ ul.html('<li class="placeholder">Sem notícias citadas.</li>'); }
    else { base.forEach(function(t){ ul.append('<li>• '+esc(t)+'</li>'); }); }
    const meta = res.meta || {};
    if (meta.gerado_em){
      const p = [];
      if (meta.cotacoes) p.push(meta.cotacoes+' cotações');
      if (meta.comparado_com) p.push('comparado com '+meta.comparado_com);
      if (res.cached) p.push('(cache do dia)');
      p.push('gerado em '+new Date(meta.gerado_em).toLocaleString('pt-BR'));
      $('#metaRodape').text(p.join(' · ')).show();
    }
  }

  function carregarEstado(){
    setStatus('<span class="spinner-terminal"></span>Carregando estado…');
    $.getJSON('?acao=estado').done(function(res){
      if (res && res.ok && res.cache && res.cache.result){
        setStatus('✔ Cache do dia carregado.');
        renderResultado({result:res.cache.result, meta:res.cache.meta, cached:true});
      } else {
        setStatus('Pronto. Clique em <b>▶ Prever mercado</b> para gerar a análise.');
      }
    }).fail(function(xhr){ setStatus('Falha ao carregar estado: '+xhr.status); });
  }

  function coletar(){
    const $b = $('#btnColetar, #btnPrever, #btnForcar'); $b.prop('disabled', true);
    setStatus('<span class="spinner-terminal"></span>Coletando notícias…');
    $.getJSON('?acao=coletar').done(function(res){
      if (res && res.ok) setStatus('✔ Coleta concluída: '+res.total_titulos+' títulos em '+res.categorias+' categorias.');
      else setStatus('❌ Erro na coleta: '+esc(res?.erro||'?'));
    }).fail(function(xhr){
      let d = xhr.status; try { d += ' '+(xhr.responseJSON?.erro || xhr.responseText?.slice(0,150) || ''); } catch(_){}
      setStatus('❌ Falha na coleta: '+esc(d));
    }).always(function(){ $b.prop('disabled', false); });
  }

  function prever(forcar){
    const $b = $('#btnColetar, #btnPrever, #btnForcar'); $b.prop('disabled', true);
    setStatus('<span class="spinner-terminal"></span>Consultando IA…');
    const url = '?acao=prever' + (forcar ? '&force=1' : '');
    $.ajax({url:url, method:'GET', dataType:'json', timeout:180000}).done(function(res){
      if (!res || !res.ok){ setStatus('❌ Erro: '+esc(res?.erro||'desconhecido')); return; }
      setStatus((res.cached?'✔ Cache':'✔ Análise concluída')+' · '+new Date().toLocaleTimeString('pt-BR'));
      renderResultado(res);
    }).fail(function(xhr){
      let d = xhr.status; try { d += ' '+(xhr.responseJSON?.erro || xhr.responseText?.slice(0,150) || ''); } catch(_){}
      setStatus('❌ Falha na IA: '+esc(d)); console.error(xhr.status, xhr.responseText);
    }).always(function(){ $b.prop('disabled', false); });
  }

  $('#btnColetar').on('click', coletar);
  $('#btnPrever').on('click', function(){ prever(false); });
  $('#btnForcar').on('click', function(){ if (confirm('Ignorar cache e chamar a IA novamente?')) prever(true); });

  carregarEstado();
});
</script>
</body>
</html>