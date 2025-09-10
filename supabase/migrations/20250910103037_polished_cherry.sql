-- =====================================================
-- BANCO DE DADOS CAIXASUPRESA - SCHEMA COMPLETO
-- Sistema de Caixas Premiadas + Sistema de Afiliados
-- Versão: 2.0 - Atualizado com Sistema de Afiliados
-- =====================================================

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET AUTOCOMMIT = 0;
START TRANSACTION;
SET time_zone = "+00:00";

-- Configurações de charset
/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

-- =====================================================
-- ESTRUTURA PRINCIPAL DO SISTEMA
-- =====================================================

-- Tabela de administradores
CREATE TABLE `admins` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nome` varchar(255) NOT NULL,
  `email` varchar(255) NOT NULL,
  `senha` varchar(255) NOT NULL,
  `ativo` tinyint(1) DEFAULT 1,
  `data_criacao` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Inserir admin padrão
INSERT INTO `admins` (`nome`, `email`, `senha`, `ativo`) VALUES
('Administrador', 'admin@gmail.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 1);

-- Tabela de usuários (expandida com sistema de afiliados)
CREATE TABLE `usuarios` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nome` varchar(255) NOT NULL,
  `email` varchar(255) NOT NULL,
  `senha` varchar(255) NOT NULL,
  `telefone` varchar(20) DEFAULT NULL,
  `saldo` decimal(10,2) DEFAULT 0.00,
  `ativo` tinyint(1) DEFAULT 1,
  `conta_demo` tinyint(1) DEFAULT 0,
  `data_cadastro` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `ultimo_login` timestamp NULL DEFAULT NULL,
  
  -- Sistema de Afiliados Original
  `codigo_afiliado` varchar(50) DEFAULT NULL,
  `codigo_afiliado_usado` varchar(50) DEFAULT NULL,
  `porcentagem_afiliado` decimal(5,2) DEFAULT 10.00,
  `afiliado_ativo` tinyint(1) DEFAULT 0,
  `saldo_comissao` decimal(10,2) DEFAULT 0.00,
  `comissao` decimal(10,2) DEFAULT 0.00,
  `total_indicados` int(11) DEFAULT 0,
  `total_comissao_gerada` decimal(10,2) DEFAULT 0.00,
  `data_aprovacao_afiliado` timestamp NULL DEFAULT NULL,
  
  -- Sistema de Afiliados Aprimorado
  `attributed_affiliate_id` int(11) DEFAULT NULL,
  `ref_code_attributed` varchar(50) DEFAULT NULL,
  `attributed_at` timestamp NULL DEFAULT NULL,
  `first_deposit_confirmed` tinyint(1) DEFAULT 0,
  `first_deposit_amount` decimal(10,2) DEFAULT NULL,
  `first_deposit_at` timestamp NULL DEFAULT NULL,
  
  -- Campos de controle
  `percentual_ganho` decimal(5,2) DEFAULT NULL,
  `codigo_convite` varchar(50) DEFAULT NULL,
  
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`),
  UNIQUE KEY `codigo_afiliado` (`codigo_afiliado`),
  KEY `idx_codigo_afiliado_usado` (`codigo_afiliado_usado`),
  KEY `idx_afiliado_ativo` (`afiliado_ativo`),
  KEY `idx_attributed_affiliate` (`attributed_affiliate_id`),
  KEY `idx_ref_code_attributed` (`ref_code_attributed`),
  KEY `idx_first_deposit` (`first_deposit_confirmed`),
  KEY `idx_usuarios_affiliate_active` (`codigo_afiliado`, `afiliado_ativo`),
  FOREIGN KEY (`attributed_affiliate_id`) REFERENCES `usuarios`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabela de configurações gerais
CREATE TABLE `configuracoes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `min_deposito` decimal(10,2) DEFAULT 5.00,
  `max_deposito` decimal(10,2) DEFAULT 10000.00,
  `min_saque` decimal(10,2) DEFAULT 30.00,
  `max_saque` decimal(10,2) DEFAULT 350.00,
  `bonus_deposito` decimal(5,2) DEFAULT 0.00,
  `valor_comissao` decimal(5,2) DEFAULT 10.00,
  `valor_comissao_n2` decimal(5,2) DEFAULT 5.00,
  `min_saque_comissao` decimal(10,2) DEFAULT 10.00,
  `max_saque_comissao` decimal(10,2) DEFAULT 1000.00,
  `rollover_multiplicador` decimal(5,2) DEFAULT 2.00,
  `data_atualizacao` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Inserir configuração padrão
