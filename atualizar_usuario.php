<?php
session_start();
require 'db.php';

if (!isset($_SESSION['admin']) || $_SESSION['admin'] !== true) {
  header('Location: login.php');
  exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = intval($_POST['id']);
    $saldo = floatval($_POST['saldo']);
    $percentual_ganho = $_POST['percentual_ganho'] ?? null;
    $usar_global = isset($_POST['usar_global']);
    $comissao = floatval($_POST['comissao'] ?? 0);
    $conta_demo = isset($_POST['conta_demo']) ? 1 : 0;
    $ativo = isset($_POST['ativo']) ? 1 : 0;

    if ($usar_global) {
        $percentual_ganho = null;
    }

    try {
        $stmt = $pdo->prepare("
            UPDATE usuarios SET 
                saldo = ?, 
                percentual_ganho = ?, 
                comissao = ?, 
                conta_demo = ?,
                ativo = ?
            WHERE id = ?
        ");
        $stmt->execute([$saldo, $percentual_ganho, $comissao, $conta_demo, $ativo, $id]);
        
        $_SESSION['success'] = 'Usuário atualizado com sucesso!';
        
    } catch (PDOException $e) {
        $_SESSION['error'] = 'Erro ao atualizar usuário: ' . $e->getMessage();
    }
}

header('Location: admin_usuarios.php');
exit;
?>