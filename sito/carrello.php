<?php
require_once 'config.php';
checkLogin();

$pdo = getDBConnection();

// Gestione azioni carrello
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['aggiorna_quantita'])) {
        $prodotto_id = $_POST['prodotto_id'];
        $quantita = $_POST['quantita'];
        
        // Verifica disponibilità in magazzino
        if ($quantita > 0) {
            $stmt = $pdo->prepare("
                SELECT np.quantita 
                FROM NegozioPossiede np
                WHERE np.codice_prodotto = ? AND np.codice_negozio = ?
            ");
            $stmt->execute([$prodotto_id, $_SESSION['carrello']['negozio_id']]);
            $quantita_magazzino = $stmt->fetchColumn();
            
            if ($quantita > $quantita_magazzino) {
                setFlashMessage("Quantità non disponibile. Massimo disponibile: $quantita_magazzino", 'error');
                header("Location: carrello.php");
                exit();
            }
        }
        
        foreach ($_SESSION['carrello']['prodotti'] as &$item) {
            if ($item['id'] == $prodotto_id) {
                if ($quantita > 0) {
                    $item['quantita'] = $quantita;
                } else {
                    // Rimuovi se quantità è 0
                    $_SESSION['carrello']['prodotti'] = array_filter(
                        $_SESSION['carrello']['prodotti'],
                        function($p) use ($prodotto_id) { return $p['id'] != $prodotto_id; }
                    );
                    $_SESSION['carrello']['prodotti'] = array_values($_SESSION['carrello']['prodotti']);
                }
                break;
            }
        }
        
        setFlashMessage('Carrello aggiornato', 'success');
        header("Location: carrello.php");
        exit();
    }
    
    if (isset($_POST['rimuovi_prodotto'])) {
        $prodotto_id = $_POST['prodotto_id'];
        
        $_SESSION['carrello']['prodotti'] = array_filter(
            $_SESSION['carrello']['prodotti'],
            function($p) use ($prodotto_id) { return $p['id'] != $prodotto_id; }
        );
        $_SESSION['carrello']['prodotti'] = array_values($_SESSION['carrello']['prodotti']);
        
        setFlashMessage('Prodotto rimosso dal carrello', 'success');
        header("Location: carrello.php");
        exit();
    }
    
    if (isset($_POST['svuota_carrello'])) {
        unset($_SESSION['carrello']);
        setFlashMessage('Carrello svuotato', 'success');
        header("Location: carrello.php");
        exit();
    }
}

// Calcola totale
$totale = 0;
if (isset($_SESSION['carrello']) && !empty($_SESSION['carrello']['prodotti'])) {
    foreach ($_SESSION['carrello']['prodotti'] as $item) {
        $totale += $item['prezzo'] * $item['quantita'];
    }
}

// Recupera info negozio
$negozio_info = null;
if (isset($_SESSION['carrello']['negozio_id'])) {
    $stmt = $pdo->prepare("SELECT * FROM Negozio WHERE codice = ?");
    $stmt->execute([$_SESSION['carrello']['negozio_id']]);
    $negozio_info = $stmt->fetch(PDO::FETCH_ASSOC);
}


$flash = getFlashMessage();
?>