INSERT INTO `configuracoes` (`id`, `min_deposito`, `max_deposito`, `min_saque`, `max_saque`, `bonus_deposito`, `valor_comissao`, `valor_comissao_n2`, `min_saque_comissao`, `max_saque_comissao`) VALUES
(1, 5.00, 10000.00, 30.00, 350.00, 0.00, 10.00, 5.00, 10.00, 1000.00);

-- Tabela de gateways de pagamento
CREATE TABLE `gateways` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nome` varchar(100) NOT NULL,
  `client_id` varchar(255) DEFAULT NULL,
  `client_secret` varchar(255) DEFAULT NULL,
  `callback_url` varchar(500) DEFAULT NULL,
  `ativo` tinyint(1) DEFAULT 0,
  `data_criacao` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `data_atualizacao` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `nome` (`nome`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Inserir gateway ExpfyPay padrão
INSERT INTO `gateways` (`nome`, `ativo`) VALUES
('expfypay', 1);

-- Tabela de transações PIX (expandida com afiliados)
CREATE TABLE `transacoes_pix` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `usuario_id` int(11) NOT NULL,
  `telefone` varchar(20) DEFAULT NULL,
  `valor` decimal(10,2) NOT NULL,
  `external_id` varchar(255) NOT NULL,
  `transaction_id` varchar(255) DEFAULT NULL,
  `qr_code` text DEFAULT NULL,
  `status` enum('pendente','aprovado','rejeitado','expirado') DEFAULT 'pendente',
  `conta_recebedora` varchar(100) DEFAULT NULL,
  `criado_em` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `atualizado_em` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  
  -- Campos do Sistema de Afiliados
  `affiliate_id` int(11) DEFAULT NULL,
  `ref_code` varchar(50) DEFAULT NULL,
  `attributed_at` timestamp NULL DEFAULT NULL,
  `is_first_deposit` tinyint(1) DEFAULT 0,
  
  PRIMARY KEY (`id`),
  UNIQUE KEY `external_id` (`external_id`),
  UNIQUE KEY `transaction_id` (`transaction_id`),
  KEY `idx_usuario_id` (`usuario_id`),
  KEY `idx_status` (`status`),
  KEY `idx_criado_em` (`criado_em`),
  KEY `idx_affiliate_id` (`affiliate_id`),
  KEY `idx_ref_code` (`ref_code`),
  KEY `idx_first_deposit` (`is_first_deposit`),
  KEY `idx_transacoes_user_status` (`usuario_id`, `status`),
  FOREIGN KEY (`usuario_id`) REFERENCES `usuarios`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`affiliate_id`) REFERENCES `usuarios`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabela de saques
CREATE TABLE `saques` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `usuario_id` int(11) NOT NULL,
  `valor` decimal(10,2) NOT NULL,
  `chave_pix` varchar(255) NOT NULL,
  `tipo_chave` varchar(50) DEFAULT NULL,
  `nome` varchar(255) DEFAULT NULL,
  `taxId` varchar(20) DEFAULT NULL,
  `external_id` varchar(255) DEFAULT NULL,
  `transaction_id` varchar(255) DEFAULT NULL,
  `status` enum('pendente','aprovado','recusado','erro_api') DEFAULT 'pendente',
  `tipo` enum('saldo','comissao') DEFAULT 'saldo',
  `data` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `aprovado_em` timestamp NULL DEFAULT NULL,
  `recusado_em` timestamp NULL DEFAULT NULL,
  `data_processamento` timestamp NULL DEFAULT NULL,
  `observacoes` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_usuario_id` (`usuario_id`),
  KEY `idx_status` (`status`),
  KEY `idx_tipo` (`tipo`),
  KEY `idx_data` (`data`),
  FOREIGN KEY (`usuario_id`) REFERENCES `usuarios`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabela de configuração de jogos/raspadinhas
