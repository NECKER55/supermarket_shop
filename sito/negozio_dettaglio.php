<?php
require_once 'config.php';
checkManager();

$negozio_id = $_GET['id'] ?? 0;
$pdo = getDBConnection();

// Verifica che il manager sia responsabile di questo negozio
$stmt = $pdo->prepare("SELECT * FROM Negozio WHERE codice = ? AND cf_responsabile = ?");
$stmt->execute([$negozio_id, $_SESSION['cf']]);
$negozio = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$negozio) {
    header("Location: gestione_negozi.php");
    exit();
}

// Aggiorna orari negozio
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['aggiorna_orari'])) {
    $orario_apertura = $_POST['orario_apertura'];
    $orario_chiusura = $_POST['orario_chiusura'];
    
    try {
        $stmt = $pdo->prepare("UPDATE Negozio SET orario_apertura = ?, orario_chiusura = ? WHERE codice = ?");
        $stmt->execute([$orario_apertura, $orario_chiusura, $negozio_id]);
        setFlashMessage("Orari aggiornati con successo", 'success');
    } catch (PDOException $e) {
        setFlashMessage("Errore nell'aggiornamento degli orari", 'error');
    }
    
    header("Location: negozio_dettaglio.php?id=$negozio_id&tab=info");
    exit();
}

// Aggiorna prezzo prodotto
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['aggiorna_prezzo'])) {
    $prodotto_id = $_POST['prodotto_id'];
    $nuovo_prezzo = $_POST['prezzo'];
    
    try {
        $stmt = $pdo->prepare("
            UPDATE NegozioPossiede 
            SET prezzo = ? 
            WHERE codice_prodotto = ? AND codice_negozio = ?
        ");
        $stmt->execute([$nuovo_prezzo, $prodotto_id, $negozio_id]);
        setFlashMessage("Prezzo aggiornato con successo", 'success');
    } catch (PDOException $e) {
        setFlashMessage("Errore nell'aggiornamento del prezzo", 'error');
    }
    
    header("Location: negozio_dettaglio.php?id=$negozio_id");
    exit();
}
//creazione degli ordini
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['crea_ordini'])) {
    $prodotti_quantita = $_POST['prodotti'] ?? [];
    
    $prodotti_da_ordinare = [];
    foreach ($prodotti_quantita as $prodotto_id => $quantita) {
        if (is_numeric($quantita) && $quantita > 0) {
            $prodotti_da_ordinare[$prodotto_id] = (int)$quantita;
        }
    }

    if (empty($prodotti_da_ordinare)) {
        setFlashMessage("Nessuna quantità specificata per creare un ordine.", 'error');
    } else {
        $errori_validazione = [];
        
        // FASE 1: Validazione "All or Nothing". Controlla tutto prima di agire.
        foreach ($prodotti_da_ordinare as $prodotto_id => $quantita) {
            try {
                $stmt_check = $pdo->prepare("SELECT trova_fornitore_economico(?, ?)");
                $stmt_check->execute([$prodotto_id, $quantita]);
                // La funzione lancia un'eccezione se non trova un fornitore
                if ($stmt_check->fetchColumn() === false) {
                     // Questo fallback gestisce casi in cui l'eccezione non viene lanciata ma non si ottengono risultati
                    throw new PDOException("Nessun fornitore disponibile per il prodotto ID $prodotto_id con la quantità richiesta.");
                }
            } catch (PDOException $e) {
                if (preg_match('/Nessun fornitore disponibile per il prodotto (\d+) con quantità (\d+)/', $e->getMessage(), $matches)) {
                     // Recupera nome prodotto per un messaggio più chiaro
                    $stmt_prod_nome = $pdo->prepare("SELECT nome FROM Prodotto WHERE codice = ?");
                    $stmt_prod_nome->execute([$matches[1]]);
                    $nome_prodotto = $stmt_prod_nome->fetchColumn();
                    $errori_validazione[] = "<b>$nome_prodotto</b>: quantità richiesta ($matches[2]) non disponibile presso nessun fornitore.";
                } else {
                    $errori_validazione[] = "Errore di validazione per prodotto ID $prodotto_id.";
                }
            }
        }
        
        // FASE 2: Decisione. Se ci sono errori, annulla tutto. Altrimenti procedi.
        if (!empty($errori_validazione)) {
            // Se ci sono errori, annulla l'intera operazione e mostra l'alert
            $messaggio_errore = "<strong>Nessun ordine creato.</strong> I seguenti prodotti non sono disponibili nelle quantità richieste:<ul>";
            foreach ($errori_validazione as $errore) {
                $messaggio_errore .= "<li>$errore</li>";
            }
            $messaggio_errore .= "</ul>";
            setFlashMessage($messaggio_errore, 'error');

        } else {
            // Se la validazione ha successo per tutti, procedi alla creazione degli ordini
            $ordini_per_fornitore = [];
            foreach ($prodotti_da_ordinare as $prodotto_id => $quantita) {
                $stmt = $pdo->prepare("SELECT trova_fornitore_economico(?, ?)");
                $stmt->execute([$prodotto_id, $quantita]);
                $fornitore_piva = $stmt->fetchColumn();

                $price_stmt = $pdo->prepare("SELECT prezzo FROM FornitorePossiede WHERE codice_fornitore = ? AND codice_prodotto = ?");
                $price_stmt->execute([$fornitore_piva, $prodotto_id]);
                $prezzo_unitario = $price_stmt->fetchColumn();

                $ordini_per_fornitore[$fornitore_piva][] = [
                    'prodotto_id' => $prodotto_id,
                    'quantita' => $quantita,
                    'prezzo' => $prezzo_unitario
                ];
            }

            $ordini_creati_count = 0;
            $prodotti_ordinati_count = 0;
            $data_consegna = date('Y-m-d H:i:s', strtotime('+2 days'));
            $pdo->beginTransaction();
            try {
                foreach ($ordini_per_fornitore as $fornitore_piva => $prodotti) {
                    $stmt = $pdo->prepare("INSERT INTO Ordine (data_consegna, codice_negozio, codice_fornitore, totale) VALUES (?, ?, ?, 0)");
                    $stmt->execute([$data_consegna, $negozio_id, $fornitore_piva]);
                    $ordine_id = $pdo->lastInsertId('ordine_codice_seq');
                    foreach ($prodotti as $prodotto) {
                        $stmt_contiene = $pdo->prepare("INSERT INTO OrdineContiene (codice_ordine, codice_prodotto, quantita, prezzo) VALUES (?, ?, ?, ?)");
                        $stmt_contiene->execute([$ordine_id, $prodotto['prodotto_id'], $prodotto['quantita'], $prodotto['prezzo']]);
                        $prodotti_ordinati_count++;
                    }
                    $ordini_creati_count++;
                }
                $pdo->commit();
                setFlashMessage("Successo! Creati $ordini_creati_count ordini ottimizzati per un totale di $prodotti_ordinati_count prodotti.", 'success');
            } catch (PDOException $e) {
                $pdo->rollBack();
                setFlashMessage("Errore critico durante la finalizzazione degli ordini: " . $e->getMessage(), 'error');
            }
        }
    }
    
    header("Location: negozio_dettaglio.php?id=$negozio_id&tab=ordini");
    exit();
}


