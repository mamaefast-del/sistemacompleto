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

// --- Lógica de Negócio ---
$custo = floatval($jogo['valor']);
$saldo = floatval($usuario['saldo']);

$chanceGanho = isset($usuario['percentual_ganho']) && $usuario['percentual_ganho'] !== null
    ? floatval($usuario['percentual_ganho'])
    : floatval($jogo['chance_ganho']);

$moeda = $jogo['moeda'] ?? 'R$';

// Distribuição de prêmios
$premios_json = $jogo['premios_json'] ?? '{"0": 100}';
$distribuicao_json = json_decode($premios_json, true);

$distribuicao = [];
foreach ($distribuicao_json as $valor => $chance) {
    $valorNum = floatval($valor);
    $chanceNum = floatval($chance);
    if ($chanceNum > 0) {
        $distribuicao[$valorNum] = $chanceNum;
    }
}
if (empty($distribuicao)) {
    $distribuicao[0] = 100;
}

function sortearPremioDistribuido($distribuicao) {
    $total = array_sum($distribuicao);
    if ($total <= 0) return 0.00;
    $rand = mt_rand(1, $total);
    $acumulado = 0;
    foreach ($distribuicao as $valor => $chance) {
        $acumulado += $chance;
        if ($rand <= $acumulado) {
            return $valor;
        }
    }
    return 0.00;
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

    if (mt_rand(1, 100) <= $chanceGanho) {
        $ganhou = true;
        $premio_real = sortearPremioDistribuido($distribuicao);
    } else {
        $ganhou = false;
        $premio_real = 0.00;
    }

    // --- Prêmio visual = real quando ganha, 0 quando não ganha ---
    $premio_visual = $ganhou ? $premio_real : 0.00;

    // --- Atualizações no Banco ---
    $novo_saldo = $saldo - $custo + $premio_real;
    $stmt = $pdo->prepare("UPDATE usuarios SET saldo = ? WHERE id = ?");
    $stmt->execute([$novo_saldo, $_SESSION['usuario_id']]);

    $stmt = $pdo->prepare("INSERT INTO historico_jogos (usuario_id, raspadinha_id, valor_apostado, valor_premiado) VALUES (?, ?, ?, ?)");
    $stmt->execute([$_SESSION['usuario_id'], $idJogo, $custo, $premio_real]);

    $_SESSION['roleta_resultado'] = [
        'ganhou' => $ganhou,
        'premio_real' => $premio_real,
        'premio_visual' => $premio_visual
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

$logo = 'logo.png';

// --- Montagem dos segmentos visuais (ordem garantida) ---
$segmentos_visuais = array_keys($distribuicao);

// mantém a ordem original do JSON
$segmentos_visuais = array_values($segmentos_visuais);

if (!in_array(0, $segmentos_visuais)) {
    array_unshift($segmentos_visuais, 0);
}

?>
<!doctype html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <title>Roleta da Sorte</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
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

/* Header do Usuário */
.user-header {
    background: #111318;
    border-bottom: 1px solid #1a1d24;
    padding: 16px 20px;
    position: sticky;
    top: 0;
    z-index: 100;
    backdrop-filter: blur(20px);
    display: flex;
    justify-content: space-between;
    align-items: center;
    max-width: 1200px;
    margin: 0 auto;
}

.user-header-logo img {
    height: 40px;
    filter: brightness(1.1);
}

.user-header-saldo {
    display: flex;
    flex-direction: column;
    align-items: flex-end;
}

.saldo-label {
    color: #8b949e;
    font-size: 12px;
    font-weight: 500;
}

.saldo-valor {
    background: linear-gradient(135deg, #fbce00, #f4c430);
    color: #000;
    padding: 8px 16px;
    border-radius: 8px;
    font-weight: 700;
    font-size: 16px;
    box-shadow: 0 2px 8px rgba(251, 206, 0, 0.3);
}

/* Container do Jogo */
.game-container {
    max-width: 600px;
    margin: 40px auto;
    padding: 0 20px;
    text-align: center;
}

/* Wrapper da Roleta */
.reel-wrapper {
    position: relative;
    background: #111318;
    border: 2px solid #1a1d24;
    border-radius: 16px;
    padding: 20px;
    margin-bottom: 30px;
    overflow: hidden;
    box-shadow: 0 8px 32px rgba(0, 0, 0, 0.4);
}

.reel-wrapper::before {
    content: '';
    position: absolute;
    inset: 0;
    padding: 2px;
    background: linear-gradient(135deg, #fbce00, #f4c430, #fbce00);
    border-radius: 16px;
    mask: linear-gradient(#fff 0 0) content-box, linear-gradient(#fff 0 0);
    mask-composite: xor;
    -webkit-mask-composite: xor;
    opacity: 0.3;
}

/* Ponteiro da Roleta */
.reel-pointer {
    position: absolute;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    width: 0;
    height: 0;
    border-left: 15px solid transparent;
    border-right: 15px solid transparent;
    border-top: 20px solid #fbce00;
    z-index: 10;
    filter: drop-shadow(0 2px 4px rgba(0, 0, 0, 0.3));
}

/* Strip da Roleta */
.reel-strip {
    display: flex;
    transition: transform 3s cubic-bezier(0.25, 0.46, 0.45, 0.94);
    will-change: transform;
}

/* Itens da Roleta */
.reel-item {
    min-width: 120px;
    height: 120px;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    background: #0d1117;
    border: 1px solid #21262d;
    border-radius: 12px;
    margin: 0 5px;
    padding: 10px;
    position: relative;
    transition: all 0.3s ease;
}

.reel-item:hover {
    border-color: #fbce00;
    transform: translateY(-2px);
    box-shadow: 0 4px 16px rgba(251, 206, 0, 0.2);
}

.reel-item img {
    width: 60px;
    height: 60px;
    object-fit: cover;
    border-radius: 8px;
    margin-bottom: 8px;
}

.reel-item-text {
    font-size: 10px;
    color: #8b949e;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.valor {
    font-size: 12px;
    color: #fbce00;
    font-weight: 700;
    margin-top: 4px;
}

/* Item "Não Ganhou" */
.item-nao-ganhou-bg {
    background: #1a1d24 !important;
    border-color: #2a2d34 !important;
}

.item-nao-ganhou {
    font-size: 12px;
    color: #8b949e;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

/* Botão Jogar */
.btn-jogar {
    background: linear-gradient(135deg, #fbce00, #f4c430);
    color: #000;
    border: none;
    padding: 16px 32px;
    border-radius: 12px;
    font-size: 18px;
    font-weight: 700;
    cursor: pointer;
    transition: all 0.3s ease;
    text-decoration: none;
    display: inline-block;
    box-shadow: 0 4px 16px rgba(251, 206, 0, 0.3);
    position: relative;
    overflow: hidden;
}

.btn-jogar::before {
    content: '';
    position: absolute;
    top: 0;
    left: -100%;
    width: 100%;
    height: 100%;
    background: linear-gradient(90deg, transparent, rgba(255,255,255,0.2), transparent);
    transition: left 0.5s ease;
}

.btn-jogar:hover::before {
    left: 100%;
}

.btn-jogar:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(251, 206, 0, 0.4);
}

.btn-jogar:active {
    transform: translateY(0);
}

/* Mensagens */
.mensagem {
    padding: 16px;
    border-radius: 12px;
    margin: 20px 0;
    font-weight: 600;
    text-align: center;
}

.mensagem.erro {
    background: rgba(220, 38, 127, 0.1);
    border: 1px solid rgba(220, 38, 127, 0.3);
    color: #ff6b6b;
}

.mensagem.sucesso {
    background: rgba(34, 197, 94, 0.1);
    border: 1px solid rgba(34, 197, 94, 0.3);
    color: #22c55e;
}

/* Prêmios Disponíveis */
.premios-disponiveis-container {
    max-width: 600px;
    margin: 40px auto;
    padding: 0 20px;
}

.premios-disponiveis-container h3 {
    color: #fbce00;
    font-size: 20px;
    font-weight: 700;
    text-align: center;
    margin-bottom: 20px;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
}

.premios-disponiveis-container h3::before {
    content: '🎁';
}

.premios-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
    gap: 16px;
}

.premio-item {
    background: #111318;
    border: 1px solid #1a1d24;
    border-radius: 12px;
    padding: 16px;
    text-align: center;
    transition: all 0.3s ease;
    position: relative;
    overflow: hidden;
}

.premio-item::before {
    content: '';
    position: absolute;
    top: 0;
    left: -100%;
    width: 100%;
    height: 2px;
    background: linear-gradient(90deg, transparent, #fbce00, transparent);
    animation: shimmer 3s infinite;
}

@keyframes shimmer {
    0% { left: -100%; }
    100% { left: 100%; }
}

.premio-item:hover {
    border-color: #fbce00;
    transform: translateY(-2px);
    box-shadow: 0 4px 16px rgba(251, 206, 0, 0.2);
}

.premio-item img {
    width: 80px;
    height: 80px;
    object-fit: cover;
    border-radius: 8px;
    margin-bottom: 12px;
    border: 2px solid #fbce00;
}

.premio-item-text {
    font-size: 14px;
    color: #fbce00;
    font-weight: 700;
}

/* Modal de Resultado */
.modal-overlay {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.8);
    backdrop-filter: blur(8px);
    display: flex;
    justify-content: center;
    align-items: center;
    z-index: 10000;
    animation: fadeIn 0.3s ease;
}

@keyframes fadeIn {
    from {
        opacity: 0;
        transform: scale(0.95);
    }
    to {
        opacity: 1;
        transform: scale(1);
    }
}

.modal-content {
    background: #111318;
    border: 1px solid #1a1d24;
    border-radius: 16px;
    padding: 40px 32px;
    text-align: center;
    max-width: 480px;
    width: 90%;
    box-shadow: 0 20px 60px rgba(0, 0, 0, 0.6);
    position: relative;
    z-index: 10001;
    animation: modalSlideIn 0.5s cubic-bezier(0.34, 1.56, 0.64, 1);
}

@keyframes modalSlideIn {
    from {
        opacity: 0;
        transform: scale(0.8) translateY(-50px);
    }
    to {
        opacity: 1;
        transform: scale(1) translateY(0);
    }
}

.modal-content::before {
    content: '';
    position: absolute;
    inset: 0;
    padding: 2px;
    background: linear-gradient(135deg, #fbce00, #f4c430, #fbce00);
    border-radius: 16px;
    mask: linear-gradient(#fff 0 0) content-box, linear-gradient(#fff 0 0);
    mask-composite: xor;
    -webkit-mask-composite: xor;
    opacity: 0.5;
    animation: borderPulse 2s ease-in-out infinite;
}

@keyframes borderPulse {
    0%, 100% {
        opacity: 0.5;
    }
    50% {
        opacity: 1;
    }
}

#modalTitle {
    color: #ffffff;
    font-size: 28px;
    font-weight: 800;
    margin-bottom: 24px;
    animation: titleBounce 0.8s cubic-bezier(0.68, -0.55, 0.265, 1.55) 0.3s both;
}

@keyframes titleBounce {
    from {
        opacity: 0;
        transform: translateY(-30px) scale(0.8);
    }
    to {
        opacity: 1;
        transform: translateY(0) scale(1);
    }
}

.modal-prize-container {
    margin: 32px auto;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    position: relative;
}

.modal-prize-container img {
    width: 160px;
    height: 160px;
    object-fit: cover;
    border-radius: 12px;
    border: 3px solid #fbce00;
    box-shadow: 0 4px 16px rgba(251, 206, 0, 0.3);
    animation: prizeReveal 1s cubic-bezier(0.68, -0.55, 0.265, 1.55) 0.5s both;
    position: relative;
    z-index: 2;
}

@keyframes prizeReveal {
    0% {
        opacity: 0;
        transform: scale(0) rotate(-180deg);
    }
    50% {
        transform: scale(1.2) rotate(-90deg);
    }
    100% {
        opacity: 1;
        transform: scale(1) rotate(0deg);
    }
}

/* Efeito de brilho atrás da imagem */
.modal-prize-container::before {
    content: '';
    position: absolute;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    width: 200px;
    height: 200px;
    background: radial-gradient(circle, rgba(251, 206, 0, 0.3) 0%, transparent 70%);
    border-radius: 50%;
    animation: glowPulse 2s ease-in-out infinite;
    z-index: 1;
}

@keyframes glowPulse {
    0%, 100% {
        transform: translate(-50%, -50%) scale(1);
        opacity: 0.3;
    }
    50% {
        transform: translate(-50%, -50%) scale(1.2);
        opacity: 0.6;
    }
}

/* Valor do prêmio */
.modal-prize-value {
    font-size: 32px;
    font-weight: 800;
    color: #fbce00;
    margin: 16px 0;
    text-shadow: 0 2px 8px rgba(251, 206, 0, 0.5);
    animation: valueCountUp 1.5s cubic-bezier(0.25, 0.46, 0.45, 0.94) 0.8s both;
}

@keyframes valueCountUp {
    from {
        opacity: 0;
        transform: translateY(20px) scale(0.8);
    }
    to {
        opacity: 1;
        transform: translateY(0) scale(1);
    }
}

#modalText {
    color: #8b949e;
    font-size: 16px;
    margin-bottom: 32px;
    line-height: 1.6;
    animation: textFadeIn 0.8s ease 1s both;
}

@keyframes textFadeIn {
    from {
        opacity: 0;
        transform: translateY(10px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.btn-continuar {
    background: linear-gradient(135deg, #fbce00, #f4c430);
    color: #000;
    border: none;
    padding: 16px 32px;
    border-radius: 12px;
    font-size: 18px;
    font-weight: 700;
    cursor: pointer;
    transition: all 0.3s ease;
    box-shadow: 0 4px 16px rgba(251, 206, 0, 0.3);
    position: relative;
    z-index: 10002;
    animation: buttonSlideUp 0.6s ease 1.2s both;
    overflow: hidden;
}

@keyframes buttonSlideUp {
    from {
        opacity: 0;
        transform: translateY(30px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.btn-continuar::before {
    content: '';
    position: absolute;
    top: 0;
    left: -100%;
    width: 100%;
    height: 100%;
    background: linear-gradient(90deg, transparent, rgba(255,255,255,0.3), transparent);
    transition: left 0.6s ease;
}

.btn-continuar:hover::before {
    left: 100%;
}

.btn-continuar:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(251, 206, 0, 0.4);
}

.btn-continuar:active {
    transform: translateY(0);
}

/* Confetes animados */
.confetti {
    position: absolute;
    width: 10px;
    height: 10px;
    background: #fbce00;
    animation: confettiFall 3s linear infinite;
}

.confetti:nth-child(odd) {
    background: #f4c430;
    animation-delay: -0.5s;
}

.confetti:nth-child(3n) {
    background: #ff6b6b;
    animation-delay: -1s;
}

.confetti:nth-child(4n) {
    background: #4ecdc4;
    animation-delay: -1.5s;
}

@keyframes confettiFall {
    0% {
        transform: translateY(-100vh) rotate(0deg);
        opacity: 1;
    }
    100% {
        transform: translateY(100vh) rotate(720deg);
        opacity: 0;
    }
}

/* Footer */
.footer {
    position: fixed;
    bottom: 0;
    left: 0;
    right: 0;
    background: #111318;
    border-top: 1px solid #1a1d24;
    display: flex;
    justify-content: space-around;
    padding: 12px 0;
    z-index: 100;
    backdrop-filter: blur(20px);
}

.footer a {
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

.footer a:hover,
.footer a.active {
    color: #fbce00;
    background: rgba(251, 206, 0, 0.1);
}

.footer .deposito-btn {
    background: linear-gradient(135deg, #fbce00, #f4c430);
    color: #000 !important;
    font-weight: 700;
    border-radius: 12px;
    box-shadow: 0 2px 8px rgba(251, 206, 0, 0.3);
}

.footer .deposito-btn:hover {
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(251, 206, 0, 0.4);
}

.footer i {
    font-size: 16px;
}

/* Responsivo */
@media (max-width: 768px) {
    .game-container {
        padding: 0 16px;
    }
    
    .user-header {
        padding: 16px;
    }
    
    .reel-item {
        min-width: 100px;
        height: 100px;
    }
    
    .reel-item img {
        width: 50px;
        height: 50px;
    }
    
    .btn-jogar {
        padding: 14px 24px;
        font-size: 16px;
    }
    
    .premios-grid {
        grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
    }
    
    .premio-item img {
        width: 60px;
        height: 60px;
    }
}

@media (max-width: 480px) {
    .reel-item {
        min-width: 80px;
        height: 80px;
    }
    
    .reel-item img {
        width: 40px;
        height: 40px;
    }
    
    .reel-item-text {
        font-size: 9px;
    }
    
    .valor {
        font-size: 10px;
    }
    
    .btn-jogar {
        padding: 12px 20px;
        font-size: 14px;
    }
}

/* Animações especiais */
.reel-wrapper.spinning {
    box-shadow: 0 8px 32px rgba(251, 206, 0, 0.4);
}

.reel-wrapper.spinning::before {
    opacity: 0.8;
    animation: borderGlow 2s ease-in-out infinite;
}

@keyframes borderGlow {
    0%, 100% {
        opacity: 0.3;
    }
    50% {
        opacity: 1;
    }
}

/* Efeitos de vitória */
.vitoria-effect {
    animation: celebration 1s ease-in-out;
}

@keyframes celebration {
    0%, 100% {
        transform: scale(1);
    }
    25% {
        transform: scale(1.05);
    }
    50% {
        transform: scale(1.1);
    }
    75% {
        transform: scale(1.05);
    }
}

/* Loading states */
.btn-jogar:disabled {
    opacity: 0.6;
    cursor: not-allowed;
    transform: none !important;
}

.btn-jogar.loading::after {
    content: '';
    position: absolute;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    width: 20px;
    height: 20px;
    border: 2px solid transparent;
    border-top: 2px solid #000;
    border-radius: 50%;
    animation: spin 1s linear infinite;
}

@keyframes spin {
    0% { transform: translate(-50%, -50%) rotate(0deg); }
    100% { transform: translate(-50%, -50%) rotate(360deg); }
}
    </style>
</head>
<body>
<script src="https://cdn.jsdelivr.net/npm/canvas-confetti@1.5.1/dist/confetti.browser.min.js"></script>

<div class="user-header">
    <div class="user-header-logo">
        <img src="images/<?= $logo ?>" alt="Logo">
    </div>
    <div class="user-header-saldo">
        <span class="saldo-label">Seu Saldo</span>
        <span class="saldo-valor"><?= $moeda ?> <?= number_format($usuario['saldo'], 2, ',', '.') ?></span>
    </div>
</div>

<div class="game-container">
    <div class="reel-wrapper">
        <div class="reel-pointer"></div>
        <div class="reel-strip" id="reelStrip">
            <?php foreach ($segmentos_visuais as $valorSegmento): ?>
                <div class="reel-item <?= ($valorSegmento == 0 ? 'item-nao-ganhou-bg' : '') ?>" 
                     data-valor="<?= $valorSegmento ?>">

                    <?php if ($valorSegmento == 0): ?>
                        <span class="item-nao-ganhou">Não Ganhou</span>
                    <?php else: ?>
                        <?php
                        $caminho_base = "images/caixa/{$valorSegmento}";
                        $caminho_webp = $caminho_base . ".webp";
                        $caminho_png  = $caminho_base . ".png";
                        $imagem_final_url = null;

                        if (file_exists($caminho_webp)) {
                            $imagem_final_url = $caminho_webp;
                        } elseif (file_exists($caminho_png)) {
                            $imagem_final_url = $caminho_png;
                        }
                        ?>
                        <?php if ($imagem_final_url): ?>
                            <img src="<?= $imagem_final_url ?>" alt="Prêmio de <?= $valorSegmento ?>">
                        <?php endif; ?>
                        <span class="reel-item-text"><?= $valorSegmento ?> Reais</span>
                        <span class="valor"><?= $moeda ?> <?= number_format($valorSegmento, 2, ',', '.') ?></span>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <div id="mensagemResultado" class="mensagem"></div>

    <?php if ($resultado === null): ?>
        <form method="post" action="roleta.php?id=<?= $idJogo ?>">
            <button class="btn-jogar">Abrir pacote <?= $moeda ?><?= number_format($custo, 2, ',', '.') ?></button>
        </form>
    <?php else: ?>
        <a href="roleta.php?id=<?= $idJogo ?>" class="btn-jogar" style="text-decoration: none; display: none;" id="btnJogarNovamente">Jogar Novamente</a>
    <?php endif; ?>

    <?php if (isset($_SESSION['erro_roleta'])): ?>
        <div class="mensagem erro"><?= $_SESSION['erro_roleta'] ?></div>
        <?php unset($_SESSION['erro_roleta']); ?>
    <?php endif; ?>
</div>

<div class="premios-disponiveis-container">
    <h3>Prêmios Disponíveis</h3>
    <div class="premios-grid">
        <?php
        $premios_para_exibir = array_filter(array_keys($distribuicao), fn($valor) => $valor > 0);
        rsort($premios_para_exibir);
        foreach ($premios_para_exibir as $valorPremio):
            $caminho_base = "images/caixa/{$valorPremio}";
            $caminho_webp = $caminho_base . ".webp";
            $caminho_png = $caminho_base . ".png";
            $imagem_final_url = null;
            if (file_exists($caminho_webp)) {
                $imagem_final_url = $caminho_webp;
            } elseif (file_exists($caminho_png)) {
                $imagem_final_url = $caminho_png;
            }
            ?>
            <div class="premio-item">
                <?php if ($imagem_final_url): ?>
                    <img src="<?= $imagem_final_url ?>" alt="Prêmio de <?= $valorPremio ?>">
                <?php endif; ?>
                <span class="premio-item-text"><?= $moeda ?> <?= number_format($valorPremio, 2, ',', '.') ?></span>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<div id="gameData"
     data-segmentos='<?= json_encode($segmentos_visuais) ?>'
     <?php if ($resultado): ?>
     data-premio-real="<?= $resultado['premio_real'] ?>"
     data-premio-visual="<?= $resultado['premio_visual'] ?>"
     data-moeda="<?= $moeda ?>"
     <?php endif; ?>>
</div>

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

<div class="footer">
    <a href="index" class="active">
        <div><i class="fas fa-house"></i><br></div>
    </a>
    <a href="menu">
        <div><i class="fas fa-box"></i><br></div>
    </a>
    <a href="deposito" class="deposito-btn">
        <div><i class="fas fa-credit-card"></i><br></div>
    </a>
    <a href="afiliado">
        <div><i class="fas fa-user-group"></i><br></div>
    </a>
    <a href="perfil">
        <div><i class="fas fa-user-plus"></i><br></div>
    </a>
</div>

</body>
</html>