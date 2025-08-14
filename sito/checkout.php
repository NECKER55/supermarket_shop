<?php
require_once 'config.php';
checkLogin();

// Verifica che ci sia qualcosa nel carrello
if (!isset($_SESSION['carrello']) || empty($_SESSION['carrello']['prodotti'])) {
    header("Location: carrello.php");
    exit();
}

$pdo = getDBConnection();

// Calcola totale
$totale = 0;
foreach ($_SESSION['carrello']['prodotti'] as $item) {
    $totale += $item['prezzo'] * $item['quantita'];
}

// Recupera info negozio
$stmt = $pdo->prepare("SELECT * FROM Negozio WHERE codice = ?");
$stmt->execute([$_SESSION['carrello']['negozio_id']]);
$negozio_info = $stmt->fetch(PDO::FETCH_ASSOC);

// Verifica sconti disponibili usando la funzione del database
$stmt = $pdo->prepare("SELECT * FROM verifica_sconto_disponibile(?)");
$stmt->execute([$_SESSION['cf']]);
$sconti_disponibili = $stmt->fetch(PDO::FETCH_ASSOC);

// Processa ordine
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['conferma_ordine'])) {
    $sconto_selezionato = $_POST['sconto'] ?? 0;
    
    try {
        $pdo->beginTransaction();
        
        $stmt = $pdo->prepare("
            INSERT INTO Fattura (cf_cliente, codice_negozio, totale, sconto)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([
            $_SESSION['cf'],
            $_SESSION['carrello']['negozio_id'],
            $totale, // sarà aggiornato dal trigger se c'è sconto
            $sconto_selezionato
        ]);
        
        $codice_fattura = $pdo->lastInsertId();
        
        // Inserisci prodotti
        foreach ($_SESSION['carrello']['prodotti'] as $item) {
            $stmt = $pdo->prepare("
                INSERT INTO FatturaContiene (codice_fattura, codice_prodotto, quantita, prezzo)
                VALUES (?, ?, ?, ?)
            ");
            $stmt->execute([
                $codice_fattura,
                $item['id'],
                $item['quantita'],
                $item['prezzo']
            ]);
        }
        
        // Se c'è uno sconto, aggiorna la fattura per attivare il trigger
        if ($sconto_selezionato > 0) {
            $stmt = $pdo->prepare("
                UPDATE Fattura 
                SET sconto = ? 
                WHERE codice = ?
            ");
            $stmt->execute([$sconto_selezionato, $codice_fattura]);
        }
        
        $pdo->commit();
        
        // Svuota carrello
        unset($_SESSION['carrello']);
        
        // Messaggio di successo
        setFlashMessage("Acquisto completato con successo! Codice fattura: $codice_fattura", 'success');
        header("Location: dashboard_cliente.php");
        exit();
        
    } catch (PDOException $e) {
        $pdo->rollBack();
        $error = "Errore durante l'acquisto: " . $e->getMessage();
    }
}

?>

<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Checkout - Catena Negozi</title>
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
        }
        
        .container {
            max-width: 800px;
            margin: 20px auto;
            padding: 0 20px;
        }
        
        .checkout-container {
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            overflow: hidden;
        }
        
        .checkout-header {
            background-color: #f8f9fa;
            padding: 20px;
            border-bottom: 1px solid #dee2e6;
        }
        
        .section {
            padding: 20px;
            border-bottom: 1px solid #eee;
        }
        
        .section:last-child {
            border-bottom: none;
        }
        
        .section h3 {
            margin-bottom: 15px;
            color: #333;
        }
        
        .order-summary {
            background-color: #f8f9fa;
            padding: 15px;
            border-radius: 4px;
            margin-bottom: 20px;
        }
        
        .order-item {
            display: flex;
            justify-content: space-between;
            margin-bottom: 10px;
            padding-bottom: 10px;
            border-bottom: 1px solid #ddd;
        }
        
        .order-item:last-child {
            border-bottom: none;
            margin-bottom: 0;
            padding-bottom: 0;
        }
        
        .totale-section {
            margin-top: 15px;
            padding-top: 15px;
            border-top: 2px solid #333;
            font-weight: bold;
            font-size: 18px;
        }
        
        .sconto-section {
            background-color: #e3f2fd;
            padding: 15px;
            border-radius: 4px;
        }
        
        .sconto-options {
            margin-top: 15px;
        }
        
        .sconto-option {
            margin-bottom: 10px;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
            display: flex;
            align-items: center;
            cursor: pointer;
            transition: background-color 0.2s;
        }
        
        .sconto-option:hover {
            background-color: #f8f9fa;
        }
        
        .sconto-option.disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
        
        .sconto-option input[type="radio"] {
            margin-right: 10px;
        }
        
        .sconto-info {
            flex-grow: 1;
        }
        
        .sconto-label {
            font-weight: bold;
        }
        
        .sconto-punti {
            font-size: 14px;
            color: #666;
        }
        
        .error {
            background-color: #f8d7da;
            color: #721c24;
            padding: 15px;
            border-radius: 4px;
            margin-bottom: 20px;
        }
        
        .info-box {
            background-color: #d1ecf1;
            color: #0c5460;
            padding: 15px;
            border-radius: 4px;
            margin-bottom: 20px;
        }
        
        .btn-container {
            display: flex;
            gap: 15px;
            justify-content: space-between;
            padding: 20px;
            background-color: #f8f9fa;
        }
        
        .btn {
            padding: 12px 30px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 16px;
            text-decoration: none;
            display: inline-block;
            text-align: center;
        }
        
        .btn-primary {
            background-color: #4CAF50;
            color: white;
            font-weight: bold;
        }
        
        .btn-primary:hover {
            background-color: #45a049;
        }
        
        .btn-secondary {
            background-color: #6c757d;
            color: white;
        }
        
        .btn-secondary:hover {
            background-color: #5a6268;
        }
        
        #totale-finale {
            color: #4CAF50;
            font-size: 24px;
        }
    </style>
</head>
<body>
    <nav class="navbar">
        <h1>Conferma Ordine</h1>
        <a href="dashboard_cliente.php">← Dashboard</a>
    </nav>
    
    <div class="container">
        <?php if (isset($error)): ?>
        <div class="error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        
        <div class="checkout-container">
            <div class="checkout-header">
                <h2>Riepilogo Ordine</h2>
            </div>
            
            <form method="POST">
                <div class="section">
                    <h3>Dettaglio Prodotti</h3>
                    <div class="order-summary">
                        <?php foreach ($_SESSION['carrello']['prodotti'] as $item): ?>
                        <div class="order-item">
                            <div>
                                <strong><?php echo htmlspecialchars($item['nome']); ?></strong>
                                <br>
                                <small><?php echo $item['quantita']; ?> × <?php echo formatPrice($item['prezzo']); ?></small>
                            </div>
                            <div><?php echo formatPrice($item['prezzo'] * $item['quantita']); ?></div>
                        </div>
                        <?php endforeach; ?>
                        
                        <div class="totale-section">
                            <div class="order-item">
                                <div>Totale prodotti:</div>
                                <div><?php echo formatPrice($totale); ?></div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="section">
                    <h3>Negozio</h3>
                    <p><?php echo htmlspecialchars($negozio_info['indirizzo']); ?></p>
                </div>
                
                <?php if ($sconti_disponibili && $sconti_disponibili['punti_attuali'] >= 100): ?>
                <div class="section">
                    <h3>Seleziona Sconto</h3>
                    <div class="info-box">
                        Hai <?php echo $sconti_disponibili['punti_attuali']; ?> punti disponibili.
                        <br><small>Nota: lo sconto massimo applicabile è di 100€</small>
                    </div>
                    
                    <div class="sconto-section">
                        <div class="sconto-options">
                            <label class="sconto-option">
                                <input type="radio" name="sconto" value="0" checked 
                                       onchange="aggiornaTotale()">
                                <div class="sconto-info">
                                    <div class="sconto-label">Nessuno sconto</div>
                                    <div class="sconto-punti">Mantieni i tuoi punti</div>
                                </div>
                            </label>
                            
                            <?php if ($sconti_disponibili['sconto_5']): ?>
                            <label class="sconto-option">
                                <input type="radio" name="sconto" value="5" onchange="aggiornaTotale()">
                                <div class="sconto-info">
                                    <div class="sconto-label">Sconto 5%</div>
                                    <div class="sconto-punti">Utilizza 100 punti</div>
                                </div>
                            </label>
                            <?php endif; ?>
                            
                            <?php if ($sconti_disponibili['sconto_15']): ?>
                            <label class="sconto-option">
                                <input type="radio" name="sconto" value="15" onchange="aggiornaTotale()">
                                <div class="sconto-info">
                                    <div class="sconto-label">Sconto 15%</div>
                                    <div class="sconto-punti">Utilizza 200 punti</div>
                                </div>
                            </label>
                            <?php endif; ?>
                            
                            <?php if ($sconti_disponibili['sconto_30']): ?>
                            <label class="sconto-option">
                                <input type="radio" name="sconto" value="30" onchange="aggiornaTotale()">
                                <div class="sconto-info">
                                    <div class="sconto-label">Sconto 30%</div>
                                    <div class="sconto-punti">Utilizza 300 punti</div>
                                </div>
                            </label>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                
                <div class="section">
                    <h3>Totale Finale</h3>
                    <div id="totale-finale"><?php echo formatPrice($totale); ?></div>
                    <div id="risparmio" style="color: #4CAF50; margin-top: 10px;"></div>
                    <div id="punti-guadagnati" style="color: #2196F3; margin-top: 10px;"></div>
                </div>
                
                <div class="btn-container">
                    <a href="carrello.php" class="btn btn-secondary">← Torna al carrello</a>
                    <button type="submit" name="conferma_ordine" class="btn btn-primary">
                        Conferma Acquisto
                    </button>
                </div>
            </form>
        </div>
    </div>
    
    <script>
        const totaleBase = <?php echo $totale; ?>;
        
        function aggiornaTotale() {
            const scontoSelezionato = document.querySelector('input[name="sconto"]:checked').value;
            const percentualeSconto = parseInt(scontoSelezionato);
            
            let sconto = 0;
            if (percentualeSconto > 0) {
                sconto = Math.min(totaleBase * percentualeSconto / 100, 100);
            }
            
            const totaleFinale = totaleBase - sconto;
            
            document.getElementById('totale-finale').textContent = 
                new Intl.NumberFormat('it-IT', { 
                    style: 'currency', 
                    currency: 'EUR' 
                }).format(totaleFinale);
            
            if (sconto > 0) {
                document.getElementById('risparmio').textContent = 
                    'Risparmio: ' + new Intl.NumberFormat('it-IT', { 
                        style: 'currency', 
                        currency: 'EUR' 
                    }).format(sconto);
            } else {
                document.getElementById('risparmio').textContent = '';
            }
            
            // Mostra punti che verranno guadagnati
            const puntiGuadagnati = Math.floor(totaleFinale);
            document.getElementById('punti-guadagnati').textContent = 
                'Guadagnerai ' + puntiGuadagnati + ' punti con questo acquisto';
        }
        
        // Calcola inizialmente
        aggiornaTotale();
    </script>
</body>
</html>