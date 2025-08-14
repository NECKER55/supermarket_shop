<?php
require_once 'config.php';
checkLogin();

if ($_SESSION['is_manager']) {
    header("Location: dashboard_manager.php");
    exit();
}

$pdo = getDBConnection();

// Recupera storico acquisti usando la funzione SQL
try {
    // Usa la funzione get_storico_cliente
    $stmt = $pdo->prepare("SELECT * FROM get_storico_cliente(?)");
    $stmt->execute([$_SESSION['cf']]);
    $storico = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Per ottenere i dettagli dei prodotti
    $fatture = [];
    foreach ($storico as $row) {
        $stmt = $pdo->prepare("
            SELECT 
                COUNT(fc.codice_prodotto) as num_prodotti,
                STRING_AGG(
                    CONCAT(p.nome, ' (', fc.quantita, ')'), 
                    ', ' ORDER BY p.nome
                ) as prodotti
            FROM FatturaContiene fc
            JOIN Prodotto p ON fc.codice_prodotto = p.codice
            WHERE fc.codice_fattura = ?
        ");
        $stmt->execute([$row['codice_fattura']]);
        $dettagli = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $fatture[] = [
            'codice' => $row['codice_fattura'],
            'data_acquisto' => $row['data_acquisto'],
            'totale' => $row['totale'],
            'sconto' => $row['sconto'],
            'negozio_indirizzo' => $row['negozio_indirizzo'],
            'num_prodotti' => $dettagli['num_prodotti'],
            'prodotti' => $dettagli['prodotti']
        ];
    }
    
    // Calcola statistiche con risparmio reale
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(*) as totale_acquisti,
            COALESCE(SUM(totale), 0) as spesa_totale,
            COALESCE(AVG(totale), 0) as spesa_media,
            COALESCE(SUM(
                CASE 
                    WHEN sconto > 0 THEN 
                        LEAST(totale * sconto / 100.0, 100.0)
                    ELSE 0
                END
            ), 0) as risparmio_totale
        FROM Fattura
        WHERE cf_cliente = ?
    ");
    $stmt->execute([$_SESSION['cf']]);
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    // Gestione errori
    $fatture = [];
    $stats = ['totale_acquisti' => 0, 'spesa_totale' => 0, 'spesa_media' => 0, 'risparmio_totale' => 0];
}
?>

<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Storico Acquisti - Catena Negozi</title>
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
        
        .navbar a:hover {
            background-color: rgba(0, 0, 0, 0.2);
        }
        
        .container {
            max-width: 1200px;
            margin: 20px auto;
            padding: 0 20px;
        }
        
        .stats-section {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            text-align: center;
        }
        
        .stat-card h3 {
            color: #666;
            font-size: 14px;
            margin-bottom: 10px;
            text-transform: uppercase;
        }
        
        .stat-card .value {
            font-size: 28px;
            font-weight: bold;
            color: #2196F3;
        }
        
        .card {
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }
        
        .card-header {
            background-color: #f8f9fa;
            padding: 15px 20px;
            border-bottom: 1px solid #dee2e6;
            font-weight: bold;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .card-body {
            padding: 20px;
        }
        
        .fattura-item {
            border: 1px solid #ddd;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 15px;
            transition: box-shadow 0.2s;
        }
        
        .fattura-item:hover {
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
        }
        
        .fattura-header {
            display: flex;
            justify-content: space-between;
            align-items: start;
            margin-bottom: 10px;
        }
        
        .fattura-info {
            flex-grow: 1;
        }
        
        .fattura-code {
            font-weight: bold;
            color: #333;
            margin-bottom: 5px;
        }
        
        .fattura-date {
            color: #666;
            font-size: 14px;
        }
        
        .fattura-totale {
            text-align: right;
        }
        
        .fattura-price {
            font-size: 20px;
            font-weight: bold;
            color: #2196F3;
        }
        
        .fattura-sconto {
            color: #4CAF50;
            font-size: 14px;
            margin-top: 5px;
        }
        
        .fattura-details {
            border-top: 1px solid #eee;
            padding-top: 10px;
            margin-top: 10px;
        }
        
        .fattura-negozio {
            color: #666;
            font-size: 14px;
            margin-bottom: 5px;
        }
        
        .fattura-prodotti {
            color: #666;
            font-size: 14px;
        }
        
        .no-data {
            text-align: center;
            padding: 60px 20px;
            color: #666;
        }
        
        .no-data h2 {
            margin-bottom: 20px;
        }
        
        .btn-primary {
            display: inline-block;
            padding: 12px 30px;
            background-color: #2196F3;
            color: white;
            text-decoration: none;
            border-radius: 4px;
            font-weight: bold;
        }
        
        .btn-primary:hover {
            background-color: #1976D2;
        }
    </style>
</head>
<body>
    <nav class="navbar">
        <h1>Storico Acquisti</h1>
        <a href="dashboard_cliente.php">← Dashboard</a>
    </nav>
    
    <div class="container">
        <?php if ($stats['totale_acquisti'] > 0): ?>
        <div class="stats-section">
            <div class="stat-card">
                <h3>Totale Acquisti</h3>
                <div class="value"><?php echo $stats['totale_acquisti']; ?></div>
            </div>
            
            <div class="stat-card">
                <h3>Spesa Totale</h3>
                <div class="value"><?php echo formatPrice($stats['spesa_totale']); ?></div>
            </div>
            
            <div class="stat-card">
                <h3>Spesa Media</h3>
                <div class="value"><?php echo formatPrice($stats['spesa_media']); ?></div>
            </div>
            
            <div class="stat-card">
                <h3>Risparmio con Sconti</h3>
                <div class="value"><?php echo formatPrice($stats['risparmio_totale']); ?></div>
            </div>
        </div>
        <?php endif; ?>
        
        <div class="card">
            <div class="card-header">
                <span>Le tue Fatture</span>
            </div>
            <div class="card-body">
                <?php if (empty($fatture)): ?>
               <div class="no-data">
                   <h2>Nessun acquisto effettuato</h2>
                   <p>Non hai ancora effettuato acquisti nei nostri negozi.</p>
                   <br>
                   <a href="catalogo_prodotti.php" class="btn-primary">Inizia lo Shopping</a>
               </div>
               <?php else: ?>
                   <?php foreach ($fatture as $fattura): ?>
                   <div class="fattura-item">
                       <div class="fattura-header">
                           <div class="fattura-info">
                               <div class="fattura-code">Fattura #<?php echo $fattura['codice']; ?></div>
                               <div class="fattura-date">
                                   <?php echo date('d/m/Y H:i', strtotime($fattura['data_acquisto'])); ?>
                               </div>
                           </div>
                           <div class="fattura-totale">
                               <div class="fattura-price"><?php echo formatPrice($fattura['totale']); ?></div>
                               <?php if ($fattura['sconto'] > 0): ?>
                               <div class="fattura-sconto">Sconto <?php echo $fattura['sconto']; ?>% applicato</div>
                               <?php endif; ?>
                           </div>
                       </div>
                       
                       <div class="fattura-details">
                           <div class="fattura-negozio">
                               <strong>Negozio:</strong> <?php echo htmlspecialchars($fattura['negozio_indirizzo']); ?>
                           </div>
                           <div class="fattura-prodotti">
                               <strong>Prodotti:</strong> <?php echo htmlspecialchars($fattura['prodotti']); ?>
                           </div>
                       </div>
                   </div>
                   <?php endforeach; ?>
               <?php endif; ?>
           </div>
       </div>
   </div>
</body>
</html>