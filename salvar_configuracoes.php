<?php
session_start();

if (!isset($_SESSION['admin']) || $_SESSION['admin'] !== true) {
    header('Location: login.php');
    exit;
}

require 'db.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Salvar configurações gerais
        if (isset($_POST['min_deposito'])) {
            $stmt = $pdo->prepare("UPDATE configuracoes SET 
                min_deposito = ?, 
                max_deposito = ?, 
                min_saque = ?, 
                max_saque = ?, 
                bonus_deposito = ?, 
                valor_comissao = ?
                WHERE id = 1");
            
            $stmt->execute([
                floatval($_POST['min_deposito']),
                floatval($_POST['max_deposito']),
                floatval($_POST['min_saque']),
                floatval($_POST['max_saque']),
                floatval($_POST['bonus_deposito']),
                floatval($_POST['valor_comissao'])
            ]);
            
            $_SESSION['sucesso'] = true;
        }
        
        // Salvar configurações de jogos
        foreach ($_POST as $key => $value) {
            if (strpos($key, 'chance_ganho_') === 0) {
                $jogo_id = str_replace('chance_ganho_', '', $key);
                $chance = floatval($value);
                
                $stmt = $pdo->prepare("UPDATE raspadinhas_config SET chance_ganho = ? WHERE id = ?");
                $stmt->execute([$chance, $jogo_id]);
            }
        }
        
        $_SESSION['sucesso'] = true;
        
    } catch (PDOException $e) {
        $_SESSION['erro'] = 'Erro ao salvar configurações: ' . $e->getMessage();
    }
}

header('Location: configuracoes_admin.php');
exit;
?>