<?php
// ============================================================
// Painel Consolidado — arquivo único (PHP + AJAX + Bootstrap)
// ============================================================
declare(strict_types=1);

// ---------- Configuração ----------
const ARQ_CONSOLIDADO = 'consolidado.json';
const ARQ_HISTORICO   = 'historico.json';
const ARQ_QUOTES      = 'quotes_cache.json';

// ---------- Funções utilitárias ----------
function ler_json(string $arquivo, $default = null) {
    $c = __DIR__ . '/' . $arquivo;
    if (!is_file($c)) return $default;
    $raw = @file_get_contents($c);
    if ($raw === false || trim($raw) === '') return $default;
    $j = json_decode($raw, true);
    return $j === null ? $default : $j;
}

function gravar_json(string $arquivo, $dados): bool {
    $c   = __DIR__ . '/' . $arquivo;
    $tmp = $c . '.tmp';
    $ok  = @file_put_contents($tmp, json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) !== false;
    if ($ok) @rename($tmp, $c);
    return $ok;
}

function parseFloatBR($v): float {
    if (is_numeric($v)) return (float)$v;
    if (!is_string($v)) return 0.0;
    $v = str_replace(['R$', ' ', '%'], '', $v);
    $v = str_replace('.', '', $v);
    $v = str_replace(',', '.', $v);
    return is_numeric($v) ? (float)$v : 0.0;
}

function normalizarConsolidado(array $json): array {
    $itens = [];
    foreach (($json['itens'] ?? []) as $it) {
        $itens[] = [
            'descricao' => $it['descricao'] ?? ($it['produto'] ?? ''),
            'valor'     => $it['valor'] ?? '',
            'unidade'   => $it['unidade'] ?? '',
            'tipo'      => $it['tipo'] ?? '',
            'fonte'     => $it['fonte'] ?? '',
        ];
    }
    $data = '';
    foreach (($json['itens'] ?? []) as $it) {
        if (!empty($it['data'])) $data = $it['data'];
    }
    if ($data === '') {
        $data = !empty($json['gerado_em'])
            ? substr((string)$json['gerado_em'], 0, 10)
            : date('Y-m-d');
    }
    return ['data' => $data, 'itens' => $itens];
}

function mesclarSnapshot(array $hist, array $snap): array {
    $snaps = $hist['snapshots'] ?? [];
    $snaps = array_values(array_filter($snaps, fn($s) => ($s['data'] ?? '') !== $snap['data']));
    $snaps[] = $snap;
    usort($snaps, fn($a, $b) => strcmp($a['data'], $b['data']));
    return ['snapshots' => $snaps];
}

function calcularJanela(array $hist, int $janelaDias = 7): array {
    $snaps = $hist['snapshots'] ?? [];
    if (count($snaps) < 2) {
        return ['ok' => false, 'motivo' => 'Histórico insuficiente (mínimo 2 dias).'];
    }
    usort($snaps, fn($a, $b) => strcmp($a['data'], $b['data']));
    $ultimo = end($snaps);

    $limite = (new DateTime($ultimo['data']))->modify("-{$janelaDias} days")->format('Y-m-d');
    $ref = $snaps[0];
    foreach ($snaps as $s) {
        if ($s['data'] >= $limite) { $ref = $s; break; }
    }
    if ($ref['data'] === $ultimo['data'] && count($snaps) >= 2) {
        $ref = $snaps[count($snaps) - 2];
    }

    $mapRef = [];
    foreach ($ref['itens'] as $it) {
        $mapRef[$it['descricao']] = parseFloatBR($it['valor']);
    }

    $linhas = [];
    foreach ($ultimo['itens'] as $it) {
        $desc = $it['descricao'];
        if (!array_key_exists($desc, $mapRef)) continue;
        $refV  = $mapRef[$desc];
        $atual = parseFloatBR($it['valor']);
        if ($refV == 0.0) continue;
        $var = (($atual - $refV) / abs($refV)) * 100.0;
        $linhas[] = [
            'descricao' => $desc,
            'unidade'   => $it['unidade'],
            'atual'     => $atual,
            'ref'       => $refV,
            'varPct'    => $var,
        ];
    }
    usort($linhas, fn($a, $b) => $b['varPct'] <=> $a['varPct']);
    $dias = (new DateTime($ultimo['data']))->diff(new DateTime($ref['data']))->days;

    return [
        'ok' => true,
        'periodo' => ['de' => $ref['data'], 'ate' => $ultimo['data'], 'dias' => $dias],
        'altas'  => array_values(array_filter($linhas, fn($l) => $l['varPct'] > 0.001)),
        'baixas' => array_values(array_filter($linhas, fn($l) => $l['varPct'] < -0.001)),
        'neutros'=> array_values(array_filter($linhas, fn($l) => abs($l['varPct']) <= 0.001)),
        'todas'  => $linhas,
    ];
}