// Tab attiva
$tab = $_GET['tab'] ?? 'prodotti';

// Recupera prodotti del negozio usando la funzione SQL
$stmt = $pdo->prepare("SELECT * FROM get_prodotti_negozio(?)");
$stmt->execute([$negozio_id]);
$prodotti = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Recupera ordini attivi
$stmt = $pdo->prepare("
    SELECT o.codice, o.totale, o.data_consegna, f.indirizzo as fornitore,
           STRING_AGG(CONCAT(p.nome, ' (', oc.quantita, ')'), ', ') as prodotti
    FROM Ordine o
    JOIN Fornitore f ON o.codice_fornitore = f.p_iva
    LEFT JOIN OrdineContiene oc ON o.codice = oc.codice_ordine
    LEFT JOIN Prodotto p ON oc.codice_prodotto = p.codice
    WHERE o.codice_negozio = ? AND o.data_consegna >= CURRENT_TIMESTAMP
    GROUP BY o.codice, o.totale, o.data_consegna, f.indirizzo
    ORDER BY o.data_consegna
");
$stmt->execute([$negozio_id]);
$ordini = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Recupera tesserati usando query diretta (non c'è una vista specifica per negozio)
$stmt = $pdo->prepare("
    SELECT p.cf, p.nome, p.cognome, t.punti, t.data_richiesta,
           CASE 
               WHEN t.punti >= 300 THEN 'Gold'
               WHEN t.punti >= 200 THEN 'Silver'
               WHEN t.punti >= 100 THEN 'Bronze'
               ELSE 'Standard'
           END as categoria
    FROM Tessera t
    JOIN Persona p ON t.cf_cliente = p.cf
    WHERE t.negozio = ?
    ORDER BY t.punti DESC, p.cognome, p.nome
");
$stmt->execute([$negozio_id]);
$tesserati = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Recupera tutti i prodotti con disponibilità da almeno un fornitore
$stmt = $pdo->query("
    SELECT DISTINCT p.codice, p.nome
    FROM Prodotto p
    WHERE EXISTS (
        SELECT 1 FROM FornitorePossiede fp 
        WHERE fp.codice_prodotto = p.codice AND fp.quantita > 0
    )
    ORDER BY p.nome
");
$prodotti_ordinabili = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Statistiche negozio
$stmt = $pdo->prepare("
    SELECT 
        COUNT(DISTINCT f.codice) as vendite_totali,
        COALESCE(SUM(f.totale), 0) as incasso_totale,
        COUNT(DISTINCT f.cf_cliente) as clienti_serviti,
        COUNT(DISTINCT t.cf_cliente) as tesserati_attivi
    FROM Negozio n
    LEFT JOIN Fattura f ON n.codice = f.codice_negozio
    LEFT JOIN Tessera t ON n.codice = t.negozio
    WHERE n.codice = ?
");
$stmt->execute([$negozio_id]);
$statistiche = $stmt->fetch(PDO::FETCH_ASSOC);

$flash = getFlashMessage();
?>

<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestione Negozio - <?php echo htmlspecialchars($negozio['indirizzo']); ?></title>
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
        
        .container {
            max-width: 1200px;
            margin: 20px auto;
            padding: 0 20px;
        }
        
        .negozio-header {
            background: white;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }
        
        .negozio-header h2 {
            margin-bottom: 10px;
        }
        
        .negozio-info {
            color: #666;
        }
        
        .stats-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-top: 15px;
            padding: 15px;
            background-color: #f8f9fa;
            border-radius: 4px;
        }
        
        .stat-item {
            text-align: center;
        }
        
        .stat-value {
            font-size: 24px;
            font-weight: bold;
            color: #2196F3;
        }
        
        .stat-label {
            font-size: 12px;
            color: #666;
            text-transform: uppercase;
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
        .flash-error ul {
            margin-top: 10px;
            margin-left: 20px;
        }
        
        .tabs {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
        }
        
        .tab-button {
            padding: 10px 20px;
            background-color: white;
            border: none;
            border-radius: 4px 4px 0 0;
            cursor: pointer;
            text-decoration: none;
            color: #333;
            font-size: 16px;
            transition: background-color 0.3s;
        }
        
        .tab-button:hover {
            background-color: #f8f9fa;
        }
        
        .tab-button.active {
            background-color: #4CAF50;
            color: white;
        }
        
        .tab-content {
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            padding: 20px;
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
        
        .scorte-basse {
            background-color: #fff3cd;
        }
        
        .ordine-form {
            background-color: #f8f9fa;
            padding: 20px;
            border-radius: 4px;
            margin-bottom: 20px;
        }
        
        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 15px;
        }
        
        .form-group {
            display: flex;
            flex-direction: column;
        }
        
        .form-group label {
            margin-bottom: 5px;
            font-weight: bold;
            color: #333;
        }
        
        .form-group input,
        .form-group select {
            padding: 8px;
            border: 1px solid #ddd;
            border-radius: 4px;
        }
        
        .btn-primary {
            padding: 10px 20px;
            background-color: #4CAF50;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 16px;
        }
        
        .prezzo-form {
            display: flex;
            gap: 10px;
            align-items: center;
        }
        
        .prezzo-form input {
            width: 100px;
            padding: 6px;
            border: 1px solid #ddd;
            border-radius: 4px;
        }
        
        .btn-small {
            padding: 6px 12px;
            background-color: #2196F3;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
        }
        
        .btn-small:hover {
            background-color: #1976D2;
        }
        
        .categoria-badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: bold;
            margin-left: 10px;
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
    </style>
</head>
<body>
    <nav class="navbar">
        <h1>Gestione Negozio</h1>
        <a href="gestione_negozi.php">← Torna ai negozi</a>
    </nav>
    
    <div class="container">
        <?php if ($flash): ?>
        <div class="flash-message flash-<?php echo $flash['type']; ?>">
            <?php echo $flash['message']; // Use raw message to allow HTML ?>
        </div>
        <?php endif; ?>
        
        <div class="negozio-header">
            <h2>Negozio #<?php echo $negozio['codice']; ?></h2>
            <div class="negozio-info">
                <strong>Indirizzo:</strong> <?php echo htmlspecialchars($negozio['indirizzo']); ?><br>
                <strong>Responsabile:</strong> <?php echo htmlspecialchars($_SESSION['nome'] . ' ' . $_SESSION['cognome']); ?>
            </div>
            
            <div class="stats-row">
                <div class="stat-item">
                    <div class="stat-value"><?php echo $statistiche['vendite_totali']; ?></div>
                    <div class="stat-label">Vendite Totali</div>
                </div>
                <div class="stat-item">
                    <div class="stat-value"><?php echo formatPrice($statistiche['incasso_totale']); ?></div>
                    <div class="stat-label">Incasso Totale</div>
                </div>
                <div class="stat-item">
                    <div class="stat-value"><?php echo $statistiche['clienti_serviti']; ?></div>
                    <div class="stat-label">Clienti Serviti</div>
                </div>
                <div class="stat-item">
                    <div class="stat-value"><?php echo $statistiche['tesserati_attivi']; ?></div>
                    <div class="stat-label">Tesserati</div>
                </div>
            </div>
        </div>
        
        <div class="tabs">
            <a href="?id=<?php echo $negozio_id; ?>&tab=prodotti" 
               class="tab-button <?php echo $tab == 'prodotti' ? 'active' : ''; ?>">
                Inventario Prodotti
            </a>
            <a href="?id=<?php echo $negozio_id; ?>&tab=ordini" 
               class="tab-button <?php echo $tab == 'ordini' ? 'active' : ''; ?>">
                Gestione Ordini
            </a>
            <a href="?id=<?php echo $negozio_id; ?>&tab=tesserati" 
               class="tab-button <?php echo $tab == 'tesserati' ? 'active' : ''; ?>">
                Clienti Tesserati
            </a>
            <a href="?id=<?php echo $negozio_id; ?>&tab=info" 
               class="tab-button <?php echo $tab == 'info' ? 'active' : ''; ?>">
                Info Negozio
            </a>
        </div>
        
        <div class="tab-content">
            <?php if ($tab == 'prodotti'): ?>
                <h3>Prodotti in Negozio</h3>
                <?php if (empty($prodotti)): ?>
                    <p>Nessun prodotto disponibile in questo negozio.</p>
                <?php else: ?>
                    <table>
                        <thead>
                            <tr>
                                <th>Codice</th>
                                <th>Nome Prodotto</th>
                                <th>Descrizione</th>
                                <th>Quantità</th>
                                <th>Prezzo</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($prodotti as $prodotto): ?>
                            <tr class="<?php echo $prodotto['quantita'] < 10 ? 'scorte-basse' : ''; ?>">
                                <td><?php echo $prodotto['codice']; ?></td>
                                <td><strong><?php echo htmlspecialchars($prodotto['nome']); ?></strong></td>
                                <td><?php echo htmlspecialchars($prodotto['descrizione']); ?></td>
                                <td>
                                    <?php echo $prodotto['quantita']; ?> pz
                                    <?php if ($prodotto['quantita'] < 10): ?>
                                        <span style="color: #ff9800; font-weight: bold;">⚠️ Scorte basse</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <form method="POST" class="prezzo-form">
                                        <input type="hidden" name="prodotto_id" value="<?php echo $prodotto['codice']; ?>">
                                        € <input type="number" name="prezzo" value="<?php echo $prodotto['prezzo']; ?>" 
                                               step="0.01" min="0" required>
                                        <button type="submit" name="aggiorna_prezzo" class="btn-small">
                                            Aggiorna
                                        </button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
                
            <?php elseif ($tab == 'ordini'): ?>
                <h3>Gestione Ordini</h3>
                
                <div class="ordine-form">
                    <h4>Crea Nuovo Ordine</h4>
                    <p style="color: #666; margin-bottom: 15px;">
                        Il sistema raggrupperà automaticamente i prodotti per il fornitore più economico.
                        La consegna avverrà automaticamente dopo 2 giorni.
                    </p>
                    
                    <form method="POST">
                        <table style="margin-bottom: 20px;">
                            <thead>
                                <tr>
                                    <th>Prodotto</th>
                                    <th>Quantità da Ordinare</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($prodotti_ordinabili as $prod): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($prod['nome']); ?></td>
                                    <td>
                                        <input type="number" 
                                               name="prodotti[<?php echo $prod['codice']; ?>]" 
                                               min="0" 
                                               value="0"
                                               style="width: 100px;">
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        
                        <button type="submit" name="crea_ordini" class="btn-primary">
                            Invia Ordine/i
                        </button>
                    </form>
                </div>
                
                <h4>Ordini in Corso</h4>
                <?php if (empty($ordini)): ?>
                    <p>Nessun ordine in corso per questo negozio.</p>
                <?php else: ?>
                    <table>
                        <thead>
                            <tr>
                                <th>Codice</th>
                                <th>Prodotti</th>
                                <th>Fornitore</th>
                                <th>Data Consegna</th>
                                <th>Totale</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($ordini as $ordine): ?>
                            <tr>
                                <td>#<?php echo $ordine['codice']; ?></td>
                                <td><?php echo htmlspecialchars($ordine['prodotti']); ?></td>
                                <td><?php echo htmlspecialchars($ordine['fornitore']); ?></td>
                                <td><?php echo date('d/m/Y H:i', strtotime($ordine['data_consegna'])); ?></td>
                                <td><?php echo formatPrice($ordine['totale']); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
                
            <?php elseif ($tab == 'tesserati'): ?>
                <h3>Clienti Tesserati</h3>
                
                <?php if (empty($tesserati)): ?>
                    <p>Nessun cliente ha ancora richiesto la tessera in questo negozio.</p>
                <?php else: ?>
                    <p style="margin-bottom: 20px;">Totale tesserati: <strong><?php echo count($tesserati); ?></strong></p>
                    
                    <table>
                        <thead>
                            <tr>
                                <th>Codice Fiscale</th>
                                <th>Nome</th>
                                <th>Cognome</th>
                                <th>Punti Attuali</th>
                                <th>Data Iscrizione</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($tesserati as $tesserato): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($tesserato['cf']); ?></td>
                                <td><?php echo htmlspecialchars($tesserato['nome']); ?></td>
                                <td><?php echo htmlspecialchars($tesserato['cognome']); ?></td>
                                <td>
                                    <strong><?php echo $tesserato['punti']; ?></strong>
                                    <?php if ($tesserato['categoria'] == 'Gold'): ?>
                                        <span class="categoria-badge badge-gold">Gold</span>
                                    <?php elseif ($tesserato['categoria'] == 'Silver'): ?>
                                        <span class="categoria-badge badge-silver">Silver</span>
                                    <?php elseif ($tesserato['categoria'] == 'Bronze'): ?>
                                        <span class="categoria-badge badge-bronze">Bronze</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo date('d/m/Y', strtotime($tesserato['data_richiesta'])); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
                
            <?php elseif ($tab == 'info'): ?>
                <h3>Informazioni Negozio</h3>
                
                <div style="margin-bottom: 30px;">
                    <p><strong>Indirizzo:</strong> <?php echo htmlspecialchars($negozio['indirizzo']); ?></p>
                    <p><strong>Codice Negozio:</strong> #<?php echo $negozio['codice']; ?></p>
                    <p><strong>Responsabile:</strong> <?php echo htmlspecialchars($_SESSION['nome'] . ' ' . $_SESSION['cognome']); ?></p>
                </div>
                
                <h4>Orari di Apertura</h4>
                <p style="color: #666; margin-bottom: 20px;">Gli orari impostati valgono per tutti i giorni della settimana</p>
                
                <form method="POST">
                    <div class="form-grid" style="max-width: 400px;">
                        <div class="form-group">
                            <label for="orario_apertura">Orario Apertura</label>
                            <input type="time" 
                                   id="orario_apertura"
                                   name="orario_apertura" 
                                   value="<?php echo substr($negozio['orario_apertura'], 0, 5); ?>"
                                   required>
                        </div>
                        
                        <div class="form-group">
                            <label for="orario_chiusura">Orario Chiusura</label>
                            <input type="time" 
                                   id="orario_chiusura"
                                   name="orario_chiusura" 
                                   value="<?php echo substr($negozio['orario_chiusura'], 0, 5); ?>"
                                   required>
                        </div>
                    </div>
                    <button type="submit" name="aggiorna_orari" class="btn-primary" style="margin-top: 20px;">
                       Salva Orari
                   </button>
               </form>
               
               <div style="margin-top: 30px; padding: 20px; background-color: #f8f9fa; border-radius: 4px;">
                   <strong>Orario attuale:</strong> 
                   <?php 
                   echo substr($negozio['orario_apertura'], 0, 5) . ' - ' . substr($negozio['orario_chiusura'], 0, 5);
                   ?>
               </div>
               
           <?php endif; ?>
       </div>
   </div>
</body>
</html>