<?php
/**
 * Script de Fusão de Esquemas - MySQL
 * Aplica as mudanças do sistema de afiliados de forma aditiva
 */

require_once 'db.php';

echo "🚀 Iniciando fusão de esquemas...\n";

// Verificar versão do MySQL
try {
    $stmt = $pdo->query("SELECT VERSION() as version");
    $version = $stmt->fetch()['version'];
    echo "🔍 Versão do MySQL: {$version}\n";
    
    $isMySQL8 = version_compare($version, '8.0.0', '>=');
    echo "📊 Suporte a IF NOT EXISTS: " . ($isMySQL8 ? "✅ Sim" : "⚠️ Limitado") . "\n";
} catch (PDOException $e) {
    echo "❌ Erro ao verificar versão: " . $e->getMessage() . "\n";
    exit(1);
}

// Função para verificar se coluna existe
function colunaExiste($pdo, $tabela, $coluna) {
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) 
            FROM INFORMATION_SCHEMA.COLUMNS 
            WHERE TABLE_SCHEMA = DATABASE() 
            AND TABLE_NAME = ? 
            AND COLUMN_NAME = ?
        ");
        $stmt->execute([$tabela, $coluna]);
        return $stmt->fetchColumn() > 0;
    } catch (PDOException $e) {
        return false;
    }
}

// Função para verificar se índice existe
function indiceExiste($pdo, $tabela, $indice) {
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) 
            FROM INFORMATION_SCHEMA.STATISTICS 
            WHERE TABLE_SCHEMA = DATABASE() 
            AND TABLE_NAME = ? 
            AND INDEX_NAME = ?
        ");
        $stmt->execute([$tabela, $indice]);
        return $stmt->fetchColumn() > 0;
    } catch (PDOException $e) {
        return false;
    }
}

// Função para verificar se tabela existe
function tabelaExiste($pdo, $tabela) {
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) 
            FROM INFORMATION_SCHEMA.TABLES 
            WHERE TABLE_SCHEMA = DATABASE() 
            AND TABLE_NAME = ?
        ");
        $stmt->execute([$tabela]);
        return $stmt->fetchColumn() > 0;
    } catch (PDOException $e) {
        return false;
    }
}

$sqlScript = [];
$sqlScript[] = "-- =====================================================";
$sqlScript[] = "-- FUSÃO DE ESQUEMAS - SISTEMA DE AFILIADOS";
$sqlScript[] = "-- Gerado em: " . date('Y-m-d H:i:s');
$sqlScript[] = "-- =====================================================";
$sqlScript[] = "";
$sqlScript[] = "SET FOREIGN_KEY_CHECKS = 0;";
$sqlScript[] = "SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';";
$sqlScript[] = "";

