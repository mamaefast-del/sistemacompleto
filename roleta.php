<?php
ob_start();
session_start();
require 'db.php';

// --- Verificação de Login ---
if (!isset($_SESSION['usuario_id'])) {
    header('Location: index.php');
    exit;
}

// --- Carregamento dos Dados ---
$idJogo = isset($_GET['id']) ? intval($_GET['id']) : 1;

// Buscar dados do jogo
$stmt = $pdo->prepare("SELECT * FROM raspadinhas_config WHERE id = ? AND ativa = 1");
$stmt->execute([$idJogo]);
$jogo = $stmt->fetch();

if (!$jogo) {
    echo "Jogo inválido!";
    exit;
}

// Buscar dados do usuário
$stmt = $pdo->prepare("SELECT * FROM usuarios WHERE id = ?");
$stmt->execute([$_SESSION['usuario_id']]);
$usuario = $stmt->fetch();
if (!$usuario) {
    session_destroy();
    header('Location: index.php');
    exit;
}

// --- Lógica de Negócio ---
$custo     = (float) ($jogo['valor'] ?? 0);
$saldo     = (float) ($usuario['saldo'] ?? 0);
$contaDemo = (bool)  ($usuario['conta_demo'] ?? 0);

$chanceGanho = isset($usuario['percentual_ganho']) && $usuario['percentual_ganho'] !== null
    ? (float) $usuario['percentual_ganho']
    : (float) ($jogo['chance_ganho'] ?? 0);

$moeda = 'R$';

// Distribuição de prêmios - buscar do banco
$premios_json  = $jogo['premios_json'] ?? '[]';
$premios_array = json_decode($premios_json, true);
if (!is_array($premios_array)) $premios_array = [];

// Processar os prêmios do formato array de objetos
$distribuicao = []; // [chave => ['valor'=>float, 'chance'=>float]]
$premios_info = []; // [chave => ['nome'=>string, 'imagem'=>string, 'valor'=>float]]

foreach ($premios_array as $index => $premio) {
    if (!is_array($premio)) continue;

    $valorNum = (float) ($premio['valor'] ?? 0);
    $chanceNum= (float) ($premio['chance'] ?? 0);
    $nome     = (string) ($premio['nome'] ?? '');
    $imagem   = (string) ($premio['imagem'] ?? '');

    $chave_premio = "premio_" . $index; // chave textual
    $distribuicao[$chave_premio] = ['valor' => $valorNum, 'chance' => $chanceNum];
    $premios_info[$chave_premio] = ['nome' => $nome, 'imagem' => $imagem, 'valor' => $valorNum];
}

// garante info do não ganhou
$premios_info['nao_ganhou'] = $premios_info['nao_ganhou'] ?? [
    'nome' => 'Não Ganhou',
    'imagem' => '',
    'valor' => 0
];

/**
 * Sorteio com pesos inteiros (evita deprecated de float->int)
 * Converte pesos (chance) para inteiros via escala (ex.: 100) preservando decimais
 */
function sortearPremioDistribuido(array $distribuicao): array {
    // escala para transformar chances decimais em inteiros
    $ESCALA = 100;

    $pesos = [];
    $total = 0;
    foreach ($distribuicao as $chave => $dados) {
        $peso = (int) round(((float) ($dados['chance'] ?? 0)) * $ESCALA);
        if ($peso < 0) $peso = 0;
        $pesos[$chave] = $peso;
        $total += $peso;
    }

    if ($total <= 0) return ['chave' => 'nao_ganhou', 'valor' => 0.00];

    // random_int evita viés e é seguro
    $rand = random_int(1, $total);
    $acumulado = 0;

    foreach ($pesos as $chave => $peso) {
        $acumulado += $peso;
        if ($rand <= $acumulado) {
            return [
                'chave' => $chave,
                'valor' => (float) ($distribuicao[$chave]['valor'] ?? 0)
            ];
        }
    }

    return ['chave' => 'nao_ganhou', 'valor' => 0.00];
}

