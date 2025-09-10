-- =====================================================
-- MIGRAÇÃO MANUAL - SISTEMA DE AFILIADOS
-- Baseado em supabase/migrations/20250910101050_summer_mode.sql
-- Adaptado para MySQL 5.7/8.0 com compatibilidade total
-- =====================================================

-- Configurações de segurança
SET FOREIGN_KEY_CHECKS = 0;
SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';

-- =====================================================
-- 1. TABELA DE CLIQUES DE AFILIADOS
-- =====================================================
CREATE TABLE IF NOT EXISTS affiliate_clicks (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- 2. TABELA DE ATRIBUIÇÕES
-- =====================================================
CREATE TABLE IF NOT EXISTS affiliate_attributions (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- 3. TABELA DE CALLBACKS DE PAGAMENTO
-- =====================================================
CREATE TABLE IF NOT EXISTS payment_callbacks (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- 4. CONFIGURAÇÕES DO SISTEMA DE AFILIADOS
-- =====================================================
CREATE TABLE IF NOT EXISTS affiliate_config (
    id INT AUTO_INCREMENT PRIMARY KEY,
    config_key VARCHAR(100) NOT NULL UNIQUE,
    config_value TEXT NOT NULL,
    description TEXT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Inserir configurações padrão
INSERT IGNORE INTO affiliate_config (config_key, config_value, description) VALUES
('AFF_COOKIE_NAME', 'aff_ref', 'Nome do cookie de afiliado'),
('AFF_COOKIE_DAYS', '30', 'Duração do cookie em dias'),
('AFF_ATTR_MODEL', 'LAST_CLICK', 'Modelo de atribuição (FIRST_CLICK ou LAST_CLICK)'),
('AFF_COOKIE_DOMAIN', '', 'Domínio do cookie (vazio = domínio atual)'),
('EXPFYPAY_WEBHOOK_SECRET', '', 'Chave secreta do webhook ExpfyPay'),
('EXPFYPAY_SUCCESS_STATUSES', '["completed","approved","paid","confirmed"]', 'Status de sucesso do ExpfyPay'),
('AFF_COMMISSION_RATE_L1', '10.00', 'Taxa de comissão nível 1 (%)'),
('AFF_COMMISSION_RATE_L2', '5.00', 'Taxa de comissão nível 2 (%)'),
('AFF_MIN_PAYOUT', '10.00', 'Valor mínimo para saque de comissão'),
('AFF_MAX_PAYOUT', '1000.00', 'Valor máximo para saque de comissão');

-- =====================================================
-- 5. ADICIONAR COLUNAS EM TABELAS EXISTENTES
-- =====================================================

-- Colunas na tabela usuarios (verificação condicional)
SET @sql = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'usuarios' AND COLUMN_NAME = 'attributed_affiliate_id') = 0,
    'ALTER TABLE usuarios ADD COLUMN attributed_affiliate_id INT NULL',
    'SELECT "Coluna attributed_affiliate_id já existe" as msg'
));
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'usuarios' AND COLUMN_NAME = 'ref_code_attributed') = 0,
    'ALTER TABLE usuarios ADD COLUMN ref_code_attributed VARCHAR(50) NULL',
    'SELECT "Coluna ref_code_attributed já existe" as msg'
));
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'usuarios' AND COLUMN_NAME = 'attributed_at') = 0,
    'ALTER TABLE usuarios ADD COLUMN attributed_at TIMESTAMP NULL',
    'SELECT "Coluna attributed_at já existe" as msg'
));
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'usuarios' AND COLUMN_NAME = 'first_deposit_confirmed') = 0,
    'ALTER TABLE usuarios ADD COLUMN first_deposit_confirmed TINYINT(1) DEFAULT 0',
    'SELECT "Coluna first_deposit_confirmed já existe" as msg'
));
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'usuarios' AND COLUMN_NAME = 'first_deposit_amount') = 0,
    'ALTER TABLE usuarios ADD COLUMN first_deposit_amount DECIMAL(10,2) NULL',
    'SELECT "Coluna first_deposit_amount já existe" as msg'
));
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'usuarios' AND COLUMN_NAME = 'first_deposit_at') = 0,
    'ALTER TABLE usuarios ADD COLUMN first_deposit_at TIMESTAMP NULL',
    'SELECT "Coluna first_deposit_at já existe" as msg'
));
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Colunas na tabela transacoes_pix
SET @sql = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transacoes_pix' AND COLUMN_NAME = 'affiliate_id') = 0,
    'ALTER TABLE transacoes_pix ADD COLUMN affiliate_id INT NULL',
    'SELECT "Coluna affiliate_id já existe" as msg'
));
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transacoes_pix' AND COLUMN_NAME = 'ref_code') = 0,
    'ALTER TABLE transacoes_pix ADD COLUMN ref_code VARCHAR(50) NULL',
    'SELECT "Coluna ref_code já existe" as msg'
));
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transacoes_pix' AND COLUMN_NAME = 'attributed_at') = 0,
    'ALTER TABLE transacoes_pix ADD COLUMN attributed_at TIMESTAMP NULL',
    'SELECT "Coluna attributed_at já existe" as msg'
));
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transacoes_pix' AND COLUMN_NAME = 'is_first_deposit') = 0,
    'ALTER TABLE transacoes_pix ADD COLUMN is_first_deposit TINYINT(1) DEFAULT 0',
    'SELECT "Coluna is_first_deposit já existe" as msg'
));
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Colunas na tabela comissoes (se existir)
SET @sql = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES 
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'comissoes') > 0 AND
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'comissoes' AND COLUMN_NAME = 'click_id') = 0,
    'ALTER TABLE comissoes ADD COLUMN click_id INT NULL',
    'SELECT "Tabela comissoes não existe ou coluna click_id já existe" as msg'
));
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES 
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'comissoes') > 0 AND
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'comissoes' AND COLUMN_NAME = 'transaction_id') = 0,
    'ALTER TABLE comissoes ADD COLUMN transaction_id VARCHAR(255) NULL',
    'SELECT "Tabela comissoes não existe ou coluna transaction_id já existe" as msg'
));
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES 
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'comissoes') > 0 AND
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'comissoes' AND COLUMN_NAME = 'ref_code') = 0,
    'ALTER TABLE comissoes ADD COLUMN ref_code VARCHAR(50) NULL',
    'SELECT "Tabela comissoes não existe ou coluna ref_code já existe" as msg'
));
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES 
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'comissoes') > 0 AND
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'comissoes' AND COLUMN_NAME = 'is_first_deposit') = 0,
    'ALTER TABLE comissoes ADD COLUMN is_first_deposit TINYINT(1) DEFAULT 0',
    'SELECT "Tabela comissoes não existe ou coluna is_first_deposit já existe" as msg'
));
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- =====================================================
-- 6. CRIAR ÍNDICES (VERIFICAÇÃO CONDICIONAL)
-- =====================================================

