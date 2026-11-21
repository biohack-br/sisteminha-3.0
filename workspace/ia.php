<?php
// ============================================================
// ANÁLISE IA v3.1 — Painel Consolidado (arquivo único)
// PHP + AJAX + Bootstrap · Pollinations API
// - Chave embutida (sem .env / sem variáveis de ambiente)
// - Painel "Ameaças catalogadas (Doomsday)" OCULTO na UI
//   mas o backend continua lendo doomsday_cache.json
//   para compor o score e o prompt da IA
// - Tema chumbo + verde em todo o painel
// ============================================================
declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);
ob_start();

// ------------------------- CONFIG -------------------------
const API_KEY     = 'sk_ZU2KUB9PCvAoDtF6L4szjm8a9unkl0lE';
const API_URL     = 'https://gen.pollinations.ai/v1/chat/completions';
const API_MODEL   = 'openai';
const API_TIMEOUT = 120;

const ARQ_CONSOLIDADO = __DIR__ . '/consolidado.json';
const ARQ_HISTORICO   = __DIR__ . '/historico.json';
const ARQ_QUOTES      = __DIR__ . '/quotes_cache.json';
const ARQ_NOTICIAS    = __DIR__ . '/base_dados_deepseek.txt';
const ARQ_DOOMSDAY    = __DIR__ . '/doomsday_cache.json'; // lido no backend, não exibido na UI
const ARQ_PREVISAO    = __DIR__ . '/previsao_cache.json';
const ARQ_MEMORIA     = __DIR__ . '/chat_memory.json';
const ARQ_CACHE       = __DIR__ . '/analise_ia_cache.json';

// ------------------------- helpers -------------------------
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
function num($v): float {
    if (is_numeric($v)) return (float)$v;
    if (!is_string($v)) return 0.0;
    $v = preg_replace('/[R$\s]|USD|\$USD/i', '', $v);
    if (substr_count($v, ',') === 1 && substr_count($v, '.') === 0) $v = str_replace(',', '.', $v);
    elseif (substr_count($v, '.') > 0 && substr_count($v, ',') > 0) { $v = str_replace('.', '', $v); $v = str_replace(',', '.', $v); }
    return is_numeric($v) ? (float)$v : 0.0;
}
function clamp(float $v, float $a, float $b): float { return max($a, min($b, $v)); }

// ------------------------- leitura de dados -------------------------
function coletarCotacoes(array $consolidado): array {
    $out = [];
    foreach ($consolidado['itens'] ?? [] as $it) {
        if (($it['tipo'] ?? '') !== 'cotacao') continue;
        $nome = (string)($it['descricao'] ?? '');
        $out[] = [
            'produto'   => explode(' [', $nome)[0],
            'descricao' => $nome,
            'valor_txt' => (string)($it['valor'] ?? ''),
            'valor'     => num($it['valor'] ?? 0),
            'unidade'   => (string)($it['unidade'] ?? ''),
            'data'      => (string)($it['data'] ?? ''),
        ];
    }
    return $out;
}

function coletarIndicadores(?array $quotes): array {
    if (!$quotes) return [];
    $map = [
        'dolar'       => 'Dólar (R$)',
        'gasolina'    => 'Gasolina (R$/L)',
        'petroleo'    => 'Petróleo Brent (USD)',
        'ouro'        => 'Ouro (R$/onça)',
        'prata'       => 'Prata (R$/onça)',
        'bitcoin'     => 'Bitcoin (USD)',
        'temperatura' => 'Temperatura',
    ];
    $out = [];
    foreach ($map as $k => $label) {
        if (isset($quotes[$k]) && $quotes[$k] !== 'N/A') {
            $out[] = ['chave' => $k, 'label' => $label, 'valor' => (string)$quotes[$k]];
        }
    }
    return $out;
}