// --- POST principal ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($saldo < $custo) {
        $_SESSION['erro_roleta'] = "Saldo insuficiente!";
        header("Location: roleta.php?id=$idJogo");
        exit;
    }

    $ganhou = false;
    $premio_real = 0.00;
    $premio_chave = 'nao_ganhou';

    // Lógica especial para conta demo (modo influenciador)
    if ($contaDemo && !empty($distribuicao)) {
        // Conta demo sempre ganha, mas varia entre os maiores prêmios
        $premios_ordenados = $distribuicao;
        uasort($premios_ordenados, fn($a, $b) => $b['valor'] <=> $a['valor']);
        $maiores_premios = array_slice($premios_ordenados, 0, 5, true);
        
        if (!empty($maiores_premios)) {
            $chaves_premios = array_keys($maiores_premios);
            $chave_sorteada = $chaves_premios[array_rand($chaves_premios)];
            
            $premio_real = (float) $maiores_premios[$chave_sorteada]['valor'];
            $premio_chave = $chave_sorteada;
            $ganhou = $premio_real > 0;
        }
    } elseif (!empty($distribuicao) && $chanceGanho > 0 && random_int(1, 100) <= (int) round($chanceGanho)) {
        // Lógica normal: filtra apenas prêmios com chance > 0
        $premios_com_chance = array_filter($distribuicao, fn($d) => ((float)$d['chance']) > 0);

        if (!empty($premios_com_chance)) {
            $resultado_sorteio = sortearPremioDistribuido($premios_com_chance);
            $premio_real = (float) $resultado_sorteio['valor'];
            $premio_chave = $resultado_sorteio['chave'];
            $ganhou = $premio_real > 0;
        }
    }

    // --- Atualizações no Banco ---
    $novo_saldo = $saldo - $custo + $premio_real;
    $stmt = $pdo->prepare("UPDATE usuarios SET saldo = ? WHERE id = ?");
    $stmt->execute([$novo_saldo, $_SESSION['usuario_id']]);

    $stmt = $pdo->prepare("INSERT INTO historico_jogos (usuario_id, raspadinha_id, valor_apostado, valor_premiado) VALUES (?, ?, ?, ?)");
    $stmt->execute([$_SESSION['usuario_id'], $idJogo, $custo, $premio_real]);

    $_SESSION['roleta_resultado'] = [
        'ganhou'       => $ganhou,
        'premio_real'  => $premio_real,
        'premio_chave' => $premio_chave
    ];

    header("Location: roleta.php?id=$idJogo");
    exit;
}

// --- Resultado da sessão ---
$resultado = null;
if (isset($_SESSION['roleta_resultado'])) {
    $resultado = $_SESSION['roleta_resultado'];
    unset($_SESSION['roleta_resultado']);
}

// --- Montagem dos segmentos visuais ---
$logo = 'logo.png';
$segmentos_visuais = [];

// Mostrar TODOS os prêmios na roleta, incluindo os com chance 0
if (!empty($distribuicao)) {
    // adiciona alguns “não ganhou” para espaçar visualmente
    $segmentos_visuais[] = 'nao_ganhou';
    $segmentos_visuais[] = 'nao_ganhou';
    $segmentos_visuais[] = 'nao_ganhou';

    foreach ($distribuicao as $chave => $dados) {
        // aparece pelo menos 1 vez
        $segmentos_visuais[] = $chave;

        // se chance > 0, duplica mais vezes para “puxar” visualmente
        $chanceNum = (float) ($dados['chance'] ?? 0);
        if ($chanceNum > 0) {
            $instancias = max(1, (int) floor($chanceNum / 2)); // ajuste fino visual
            for ($i = 0; $i < $instancias; $i++) {
                $segmentos_visuais[] = $chave;
            }
        }
    }
}

// Embaralhar os segmentos para distribuição aleatória visual
shuffle($segmentos_visuais);

?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>Roleta da Sorte</title>
    <meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1,user-scalable=no">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
/* Reset e base */
* {
  margin: 0;
  padding: 0;
  box-sizing: border-box;
}

body {
  font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
  background: #0a0b0f;
  color: #ffffff;
  min-height: 100vh;
  line-height: 1.5;
  padding-bottom: 80px;
}

/* Header */
.header {
  background: #111318;
  border-bottom: 1px solid #1a1d24;
  padding: 16px 20px;
  position: sticky;
  top: 0;
  z-index: 100;
  backdrop-filter: blur(20px);
}

.header-content {
  max-width: 1200px;
  margin: 0 auto;
  display: flex;
  justify-content: space-between;
  align-items: center;
}

