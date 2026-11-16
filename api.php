<?php
// Motor do Diagnóstico GMN · JN Tráfego Pago
// Busca dados públicos do Google Maps via SerpApi e devolve um JSON normalizado.
// A chave fica em config/serpapi.key (pasta protegida por .htaccess), nunca na página.
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$BASE = __DIR__;
$KEY = trim((string)@file_get_contents($BASE . '/config/serpapi.key'));
$ACESSO = trim((string)@file_get_contents($BASE . '/config/acesso.txt'));
$LIMITE_DIA = 150; // buscas novas por dia (trava contra gasto)

function sair(array $d, int $code = 200): void { http_response_code($code); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }

if ($KEY === '') sair(['erro' => 'Chave do SerpApi não configurada no servidor.'], 500);
$ADMIN = trim((string)@file_get_contents($BASE . '/config/admin.txt'));
$admOk = $ADMIN !== '' && hash_equals($ADMIN, (string)($_GET['adm'] ?? ''));
if (!$admOk && $ACESSO !== '' && ($_GET['k'] ?? '') !== $ACESSO) sair(['erro' => 'Código de acesso inválido.'], 403);

$CACHE = $BASE . '/cache';
if (!is_dir($CACHE)) @mkdir($CACHE, 0700, true);

function novasHoje(string $dir): int {
  $n = 0; $hoje = date('Y-m-d');
  foreach (glob($dir . '/*.json') ?: [] as $f) if (date('Y-m-d', filemtime($f)) === $hoje) $n++;
  return $n;
}

function serp(array $p): array {
  global $KEY, $CACHE, $LIMITE_DIA;
  $p += ['hl' => 'pt-br', 'gl' => 'br'];
  $f = $CACHE . '/' . md5(json_encode($p)) . '.json';
  if (is_file($f) && time() - filemtime($f) < 3 * 86400) return json_decode((string)file_get_contents($f), true) ?: [];
  if (novasHoje($CACHE) >= $LIMITE_DIA) return ['error' => 'Limite diário de buscas atingido.'];
  $p['api_key'] = $KEY;
  $url = 'https://serpapi.com/search.json?' . http_build_query($p);
  $ch = curl_init($url);
  curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 45]);
  $r = curl_exec($ch); curl_close($ch);
  if ($r === false || $r === '') return ['error' => 'Falha de conexão com o provedor de dados.'];
  $d = json_decode($r, true) ?: ['error' => 'Resposta inválida do provedor.'];
  if (empty($d['error'])) file_put_contents($f, $r);
  return $d;
}

function resumoLocal(array $r): array {
  return [
    'titulo' => $r['title'] ?? '', 'data_id' => $r['data_id'] ?? '', 'endereco' => $r['address'] ?? '',
    'nota' => $r['rating'] ?? null, 'avaliacoes' => $r['reviews'] ?? 0,
    'categoria' => is_array($r['type'] ?? null) ? implode(', ', $r['type']) : (string)($r['type'] ?? ''),
    'lat' => $r['gps_coordinates']['latitude'] ?? null, 'lng' => $r['gps_coordinates']['longitude'] ?? null,
  ];
}

$acao = $_GET['acao'] ?? '';

// ---- Leads ----
// config/leads.csv: uma linha por lead (tela 1). config/eventos.csv: buscas e diagnósticos de cada lead.
function lidValido(string $lid): bool {
  global $BASE;
  // Acesso de administrador (Jaderson e Lidiane): link com a chave de admin
  $adm = trim((string)@file_get_contents($BASE . '/config/admin.txt'));
  if ($lid === 'admin' && $adm !== '' && hash_equals($adm, (string)($_GET['adm'] ?? ''))) return true;
  if (!preg_match('/^[a-f0-9]{16}$/', $lid)) return false;
  $f = $BASE . '/config/leads.csv';
  return is_file($f) && strpos((string)file_get_contents($f), $lid) !== false;
}
function exigirLead(): void {
  if (!lidValido((string)($_GET['lid'] ?? ''))) sair(['erro' => 'Preencha seus dados primeiro (atualize a página).'], 401);
}
function registrar(string $tipo, array $extra): void {
  global $BASE;
  $f = $BASE . '/config/eventos.csv'; $novo = !is_file($f);
  if ($fh = fopen($f, 'a')) {
    if ($novo) fputcsv($fh, ['data_hora', 'lid', 'tipo', 'detalhe']);
    fputcsv($fh, [date('Y-m-d H:i:s'), $_GET['lid'] ?? '', $tipo, json_encode($extra, JSON_UNESCAPED_UNICODE)]);
    fclose($fh);
  }
}