-- Índices na tabela usuarios
SET @sql = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS 
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'usuarios' AND INDEX_NAME = 'idx_attributed_affiliate') = 0,
    'ALTER TABLE usuarios ADD INDEX idx_attributed_affiliate (attributed_affiliate_id)',
    'SELECT "Índice idx_attributed_affiliate já existe" as msg'
));
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS 
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'usuarios' AND INDEX_NAME = 'idx_ref_code_attributed') = 0,
    'ALTER TABLE usuarios ADD INDEX idx_ref_code_attributed (ref_code_attributed)',
    'SELECT "Índice idx_ref_code_attributed já existe" as msg'
));
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS 
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'usuarios' AND INDEX_NAME = 'idx_first_deposit') = 0,
    'ALTER TABLE usuarios ADD INDEX idx_first_deposit (first_deposit_confirmed)',
    'SELECT "Índice idx_first_deposit já existe" as msg'
));
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Índices na tabela transacoes_pix
SET @sql = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS 
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transacoes_pix' AND INDEX_NAME = 'idx_affiliate_id') = 0,
    'ALTER TABLE transacoes_pix ADD INDEX idx_affiliate_id (affiliate_id)',
    'SELECT "Índice idx_affiliate_id já existe" as msg'
));
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS 
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transacoes_pix' AND INDEX_NAME = 'idx_ref_code') = 0,
    'ALTER TABLE transacoes_pix ADD INDEX idx_ref_code (ref_code)',
    'SELECT "Índice idx_ref_code já existe" as msg'
));
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS 
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transacoes_pix' AND INDEX_NAME = 'idx_first_deposit') = 0,
    'ALTER TABLE transacoes_pix ADD INDEX idx_first_deposit (is_first_deposit)',
    'SELECT "Índice idx_first_deposit já existe" as msg'
));
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Índices na tabela comissoes (se existir)
SET @sql = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES 
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'comissoes') > 0 AND
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS 
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'comissoes' AND INDEX_NAME = 'idx_click_id') = 0,
    'ALTER TABLE comissoes ADD INDEX idx_click_id (click_id)',
    'SELECT "Tabela comissoes não existe ou índice idx_click_id já existe" as msg'
));
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES 
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'comissoes') > 0 AND
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS 
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'comissoes' AND INDEX_NAME = 'idx_transaction_id') = 0,
    'ALTER TABLE comissoes ADD INDEX idx_transaction_id (transaction_id)',
    'SELECT "Tabela comissoes não existe ou índice idx_transaction_id já existe" as msg'
));
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- =====================================================
-- 7. CRIAR VIEW PARA RELATÓRIOS
-- =====================================================
CREATE OR REPLACE VIEW view_affiliate_report AS
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

