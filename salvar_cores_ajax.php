<?php
session_start();
require 'db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['admin']) || $_SESSION['admin'] !== true) {
    echo json_encode(['success' => false, 'error' => 'Acesso negado']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Método não permitido']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    echo json_encode(['success' => false, 'error' => 'Dados inválidos']);
    exit;
}

try {
    $cor_primaria = $input['primaria'] ?? '#fbce00';
    $cor_secundaria = $input['secundaria'] ?? '#f4c430';
    $cor_azul = $input['azul'] ?? '#007fdb';
    $cor_verde = $input['verde'] ?? '#00e880';
    $cor_fundo = $input['fundo'] ?? '#0a0b0f';
    $cor_painel = $input['painel'] ?? '#111318';
    
    // Validar formato hexadecimal
    $cores = [$cor_primaria, $cor_secundaria, $cor_azul, $cor_verde, $cor_fundo, $cor_painel];
    foreach ($cores as $cor) {
        if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $cor)) {
            throw new Exception('Formato de cor inválido: ' . $cor);
        }
    }
    
    // Verificar se registro existe
    $stmt = $pdo->query("SELECT COUNT(*) FROM cores_site");
    $exists = $stmt->fetchColumn() > 0;
    
    if ($exists) {
        $stmt = $pdo->prepare("UPDATE cores_site SET cor_primaria = ?, cor_secundaria = ?, cor_azul = ?, cor_verde = ?, cor_fundo = ?, cor_painel = ? WHERE id = 1");
        $stmt->execute([$cor_primaria, $cor_secundaria, $cor_azul, $cor_verde, $cor_fundo, $cor_painel]);
    } else {
        $stmt = $pdo->prepare("INSERT INTO cores_site (cor_primaria, cor_secundaria, cor_azul, cor_verde, cor_fundo, cor_painel) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$cor_primaria, $cor_secundaria, $cor_azul, $cor_verde, $cor_fundo, $cor_painel]);
    }
    
    // Gerar CSS dinâmico
    gerarCSSPersonalizado($cor_primaria, $cor_secundaria, $cor_azul, $cor_verde, $cor_fundo, $cor_painel);
    
    echo json_encode(['success' => true, 'message' => 'Cores atualizadas com sucesso!']);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

function gerarCSSPersonalizado($primaria, $secundaria, $azul, $verde, $fundo, $painel) {
    // Converter hex para RGB
    function hexToRgb($hex) {
        $hex = ltrim($hex, '#');
        return [
            'r' => hexdec(substr($hex, 0, 2)),
            'g' => hexdec(substr($hex, 2, 2)),
            'b' => hexdec(substr($hex, 4, 2))
        ];
    }
    
    $primaryRgb = hexToRgb($primaria);
    $primaryRgbString = $primaryRgb['r'] . ', ' . $primaryRgb['g'] . ', ' . $primaryRgb['b'];
    
    $secondaryRgb = hexToRgb($secundaria);
    $secondaryRgbString = $secondaryRgb['r'] . ', ' . $secondaryRgb['g'] . ', ' . $secondaryRgb['b'];
    
    $blueRgb = hexToRgb($azul);
    $blueRgbString = $blueRgb['r'] . ', ' . $blueRgb['g'] . ', ' . $blueRgb['b'];
    
    $greenRgb = hexToRgb($verde);
    $greenRgbString = $greenRgb['r'] . ', ' . $greenRgb['g'] . ', ' . $greenRgb['b'];
    
    $css = "/* Cores personalizadas - Gerado automaticamente em " . date('Y-m-d H:i:s') . " */
:root {
    --primary-gold: {$primaria};
    --secondary-gold: {$secundaria};
    --primary-blue: {$azul};
    --primary-green: {$verde};
    --bg-dark: {$fundo};
    --bg-panel: {$painel};
    --primary-gold-rgb: {$primaryRgbString};
    --secondary-gold-rgb: {$secondaryRgbString};
    --primary-blue-rgb: {$blueRgbString};
    --primary-green-rgb: {$greenRgbString};
}

/* Aplicação das cores personalizadas */
.btn-primary, 
.btn-depositar, 
.saldo, 
.generate-btn,
.btn-jogar,
.btn-copiar,
.quick-amount:hover,
.btn-continuar,
.login-button,
.btn-full,
.step-number,
.package-price,
.hot-badge {
    background: linear-gradient(135deg, var(--primary-gold), var(--secondary-gold)) !important;
    color: #000 !important;
}

.footer a.active, 
.tab.active, 
i.active,
.bottom-nav a.active,
.bottom-nav a:hover,
.how-it-works h2 {
    color: var(--primary-blue) !important;
}

.footer a.deposito-btn,
.footer a.deposit-btn,
.bottom-nav .deposit-btn {
    background: linear-gradient(135deg, var(--primary-gold), var(--secondary-gold)) !important;
    color: #000 !important;
}

/* Elementos específicos que precisam da cor primária */
.winner-card::before,
.package-card::before,
.card::before,
.step-card::before {
    background: linear-gradient(90deg, transparent, var(--primary-gold), transparent) !important;
}

.winner-product-image,
.premio-item img {
    border-color: var(--primary-gold) !important;
}

.package-price {
    color: var(--primary-gold) !important;
    border-color: var(--primary-gold) !important;
}

/* Sombras e efeitos com RGB */
.btn-primary:hover,
.btn-depositar:hover,
.generate-btn:hover,
.btn-jogar:hover,
.login-button:hover,
.step-number:hover {
    box-shadow: 0 6px 20px rgba(var(--primary-gold-rgb), 0.4) !important;
}

.bottom-nav .deposit-btn:hover {
    box-shadow: 0 4px 12px rgba(var(--primary-gold-rgb), 0.4) !important;
}

/* Bordas e contornos */
.reel-wrapper::before,
.modal-content::before,
.login-container::before {
    background: linear-gradient(135deg, var(--primary-gold), var(--secondary-gold), var(--primary-gold)) !important;
}

/* Animações e efeitos especiais */
.winner-card:hover,
.package-card:hover {
    border-color: var(--primary-gold) !important;
    box-shadow: 0 4px 16px rgba(var(--primary-gold-rgb), 0.2) !important;
}

.btn-verde, 
.status-aprovado,
.btn-success {
    background: var(--primary-green) !important;
    color: #000 !important;
}

body {
    background: var(--bg-dark) !important;
}

.header, 
.card, 
.container,
.deposit-form,
.history-section,
.winners-section,
.packages-section .package-card,
.how-it-works,
.game-container,
.modal-content,
.login-container {
    background: var(--bg-panel) !important;
}

.text-primary,
.title,
.welcome-title,
.stat-value,
.transaction-value,
.winner-prize,
.package-price,
.step-number,
.modal-content h2,
.form-title h1,
.section-header h2,
.winners-header h2,
.valor-label,
.codigo-afiliado,
.highlight,
.highlight2 {
    color: var(--primary-gold) !important;
}

.border-primary,
.package-card::before,
.card::before {
    border-color: var(--primary-gold) !important;
}

/* Inputs e formulários */
.form-input:focus,
.input-group input:focus,
.search-input:focus,
.select-input:focus,
.input-box:focus,
.color-text:focus,
.affiliate-link input:focus {
    border-color: var(--primary-gold) !important;
    box-shadow: 0 0 0 3px rgba(var(--primary-gold-rgb), 0.1) !important;
}

/* Status e badges */
.status-pendente {
    color: var(--primary-gold) !important;
    background: rgba(var(--primary-gold-rgb), 0.15) !important;
}

/* Efeitos hover */
.card:hover,
.stat-card:hover,
.package-card:hover,
.winner-card:hover,
.transaction-item:hover {
    border-color: var(--primary-gold) !important;
    box-shadow: 0 8px 24px rgba(var(--primary-gold-rgb), 0.1) !important;
}

/* Elementos específicos do jogo */
.reel-item-text,
.premio-item-text {
    color: var(--primary-gold) !important;
}

/* Navegação e tabs */
.nav-item.active,
.logo,
.tab.active::after {
    color: var(--primary-gold) !important;
    background: linear-gradient(135deg, var(--primary-gold), var(--secondary-gold)) !important;
}

/* Modais e overlays */
.modal-prize-container img {
    border-color: var(--primary-gold) !important;
}

/* Efeitos de animação */
.gate-arrow {
    filter: drop-shadow(0 0 6px var(--primary-gold)) drop-shadow(0 0 14px var(--primary-gold)) !important;
}

/* Quick amounts e botões especiais */
.quick-amount:hover,
.quick-amounts button:hover {
    border-color: var(--primary-gold) !important;
    background: rgba(var(--primary-gold-rgb), 0.1) !important;
    color: var(--primary-gold) !important;
}

/* Elementos específicos */
.highlight,
.highlight2 {
    color: var(--primary-gold) !important;
}

.valor-label,
.codigo-afiliado {
    color: var(--primary-gold) !important;
    background: rgba(var(--primary-gold-rgb), 0.15) !important;
}

/* Navegação */
.nav-item.active,
.logo {
    color: var(--primary-gold) !important;
}

/* Modais e overlays */
.modal-overlay {
    backdrop-filter: blur(8px);
}

/* Animações e efeitos */
@keyframes shimmer {
    0% { left: -100%; }
    100% { left: 100%; }
}

.card::before,
.package-card::before {
    background: linear-gradient(90deg, transparent, var(--primary-gold), transparent) !important;
}
";
    
    file_put_contents(__DIR__ . '/css/cores-dinamicas.css', $css);
}
?>