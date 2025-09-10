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
        echo 'E-mail ou senha incorretos';
        exit;
    }

    // Verificar senha
    if (!password_verify($senha, $usuario['senha'])) {
        echo 'E-mail ou senha incorretos';
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