WHERE u.codigo_afiliado IS NOT NULL AND u.codigo_afiliado != '';

-- =====================================================
-- 8. MIGRAÇÃO DE DADOS EXISTENTES
-- =====================================================

-- Migrar dados existentes de codigo_afiliado_usado para attributed_affiliate_id
UPDATE usuarios u1 
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
AND u1.attributed_affiliate_id IS NULL;

-- Criar atribuições para usuários já existentes
INSERT IGNORE INTO affiliate_attributions (user_id, affiliate_id, ref_code, attribution_model, created_at)
SELECT 
    u1.id,
    u1.attributed_affiliate_id,
    u1.ref_code_attributed,
    'LAST_CLICK',
    u1.attributed_at
FROM usuarios u1
WHERE u1.attributed_affiliate_id IS NOT NULL 
AND u1.ref_code_attributed IS NOT NULL;

-- Marcar transações existentes como primeiro depósito quando aplicável
UPDATE transacoes_pix t
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
);

-- Atualizar usuários com primeiro depósito confirmado
UPDATE usuarios u
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
);

-- =====================================================
-- 9. ÍNDICES ADICIONAIS PARA PERFORMANCE
-- =====================================================

-- Índices compostos para consultas frequentes
SET @sql = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS 
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'usuarios' AND INDEX_NAME = 'idx_usuarios_affiliate_active') = 0,
    'ALTER TABLE usuarios ADD INDEX idx_usuarios_affiliate_active (codigo_afiliado, afiliado_ativo)',
    'SELECT "Índice idx_usuarios_affiliate_active já existe" as msg'
));
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS 
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transacoes_pix' AND INDEX_NAME = 'idx_transacoes_user_status') = 0,
    'ALTER TABLE transacoes_pix ADD INDEX idx_transacoes_user_status (usuario_id, status)',
    'SELECT "Índice idx_transacoes_user_status já existe" as msg'
));
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Índices para relatórios por data
SET @sql = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS 
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'affiliate_clicks' AND INDEX_NAME = 'idx_affiliate_clicks_date_ref') = 0,
    'ALTER TABLE affiliate_clicks ADD INDEX idx_affiliate_clicks_date_ref (created_at, ref_code)',
    'SELECT "Índice idx_affiliate_clicks_date_ref já existe" as msg'
));
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS 
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'affiliate_attributions' AND INDEX_NAME = 'idx_affiliate_attributions_date') = 0,
    'ALTER TABLE affiliate_attributions ADD INDEX idx_affiliate_attributions_date (created_at, affiliate_id)',
    'SELECT "Índice idx_affiliate_attributions_date já existe" as msg'
));
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Restaurar configurações
SET FOREIGN_KEY_CHECKS = 1;

-- =====================================================
-- MIGRAÇÃO CONCLUÍDA
-- =====================================================
SELECT 'Migração do sistema de afiliados concluída com sucesso!' as resultado;