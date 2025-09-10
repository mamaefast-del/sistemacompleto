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
        $productName = trim($_POST['productName']);
        $productValue = floatval($_POST['productValue']);
        $productChance = floatval($_POST['productChance']);
        
        // Validações
        if (empty($productName)) {
            throw new Exception('Nome do produto é obrigatório');
        }
        
        if ($productValue <= 0) {
            throw new Exception('Valor do produto deve ser maior que zero');
        }
        
        if ($productChance < 0 || $productChance > 100) {
            throw new Exception('Chance deve estar entre 0 e 100%');
        }
        
        // Buscar configuração atual do pacote
        $stmt = $pdo->prepare("SELECT premios_json FROM raspadinhas_config WHERE id = ?");
        $stmt->execute([$packageId]);
        $package = $stmt->fetch();
        
        if (!$package) {
            throw new Exception('Pacote não encontrado');
        }
        
        // Decodificar JSON atual
        $premios = json_decode($package['premios_json'], true);
        if (!is_array($premios)) {
            $premios = [];
        }
        
        // Verificar se o valor já existe
        if (isset($premios[strval($productValue)])) {
            throw new Exception('Já existe um produto com este valor neste pacote');
        }
        
        // Adicionar novo produto
        $premios[strval($productValue)] = $productChance;
        
        // Salvar de volta no banco
        $stmt = $pdo->prepare("UPDATE raspadinhas_config SET premios_json = ? WHERE id = ?");
        $result = $stmt->execute([json_encode($premios), $packageId]);
        
        if (!$result) {
            throw new Exception('Erro ao salvar no banco de dados');
        }
        
        // Processar upload de imagem se fornecida
        if (isset($_FILES['productImage']) && $_FILES['productImage']['error'] === UPLOAD_ERR_OK) {
            $uploadDir = 'images/caixa/';
            
            // Criar diretório se não existir
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }
            
            $imageExtension = strtolower(pathinfo($_FILES['productImage']['name'], PATHINFO_EXTENSION));
            $allowedExtensions = ['jpg', 'jpeg', 'png', 'webp'];
            
            if (in_array($imageExtension, $allowedExtensions)) {
                $imageName = $productValue . '.webp';
                $imagePath = $uploadDir . $imageName;
                
                // Verificar se GD está disponível
                if (extension_loaded('gd')) {
                    // Converter para WebP se necessário
                    $sourceImage = null;
                    switch ($imageExtension) {
                        case 'jpg':
                        case 'jpeg':
                            $sourceImage = imagecreatefromjpeg($_FILES['productImage']['tmp_name']);
                            break;
                        case 'png':
                            $sourceImage = imagecreatefrompng($_FILES['productImage']['tmp_name']);
                            break;
                        case 'webp':
                            $sourceImage = imagecreatefromwebp($_FILES['productImage']['tmp_name']);
                            break;
                    }
                    
                    if ($sourceImage) {
                        // Redimensionar para 300x300
                        $resized = imagecreatetruecolor(300, 300);
                        imagecopyresampled($resized, $sourceImage, 0, 0, 0, 0, 300, 300, imagesx($sourceImage), imagesy($sourceImage));
                        
                        // Salvar como WebP
                        imagewebp($resized, $imagePath, 90);
                        
                        // Limpar memória
                        imagedestroy($sourceImage);
                        imagedestroy($resized);
                    }
                } else {
                    // Se GD não estiver disponível, apenas mover o arquivo
                    move_uploaded_file($_FILES['productImage']['tmp_name'], $imagePath);
                }
            }
        }
        
        echo json_encode([
            'success' => true, 
            'message' => 'Produto adicionado com sucesso!',
            'product' => [
                'name' => $productName,
                'value' => $productValue,
                'chance' => $productChance
            ]
        ]);
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    } catch (Error $e) {
        echo json_encode(['success' => false, 'message' => 'Erro interno: ' . $e->getMessage()]);
    }
} else {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Método não permitido']);
}
?>