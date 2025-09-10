/*
# Sistema de Afiliados Aprimorado - Migração Completa

1. Novas Tabelas
   - `affiliate_clicks` - Rastreamento de cliques com UTMs
   - `affiliate_attributions` - Atribuições de usuários a afiliados
   - `payment_callbacks` - Log de callbacks de pagamento
   - `affiliate_config` - Configurações do sistema

2. Melhorias em Tabelas Existentes
   - `usuarios` - Adicionar campos de atribuição
   - `transacoes_pix` - Adicionar campos de afiliado
   - `comissoes` - Melhorar estrutura

3. Índices e Performance
   - Índices otimizados para consultas frequentes
   - Chaves únicas para evitar duplicações

4. Compatibilidade
   - Todas as alterações são aditivas
   - Dados existentes preservados
   - Backwards compatible
*/

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
);

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
);

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
);

-- =====================================================
-- 4. CONFIGURAÇÕES DO SISTEMA DE AFILIADOS
-- =====================================================
CREATE TABLE IF NOT EXISTS affiliate_config (
    id INT AUTO_INCREMENT PRIMARY KEY,
    config_key VARCHAR(100) NOT NULL UNIQUE,
    config_value TEXT NOT NULL,
    description TEXT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

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
-- 5. MELHORIAS NA TABELA DE USUÁRIOS
-- =====================================================
-- Adicionar campos de atribuição se não existirem
DO $$
BEGIN
    -- Campo para ID do afiliado que indicou
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns 
        WHERE table_name = 'usuarios' AND column_name = 'attributed_affiliate_id'
    ) THEN
        ALTER TABLE usuarios ADD COLUMN attributed_affiliate_id INT NULL;
        ALTER TABLE usuarios ADD INDEX idx_attributed_affiliate (attributed_affiliate_id);
    END IF;
    
    -- Campo para código de referência usado
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns 
        WHERE table_name = 'usuarios' AND column_name = 'ref_code_attributed'
    ) THEN
        ALTER TABLE usuarios ADD COLUMN ref_code_attributed VARCHAR(50) NULL;
        ALTER TABLE usuarios ADD INDEX idx_ref_code_attributed (ref_code_attributed);
    END IF;
    
    -- Campo para data de atribuição
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns 
        WHERE table_name = 'usuarios' AND column_name = 'attributed_at'
    ) THEN
        ALTER TABLE usuarios ADD COLUMN attributed_at TIMESTAMP NULL;
    END IF;
    
    -- Campo para primeiro depósito confirmado
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns 
        WHERE table_name = 'usuarios' AND column_name = 'first_deposit_confirmed'
    ) THEN
        ALTER TABLE usuarios ADD COLUMN first_deposit_confirmed TINYINT(1) DEFAULT 0;
        ALTER TABLE usuarios ADD INDEX idx_first_deposit (first_deposit_confirmed);
    END IF;
    
    -- Campo para valor do primeiro depósito
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns 
        WHERE table_name = 'usuarios' AND column_name = 'first_deposit_amount'
    ) THEN
        ALTER TABLE usuarios ADD COLUMN first_deposit_amount DECIMAL(10,2) NULL;
    END IF;
    
    -- Campo para data do primeiro depósito
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns 
        WHERE table_name = 'usuarios' AND column_name = 'first_deposit_at'
    ) THEN
        ALTER TABLE usuarios ADD COLUMN first_deposit_at TIMESTAMP NULL;
    END IF;
END $$;

-- =====================================================
-- 6. MELHORIAS NA TABELA DE TRANSAÇÕES PIX
-- =====================================================
DO $$
BEGIN
    -- Campo para ID do afiliado
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns 
        WHERE table_name = 'transacoes_pix' AND column_name = 'affiliate_id'
    ) THEN
        ALTER TABLE transacoes_pix ADD COLUMN affiliate_id INT NULL;
        ALTER TABLE transacoes_pix ADD INDEX idx_affiliate_id (affiliate_id);
    END IF;
    
    -- Campo para código de referência
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns 
        WHERE table_name = 'transacoes_pix' AND column_name = 'ref_code'
    ) THEN
        ALTER TABLE transacoes_pix ADD COLUMN ref_code VARCHAR(50) NULL;
        ALTER TABLE transacoes_pix ADD INDEX idx_ref_code (ref_code);
    END IF;
    
    -- Campo para data de atribuição
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns 
        WHERE table_name = 'transacoes_pix' AND column_name = 'attributed_at'
    ) THEN
        ALTER TABLE transacoes_pix ADD COLUMN attributed_at TIMESTAMP NULL;
    END IF;
    
    -- Campo para marcar se é primeiro depósito
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns 
        WHERE table_name = 'transacoes_pix' AND column_name = 'is_first_deposit'
    ) THEN
        ALTER TABLE transacoes_pix ADD COLUMN is_first_deposit TINYINT(1) DEFAULT 0;
        ALTER TABLE transacoes_pix ADD INDEX idx_first_deposit (is_first_deposit);
    END IF;
