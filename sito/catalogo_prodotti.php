<?php
require_once 'config.php';
checkLogin();

$pdo = getDBConnection();

// Recupera negozi disponibili
$stmt = $pdo->query("SELECT codice, indirizzo FROM Negozio ORDER BY indirizzo");
$negozi = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Filtro per negozio
$negozio_selezionato = $_GET['negozio'] ?? '';

// Funzione per calcolare la disponibilità effettiva considerando il carrello
function getDisponibilitaEffettiva($prodotto_id, $negozio_id, $quantita_db) {
    if (isset($_SESSION['carrello']) && $_SESSION['carrello']['negozio_id'] == $negozio_id) {
        foreach ($_SESSION['carrello']['prodotti'] as $item) {
            if ($item['id'] == $prodotto_id) {
                return $quantita_db - $item['quantita'];
            }
        }
    }
    return $quantita_db;
}

// Recupera prodotti
if ($negozio_selezionato) {
    // Usa la funzione SQL get_prodotti_negozio
    $stmt = $pdo->prepare("SELECT * FROM get_prodotti_negozio(?)");
    $stmt->execute([$negozio_selezionato]);
    $prodotti = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Aggiusta la disponibilità per considerare il carrello
    foreach ($prodotti as $key => $prodotto) {
        $prodotti[$key]['quantita_disponibile'] = getDisponibilitaEffettiva(
            $prodotto['codice'], 
            $negozio_selezionato, 
            $prodotto['quantita']
        );
    }
    unset($prodotto); // Importante: distruggi il riferimento
} else {
    // Mostra tutti i prodotti con range prezzi
    $stmt = $pdo->query("
        SELECT 
            p.codice, 
            p.nome, 
            p.descrizione,
            MIN(np.prezzo) as prezzo_min, 
            MAX(np.prezzo) as prezzo_max,
            COUNT(DISTINCT np.codice_negozio) as disponibile_in_negozi
        FROM Prodotto p
        JOIN NegozioPossiede np ON p.codice = np.codice_prodotto
        WHERE np.quantita > 0
        GROUP BY p.codice, p.nome, p.descrizione
        ORDER BY p.nome
    ");
    $prodotti = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Aggiungi al carrello
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['aggiungi_carrello'])) {
    $prodotto_id = $_POST['prodotto_id'];
    $quantita = $_POST['quantita'];
    $negozio_id = $_POST['negozio_id'];
    
    // Verifica disponibilità effettiva
    $stmt = $pdo->prepare("
        SELECT np.quantita 
        FROM NegozioPossiede np
        WHERE np.codice_prodotto = ? AND np.codice_negozio = ?
    ");
    $stmt->execute([$prodotto_id, $negozio_id]);
    $quantita_db = $stmt->fetchColumn();
    
    $quantita_disponibile = getDisponibilitaEffettiva($prodotto_id, $negozio_id, $quantita_db);
    
    if ($quantita > $quantita_disponibile) {
        setFlashMessage("Quantità non disponibile. Massimo disponibile: $quantita_disponibile", 'error');
        header("Location: catalogo_prodotti.php?negozio=$negozio_selezionato");
        exit();
    }
    
    // Inizializza carrello se non esiste
    if (!isset($_SESSION['carrello'])) {
        $_SESSION['carrello'] = [
            'negozio_id' => $negozio_id,
            'prodotti' => []
        ];
    }
    
    // Verifica se il negozio è lo stesso
    if ($_SESSION['carrello']['negozio_id'] != $negozio_id) {
        setFlashMessage('Il carrello contiene prodotti di un altro negozio. Svuota il carrello prima di aggiungere prodotti da un negozio diverso.', 'error');
    } else {
        // Recupera info prodotto
        $stmt = $pdo->prepare("
            SELECT p.nome, np.prezzo 
            FROM Prodotto p
            JOIN NegozioPossiede np ON p.codice = np.codice_prodotto
            WHERE p.codice = ? AND np.codice_negozio = ?
        ");
        $stmt->execute([$prodotto_id, $negozio_id]);
        $prodotto_info = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Aggiungi o aggiorna quantità
        $trovato = false;
        foreach ($_SESSION['carrello']['prodotti'] as &$item) {
            if ($item['id'] == $prodotto_id) {
                // Verifica che la quantità totale non superi la disponibilità
                if ($item['quantita'] + $quantita > $quantita_db) {
                    $max_aggiungibili = $quantita_db - $item['quantita'];
                    setFlashMessage("Puoi aggiungere massimo $max_aggiungibili pezzi di questo prodotto", 'error');
                    header("Location: catalogo_prodotti.php?negozio=$negozio_selezionato");
                    exit();
                }
                $item['quantita'] += $quantita;
                $trovato = true;
                break;
            }
        }
        
        if (!$trovato) {
            $_SESSION['carrello']['prodotti'][] = [
                'id' => $prodotto_id,
                'nome' => $prodotto_info['nome'],
                'prezzo' => $prodotto_info['prezzo'],
                'quantita' => $quantita
            ];
        }
        
        setFlashMessage('Prodotto aggiunto al carrello!', 'success');
    }
    
    header("Location: catalogo_prodotti.php" . ($negozio_selezionato ? "?negozio=$negozio_selezionato" : ""));
    exit();
}

$flash = getFlashMessage();
?>

<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Catalogo Prodotti - Catena Negozi</title>
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
            max-width: 1200px;
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
            border: 1px solid #c3e6cb;
        }
        
        .flash-error {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        
        .filter-section {
            background: white;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }
        
        .filter-section form {
            display: flex;
            gap: 15px;
            align-items: center;
        }
        
        select {
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 16px;
        }
        
        button {
            padding: 8px 20px;
            background-color: #2196F3;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 16px;
        }
        
        button:hover {
            background-color: #1976D2;
        }
        
        button:disabled {
            background-color: #ccc;
            cursor: not-allowed;
        }
        
        .products-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 20px;
        }
        
        .product-card {
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            padding: 20px;
            display: flex;
            flex-direction: column;
        }
        
        .product-card.unavailable {
            opacity: 0.7;
            background-color: #f8f9fa;
        }
        
        .product-card h3 {
            color: #333;
            margin-bottom: 10px;
        }
        
        .product-card .description {
            color: #666;
            font-size: 14px;
            margin-bottom: 15px;
            flex-grow: 1;
        }
        
        .product-card .price {
            font-size: 24px;
            font-weight: bold;
            color: #2196F3;
            margin-bottom: 15px;
        }
        
        .product-card .price-range {
            font-size: 18px;
            color: #666;
            margin-bottom: 15px;
        }
        
        .product-card .availability {
            color: #4CAF50;
            font-size: 14px;
            margin-bottom: 10px;
        }
        
        .product-card .availability.low-stock {
            color: #ff9800;
        }
        
        .product-card .availability.out-of-stock {
            color: #f44336;
        }
        
        .product-card .in-cart-info {
            background-color: #e3f2fd;
            color: #1565c0;
            padding: 8px;
            border-radius: 4px;
            font-size: 13px;
            margin-bottom: 10px;
        }
        
        .product-card form {
            display: flex;
            gap: 10px;
            align-items: center;
        }
        
        .product-card input[type="number"] {
            width: 60px;
            padding: 6px;
            border: 1px solid #ddd;
            border-radius: 4px;
        }
        
        .btn-add-cart {
            background-color: #4CAF50;
            flex-grow: 1;
        }
        
        .btn-add-cart:hover {
            background-color: #45a049;
        }
        
        .no-products {
            background: white;
            padding: 40px;
            text-align: center;
            border-radius: 8px;
            color: #666;
        }
        
        .info-message {
            background-color: #e3f2fd;
            color: #1565c0;
            padding: 15px;
            border-radius: 4px;
            margin-bottom: 20px;
        }
        
        .cart-info {
            position: fixed;
            bottom: 20px;
            right: 20px;
            background-color: #ff9800;
            color: white;
            padding: 15px 20px;
            border-radius: 50px;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.2);
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .cart-info:hover {
            background-color: #f57c00;
        }
        
        .available-in {
            color: #999;
            font-size: 12px;
            margin-top: 5px;
        }
    </style>
</head>
<body>
    <nav class="navbar">
        <h1>Catalogo Prodotti</h1>
        <div>
            <a href="dashboard_cliente.php">← Dashboard</a>
            <a href="carrello.php">🛒 Carrello</a>
        </div>
    </nav>
    
    <div class="container">
        <?php if ($flash): ?>
        <div class="flash-message flash-<?php echo $flash['type']; ?>">
            <?php echo htmlspecialchars($flash['message']); ?>
        </div>
        <?php endif; ?>
        
        <div class="filter-section">
            <form method="GET">
                <label for="negozio">Seleziona negozio:</label>
                <select name="negozio" id="negozio" onchange="this.form.submit()">
                    <option value="">-- Tutti i negozi --</option>
                    <?php foreach ($negozi as $negozio): ?>
                    <option value="<?php echo $negozio['codice']; ?>" 
                            <?php echo $negozio_selezionato == $negozio['codice'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($negozio['indirizzo']); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </form>
        </div>
        
        <?php if (!$negozio_selezionato): ?>
        <div class="info-message">
            Seleziona un negozio per vedere i prezzi esatti e poter aggiungere prodotti al carrello.
        </div>
        <?php endif; ?>
        
        <?php if (empty($prodotti)): ?>
        <div class="no-products">
            <h2>Nessun prodotto disponibile</h2>
            <p>Seleziona un altro negozio o riprova più tardi.</p>
        </div>
        <?php else: ?>
        <div class="products-grid">
            <?php foreach ($prodotti as $prodotto): ?>
            <div class="product-card <?php echo ($negozio_selezionato && $prodotto['quantita_disponibile'] <= 0) ? 'unavailable' : ''; ?>">
                <h3><?php echo htmlspecialchars($prodotto['nome']); ?></h3>
                <p class="description"><?php echo htmlspecialchars($prodotto['descrizione']); ?></p>
                
                <?php if ($negozio_selezionato): ?>
                    <div class="price"><?php echo formatPrice($prodotto['prezzo']); ?></div>
                    
                    <?php 
                    // Mostra info su quantità nel carrello
                    $quantita_nel_carrello = 0;
                    if (isset($_SESSION['carrello']) && $_SESSION['carrello']['negozio_id'] == $negozio_selezionato) {
                        foreach ($_SESSION['carrello']['prodotti'] as $item) {
                            if ($item['id'] == $prodotto['codice']) {
                                $quantita_nel_carrello = $item['quantita'];
                                break;
                            }
                        }
                    }
                    
                    if ($quantita_nel_carrello > 0): ?>
                        <div class="in-cart-info">
                            🛒 Nel carrello: <?php echo $quantita_nel_carrello; ?> pezzi
                        </div>
                    <?php endif; ?>
                    
                    <?php if ($prodotto['quantita_disponibile'] <= 0): ?>
                        <div class="availability out-of-stock">
                            Esaurito (tutto nel carrello)
                        </div>
                        <button disabled class="btn-add-cart">Non disponibile</button>
                    <?php else: ?>
                        <div class="availability <?php echo $prodotto['quantita_disponibile'] <= 5 ? 'low-stock' : ''; ?>">
                            Disponibili: <?php echo $prodotto['quantita_disponibile']; ?> pezzi
                            <?php if ($prodotto['quantita'] != $prodotto['quantita_disponibile']): ?>
                                (totale: <?php echo $prodotto['quantita']; ?>)
                            <?php endif; ?>
                        </div>
                        
                        <form method="POST">
                            <input type="hidden" name="prodotto_id" value="<?php echo $prodotto['codice']; ?>">
                            <input type="hidden" name="negozio_id" value="<?php echo $negozio_selezionato; ?>">
                            <input type="number" name="quantita" value="1" min="1" 
                                   max="<?php echo $prodotto['quantita_disponibile']; ?>" required>
                            <button type="submit" name="aggiungi_carrello" class="btn-add-cart">
                                Aggiungi al carrello
                            </button>
                        </form>
                    <?php endif; ?>
                <?php else: ?>
                    <div class="price-range">
                        Da <?php echo formatPrice($prodotto['prezzo_min']); ?> 
                        a <?php echo formatPrice($prodotto['prezzo_max']); ?>
                    </div>
                    <div class="available-in">
                        Disponibile in <?php echo $prodotto['disponibile_in_negozi']; ?> negozi
                    </div>
                    <p style="color: #999; font-size: 14px; margin-top: 10px;">
                        Seleziona un negozio per acquistare
                    </p>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
    
    <?php if (isset($_SESSION['carrello']) && !empty($_SESSION['carrello']['prodotti'])): ?>
    <a href="carrello.php" class="cart-info">
        🛒 <?php echo count($_SESSION['carrello']['prodotti']); ?> prodotti nel carrello
    </a>
    <?php endif; ?>
</body>
</html>