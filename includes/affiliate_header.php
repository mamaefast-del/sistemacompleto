<?php
/**
 * Header de Rastreamento de Afiliados
 * Incluir no início de todas as páginas públicas
 */

// Verificar se já foi inicializado nesta requisição
if (!defined('AFFILIATE_TRACKER_INITIALIZED')) {
    define('AFFILIATE_TRACKER_INITIALIZED', true);
    
    // Incluir dependências
    require_once __DIR__ . '/affiliate_tracker.php';
    
    // Inicializar sessão se não estiver ativa
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    // Rastrear clique de afiliado se houver parâmetros
    if (!empty($_GET['ref']) || !empty($_GET['codigo']) || !empty($_GET['affiliate']) || 
        !empty($_GET['utm_source']) || !empty($_GET['utm_medium'])) {
        
        try {
            $clickId = trackAffiliateClick($pdo);
            if ($clickId) {
                // Log do clique capturado
                error_log("Clique de afiliado capturado: ID $clickId");
            }
        } catch (Exception $e) {
            error_log("Erro ao rastrear clique de afiliado: " . $e->getMessage());
        }
    }
}
?>