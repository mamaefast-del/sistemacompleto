<?php
session_start();
require 'db.php';

if (!isset($_SESSION['usuario_id'])) {
    header('Location: index.php');
    exit;
}

$usuario_id = $_SESSION['usuario_id'];

// Buscar dados do usuário
$stmt = $pdo->prepare("SELECT * FROM usuarios WHERE id = ?");
$stmt->execute([$usuario_id]);
$usuario = $stmt->fetch();

echo "<!DOCTYPE html>";
echo "<html><head><title>Debug Dados de Afiliado</title>";
echo "<style>
body{font-family:Arial,sans-serif;margin:20px;background:#f5f5f5;} 
.card{background:white;padding:20px;margin:20px 0;border-radius:8px;box-shadow:0 2px 8px rgba(0,0,0,0.1);} 
h2{color:#333;border-bottom:2px solid #fbce00;padding-bottom:10px;} 
table{width:100%;border-collapse:collapse;margin:10px 0;} 
th,td{padding:8px;text-align:left;border-bottom:1px solid #ddd;} 
th{background:#f8f9fa;}
.success{color:#22c55e;font-weight:bold;} 
.error{color:#ef4444;font-weight:bold;} 
.info{color:#3b82f6;font-weight:bold;}
</style>";
echo "</head><body>";

echo "<h1>🔍 Debug - Dados do Afiliado</h1>";

// Informações do usuário
echo "<div class='card'>";
echo "<h2>👤 Dados do Usuário</h2>";
echo "<table>";
echo "<tr><th>Campo</th><th>Valor</th></tr>";
echo "<tr><td>ID</td><td>{$usuario['id']}</td></tr>";
echo "<tr><td>Nome</td><td>{$usuario['nome']}</td></tr>";
echo "<tr><td>Email</td><td>{$usuario['email']}</td></tr>";
echo "<tr><td>Código Afiliado</td><td>" . ($usuario['codigo_afiliado'] ?: 'N/A') . "</td></tr>";
echo "<tr><td>Afiliado Ativo</td><td>" . ($usuario['afiliado_ativo'] ? 'Sim' : 'Não') . "</td></tr>";
echo "<tr><td>Porcentagem</td><td>" . number_format($usuario['porcentagem_afiliado'], 2) . "%</td></tr>";
echo "<tr><td>Comissão Pendente</td><td>R$ " . number_format($usuario['comissao'], 2, ',', '.') . "</td></tr>";
echo "<tr><td>Saldo Comissão</td><td>R$ " . number_format($usuario['saldo_comissao'], 2, ',', '.') . "</td></tr>";
echo "</table>";
echo "</div>";

if (!empty($usuario['codigo_afiliado'])) {
    // Verificar indicados pelo método antigo
    echo "<div class='card'>";
    echo "<h2>📊 Indicados (Método Antigo)</h2>";
    
    $stmt = $pdo->prepare("SELECT id, nome, email, data_cadastro FROM usuarios WHERE codigo_afiliado_usado = ? ORDER BY data_cadastro DESC");
    $stmt->execute([$usuario['codigo_afiliado']]);
    $indicados_antigo = $stmt->fetchAll();
    
    if ($indicados_antigo) {
        echo "<table>";
        echo "<tr><th>ID</th><th>Nome</th><th>Email</th><th>Data Cadastro</th></tr>";
        foreach ($indicados_antigo as $ind) {
            echo "<tr><td>{$ind['id']}</td><td>{$ind['nome']}</td><td>{$ind['email']}</td><td>" . date('d/m/Y H:i', strtotime($ind['data_cadastro'])) . "</td></tr>";
        }
        echo "</table>";
    } else {
        echo "<p class='info'>Nenhum indicado encontrado pelo método antigo</p>";
    }
    echo "</div>";
    
    // Verificar indicados pelo método novo
    echo "<div class='card'>";
    echo "<h2>📊 Indicados (Método Novo)</h2>";
    
    $stmt = $pdo->prepare("SELECT id, nome, email, data_cadastro FROM usuarios WHERE attributed_affiliate_id = ? ORDER BY data_cadastro DESC");
    $stmt->execute([$usuario_id]);
    $indicados_novo = $stmt->fetchAll();
    
    if ($indicados_novo) {
        echo "<table>";
        echo "<tr><th>ID</th><th>Nome</th><th>Email</th><th>Data Cadastro</th></tr>";
        foreach ($indicados_novo as $ind) {
            echo "<tr><td>{$ind['id']}</td><td>{$ind['nome']}</td><td>{$ind['email']}</td><td>" . date('d/m/Y H:i', strtotime($ind['data_cadastro'])) . "</td></tr>";
        }
        echo "</table>";
    } else {
        echo "<p class='info'>Nenhum indicado encontrado pelo método novo</p>";
    }
    echo "</div>";
    
    // Verificar cliques
    echo "<div class='card'>";
    echo "<h2>🖱️ Cliques de Afiliado</h2>";
    
    $stmt = $pdo->prepare("SELECT * FROM affiliate_clicks WHERE affiliate_id = ? OR ref_code = ? ORDER BY created_at DESC LIMIT 10");
    $stmt->execute([$usuario_id, $usuario['codigo_afiliado']]);
    $clicks = $stmt->fetchAll();
    
    if ($clicks) {
        echo "<table>";
        echo "<tr><th>ID</th><th>Ref Code</th><th>URL</th><th>UTM Source</th><th>IP</th><th>Converteu</th><th>Data</th></tr>";
        foreach ($clicks as $click) {
            $converteu = $click['converteu'] ? 'Sim' : 'Não';
            echo "<tr><td>{$click['id']}</td><td>{$click['ref_code']}</td><td>" . substr($click['url'], 0, 50) . "...</td><td>{$click['utm_source']}</td><td>{$click['ip_address']}</td><td>$converteu</td><td>" . date('d/m/Y H:i', strtotime($click['created_at'])) . "</td></tr>";
        }
        echo "</table>";
    } else {
        echo "<p class='info'>Nenhum clique registrado</p>";
    }
    echo "</div>";
    
    // Verificar atribuições
    echo "<div class='card'>";
    echo "<h2>🎯 Atribuições</h2>";
    
    $stmt = $pdo->prepare("SELECT aa.*, u.nome as user_name, u.email as user_email FROM affiliate_attributions aa LEFT JOIN usuarios u ON aa.user_id = u.id WHERE aa.affiliate_id = ? ORDER BY aa.created_at DESC LIMIT 10");
    $stmt->execute([$usuario_id]);
    $attributions = $stmt->fetchAll();
    
    if ($attributions) {
        echo "<table>";
        echo "<tr><th>ID</th><th>Usuário</th><th>Email</th><th>Ref Code</th><th>Modelo</th><th>Data</th></tr>";
        foreach ($attributions as $attr) {
            echo "<tr><td>{$attr['id']}</td><td>{$attr['user_name']}</td><td>{$attr['user_email']}</td><td>{$attr['ref_code']}</td><td>{$attr['attribution_model']}</td><td>" . date('d/m/Y H:i', strtotime($attr['created_at'])) . "</td></tr>";
        }
        echo "</table>";
    } else {
        echo "<p class='info'>Nenhuma atribuição registrada</p>";
    }
    echo "</div>";
    
    // Verificar comissões
    echo "<div class='card'>";
    echo "<h2>💰 Comissões</h2>";
    
    $stmt = $pdo->prepare("SELECT c.*, u.nome as indicado_nome FROM comissoes c LEFT JOIN usuarios u ON c.usuario_indicado_id = u.id WHERE c.afiliado_id = ? ORDER BY c.data_criacao DESC LIMIT 10");
    $stmt->execute([$usuario_id]);
    $comissoes = $stmt->fetchAll();
    
    if ($comissoes) {
        echo "<table>";
        echo "<tr><th>ID</th><th>Indicado</th><th>Valor Transação</th><th>Comissão</th><th>%</th><th>Status</th><th>Data</th></tr>";
        foreach ($comissoes as $com) {
            echo "<tr><td>{$com['id']}</td><td>{$com['indicado_nome']}</td><td>R$ " . number_format($com['valor_transacao'], 2, ',', '.') . "</td><td>R$ " . number_format($com['valor_comissao'], 2, ',', '.') . "</td><td>{$com['porcentagem_aplicada']}%</td><td>{$com['status']}</td><td>" . date('d/m/Y H:i', strtotime($com['data_criacao'])) . "</td></tr>";
        }
        echo "</table>";
    } else {
        echo "<p class='info'>Nenhuma comissão registrada</p>";
    }
    echo "</div>";
    
    // Verificar transações com afiliado
    echo "<div class='card'>";
    echo "<h2>💳 Transações com Afiliado</h2>";
    
    $stmt = $pdo->prepare("SELECT * FROM transacoes_pix WHERE affiliate_id = ? OR ref_code = ? ORDER BY criado_em DESC LIMIT 10");
    $stmt->execute([$usuario_id, $usuario['codigo_afiliado']]);
    $transacoes = $stmt->fetchAll();
    
    if ($transacoes) {
        echo "<table>";
        echo "<tr><th>ID</th><th>Usuário ID</th><th>Valor</th><th>Status</th><th>Affiliate ID</th><th>Ref Code</th><th>1º Depósito</th><th>Data</th></tr>";
        foreach ($transacoes as $trans) {
            $primeiro = $trans['is_first_deposit'] ? 'Sim' : 'Não';
            echo "<tr><td>{$trans['id']}</td><td>{$trans['usuario_id']}</td><td>R$ " . number_format($trans['valor'], 2, ',', '.') . "</td><td>{$trans['status']}</td><td>{$trans['affiliate_id']}</td><td>{$trans['ref_code']}</td><td>$primeiro</td><td>" . date('d/m/Y H:i', strtotime($trans['criado_em'])) . "</td></tr>";
        }
        echo "</table>";
    } else {
        echo "<p class='info'>Nenhuma transação com afiliado registrada</p>";
    }
    echo "</div>";
    
    // Botões de ação
    echo "<div class='card'>";
    echo "<h2>🛠️ Ações de Debug</h2>";
    echo "<p><a href='?create_test_data=1' style='background:#fbce00;color:#000;padding:10px 20px;text-decoration:none;border-radius:6px;font-weight:bold;'>Criar Dados de Teste</a></p>";
    echo "<p><a href='test_affiliate_system.php' style='background:#22c55e;color:#fff;padding:10px 20px;text-decoration:none;border-radius:6px;font-weight:bold;margin-left:10px;'>Teste Completo do Sistema</a></p>";
    echo "<p><a href='afiliado.php' style='background:#3b82f6;color:#fff;padding:10px 20px;text-decoration:none;border-radius:6px;font-weight:bold;margin-left:10px;'>Voltar ao Painel</a></p>";
    echo "</div>";
}

echo "</body></html>";
?>