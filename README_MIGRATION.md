# 🚀 Migração do Sistema de Afiliados

## 📋 Resumo

Este documento descreve o processo de fusão do esquema novo de afiliados com o banco MySQL existente, de forma **100% aditiva** e **backwards-compatible**.

## 🎯 Objetivos Alcançados

- ✅ Rastreamento completo de cliques com UTMs
- ✅ Atribuição automática no cadastro (FIRST_CLICK/LAST_CLICK)
- ✅ Processamento de primeiro depósito via ExpfyPay
- ✅ Idempotência nos webhooks
- ✅ Relatórios administrativos completos
- ✅ Zero perda de dados existentes

## 📁 Arquivos Criados

### Scripts de Migração
- `scripts/create_backups.php` - Criação de backups automáticos
- `scripts/merge_schema.php` - Fusão inteligente de esquemas
- `scripts/post_merge_validation.php` - Validação pós-migração
- `scripts/show_create_tables.php` - Documentação das estruturas
- `run_migration.php` - Script principal de execução

### SQL Manual
- `bd/manual_migration.sql` - Script SQL para execução manual

### Sistema de Afiliados
- `includes/affiliate_tracker.php` - Classe principal de rastreamento
- `webhook_expfypay.php` - Endpoint para callbacks ExpfyPay
- `includes/affiliate_header.php` - Header para páginas públicas
- `admin_affiliate_reports.php` - Relatórios administrativos
- `affiliate_config_admin.php` - Configurações do sistema
- `test_affiliate_system.php` - Testes automatizados

## 🗄️ Estrutura do Banco

### Novas Tabelas
```sql
affiliate_clicks          -- Rastreamento de cliques
affiliate_attributions    -- Atribuições de usuários  
payment_callbacks         -- Logs de callbacks
affiliate_config          -- Configurações do sistema
```

### Colunas Adicionadas
```sql
-- usuarios
attributed_affiliate_id   -- ID do afiliado que indicou
ref_code_attributed      -- Código de referência usado
attributed_at            -- Data da atribuição
first_deposit_confirmed  -- Primeiro depósito confirmado
first_deposit_amount     -- Valor do primeiro depósito
first_deposit_at         -- Data do primeiro depósito

-- transacoes_pix  
affiliate_id             -- ID do afiliado
ref_code                 -- Código de referência
attributed_at            -- Data da atribuição
is_first_deposit         -- Marca primeiro depósito

-- comissoes (se existir)
click_id                 -- ID do clique original
transaction_id           -- ID da transação
ref_code                 -- Código de referência
is_first_deposit         -- Marca primeiro depósito
```

## 🚀 Como Executar

### Opção 1: Script Automatizado (Recomendado)
```bash
php run_migration.php
```

### Opção 2: SQL Manual
```bash
mysql -u caixasupresa -p caixasupresa < bd/manual_migration.sql
```

### Opção 3: Scripts Individuais
```bash
php scripts/create_backups.php
php scripts/merge_schema.php
php scripts/post_merge_validation.php
php scripts/show_create_tables.php
```

## 🔧 Configuração Pós-Migração

### 1. Configurar Webhook ExpfyPay
- **Endpoint:** `https://seudominio.com/webhook_expfypay.php`
- **Método:** POST
- **Header:** X-Signature (HMAC SHA256)

### 2. Configurar Sistema
Acesse `affiliate_config_admin.php` e configure:
- Duração do cookie (padrão: 30 dias)
- Modelo de atribuição (FIRST_CLICK/LAST_CLICK)
- Taxas de comissão (Nível 1: 10%, Nível 2: 5%)
- Limites de saque (Min: R$ 10, Max: R$ 1.000)

### 3. Integrar nas Páginas
As páginas principais já foram atualizadas:
- `index.php` - Página inicial com rastreamento
- `menu.php` - Menu de pacotes
- `deposito.php` - Página de depósito
- `register_ajax.php` - Cadastro com atribuição

## 🧪 Testes

### Teste Automatizado
```bash
php test_affiliate_system.php
```

### Teste Manual
1. Acesse: `/?ref=AFIL001&utm_source=facebook`
2. Faça um cadastro
3. Realize um depósito
4. Verifique em `admin_affiliate_reports.php`

## 📊 Relatórios

### Dashboard Administrativo
- **URL:** `admin_affiliate_reports.php`
- **Métricas:** Cliques, conversões, depósitos, comissões
- **Filtros:** Data, afiliado, UTM source
- **Export:** CSV completo

### KPIs Disponíveis
- Total de cliques por afiliado
- Taxa de conversão clique → cadastro
- Taxa de conversão cadastro → depósito
- Volume de primeiro depósito
- Comissões geradas e pendentes

## 🔒 Segurança

### Validações Implementadas
- ✅ Sanitização de códigos de referência
- ✅ Validação de assinatura HMAC nos webhooks
- ✅ Prepared statements em todas as queries
- ✅ Cookies seguros com SameSite=Lax
- ✅ Idempotência por transaction_id

### Logs de Auditoria
- `logs/webhook_expfypay.log` - Logs detalhados de webhooks
- `payment_callbacks` - Registro de todos os callbacks
- `affiliate_clicks` - Histórico completo de cliques

## 🔄 Compatibilidade

### Backwards Compatibility
- ✅ Todas as funcionalidades existentes mantidas
- ✅ Nenhuma alteração destrutiva
- ✅ Campos antigos preservados (`codigo_afiliado_usado`)
- ✅ APIs existentes inalteradas

### Versões MySQL
- ✅ MySQL 5.7+ (com verificações condicionais)
- ✅ MySQL 8.0+ (com IF NOT EXISTS nativo)
- ✅ MariaDB 10.3+

## 📈 Performance

### Índices Otimizados
- Consultas de afiliados: `idx_usuarios_affiliate_active`
- Relatórios de cliques: `idx_affiliate_clicks_date_ref`
- Busca de transações: `idx_transacoes_user_status`
- Atribuições: `unique_user_attribution`

### Queries Otimizadas
- Views pré-calculadas para relatórios
- Índices compostos para filtros frequentes
- Paginação eficiente nos relatórios

## 🆘 Troubleshooting

### Problemas Comuns

**Erro de Foreign Key:**
```sql
SET FOREIGN_KEY_CHECKS = 0;
-- Execute a migração
SET FOREIGN_KEY_CHECKS = 1;
```

**Coluna já existe:**
- O script verifica automaticamente via INFORMATION_SCHEMA
- Execução é idempotente e segura

**Webhook não funciona:**
1. Verificar assinatura HMAC
2. Conferir logs em `logs/webhook_expfypay.log`
3. Validar configuração em `affiliate_config`

### Rollback (Se Necessário)
```bash
# Restaurar backup completo
mysql -u caixasupresa -p caixasupresa < backups/backup_pre_merge_*.sql

# Ou remover apenas as novas tabelas
DROP TABLE IF EXISTS affiliate_clicks, affiliate_attributions, payment_callbacks, affiliate_config;
DROP VIEW IF EXISTS view_affiliate_report;
```

## 📞 Suporte

Para dúvidas ou problemas:
1. Verificar logs em `backups/merge_report_*.txt`
2. Executar `test_affiliate_system.php` para diagnóstico
3. Consultar `admin_affiliate_reports.php` para métricas

---

**Status:** ✅ Migração Pronta para Produção  
**Compatibilidade:** 100% Backwards Compatible  
**Perda de Dados:** Zero  
**Testes:** Automatizados e Manuais Disponíveis