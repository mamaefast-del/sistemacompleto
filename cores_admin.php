<?php
session_start();
require 'db.php';

if (!isset($_SESSION['admin']) || $_SESSION['admin'] !== true) {
    header('Location: login.php');
    exit;
}

// Criar tabela de cores se não existir
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS cores_site (
        id INT AUTO_INCREMENT PRIMARY KEY,
        cor_primaria VARCHAR(7) DEFAULT '#fbce00',
        cor_secundaria VARCHAR(7) DEFAULT '#f4c430',
        cor_azul VARCHAR(7) DEFAULT '#007fdb',
        cor_verde VARCHAR(7) DEFAULT '#00e880',
        cor_fundo VARCHAR(7) DEFAULT '#0a0b0f',
        cor_painel VARCHAR(7) DEFAULT '#111318',
        atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )");
    
    // Inserir cores padrão se não existir
    $stmt = $pdo->query("SELECT COUNT(*) FROM cores_site");
    if ($stmt->fetchColumn() == 0) {
        $pdo->exec("INSERT INTO cores_site (cor_primaria, cor_secundaria, cor_azul, cor_verde, cor_fundo, cor_painel) VALUES ('#fbce00', '#f4c430', '#007fdb', '#00e880', '#0a0b0f', '#111318')");
    }
} catch (PDOException $e) {
    // Erro na criação da tabela
}

$mensagem = '';

// Processar atualização de cores
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $cor_primaria = $_POST['cor_primaria'] ?? '#fbce00';
        $cor_secundaria = $_POST['cor_secundaria'] ?? '#f4c430';
        $cor_azul = $_POST['cor_azul'] ?? '#007fdb';
        $cor_verde = $_POST['cor_verde'] ?? '#00e880';
        $cor_fundo = $_POST['cor_fundo'] ?? '#0a0b0f';
        $cor_painel = $_POST['cor_painel'] ?? '#111318';
        
        // Validar formato hexadecimal
        $cores = [$cor_primaria, $cor_secundaria, $cor_azul, $cor_verde, $cor_fundo, $cor_painel];
        foreach ($cores as $cor) {
            if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $cor)) {
                throw new Exception('Formato de cor inválido: ' . $cor);
            }
        }
        
        $stmt = $pdo->prepare("UPDATE cores_site SET cor_primaria = ?, cor_secundaria = ?, cor_azul = ?, cor_verde = ?, cor_fundo = ?, cor_painel = ? WHERE id = 1");
        $stmt->execute([$cor_primaria, $cor_secundaria, $cor_azul, $cor_verde, $cor_fundo, $cor_painel]);
        
        // Gerar arquivo CSS dinâmico
        gerarCSSPersonalizado($cor_primaria, $cor_secundaria, $cor_azul, $cor_verde, $cor_fundo, $cor_painel);
        
        $mensagem = 'Cores atualizadas com sucesso!';
        
    } catch (Exception $e) {
        $mensagem = 'Erro ao atualizar cores: ' . $e->getMessage();
    }
}

// Buscar cores atuais
$stmt = $pdo->query("SELECT * FROM cores_site ORDER BY id DESC LIMIT 1");
$cores = $stmt->fetch() ?: [
    'cor_primaria' => '#fbce00',
    'cor_secundaria' => '#f4c430',
    'cor_azul' => '#007fdb',
    'cor_verde' => '#00e880',
    'cor_fundo' => '#0a0b0f',
    'cor_painel' => '#111318'
];