// ---------- API interna (AJAX) ----------
$acao = $_GET['acao'] ?? $_POST['acao'] ?? '';

if ($acao !== '') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    try {
        switch ($acao) {
            case 'sync':
                $cons = ler_json(ARQ_CONSOLIDADO);
                if (!$cons) throw new RuntimeException('consolidado.json não encontrado ou inválido.');
                $snap = normalizarConsolidado($cons);
                $hist = ler_json(ARQ_HISTORICO, ['snapshots' => []]);
                $hist = mesclarSnapshot($hist, $snap);
                if (!gravar_json(ARQ_HISTORICO, $hist)) {
                    throw new RuntimeException('Falha ao gravar historico.json (permissões?).');
                }
                // cai no retorno de 'estado'
            case 'estado':
                $hist   = ler_json(ARQ_HISTORICO, ['snapshots' => []]);
                $janela = max(1, (int)($_GET['janela'] ?? 7));
                $semana = calcularJanela($hist, $janela);
                $snaps  = $hist['snapshots'] ?? [];
                usort($snaps, fn($a, $b) => strcmp($a['data'], $b['data']));
                $ultimo = $snaps ? end($snaps) : null;
                $listaSnaps = array_map(fn($s) => [
                    'data'  => $s['data'],
                    'itens' => count($s['itens'] ?? []),
                ], $snaps);
                echo json_encode([
                    'ok' => true,
                    'total_snapshots' => count($snaps),
                    'ultimo' => $ultimo,
                    'snapshots' => $listaSnaps,
                    'semana' => $semana,
                    'quotes' => ler_json(ARQ_QUOTES, []),
                ], JSON_UNESCAPED_UNICODE);
                break;

            case 'remover_dia':
                $dia = $_POST['dia'] ?? '';
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dia)) {
                    throw new RuntimeException('Data inválida.');
                }
                $hist = ler_json(ARQ_HISTORICO, ['snapshots' => []]);
                $hist['snapshots'] = array_values(array_filter(
                    $hist['snapshots'] ?? [],
                    fn($s) => ($s['data'] ?? '') !== $dia
                ));
                gravar_json(ARQ_HISTORICO, $hist);
                echo json_encode(['ok' => true]);
                break;

            case 'limpar':
                gravar_json(ARQ_HISTORICO, ['snapshots' => []]);
                echo json_encode(['ok' => true]);
                break;

            case 'exportar':
                $hist = ler_json(ARQ_HISTORICO, ['snapshots' => []]);
                header('Content-Disposition: attachment; filename="historico-' . date('Ymd') . '.json"');
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode($hist, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
                exit;

            case 'importar':
                $body = file_get_contents('php://input');
                $json = json_decode($body, true);
                if (!is_array($json) || !isset($json['snapshots']) || !is_array($json['snapshots'])) {
                    throw new RuntimeException('Formato inválido.');
                }
                $hist = ler_json(ARQ_HISTORICO, ['snapshots' => []]);
                foreach ($json['snapshots'] as $s) {
                    if (isset($s['data'], $s['itens'])) {
                        $hist = mesclarSnapshot($hist, $s);
                    }
                }
                gravar_json(ARQ_HISTORICO, $hist);
                echo json_encode(['ok' => true, 'total' => count($hist['snapshots'])]);
                break;

            default:
                http_response_code(400);
                echo json_encode(['ok' => false, 'erro' => 'Ação desconhecida.']);
        }
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'erro' => $e->getMessage()]);
    }
    exit;
}
?><!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Painel Consolidado — Avançado</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<style>
  :root{
    --bg:#0f1316;--card:#181d22;--border:#2e3840;
    --verde:#00ff66;--verde-suave:#66ff99;--verde-dim:#44aa66;--vermelho:#ff6666;
  }
  body{background:var(--bg);color:var(--verde);font-family:'Consolas','Courier New',monospace;padding-bottom:40px;}
  h1{font-size:1.1rem;text-transform:uppercase;letter-spacing:2px;
     border-bottom:1px solid var(--verde);padding-bottom:8px;margin-bottom:6px;
     text-shadow:0 0 8px rgba(0,255,102,.3);}
  .card-painel{background:var(--card);border:1px solid var(--border);border-radius:8px;}
  .card-painel .card-header{background:#12171b;border-bottom:1px solid var(--border);
                            color:var(--verde-suave);font-weight:bold;font-size:.85rem;letter-spacing:1px;}
  .status{font-size:.75rem;color:var(--verde-dim);margin-bottom:14px;}
  .alta{color:var(--verde);font-weight:bold;}
  .baixa{color:var(--vermelho);font-weight:bold;}
  .neutro{color:var(--verde-dim);}
  .tab{width:100%;border-collapse:collapse;font-size:.8rem;}
  .tab th,.tab td{text-align:left;padding:4px 6px;border-bottom:1px solid var(--border);}
  .tab th{color:var(--verde);font-weight:bold;position:sticky;top:0;background:#12171b;z-index:1;}
  .tab td.num{text-align:right;font-variant-numeric:tabular-nums;}
  .chart-wrap{position:relative;height:380px;}
  .linha-var{display:flex;justify-content:space-between;padding:3px 0;border-bottom:1px dashed #20262c;font-size:.8rem;}
  .linha-var:last-child{border-bottom:none;}
  .badge-verde{background:#004d1a;color:var(--verde);border:1px solid #006622;}
  .badge-vermelha{background:#3a1010;color:var(--vermelho);border:1px solid #662222;}
  .btn-terminal{background:#181d22;color:var(--verde);border:1px solid var(--verde);font-size:.75rem;}
  .btn-terminal:hover{background:var(--verde);color:var(--bg);}
  .form-select-sm,.form-control-sm{background:#0f1316;color:var(--verde);border:1px solid var(--border);font-size:.75rem;}
  .form-select-sm:focus,.form-control-sm:focus{background:#0f1316;color:var(--verde);border-color:var(--verde);box-shadow:0 0 0 .15rem rgba(0,255,102,.25);}
  .kpi{background:#12171b;border:1px solid var(--border);border-radius:6px;padding:8px 10px;font-size:.8rem;}
  .kpi .num{font-size:1.1rem;font-weight:bold;color:var(--verde-suave);}
  .filtro-ativo{background:var(--verde)!important;color:var(--bg)!important;}
  .topo-indicadores{display:flex;flex-wrap:wrap;gap:.35rem 1rem;font-size:.78rem;
                    background:#12171b;border:1px solid var(--border);border-radius:6px;padding:8px 12px;}
  .topo-indicadores .rotulo{color:var(--verde-dim);}
  .topo-indicadores .valor{color:var(--verde-suave);font-weight:bold;}
</style>
</head>
<body>
<div class="container-fluid px-3 py-3">

  <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div>
      <h1>[&gt;] Painel Consolidado</h1>
      <div id="status" class="status">Sincronizando...</div>
    </div>
    <div class="d-flex gap-2 flex-wrap">
      <button id="btnSync" class="btn btn-terminal">Sincronizar</button>
      <a id="btnExport" class="btn btn-terminal" href="?acao=exportar">Exportar</a>
      <label class="btn btn-terminal mb-0">
        Importar<input type="file" id="fileImport" accept=".json" hidden>
      </label>
      <button id="btnLimpar" class="btn btn-terminal">Limpar</button>
    </div>
  </div>

  <div id="topoIndicadores" class="topo-indicadores mb-3" style="display:none;"></div>

  <div class="row g-2 mb-3" id="kpis"></div>

  <div class="card-painel mb-3">
    <div class="card-header">Filtros</div>
    <div class="card-body d-flex flex-wrap gap-2 align-items-center">
      <select id="selJanela" class="form-select form-select-sm w-auto">
        <option value="7" selected>Últimos 7 dias</option>
        <option value="15">Últimos 15 dias</option>
        <option value="30">Últimos 30 dias</option>
      </select>
      <input id="busca" class="form-control form-control-sm w-auto" placeholder="Buscar produto...">
      <div class="btn-group" role="group" id="filtroTipo">
        <button class="btn btn-terminal filtro-ativo" data-tipo="todos">Todos</button>
        <button class="btn btn-terminal" data-tipo="alta">Só altas</button>
        <button class="btn btn-terminal" data-tipo="baixa">Só baixas</button>
        <button class="btn btn-terminal" data-tipo="neutro">Neutros</button>
      </div>
      <select id="selOrdenar" class="form-select form-select-sm w-auto">
        <option value="var_desc">Maior alta</option>
        <option value="var_asc">Maior baixa</option>
        <option value="nome_asc">Nome (A-Z)</option>
        <option value="valor_desc">Maior valor</option>
      </select>
      <span class="ms-auto small text-secondary" id="contador"></span>
    </div>
  </div>

  <div class="row g-3">
    <div class="col-xl-7">
      <div class="card-painel h-100">
        <div class="card-header d-flex justify-content-between align-items-center">
          <span>Gráfico</span>
          <select id="selGrafico" class="form-select form-select-sm w-auto">
            <option value="var">Variação da janela (%)</option>
            <option value="valor">Valor atual</option>
          </select>
        </div>
        <div class="card-body">
          <div class="chart-wrap"><canvas id="grafico"></canvas></div>
        </div>
      </div>
    </div>

    <div class="col-xl-5">
      <div class="card-painel h-100">
        <div class="card-header d-flex justify-content-between">
          <span>Movimento da janela</span>
          <span id="periodoTxt" class="small text-secondary"></span>
        </div>
        <div class="card-body" style="max-height:440px;overflow-y:auto;">
          <div class="mb-1"><span class="badge badge-verde">▲ Em alta</span></div>
          <div id="listaAltas" class="mb-3"></div>
          <div class="mb-1"><span class="badge badge-vermelha">▼ Em baixa</span></div>
          <div id="listaBaixas"></div>
        </div>
      </div>
    </div>
  </div>

  <div class="card-painel mt-3">
    <div class="card-header">Itens comparados na janela</div>
    <div class="card-body p-0">
      <div style="max-height:420px;overflow-y:auto;">
        <table class="tab">
          <thead><tr>
            <th>Produto</th><th class="num">Atual</th><th class="num">Referência</th>
            <th class="num">Var. %</th><th>Unid.</th>
          </tr></thead>
          <tbody id="tabelaItens"></tbody>
        </table>
      </div>
    </div>
  </div>

  <div class="card-painel mt-3">
    <div class="card-header d-flex justify-content-between">
      <span>Snapshots no histórico</span>
      <span class="small text-secondary">clique em ✕ para remover um dia</span>
    </div>
    <div class="card-body" id="listaSnapshots" style="max-height:240px;overflow-y:auto;"></div>
  </div>

</div>
<script>
const nf = new Intl.NumberFormat('pt-BR',{minimumFractionDigits:2,maximumFractionDigits:2});
const state = { dados:null, filtro:'todos', ordem:'var_desc', busca:'' };
let chart = null;

function cls(v){ return v>0.001?'alta':v<-0.001?'baixa':'neutro'; }
function sinal(v){ return v>0.001?'▲ ':v<-0.001?'▼ ':'— '; }

// --- AJAX helper corrigido ---
// api('estado')                         -> GET  ?acao=estado
// api('estado', {janela:7})             -> GET  ?acao=estado&janela=7
// api('sync')                           -> GET  ?acao=sync
// api('remover_dia', null, {dia:'...'}) -> POST ?acao=remover_dia  (corpo JSON)
function api(acao, params, corpo, metodo) {
  params = params || {};
  const qs = new URLSearchParams(Object.assign({acao}, params)).toString();
  const opts = {
    url: '?' + qs,
    method: metodo || (corpo ? 'POST' : 'GET'),
    dataType: 'json',
    timeout: 20000
  };
  if (corpo) {
    opts.data = JSON.stringify(corpo);
    opts.contentType = 'application/json; charset=utf-8';
  }
  return $.ajax(opts);
}

function carregar(msg) {
  if (msg) $('#status').text(msg);
  const janela = $('#selJanela').val() || '7';
  return api('estado', {janela})
    .done(function(res){
      if (!res || !res.ok) { $('#status').text('Erro: ' + (res?.erro||'?')); return; }
      state.dados = res;
      $('#status').text(`OK · ${res.total_snapshots} snapshot(s) · último ${res.ultimo?.data || '—'}`);
      renderTudo();
    })
    .fail(function(xhr){
      let detalhe = xhr.status;
      try { detalhe += ' ' + (xhr.responseJSON?.erro || xhr.responseText?.slice(0,120) || ''); } catch(_){}
      $('#status').text('Falha na API: ' + detalhe);
      console.error('Falha AJAX', xhr.status, xhr.responseText);
    });
}

function sincronizar() {
  $('#status').text('Lendo consolidado.json...');
  return api('sync')
    .done(function(res){
      if (!res || !res.ok) { $('#status').text('Erro: ' + (res?.erro||'?')); return; }
      state.dados = res;
      $('#status').text(`Sincronizado · ${res.total_snapshots} snapshot(s)`);
      renderTudo();
    })
    .fail(function(xhr){
      let detalhe = xhr.status;
      try { detalhe += ' ' + (xhr.responseJSON?.erro || xhr.responseText?.slice(0,120) || ''); } catch(_){}
      $('#status').text('Falha ao sincronizar: ' + detalhe);
      console.error('Falha AJAX', xhr.status, xhr.responseText);
    });
}

function renderIndicadores() {
  const q = state.dados?.quotes || {};
  const rotulos = {
    temperatura:'Indaiatuba / SP', gasolina:'Gasolina', petroleo:'Petróleo',
    dolar:'Dólar', prata:'Prata', ouro:'Ouro', bitcoin:'Bitcoin'
  };
  let html = '';
  for (const [k,r] of Object.entries(rotulos)) {
    if (q[k] !== undefined) html += `<span><span class="rotulo">${r}:</span> <span class="valor">${q[k]}</span></span>`;
  }
  if (html) $('#topoIndicadores').show().html(html);
}

function renderKPIs() {
  const res = state.dados; if (!res) return;
  const s = (res.semana && res.semana.ok) ? res.semana : { altas:[], baixas:[], todas:[] };
  const maiorAlta  = s.altas.length  ? s.altas[0] : null;
  const maiorBaixa = s.baixas.length ? s.baixas[s.baixas.length-1] : null;
  $('#kpis').html(`
    <div class="col-6 col-md-3"><div class="kpi">
      <div class="text-secondary small">Snapshot atual</div>
      <div class="num">${res.ultimo?.data || '—'}</div></div></div>
    <div class="col-6 col-md-3"><div class="kpi">
      <div class="text-secondary small">Altas / Baixas</div>
      <div class="num"><span class="alta">${s.altas.length}</span> / <span class="baixa">${s.baixas.length}</span></div></div></div>
    <div class="col-6 col-md-3"><div class="kpi">
      <div class="text-secondary small">Maior alta</div>
      <div class="num alta">${maiorAlta? sinal(maiorAlta.varPct)+nf.format(maiorAlta.varPct)+'%' : '—'}</div>
      <div class="small text-secondary">${maiorAlta?.descricao || ''}</div></div></div>
    <div class="col-6 col-md-3"><div class="kpi">
      <div class="text-secondary small">Maior baixa</div>
      <div class="num baixa">${maiorBaixa? sinal(maiorBaixa.varPct)+nf.format(maiorBaixa.varPct)+'%' : '—'}</div>
      <div class="small text-secondary">${maiorBaixa?.descricao || ''}</div></div></div>
  `);
}

function itensFiltrados() {
  const s = state.dados?.semana;
  if (!s || !s.ok) return [];
  let lista = s.todas.slice();
  if (state.filtro === 'alta')   lista = lista.filter(l => l.varPct >  0.001);
  if (state.filtro === 'baixa')  lista = lista.filter(l => l.varPct < -0.001);
  if (state.filtro === 'neutro') lista = lista.filter(l => Math.abs(l.varPct) <= 0.001);
  if (state.busca) {
    const q = state.busca.toLowerCase();
    lista = lista.filter(l => l.descricao.toLowerCase().includes(q));
  }
  switch (state.ordem) {
    case 'var_desc':   lista.sort((a,b)=>b.varPct-a.varPct); break;
    case 'var_asc':    lista.sort((a,b)=>a.varPct-b.varPct); break;
    case 'nome_asc':   lista.sort((a,b)=>a.descricao.localeCompare(b.descricao)); break;
    case 'valor_desc': lista.sort((a,b)=>b.atual-a.atual); break;
  }
  return lista;
}

function renderGrafico() {
  const modo = $('#selGrafico').val();
  const top = itensFiltrados().slice(0, 20);
  const labels = top.map(l => l.descricao.length>28? l.descricao.slice(0,28)+'…' : l.descricao);
  const valores = top.map(l => modo==='var' ? l.varPct : l.atual);
  const cores = modo==='var'
    ? top.map(l => l.varPct>=0 ? 'rgba(0,255,102,.45)' : 'rgba(255,102,102,.45)')
    : top.map(() => 'rgba(0,255,102,.35)');

  if (chart) chart.destroy();
  chart = new Chart(document.getElementById('grafico').getContext('2d'), {
    type:'bar',
    data:{ labels, datasets:[{ label: modo==='var'?'Var %':'Valor',
      data: valores, backgroundColor: cores,
      borderColor:'#00ff66', borderWidth:1, borderRadius:3 }] },
    options:{ indexAxis:'y', responsive:true, maintainAspectRatio:false,
      plugins:{ legend:{display:false},
        tooltip:{ callbacks:{ label:c=> modo==='var'? ` ${nf.format(c.parsed.x)}%` : ` ${nf.format(c.parsed.x)}` } } },
      scales:{ x:{ ticks:{color:'#44aa66',font:{size:10}}, grid:{color:'#2e3840'} },
               y:{ ticks:{color:'#66ff99',font:{size:10}}, grid:{color:'#2e3840'} } } }
  });
}

function renderListas() {
  const s = state.dados?.semana;
  const altasEl = $('#listaAltas').empty(), baixasEl = $('#listaBaixas').empty();
  if (!s || !s.ok) {
    altasEl.html(`<div class="neutro">${s?.motivo || 'Sem dados.'}</div>`);
    $('#periodoTxt').text('');
    return;
  }
  s.altas.slice(0,8).forEach(l => altasEl.append(
    `<div class="linha-var"><span>${l.descricao}</span><span class="alta">${sinal(l.varPct)}${nf.format(l.varPct)}%</span></div>`));
  if (!s.altas.length) altasEl.html('<div class="neutro">Nenhum item em alta.</div>');

  s.baixas.slice(-8).reverse().forEach(l => baixasEl.append(
    `<div class="linha-var"><span>${l.descricao}</span><span class="baixa">${sinal(l.varPct)}${nf.format(l.varPct)}%</span></div>`));
  if (!s.baixas.length) baixasEl.html('<div class="neutro">Nenhum item em baixa.</div>');

  $('#periodoTxt').text(`${s.periodo.de} → ${s.periodo.ate} (${s.periodo.dias}d)`);
}

function renderTabela() {
  const lista = itensFiltrados();
  const corpo = $('#tabelaItens').empty();
  lista.forEach(l => corpo.append(`<tr>
    <td>${l.descricao}</td>
    <td class="num">${nf.format(l.atual)}</td>
    <td class="num">${nf.format(l.ref)}</td>
    <td class="num ${cls(l.varPct)}">${sinal(l.varPct)}${nf.format(l.varPct)}%</td>
    <td>${l.unidade || ''}</td>
  </tr>`));
  $('#contador').text(`${lista.length} item(ns)`);
}

function renderSnapshots() {
  const snaps = state.dados?.snapshots || [];
  const cont = $('#listaSnapshots').empty();
  if (!snaps.length) { cont.html('<div class="neutro">Nenhum snapshot salvo.</div>'); return; }
  snaps.slice().reverse().forEach(s => {
    cont.append(`<div class="linha-var">
      <span>${s.data} · ${s.itens} itens</span>
      <button class="btn btn-terminal btn-sm py-0 px-2" data-dia="${s.data}">✕</button>
    </div>`);
  });
  cont.find('button[data-dia]').on('click', function(){
    const dia = $(this).data('dia');
    if (!confirm('Remover snapshot ' + dia + '?')) return;
    api('remover_dia', null, {dia})
      .done(()=> carregar())
      .fail(xhr => alert('Falha ao remover: ' + xhr.status));
  });
}

function renderTudo() {
  renderIndicadores();
  renderKPIs();
  renderGrafico();
  renderListas();
  renderTabela();
  renderSnapshots();
}

$(function(){
  carregar('Carregando estado...');

  $('#btnSync').on('click', sincronizar);
  $('#btnLimpar').on('click', function(){
    if (!confirm('Apagar todo o histórico no servidor?')) return;
    api('limpar', null, {}, 'POST').done(()=> carregar()).fail(xhr => alert('Falha: ' + xhr.status));
  });
  $('#selJanela').on('change', ()=> carregar('Recalculando janela...'));
  $('#busca').on('input', function(){ state.busca = $(this).val(); renderTabela(); renderGrafico(); });
  $('#filtroTipo button').on('click', function(){
    $('#filtroTipo button').removeClass('filtro-ativo');
    $(this).addClass('filtro-ativo');
    state.filtro = $(this).data('tipo');
    renderTabela(); renderGrafico();
  });
  $('#selOrdenar').on('change', function(){ state.ordem = $(this).val(); renderTabela(); });
  $('#selGrafico').on('change', renderGrafico);
  $('#fileImport').on('change', function(e){
    const file = e.target.files[0]; if (!file) return;
    const reader = new FileReader();
    reader.onload = function(ev){
      try {
        const json = JSON.parse(ev.target.result);
        $.ajax({ url:'?acao=importar', method:'POST',
          data: JSON.stringify(json), contentType:'application/json', dataType:'json' })
          .done(()=> carregar())
          .fail(xhr => alert('Falha: ' + (xhr.responseJSON?.erro || xhr.status)));
      } catch(err) { alert('JSON inválido: ' + err.message); }
    };
    reader.readAsText(file);
    e.target.value = '';
  });
});
</script>
</body>
</html>