CREATE TABLE `raspadinhas_config` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nome` varchar(255) NOT NULL,
  `valor` decimal(10,2) NOT NULL,
  `chance_ganho` decimal(5,2) DEFAULT 50.00,
  `premios_json` longtext DEFAULT NULL,
  `moeda` varchar(10) DEFAULT 'R$',
  `ativa` tinyint(1) DEFAULT 1,
  `data_criacao` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `data_atualizacao` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ativa` (`ativa`),
  KEY `idx_valor` (`valor`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Inserir configurações padrão de jogos
INSERT INTO `raspadinhas_config` (`id`, `nome`, `valor`, `chance_ganho`, `premios_json`, `ativa`) VALUES
(1, 'Caixa Iniciante', 1.00, 80.00, '[{"nome":"R$ 2","valor":2,"chance":30,"imagem":"images/caixa/2.webp"},{"nome":"R$ 5","valor":5,"chance":20,"imagem":"images/caixa/5.webp"},{"nome":"R$ 10","valor":10,"chance":10,"imagem":"images/caixa/10.webp"},{"nome":"R$ 50","valor":50,"chance":5,"imagem":"images/caixa/50.webp"},{"nome":"Não Ganhou","valor":0,"chance":35,"imagem":""}]', 1),
(2, 'Caixa Intermediária', 5.00, 75.00, '[{"nome":"R$ 10","valor":10,"chance":25,"imagem":"images/caixa/10.webp"},{"nome":"R$ 25","valor":25,"chance":20,"imagem":"images/caixa/25.webp"},{"nome":"R$ 100","valor":100,"chance":15,"imagem":"images/caixa/100.webp"},{"nome":"R$ 500","valor":500,"chance":5,"imagem":"images/caixa/500.webp"},{"nome":"Não Ganhou","valor":0,"chance":35,"imagem":""}]', 1),
(3, 'Caixa Premium', 15.00, 70.00, '[{"nome":"R$ 50","valor":50,"chance":20,"imagem":"images/caixa/50.webp"},{"nome":"R$ 150","valor":150,"chance":15,"imagem":"images/caixa/150.webp"},{"nome":"R$ 500","valor":500,"chance":10,"imagem":"images/caixa/500.webp"},{"nome":"R$ 2000","valor":2000,"chance":3,"imagem":"images/caixa/2000.webp"},{"nome":"Não Ganhou","valor":0,"chance":52,"imagem":""}]', 1),
(4, 'Caixa VIP', 50.00, 65.00, '[{"nome":"R$ 100","valor":100,"chance":15,"imagem":"images/caixa/100.webp"},{"nome":"R$ 500","valor":500,"chance":12,"imagem":"images/caixa/500.webp"},{"nome":"R$ 2000","valor":2000,"chance":8,"imagem":"images/caixa/2000.webp"},{"nome":"R$ 10000","valor":10000,"chance":2,"imagem":"images/caixa/10000.webp"},{"nome":"Não Ganhou","valor":0,"chance":63,"imagem":""}]', 1),
(5, 'Caixa Especial', 1.00, 85.00, '[{"nome":"R$ 3","valor":3,"chance":25,"imagem":"images/caixa/3.webp"},{"nome":"R$ 7","valor":7,"chance":20,"imagem":"images/caixa/7.webp"},{"nome":"R$ 25","valor":25,"chance":10,"imagem":"images/caixa/25.webp"},{"nome":"R$ 100","valor":100,"chance":5,"imagem":"images/caixa/100.webp"},{"nome":"Não Ganhou","valor":0,"chance":40,"imagem":""}]', 1);

-- Tabela de histórico de jogos
CREATE TABLE `historico_jogos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `usuario_id` int(11) NOT NULL,
  `raspadinha_id` int(11) DEFAULT NULL,
  `valor_apostado` decimal(10,2) NOT NULL,
  `valor_premiado` decimal(10,2) DEFAULT 0.00,
  `data_jogo` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_usuario_id` (`usuario_id`),
  KEY `idx_raspadinha_id` (`raspadinha_id`),
  KEY `idx_data_jogo` (`data_jogo`),
  FOREIGN KEY (`usuario_id`) REFERENCES `usuarios`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`raspadinha_id`) REFERENCES `raspadinhas_config`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabela de rollover
