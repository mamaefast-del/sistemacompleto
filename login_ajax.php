<?php
session_start();
require 'db.php';

header('Content-Type: text/plain; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo 'Método não permitido';
    exit;
}

$email = trim($_POST['email'] ?? '');
$senha = $_POST['senha'] ?? '';

// Validações básicas
if (empty($email) || empty($senha)) {
    echo 'Preencha todos os campos';
    exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo 'E-mail inválido';
    exit;
}

try {
    // Buscar usuário no banco
    $stmt = $pdo->prepare("SELECT id, nome, email, senha, ativo FROM usuarios WHERE email = ? AND ativo = 1");
    $stmt->execute([$email]);
    $usuario = $stmt->fetch();

    if (!$usuario) {
        // Verificar se o usuário existe mas está inativo
        $stmt = $pdo->prepare("SELECT id, ativo FROM usuarios WHERE email = ?");
        $stmt->execute([$email]);
        $usuarioInativo = $stmt->fetch();
        
        if ($usuarioInativo && !$usuarioInativo['ativo']) {
            echo 'Conta desativada. Entre em contato com o suporte';
        } else {
            echo 'Este usuário não existe. Verifique o e-mail digitado';
        }
        exit;
    }

    // Verificar senha
    if (!password_verify($senha, $usuario['senha'])) {
        echo 'Senha incorreta. Tente novamente';
        exit;
    }

    // Login realizado com sucesso
    $_SESSION['usuario_id'] = $usuario['id'];
    $_SESSION['usuario_nome'] = $usuario['nome'];
    $_SESSION['usuario_email'] = $usuario['email'];
    
    // Regenerar ID da sessão para segurança
    session_regenerate_id(true);
    
    echo 'success';
    
} catch (PDOException $e) {
    error_log("Erro no login: " . $e->getMessage());
    echo 'Erro interno do servidor. Tente novamente.';
} catch (Exception $e) {
    error_log("Erro no login: " . $e->getMessage());
    echo 'Erro interno do servidor. Tente novamente.';
}
?>