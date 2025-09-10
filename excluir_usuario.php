<?php
session_start();
require 'db.php';

if (!isset($_SESSION['admin']) || $_SESSION['admin'] !== true) {
    header('Location: login.php');
    exit;
}

if (isset($_GET['id']) && is_numeric($_GET['id'])) {
    $id = (int) $_GET['id'];
    
    try {
        // Verificar se o usuário tem transações aprovadas
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM transacoes_pix WHERE usuario_id = ? AND status = 'aprovado'");
        $stmt->execute([$id]);
        $has_transactions = $stmt->fetchColumn() > 0;
        
        if ($has_transactions) {
            $_SESSION['error'] = 'Não é possível excluir usuário com transações aprovadas!';
        } else {
            // Excluir dados relacionados primeiro
            $tabelas = [
                'rollover',
                'transacoes_pix',
                'saques',
                'historico_jogos',
                'comissoes',
                'historico_afiliados'
            ];

            foreach ($tabelas as $tabela) {
                try {
                    $stmt = $pdo->prepare("DELETE FROM $tabela WHERE usuario_id = ? OR afiliado_id = ?");
                    $stmt->execute([$id, $id]);
                } catch (PDOException $e) {
                    // Tabela pode não existir ou coluna pode não existir
                    error_log("Erro ao excluir de $tabela: " . $e->getMessage());
                }
            }
            
            // Excluir usuário
            $stmt = $pdo->prepare("DELETE FROM usuarios WHERE id = ?");
            $stmt->execute([$id]);
            
            $_SESSION['success'] = 'Usuário excluído com sucesso!';
        }
    } catch (PDOException $e) {
        $_SESSION['error'] = 'Erro ao excluir usuário: ' . $e->getMessage();
    }
}

header('Location: admin_usuarios.php');
exit;
?>