function calcularVariacoes(array $cotacoes, ?array $historico): array {
    if (!$historico || empty($historico['snapshots'])) return [];
    $snaps = $historico['snapshots'];
    usort($snaps, fn($a, $b) => strcmp($a['data'] ?? '', $b['data'] ?? ''));
    if (count($snaps) < 2) return [];
    $ant = $snaps[count($snaps) - 2];
    $mapAnt = [];
    foreach ($ant['itens'] ?? [] as $it) $mapAnt[$it['descricao'] ?? ''] = num($it['valor'] ?? 0);
    $out = [];
    foreach ($cotacoes as $c) {
        $chaveBase = explode(' (', $c['descricao'])[0];
        $vAnt = null;
        foreach ($mapAnt as $desc => $v) {
            if (str_starts_with($desc, $chaveBase)) { $vAnt = $v; break; }
        }
        if ($vAnt === null || $vAnt == 0.0) continue;
        $var = (($c['valor'] - $vAnt) / abs($vAnt)) * 100;
        $out[] = [
            'produto' => $c['produto'],
            'atual'   => $c['valor_txt'],
            'antes'   => (string)$vAnt,
            'var_pct' => round($var, 2),
        ];
    }
    usort($out, fn($a, $b) => abs($b['var_pct']) <=> abs($a['var_pct']));
    return $out;
}

function extrairNoticias(string $path, int $max = 3000): string {
    if (!is_file($path)) return '';
    $txt = (string)@file_get_contents($path);
    if (trim($txt) === '') return '';
    if (mb_strlen($txt) > $max) $txt = mb_substr($txt, 0, $max) . "\n[...truncado...]";
    return $txt;
}

function extrairAmeacasDoomsday(?array $doom): array {
    if (!$doom || empty($doom['ameacas'])) return [];
    $out = [];
    foreach ($doom['ameacas'] as $a) {
        $out[] = [
            'categoria'     => (string)($a['categoria'] ?? ''),
            'rotulo'        => (string)($a['rotulo'] ?? ''),
            'peso'          => (int)($a['peso'] ?? 0),
            'justificativa' => (string)($a['justificativa'] ?? ''),
            'alvos'         => $a['alvos'] ?? [],
        ];
    }
    usort($out, fn($x, $y) => $y['peso'] <=> $x['peso']);
    return $out;
}

function extrairMemoria(?array $mem): string {
    if (!$mem) return '';
    $nome = $mem['known']['user'] ?? null;
    $ultimas = array_slice($mem['history'] ?? [], -3);
    if (!$nome && !$ultimas) return '';
    $linhas = [];
    if ($nome) $linhas[] = "O usuário se chama {$nome}.";
    foreach ($ultimas as $h) {
        $u = trim((string)($h['user'] ?? ''));
        $b = trim((string)($h['bot'] ?? ''));
        if ($u !== '') $linhas[] = "Usuário perguntou recentemente: {$u}";
        if ($b !== '') $linhas[] = "Você respondeu: " . mb_substr($b, 0, 200);
    }
    return implode("\n", $linhas);
}

// ------------------------- cálculo de risco (determinístico) -------------------------
function calcularRisco(array $cotacoes, array $variacoes, array $ameacas, array $indicadores): array {
    $score = 0.0;
    $fatores = [];

    // 1) Volatilidade
    $vol = 0.0;
    foreach ($variacoes as $v) if (abs($v['var_pct']) >= 2.0) $vol += abs($v['var_pct']);
    if ($vol > 0) {
        $contrib = clamp($vol * 1.2, 0, 25);
        $score += $contrib;
        $fatores[] = "Volatilidade de cotações: +" . round($contrib, 1) . " (soma |Δ|≥2%: " . round($vol, 1) . "%)";
    }

    // 2) Ameaças Doomsday (peso médio das top-5)
    $top = array_slice($ameacas, 0, 5);
    if ($top) {
        $pesoMedio = array_sum(array_column($top, 'peso')) / count($top);
        $contrib = clamp($pesoMedio * 3.0, 0, 30);
        $score += $contrib;
        $fatores[] = "Ameaças top-5 (peso médio " . round($pesoMedio, 1) . "): +" . round($contrib, 1);
    }

    // 3) Macro
    foreach ($indicadores as $ind) {
        $v = num($ind['valor']);
        if ($ind['chave'] === 'petroleo' && $v > 100) { $score += 5; $fatores[] = "Petróleo acima de USD 100: +5"; }
        if ($ind['chave'] === 'dolar'    && $v > 5.0) { $score += 5; $fatores[] = "Dólar acima de R$ 5,00: +5"; }
        if ($ind['chave'] === 'gasolina' && $v > 6.0) { $score += 5; $fatores[] = "Gasolina acima de R$ 6,00: +5"; }
    }

    // 4) Quedas fortes
    $quedas = array_filter($variacoes, fn($v) => $v['var_pct'] <= -3.0);
    if ($quedas) {
        $contrib = clamp(count($quedas) * 3.0, 0, 15);
        $score += $contrib;
        $fatores[] = "Quedas ≥3% em " . count($quedas) . " produto(s): +" . round($contrib, 1);
    }

    $score = clamp($score, 0, 100);
    $nivel = match (true) {
        $score >= 75 => 'CRÍTICO',
        $score >= 55 => 'ALTO',
        $score >= 35 => 'MODERADO',
        default       => 'BAIXO',
    };
    return ['score' => round($score, 1), 'nivel' => $nivel, 'fatores' => $fatores];
}

