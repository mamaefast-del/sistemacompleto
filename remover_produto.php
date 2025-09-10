<?php
session_start();

// Verificar se é admin
if (!isset($_SESSION['admin']) || $_SESSION['admin'] !== true) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Acesso negado']);
    exit;
}

require 'db.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $packageId = intval($_POST['packageId']);
        $productValue = floatval($_POST['productValue']);
        
        // Não permitir remover o item "Não Ganhou" (valor 0)
        if ($productValue == 0) {
            throw new Exception('Não é possível remover o item "Não Ganhou"');
        }
        
        // Buscar configuração atual do pacote
        $stmt = $pdo->prepare("SELECT premios_json FROM raspadinhas_config WHERE id = ?");
        $stmt->execute([$packageId]);
        $package = $stmt->fetch();
        
        if (!$package) {
            throw new Exception('Pacote não encontrado');
        }
        
        // Decodificar JSON atual
        $premios = json_decode($package['premios_json'], true) ?: [];
        
        // Verificar se o produto existe
        if (!isset($premios[$productValue])) {
            throw new Exception('Produto não encontrado');
        }
        
        // Remover produto
        unset($premios[$productValue]);
        
        // Salvar de volta no banco
        $stmt = $pdo->prepare("UPDATE raspadinhas_config SET premios_json = ? WHERE id = ?");
        $stmt->execute([json_encode($premios), $packageId]);
        
        // Remover imagem se existir
        $imagePaths = [
            "images/caixa/{$productValue}.webp",
            "images/caixa/{$productValue}.png",
            "images/caixa/{$productValue}.jpg"
        ];
        
        foreach ($imagePaths as $imagePath) {
            if (file_exists($imagePath)) {
                unlink($imagePath);
                break;
            }
        }
        
        echo json_encode([
            'success' => true, 
            'message' => 'Produto removido com sucesso!'
        ]);
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
} else {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Método não permitido']);
}
?>