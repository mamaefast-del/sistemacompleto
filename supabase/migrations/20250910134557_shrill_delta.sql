-- =====================================================
-- ADICIONAR APENAS NOVAS TABELAS DO SISTEMA DE AFILIADOS
-- PRESERVA TODOS OS DADOS EXISTENTES
-- =====================================================

SET FOREIGN_KEY_CHECKS = 0;
SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';

-- =====================================================
-- 1. TABELA DE CLIQUES DE AFILIADOS
-- =====================================================
CREATE TABLE IF NOT EXISTS `affiliate_clicks` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `affiliate_id` int(11) DEFAULT NULL,
  `ref_code` varchar(50) NOT NULL,
  `url` text NOT NULL,
  `utms_json` json DEFAULT NULL,
  `utm_source` varchar(100) DEFAULT NULL,
  `utm_medium` varchar(100) DEFAULT NULL,
  `utm_campaign` varchar(100) DEFAULT NULL,
  `utm_content` varchar(100) DEFAULT NULL,
  `utm_term` varchar(100) DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `subdomain` varchar(100) DEFAULT NULL,
  `referrer` text DEFAULT NULL,
  `session_id` varchar(100) DEFAULT NULL,
  `converteu` tinyint(1) DEFAULT 0,
  `usuario_convertido_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_affiliate_id` (`affiliate_id`),
  KEY `idx_ref_code` (`ref_code`),
  KEY `idx_created_at` (`created_at`),
  KEY `idx_converteu` (`converteu`),
  KEY `idx_session_id` (`session_id`),
  KEY `idx_ip_date` (`ip_address`, `created_at`),
  KEY `idx_affiliate_clicks_date_ref` (`created_at`, `ref_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- 2. TABELA DE ATRIBUIÇÕES DE AFILIADOS
-- =====================================================
CREATE TABLE IF NOT EXISTS `affiliate_attributions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `affiliate_id` int(11) NOT NULL,
  `ref_code` varchar(50) NOT NULL,
  `attribution_model` enum('FIRST_CLICK','LAST_CLICK') DEFAULT 'LAST_CLICK',
  `click_id` int(11) DEFAULT NULL,
  `utm_source` varchar(100) DEFAULT NULL,
  `utm_medium` varchar(100) DEFAULT NULL,
  `utm_campaign` varchar(100) DEFAULT NULL,
  `utm_content` varchar(100) DEFAULT NULL,
  `utm_term` varchar(100) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_user_attribution` (`user_id`),
  KEY `idx_affiliate_id` (`affiliate_id`),
  KEY `idx_ref_code` (`ref_code`),
  KEY `idx_created_at` (`created_at`),
  KEY `idx_affiliate_attributions_date` (`created_at`, `affiliate_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- 3. TABELA DE CALLBACKS DE PAGAMENTO
-- =====================================================
CREATE TABLE IF NOT EXISTS `payment_callbacks` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `provider` varchar(50) NOT NULL DEFAULT 'expfypay',
  `transaction_id` varchar(255) NOT NULL,
  `external_id` varchar(255) DEFAULT NULL,
  `payload_json` json NOT NULL,
  `status` varchar(50) NOT NULL,
  `amount` decimal(10,2) DEFAULT NULL,
  `user_id` int(11) DEFAULT NULL,
  `signature_ok` tinyint(1) DEFAULT 0,
  `processed_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_transaction` (`provider`, `transaction_id`),
  KEY `idx_external_id` (`external_id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_processed_at` (`processed_at`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- 4. CONFIGURAÇÕES DO SISTEMA DE AFILIADOS
-- =====================================================
CREATE TABLE IF NOT EXISTS `affiliate_config` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `config_key` varchar(100) NOT NULL,
  `config_value` text NOT NULL,
  `description` text DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `config_key` (`config_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Inserir configurações padrão do sistema de afiliados
INSERT IGNORE INTO `affiliate_config` (`config_key`, `config_value`, `description`) VALUES
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
-- 5. TABELA DE MATERIAIS DE MARKETING
-- =====================================================
CREATE TABLE IF NOT EXISTS `marketing_materials` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `titulo` varchar(255) NOT NULL,
  `descricao` text DEFAULT NULL,
  `arquivo` varchar(500) DEFAULT NULL,
  `tipo` enum('banner','video','texto','imagem') DEFAULT 'banner',
  `dimensoes` varchar(50) DEFAULT NULL,
  `ativo` tinyint(1) DEFAULT 1,
  `data_criacao` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `data_atualizacao` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_tipo` (`tipo`),
  KEY `idx_ativo` (`ativo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- 6. TABELA DE HISTÓRICO DE AFILIADOS
-- =====================================================
CREATE TABLE IF NOT EXISTS `historico_afiliados` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `afiliado_id` int(11) NOT NULL,
  `acao` varchar(100) NOT NULL,
  `detalhes` text DEFAULT NULL,
  `data_acao` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_afiliado_id` (`afiliado_id`),
  KEY `idx_acao` (`acao`),
  KEY `idx_data_acao` (`data_acao`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- 7. TABELA DE CONFIGURAÇÃO DE PIXELS
-- =====================================================
CREATE TABLE IF NOT EXISTS `pixels_config` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `plataforma` varchar(50) NOT NULL,
  `pixel_id` varchar(255) DEFAULT NULL,
  `codigo_head` text DEFAULT NULL,
  `codigo_body` text DEFAULT NULL,
  `ativo` tinyint(1) DEFAULT 1,
  `data_atualizacao` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Inserir pixels padrão (apenas se não existirem)
INSERT IGNORE INTO `pixels_config` (`id`, `plataforma`, `ativo`) VALUES
(1, 'Facebook Pixel', 1),
(2, 'Google Analytics', 1),
(3, 'Google Ads', 1),
(4, 'TikTok Pixel', 1),
(5, 'Kwai Ads', 1);

-- =====================================================
-- 8. TABELA DE SPLITS DO GATEWAY
-- =====================================================
CREATE TABLE IF NOT EXISTS `gateway_splits` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `email` varchar(255) NOT NULL,
  `porcentagem` decimal(5,2) NOT NULL,
  `descricao` varchar(255) DEFAULT NULL,
  `ativo` tinyint(1) DEFAULT 1,
  `data_criacao` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `data_atualizacao` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Inserir split padrão (apenas se não existir)
INSERT IGNORE INTO `gateway_splits` (`email`, `porcentagem`, `descricao`) VALUES
('levicarimbo@gmail.com', 5.0, 'Split principal do gateway');

-- =====================================================
-- 9. TABELA DE CONFIGURAÇÃO DE DEMO
-- =====================================================
CREATE TABLE IF NOT EXISTS `demo_config` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `saldo_inicial_demo` decimal(10,2) DEFAULT 1000.00,
  `percentual_ganho_demo` decimal(5,2) DEFAULT 80.00,
  `limite_diario_demo` decimal(10,2) DEFAULT 500.00,
  `duracao_demo` int(11) DEFAULT 7,
  `data_atualizacao` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Inserir configuração demo padrão (apenas se não existir)
INSERT IGNORE INTO `demo_config` (`saldo_inicial_demo`, `percentual_ganho_demo`, `limite_diario_demo`, `duracao_demo`) VALUES
(1000.00, 80.00, 500.00, 7);

-- =====================================================
-- 10. TABELA DE CORES DO SITE
-- =====================================================
CREATE TABLE IF NOT EXISTS `cores_site` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `cor_primaria` varchar(7) DEFAULT '#fbce00',
  `cor_secundaria` varchar(7) DEFAULT '#f4c430',
  `cor_azul` varchar(7) DEFAULT '#007fdb',
  `cor_verde` varchar(7) DEFAULT '#00e880',
  `cor_fundo` varchar(7) DEFAULT '#0a0b0f',
  `cor_painel` varchar(7) DEFAULT '#111318',
  `atualizado_em` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Inserir cores padrão (apenas se não existir)
INSERT IGNORE INTO `cores_site` (`cor_primaria`, `cor_secundaria`, `cor_azul`, `cor_verde`, `cor_fundo`, `cor_painel`) VALUES
('#fbce00', '#f4c430', '#007fdb', '#00e880', '#0a0b0f', '#111318');

-- =====================================================
-- 11. ADICIONAR COLUNAS EM TABELAS EXISTENTES (SEGURO)
-- =====================================================

-- Verificar e adicionar colunas na tabela usuarios (apenas se não existirem)
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

-- Verificar e adicionar colunas na tabela transacoes_pix (apenas se não existirem)
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

-- Verificar e adicionar colunas na tabela comissoes (apenas se a tabela existir e colunas não existirem)
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
-- 12. CRIAR ÍNDICES ADICIONAIS (APENAS SE NÃO EXISTIREM)
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

-- =====================================================
-- 13. CRIAR VIEW PARA RELATÓRIOS
-- =====================================================
CREATE OR REPLACE VIEW `view_affiliate_report` AS
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
-- 14. MIGRAÇÃO DE DADOS EXISTENTES (PRESERVANDO TUDO)
-- =====================================================

-- Migrar dados existentes de codigo_afiliado_usado para attributed_affiliate_id (apenas se não foi feito)
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

-- Criar atribuições para usuários já existentes (apenas se não existirem)
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

-- Marcar transações existentes como primeiro depósito quando aplicável (apenas se não foi feito)
UPDATE transacoes_pix t
SET is_first_deposit = 1,
    affiliate_id = (SELECT attributed_affiliate_id FROM usuarios WHERE id = t.usuario_id),
    ref_code = (SELECT ref_code_attributed FROM usuarios WHERE id = t.usuario_id),
    attributed_at = t.criado_em
WHERE t.status = 'aprovado'
AND (t.is_first_deposit IS NULL OR t.is_first_deposit = 0)
AND t.id = (
    SELECT MIN(t2.id) 
    FROM transacoes_pix t2 
    WHERE t2.usuario_id = t.usuario_id 
    AND t2.status = 'aprovado'
);

-- Atualizar usuários com primeiro depósito confirmado (apenas se não foi feito)
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
)
AND u.first_deposit_confirmed = 0;

-- =====================================================
-- 15. ÍNDICES ADICIONAIS PARA PERFORMANCE
-- =====================================================

-- Índices compostos para consultas frequentes (apenas se não existirem)
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

-- Índices para comissoes (se a tabela existir)
SET @sql = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES 
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'comissoes') > 0 AND
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS 
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'comissoes' AND INDEX_NAME = 'idx_comissoes_affiliate_status') = 0,
    'ALTER TABLE comissoes ADD INDEX idx_comissoes_affiliate_status (afiliado_id, status)',
    'SELECT "Tabela comissoes não existe ou índice já existe" as msg'
));
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- =====================================================
-- 16. DADOS DE EXEMPLO PARA TESTE (APENAS SE NÃO EXISTIREM)
-- =====================================================

-- Usuário afiliado de exemplo (apenas se não existir)
INSERT IGNORE INTO `usuarios` (`id`, `nome`, `email`, `senha`, `codigo_afiliado`, `afiliado_ativo`, `porcentagem_afiliado`, `saldo`, `data_cadastro`) VALUES
(672, 'Afiliado Teste', 'afiliado@teste.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'AFIL001', 1, 15.00, 0.00, NOW());

-- Usuário indicado de exemplo (apenas se não existir)
INSERT IGNORE INTO `usuarios` (`id`, `nome`, `email`, `senha`, `codigo_afiliado_usado`, `attributed_affiliate_id`, `ref_code_attributed`, `attributed_at`, `saldo`, `data_cadastro`) VALUES
(673, 'Usuário Indicado', 'indicado@teste.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'AFIL001', 672, 'AFIL001', NOW(), 0.00, NOW());

-- Atribuição de exemplo (apenas se não existir)
INSERT IGNORE INTO `affiliate_attributions` (`user_id`, `affiliate_id`, `ref_code`, `attribution_model`, `utm_source`, `utm_medium`) VALUES
(673, 672, 'AFIL001', 'LAST_CLICK', 'facebook', 'social');

-- Clique de exemplo (apenas se não existir)
INSERT IGNORE INTO `affiliate_clicks` (`affiliate_id`, `ref_code`, `url`, `utm_source`, `utm_medium`, `utm_campaign`, `ip_address`, `session_id`) VALUES
(672, 'AFIL001', '/?ref=AFIL001&utm_source=facebook&utm_medium=social&utm_campaign=promo2025', 'facebook', 'social', 'promo2025', '127.0.0.1', 'test_session_001');

-- =====================================================
-- 17. CONFIGURAÇÕES FINAIS
-- =====================================================

-- Configurar AUTO_INCREMENT para evitar conflitos (apenas se as tabelas foram criadas agora)
ALTER TABLE `affiliate_clicks` AUTO_INCREMENT = 1000;
ALTER TABLE `affiliate_attributions` AUTO_INCREMENT = 1000;
ALTER TABLE `payment_callbacks` AUTO_INCREMENT = 1000;

-- Restaurar configurações
SET FOREIGN_KEY_CHECKS = 1;

-- =====================================================
-- RESULTADO FINAL
-- =====================================================
SELECT 'Tabelas de afiliados adicionadas com sucesso! Todos os dados existentes foram preservados.' as resultado;
SELECT 'Novas tabelas: affiliate_clicks, affiliate_attributions, payment_callbacks, affiliate_config' as tabelas_criadas;
SELECT 'Colunas adicionadas em: usuarios, transacoes_pix, comissoes (se existir)' as colunas_adicionadas;
SELECT 'View criada: view_affiliate_report' as view_criada;
SELECT 'Dados de exemplo: Afiliado AFIL001 (15% comissão)' as dados_exemplo;