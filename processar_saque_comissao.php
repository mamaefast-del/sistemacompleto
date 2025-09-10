<?php
header('Content-Type: application/json');
session_start();
require 'db.php';

if (!isset($_SESSION['usuario_id'])) {
    echo json_encode(['status' => 'erro', 'mensagem' => 'Sessão expirada. Faça login novamente.']);
    exit;
}

$usuario_id = $_SESSION['usuario_id'];
$valor = floatval($_POST['valor'] ?? 0);
$chave_pix = trim($_POST['chave_pix'] ?? '');
$tipo_chave = strtolower(trim($_POST['tipo_chave'] ?? 'email'));

// Validar tipo de chave
$tipos_validos = ['cpf', 'cnpj', 'email', 'telefone', 'aleatoria'];
if (!in_array($tipo_chave, $tipos_validos)) {
    echo json_encode(['status' => 'erro', 'mensagem' => '❌ Tipo de chave Pix inválido.']);
    exit;
}

// Buscar limites de saque de comissão
$config = $pdo->query("SELECT min_saque_comissao, max_saque_comissao FROM configuracoes LIMIT 1")->fetch();
$min_saque = floatval($config['min_saque_comissao'] ?? 10);
$max_saque = floatval($config['max_saque_comissao'] ?? 1000);

// Buscar comissão do usuário
$stmt = $pdo->prepare("SELECT comissao, codigo_afiliado FROM usuarios WHERE id = ?");
$stmt->execute([$usuario_id]);
$usuario = $stmt->fetch();
$comissao_disponivel = floatval($usuario['comissao'] ?? 0);

// Verificar se é afiliado
if (empty($usuario['codigo_afiliado'])) {
    echo json_encode(['status' => 'erro', 'mensagem' => '❌ Você não é um afiliado.']);
    exit;
}

// Validações
if ($valor < $min_saque || $valor > $max_saque) {
    echo json_encode(['status' => 'erro', 'mensagem' => "❌ Valor deve estar entre R$ " . number_format($min_saque, 2, ',', '.') . " e R$ " . number_format($max_saque, 2, ',', '.') . "."]);
    exit;
}

if ($valor > $comissao_disponivel) {
    echo json_encode(['status' => 'erro', 'mensagem' => '❌ Comissão insuficiente. Disponível: R$ ' . number_format($comissao_disponivel, 2, ',', '.')]);
    exit;
}

if (empty($chave_pix)) {
    echo json_encode(['status' => 'erro', 'mensagem' => '❌ Chave Pix é obrigatória.']);
    exit;
}

try {
    // Verificar se não há saque pendente
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM saques WHERE usuario_id = ? AND tipo = 'comissao' AND status = 'pendente'");
    $stmt->execute([$usuario_id]);
    $saque_pendente = $stmt->fetchColumn();
    
    if ($saque_pendente > 0) {
        echo json_encode(['status' => 'erro', 'mensagem' => '❌ Você já possui um saque de comissão pendente.']);
        exit;
    }
    
    // Descontar da comissão
    $stmt = $pdo->prepare("UPDATE usuarios SET comissao = comissao - ? WHERE id = ?");
    $stmt->execute([$valor, $usuario_id]);
    
    // Registrar o saque
    $stmt = $pdo->prepare("
        INSERT INTO saques (usuario_id, valor, chave_pix, tipo_chave, status, data, tipo) 
        VALUES (?, ?, ?, ?, 'pendente', NOW(), 'comissao')
    ");
    $stmt->execute([$usuario_id, $valor, $chave_pix, $tipo_chave]);
    
    echo json_encode([
        'status' => 'sucesso',
        'mensagem' => '✅ Saque de comissão solicitado com sucesso! Aguardando aprovação.'
    ]);
    
} catch (PDOException $e) {
    // Reverter desconto em caso de erro
    $stmt = $pdo->prepare("UPDATE usuarios SET comissao = comissao + ? WHERE id = ?");
    $stmt->execute([$valor, $usuario_id]);
    
    error_log("Erro ao processar saque de comissão: " . $e->getMessage());
    echo json_encode(['status' => 'erro', 'mensagem' => '❌ Erro interno. Tente novamente.']);
}
?>