function gerarCSSPersonalizado($primaria, $secundaria, $azul, $verde, $fundo, $painel) {
    $css = "/* Cores personalizadas geradas automaticamente */
:root {
    --primary-gold: {$primaria};
    --secondary-gold: {$secundaria};
    --primary-blue: {$azul};
    --primary-green: {$verde};
    --bg-dark: {$fundo};
    --bg-panel: {$painel};
}

/* Aplicar cores personalizadas */
.btn-primary, .btn-depositar, .saldo, .generate-btn {
    background: linear-gradient(135deg, {$primaria}, {$secundaria}) !important;
}

.footer a.active, .tab.active, i.active {
    color: {$azul} !important;
}

.footer a.deposito-btn {
    background: {$azul} !important;
}

.btn-verde, .status-aprovado {
    background: {$verde} !important;
}

body {
    background: {$fundo} !important;
}

.header, .card, .container {
    background: {$painel} !important;
}

.text-primary {
    color: {$primaria} !important;
}

.border-primary {
    border-color: {$primaria} !important;
}
";
    
    file_put_contents(__DIR__ . '/css/cores-personalizadas.css', $css);
}
?>

<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Personalizar Cores - Admin</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        :root {
            --bg-dark: <?= $cores['cor_fundo'] ?>;
            --bg-panel: <?= $cores['cor_painel'] ?>;
            --primary-gold: <?= $cores['cor_primaria'] ?>;
            --secondary-gold: <?= $cores['cor_secundaria'] ?>;
            --primary-blue: <?= $cores['cor_azul'] ?>;
            --primary-green: <?= $cores['cor_verde'] ?>;
            --text-light: #f0f6fc;
            --text-muted: #8b949e;
            --radius: 12px;
            --transition: 0.3s ease;
            --border-panel: #21262d;
            --shadow-gold: 0 0 20px rgba(251, 206, 0, 0.3);
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, var(--bg-dark) 0%, var(--bg-panel) 100%);
            color: var(--text-light);
            min-height: 100vh;
            padding-top: 80px;
        }

        /* Header */
        .header {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            height: 80px;
            background: rgba(13, 17, 23, 0.95);
            backdrop-filter: blur(10px);
            border-bottom: 2px solid var(--primary-gold);
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 24px;
            z-index: 1000;
            box-shadow: var(--shadow-gold);
        }

        .logo {
            display: flex;
            align-items: center;
            gap: 12px;
            font-size: 20px;
            font-weight: 700;
            color: var(--primary-gold);
            text-decoration: none;
            transition: var(--transition);
        }

        .logo:hover {
            transform: scale(1.05);
            text-shadow: 0 0 10px var(--primary-gold);
        }

        .logo i {
            font-size: 24px;
        }

        .nav-menu {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .nav-item {
            padding: 10px 16px;
            background: var(--bg-panel);
            border: 1px solid var(--border-panel);
            border-radius: var(--radius);
            text-decoration: none;
            color: var(--text-light);
            font-weight: 500;
            transition: var(--transition);
            display: flex;
            align-items: center;
            gap: 8px;
            position: relative;
            overflow: hidden;
        }

        .nav-item:hover {
            background: linear-gradient(135deg, var(--primary-gold), var(--secondary-gold));
            color: #000;
            transform: translateY(-2px);
            box-shadow: var(--shadow-gold);
        }

        .nav-item.active {
            background: linear-gradient(135deg, var(--primary-gold), var(--secondary-gold));
            color: #000;
            box-shadow: var(--shadow-gold);
        }

        .nav-text {
            display: inline;
        }

        .content {
            padding: 40px;
            max-width: 1400px;
            margin: 0 auto;
        }

        h2 {
            font-size: 28px;
            color: var(--primary-gold);
            margin-bottom: 30px;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .card {
            background: var(--bg-panel);
            border: 1px solid var(--border-panel);
            border-radius: var(--radius);
            padding: 24px;
            margin-bottom: 24px;
            transition: var(--transition);
        }

        .card:hover {
            border-color: var(--primary-gold);
            box-shadow: var(--shadow-gold);
        }

        .card h3 {
            color: var(--primary-gold);
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 20px;
        }

        .color-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .color-item {
            background: var(--bg-dark);
            border: 1px solid var(--border-panel);
            border-radius: var(--radius);
            padding: 20px;
            transition: var(--transition);
        }

        .color-item:hover {
            border-color: var(--primary-gold);
            transform: translateY(-2px);
        }

        .color-item label {
            display: block;
            font-weight: 600;
            color: var(--text-light);
            margin-bottom: 12px;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .color-input-group {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .color-input {
            width: 60px;
            height: 40px;
            border: 2px solid var(--border-panel);
            border-radius: 8px;
            cursor: pointer;
            transition: var(--transition);
        }

        .color-input:hover {
            border-color: var(--primary-gold);
            transform: scale(1.05);
        }

        .color-text {
            flex: 1;
            padding: 10px 16px;
            background: var(--bg-panel);
            border: 1px solid var(--border-panel);
            border-radius: 8px;
            color: var(--text-light);
            font-family: monospace;
            font-size: 14px;
            text-transform: uppercase;
        }

        .color-text:focus {
            outline: none;
            border-color: var(--primary-gold);
            box-shadow: 0 0 0 3px rgba(251, 206, 0, 0.1);
        }

        .color-preview {
            width: 40px;
            height: 40px;
            border-radius: 8px;
            border: 2px solid var(--border-panel);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
            font-weight: 600;
            color: #000;
        }

        .btn {
            padding: 16px 32px;
            background: linear-gradient(135deg, var(--primary-gold), var(--secondary-gold));
            color: #000;
            font-weight: 700;
            border: none;
            border-radius: var(--radius);
            cursor: pointer;
            transition: var(--transition);
            font-size: 16px;
            box-shadow: 0 2px 8px rgba(251, 206, 0, 0.3);
            display: inline-flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
            margin-top: 20px;
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(251, 206, 0, 0.4);
        }

        .btn-reset {
            background: linear-gradient(135deg, #ef4444, #dc2626);
            color: white;
            margin-left: 12px;
        }

        .btn-reset:hover {
            box-shadow: 0 4px 12px rgba(239, 68, 68, 0.4);
        }

        .preview-section {
            background: var(--bg-dark);
            border: 2px solid var(--primary-gold);
            border-radius: var(--radius);
            padding: 24px;
            margin-top: 30px;
        }

        .preview-section h4 {
            color: var(--primary-gold);
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .preview-elements {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
        }

        .preview-btn {
            padding: 12px 20px;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }

        .preview-primary {
            background: linear-gradient(135deg, var(--primary-gold), var(--secondary-gold));
            color: #000;
        }

        .preview-blue {
            background: var(--primary-blue);
            color: white;
        }

        .preview-green {
            background: var(--primary-green);
            color: #000;
        }

        .message {
            padding: 16px 20px;
            border-radius: var(--radius);
            margin-bottom: 24px;
            display: flex;
            align-items: center;
            gap: 12px;
            font-weight: 500;
            animation: slideInDown 0.4s ease;
        }

        .message.success {
            background: rgba(34, 197, 94, 0.15);
            border: 1px solid rgba(34, 197, 94, 0.3);
            color: #22c55e;
        }

        .message.error {
            background: rgba(239, 68, 68, 0.15);
            border: 1px solid rgba(239, 68, 68, 0.3);
            color: #ef4444;
        }

        @keyframes slideInDown {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .info-box {
            background: rgba(251, 206, 0, 0.1);
            border: 1px solid var(--primary-gold);
            border-radius: var(--radius);
            padding: 16px;
            margin-bottom: 24px;
            color: var(--text-light);
        }

        .info-box i {
            color: var(--primary-gold);
            margin-right: 8px;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .header {
                padding: 0 16px;
            }

            .nav-text {
                display: none;
            }

            .nav-item {
                padding: 10px;
            }

            .content {
                padding: 20px;
            }

            .color-grid {
                grid-template-columns: 1fr;
            }

            .preview-elements {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>

<body>
    <!-- Header -->
    <div class="header">
        <a href="painel_admin.php" class="logo">
            <i class="fas fa-crown"></i>
            <span>Admin Panel</span>
        </a>
        
        <nav class="nav-menu">
            <a href="painel_admin.php" class="nav-item">
                <i class="fas fa-tachometer-alt"></i>
                <span class="nav-text">Dashboard</span>
            </a>
            <a href="configuracoes_admin.php" class="nav-item">
                <i class="fas fa-cog"></i>
                <span class="nav-text">Configurações</span>
            </a>
            <a href="cores_admin.php" class="nav-item active">
                <i class="fas fa-palette"></i>
                <span class="nav-text">Cores</span>
            </a>
            <a href="usuarios_admin.php" class="nav-item">
                <i class="fas fa-users"></i>
                <span class="nav-text">Usuários</span>
            </a>
            <a href="premios_admin.php" class="nav-item">
                <i class="fas fa-gift"></i>
                <span class="nav-text">Prêmios</span>
            </a>
            <a href="saques_admin.php" class="nav-item">
                <i class="fas fa-money-bill-wave"></i>
                <span class="nav-text">Saques</span>
            </a>
            <a href="gateways_admin.php" class="nav-item">
                <i class="fas fa-credit-card"></i>
                <span class="nav-text">Gateways</span>
            </a>
            <a href="pix_admin.php" class="nav-item">
                <i class="fas fa-exchange-alt"></i>
                <span class="nav-text">Transações</span>
            </a>
        </nav>
    </div>

    <div class="content">
        <h2>
            <i class="fas fa-palette"></i>
            Personalizar Cores do Site
        </h2>

        <?php if ($mensagem): ?>
            <div class="message <?= strpos($mensagem, 'sucesso') !== false ? 'success' : 'error' ?>">
                <i class="fas fa-<?= strpos($mensagem, 'sucesso') !== false ? 'check-circle' : 'exclamation-circle' ?>"></i>
                <?= htmlspecialchars($mensagem) ?>
            </div>
        <?php endif; ?>

        <div class="info-box">
            <i class="fas fa-info-circle"></i>
            <strong>Importante:</strong> As alterações de cores serão aplicadas em todo o site. 
            Após salvar, as mudanças aparecerão imediatamente em todas as páginas.
        </div>

        <form method="POST">
            <div class="card">
                <h3>
                    <i class="fas fa-paint-brush"></i>
                    Configurar Cores Principais
                </h3>
                
                <div class="color-grid">
                    <div class="color-item">
                        <label for="cor_primaria">
                            <i class="fas fa-star"></i>
                            Cor Primária (Dourado)
                        </label>
                        <div class="color-input-group">
                            <input type="color" 
                                   id="cor_primaria" 
                                   name="cor_primaria" 
                                   value="<?= $cores['cor_primaria'] ?>" 
                                   class="color-input"
                                   onchange="updatePreview('primaria', this.value)">
                            <input type="text" 
                                   class="color-text" 
                                   value="<?= $cores['cor_primaria'] ?>" 
                                   id="cor_primaria_text"
                                   onchange="updateColorPicker('cor_primaria', this.value)">
                            <div class="color-preview" 
                                 id="preview_primaria" 
                                 style="background: <?= $cores['cor_primaria'] ?>">
                                ★
                            </div>
                        </div>
                    </div>

                    <div class="color-item">
                        <label for="cor_secundaria">
                            <i class="fas fa-adjust"></i>
                            Cor Secundária (Gradiente)
                        </label>
                        <div class="color-input-group">
                            <input type="color" 
                                   id="cor_secundaria" 
                                   name="cor_secundaria" 
                                   value="<?= $cores['cor_secundaria'] ?>" 
                                   class="color-input"
                                   onchange="updatePreview('secundaria', this.value)">
                            <input type="text" 
                                   class="color-text" 
                                   value="<?= $cores['cor_secundaria'] ?>" 
                                   id="cor_secundaria_text"
                                   onchange="updateColorPicker('cor_secundaria', this.value)">
                            <div class="color-preview" 
                                 id="preview_secundaria" 
                                 style="background: <?= $cores['cor_secundaria'] ?>">
                                ◐
                            </div>
                        </div>
                    </div>

                    <div class="color-item">
                        <label for="cor_azul">
                            <i class="fas fa-link"></i>
                            Cor Azul (Links/Ativos)
                        </label>
                        <div class="color-input-group">
                            <input type="color" 
                                   id="cor_azul" 
                                   name="cor_azul" 
                                   value="<?= $cores['cor_azul'] ?>" 
                                   class="color-input"
                                   onchange="updatePreview('azul', this.value)">
                            <input type="text" 
                                   class="color-text" 
                                   value="<?= $cores['cor_azul'] ?>" 
                                   id="cor_azul_text"
                                   onchange="updateColorPicker('cor_azul', this.value)">
                            <div class="color-preview" 
                                 id="preview_azul" 
                                 style="background: <?= $cores['cor_azul'] ?>">
                                🔗
                            </div>
                        </div>
                    </div>

                    <div class="color-item">
                        <label for="cor_verde">
                            <i class="fas fa-check-circle"></i>
                            Cor Verde (Sucesso)
                        </label>
                        <div class="color-input-group">
                            <input type="color" 
                                   id="cor_verde" 
                                   name="cor_verde" 
                                   value="<?= $cores['cor_verde'] ?>" 
                                   class="color-input"
                                   onchange="updatePreview('verde', this.value)">
                            <input type="text" 
                                   class="color-text" 
                                   value="<?= $cores['cor_verde'] ?>" 
                                   id="cor_verde_text"
                                   onchange="updateColorPicker('cor_verde', this.value)">
                            <div class="color-preview" 
                                 id="preview_verde" 
                                 style="background: <?= $cores['cor_verde'] ?>">
                                ✓
                            </div>
                        </div>
                    </div>

                    <div class="color-item">
                        <label for="cor_fundo">
                            <i class="fas fa-fill"></i>
                            Cor de Fundo
                        </label>
                        <div class="color-input-group">
                            <input type="color" 
                                   id="cor_fundo" 
                                   name="cor_fundo" 
                                   value="<?= $cores['cor_fundo'] ?>" 
                                   class="color-input"
                                   onchange="updatePreview('fundo', this.value)">
                            <input type="text" 
                                   class="color-text" 
                                   value="<?= $cores['cor_fundo'] ?>" 
                                   id="cor_fundo_text"
                                   onchange="updateColorPicker('cor_fundo', this.value)">
                            <div class="color-preview" 
                                 id="preview_fundo" 
                                 style="background: <?= $cores['cor_fundo'] ?>">
                                ■
                            </div>
                        </div>
                    </div>

                    <div class="color-item">
                        <label for="cor_painel">
                            <i class="fas fa-window-maximize"></i>
                            Cor dos Painéis
                        </label>
                        <div class="color-input-group">
                            <input type="color" 
                                   id="cor_painel" 
                                   name="cor_painel" 
                                   value="<?= $cores['cor_painel'] ?>" 
                                   class="color-input"
                                   onchange="updatePreview('painel', this.value)">
                            <input type="text" 
                                   class="color-text" 
                                   value="<?= $cores['cor_painel'] ?>" 
                                   id="cor_painel_text"
                                   onchange="updateColorPicker('cor_painel', this.value)">
                            <div class="color-preview" 
                                 id="preview_painel" 
                                 style="background: <?= $cores['cor_painel'] ?>">
                                ▢
                            </div>
                        </div>
                    </div>
                </div>

                <div class="preview-section">
                    <h4>
                        <i class="fas fa-eye"></i>
                        Pré-visualização
                    </h4>
                    <div class="preview-elements">
                        <button type="button" class="preview-btn preview-primary">
                            <i class="fas fa-star"></i>
                            Botão Primário
                        </button>
                        <button type="button" class="preview-btn preview-blue">
                            <i class="fas fa-link"></i>
                            Botão Azul
                        </button>
                        <button type="button" class="preview-btn preview-green">
                            <i class="fas fa-check"></i>
                            Botão Verde
                        </button>
                    </div>
                </div>

                <div style="display: flex; gap: 12px; margin-top: 30px;">
                    <button type="submit" class="btn">
                        <i class="fas fa-save"></i>
                        Salvar Cores
                    </button>
                    
                    <button type="button" class="btn btn-reset" onclick="resetarCores()">
                        <i class="fas fa-undo"></i>
                        Restaurar Padrão
                    </button>
                </div>
            </div>
        </form>
    </div>

    <script>
        function updatePreview(tipo, cor) {
            const preview = document.getElementById('preview_' + tipo);
            const textInput = document.getElementById('cor_' + tipo + '_text');
            
            preview.style.background = cor;
            textInput.value = cor.toUpperCase();
            
            // Atualizar CSS em tempo real
            document.documentElement.style.setProperty('--primary-' + (tipo === 'primaria' ? 'gold' : tipo === 'secundaria' ? 'gold' : tipo), cor);
            
            if (tipo === 'primaria') {
                document.documentElement.style.setProperty('--primary-gold', cor);
            } else if (tipo === 'secundaria') {
                document.documentElement.style.setProperty('--secondary-gold', cor);
            } else if (tipo === 'azul') {
                document.documentElement.style.setProperty('--primary-blue', cor);
            } else if (tipo === 'verde') {
                document.documentElement.style.setProperty('--primary-green', cor);
            } else if (tipo === 'fundo') {
                document.documentElement.style.setProperty('--bg-dark', cor);
            } else if (tipo === 'painel') {
                document.documentElement.style.setProperty('--bg-panel', cor);
            }
        }

        function updateColorPicker(inputId, cor) {
            if (/^#[0-9A-Fa-f]{6}$/.test(cor)) {
                document.getElementById(inputId).value = cor;
                const tipo = inputId.replace('cor_', '');
                updatePreview(tipo, cor);
            }
        }

        function resetarCores() {
            if (confirm('Tem certeza que deseja restaurar as cores padrão?')) {
                const coresPadrao = {
                    'cor_primaria': '#fbce00',
                    'cor_secundaria': '#f4c430',
                    'cor_azul': '#007fdb',
                    'cor_verde': '#00e880',
                    'cor_fundo': '#0a0b0f',
                   'cor_painel': '#111318',
                   'cor_texto': '#ffffff'
                };
                
                Object.keys(coresPadrao).forEach(cor => {
                    document.getElementById(cor).value = coresPadrao[cor];
                    document.getElementById(cor + '_text').value = coresPadrao[cor];
                    const tipo = cor.replace('cor_', '');
                    updatePreview(tipo, coresPadrao[cor]);
                });
                
                // Aplicar cores imediatamente
                aplicarCoresImediatamente();
            }
        }
        
        function aplicarCoresImediatamente() {
            // Aplicar cores em tempo real no painel admin
            const cores = {
                primaria: document.getElementById('cor_primaria').value,
                secundaria: document.getElementById('cor_secundaria').value,
                azul: document.getElementById('cor_azul').value,
                verde: document.getElementById('cor_verde').value,
                fundo: document.getElementById('cor_fundo').value,
                painel: document.getElementById('cor_painel').value
            };
            
            // Atualizar CSS customizado
            fetch('salvar_cores_ajax.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify(cores)
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Recarregar CSS dinâmico
                    const link = document.querySelector('link[href*="cores-dinamicas.css"]');
                    if (link) {
                        link.href = 'css/cores-dinamicas.css?v=' + Date.now();
                    }
                }
            })
            .catch(error => console.error('Erro ao aplicar cores:', error));
        }

        // Sincronizar inputs de texto com color pickers
        document.addEventListener('DOMContentLoaded', function() {
            const colorInputs = document.querySelectorAll('.color-text');
            colorInputs.forEach(input => {
                input.addEventListener('input', function() {
                    const cor = this.value;
                    if (/^#[0-9A-Fa-f]{6}$/.test(cor)) {
                        const tipo = this.id.replace('cor_', '').replace('_text', '');
                        updateColorPicker('cor_' + tipo, cor);
                    }
                });
            });
            
            // Aplicar cores ao carregar
            aplicarCoresImediatamente();
        });
    </script>
</body>
</html>