if ($acao === 'lead') {
  $ln = trim((string)($_GET['lead_nome'] ?? '')); $le = trim((string)($_GET['lead_email'] ?? ''));
  $lf = preg_replace('/\D+/', '', (string)($_GET['lead_fone'] ?? ''));
  if (mb_strlen($ln) < 2) sair(['erro' => 'Preencha seu nome.'], 400);
  if (!filter_var($le, FILTER_VALIDATE_EMAIL)) sair(['erro' => 'Preencha um e-mail válido.'], 400);
  if (strlen($lf) < 10 || strlen($lf) > 13) sair(['erro' => 'Preencha um WhatsApp válido, com DDD.'], 400);
  if (($_GET['lgpd'] ?? '') !== '1') sair(['erro' => 'É preciso aceitar a Política de Privacidade.'], 400);
  $lid = bin2hex(random_bytes(8));
  $arq = $BASE . '/config/leads.csv'; $novo = !is_file($arq);
  $fh = fopen($arq, 'a');
  if (!$fh) sair(['erro' => 'Não foi possível salvar seus dados. Tente de novo.'], 500);
  if ($novo) fputcsv($fh, ['data_hora', 'lid', 'nome', 'email', 'whatsapp', 'ip']);
  fputcsv($fh, [date('Y-m-d H:i:s'), $lid, $ln, $le, $lf, $_SERVER['REMOTE_ADDR'] ?? '']);
  fclose($fh);
  sair(['ok' => true, 'lid' => $lid]);
}

if ($acao === 'buscar') {
  $q = trim(($_GET['nome'] ?? '') . ' ' . ($_GET['cidade'] ?? ''));
  if (mb_strlen($q) < 3) sair(['erro' => 'Digite o nome da empresa' . (($_GET['ecommerce'] ?? '') === '1' ? '.' : ' e a cidade.')], 400);
  exigirLead();
  registrar('busca', ['empresa' => $q]);
  $d = serp(['engine' => 'google_maps', 'q' => $q, 'type' => 'search']);
  if (!empty($d['error'])) sair(['erro' => $d['error']], 502);
  $lista = [];
  if (!empty($d['place_results'])) $lista[] = resumoLocal($d['place_results']);
  foreach (array_slice($d['local_results'] ?? [], 0, 10) as $r) $lista[] = resumoLocal($r);
  sair(['resultados' => $lista]);
}

