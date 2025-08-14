<?php
require_once 'config.php';
checkManager();

$pdo = getDBConnection();

// Aggiungi nuovo utente
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['aggiungi_utente'])) {
    $cf = strtoupper($_POST['cf']); // Converti in maiuscolo lato server
    $nome = $_POST['nome'];
    $cognome = $_POST['cognome'];
    $username = $_POST['username'];
    $password = $_POST['password'];
    $manager = isset($_POST['manager']) ? 1 : 0;
    
    try {
        $pdo->beginTransaction();
        
        // Inserisci persona
        $stmt = $pdo->prepare("INSERT INTO Persona (cf, nome, cognome) VALUES (?, ?, ?)");
        $stmt->execute([$cf, $nome, $cognome]);
        
        // Inserisci credenziali
        $stmt = $pdo->prepare("INSERT INTO Credenziali (username, password, cf_persona, manager) VALUES (?, ?, ?, ?)");
        $stmt->execute([$username, $password, $cf, $manager]);
        
        $pdo->commit();
        setFlashMessage("Utente aggiunto con successo", 'success');
        
    } catch (PDOException $e) {
        $pdo->rollBack();
        setFlashMessage("Errore: " . $e->getMessage(), 'error');
    }
    
    header("Location: gestione_utenti.php");
    exit();
}

// Elimina utente
if (isset($_GET['elimina'])) {
    $cf = $_GET['elimina'];
    
    try {
        // Verifica che non sia l'utente corrente
        if ($cf == $_SESSION['cf']) {
            setFlashMessage("Non puoi eliminare il tuo account", 'error');
        } else {
            $stmt = $pdo->prepare("DELETE FROM Persona WHERE cf = ?");
            $stmt->execute([$cf]);
            setFlashMessage("Utente eliminato", 'success');
        }
    } catch (PDOException $e) {
        setFlashMessage("Errore nell'eliminazione: " . $e->getMessage(), 'error');
    }
    
    header("Location: gestione_utenti.php");
    exit();
}

// Recupera lista utenti con informazioni tessera
$stmt = $pdo->query("
    SELECT p.cf, p.nome, p.cognome, c.username, c.manager,
           t.punti, t.negozio, n.indirizzo as negozio_indirizzo,
           CASE 
               WHEN t.punti >= 300 THEN 'Gold'
               WHEN t.punti >= 200 THEN 'Silver'
               WHEN t.punti >= 100 THEN 'Bronze'
               ELSE 'Standard'
           END as categoria
    FROM Persona p
    JOIN Credenziali c ON p.cf = c.cf_persona
    LEFT JOIN Tessera t ON p.cf = t.cf_cliente
    LEFT JOIN Negozio n ON t.negozio = n.codice
    ORDER BY c.manager DESC, p.cognome, p.nome
");
$utenti = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Recupera clienti con più di 300 punti dalla vista materializzata
$stmt = $pdo->query("
    SELECT 
        persona_cf,
        persona_nome,
        persona_cognome,
        tessera_punti,
        tessera_data_richiesta,
        n.indirizzo as negozio_indirizzo
    FROM materialized_view_utenti_piu_300_punti
    LEFT JOIN Negozio n ON tessera_negozio = n.codice
    ORDER BY tessera_punti DESC
");
$clienti_gold = $stmt->fetchAll(PDO::FETCH_ASSOC);

$flash = getFlashMessage();
?>

<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestione Utenti - Manager</title>
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
        
        .navbar a {
            color: white;
            text-decoration: none;
            padding: 8px 16px;
            background-color: #555;
            border-radius: 4px;
        }
        
        .navbar a:hover {
            background-color: #666;
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
        }
        
        .flash-error {
            background-color: #f8d7da;
            color: #721c24;
        }
        
        .info-box {
            background-color: #e3f2fd;
            color: #1565c0;
            padding: 15px;
            border-radius: 4px;
            margin-bottom: 20px;
        }
        
        .card {
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            margin-bottom: 20px;
        }
        
        .card-header {
            background-color: #f8f9fa;
            padding: 15px 20px;
            border-bottom: 1px solid #dee2e6;
            font-weight: bold;
        }
        
        .card-header.gold {
            background-color: #FFD700;
            color: #333;
        }
        
        .card-body {
            padding: 20px;
        }
        
        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }
        
        .form-group {
            display: flex;
            flex-direction: column;
        }
        
        label {
            margin-bottom: 5px;
            color: #333;
            font-weight: bold;
        }
        
        input[type="text"],
        input[type="password"] {
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 16px;
        }
        
        input[type="text"]:focus,
        input[type="password"]:focus {
            outline: none;
            border-color: #4CAF50;
        }
        
        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-top: 10px;
        }
        
        button {
            padding: 10px 20px;
            background-color: #4CAF50;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 16px;
        }
        
        button:hover {
            background-color: #45a049;
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
            position: sticky;
            top: 0;
        }
        
        tr:hover {
            background-color: #f8f9fa;
        }
        
        .badge {
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: bold;
        }
        
        .badge-success {
            background-color: #28a745;
            color: white;
        }
        
        .badge-info {
            background-color: #17a2b8;
            color: white;
        }
        
        .badge-gold {
            background-color: #FFD700;
            color: #333;
        }
        
        .badge-silver {
            background-color: #C0C0C0;
            color: #333;
        }
        
        .badge-bronze {
            background-color: #CD7F32;
            color: white;
        }
        
        .btn-danger {
            background-color: #dc3545;
            color: white;
            padding: 6px 12px;
            text-decoration: none;
            border-radius: 4px;
            font-size: 14px;
        }
        
        .btn-danger:hover {
            background-color: #c82333;
        }
        
        .actions {
            display: flex;
            gap: 10px;
        }
        
        .gold-client-item {
            padding: 15px;
            margin-bottom: 10px;
            background-color: #FFF8DC;
            border-radius: 4px;
            border-left: 4px solid #FFD700;
        }
        
        .gold-client-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 5px;
        }
        
        .gold-client-name {
            font-weight: bold;
            font-size: 16px;
        }
        
        .gold-client-points {
            background-color: #FFD700;
            color: #333;
            padding: 4px 12px;
            border-radius: 20px;
            font-weight: bold;
        }
        
        .gold-client-details {
            color: #666;
            font-size: 14px;
        }
        
        .cf-info {
            font-size: 12px;
            color: #666;
            margin-top: 5px;
        }
    </style>