.logo img {
  height: 40px;
  filter: brightness(1.1);
}

.user-actions {
  display: flex;
  align-items: center;
  gap: 12px;
}

.saldo {
  background: linear-gradient(135deg, #fbce00, #f4c430);
  color: #000;
  padding: 8px 16px;
  border-radius: 8px;
  font-weight: 700;
  font-size: 14px;
  box-shadow: 0 2px 8px rgba(251, 206, 0, 0.3);
}

.btn {
  padding: 10px 16px;
  border-radius: 8px;
  border: none;
  font-weight: 600;
  cursor: pointer;
  transition: all 0.2s ease;
  text-decoration: none;
  display: inline-flex;
  align-items: center;
  gap: 6px;
  font-size: 14px;
}

.btn-primary {
  background: linear-gradient(135deg, #fbce00, #f4c430);
  color: #000;
  box-shadow: 0 2px 8px rgba(251, 206, 0, 0.3);
}

.btn-primary:hover {
  transform: translateY(-1px);
  box-shadow: 0 4px 12px rgba(251, 206, 0, 0.4);
}

.btn-secondary {
  background: #1a1d24;
  color: #ffffff;
  border: 1px solid #2a2d34;
}

.btn-secondary:hover {
  background: #2a2d34;
}

/* Container principal */
.main-container {
  max-width: 1200px;
  margin: 0 auto;
  padding: 0 0px;
}

/* ================= ROleta e dimensões ================= */
:root {
  /* Altura calculada via JS (fallback inicial) */
  --reel-h: 240px;
  /* Aspecto do portão = altura/largura (ajustado via JS ao carregar a imagem) */
  --gate-aspect: 0.5;
  /* Escala relativa dos itens dentro da roleta */
  --item-scale: 0.66;
}

/* Roleta segue a proporção do portão (sem deformar) */
.reel-wrapper {
  position: relative;
  width: 100%;
  max-width: 100%;
  /* Largura/Altura = 1 / (altura/largura) */
  aspect-ratio: calc(1 / var(--gate-aspect));
  height: auto;
  min-height: 200px; /* fallback enquanto a imagem não carrega */
  margin: 0 auto 32px;
  overflow: hidden;
  border-radius: 0px;
  background: #0d1117;
  box-shadow: 0 8px 32px rgba(0, 0, 0, 0.4);
}

/* Borda animada suave SOMENTE no contorno */
.reel-wrapper::before {
  content: "";
  position: absolute;
  inset: 0;
  border-radius: 16px;            /* combina com o wrapper */
  padding: 1px; /* espessura */
  background-size: 300% 300%;
  animation: borderFlow 3s linear infinite;
  -webkit-mask: linear-gradient(#000 0 0) content-box, linear-gradient(#000 0 0);
  -webkit-mask-composite: xor; mask-composite: exclude;
  pointer-events: none;
  z-index: 30; /* Borda acima de tudo interno, exceto header etc */
}
@keyframes borderFlow {
  0% { background-position: 0% 50%; }
  100% { background-position: 200% 50%; }
}

/* Faixa de itens ocupa todo o wrapper */
.reel-strip {
  position: absolute;   /* passa a ocupar o wrapper todo */
  inset: 0;
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 0 16px;
  z-index: 10; /* abaixo da borda */
}

.reel-item {
  min-width: calc(var(--reel-h) * 0.45);
  height:     calc(var(--reel-h) * var(--item-scale));
  background: #1a1d24;
  border-radius: 8px;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  border: 1px solid #21262d;
  transition: all 0.2s ease;
  flex-shrink: 0;
}

.reel-item img {
  width:  calc(var(--reel-h) * 0.35);
  height: calc(var(--reel-h) * 0.35);
  object-fit: cover;
  border-radius: 4px;
  margin-bottom: 4px;
}

.reel-item-text {
  font-size: clamp(10px, 1.6vw, 16px);
  font-weight: 600;
  color: #fbce00;
}

.item-nao-ganhou-bg {
  background: #2d1b1b;
  border-color: #4a2c2c;
}

.item-nao-ganhou {
  font-size: clamp(10px, 1.6vw, 16px);
  font-weight: 600;
  color: #ff6b6b;
}

/* --- PORTÕES + SETA (corrigidos) --- */
/*
.gate-overlay {
  position: absolute;
  inset: 0;                   
  pointer-events: none;
  z-index: 50;                
  display: flex;
}*/
.gate-overlay {
    position: absolute;
    inset: 0;
    left: 50%;
    transform: translateX(-50%);
    min-height: 200px;
    width: 1550px;
    pointer-events: none;
    z-index: 29;
    display: flex;
    margin: 0;
}

/* .gate é a PRÓPRIA <img> (metade esquerda/direita) */
/*.gate {
  position: absolute;
  top: 0;
  bottom: 0;
  width: 50%;                 
  height: 100%;              
  display: block;
  background: transparent !important;
  transition: transform 1s ease-in-out, opacity .3s ease;
  object-fit: contain;        
  object-position: center;
  z-index: 2;                 
}*/
.gate {
    position: absolute;
    top: 0;
    bottom: 0;
    width: 50%;
    height: 100%;
    display: block;
    background: transparent !important;
    transition: transform 1s ease-in-out, opacity .3s ease;
    object-fit: contain;
    object-position: center;
    z-index: 2;
    object-fit: cover;
}

/* cada lado */
.gate-left  { left: 0;  transform: translateX(0); }
.gate-right { right: 0; transform: translateX(0); }

/* animação de abrir */
.gate-overlay.open .gate-left  { transform: translateX(-100%); }
.gate-overlay.open .gate-right { transform: translateX(100%); }

/* Seta atrás dos portões (e à frente da faixa, se desejar) */
/*.gate-arrow {
  position: absolute;
  top: 0;
  left: 50%;
  transform: translate(-50%, -15%);
  height: calc(var(--reel-h) * 0.22);
  width: auto;
  z-index: 1; 
  filter: drop-shadow(0 0 6px #fbce00) drop-shadow(0 0 14px #fbce00);
  animation: pulse 1.3s ease-in-out infinite;
}*/
.gate-arrow {
    position: absolute;
    top: -10px;
    left: 50%;
    transform: translate(-50%, -15%);
    height: calc(var(--reel-h) * 0.22);
    width: auto;
    z-index: 0;
    filter: drop-shadow(0 0 6px #fbce00) drop-shadow(0 0 14px #fbce00);
    animation: pulse 1.3s ease-in-out infinite;
}

@keyframes pulse {
  0%,100% { transform: translate(-50%, -15%) scale(1); }
  50%     { transform: translate(-50%, -15%) scale(1.06); }
}

/* Botão Jogar */
.btn-jogar {
  background: linear-gradient(135deg, #fbce00, #f4c430);
  color: #000;
  border: none;
  padding: 16px 32px;
  border-radius: 12px;
  font-size: 18px;
  font-weight: 800;
  cursor: pointer;
  transition: all 0.3s ease;
  box-shadow: 0 4px 16px rgba(251, 206, 0, 0.3);
  text-decoration: none;
  display: block;
  margin: 20px auto;
}

.btn-jogar:hover {
  transform: translateY(-2px);
  box-shadow: 0 8px 24px rgba(251, 206, 0, 0.4);
}

/* Mensagens */
.mensagem.erro {
  background: rgba(255, 107, 107, 0.1);
  border: 1px solid #ff6b6b;
  color: #ff6b6b;
}

/* Área da seção */
.premios-disponiveis-container{
  display:flex;
  flex-direction:column;
  gap:14px;
  margin:16px 10px;
}

/* Título da seção */
.premios-disponiveis-container h3{
  color:#dfe6ef;
  font-size:14px;
  font-weight:800;
  letter-spacing:.08em;
  margin:0;
  text-align:left;
}

/* Grid responsiva */
.premios-grid{
  display:grid;
  gap:16px;
  grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
}

/* Card */
.premio-item{
  background:#0d1117;
  border:1px solid rgba(255,255,255,.12);
  border-radius:12px;
  padding:14px;
  display:flex;
  flex-direction:column;
  align-items:center;
  justify-content:flex-start;
  min-height:220px;
  transition:transform .2s, box-shadow .3s, border-color .2s;
}
.premio-item:hover{
  transform:translateY(-2px);
  border-color:#fbce00;
  box-shadow:0 8px 24px rgba(0,0,0,.35);
}

/* Imagem do item */
.premio-item img{
  width:100px;
  height:100px;
  object-fit:contain;
  margin:6px auto 10px;
  display:block;
}

/* Nome do item (opcional) */
.premio-item .premio-nome{
  color:#dfe6ef;
  font-size:13px;
  font-weight:600;
  text-align:center;
  line-height:1.3;
  min-height:34px;
  margin-bottom:10px;
}

/* Preço como botão fixo no rodapé do card */
.premio-item .premio-preco{
  margin-top:auto;
  display:inline-block;
  background:#fbce00;
  color:#000;
  font-weight:800;
  font-size:14px;
  padding:10px 14px;
  border-radius:10px;
  text-align:center;
  width:100%;
  box-shadow:0 6px 16px rgba(251,206,0,.25);
}

/* Mobile: duas colunas para caber melhor */
@media (max-width:480px){
  .premios-grid{
    grid-template-columns: repeat(2, 1fr);
    gap:12px;
  }
  .premio-item{
    min-height:200px;
    padding:12px;
  }
  .premio-item img{
    width:88px; height:88px;
  }
}

/* Modal */
.modal-overlay {
  position: fixed;
  inset: 0;
  background: rgba(0, 0, 0, 0.8);
  backdrop-filter: blur(8px);
  z-index: 9999;
  justify-content: center;
  align-items: center;
  animation: fadeIn 0.3s ease;
}

.modal-overlay.show { display: flex; }

@keyframes fadeIn {
  from { opacity: 0; transform: scale(0.95); }
  to   { opacity: 1; transform: scale(1); }
}

.modal-content {
  background: #111318;
  border: 1px solid #1a1d24;
  border-radius: 16px;
  width: 100%;
  max-width: 420px;
  padding: 32px 24px;
  box-shadow: 0 20px 60px rgba(0, 0, 0, 0.6);
  text-align: center;
}

.modal-content h2 {
  color: #fbce00;
  font-size: 28px;
  font-weight: 800;
  margin-bottom: 16px;
}

.modal-prize-container {
  display: flex;
  justify-content: center;
  align-items: center;
  margin: 20px 0;
  text-align: center;
}

.modal-prize-container img {
  max-width: 120px;
  max-height: 120px;
  border-radius: 8px;
  margin: 0 auto;
  display: block;
  border: 2px solid #fbce00;
}

.modal-content p {
  color: #ffffff;
  font-size: 18px;
  font-weight: 600;
  margin-bottom: 24px;
}

.btn-continuar {
  background: linear-gradient(135deg, #fbce00, #f4c430);
  color: #000;
  border: none;
  padding: 14px 32px;
  border-radius: 8px;
  font-size: 16px;
  font-weight: 700;
  cursor: pointer;
  transition: all 0.2s ease;
  box-shadow: 0 2px 8px rgba(251, 206, 0, 0.3);
}

.btn-continuar:hover {
  transform: translateY(-1px);
  box-shadow: 0 4px 12px rgba(251, 206, 0, 0.4);
}

/* Bottom Navigation */
.bottom-nav {
  position: fixed;
  bottom: 0;
  left: 0;
  right: 0;
  background: #111318;
  border-top: 1px solid #1a1d24;
  display: flex;
  justify-content: space-around;
  padding: 12px 0;
  z-index: 1000;
  backdrop-filter: blur(20px);
}

.bottom-nav a {
  color: #8b949e;
  text-decoration: none;
  text-align: center;
  padding: 8px 12px;
  border-radius: 8px;
  transition: all 0.2s ease;
  font-size: 12px;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 4px;
}

.bottom-nav a:hover,
.bottom-nav a.active {
  color: #fbce00;
  background: rgba(251, 206, 0, 0.1);
}

.bottom-nav .deposit-btn {
  background: linear-gradient(135deg, #fbce00, #f4c430);
  color: #000 !important;
  font-weight: 700;
  border-radius: 12px;
  box-shadow: 0 2px 8px rgba(251, 206, 0, 0.3);
}

.bottom-nav .deposit-btn:hover {
  transform: translateY(-1px);
  box-shadow: 0 4px 12px rgba(251, 206, 0, 0.4);
}

/* ABRIR CAIXA */
.caixa-btn {
  background: #ffcc00;
  border: none;
  border-radius: 8px;
  padding: 12px 24px;
  color: #000;
  font-size: 15px;
  font-weight: bold;
  cursor: pointer;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 10px;
  transition: all 0.3s ease-in-out;
  text-decoration: none;
  box-shadow: 0 0 15px rgba(255, 204, 0, 0.8), 
              0 0 30px rgba(255, 204, 0, 0.6), 
              0 0 45px rgba(255, 204, 0, 0.4);
}

.caixa-btn:hover {
  background: #ffd633;
  box-shadow: 0 0 20px rgba(255, 230, 100, 1),
              0 0 40px rgba(255, 200, 50, 0.9),
              0 0 60px rgba(255, 200, 50, 0.7);
  transform: scale(1.05);
}

.caixa-preco {
  background: #fff3cc;
  padding: 6px 12px;
  border-radius: 6px;
  font-size: 14px;
  font-weight: bold;
  color: #000;
}

/* Cards de prêmios (lista) */
.premios-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
  gap: 15px;
}

.premio-card {
  background: #ffcc0008;
  border-radius: 5px;
  padding: 12px;
  text-align: center;
  transition: transform 0.2s, box-shadow 0.3s;
  display: flex;
  flex-direction: column;
  justify-content: space-between;
  border: 1px solid #ffffff1f; /* Bordinha fina branca */
}

.premio-card:hover {
  transform: translateY(-4px);
  box-shadow: 0 0 15px rgba(219 177 9 / 0%);
}

.premio-img img {
  max-height: 100px;
  object-fit: contain;
  margin: 0 auto;
}

.premio-info {
  margin-top: 10px;
}

.premio-nome {
  display: block;
  font-size: 13px;
  color: #ccc;
  margin-bottom: 5px;
}

.premio-label {
  display: inline-block;
  background: #f1c40f;
  color: #000000;
  padding: 6px 12px;
  border-radius: 6px;
  font-size: 14px;
  font-weight: bold;
}

/* Banner responsivo centralizado (não interfere no layout) */
.banner-top {
  display: flex;
  justify-content: center;
  padding: 0px 0;
}

.banner-top img {
  display: block;
  width: min(1200px, 100%);
  height: auto;
  border-radius: 12px;
  box-shadow: 0 10px 30px rgba(0,0,0,.35);
}
    </style>
</head>
<body>
<script src="https://cdn.jsdelivr.net/npm/canvas-confetti@1.5.1/dist/confetti.browser.min.js"></script>

<!-- Header -->
<div class="header">
  <div class="header-content">
    <div class="logo">
      <img src="images/<?= htmlspecialchars($logo) ?>" alt="Logo">
    </div>
    <div class="user-actions">
      <span class="saldo"><?= $moeda ?> <?= number_format((float)$usuario['saldo'], 2, ',', '.') ?></span>
      <a href="deposito.php" class="btn btn-primary">
        <i class="fas fa-plus"></i> Recarregar
      </a>
    </div>
  </div>
</div>

<!-- Banner acima do jogo -->
<div class="banner-top">
  <img src="https://i.ibb.co/bjqHZVjP/caixabanner.png" alt="Banner da roleta" />
</div>

<!-- Jogo organizado: faixa dentro do wrapper -->
<div class="game-container">
  <div class="reel-wrapper" id="reelWrapper" style="max-width: 1200px;">

    <!-- Faixa de itens DENTRO do wrapper -->
    <div class="reel-strip" id="reelStrip">
      <?php foreach ($segmentos_visuais as $seg): ?>
        <?php $ehNaoGanhou = ($seg === 'nao_ganhou'); ?>
        <div class="reel-item <?= $ehNaoGanhou ? 'item-nao-ganhou-bg' : '' ?>"
             data-chave="<?= htmlspecialchars($seg) ?>">
          <?php if ($ehNaoGanhou): ?>
            <span class="item-nao-ganhou">Não Ganhou</span>
          <?php else: ?>
            <?php
            // usa a imagem específica do prêmio configurada no banco
            $imgInfo = $premios_info[$seg]['imagem'] ?? '';
            $imagem_final_url = $imgInfo; // usa diretamente a imagem configurada
            ?>
            <?php if ($imagem_final_url): ?>
              <img src="<?= htmlspecialchars($imagem_final_url) ?>" alt="Prêmio">
            <?php endif; ?>
            <span class="reel-item-text">
              <?= $moeda ?> <?= number_format((float)($premios_info[$seg]['valor'] ?? 0), 2, ',', '.') ?>
            </span>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>

    <!-- Portões + seta ACIMA da faixa -->
    <div class="gate-overlay" id="gateOverlay">
      <img class="gate gate-left" id="gateLeft" src="https://i.ibb.co/6RYmBYcM/portaoL.png" alt="Portão esquerdo">
      <img class="gate gate-right" id="gateRight" src="https://i.ibb.co/Z65d18p0/portaoR.png" alt="Portão direito">
      <img class="gate-arrow" src="https://tesouropremiado.com/images/pin2.webp" alt="Seta neon">
    </div>

  </div>
</div>

<div id="mensagemResultado" class="mensagem"></div>

<?php if ($resultado === null): ?>
    <div style="text-align: center;">
        <form method="post" action="roleta.php?id=<?= htmlspecialchars($idJogo, ENT_QUOTES) ?>">
            <button class="caixa-btn" type="submit">
                <!-- Ícone SVG da chave -->
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="currentColor" viewBox="0 0 16 16">
                  <path d="M3 10a5 5 0 1 1 9.9 1H13v2h-2v2H9v-2H7v-2H5.9A5 5 0 0 1 3 10zM10 6a4 4 0 1 0-8 0 4 4 0 0 0 8 0z"/>
                </svg>
                Abrir Caixa 
                <span class="caixa-preco">R$ <?= number_format((float)$custo, 2, ',', '.') ?></span>
            </button>
        </form>
    </div>
<?php else: ?>
    <div style="text-align: center;">
        <a href="roleta.php?id=<?= htmlspecialchars($idJogo, ENT_QUOTES) ?>"
           class="caixa-btn"
           id="btnJogarNovamente">
            <!-- Ícone SVG da chave -->
            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="currentColor" viewBox="0 0 16 16">
              <path d="M3 10a5 5 0 1 1 9.9 1H13v2h-2v2H9v-2H7v-2H5.9A5 5 0 0 1 3 10zM10 6a4 4 0 1 0-8 0 4 4 0 0 0 8 0z"/>
            </svg>
            Jogar Novamente
        </a>
    </div>
<?php endif; ?>

<?php if (isset($_SESSION['erro_roleta'])): ?>
  <div class="mensagem erro"><?= $_SESSION['erro_roleta'] ?></div>
  <?php unset($_SESSION['erro_roleta']); ?>
<?php endif; ?>

<div class="premios-disponiveis-container" style="align-items: center;">
  <h3 class="titulo-premios">Conteúdo dessa Caixa:</h3>
  <div class="premios-grid" style="max-width: 1200px;">
      <?php
      // lista apenas prêmios com valor > 0
      $premios_para_exibir = array_filter($premios_info, fn($p) => ($p['valor'] ?? 0) > 0);
      // ordena por valor DESC
      uasort($premios_para_exibir, fn($a, $b) => ($b['valor'] <=> $a['valor']));
      foreach ($premios_para_exibir as $info):
          $valorPremio = (float) $info['valor'];
          $imagem_final_url = $info['imagem'] ?? '';
      ?>
          <div class="premio-card">
              <?php if ($imagem_final_url): ?>
                  <div class="premio-img">
                      <img src="<?= htmlspecialchars($imagem_final_url) ?>" alt="Prêmio de <?= number_format($valorPremio, 2, ',', '.') ?>">
                  </div>
              <?php endif; ?>
              <div class="premio-info">
                  <span class="premio-label">R$ <?= number_format($valorPremio, 2, ',', '.') ?></span>
              </div>
          </div>
      <?php endforeach; ?>
  </div>
</div>

<!-- Bloco de dados para o JS -->
<div id="gameData"
     data-segmentos='<?= json_encode($segmentos_visuais, JSON_UNESCAPED_UNICODE) ?>'
     data-moeda="<?= htmlspecialchars($moeda) ?>"
     <?php if ($resultado): ?>
     data-ganhou="<?= $resultado['ganhou'] ? '1' : '0' ?>"
     data-premio-real="<?= htmlspecialchars((string)$resultado['premio_real']) ?>"
     data-premio-chave="<?= htmlspecialchars((string)$resultado['premio_chave']) ?>"
     data-premio-imagem="<?= htmlspecialchars($premios_info[$resultado['premio_chave']]['imagem'] ?? '') ?>"
     <?php endif; ?>>
</div>

<!-- Logs seguros no console (fora do PHP) -->
<script>
console.log('=== DADOS DO PHP ===');
console.log('Segmentos:', JSON.parse(document.getElementById('gameData').dataset.segmentos || '[]'));
<?php if (!empty($premios_info)): ?>
console.log('Prêmios Info:', <?= json_encode($premios_info, JSON_UNESCAPED_UNICODE) ?>);
<?php endif; ?>
<?php if (!empty($resultado)): ?>
console.log('Resultado da sessão:', {
  ganhou: <?= $resultado['ganhou'] ? 'true' : 'false' ?>,
  premioReal: <?= json_encode($resultado['premio_real']) ?>,
  premioChave: <?= json_encode($resultado['premio_chave']) ?>,
  premioImagem: <?= json_encode($premios_info[$resultado['premio_chave']]['imagem'] ?? '') ?>
});
<?php endif; ?>
</script>

<!-- 🔧 Ajuste automático de proporção dos portões (evita distorção no mobile) -->
<script>
(function() {
  const reelWrapper = document.getElementById('reelWrapper');
  const left  = document.getElementById('gateLeft');
  const right = document.getElementById('gateRight');

  function setGateAspectFromImages() {
    if (!left || !right || !reelWrapper) return;

    const lh = left.naturalHeight, lw = left.naturalWidth;
    const rh = right.naturalHeight, rw = right.naturalWidth;

    const h = Math.max(lh || 0, rh || 0);
    const wTotal = (lw || 0) + (rw || 0);

    if (h > 0 && wTotal > 0) {
      const gateAspect = h / wTotal;     // altura / largura total
      reelWrapper.style.setProperty('--gate-aspect', gateAspect.toString());
    }
  }

  function onReadyToMeasure(img, cb) {
    if (img.complete && img.naturalWidth) cb();
    else img.addEventListener('load', cb, { once: true });
  }

  onReadyToMeasure(left,  setGateAspectFromImages);
  onReadyToMeasure(right, setGateAspectFromImages);

  // Recalcula em resize/rotação
  window.addEventListener('resize', () => {
    clearTimeout(window.__gateAspectTO);
    window.__gateAspectTO = setTimeout(setGateAspectFromImages, 100);
  });
})();
</script>

<!-- 🔧 Mantém --reel-h sincronizado com a altura real do wrapper -->
<script>
(function syncReelHeight() {
  const reelWrapper = document.getElementById('reelWrapper');
  if (!reelWrapper) return;

  const update = () => {
    const h = reelWrapper.clientHeight || 240;
    reelWrapper.style.setProperty('--reel-h', h + 'px');
  };

  window.addEventListener('load', update);
  window.addEventListener('resize', () => {
    clearTimeout(window.__reelHTo);
    window.__reelHTo = setTimeout(update, 100);
  });

  if (window.ResizeObserver) {
    const ro = new ResizeObserver(update);
    ro.observe(reelWrapper);
  }
  update();
})();
</script>

<script src="js/game.js?v=<?= time() ?>"></script>

<div id="resultadoModal" class="modal-overlay" style="display: none;">
    <div class="modal-content">
        <h2 id="modalTitle"></h2>
        <div class="modal-prize-container">
            <img id="modalImage" src="" alt="Prêmio">
        </div>
        <p id="modalText"></p>
        <button id="modalContinueBtn" class="btn-continuar">Continuar</button>
    </div>
</div>

<!-- Bottom Navigation -->
<div class="bottom-nav">
  <a href="index">
    <i class="fas fa-home"></i>
    <span>Início</span>
  </a>
  <a href="menu" class="active">
    <i class="fas fa-box"></i>
    <span>Pacotes</span>
  </a>
  <a href="deposito" class="deposit-btn">
    <i class="fas fa-credit-card"></i>
    <span>Depositar</span>
  </a>
  <a href="afiliado">
    <i class="fas fa-users"></i>
    <span>Afiliados</span>
  </a>
  <a href="perfil">
    <i class="fas fa-user"></i>
    <span>Perfil</span>
  </a>
</div>

</body>
</html>
