<?php
require_once 'config.php';
checkManager();

$pdo = getDBConnection();

// Statistiche dashboard
try {
    // Conta negozi gestiti dal manager corrente
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM Negozio WHERE cf_responsabile = ?");
    $stmt->execute([$_SESSION['cf']]);
    $totNegozi = $stmt->fetchColumn();
    
    // Conta clienti
    $stmt = $pdo->query("SELECT COUNT(*) FROM Persona WHERE cf NOT IN (SELECT cf_persona FROM Credenziali WHERE manager = true)");
    $totClienti = $stmt->fetchColumn();
    
    // Conta prodotti
    $stmt = $pdo->query("SELECT COUNT(*) FROM Prodotto");
    $totProdotti = $stmt->fetchColumn();
    
    // Clienti con più di 300 punti usando la vista materializzata
    $stmt = $pdo->query("SELECT COUNT(DISTINCT persona_cf) FROM materialized_view_utenti_piu_300_punti");
    $clientiPuntiAlti = $stmt->fetchColumn();
    
    
} catch (PDOException $e) {
    // Gestione errori
    $totNegozi = 0;
    $totClienti = 0;
    $totProdotti = 0;
    $clientiPuntiAlti = 0;
}
?>

<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Manager - Catena Negozi</title>
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
            background-color: #333;
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
            background-color: #555;
            border-radius: 4px;
            transition: background-color 0.3s;
        }
        
        .navbar a:hover {
            background-color: #666;
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
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
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
            font-size: 16px;
            margin-bottom: 10px;
        }
        
        .stat-card .number {
            font-size: 36px;
            font-weight: bold;
            color: #333;
        }
        
        .stat-card.highlight {
            background-color: #e3f2fd;
        }
        
        .stat-card.highlight .number {
            color: #2196F3;
        }
        
        .menu-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
        }
        
        .menu-item {
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            padding: 30px 20px;
            text-align: center;
            text-decoration: none;
            color: #333;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        
        .menu-item:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.15);
        }
        
        .menu-item .icon {
            font-size: 48px;
            margin-bottom: 10px;
        }
        
        .menu-item h3 {
            font-size: 18px;
            margin-bottom: 5px;
        }
        
        .menu-item p {
            font-size: 14px;
            color: #666;
        }
        
        .alert {
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 4px;
        }
        
        .alert-warning {
            background-color: #fff3cd;
            color: #856404;
            border: 1px solid #ffeeba;
        }
    </style>
</head>
<body>
    <nav class="navbar">
        <h1>Dashboard Manager</h1>
        <div class="user-info">
            <span>Benvenuto, <?php echo htmlspecialchars($_SESSION['nome'] . ' ' . $_SESSION['cognome']); ?></span>
            <a href="cambio_password.php">Cambia Password</a>
            <a href="logout.php">Logout</a>
        </div>
    </nav>
    
    <div class="container">
        <div class="welcome">
            <h2>Pannello di Controllo Manager</h2>
            <p>Gestisci negozi, prodotti, clienti e fornitori della catena.</p>
        </div>

        <div class="stats-grid">
            <div class="stat-card">
                <h3>I Tuoi Negozi</h3>
                <div class="number"><?php echo $totNegozi; ?></div>
            </div>
            <div class="stat-card">
                <h3>Clienti Registrati</h3>
                <div class="number"><?php echo $totClienti; ?></div>
            </div>
            <div class="stat-card">
                <h3>Prodotti in Catalogo</h3>
                <div class="number"><?php echo $totProdotti; ?></div>
            </div>
            <div class="stat-card">
                <h3>Clienti Premium (300+ punti)</h3>
                <div class="number"><?php echo $clientiPuntiAlti; ?></div>
            </div>
        </div>
        
        <h2 style="margin-bottom: 20px;">Menu Gestione</h2>
        
        <div class="menu-grid">
            <a href="gestione_utenti.php" class="menu-item">
                <div class="icon">👥</div>
                <h3>Gestione Utenti</h3>
                <p>Crea e gestisci account clienti e manager</p>
            </a>
            
            <a href="gestione_negozi.php" class="menu-item">
                <div class="icon">🏪</div>
                <h3>I Miei Negozi</h3>
                <p>Gestisci prodotti e ordini dei tuoi negozi</p>
            </a>
            
            <a href="gestione_fornitori.php" class="menu-item">
                <div class="icon">🚚</div>
                <h3>Gestione Fornitori</h3>
                <p>Fornitori e cataloghi</p>
            </a>
        </div>
    </div>
</body>
</html>