if ($acao === 'diagnostico') {
  $id = $_GET['data_id'] ?? ''; $lat = (float)($_GET['lat'] ?? 0); $lng = (float)($_GET['lng'] ?? 0);
  $palavra = trim($_GET['palavra'] ?? '');
  if (!preg_match('/^0x[0-9a-f]+:0x[0-9a-f]+$/i', $id) || !$lat || !$lng) sair(['erro' => 'Empresa inválida. Busque de novo.'], 400);
  exigirLead();
  registrar('diagnostico', ['empresa' => (string)($_GET['titulo'] ?? ''), 'data_id' => $id]);

  // 1. Perfil
  $pd = serp(['engine' => 'google_maps', 'type' => 'place', 'data' => "!4m5!3m4!1s{$id}!8m2!3d{$lat}!4d{$lng}"]);
  if (!empty($pd['error'])) sair(['erro' => $pd['error']], 502);
  $p = $pd['place_results'] ?? [];
  $tipos = is_array($p['type'] ?? null) ? $p['type'] : array_filter([(string)($p['type'] ?? '')]);
  // Empresa sem endereço (área de cobertura): o Google dá um ponto genérico. Centraliza na cidade digitada.
  $centroPorCidade = false; $ecommerce = ($_GET['ecommerce'] ?? '') === '1';
  $cidadeDigitada = trim((string)($_GET['cidade'] ?? ''));
  if (!$ecommerce && trim((string)($p['address'] ?? '')) === '' && $cidadeDigitada !== '') {
    $cg = serp(['engine' => 'google_maps', 'q' => $cidadeDigitada . ', Brasil', 'type' => 'search']);
    $gc = $cg['place_results']['gps_coordinates'] ?? ($cg['local_results'][0]['gps_coordinates'] ?? null);
    if ($gc && isset($gc['latitude'], $gc['longitude'])) { $lat = (float)$gc['latitude']; $lng = (float)$gc['longitude']; $centroPorCidade = true; }
  }
  if ($palavra === '') $palavra = $tipos[0] ?? ($p['title'] ?? '');

  // 1b. Painel da empresa na Pesquisa Google (descrição "Da empresa" e redes sociais)
  $cidade = '';
  if (preg_match('/-\s*([^,\-]+?)\s*-\s*[A-Z]{2}\b/u', (string)($p['address'] ?? ''), $mm)) $cidade = trim($mm[1]);
  $kgRaw = serp(['engine' => 'google', 'q' => trim(($p['title'] ?? '') . ' ' . $cidade), 'google_domain' => 'google.com.br']);
  $kg = $kgRaw['knowledge_graph'] ?? [];
  $kgOk = !empty($kg['title']) && (mb_strtolower($kg['title']) === mb_strtolower((string)($p['title'] ?? '')) || ($kg['place_id'] ?? '') === ($p['place_id'] ?? '-'));
  $redes = [];
  foreach (($kg['perfis'] ?? $kg['profiles'] ?? []) as $pf) $redes[] = ['nome' => $pf['name'] ?? '', 'link' => $pf['link'] ?? ''];
  $descricao = $kgOk ? trim((string)($kg['merchant_description'] ?? ''), " \"\n") : null;

  // 1c. Site: confere se telefone e nome aparecem no site e se há links de redes
  $siteCheck = null; $site = (string)($p['website'] ?? '');
  if ($site !== '' && !preg_match('#instagram\.com|facebook\.com|wa\.me|linktr\.ee|docs\.google|forms\.gle|drive\.google#i', $site)) {
    $ch = curl_init($site);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 15, CURLOPT_USERAGENT => 'Mozilla/5.0 (DiagnosticoJN)']);
    $html = (string)curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    $digSite = preg_replace('/\D+/', '', $html); $tel = preg_replace('/\D+/', '', (string)($p['phone'] ?? ''));
    $tel8 = substr($tel, -8);
    $nomeBase = mb_strtolower(trim(preg_split('/[|\-–]/u', (string)($p['title'] ?? ''))[0]));
    $siteCheck = [
      'abre' => $code >= 200 && $code < 400, 'http' => $code,
      'telefone_no_site' => $tel8 !== '' && strpos($digSite, $tel8) !== false,
      'nome_no_site' => $nomeBase !== '' && mb_strpos(mb_strtolower(strip_tags($html)), $nomeBase) !== false,
      'instagram' => (bool)preg_match('#instagram\.com/[A-Za-z0-9_.]+#', $html),
      'facebook' => (bool)preg_match('#facebook\.com/[A-Za-z0-9_.]+#', $html),
    ];
  }
  // Atributos lidos (parciais: só confirmam presença)
  $atributos = [];
  foreach (array_merge($p['extensions'] ?? [], $p['unsupported_extensions'] ?? []) as $grp) foreach ((array)$grp as $k => $vals) foreach ((array)$vals as $v) $atributos[] = $v;

  // 2. Avaliações (mais novas primeiro)
  $rv = serp(['engine' => 'google_maps_reviews', 'data_id' => $id, 'sort_by' => 'newestFirst']);
  $avals = [];
  foreach ($rv['reviews'] ?? [] as $r) $avals[] = [
    'nota' => $r['rating'] ?? null, 'data' => substr((string)($r['iso_date'] ?? ''), 0, 10),
    'texto' => mb_substr((string)($r['snippet'] ?? ''), 0, 160), 'respondida' => !empty($r['response']),
  ];

  // 3. Fotos (primeira página; "mais" indica que há outras páginas)
  $ph = serp(['engine' => 'google_maps_photos', 'data_id' => $id]);
  $fotos = count($ph['photos'] ?? []);
  $fotosMais = !empty($ph['serpapi_pagination']['next_page_token']);

  // 4E. E-commerce: posição do site na Pesquisa Google em 5 capitais (média Brasil)
  if ($ecommerce) {
    $dominio = preg_replace('/^www\./', '', (string)parse_url((string)($p['website'] ?? ''), PHP_URL_HOST));
    $capitais = ['São Paulo' => 'Sao Paulo,State of Sao Paulo,Brazil', 'Rio de Janeiro' => 'Rio de Janeiro,State of Rio de Janeiro,Brazil',
      'Belo Horizonte' => 'Belo Horizonte,State of Minas Gerais,Brazil', 'Porto Alegre' => 'Porto Alegre,State of Rio Grande do Sul,Brazil',
      'Brasília' => 'Brasilia,Federal District,Brazil'];
    $linhas = []; $freq = []; $shopping = false; $pool = [];
    foreach ($capitais as $nomeCid => $loc) {
      $g = serp(['engine' => 'google', 'q' => $palavra, 'location' => $loc, 'google_domain' => 'google.com.br', 'num' => 20]);
      $pos = null; $top = [];
      foreach ($g['organic_results'] ?? [] as $r) {
        $host = preg_replace('/^www\./', '', (string)parse_url((string)($r['link'] ?? ''), PHP_URL_HOST));
        if ($dominio !== '' && $pos === null && ($host === $dominio || str_ends_with($host, '.' . $dominio))) $pos = $r['position'] ?? null;
        if (count($top) < 3) $top[] = ['site' => $host, 'titulo' => $r['title'] ?? ''];
        if (($r['position'] ?? 99) <= 5 && $host !== '') { $freq[$host] = $freq[$host] ?? ['n' => 0, 'soma' => 0, 'titulo' => $r['title'] ?? '']; $freq[$host]['n']++; $freq[$host]['soma'] += (int)$r['position']; }
      }
      $lp = $g['local_results']['places'] ?? (isset($g['local_results'][0]) ? $g['local_results'] : []);
      foreach ($lp as $pl) { $t = (string)($pl['title'] ?? ''); if ($t === '' || !isset($pl['rating'])) continue;
        $rev = (int)preg_replace('/\D+/', '', (string)($pl['reviews'] ?? 0));
        $pool[mb_strtolower($t)] = ['titulo' => $t, 'nota' => (float)$pl['rating'], 'avaliacoes' => $rev, 'cidade' => $nomeCid]; }
      foreach (array_merge($g['shopping_results'] ?? [], $g['immersive_products'] ?? []) as $s)
        if ($dominio !== '' && stripos((string)($s['source'] ?? '') . ' ' . (string)($s['link'] ?? ''), explode('.', $dominio)[0]) !== false) $shopping = true;
      $linhas[] = ['cidade' => $nomeCid, 'pos' => $pos, 'top3' => $top];
    }
    uasort($freq, fn($a, $b) => [$b['n'], $a['soma'] / $a['n']] <=> [$a['n'], $b['soma'] / $b['n']]);
    $lideres = [];
    foreach (array_slice($freq, 0, 5, true) as $host => $f) $lideres[] = ['site' => $host, 'titulo' => $f['titulo'], 'cidades' => $f['n'], 'media' => round($f['soma'] / $f['n'], 1)];
    $posValidas = array_filter(array_column($linhas, 'pos'));
    // Ranking por pontuação (força = nota x volume), com a própria empresa inserida
    $forca = fn($n, $q) => round($n * log(1 + $q), 2);
    unset($pool[mb_strtolower((string)($p['title'] ?? ''))]);
    $rank = array_values($pool);
    $rank[] = ['titulo' => $p['title'] ?? '', 'nota' => (float)($p['rating'] ?? 0), 'avaliacoes' => (int)($p['reviews'] ?? 0), 'cidade' => '', 'voce' => true];
    foreach ($rank as &$r) $r['forca'] = $forca($r['nota'], $r['avaliacoes']); unset($r);
    usort($rank, fn($a, $b) => $b['forca'] <=> $a['forca']);
    $posForca = null; foreach ($rank as $i => $r) if (!empty($r['voce'])) $posForca = $i + 1;
    sair([
      'nome' => $p['title'] ?? '', 'endereco' => $p['address'] ?? '', 'categorias' => array_values($tipos),
      'site' => $p['website'] ?? '', 'telefone' => $p['phone'] ?? '', 'horario' => $p['hours'] ?? null,
      'nota' => $p['rating'] ?? null, 'total_avaliacoes' => $p['reviews'] ?? 0,
      'distribuicao' => $p['rating_summary'] ?? [], 'nao_reivindicado' => !empty($p['unclaimed_listing']),
      'temporariamente_fechado' => stripos((string)($p['open_state'] ?? ''), 'temporariamente') !== false,
      'painel_ok' => $kgOk, 'descricao' => $descricao, 'redes' => $redes, 'site_check' => $siteCheck, 'atributos' => $atributos,
      'avaliacoes' => $avals, 'fotos' => $fotos, 'fotos_mais' => $fotosMais,
      'posts' => array_map(fn($x) => ['data' => $x['date'] ?? '', 'cta' => $x['cta'] ?? ''], $p['posts'] ?? []),
      'ecommerce' => true, 'nacional' => ['palavra' => $palavra, 'dominio' => $dominio, 'cidades' => $linhas, 'lideres' => $lideres,
        'media' => $posValidas ? round(array_sum($posValidas) / count($posValidas), 1) : null, 'aparece_em' => count($posValidas), 'shopping' => $shopping,
        'ranking_forca' => array_slice($rank, 0, 15), 'pos_forca' => $posForca, 'total_forca' => count($rank)],
      'concorrentes' => [], 'gerado_em' => date('c'),
    ]);
  }

  // 4. Grade 3x3 (~2 km) para a palavra-chave
  $n = 3; $passo = 2.0; $dlat = $passo / 111.0; $dlng = $passo / (111.0 * cos(deg2rad($lat)));
  $pontos = []; $centro = [];
  for ($i = 0; $i < $n; $i++) for ($j = 0; $j < $n; $j++) {
    $la = $lat + (intdiv($n, 2) - $i) * $dlat; $ln = $lng + ($j - intdiv($n, 2)) * $dlng;
    $g = serp(['engine' => 'google_maps', 'q' => $palavra, 'type' => 'search', 'll' => sprintf('@%.7f,%.7f,15z', $la, $ln)]);
    $pos = null;
    foreach ($g['local_results'] ?? [] as $r) if (($r['data_id'] ?? '') === $id) { $pos = $r['position'] ?? null; break; }
    $pontos[] = ['lat' => $la, 'lng' => $ln, 'pos' => $pos];
    if ($i === 1 && $j === 1) $centro = $g['local_results'] ?? [];
  }
  $conc = [];
  foreach ($centro as $r) { if (($r['data_id'] ?? '') === $id) continue; $conc[] = resumoLocal($r) + ['posicao' => $r['position'] ?? null]; if (count($conc) >= 3) break; }

  sair([
    'nome' => $p['title'] ?? '', 'endereco' => $p['address'] ?? '', 'categorias' => array_values($tipos),
    'site' => $p['website'] ?? '', 'telefone' => $p['phone'] ?? '', 'horario' => $p['hours'] ?? null,
    'nota' => $p['rating'] ?? null, 'total_avaliacoes' => $p['reviews'] ?? 0,
    'distribuicao' => $p['rating_summary'] ?? [], 'nao_reivindicado' => !empty($p['unclaimed_listing']),
    'temporariamente_fechado' => stripos((string)($p['open_state'] ?? ''), 'temporariamente') !== false,
    'painel_ok' => $kgOk, 'descricao' => $descricao, 'redes' => $redes, 'site_check' => $siteCheck, 'atributos' => $atributos,
    'avaliacoes' => $avals, 'fotos' => $fotos, 'fotos_mais' => $fotosMais,
    'posts' => array_map(fn($x) => ['data' => $x['date'] ?? '', 'cta' => $x['cta'] ?? ''], $p['posts'] ?? []),
    'grade' => ['palavra' => $palavra, 'passo_km' => $passo, 'pontos' => $pontos],
    'concorrentes' => $conc, 'lat' => $lat, 'lng' => $lng, 'centro_por_cidade' => $centroPorCidade, 'cidade' => $cidadeDigitada, 'gerado_em' => date('c'),
  ]);
}

sair(['erro' => 'Ação desconhecida.'], 400);