try {
    // =====================================================
    // 1. CRIAR TABELAS DE AFILIADOS
    // =====================================================
    
    echo "📋 Criando tabelas de afiliados...\n";
    
    // Tabela de cliques de afiliados
    if (!tabelaExiste($pdo, 'affiliate_clicks')) {
        echo "  ➕ Criando tabela affiliate_clicks...\n";
        $sql = "CREATE TABLE affiliate_clicks (
            id INT AUTO_INCREMENT PRIMARY KEY,
            affiliate_id INT NULL,
            ref_code VARCHAR(50) NOT NULL,
            url TEXT NOT NULL,
            utms_json JSON NULL,
            utm_source VARCHAR(100) NULL,
            utm_medium VARCHAR(100) NULL,
            utm_campaign VARCHAR(100) NULL,
            utm_content VARCHAR(100) NULL,
            utm_term VARCHAR(100) NULL,
            ip_address VARCHAR(45) NULL,
            user_agent TEXT NULL,
            subdomain VARCHAR(100) NULL,
            referrer TEXT NULL,
            session_id VARCHAR(100) NULL,
            converteu TINYINT(1) DEFAULT 0,
            usuario_convertido_id INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            
            INDEX idx_affiliate_id (affiliate_id),
            INDEX idx_ref_code (ref_code),
            INDEX idx_created_at (created_at),
            INDEX idx_converteu (converteu),
            INDEX idx_session_id (session_id),
            INDEX idx_ip_date (ip_address, created_at),
            
            FOREIGN KEY (affiliate_id) REFERENCES usuarios(id) ON DELETE SET NULL,
            FOREIGN KEY (usuario_convertido_id) REFERENCES usuarios(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
        
        $pdo->exec($sql);
        $sqlScript[] = $sql;
        echo "    ✅ Tabela affiliate_clicks criada\n";
    } else {
        echo "    ⏭️ Tabela affiliate_clicks já existe\n";
    }
    
    // Tabela de atribuições
    if (!tabelaExiste($pdo, 'affiliate_attributions')) {
        echo "  ➕ Criando tabela affiliate_attributions...\n";
        $sql = "CREATE TABLE affiliate_attributions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            affiliate_id INT NOT NULL,
            ref_code VARCHAR(50) NOT NULL,
            attribution_model ENUM('FIRST_CLICK', 'LAST_CLICK') DEFAULT 'LAST_CLICK',
            click_id INT NULL,
            utm_source VARCHAR(100) NULL,
            utm_medium VARCHAR(100) NULL,
            utm_campaign VARCHAR(100) NULL,
            utm_content VARCHAR(100) NULL,
            utm_term VARCHAR(100) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            
            UNIQUE KEY unique_user_attribution (user_id),
            INDEX idx_affiliate_id (affiliate_id),
            INDEX idx_ref_code (ref_code),
            INDEX idx_created_at (created_at),
            
            FOREIGN KEY (user_id) REFERENCES usuarios(id) ON DELETE CASCADE,
            FOREIGN KEY (affiliate_id) REFERENCES usuarios(id) ON DELETE CASCADE,
            FOREIGN KEY (click_id) REFERENCES affiliate_clicks(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
        
        $pdo->exec($sql);
        $sqlScript[] = $sql;
        echo "    ✅ Tabela affiliate_attributions criada\n";
    } else {
        echo "    ⏭️ Tabela affiliate_attributions já existe\n";
    }
    
    // Tabela de callbacks de pagamento
    if (!tabelaExiste($pdo, 'payment_callbacks')) {
        echo "  ➕ Criando tabela payment_callbacks...\n";
        $sql = "CREATE TABLE payment_callbacks (
            id INT AUTO_INCREMENT PRIMARY KEY,
            provider VARCHAR(50) NOT NULL DEFAULT 'expfypay',
            transaction_id VARCHAR(255) NOT NULL,
            external_id VARCHAR(255) NULL,
            payload_json JSON NOT NULL,
            status VARCHAR(50) NOT NULL,
            amount DECIMAL(10,2) NULL,
            user_id INT NULL,
            signature_ok TINYINT(1) DEFAULT 0,
            processed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            
            UNIQUE KEY unique_transaction (provider, transaction_id),
            INDEX idx_external_id (external_id),
            INDEX idx_user_id (user_id),
            INDEX idx_processed_at (processed_at),
            INDEX idx_status (status),
            
            FOREIGN KEY (user_id) REFERENCES usuarios(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
        
        $pdo->exec($sql);
        $sqlScript[] = $sql;
        echo "    ✅ Tabela payment_callbacks criada\n";
    } else {
        echo "    ⏭️ Tabela payment_callbacks já existe\n";
    }
    
    // Tabela de configurações de afiliados
    if (!tabelaExiste($pdo, 'affiliate_config')) {
        echo "  ➕ Criando tabela affiliate_config...\n";
        $sql = "CREATE TABLE affiliate_config (
            id INT AUTO_INCREMENT PRIMARY KEY,
            config_key VARCHAR(100) NOT NULL UNIQUE,
            config_value TEXT NOT NULL,
            description TEXT NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
        
        $pdo->exec($sql);
        $sqlScript[] = $sql;
        echo "    ✅ Tabela affiliate_config criada\n";
        
        // Inserir configurações padrão
        echo "  ⚙️ Inserindo configurações padrão...\n";
        $configs = [
            ['AFF_COOKIE_NAME', 'aff_ref', 'Nome do cookie de afiliado'],
            ['AFF_COOKIE_DAYS', '30', 'Duração do cookie em dias'],
            ['AFF_ATTR_MODEL', 'LAST_CLICK', 'Modelo de atribuição'],
            ['AFF_COOKIE_DOMAIN', '', 'Domínio do cookie'],
            ['EXPFYPAY_WEBHOOK_SECRET', '', 'Chave secreta do webhook'],
            ['EXPFYPAY_SUCCESS_STATUSES', '["completed","approved","paid","confirmed"]', 'Status de sucesso'],
            ['AFF_COMMISSION_RATE_L1', '10.00', 'Taxa de comissão nível 1'],
            ['AFF_COMMISSION_RATE_L2', '5.00', 'Taxa de comissão nível 2'],
            ['AFF_MIN_PAYOUT', '10.00', 'Valor mínimo para saque'],
            ['AFF_MAX_PAYOUT', '1000.00', 'Valor máximo para saque']
        ];
        
        foreach ($configs as $config) {
            $stmt = $pdo->prepare("INSERT IGNORE INTO affiliate_config (config_key, config_value, description) VALUES (?, ?, ?)");
            $stmt->execute($config);
            $sqlScript[] = "INSERT IGNORE INTO affiliate_config (config_key, config_value, description) VALUES ('{$config[0]}', '{$config[1]}', '{$config[2]}');";
        }
        echo "    ✅ Configurações padrão inseridas\n";
    } else {
        echo "    ⏭️ Tabela affiliate_config já existe\n";
    }
    
    // =====================================================
    // 2. ADICIONAR COLUNAS EM TABELAS EXISTENTES
    // =====================================================
    
    echo "\n🔧 Adicionando colunas em tabelas existentes...\n";
    
    // Colunas na tabela usuarios
    $colunasUsuarios = [
        'attributed_affiliate_id' => 'INT NULL',
        'ref_code_attributed' => 'VARCHAR(50) NULL',
        'attributed_at' => 'TIMESTAMP NULL',
        'first_deposit_confirmed' => 'TINYINT(1) DEFAULT 0',
        'first_deposit_amount' => 'DECIMAL(10,2) NULL',
        'first_deposit_at' => 'TIMESTAMP NULL'
    ];
    
    foreach ($colunasUsuarios as $coluna => $definicao) {
        if (!colunaExiste($pdo, 'usuarios', $coluna)) {
            echo "  ➕ Adicionando coluna usuarios.{$coluna}...\n";
            $sql = "ALTER TABLE usuarios ADD COLUMN {$coluna} {$definicao}";
            $pdo->exec($sql);
            $sqlScript[] = $sql . ";";
            echo "    ✅ Coluna {$coluna} adicionada\n";
        } else {
            echo "    ⏭️ Coluna usuarios.{$coluna} já existe\n";
        }
    }
    
    // Índices na tabela usuarios
    $indicesUsuarios = [
        'idx_attributed_affiliate' => 'attributed_affiliate_id',
        'idx_ref_code_attributed' => 'ref_code_attributed',
        'idx_first_deposit' => 'first_deposit_confirmed'
    ];
    
    foreach ($indicesUsuarios as $nomeIndice => $coluna) {
        if (!indiceExiste($pdo, 'usuarios', $nomeIndice)) {
            echo "  📊 Criando índice usuarios.{$nomeIndice}...\n";
            $sql = "ALTER TABLE usuarios ADD INDEX {$nomeIndice} ({$coluna})";
            $pdo->exec($sql);
            $sqlScript[] = $sql . ";";
            echo "    ✅ Índice {$nomeIndice} criado\n";
        } else {
            echo "    ⏭️ Índice usuarios.{$nomeIndice} já existe\n";
        }
    }
    
    // Colunas na tabela transacoes_pix
    $colunasTransacoes = [
        'affiliate_id' => 'INT NULL',
        'ref_code' => 'VARCHAR(50) NULL',
        'attributed_at' => 'TIMESTAMP NULL',
        'is_first_deposit' => 'TINYINT(1) DEFAULT 0'
    ];
    
    foreach ($colunasTransacoes as $coluna => $definicao) {
        if (!colunaExiste($pdo, 'transacoes_pix', $coluna)) {
            echo "  ➕ Adicionando coluna transacoes_pix.{$coluna}...\n";
            $sql = "ALTER TABLE transacoes_pix ADD COLUMN {$coluna} {$definicao}";
            $pdo->exec($sql);
            $sqlScript[] = $sql . ";";
            echo "    ✅ Coluna {$coluna} adicionada\n";
        } else {
            echo "    ⏭️ Coluna transacoes_pix.{$coluna} já existe\n";
        }
    }
    
    // Índices na tabela transacoes_pix
    $indicesTransacoes = [
        'idx_affiliate_id' => 'affiliate_id',
        'idx_ref_code' => 'ref_code',
        'idx_first_deposit' => 'is_first_deposit'
    ];
    
    foreach ($indicesTransacoes as $nomeIndice => $coluna) {
        if (!indiceExiste($pdo, 'transacoes_pix', $nomeIndice)) {
            echo "  📊 Criando índice transacoes_pix.{$nomeIndice}...\n";
            $sql = "ALTER TABLE transacoes_pix ADD INDEX {$nomeIndice} ({$coluna})";
            $pdo->exec($sql);
            $sqlScript[] = $sql . ";";
            echo "    ✅ Índice {$nomeIndice} criado\n";
        } else {
            echo "    ⏭️ Índice transacoes_pix.{$nomeIndice} já existe\n";
        }
    }
    
    // Colunas na tabela comissoes (se existir)
    if (tabelaExiste($pdo, 'comissoes')) {
        $colunasComissoes = [
            'click_id' => 'INT NULL',
            'transaction_id' => 'VARCHAR(255) NULL',
            'ref_code' => 'VARCHAR(50) NULL',
            'is_first_deposit' => 'TINYINT(1) DEFAULT 0'
        ];
        
        foreach ($colunasComissoes as $coluna => $definicao) {
            if (!colunaExiste($pdo, 'comissoes', $coluna)) {
                echo "  ➕ Adicionando coluna comissoes.{$coluna}...\n";
                $sql = "ALTER TABLE comissoes ADD COLUMN {$coluna} {$definicao}";
                $pdo->exec($sql);
                $sqlScript[] = $sql . ";";
                echo "    ✅ Coluna {$coluna} adicionada\n";
            } else {
                echo "    ⏭️ Coluna comissoes.{$coluna} já existe\n";
            }
        }
        
        // Índices na tabela comissoes
        $indicesComissoes = [
            'idx_click_id' => 'click_id',
            'idx_transaction_id' => 'transaction_id',
            'idx_ref_code' => 'ref_code'
        ];
        
        foreach ($indicesComissoes as $nomeIndice => $coluna) {
            if (!indiceExiste($pdo, 'comissoes', $nomeIndice)) {
                echo "  📊 Criando índice comissoes.{$nomeIndice}...\n";
                $sql = "ALTER TABLE comissoes ADD INDEX {$nomeIndice} ({$coluna})";
                $pdo->exec($sql);
                $sqlScript[] = $sql . ";";
                echo "    ✅ Índice {$nomeIndice} criado\n";
            } else {
                echo "    ⏭️ Índice comissoes.{$nomeIndice} já existe\n";
            }
        }
    }
    
    // =====================================================
    // 3. CRIAR VIEWS PARA RELATÓRIOS
    // =====================================================
    
    echo "\n📊 Criando views para relatórios...\n";
    
    $viewSql = "CREATE OR REPLACE VIEW view_affiliate_report AS
    SELECT 
        u.id as affiliate_id,
        u.nome as affiliate_name,
        u.email as affiliate_email,
        u.codigo_afiliado as ref_code,
        u.porcentagem_afiliado as commission_rate,
        u.afiliado_ativo as is_active,
        
        -- Estatísticas de cliques
        COALESCE(clicks_stats.total_clicks, 0) as total_clicks,
        COALESCE(clicks_stats.unique_ips, 0) as unique_visitors,
        
        -- Estatísticas de cadastros
        COALESCE(signup_stats.total_signups, 0) as total_signups,
        COALESCE(signup_stats.signups_today, 0) as signups_today,
        COALESCE(signup_stats.signups_this_month, 0) as signups_this_month,
        
        -- Estatísticas de depósitos
        COALESCE(deposit_stats.total_deposits, 0) as total_deposits,
        COALESCE(deposit_stats.total_deposit_amount, 0) as total_deposit_amount,
        COALESCE(deposit_stats.first_deposits, 0) as first_deposits,
        COALESCE(deposit_stats.first_deposit_amount, 0) as first_deposit_amount,
        
        -- Estatísticas de comissões
        COALESCE(commission_stats.total_commission, 0) as total_commission,
        COALESCE(commission_stats.pending_commission, 0) as pending_commission,
        COALESCE(commission_stats.paid_commission, 0) as paid_commission,
        
        -- Taxas de conversão
        CASE 
            WHEN COALESCE(clicks_stats.total_clicks, 0) > 0 
            THEN ROUND((COALESCE(signup_stats.total_signups, 0) / clicks_stats.total_clicks) * 100, 2)
            ELSE 0 
        END as signup_conversion_rate,
        
        CASE 
            WHEN COALESCE(signup_stats.total_signups, 0) > 0 
            THEN ROUND((COALESCE(deposit_stats.first_deposits, 0) / signup_stats.total_signups) * 100, 2)
            ELSE 0 
        END as deposit_conversion_rate,
        
        u.data_cadastro as created_at

    FROM usuarios u
    LEFT JOIN (
        SELECT 
            affiliate_id,
            COUNT(*) as total_clicks,
            COUNT(DISTINCT ip_address) as unique_ips
        FROM affiliate_clicks 
        WHERE affiliate_id IS NOT NULL
        GROUP BY affiliate_id
    ) clicks_stats ON u.id = clicks_stats.affiliate_id

    LEFT JOIN (
        SELECT 
            aa.affiliate_id,
            COUNT(*) as total_signups,
            COUNT(CASE WHEN DATE(aa.created_at) = CURDATE() THEN 1 END) as signups_today,
            COUNT(CASE WHEN YEAR(aa.created_at) = YEAR(NOW()) AND MONTH(aa.created_at) = MONTH(NOW()) THEN 1 END) as signups_this_month
        FROM affiliate_attributions aa
        GROUP BY aa.affiliate_id
    ) signup_stats ON u.id = signup_stats.affiliate_id

    LEFT JOIN (
        SELECT 
            t.affiliate_id,
            COUNT(*) as total_deposits,
            SUM(t.valor) as total_deposit_amount,
            COUNT(CASE WHEN t.is_first_deposit = 1 THEN 1 END) as first_deposits,
            SUM(CASE WHEN t.is_first_deposit = 1 THEN t.valor ELSE 0 END) as first_deposit_amount
        FROM transacoes_pix t
        WHERE t.status = 'aprovado' AND t.affiliate_id IS NOT NULL
        GROUP BY t.affiliate_id
    ) deposit_stats ON u.id = deposit_stats.affiliate_id

    LEFT JOIN (
        SELECT 
            c.afiliado_id,
            SUM(c.valor_comissao) as total_commission,
            SUM(CASE WHEN c.status = 'pendente' THEN c.valor_comissao ELSE 0 END) as pending_commission,
            SUM(CASE WHEN c.status = 'pago' THEN c.valor_comissao ELSE 0 END) as paid_commission
        FROM comissoes c
        GROUP BY c.afiliado_id
    ) commission_stats ON u.id = commission_stats.afiliado_id

    WHERE u.codigo_afiliado IS NOT NULL AND u.codigo_afiliado != '';";
    
    $pdo->exec($viewSql);
    $sqlScript[] = $viewSql;
    echo "    ✅ View view_affiliate_report criada\n";
    
    // =====================================================
    // 4. MIGRAÇÃO DE DADOS EXISTENTES
    // =====================================================
    
    echo "\n🔄 Migrando dados existentes...\n";
    
    // Migrar dados de codigo_afiliado_usado para attributed_affiliate_id
    echo "  🔗 Migrando atribuições existentes...\n";
    $sql = "UPDATE usuarios u1 
            SET attributed_affiliate_id = (
                SELECT u2.id 
                FROM usuarios u2 
                WHERE u2.codigo_afiliado = u1.codigo_afiliado_usado 
                LIMIT 1
            ),
            ref_code_attributed = u1.codigo_afiliado_usado,
            attributed_at = u1.data_cadastro
            WHERE u1.codigo_afiliado_usado IS NOT NULL 
            AND u1.codigo_afiliado_usado != ''
            AND u1.attributed_affiliate_id IS NULL";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    $affected = $stmt->rowCount();
    $sqlScript[] = $sql . ";";
    echo "    ✅ {$affected} usuários migrados\n";
    
    // Criar atribuições para usuários já existentes
    echo "  📝 Criando registros de atribuição...\n";
    $sql = "INSERT IGNORE INTO affiliate_attributions (user_id, affiliate_id, ref_code, attribution_model, created_at)
            SELECT 
                u1.id,
                u1.attributed_affiliate_id,
                u1.ref_code_attributed,
                'LAST_CLICK',
                u1.attributed_at
            FROM usuarios u1
            WHERE u1.attributed_affiliate_id IS NOT NULL 
            AND u1.ref_code_attributed IS NOT NULL";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    $affected = $stmt->rowCount();
    $sqlScript[] = $sql . ";";
    echo "    ✅ {$affected} atribuições criadas\n";
    
    // Marcar transações existentes como primeiro depósito
    echo "  💰 Marcando primeiros depósitos...\n";
    $sql = "UPDATE transacoes_pix t
            SET is_first_deposit = 1,
                affiliate_id = (SELECT attributed_affiliate_id FROM usuarios WHERE id = t.usuario_id),
                ref_code = (SELECT ref_code_attributed FROM usuarios WHERE id = t.usuario_id),
                attributed_at = t.criado_em
            WHERE t.status = 'aprovado'
            AND t.is_first_deposit IS NULL
            AND t.id = (
                SELECT MIN(t2.id) 
                FROM transacoes_pix t2 
                WHERE t2.usuario_id = t.usuario_id 
                AND t2.status = 'aprovado'
            )";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    $affected = $stmt->rowCount();
    $sqlScript[] = $sql . ";";
    echo "    ✅ {$affected} primeiros depósitos marcados\n";
    
    // Atualizar usuários com primeiro depósito confirmado
    echo "  ✅ Atualizando status de primeiro depósito...\n";
    $sql = "UPDATE usuarios u
            SET first_deposit_confirmed = 1,
                first_deposit_amount = (
                    SELECT t.valor 
                    FROM transacoes_pix t 
                    WHERE t.usuario_id = u.id 
                    AND t.is_first_deposit = 1 
                    AND t.status = 'aprovado'
                    LIMIT 1
                ),
                first_deposit_at = (
                    SELECT t.criado_em 
                    FROM transacoes_pix t 
                    WHERE t.usuario_id = u.id 
                    AND t.is_first_deposit = 1 
                    AND t.status = 'aprovado'
                    LIMIT 1
                )
            WHERE EXISTS (
                SELECT 1 FROM transacoes_pix t 
                WHERE t.usuario_id = u.id 
                AND t.is_first_deposit = 1 
                AND t.status = 'aprovado'
            )";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    $affected = $stmt->rowCount();
    $sqlScript[] = $sql . ";";
    echo "    ✅ {$affected} usuários atualizados\n";
    
    // =====================================================
    // 5. ÍNDICES ADICIONAIS PARA PERFORMANCE
    // =====================================================
    
    echo "\n⚡ Criando índices de performance...\n";
    
    $indicesPerformance = [
        ['usuarios', 'idx_usuarios_affiliate_active', 'codigo_afiliado, afiliado_ativo'],
        ['transacoes_pix', 'idx_transacoes_user_status', 'usuario_id, status'],
        ['comissoes', 'idx_comissoes_affiliate_status', 'afiliado_id, status']
    ];
    
    foreach ($indicesPerformance as $indiceInfo) {
        [$tabela, $nomeIndice, $colunas] = $indiceInfo;
        
        if (tabelaExiste($pdo, $tabela) && !indiceExiste($pdo, $tabela, $nomeIndice)) {
            echo "  📊 Criando índice {$tabela}.{$nomeIndice}...\n";
            $sql = "ALTER TABLE {$tabela} ADD INDEX {$nomeIndice} ({$colunas})";
            $pdo->exec($sql);
            $sqlScript[] = $sql . ";";
            echo "    ✅ Índice {$nomeIndice} criado\n";
        } else {
            echo "    ⏭️ Índice {$tabela}.{$nomeIndice} já existe ou tabela não encontrada\n";
        }
    }
    
    $sqlScript[] = "";
    $sqlScript[] = "SET FOREIGN_KEY_CHECKS = 1;";
    $sqlScript[] = "";
    $sqlScript[] = "-- =====================================================";
    $sqlScript[] = "-- FUSÃO CONCLUÍDA EM: " . date('Y-m-d H:i:s');
    $sqlScript[] = "-- =====================================================";
    
    // Salvar script aplicado
    $scriptPath = "bd/_merged_apply_" . date('Y-m-d_H-i-s') . ".sql";
    file_put_contents($scriptPath, implode("\n", $sqlScript));
    echo "\n💾 Script aplicado salvo em: {$scriptPath}\n";
    
    echo "\n✅ Fusão de esquemas concluída com sucesso!\n";
    
} catch (PDOException $e) {
    echo "\n❌ Erro durante a migração: " . $e->getMessage() . "\n";
    echo "🔄 Revertendo alterações...\n";
    
    // Em caso de erro, tentar reverter (se possível)
    try {
        $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
    } catch (PDOException $e2) {
        // Ignorar erro de reversão
    }
    
    exit(1);
}
?>