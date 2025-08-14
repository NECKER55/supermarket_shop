<?php
require_once 'config.php';
checkLogin();

// Se è un manager, reindirizza alla dashboard manager
if ($_SESSION['is_manager']) {
    header("Location: dashboard_manager.php");
    exit();
}

$pdo = getDBConnection();

// Gestione richiesta tessera
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['richiedi_tessera'])) {
    $negozio_id = $_POST['negozio_id'];
    
    try {
        $stmt = $pdo->prepare("INSERT INTO Tessera (cf_cliente, negozio, punti) VALUES (?, ?, 0)");
        $stmt->execute([$_SESSION['cf'], $negozio_id]);
        setFlashMessage("Tessera fedeltà richiesta con successo!", 'success');
    } catch (PDOException $e) {
        setFlashMessage("Errore nella richiesta della tessera", 'error');
    }
    
    header("Location: dashboard_cliente.php");
    exit();
}

// Recupera informazioni tessera fedeltà
try {
    $stmt = $pdo->prepare("
        SELECT t.punti, t.data_richiesta, t.negozio, 
               n.indirizzo as negozio_indirizzo,
               CASE 
                   WHEN t.punti >= 300 THEN 'Premium Gold'
                   WHEN t.punti >= 200 THEN 'Premium Silver'
                   WHEN t.punti >= 100 THEN 'Premium Bronze'
                   ELSE 'Standard'
               END as categoria_tessera,
               CASE 
                   WHEN t.punti >= 300 THEN 30
                   WHEN t.punti >= 200 THEN 15
                   WHEN t.punti >= 100 THEN 5
                   ELSE 0
               END as sconto_massimo
        FROM Tessera t
        LEFT JOIN Negozio n ON t.negozio = n.codice
        WHERE t.cf_cliente = ?
    ");
    $stmt->execute([$_SESSION['cf']]);
    $tessera = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Se non ha tessera, recupera negozi disponibili
    if (!$tessera) {
        $stmt = $pdo->query("SELECT codice, indirizzo FROM Negozio ORDER BY indirizzo");
        $negozi_disponibili = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // Verifica sconti disponibili
    if ($tessera) {
        $stmt = $pdo->prepare("SELECT * FROM verifica_sconto_disponibile(?)");
        $stmt->execute([$_SESSION['cf']]);
        $sconti = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    // Recupera lista negozi per shopping
    $stmt = $pdo->query("
        SELECT n.codice, n.indirizzo, n.orario_apertura, n.orario_chiusura,
               COUNT(DISTINCT np.codice_prodotto) as num_prodotti
        FROM Negozio n
        LEFT JOIN NegozioPossiede np ON n.codice = np.codice_negozio AND np.quantita > 0
        GROUP BY n.codice, n.indirizzo, n.orario_apertura, n.orario_chiusura
        ORDER BY n.indirizzo
    ");
    $negozi = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Ultimi acquisti
    $stmt = $pdo->prepare("
        SELECT f.codice, f.data_acquisto, f.totale, f.sconto, n.indirizzo
        FROM Fattura f
        JOIN Negozio n ON f.codice_negozio = n.codice
        WHERE f.cf_cliente = ?
        ORDER BY f.data_acquisto DESC
        LIMIT 5
    ");
    $stmt->execute([$_SESSION['cf']]);
    $ultimi_acquisti = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Statistiche personali
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(*) as totale_acquisti,
            COALESCE(SUM(totale), 0) as spesa_totale
        FROM Fattura
        WHERE cf_cliente = ?
    ");
    $stmt->execute([$_SESSION['cf']]);
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    // Gestione errori
}

$flash = getFlashMessage();
?>

<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Cliente - Catena Negozi</title>
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
        
        .navbar h1 {
            font-size: 24px;
        }
        
        .navbar .user-info {
            display: flex;
            align-items: center;
            gap: 20px;
        }
        
        .navbar a {
            color: white;
            text-decoration: none;
            padding: 8px 16px;
            background-color: rgba(0, 0, 0, 0.1);
            border-radius: 4px;
            transition: background-color 0.3s;
        }
        
        .navbar a:hover {
            background-color: rgba(0, 0, 0, 0.2);
        }
        
        .container {
            max-width: 1200px;
            margin: 20px auto;
            padding: 0 20px;
        }
        
        .welcome {
            background: white;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
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
        
        .card {
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            margin-bottom: 20px;
            overflow: hidden;
        }
        
        .card-header {
            background-color: #f8f9fa;
            padding: 15px 20px;
            border-bottom: 1px solid #dee2e6;
            font-weight: bold;
        }
        
        .card-body {
            padding: 20px;
        }
        
        .tessera-info {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
        }
        
        .tessera-stat {
            text-align: center;
            padding: 15px;
            background-color: #f8f9fa;
            border-radius: 8px;
        }
        
        .tessera-stat h3 {
            color: #666;
            font-size: 14px;
            margin-bottom: 5px;
        }
        
        .tessera-stat .value {
            font-size: 24px;
            font-weight: bold;
            color: #2196F3;
        }
        
        .categoria-badge {
            display: inline-block;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 14px;
            font-weight: bold;
            margin-top: 10px;
        }
        
        .categoria-gold {
            background-color: #FFD700;
            color: #333;
        }
        
        .categoria-silver {
            background-color: #C0C0C0;
            color: #333;
        }
        
        .categoria-bronze {
            background-color: #CD7F32;
            color: white;
        }
        
        .categoria-standard {
            background-color: #6c757d;
            color: white;
        }
        
        .sconti-disponibili {
            display: flex;
            gap: 15px;
            margin-top: 15px;
        }
        
        .sconto-badge {
            padding: 10px 20px;
            border-radius: 20px;
            font-weight: bold;
            text-align: center;
        }
        
        .sconto-attivo {
            background-color: #4CAF50;
            color: white;
        }
        
        .sconto-inattivo {
            background-color: #e0e0e0;
            color: #999;
        }
        
        .action-buttons {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-top: 20px;
        }
        
        .action-button {
            display: block;
            padding: 15px;
            text-align: center;
            text-decoration: none;
            color: white;
            border-radius: 4px;
            transition: opacity 0.3s;
        }
        
        .action-button:hover {
            opacity: 0.9;
        }
        
        .btn-primary {
            background-color: #2196F3;
        }
        
        .btn-success {
            background-color: #4CAF50;
        }
        
        .btn-info {
            background-color: #17a2b8;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
        }
        
        th, td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }
        
        th {
            background-color: #f8f9fa;
            font-weight: bold;
        }
        
        .no-data {
            text-align: center;
            color: #666;
            padding: 20px;
        }
        
        .stats-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
            padding: 15px;
            background-color: #e3f2fd;
            border-radius: 8px;
        }
        
        .stat-item {
            text-align: center;
        }
        
        .stat-label {
            font-size: 12px;
            color: #666;
            text-transform: uppercase;
        }
        
        .stat-value {
            font-size: 20px;
            font-weight: bold;
            color: #1976D2;
        }
    </style>
</head>
<body>
    <nav class="navbar">
        <h1>Area Cliente</h1>
        <div class="user-info">
            <span>Ciao, <?php echo htmlspecialchars($_SESSION['nome']); ?>!</span>
            <a href="cambio_password.php">Cambia Password</a>
            <a href="logout.php">Logout</a>
        </div>
    </nav>
    
    <div class="container">
        <?php if ($flash): ?>
        <div class="flash-message flash-<?php echo $flash['type']; ?>">
            <?php echo htmlspecialchars($flash['message']); ?>
        </div>
        <?php endif; ?>
        
        <div class="welcome">
            <h2>Benvenuto nel tuo account!</h2>
            <p>Da qui puoi visualizzare i prodotti disponibili, effettuare acquisti e gestire la tua tessera fedeltà.</p>
            
            <?php if ($stats['totale_acquisti'] > 0): ?>
            <div class="stats-row">
                <div class="stat-item">
                    <div class="stat-label">Acquisti Totali</div>
                    <div class="stat-value"><?php echo $stats['totale_acquisti']; ?></div>
                </div>
                <div class="stat-item">
                    <div class="stat-label">Spesa Totale</div>
                    <div class="stat-value"><?php echo formatPrice($stats['spesa_totale']); ?></div>
                </div>
            </div>
            <?php endif; ?>
        </div>
        
        <?php if ($tessera): ?>
        <div class="card">
            <div class="card-header">
                La tua Tessera Fedeltà
                <span class="categoria-badge categoria-<?php echo strtolower(str_replace(' ', '-', $tessera['categoria_tessera'])); ?>">
                    <?php echo $tessera['categoria_tessera']; ?>
                </span>
            </div>
            <div class="card-body">
                <div class="tessera-info">
                    <div class="tessera-stat">
                        <h3>Punti Disponibili</h3>
                        <div class="value"><?php echo $tessera['punti']; ?></div>
                    </div>
                    <div class="tessera-stat">
                        <h3>Sconto Massimo</h3>
                        <div class="value"><?php echo $tessera['sconto_massimo']; ?>%</div>
                    </div>
                    <div class="tessera-stat">
                        <h3>Emessa da</h3>
                        <div class="value" style="font-size: 16px;">
                            <?php echo htmlspecialchars($tessera['negozio_indirizzo'] ?? 'Negozio non più attivo'); ?>
                            <?php if ($tessera['negozio'] === null): ?>
                                <br><small style="color: #ff9800;">(Tessera ancora valida)</small>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <?php if (isset($sconti)): ?>
                <div class="sconti-disponibili">
                    <div class="sconto-badge <?php echo $sconti['sconto_5'] ? 'sconto-attivo' : 'sconto-inattivo'; ?>">
                        5% (100 punti)
                    </div>
                    <div class="sconto-badge <?php echo $sconti['sconto_15'] ? 'sconto-attivo' : 'sconto-inattivo'; ?>">
                        15% (200 punti)
                    </div>
                    <div class="sconto-badge <?php echo $sconti['sconto_30'] ? 'sconto-attivo' : 'sconto-inattivo'; ?>">
                        30% (300 punti)
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php else: ?>
        <div class="card">
            <div class="card-header">
                Richiedi la tua Tessera Fedeltà
            </div>
            <div class="card-body">
                <p style="margin-bottom: 20px;">Non hai ancora una tessera fedeltà! Richiedila ora per accumulare punti e ottenere sconti esclusivi.</p>
                
                <form method="POST">
                    <div class="form-group" style="margin-bottom: 20px;">
                        <label for="negozio_id" style="font-weight: bold; margin-bottom: 10px; display: block;">
                            Seleziona il negozio dove vuoi richiedere la tessera:
                        </label>
                        <select name="negozio_id" id="negozio_id" required 
                                style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 4px;">
                            <option value="">-- Seleziona un negozio --</option>
                            <?php foreach ($negozi_disponibili as $neg): ?>
                            <option value="<?php echo $neg['codice']; ?>">
                                <?php echo htmlspecialchars($neg['indirizzo']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <button type="submit" name="richiedi_tessera" class="btn btn-primary" 
                            style="background-color: #4CAF50; color: white; padding: 12px 30px; 
                                   border: none; border-radius: 4px; cursor: pointer; font-size: 16px;">
                        Richiedi Tessera Fedeltà
                    </button>
                </form>
            </div>
        </div>
        <?php endif; ?>
        
        <div class="card" style="margin-top: 20px;">
            <div class="card-header">
                Negozi Disponibili
            </div>
            <div class="card-body">
                <div class="negozi-grid" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 20px;">
                    <?php foreach ($negozi as $negozio): ?>
                    <div class="negozio-card" style="border: 1px solid #ddd; border-radius: 8px; padding: 20px; transition: box-shadow 0.3s;">
                        <h4><?php echo htmlspecialchars($negozio['indirizzo']); ?></h4>
                        <p style="color: #666; margin: 10px 0;">
                            Orari: <?php echo substr($negozio['orario_apertura'], 0, 5); ?> - 
                            <?php echo substr($negozio['orario_chiusura'], 0, 5); ?>
                        </p>
                        <p style="color: #2196F3; margin: 10px 0;">
                            <?php echo $negozio['num_prodotti']; ?> prodotti disponibili
                        </p>
                        <a href="catalogo_prodotti.php?negozio=<?php echo $negozio['codice']; ?>" 
                           class="btn btn-primary" 
                           style="display: inline-block; background-color: #2196F3; color: white; 
                                  padding: 10px 20px; text-decoration: none; border-radius: 4px; 
                                  text-align: center; width: 100%;">
                            Vai al Negozio →
                        </a>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        
        <div class="action-buttons">
            <a href="carrello.php" class="action-button btn-success">
                🛒 Vai al Carrello
            </a>
            <a href="storico_acquisti.php" class="action-button btn-info">
                📋 Storico Acquisti
            </a>
        </div>
        
        <?php if (!empty($ultimi_acquisti)): ?>
        <div class="card">
            <div class="card-header">
                Ultimi Acquisti
            </div>
            <div class="card-body">
                <table>
                    <thead>
                        <tr>
                            <th>Data</th>
                            <th>Negozio</th>
                            <th>Totale</th>
                            <th>Sconto</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($ultimi_acquisti as $acquisto): ?>
                        <tr>
                            <td><?php echo date('d/m/Y H:i', strtotime($acquisto['data_acquisto'])); ?></td>
                            <td><?php echo htmlspecialchars($acquisto['indirizzo']); ?></td>
                            <td><?php echo formatPrice($acquisto['totale']); ?></td>
                            <td><?php echo $acquisto['sconto'] ? $acquisto['sconto'] . '%' : '-'; ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>
    </div>
</body>
</html>