END $$;

-- =====================================================
-- 7. MELHORIAS NA TABELA DE COMISSÕES
-- =====================================================
DO $$
BEGIN
    -- Campo para ID do clique original
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns 
        WHERE table_name = 'comissoes' AND column_name = 'click_id'
    ) THEN
        ALTER TABLE comissoes ADD COLUMN click_id INT NULL;
        ALTER TABLE comissoes ADD INDEX idx_click_id (click_id);
    END IF;
    
    -- Campo para transaction_id do pagamento
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns 
        WHERE table_name = 'comissoes' AND column_name = 'transaction_id'
    ) THEN
        ALTER TABLE comissoes ADD COLUMN transaction_id VARCHAR(255) NULL;
        ALTER TABLE comissoes ADD INDEX idx_transaction_id (transaction_id);
    END IF;
    
    -- Campo para código de referência
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns 
        WHERE table_name = 'comissoes' AND column_name = 'ref_code'
    ) THEN
        ALTER TABLE comissoes ADD COLUMN ref_code VARCHAR(50) NULL;
        ALTER TABLE comissoes ADD INDEX idx_ref_code (ref_code);
    END IF;
    
    -- Campo para marcar se é primeiro depósito
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns 
        WHERE table_name = 'comissoes' AND column_name = 'is_first_deposit'
    ) THEN
        ALTER TABLE comissoes ADD COLUMN is_first_deposit TINYINT(1) DEFAULT 0;
    END IF;
END $$;

-- =====================================================
-- 8. VIEWS PARA RELATÓRIOS
-- =====================================================

-- View para relatório completo de afiliados
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
-- 9. TRIGGERS PARA MANTER CONSISTÊNCIA
-- =====================================================

-- Trigger para atualizar estatísticas quando um clique converte
DELIMITER $$
CREATE TRIGGER IF NOT EXISTS tr_affiliate_click_convert
    AFTER UPDATE ON affiliate_clicks
    FOR EACH ROW
BEGIN
    IF NEW.converteu = 1 AND OLD.converteu = 0 THEN
        -- Atualizar estatísticas do afiliado se necessário
        UPDATE usuarios 
        SET total_indicados = (
            SELECT COUNT(*) FROM usuarios u2 
            WHERE u2.attributed_affiliate_id = NEW.affiliate_id
        )
        WHERE id = NEW.affiliate_id;
    END IF;
END$$
DELIMITER ;

-- =====================================================
-- 10. ÍNDICES ADICIONAIS PARA PERFORMANCE
-- =====================================================

-- Índices compostos para consultas frequentes
CREATE INDEX IF NOT EXISTS idx_usuarios_affiliate_active ON usuarios(codigo_afiliado, afiliado_ativo);
CREATE INDEX IF NOT EXISTS idx_transacoes_user_status ON transacoes_pix(usuario_id, status);
CREATE INDEX IF NOT EXISTS idx_comissoes_affiliate_status ON comissoes(afiliado_id, status);

-- Índices para relatórios por data
CREATE INDEX IF NOT EXISTS idx_affiliate_clicks_date_ref ON affiliate_clicks(created_at, ref_code);
CREATE INDEX IF NOT EXISTS idx_affiliate_attributions_date ON affiliate_attributions(created_at, affiliate_id);
CREATE INDEX IF NOT EXISTS idx_payment_callbacks_date ON payment_callbacks(processed_at, provider);

-- =====================================================
-- 11. MIGRAÇÃO DE DADOS EXISTENTES (SE NECESSÁRIO)
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
-- 12. LIMPEZA E OTIMIZAÇÃO
-- =====================================================

-- Remover registros de cliques muito antigos (opcional, manter últimos 6 meses)
-- DELETE FROM affiliate_clicks WHERE created_at < DATE_SUB(NOW(), INTERVAL 6 MONTH);

-- Otimizar tabelas
OPTIMIZE TABLE affiliate_clicks;
OPTIMIZE TABLE affiliate_attributions;
OPTIMIZE TABLE payment_callbacks;
OPTIMIZE TABLE usuarios;
OPTIMIZE TABLE transacoes_pix;
OPTIMIZE TABLE comissoes;