<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Carrello - Catena Negozi</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: Arial, sans-serif;
            background-color: #f4f4f4;
        }
        
        .navbar {
            background-color: #2196F3;
            color: white;
            padding: 15px 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .navbar a {
            color: white;
            text-decoration: none;
            padding: 8px 16px;
            background-color: rgba(0, 0, 0, 0.1);
            border-radius: 4px;
            margin-left: 10px;
        }
        
        .navbar a:hover {
            background-color: rgba(0, 0, 0, 0.2);
        }
        
        .container {
            max-width: 1000px;
            margin: 20px auto;
            padding: 0 20px;
        }
        
        .flash-message {
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 4px;
        }
        
        .flash-success {
            background-color: #d4edda;
            color: #155724;
        }
        
        .flash-error {
            background-color: #f8d7da;
            color: #721c24;
        }
        
        .cart-container {
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            overflow: hidden;
        }
        
        .cart-header {
            background-color: #f8f9fa;
            padding: 20px;
            border-bottom: 1px solid #dee2e6;
        }
        
        .cart-header h2 {
            margin: 0 0 10px 0;
        }
        
        .negozio-info {
            color: #666;
            font-size: 14px;
        }
        
        .cart-items {
            padding: 20px;
        }
        
        .cart-item {
            border-bottom: 1px solid #eee;
            padding: 20px 0;
            display: flex;
            align-items: center;
            gap: 20px;
        }
        
        .cart-item:last-child {
            border-bottom: none;
        }
        
        .item-info {
            flex-grow: 1;
        }
        
        .item-name {
            font-weight: bold;
            margin-bottom: 5px;
        }
        
        .item-price {
            color: #666;
        }
        
        .item-controls {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        input[type="number"] {
            width: 60px;
            padding: 6px;
            border: 1px solid #ddd;
            border-radius: 4px;
        }
        
        .btn-update {
            padding: 6px 12px;
            background-color: #2196F3;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
        }
        
        .btn-remove {
            padding: 6px 12px;
            background-color: #f44336;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
        }
        
        .cart-footer {
            background-color: #f8f9fa;
            padding: 20px;
            border-top: 2px solid #dee2e6;
        }
        
        .totale-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            font-size: 20px;
            font-weight: bold;
        }
        
        .sconto-section {
            background-color: #e3f2fd;
            padding: 15px;
            border-radius: 4px;
            margin-bottom: 20px;
        }
        
        .sconto-options {
            display: flex;
            gap: 15px;
            margin-top: 10px;
        }
        
        .sconto-option {
            display: flex;
            align-items: center;
            gap: 5px;
        }
        
        .sconto-option input[type="radio"] {
            margin: 0;
        }
        
        .sconto-option.disabled {
            opacity: 0.5;
        }
        
        .action-buttons {
            display: flex;
            gap: 15px;
            justify-content: space-between;
        }
        
        .btn-primary {
            padding: 12px 30px;
            background-color: #4CAF50;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 16px;
            font-weight: bold;
            text-decoration: none;
            display: inline-block;
        }
        
        .btn-primary:hover {
            background-color: #45a049;
        }
        
        .btn-secondary {
            padding: 12px 30px;
            background-color: #6c757d;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 16px;
            text-decoration: none;
            display: inline-block;
        }
        
        .empty-cart {
            text-align: center;
            padding: 60px 20px;
            color: #666;
        }
        
        .empty-cart h2 {
            margin-bottom: 20px;
        }
        
        .sconto-preview {
            color: #4CAF50;
            font-size: 16px;
            margin-top: 10px;
        }
    </style>
</head>
<body>
    <nav class="navbar">
        <h1>Il tuo Carrello</h1>
        <div>
            <a href="catalogo_prodotti.php">← Continua shopping</a>
            <a href="dashboard_cliente.php">Dashboard</a>
        </div>
    </nav>
    
    <div class="container">
        <?php if ($flash): ?>
        <div class="flash-message flash-<?php echo $flash['type']; ?>">
            <?php echo htmlspecialchars($flash['message']); ?>
        </div>
        <?php endif; ?>
        
        <?php if (!isset($_SESSION['carrello']) || empty($_SESSION['carrello']['prodotti'])): ?>
        <div class="cart-container">
            <div class="empty-cart">
                <h2>Il tuo carrello è vuoto</h2>
                <p>Aggiungi prodotti dal catalogo per iniziare lo shopping!</p>
                <br>
                <a href="catalogo_prodotti.php" class="btn-primary">Vai al Catalogo</a>
            </div>
        </div>
        <?php else: ?>
        <div class="cart-container">
            <div class="cart-header">
                <h2>Riepilogo Carrello</h2>
                <?php if ($negozio_info): ?>
                <div class="negozio-info">
                    Negozio: <?php echo htmlspecialchars($negozio_info['indirizzo']); ?>
                </div>
                <?php endif; ?>
            </div>
            
            <div class="cart-items">
                <?php foreach ($_SESSION['carrello']['prodotti'] as $item): ?>
                <div class="cart-item">
                    <div class="item-info">
                        <div class="item-name"><?php echo htmlspecialchars($item['nome']); ?></div>
                        <div class="item-price"><?php echo formatPrice($item['prezzo']); ?> cad.</div>
                    </div>
                    
                    <div class="item-controls">
                        <form method="POST" style="display: flex; gap: 10px;">
                            <input type="hidden" name="prodotto_id" value="<?php echo $item['id']; ?>">
                            <?php
                            // Recupera quantità massima disponibile
                            $stmt = $pdo->prepare("
                                SELECT np.quantita 
                                FROM NegozioPossiede np
                                WHERE np.codice_prodotto = ? AND np.codice_negozio = ?
                            ");
                            $stmt->execute([$item['id'], $_SESSION['carrello']['negozio_id']]);
                            $max_disponibile = $stmt->fetchColumn();
                            ?>
                            <input type="number" name="quantita" value="<?php echo $item['quantita']; ?>" 
                                   min="0" max="<?php echo $max_disponibile; ?>">
                            <button type="submit" name="aggiorna_quantita" class="btn-update">Aggiorna</button>
                        </form>
                        
                        <form method="POST">
                            <input type="hidden" name="prodotto_id" value="<?php echo $item['id']; ?>">
                            <button type="submit" name="rimuovi_prodotto" class="btn-remove">Rimuovi</button>
                        </form>
                    </div>
                    
                    <div style="text-align: right; min-width: 100px;">
                        <strong><?php echo formatPrice($item['prezzo'] * $item['quantita']); ?></strong>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            
            <div class="cart-footer">
                <div class="totale-row">
                    <span>Totale:</span>
                    <span id="totale-display"><?php echo formatPrice($totale); ?></span>
                </div>
                
                <div class="action-buttons">
                    <form method="POST" style="display: inline;">
                        <button type="submit" name="svuota_carrello" class="btn-secondary">Svuota Carrello</button>
                    </form>
                    
                    <a href="checkout.php" class="btn-primary">Procedi al Checkout</a>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
</body>
</html>