// ============================================================
// ENDPOINT AJAX
// ============================================================
$acao = $_GET['acao'] ?? '';
if ($acao === 'analisar') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');

    try {
        $force = ($_GET['force'] ?? '') === '1';

        if (!$force && is_file(ARQ_CACHE)) {
            $cache = lerJson(ARQ_CACHE);
            if ($cache && ($cache['data'] ?? '') === date('Y-m-d')) {
                echo json_encode(['ok' => true, 'cached' => true] + $cache, JSON_UNESCAPED_UNICODE);
                exit;
            }
        }

        // ---------- carrega dados ----------
        $consolidado = lerJson(ARQ_CONSOLIDADO);
        if (!$consolidado || !isset($consolidado['itens'])) {
            throw new RuntimeException('consolidado.json inválido ou ausente.');
        }

        $cotacoes    = coletarCotacoes($consolidado);
        $historico   = lerJson(ARQ_HISTORICO);
        $quotes      = lerJson(ARQ_QUOTES);
        $doom        = lerJson(ARQ_DOOMSDAY);     // <-- continua lendo
        $previsao    = lerJson(ARQ_PREVISAO);
        $memoria     = lerJson(ARQ_MEMORIA);

        $variacoes   = calcularVariacoes($cotacoes, $historico);
        $indicadores = coletarIndicadores($quotes);
        $ameacas     = extrairAmeacasDoomsday($doom);   // <-- continua extraindo
        $noticias    = extrairNoticias(ARQ_NOTICIAS, 3500);
        $memoriaTxt  = extrairMemoria($memoria);
        $risco       = calcularRisco($cotacoes, $variacoes, $ameacas, $indicadores); // <-- score usa ameaças

        // ---------- monta contexto para a IA ----------
        $linhasCot = [];
        foreach ($cotacoes as $c) {
            $linhasCot[] = "- {$c['produto']}: {$c['valor_txt']} {$c['unidade']} ({$c['data']})";
        }
        $linhasVar = [];
        foreach ($variacoes as $v) {
            $sinal = $v['var_pct'] >= 0 ? '+' : '';
            $linhasVar[] = "- {$v['produto']}: {$sinal}{$v['var_pct']}% (antes {$v['antes']})";
        }
        $linhasInd = [];
        foreach ($indicadores as $i) {
            $linhasInd[] = "- {$i['label']}: {$i['valor']}";
        }
        $linhasAme = [];
        foreach (array_slice($ameacas, 0, 8) as $a) {
            $alvos = implode(', ', array_slice($a['alvos'], 0, 5));
            $linhasAme[] = "- [{$a['categoria']}·peso {$a['peso']}] {$a['rotulo']} — alvos: {$alvos}";
        }
        $prevTxt = '';
        if ($previsao && isset($previsao['result'])) {
            $r = $previsao['result'];
            $prevTxt = "RESUMO PRÉVIO (cache):\n" . ($r['resumo_executivo'] ?? '') . "\n";
        }

        $dadosParaIA =
            "=== COTAÇÕES ATUAIS (" . count($cotacoes) . ") ===\n" . implode("\n", $linhasCot) . "\n\n" .
            "=== VARIAÇÕES VS. SNAPSHOT ANTERIOR ===\n" . ($linhasVar ? implode("\n", $linhasVar) : '(sem histórico)') . "\n\n" .
            "=== INDICADORES MACRO ===\n" . ($linhasInd ? implode("\n", $linhasInd) : '(sem dados)') . "\n\n" .
            "=== AMEAÇAS DOOMSDAY (top 8) ===\n" . ($linhasAme ? implode("\n", $linhasAme) : '(sem dados)') . "\n\n" .
            $prevTxt .
            "=== SCORE DE RISCO CALCULADO (PHP, não recalcule) ===\n" .
            "Score: {$risco['score']} / 100 — Nível: {$risco['nivel']}\n" .
            "Fatores: " . implode('; ', $risco['fatores']) . "\n\n" .
            "=== NOTÍCIAS RECENTES ===\n" . ($noticias ?: '(sem notícias)') . "\n\n" .
            ($memoriaTxt ? "=== MEMÓRIA DO USUÁRIO ===\n{$memoriaTxt}\n\n" : '');

        // ---------- prompt ----------
        $systemPrompt = <<<PROMPT
Você é um analista sênior de risco, commodities agropecuárias e geopolítica no Brasil.
Receberá: cotações atuais, variações, indicadores macro, ameaças catalogadas, score de risco JÁ CALCULADO e notícias.

Sua tarefa: produzir uma análise interpretativa e acionável, em JSON EXATO (sem markdown, sem texto fora do JSON):

{
  "comprar": [
    { "produto": "Nome curto", "motivo": "1-2 frases com base nos dados." }
  ],
  "alerta": [
    { "produto": "Nome curto", "motivo": "1-2 frases." }
  ],
  "leitura_risco": "2-3 frases interpretando o score fornecido. NÃO recalcule o score.",
  "contexto_noticias": "2-3 frases ligando notícias a cotações, se houver dados.",
  "observacao": "Frase opcional sobre limitações."
}

REGRAS:
- "comprar" e "alerta": no máximo 4 itens cada; ordene por convicção.
- Use APENAS números fornecidos. Não invente preços, datas ou fontes.
- Leve em conta: variações recentes, indicadores (dólar, petróleo, gasolina), ameaças (peso/categoria) e notícias.
- Se a memória do usuário indicar foco (sobrevivência, negócio, geopolítica), ajuste o tom e a priorização.
- Se faltar dado, diga isso em "observacao" e ainda assim entregue o que é possível.
PROMPT;

        $payload = [
            'model'       => API_MODEL,
            'messages'    => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user',   'content' => $dadosParaIA],
            ],
            'temperature' => 0.35,
            'max_tokens'  => 1400,
        ];

        if (trim(API_KEY) === '' || strpos(API_KEY, 'sk_') !== 0) {
            throw new RuntimeException('API_KEY inválida no topo do arquivo. Coloque sua chave sk_...');
        }

        $ch = curl_init(API_URL);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Accept: application/json',
                'Authorization: Bearer ' . trim(API_KEY),
            ],
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => API_TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($response === false) throw new RuntimeException("cURL: {$curlErr}");

        $jsonResp = json_decode($response, true);
        if ($httpCode !== 200 || !isset($jsonResp['choices'][0]['message']['content'])) {
            $msg = $jsonResp['error']['message'] ?? substr($response, 0, 250);
            throw new RuntimeException("API HTTP {$httpCode}: {$msg}");
        }

        $conteudo = $jsonResp['choices'][0]['message']['content'];
        $conteudo = preg_replace('/^```(?:json)?\s*|\s*```$/m', '', trim($conteudo));
        $analise  = json_decode($conteudo, true);

        if (!is_array($analise)) {
            $analise = [
                'comprar'           => [],
                'alerta'            => [],
                'leitura_risco'     => $risco['nivel'] . ' — ' . implode('; ', $risco['fatores']),
                'contexto_noticias' => '',
                'observacao'        => 'A IA não retornou JSON válido. Conteúdo bruto: ' . mb_substr($conteudo, 0, 300),
            ];
        }

        $meta = [
            'total_cotacoes' => count($cotacoes),
            'comparado_com'  => $variacoes ? ($historico['snapshots'][count($historico['snapshots']) - 2]['data'] ?? null) : null,
            'gerado_em'      => date('c'),
            'risco'          => $risco,
            'variacoes'      => $variacoes,
            'indicadores'    => $indicadores,
            'ameacas'        => array_slice($ameacas, 0, 8), // mantido para o cache, não exibido na UI
            'top_noticias'   => array_slice(array_filter(array_map('trim', explode("\n", $noticias))), 0, 8),
        ];

        $pacote = ['data' => date('Y-m-d'), 'analise' => $analise, 'meta' => $meta];
        gravarJson(ARQ_CACHE, $pacote);

        echo json_encode(['ok' => true, 'cached' => false] + $pacote, JSON_UNESCAPED_UNICODE);

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
<title>Análise IA v3.1 — Painel Consolidado</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<style>
  :root{
    --bg:#0f1316; --card:#181d22; --card-head:#12171b; --border:#2e3840;
    --verde:#00ff66; --verde-suave:#66ff99; --verde-dim:#44aa66;
    --vermelho:#ff6666; --amarelo:#ffcc44; --azul:#66ccff;
  }

  html, body{
    background:var(--bg) !important;
    color:var(--verde);
    font-family:'Consolas','Courier New',monospace;
    min-height:100vh;
  }
  body{ padding-bottom:40px; }

  h1{
    font-size:1.15rem; text-transform:uppercase; letter-spacing:2px;
    border-bottom:1px solid var(--verde); padding-bottom:8px; margin-bottom:6px;
    text-shadow:0 0 8px rgba(0,255,102,.35); color:var(--verde);
  }
  .subtitulo{ font-size:.72rem; color:var(--verde-dim); margin-bottom:18px; }

  /* ---------- Cards ---------- */
  .card-painel{
    background:var(--card) !important;
    border:1px solid var(--border);
    border-radius:8px; overflow:hidden; margin-bottom:16px;
  }
  .card-painel .card-header{
    background:var(--card-head) !important;
    border-bottom:1px solid var(--border);
    color:var(--verde-suave) !important;
    font-weight:bold; font-size:.8rem;
    letter-spacing:1px; text-transform:uppercase;
  }
  .card-painel .card-body{
    background:var(--card) !important;
    color:var(--verde-suave) !important;
  }

  /* ---------- Tabelas (radar + indicadores) ---------- */
  .card-painel .table,
  .card-painel table.table,
  .tabela-mini{
    --bs-table-bg: transparent;
    --bs-table-color: var(--verde-suave);
    --bs-table-border-color: var(--border);
    --bs-table-striped-bg: rgba(0,255,102,.03);
    --bs-table-striped-color: var(--verde-suave);
    --bs-table-hover-bg: rgba(0,255,102,.06);
    --bs-table-hover-color: var(--verde);

    background:transparent !important;
    color:var(--verde-suave) !important;
    margin-bottom:0;
    font-size:.74rem;
    border-color:var(--border) !important;
  }
  .card-painel .table > :not(caption) > * > *{
    background:transparent !important;
    color:var(--verde-suave) !important;
    border-color:var(--border) !important;
    box-shadow:none !important;
  }
  .card-painel .table thead th,
  .tabela-mini th{
    background:var(--card-head) !important;
    color:var(--verde-dim) !important;
    border-bottom:1px solid var(--border) !important;
    font-weight:normal; font-size:.68rem;
    letter-spacing:1px; text-transform:uppercase;
    padding:.4rem .6rem;
  }
  .card-painel .table tbody tr{
    background:transparent !important;
    border-bottom:1px dashed #20262c;
  }
  .card-painel .table tbody tr:last-child{ border-bottom:none; }
  .card-painel .table tbody tr:hover{
    background:rgba(0,255,102,.04) !important;
  }
  .card-painel .table tbody td{
    color:var(--verde-suave) !important;
    padding:.35rem .6rem;
    vertical-align:middle;
    border-color:var(--border) !important;
  }
  .card-painel .table tbody td:first-child{ color:#9fd9b6 !important; }
  .card-painel .table tbody td.status,
  .card-painel .table tbody td[colspan]{
    color:var(--verde-dim) !important;
    text-align:center;
  }
  .var-pos{ color:var(--verde) !important; font-weight:bold; }
  .var-neg{ color:var(--vermelho) !important; font-weight:bold; }

  /* ---------- Botões ---------- */
  .btn-terminal{
    background:#181d22 !important; color:var(--verde) !important;
    border:1px solid var(--verde) !important;
    font-size:.75rem; letter-spacing:1px;
  }
  .btn-terminal:hover{
    background:var(--verde) !important; color:var(--bg) !important;
  }
  .btn-terminal:disabled{ opacity:.5; }

  /* ---------- Itens / leitura / observação ---------- */
  .status{ font-size:.78rem; color:var(--verde-dim); }
  .item-analise{
    display:flex; gap:12px; align-items:flex-start;
    padding:9px 12px; border-bottom:1px dashed #20262c;
    background:transparent; color:var(--verde-suave);
  }
  .item-analise:last-child{ border-bottom:none; }
  .item-analise .icone{ font-size:1rem; flex-shrink:0; width:22px; text-align:center; }
  .item-analise .nome{ font-weight:bold; color:var(--verde-suave); font-size:.88rem; }
  .item-analise .motivo{ font-size:.76rem; color:#9fd9b6; margin-top:2px; line-height:1.35; }
  .compra .icone{ color:var(--verde); text-shadow:0 0 6px var(--verde); }
  .alerta .icone{ color:var(--amarelo); text-shadow:0 0 6px var(--amarelo); }

  .observacao{
    background:#1a1d12 !important; border:1px solid #3a3f22;
    border-left:3px solid var(--amarelo);
    color:#d6e6a8 !important; font-size:.76rem;
    padding:8px 12px; border-radius:4px;
  }
  .leitura{
    background:#0f1a12 !important; border:1px solid #20412a;
    border-left:3px solid var(--verde);
    color:#c8f5d6 !important; font-size:.78rem;
    padding:8px 12px; border-radius:4px;
  }

  /* ---------- Spinner / meta / badge ---------- */
  .spinner-terminal{
    display:inline-block; width:14px; height:14px;
    border:2px solid var(--verde-dim); border-top-color:var(--verde);
    border-radius:50%; animation:girar .8s linear infinite;
    vertical-align:-2px; margin-right:6px;
  }
  @keyframes girar{ to{ transform:rotate(360deg); } }
  .meta-rodape{
    font-size:.7rem; color:var(--verde-dim);
    border-top:1px solid var(--border);
    padding-top:10px; margin-top:14px;
  }
  .badge-count{
    background:#004d1a !important; color:var(--verde) !important;
    border:1px solid #006622; font-weight:normal;
    font-size:.7rem; padding:.15rem .5rem;
  }

  /* ---------- Placar de risco ---------- */
  .risco-box{
    display:flex; align-items:center; gap:18px;
    padding:14px 18px;
    background:var(--card) !important;
    border:1px solid var(--border);
    border-radius:8px; margin-bottom:16px; flex-wrap:wrap;
  }
  .risco-score{ font-size:2rem; font-weight:bold; letter-spacing:2px; }
  .risco-nivel{ font-size:.85rem; letter-spacing:3px; }
  .risco-barra{
    flex:1; min-width:200px; height:18px;
    background:#0f1316 !important;
    border-radius:4px; overflow:hidden;
    border:1px solid var(--border);
  }
  .risco-barra .fill{
    height:100%; transition:width .4s;
    background:linear-gradient(90deg,#44aa66,#00ff66);
  }
  .risco-fatores{ font-size:.7rem; color:var(--verde-dim); margin-top:4px; }

  /* ---------- Notícias ---------- */
  .noticia-item{
    font-size:.74rem; color:#9fd9b6;
    padding:4px 0; border-bottom:1px dashed #20262c;
  }
  .noticia-item:last-child{ border-bottom:none; }
  .noticia-item::before{ content:'> '; color:var(--verde-dim); }

  /* ---------- Sanidade anti-branco ---------- */
  .container, .row, .col-12, .col-lg-5, .col-lg-6, .col-lg-7,
  .card, .card-body, .table-responsive{
    background:transparent !important;
    color:var(--verde-suave);
  }
</style>
</head>
<body>
<div class="container py-4">

  <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div>
      <h1>[&gt;] Análise IA v3.1 — Painel Consolidado</h1>
      <div class="subtitulo" id="status">Pronto. Clique em "Analisar agora" ou "Forçar" para consultar.</div>
    </div>
    <div class="d-flex gap-2">
      <button id="btnAnalisar" class="btn btn-terminal">▶ Analisar agora</button>
      <button id="btnForcar" class="btn btn-terminal" title="Ignora o cache do dia">⟳ Forçar atualização</button>
    </div>
  </div>

  <div class="risco-box" id="riscoBox" style="display:none;">
    <div>
      <div class="risco-score" id="riscoScore">0</div>
      <div class="risco-nivel" id="riscoNivel">—</div>
    </div>
    <div style="flex:1;min-width:200px;">
      <div class="risco-barra"><div class="fill" id="riscoBarra" style="width:0%;"></div></div>
      <div class="risco-fatores" id="riscoFatores"></div>
    </div>
  </div>

  <div id="boxLeitura" style="display:none;" class="mb-3">
    <div class="leitura"><strong>Leitura de risco:</strong> <span id="txtLeitura"></span></div>
  </div>
  <div id="boxContexto" style="display:none;" class="mb-3">
    <div class="leitura"><strong>Contexto de notícias:</strong> <span id="txtContexto"></span></div>
  </div>

  <div class="row g-3">
    <div class="col-lg-6">
      <div class="card-painel h-100">
        <div class="card-header d-flex justify-content-between align-items-center">
          <span>▲ Melhores para comprar</span>
          <span class="badge badge-count" id="countComprar">0</span>
        </div>
        <div class="card-body p-0" id="boxComprar">
          <div class="p-4 text-center status">Aguardando análise…</div>
        </div>
      </div>
    </div>
    <div class="col-lg-6">
      <div class="card-painel h-100">
        <div class="card-header d-flex justify-content-between align-items-center">
          <span style="color:var(--amarelo)">⚠ Produtos em alerta</span>
          <span class="badge badge-count" id="countAlerta">0</span>
        </div>
        <div class="card-body p-0" id="boxAlerta">
          <div class="p-4 text-center status">Aguardando análise…</div>
        </div>
      </div>
    </div>
  </div>

  <div class="row g-3 mt-1">
    <div class="col-lg-7">
      <div class="card-painel h-100">
        <div class="card-header">↕ Radar de variações (vs. snapshot anterior)</div>
        <div class="card-body p-0">
          <table class="table table-sm tabela-mini mb-0">
            <thead><tr><th>Produto</th><th class="text-end">Atual</th><th class="text-end">Antes</th><th class="text-end">Δ</th></tr></thead>
            <tbody id="tbodyVariacoes"><tr><td colspan="4" class="text-center status p-3">sem dados</td></tr></tbody>
          </table>
        </div>
      </div>
    </div>
    <div class="col-lg-5">
      <div class="card-painel h-100">
        <div class="card-header">＄ Indicadores macro</div>
        <div class="card-body p-0">
          <table class="table table-sm tabela-mini mb-0">
            <thead><tr><th>Indicador</th><th class="text-end">Valor</th></tr></thead>
            <tbody id="tbodyIndicadores"><tr><td colspan="2" class="text-center status p-3">sem dados</td></tr></tbody>
          </table>
        </div>
      </div>
    </div>
  </div>

  <div class="row g-3 mt-1">
    <div class="col-12">
      <div class="card-painel h-100">
        <div class="card-header">📰 Notícias recentes (fonte local)</div>
        <div class="card-body p-3" id="boxNoticias">
          <div class="status">sem dados</div>
        </div>
      </div>
    </div>
  </div>

  <div class="mt-3" id="boxObservacao" style="display:none;">
    <div class="observacao"><strong>Observação:</strong> <span id="txtObservacao"></span></div>
  </div>

  <div class="meta-rodape" id="metaRodape" style="display:none;"></div>
</div>

<script>
$(function(){
  function setStatus(html){ $('#status').html(html); }
  function esc(s){ return String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

  function renderLista(sel, itens, classe, icone){
    const box = $(sel).empty();
    if (!itens || !itens.length){ box.html('<div class="p-4 text-center status">Nenhum item.</div>'); return; }
    itens.forEach(it => {
      box.append(`
        <div class="item-analise ${classe}">
          <div class="icone">${icone}</div>
          <div>
            <div class="nome">${esc(it.produto || '—')}</div>
            <div class="motivo">${esc(it.motivo || '')}</div>
          </div>
        </div>`);
    });
  }

  function renderRisco(r){
    if (!r) return;
    $('#riscoBox').show();
    $('#riscoScore').text(r.score);
    $('#riscoNivel').text(r.nivel);
    const cor = { 'BAIXO':'#66ff99','MODERADO':'#66ccff','ALTO':'#ffcc44','CRÍTICO':'#ff6666' }[r.nivel] || '#66ff99';
    $('#riscoScore, #riscoNivel').css('color', cor).css('text-shadow', '0 0 10px ' + cor);
    $('#riscoBarra').css('width', (r.score||0) + '%').css('background', `linear-gradient(90deg,#44aa66,${cor})`);
    $('#riscoFatores').html((r.fatores||[]).map(f => '· ' + esc(f)).join('<br>'));
  }

  function renderVariacoes(vars){
    const tb = $('#tbodyVariacoes').empty();
    if (!vars || !vars.length){ tb.html('<tr><td colspan="4" class="text-center status p-3">sem histórico</td></tr>'); return; }
    vars.slice(0, 12).forEach(v => {
      const cls = v.var_pct >= 0 ? 'var-pos' : 'var-neg';
      const sinal = v.var_pct >= 0 ? '+' : '';
      tb.append(`<tr><td>${esc(v.produto)}</td><td class="text-end">${esc(v.atual)}</td><td class="text-end">${esc(v.antes)}</td><td class="text-end ${cls}">${sinal}${v.var_pct}%</td></tr>`);
    });
  }

  function renderIndicadores(ind){
    const tb = $('#tbodyIndicadores').empty();
    if (!ind || !ind.length){ tb.html('<tr><td colspan="2" class="text-center status p-3">sem dados</td></tr>'); return; }
    ind.forEach(i => tb.append(`<tr><td>${esc(i.label)}</td><td class="text-end">${esc(i.valor)}</td></tr>`));
  }

  function renderNoticias(n){
    const box = $('#boxNoticias').empty();
    if (!n || !n.length){ box.html('<div class="status">sem notícias</div>'); return; }
    n.forEach(l => box.append(`<div class="noticia-item">${esc(l)}</div>`));
  }

  function renderResultado(res){
    const a = res.analise || {};
    const meta = res.meta || {};

    renderLista('#boxComprar', a.comprar, 'compra', '▲');
    renderLista('#boxAlerta',  a.alerta,  'alerta', '⚠');
    $('#countComprar').text((a.comprar||[]).length);
    $('#countAlerta').text((a.alerta||[]).length);

    if (a.leitura_risco){ $('#txtLeitura').text(a.leitura_risco); $('#boxLeitura').show(); } else $('#boxLeitura').hide();
    if (a.contexto_noticias){ $('#txtContexto').text(a.contexto_noticias); $('#boxContexto').show(); } else $('#boxContexto').hide();
    if (a.observacao){ $('#txtObservacao').text(a.observacao); $('#boxObservacao').show(); } else $('#boxObservacao').hide();

    renderRisco(meta.risco);
    renderVariacoes(meta.variacoes);
    renderIndicadores(meta.indicadores);
    renderNoticias(meta.top_noticias);
    // Painel Doomsday: oculto por decisão de UI (os dados continuam no meta.ameacas)

    if (meta.gerado_em){
      const partes = [];
      if (meta.total_cotacoes) partes.push(meta.total_cotacoes + ' cotações');
      if (meta.comparado_com) partes.push('comparado com ' + meta.comparado_com);
      if (res.cached) partes.push('(cache de hoje)');
      partes.push('gerado em ' + new Date(meta.gerado_em).toLocaleString('pt-BR'));
      $('#metaRodape').text(partes.join(' · ')).show();
    }
  }

  function chamarIA(forcar){
    const $a = $('#btnAnalisar'), $f = $('#btnForcar');
    $a.prop('disabled', true); $f.prop('disabled', true);
    setStatus('<span class="spinner-terminal"></span>Consultando IA… pode levar até 90s.');
    $.ajax({ url: '?acao=analisar' + (forcar?'&force=1':''), method:'GET', dataType:'json', timeout:150000 })
      .done(res => {
        if (!res || !res.ok){ setStatus('❌ Erro: ' + esc(res?.erro || 'desconhecido')); return; }
        setStatus((res.cached ? '✔ Carregado do cache' : '✔ Análise concluída') + ' · ' + new Date().toLocaleTimeString('pt-BR'));
        renderResultado(res);
      })
      .fail(xhr => {
        let d = xhr.status; try { d += ' ' + (xhr.responseJSON?.erro || xhr.responseText?.slice(0,150) || ''); } catch(_){}
        setStatus('❌ Falha: ' + esc(d));
      })
      .always(() => { $a.prop('disabled', false); $f.prop('disabled', false); });
  }

  $('#btnAnalisar').on('click', () => chamarIA(false));
  $('#btnForcar').on('click', () => { if (confirm('Ignorar o cache do dia e chamar a IA novamente?')) chamarIA(true); });

  chamarIA(false);
});
</script>
</body>
</html>