<?php
session_start();
require 'db.php';

header('Content-Type: text/plain; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo 'Método não permitido';
    exit;
}

$nome = trim($_POST['nome'] ?? '');
$email = trim($_POST['email'] ?? '');
$senha = $_POST['senha'] ?? '';

// Validações básicas
if (empty($nome) || empty($email) || empty($senha)) {
    echo 'Preencha todos os campos';
    exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo 'E-mail inválido';
    exit;
}

if (strlen($senha) < 6) {
    echo 'A senha deve ter pelo menos 6 caracteres';
    exit;
}

if (strlen($nome) < 2) {
    echo 'Nome deve ter pelo menos 2 caracteres';
    exit;
}

// Sanitizar nome
$nome = preg_replace('/[^a-zA-ZÀ-ÿ\s]/', '', $nome);
if (empty($nome)) {
    echo 'Nome contém caracteres inválidos';
    exit;
}

try {
    // Verificar se e-mail já existe
    $stmt = $pdo->prepare("SELECT id FROM usuarios WHERE email = ?");
    $stmt->execute([$email]);
    
    if ($stmt->fetch()) {
        echo 'E-mail já cadastrado';
        exit;
    }

    // Verificar código de convite se fornecido
    $codigoConvite = null;
    if (isset($_POST['codigo_convite']) && !empty(trim($_POST['codigo_convite']))) {
        $codigoConvite = trim($_POST['codigo_convite']);
        
        // Validar se o código existe (opcional - você pode implementar uma tabela de códigos)
        // Por agora, apenas armazenamos o código
    }

    // Hash da senha
    $senhaHash = password_hash($senha, PASSWORD_DEFAULT);

    // Inserir usuário
    $stmt = $pdo->prepare("
        INSERT INTO usuarios (nome, email, senha, codigo_convite, saldo) 
        VALUES (?, ?, ?, ?, ?)
    ");
    
    $saldoInicial = 0.00;
    // Você pode dar um bônus inicial aqui se quiser
    // $saldoInicial = 10.00; // Bônus de R$ 10 para novos usuários
    
    $stmt->execute([$nome, $email, $senhaHash, $codigoConvite, $saldoInicial]);
    
    $usuarioId = $pdo->lastInsertId();

    // Fazer login automaticamente
    $_SESSION['usuario_id'] = $usuarioId;
    $_SESSION['usuario_nome'] = $nome;
    $_SESSION['usuario_email'] = $email;
    
    // Regenerar ID da sessão para segurança
    session_regenerate_id(true);
    
    // Processar atribuição de afiliado
    try {
        require_once 'includes/affiliate_tracker.php';
        $affiliateAttributed = attributeUserToAffiliate($pdo, $usuarioId);
        if ($affiliateAttributed) {
            error_log("Usuário $usuarioId atribuído a afiliado com sucesso");
        }
    } catch (Exception $e) {
        error_log("Erro ao processar atribuição de afiliado: " . $e->getMessage());
        // Não falhar o cadastro por erro de afiliado
    }
    
    echo 'success';
    
} catch (PDOException $e) {
    error_log("Erro no cadastro: " . $e->getMessage());
    
    // Verificar se é erro de e-mail duplicado
    if ($e->getCode() == 23000) {
        echo 'E-mail já cadastrado';
    } else {
        echo 'Erro interno do servidor. Tente novamente.';
    }
} catch (Exception $e) {
    error_log("Erro no cadastro: " . $e->getMessage());
    echo 'Erro interno do servidor. Tente novamente.';
}
?>