</head>
<body>
    <nav class="navbar">
        <h1>Gestione Utenti</h1>
        <a href="dashboard_manager.php">← Dashboard</a>
    </nav>
    
    <div class="container">
        <?php if ($flash): ?>
        <div class="flash-message flash-<?php echo $flash['type']; ?>">
            <?php echo htmlspecialchars($flash['message']); ?>
        </div>
        <?php endif; ?>
        
        <?php if (count($clienti_gold) > 0): ?>
        <div class="info-box">
            <strong>ℹ️ Info:</strong> Ci sono attualmente <?php echo count($clienti_gold); ?> clienti con più di 300 punti (Premium Gold).
        </div>
        <?php endif; ?>
        
        <div class="card">
            <div class="card-header">
                Aggiungi Nuovo Utente
            </div>
            <div class="card-body">
                <form method="POST">
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="cf">Codice Fiscale</label>
                            <input type="text" 
                                   id="cf" 
                                   name="cf" 
                                   required 
                                   maxlength="16" 
                                   pattern="[a-zA-Z0-9]{16}"
                                   oninput="this.value = this.value.toUpperCase();"
                                   placeholder="RSSMRA80A01H501Z">
                            <div class="cf-info">16 caratteri alfanumerici (verranno convertiti in maiuscolo)</div>
                        </div>
                        
                        <div class="form-group">
                            <label for="nome">Nome</label>
                            <input type="text" id="nome" name="nome" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="cognome">Cognome</label>
                            <input type="text" id="cognome" name="cognome" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="username">Username</label>
                            <input type="text" id="username" name="username" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="password">Password</label>
                            <input type="password" id="password" name="password" required>
                        </div>
                    </div>
                    
                    <div class="checkbox-group">
                        <input type="checkbox" id="manager" name="manager" value="1">
                        <label for="manager">Utente Manager</label>
                    </div>
                    
                    <button type="submit" name="aggiungi_utente" style="margin-top: 20px;">
                        Aggiungi Utente
                    </button>
                </form>
            </div>
        </div>
        
        <?php if (!empty($clienti_gold)): ?>
        <div class="card">
            <div class="card-header gold">
                🏆 Clienti Premium Gold (300+ punti)
            </div>
            <div class="card-body">
                <?php foreach ($clienti_gold as $cliente): ?>
                <div class="gold-client-item">
                    <div class="gold-client-header">
                        <div class="gold-client-name">
                            <?php echo htmlspecialchars($cliente['persona_nome'] . ' ' . $cliente['persona_cognome']); ?>
                        </div>
                        <div class="gold-client-points">
                            <?php echo $cliente['tessera_punti']; ?> punti
                        </div>
                    </div>
                    <div class="gold-client-details">
                        <strong>CF:</strong> <?php echo htmlspecialchars($cliente['persona_cf']); ?><br>
                        <strong>Tessera dal:</strong> <?php echo date('d/m/Y', strtotime($cliente['tessera_data_richiesta'])); ?><br>
                        <strong>Negozio:</strong> <?php echo htmlspecialchars($cliente['negozio_indirizzo'] ?? 'Negozio non più attivo'); ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
        
        <div class="card">
            <div class="card-header">
                Utenti Registrati
            </div>
            <div class="card-body" style="overflow-x: auto;">
                <table>
                    <thead>
                        <tr>
                            <th>CF</th>
                            <th>Nome</th>
                            <th>Cognome</th>
                            <th>Username</th>
                            <th>Tipo</th>
                            <th>Tessera</th>
                            <th>Azioni</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($utenti as $utente): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($utente['cf']); ?></td>
                            <td><?php echo htmlspecialchars($utente['nome']); ?></td>
                            <td><?php echo htmlspecialchars($utente['cognome']); ?></td>
                            <td><?php echo htmlspecialchars($utente['username']); ?></td>
                            <td>
                                <?php if ($utente['manager']): ?>
                                    <span class="badge badge-success">Manager</span>
                                <?php else: ?>
                                    <span class="badge badge-info">Cliente</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($utente['punti'] !== null): ?>
                                    <?php echo $utente['punti']; ?> punti
                                    <?php if ($utente['categoria'] == 'Gold'): ?>
                                        <span class="badge badge-gold">Gold</span>
                                    <?php elseif ($utente['categoria'] == 'Silver'): ?>
                                        <span class="badge badge-silver">Silver</span>
                                    <?php elseif ($utente['categoria'] == 'Bronze'): ?>
                                        <span class="badge badge-bronze">Bronze</span>
                                    <?php endif; ?>
                                    <br>
                                    <small><?php echo htmlspecialchars($utente['negozio_indirizzo'] ?? 'Negozio chiuso'); ?></small>
                                <?php else: ?>
                                    -
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="actions">
                                    <?php if ($utente['cf'] != $_SESSION['cf']): ?>
                                    <a href="?elimina=<?php echo $utente['cf']; ?>" 
                                       class="btn-danger"
                                       onclick="return confirm('Eliminare questo utente?')">
                                        Elimina
                                    </a>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</body>
</html>