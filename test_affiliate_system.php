<?php
/**
 * Script de Teste do Sistema de Afiliados
 * Simula todo o fluxo: clique → cadastro → depósito → comissão
 */

require 'db.php';
require_once 'includes/affiliate_tracker.php';

echo "<!DOCTYPE html>";
echo "<html><head><title>Teste do Sistema de Afiliados</title>";
echo "<style>
body{font-family:Arial,sans-serif;margin:40px;background:#f5f5f5;} 
.success{color:#22c55e;font-weight:bold;} 
.error{color:#ef4444;font-weight:bold;} 
.info{color:#3b82f6;font-weight:bold;} 
.warning{color:#f59e0b;font-weight:bold;}
.card{background:white;padding:20px;margin:20px 0;border-radius:8px;box-shadow:0 2px 8px rgba(0,0,0,0.1);} 
h2{color:#333;border-bottom:2px solid #fbce00;padding-bottom:10px;} 
.step{background:#f8f9fa;padding:15px;margin:10px 0;border-left:4px solid #fbce00;border-radius:4px;}
.result{background:#e8f5e8;padding:10px;margin:10px 0;border-radius:4px;border:1px solid #22c55e;}
.test-btn{background:#fbce00;color:#000;padding:10px 20px;border:none;border-radius:6px;cursor:pointer;font-weight:bold;margin:5px;}
.test-btn:hover{background:#f4c430;}
</style>";
echo "</head><body>";

echo "<h1>🧪 Teste Completo do Sistema de Afiliados</h1>";

// Verificar se as tabelas foram criadas
echo "<div class='card'>";
echo "<h2>📋 1. Verificação das Tabelas</h2>";

$tables = [
    'affiliate_clicks' => 'Rastreamento de cliques',
    'affiliate_attributions' => 'Atribuições de usuários',
    'payment_callbacks' => 'Logs de callbacks',
    'affiliate_config' => 'Configurações'
];

foreach ($tables as $table => $desc) {
    try {
        $count = $pdo->query("SELECT COUNT(*) FROM `$table`")->fetchColumn();
        echo "<div class='result'>✅ <strong>$table:</strong> $desc ($count registros)</div>";
    } catch (PDOException $e) {
        echo "<div class='error'>❌ <strong>$table:</strong> " . $e->getMessage() . "</div>";
    }
}
echo "</div>";

// Teste 1: Simular clique com UTMs
echo "<div class='card'>";
echo "<h2>🖱️ 2. Teste de Clique com UTMs</h2>";

if (isset($_GET['test_click'])) {
    // Simular parâmetros de afiliado
    $_GET['ref'] = 'AFIL001';
    $_GET['utm_source'] = 'facebook';
    $_GET['utm_medium'] = 'social';
    $_GET['utm_campaign'] = 'promo2025';
    
    session_start();
    
    try {
        $tracker = new AffiliateTracker($pdo);
        $clickId = $tracker->trackClick();
        
        if ($clickId) {
            echo "<div class='result'>✅ <strong>Clique rastreado com sucesso!</strong><br>";
            echo "Click ID: $clickId<br>";
            echo "Ref Code: AFIL001<br>";
            echo "UTM Source: facebook<br>";
            echo "UTM Medium: social<br>";
            echo "UTM Campaign: promo2025</div>";
            
            // Verificar se foi salvo no banco
            $stmt = $pdo->prepare("SELECT * FROM affiliate_clicks WHERE id = ?");
            $stmt->execute([$clickId]);
            $click = $stmt->fetch();
            
            if ($click) {
                echo "<div class='step'><strong>Dados salvos no banco:</strong><br>";
                echo "Affiliate ID: {$click['affiliate_id']}<br>";
                echo "UTMs: {$click['utms_json']}<br>";
                echo "IP: {$click['ip_address']}<br>";
                echo "Session: {$click['session_id']}</div>";
            }
        } else {
            echo "<div class='error'>❌ Falha ao rastrear clique</div>";
        }
    } catch (Exception $e) {
        echo "<div class='error'>❌ Erro: " . $e->getMessage() . "</div>";
    }
} else {
    echo "<div class='step'>Clique no botão abaixo para simular um clique de afiliado:</div>";
    echo "<button class='test-btn' onclick=\"window.location.href='?test_click=1'\">🖱️ Simular Clique</button>";
}
echo "</div>";

// Teste 2: Simular cadastro
echo "<div class='card'>";
echo "<h2>👤 3. Teste de Cadastro com Atribuição</h2>";

if (isset($_GET['test_signup'])) {
    // Simular dados de afiliado na sessão
    session_start();
    $_SESSION['affiliate_ref'] = 'AFIL001';
    $_SESSION['affiliate_click_id'] = 1;
    $_SESSION['affiliate_utms'] = [
        'utm_source' => 'facebook',
        'utm_medium' => 'social',
        'utm_campaign' => 'promo2025'
    ];
    
    try {
        // Criar usuário de teste
        $testEmail = 'teste_' . time() . '@exemplo.com';
        $testName = 'Usuário Teste ' . date('H:i:s');
        
        $stmt = $pdo->prepare("
            INSERT INTO usuarios (nome, email, senha, saldo) 
            VALUES (?, ?, ?, 0)
        ");
        $stmt->execute([$testName, $testEmail, password_hash('123456', PASSWORD_DEFAULT)]);
        $userId = $pdo->lastInsertId();
        
        // Processar atribuição
        $tracker = new AffiliateTracker($pdo);
        $attributed = $tracker->attributeUser($userId);
        
        if ($attributed) {
            echo "<div class='result'>✅ <strong>Usuário atribuído com sucesso!</strong><br>";
            echo "User ID: $userId<br>";
            echo "Email: $testEmail<br>";
            echo "Atribuído ao afiliado: AFIL001</div>";
            
            // Verificar atribuição no banco
            $stmt = $pdo->prepare("
                SELECT aa.*, u.nome as affiliate_name 
                FROM affiliate_attributions aa
                LEFT JOIN usuarios u ON aa.affiliate_id = u.id
                WHERE aa.user_id = ?
            ");
            $stmt->execute([$userId]);
            $attribution = $stmt->fetch();
            
            if ($attribution) {
                echo "<div class='step'><strong>Atribuição salva:</strong><br>";
                echo "Afiliado: {$attribution['affiliate_name']}<br>";
                echo "Ref Code: {$attribution['ref_code']}<br>";
                echo "Modelo: {$attribution['attribution_model']}<br>";
                echo "UTM Source: {$attribution['utm_source']}</div>";
            }
        } else {
            echo "<div class='error'>❌ Falha na atribuição</div>";
        }
    } catch (Exception $e) {
        echo "<div class='error'>❌ Erro: " . $e->getMessage() . "</div>";
    }
} else {
    echo "<div class='step'>Clique no botão abaixo para simular um cadastro com atribuição:</div>";
    echo "<button class='test-btn' onclick=\"window.location.href='?test_signup=1'\">👤 Simular Cadastro</button>";
}
echo "</div>";

// Teste 3: Simular webhook de depósito
echo "<div class='card'>";
echo "<h2>💰 4. Teste de Webhook de Depósito</h2>";

if (isset($_GET['test_deposit'])) {
    try {
        // Buscar usuário com atribuição
        $stmt = $pdo->query("
            SELECT u.id, u.nome, u.email, aa.affiliate_id, aa.ref_code
            FROM usuarios u
            JOIN affiliate_attributions aa ON u.id = aa.user_id
            WHERE u.email LIKE 'teste_%'
            ORDER BY u.id DESC
            LIMIT 1
        ");
        $testUser = $stmt->fetch();
        
        if (!$testUser) {
            echo "<div class='warning'>⚠️ Execute primeiro o teste de cadastro</div>";
        } else {
            // Criar transação de teste
            $transactionId = 'TEST_TXN_' . time();
            $externalId = 'TEST_EXT_' . time();
            $amount = 50.00;
            
            $stmt = $pdo->prepare("
                INSERT INTO transacoes_pix (usuario_id, valor, external_id, transaction_id, status, criado_em) 
                VALUES (?, ?, ?, ?, 'pendente', NOW())
            ");
            $stmt->execute([$testUser['id'], $amount, $externalId, $transactionId]);
            
            // Processar primeiro depósito
            $tracker = new AffiliateTracker($pdo);
            $processed = $tracker->processFirstDeposit($testUser['id'], $transactionId, $amount);
            
            if ($processed) {
                echo "<div class='result'>✅ <strong>Primeiro depósito processado!</strong><br>";
                echo "User ID: {$testUser['id']}<br>";
                echo "Transaction ID: $transactionId<br>";
                echo "Valor: R$ " . number_format($amount, 2, ',', '.') . "<br>";
                echo "Afiliado: {$testUser['ref_code']}</div>";
                
                // Verificar comissão gerada
                $stmt = $pdo->prepare("
                    SELECT * FROM comissoes 
                    WHERE transaction_id = ? AND afiliado_id = ?
                ");
                $stmt->execute([$transactionId, $testUser['affiliate_id']]);
                $commission = $stmt->fetch();
                
                if ($commission) {
                    echo "<div class='step'><strong>Comissão gerada:</strong><br>";
                    echo "Valor da comissão: R$ " . number_format($commission['valor_comissao'], 2, ',', '.') . "<br>";
                    echo "Percentual: {$commission['porcentagem_aplicada']}%<br>";
                    echo "Nível: {$commission['nivel']}<br>";
                    echo "Status: {$commission['status']}</div>";
                }
            } else {
                echo "<div class='error'>❌ Falha ao processar depósito</div>";
            }
        }
    } catch (Exception $e) {
        echo "<div class='error'>❌ Erro: " . $e->getMessage() . "</div>";
    }
} else {
    echo "<div class='step'>Clique no botão abaixo para simular um webhook de depósito:</div>";
    echo "<button class='test-btn' onclick=\"window.location.href='?test_deposit=1'\">💰 Simular Depósito</button>";
}
echo "</div>";

// Teste 4: Verificar idempotência
echo "<div class='card'>";
echo "<h2>🔄 5. Teste de Idempotência</h2>";

if (isset($_GET['test_idempotency'])) {
    try {
        // Buscar última transação de teste
        $stmt = $pdo->query("
            SELECT transaction_id, usuario_id, valor 
            FROM transacoes_pix 
            WHERE transaction_id LIKE 'TEST_TXN_%' 
            ORDER BY id DESC 
            LIMIT 1
        ");
        $lastTransaction = $stmt->fetch();
        
        if ($lastTransaction) {
            $transactionId = $lastTransaction['transaction_id'];
            $userId = $lastTransaction['usuario_id'];
            $amount = $lastTransaction['valor'];
            
            // Contar comissões antes
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM comissoes WHERE transaction_id = ?");
            $stmt->execute([$transactionId]);
            $commissionsBefore = $stmt->fetchColumn();
            
            // Tentar processar novamente
            $tracker = new AffiliateTracker($pdo);
            $processed = $tracker->processFirstDeposit($userId, $transactionId, $amount);
            
            // Contar comissões depois
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM comissoes WHERE transaction_id = ?");
            $stmt->execute([$transactionId]);
            $commissionsAfter = $stmt->fetchColumn();
            
            if ($commissionsBefore === $commissionsAfter) {
                echo "<div class='result'>✅ <strong>Idempotência funcionando!</strong><br>";
                echo "Comissões antes: $commissionsBefore<br>";
                echo "Comissões depois: $commissionsAfter<br>";
                echo "Nenhuma duplicação detectada</div>";
            } else {
                echo "<div class='error'>❌ <strong>Falha na idempotência!</strong><br>";
                echo "Comissões antes: $commissionsBefore<br>";
                echo "Comissões depois: $commissionsAfter<br>";
                echo "Duplicação detectada!</div>";
            }
        } else {
            echo "<div class='warning'>⚠️ Execute primeiro o teste de depósito</div>";
        }
    } catch (Exception $e) {
        echo "<div class='error'>❌ Erro: " . $e->getMessage() . "</div>";
    }
} else {
    echo "<div class='step'>Clique no botão abaixo para testar a idempotência:</div>";
    echo "<button class='test-btn' onclick=\"window.location.href='?test_idempotency=1'\">🔄 Testar Idempotência</button>";
}
echo "</div>";

// Resumo dos dados
echo "<div class='card'>";
echo "<h2>📊 6. Resumo dos Dados de Teste</h2>";

try {
    // Estatísticas gerais
    $stats = [
        'Cliques registrados' => $pdo->query("SELECT COUNT(*) FROM affiliate_clicks")->fetchColumn(),
        'Atribuições criadas' => $pdo->query("SELECT COUNT(*) FROM affiliate_attributions")->fetchColumn(),
        'Callbacks registrados' => $pdo->query("SELECT COUNT(*) FROM payment_callbacks")->fetchColumn(),
        'Comissões geradas' => $pdo->query("SELECT COUNT(*) FROM comissoes WHERE transaction_id LIKE 'TEST_%'")->fetchColumn(),
        'Usuários de teste' => $pdo->query("SELECT COUNT(*) FROM usuarios WHERE email LIKE 'teste_%'")->fetchColumn()
    ];
    
    echo "<table style='width:100%;border-collapse:collapse;'>";
    echo "<tr style='background:#f8f9fa;'><th style='padding:10px;text-align:left;'>Métrica</th><th style='padding:10px;text-align:left;'>Valor</th></tr>";
    
    foreach ($stats as $metric => $value) {
        echo "<tr><td style='padding:8px;border-bottom:1px solid #ddd;'>$metric</td><td style='padding:8px;border-bottom:1px solid #ddd;'><strong>$value</strong></td></tr>";
    }
    echo "</table>";
    
    // Últimos cliques
    echo "<h3>🖱️ Últimos Cliques:</h3>";
    $stmt = $pdo->query("
        SELECT ac.*, u.nome as affiliate_name 
        FROM affiliate_clicks ac
        LEFT JOIN usuarios u ON ac.affiliate_id = u.id
        ORDER BY ac.created_at DESC 
        LIMIT 5
    ");
    $clicks = $stmt->fetchAll();
    
    if ($clicks) {
        echo "<table style='width:100%;border-collapse:collapse;margin:10px 0;'>";
        echo "<tr style='background:#f8f9fa;'><th style='padding:8px;'>Afiliado</th><th style='padding:8px;'>Ref Code</th><th style='padding:8px;'>UTMs</th><th style='padding:8px;'>Converteu</th><th style='padding:8px;'>Data</th></tr>";
        
        foreach ($clicks as $click) {
            $utms = json_decode($click['utms_json'], true);
            $utmString = $utms ? implode(', ', array_map(fn($k,$v) => "$k:$v", array_keys($utms), $utms)) : 'N/A';
            $converteu = $click['converteu'] ? '✅ Sim' : '❌ Não';
            
            echo "<tr>";
            echo "<td style='padding:6px;border-bottom:1px solid #ddd;'>{$click['affiliate_name']}</td>";
            echo "<td style='padding:6px;border-bottom:1px solid #ddd;'>{$click['ref_code']}</td>";
            echo "<td style='padding:6px;border-bottom:1px solid #ddd;'>$utmString</td>";
            echo "<td style='padding:6px;border-bottom:1px solid #ddd;'>$converteu</td>";
            echo "<td style='padding:6px;border-bottom:1px solid #ddd;'>" . date('d/m/Y H:i', strtotime($click['created_at'])) . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    }
    
    // Últimas atribuições
    echo "<h3>🎯 Últimas Atribuições:</h3>";
    $stmt = $pdo->query("
        SELECT aa.*, u1.nome as user_name, u1.email, u2.nome as affiliate_name 
        FROM affiliate_attributions aa
        LEFT JOIN usuarios u1 ON aa.user_id = u1.id
        LEFT JOIN usuarios u2 ON aa.affiliate_id = u2.id
        ORDER BY aa.created_at DESC 
        LIMIT 5
    ");
    $attributions = $stmt->fetchAll();
    
    if ($attributions) {
        echo "<table style='width:100%;border-collapse:collapse;margin:10px 0;'>";
        echo "<tr style='background:#f8f9fa;'><th style='padding:8px;'>Usuário</th><th style='padding:8px;'>Afiliado</th><th style='padding:8px;'>Ref Code</th><th style='padding:8px;'>Modelo</th><th style='padding:8px;'>Data</th></tr>";
        
        foreach ($attributions as $attr) {
            echo "<tr>";
            echo "<td style='padding:6px;border-bottom:1px solid #ddd;'>{$attr['user_name']}<br><small>{$attr['email']}</small></td>";
            echo "<td style='padding:6px;border-bottom:1px solid #ddd;'>{$attr['affiliate_name']}</td>";
            echo "<td style='padding:6px;border-bottom:1px solid #ddd;'>{$attr['ref_code']}</td>";
            echo "<td style='padding:6px;border-bottom:1px solid #ddd;'>{$attr['attribution_model']}</td>";
            echo "<td style='padding:6px;border-bottom:1px solid #ddd;'>" . date('d/m/Y H:i', strtotime($attr['created_at'])) . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    }
    
} catch (Exception $e) {
    echo "<div class='error'>❌ Erro ao buscar dados: " . $e->getMessage() . "</div>";
}
echo "</div>";

// Limpeza
echo "<div class='card'>";
echo "<h2>🧹 7. Limpeza de Dados de Teste</h2>";

if (isset($_GET['cleanup'])) {
    try {
        // Remover dados de teste
        $pdo->exec("DELETE FROM usuarios WHERE email LIKE 'teste_%'");
        $pdo->exec("DELETE FROM affiliate_clicks WHERE ref_code = 'AFIL001' AND ip_address IS NULL");
        $pdo->exec("DELETE FROM comissoes WHERE transaction_id LIKE 'TEST_%'");
        $pdo->exec("DELETE FROM transacoes_pix WHERE transaction_id LIKE 'TEST_%'");
        $pdo->exec("DELETE FROM payment_callbacks WHERE transaction_id LIKE 'TEST_%'");
        
        echo "<div class='result'>✅ <strong>Dados de teste removidos com sucesso!</strong></div>";
    } catch (Exception $e) {
        echo "<div class='error'>❌ Erro na limpeza: " . $e->getMessage() . "</div>";
    }
} else {
    echo "<div class='step'>Clique no botão abaixo para limpar todos os dados de teste:</div>";
    echo "<button class='test-btn' onclick=\"if(confirm('Tem certeza que deseja limpar os dados de teste?')) window.location.href='?cleanup=1'\">🧹 Limpar Dados</button>";
}
echo "</div>";

// Instruções finais
echo "<div class='card'>";
echo "<h2>📋 8. Checklist de Implementação</h2>";
echo "<div class='step'>";
echo "<h3>✅ Pontos Implementados:</h3>";
echo "<ul>";
echo "<li>✅ Rastreamento de cliques com UTMs</li>";
echo "<li>✅ Atribuição no cadastro (FIRST_CLICK/LAST_CLICK)</li>";
echo "<li>✅ Processamento de primeiro depósito</li>";
echo "<li>✅ Comissões automáticas (2 níveis)</li>";
echo "<li>✅ Idempotência nos callbacks</li>";
echo "<li>✅ Logs detalhados</li>";
echo "<li>✅ Relatórios administrativos</li>";
echo "</ul>";
echo "</div>";

echo "<div class='step'>";
echo "<h3>🔧 Configurações Necessárias:</h3>";
echo "<ul>";
echo "<li>🔑 Configurar EXPFYPAY_WEBHOOK_SECRET no banco</li>";
echo "<li>🌐 Apontar webhook ExpfyPay para: <code>/webhook_expfypay.php</code></li>";
echo "<li>🍪 Configurar domínio do cookie se usar subdomínios</li>";
echo "<li>📊 Acessar relatórios em: <code>admin_affiliate_reports.php</code></li>";
echo "</ul>";
echo "</div>";

echo "<div class='step'>";
echo "<h3>🧪 Teste Manual Completo:</h3>";
echo "<ol>";
echo "<li>Acesse: <code>/?ref=AFIL001&utm_source=facebook</code></li>";
echo "<li>Faça um cadastro</li>";
echo "<li>Faça um depósito via ExpfyPay</li>";
echo "<li>Verifique os relatórios de afiliados</li>";
echo "<li>Confirme que a comissão foi gerada</li>";
echo "</ol>";
echo "</div>";
echo "</div>";

echo "</body></html>";
?>