CREATE TABLE `rollover` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `usuario_id` int(11) NOT NULL,
  `valor_deposito` decimal(10,2) NOT NULL,
  `valor_necessario` decimal(10,2) NOT NULL,
  `valor_acumulado` decimal(10,2) DEFAULT 0.00,
  `finalizado` tinyint(1) DEFAULT 0,
  `criado_em` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `finalizado_em` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_usuario_id` (`usuario_id`),
  KEY `idx_finalizado` (`finalizado`),
  FOREIGN KEY (`usuario_id`) REFERENCES `usuarios`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- SISTEMA DE AFILIADOS APRIMORADO
-- =====================================================

-- Tabela de cliques de afiliados
CREATE TABLE `affiliate_clicks` (
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
  KEY `idx_affiliate_clicks_date_ref` (`created_at`, `ref_code`),
  FOREIGN KEY (`affiliate_id`) REFERENCES `usuarios`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`usuario_convertido_id`) REFERENCES `usuarios`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabela de atribuições de afiliados
CREATE TABLE `affiliate_attributions` (
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
  KEY `idx_affiliate_attributions_date` (`created_at`, `affiliate_id`),
  FOREIGN KEY (`user_id`) REFERENCES `usuarios`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`affiliate_id`) REFERENCES `usuarios`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`click_id`) REFERENCES `affiliate_clicks`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabela de callbacks de pagamento
CREATE TABLE `payment_callbacks` (
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
  KEY `idx_status` (`status`),
  FOREIGN KEY (`user_id`) REFERENCES `usuarios`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabela de configurações do sistema de afiliados
CREATE TABLE `affiliate_config` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `config_key` varchar(100) NOT NULL,
  `config_value` text NOT NULL,
  `description` text DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `config_key` (`config_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Inserir configurações padrão do sistema de afiliados
INSERT INTO `affiliate_config` (`config_key`, `config_value`, `description`) VALUES
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

-- Tabela de comissões (expandida)
CREATE TABLE `comissoes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `afiliado_id` int(11) NOT NULL,
  `usuario_indicado_id` int(11) NOT NULL,
  `valor_transacao` decimal(10,2) NOT NULL,
  `valor_comissao` decimal(10,2) NOT NULL,
  `porcentagem_aplicada` decimal(5,2) NOT NULL,
  `nivel` tinyint(1) DEFAULT 1,
  `tipo` enum('deposito','jogada','bonus') DEFAULT 'deposito',
  `status` enum('pendente','pago','cancelado') DEFAULT 'pendente',
  `data_criacao` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `data_pagamento` timestamp NULL DEFAULT NULL,
  
  -- Campos do Sistema de Afiliados Aprimorado
  `click_id` int(11) DEFAULT NULL,
  `transaction_id` varchar(255) DEFAULT NULL,
  `ref_code` varchar(50) DEFAULT NULL,
  `is_first_deposit` tinyint(1) DEFAULT 0,
  
  PRIMARY KEY (`id`),
  KEY `idx_afiliado_id` (`afiliado_id`),
  KEY `idx_usuario_indicado_id` (`usuario_indicado_id`),
  KEY `idx_status` (`status`),
  KEY `idx_data_criacao` (`data_criacao`),
  KEY `idx_nivel` (`nivel`),
  KEY `idx_tipo` (`tipo`),
  KEY `idx_click_id` (`click_id`),
  KEY `idx_transaction_id` (`transaction_id`),
  KEY `idx_ref_code` (`ref_code`),
  KEY `idx_comissoes_affiliate_status` (`afiliado_id`, `status`),
  FOREIGN KEY (`afiliado_id`) REFERENCES `usuarios`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`usuario_indicado_id`) REFERENCES `usuarios`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`click_id`) REFERENCES `affiliate_clicks`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabela de histórico de afiliados
CREATE TABLE `historico_afiliados` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `afiliado_id` int(11) NOT NULL,
  `acao` varchar(100) NOT NULL,
  `detalhes` text DEFAULT NULL,
  `data_acao` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_afiliado_id` (`afiliado_id`),
  KEY `idx_acao` (`acao`),
  KEY `idx_data_acao` (`data_acao`),
  FOREIGN KEY (`afiliado_id`) REFERENCES `usuarios`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- TABELAS DE SISTEMA E LOGS
-- =====================================================

-- Tabela de logs de webhook
CREATE TABLE `webhook_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `transaction_id` varchar(255) DEFAULT NULL,
  `external_id` varchar(255) DEFAULT NULL,
  `payload` longtext DEFAULT NULL,
  `status` varchar(50) DEFAULT NULL,
  `data_recebimento` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_transaction_id` (`transaction_id`),
  KEY `idx_external_id` (`external_id`),
  KEY `idx_data_recebimento` (`data_recebimento`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabela de logs administrativos
CREATE TABLE `log_admin` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `acao` varchar(255) NOT NULL,
  `detalhes` text DEFAULT NULL,
  `data` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_acao` (`acao`),
  KEY `idx_data` (`data`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabela de cores do site
CREATE TABLE `cores_site` (
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

-- Inserir cores padrão
INSERT INTO `cores_site` (`cor_primaria`, `cor_secundaria`, `cor_azul`, `cor_verde`, `cor_fundo`, `cor_painel`) VALUES
('#fbce00', '#f4c430', '#007fdb', '#00e880', '#0a0b0f', '#111318');

-- Tabela de configuração de pixels
CREATE TABLE `pixels_config` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `plataforma` varchar(50) NOT NULL,
  `pixel_id` varchar(255) DEFAULT NULL,
  `codigo_head` text DEFAULT NULL,
  `codigo_body` text DEFAULT NULL,
  `ativo` tinyint(1) DEFAULT 1,
  `data_atualizacao` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Inserir pixels padrão
INSERT INTO `pixels_config` (`id`, `plataforma`, `ativo`) VALUES
(1, 'Facebook Pixel', 1),
(2, 'Google Analytics', 1),
(3, 'Google Ads', 1),
(4, 'TikTok Pixel', 1),
(5, 'Kwai Ads', 1);

-- Tabela de splits do gateway
CREATE TABLE `gateway_splits` (
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

-- Inserir split padrão
INSERT INTO `gateway_splits` (`email`, `porcentagem`, `descricao`) VALUES
('levicarimbo@gmail.com', 5.0, 'Split principal do gateway');

-- Tabela de configuração de demo
CREATE TABLE `demo_config` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `saldo_inicial_demo` decimal(10,2) DEFAULT 1000.00,
  `percentual_ganho_demo` decimal(5,2) DEFAULT 80.00,
  `limite_diario_demo` decimal(10,2) DEFAULT 500.00,
  `duracao_demo` int(11) DEFAULT 7,
  `data_atualizacao` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Inserir configuração demo padrão
INSERT INTO `demo_config` (`saldo_inicial_demo`, `percentual_ganho_demo`, `limite_diario_demo`, `duracao_demo`) VALUES
(1000.00, 80.00, 500.00, 7);

-- Tabela de depósitos (se necessário para compatibilidade)
CREATE TABLE `depositos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `usuario_id` int(11) NOT NULL,
  `valor` decimal(10,2) NOT NULL,
  `metodo` varchar(50) DEFAULT 'PIX',
  `status` enum('pendente','aprovado','rejeitado') DEFAULT 'pendente',
  `comprovante` text DEFAULT NULL,
  `data_solicitacao` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `data_processamento` timestamp NULL DEFAULT NULL,
  `observacoes` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_usuario_id` (`usuario_id`),
  KEY `idx_status` (`status`),
  KEY `idx_data_solicitacao` (`data_solicitacao`),
  FOREIGN KEY (`usuario_id`) REFERENCES `usuarios`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabela de materiais de marketing
CREATE TABLE `marketing_materials` (
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
-- VIEWS PARA RELATÓRIOS
-- =====================================================

-- View para relatório completo de afiliados
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
-- TRIGGERS PARA MANTER CONSISTÊNCIA
-- =====================================================

DELIMITER $$

-- Trigger para atualizar estatísticas quando um clique converte
CREATE TRIGGER `tr_affiliate_click_convert`
    AFTER UPDATE ON `affiliate_clicks`
    FOR EACH ROW
BEGIN
    IF NEW.converteu = 1 AND OLD.converteu = 0 THEN
        -- Atualizar estatísticas do afiliado
        UPDATE usuarios 
        SET total_indicados = (
            SELECT COUNT(*) FROM usuarios u2 
            WHERE u2.attributed_affiliate_id = NEW.affiliate_id
        )
        WHERE id = NEW.affiliate_id;
    END IF;
END$$

-- Trigger para atualizar comissão quando status muda
CREATE TRIGGER `tr_comissao_status_update`
    AFTER UPDATE ON `comissoes`
    FOR EACH ROW
BEGIN
    IF NEW.status != OLD.status THEN
        IF NEW.status = 'pago' AND OLD.status = 'pendente' THEN
            -- Mover da comissão pendente para saldo de comissão
            UPDATE usuarios 
            SET comissao = comissao - NEW.valor_comissao,
                saldo_comissao = saldo_comissao + NEW.valor_comissao
            WHERE id = NEW.afiliado_id;
        ELSEIF NEW.status = 'pendente' AND OLD.status = 'pago' THEN
            -- Reverter se necessário
            UPDATE usuarios 
            SET comissao = comissao + NEW.valor_comissao,
                saldo_comissao = saldo_comissao - NEW.valor_comissao
            WHERE id = NEW.afiliado_id;
        END IF;
    END IF;
END$$

DELIMITER ;

-- =====================================================
-- DADOS DE EXEMPLO PARA TESTE
-- =====================================================

-- Usuário afiliado de exemplo
INSERT INTO `usuarios` (`id`, `nome`, `email`, `senha`, `codigo_afiliado`, `afiliado_ativo`, `porcentagem_afiliado`, `saldo`, `data_cadastro`) VALUES
(672, 'Afiliado Teste', 'afiliado@teste.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'AFIL001', 1, 15.00, 0.00, NOW());

-- Usuário indicado de exemplo
INSERT INTO `usuarios` (`id`, `nome`, `email`, `senha`, `codigo_afiliado_usado`, `attributed_affiliate_id`, `ref_code_attributed`, `attributed_at`, `saldo`, `data_cadastro`) VALUES
(673, 'Usuário Indicado', 'indicado@teste.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'AFIL001', 672, 'AFIL001', NOW(), 0.00, NOW());

-- Atribuição de exemplo
INSERT INTO `affiliate_attributions` (`user_id`, `affiliate_id`, `ref_code`, `attribution_model`, `utm_source`, `utm_medium`) VALUES
(673, 672, 'AFIL001', 'LAST_CLICK', 'facebook', 'social');

-- Clique de exemplo
INSERT INTO `affiliate_clicks` (`affiliate_id`, `ref_code`, `url`, `utm_source`, `utm_medium`, `utm_campaign`, `ip_address`, `session_id`) VALUES
(672, 'AFIL001', '/?ref=AFIL001&utm_source=facebook&utm_medium=social&utm_campaign=promo2025', 'facebook', 'social', 'promo2025', '127.0.0.1', 'test_session_001');

-- =====================================================
-- CONFIGURAÇÕES FINAIS
-- =====================================================

-- Configurar AUTO_INCREMENT para evitar conflitos
ALTER TABLE `usuarios` AUTO_INCREMENT = 1000;
ALTER TABLE `transacoes_pix` AUTO_INCREMENT = 1000;
ALTER TABLE `saques` AUTO_INCREMENT = 1000;
ALTER TABLE `comissoes` AUTO_INCREMENT = 1000;
ALTER TABLE `affiliate_clicks` AUTO_INCREMENT = 1000;
ALTER TABLE `affiliate_attributions` AUTO_INCREMENT = 1000;
ALTER TABLE `payment_callbacks` AUTO_INCREMENT = 1000;

-- Otimizar tabelas
OPTIMIZE TABLE `usuarios`;
OPTIMIZE TABLE `transacoes_pix`;
OPTIMIZE TABLE `comissoes`;
OPTIMIZE TABLE `affiliate_clicks`;
OPTIMIZE TABLE `affiliate_attributions`;
OPTIMIZE TABLE `payment_callbacks`;

COMMIT;

-- =====================================================
-- INFORMAÇÕES DA MIGRAÇÃO
-- =====================================================

SELECT 'Schema CaixaSurpresa v2.0 com Sistema de Afiliados criado com sucesso!' as resultado;
SELECT 'Tabelas principais: usuarios, transacoes_pix, comissoes, affiliate_clicks, affiliate_attributions' as tabelas_principais;
SELECT 'Login admin: admin@gmail.com / senha: admin123' as login_admin;
SELECT 'Afiliado teste: AFIL001 (15% comissão)' as afiliado_teste;
SELECT 'Próximo passo: Configurar webhook ExpfyPay para /webhook_expfypay.php' as proximo_passo;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;