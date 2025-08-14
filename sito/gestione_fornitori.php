<?php
require_once 'config.php';
checkManager();

$pdo = getDBConnection();

// Aggiungi nuovo fornitore
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['aggiungi_fornitore'])) {
    $p_iva = $_POST['p_iva'];
    $indirizzo = $_POST['indirizzo'];
    
    try {
        $stmt = $pdo->prepare("INSERT INTO Fornitore (p_iva, indirizzo) VALUES (?, ?)");
        $stmt->execute([$p_iva, $indirizzo]);
        setFlashMessage("Fornitore aggiunto con successo", 'success');
    } catch (PDOException $e) {
        setFlashMessage("Errore: " . $e->getMessage(), 'error');
    }
    
    header("Location: gestione_fornitori.php");
    exit();
}

// Aggiungi prodotto a fornitore
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['aggiungi_prodotto_fornitore'])) {
    $fornitore_id = $_POST['fornitore_id'];
    $prodotto_id = $_POST['prodotto_id'];
    $prezzo = $_POST['prezzo'];
    $quantita = $_POST['quantita'];
    
    try {
        $stmt = $pdo->prepare("
            INSERT INTO FornitorePossiede (codice_fornitore, codice_prodotto, prezzo, quantita) 
            VALUES (?, ?, ?, ?)
            ON CONFLICT (codice_fornitore, codice_prodotto) 
            DO UPDATE SET prezzo = EXCLUDED.prezzo, quantita = EXCLUDED.quantita
        ");
        $stmt->execute([$fornitore_id, $prodotto_id, $prezzo, $quantita]);
        setFlashMessage("Catalogo fornitore aggiornato", 'success');
    } catch (PDOException $e) {
        setFlashMessage("Errore: " . $e->getMessage(), 'error');
    }
    
    header("Location: gestione_fornitori.php");
    exit();
}

// Recupera fornitori con statistiche
$stmt = $pdo->query("
    SELECT f.p_iva, f.indirizzo,
           COUNT(DISTINCT fp.codice_prodotto) as num_prodotti,
           COUNT(DISTINCT o.codice) as num_ordini,
           COALESCE(SUM(DISTINCT o.totale), 0) as totale_ordini
    FROM Fornitore f
    LEFT JOIN FornitorePossiede fp ON f.p_iva = fp.codice_fornitore
    LEFT JOIN Ordine o ON f.p_iva = o.codice_fornitore
    GROUP BY f.p_iva, f.indirizzo
    ORDER BY f.p_iva
");
$fornitori = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Recupera prodotti per il form
$stmt = $pdo->query("SELECT codice, nome FROM Prodotto ORDER BY nome");
$prodotti = $stmt->fetchAll(PDO::FETCH_ASSOC);

$flash = getFlashMessage();
?>

<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestione Fornitori - Manager</title>
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
        input[type="number"],
        select {
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 16px;
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
        }
        
        .expandable-row {
            background-color: #f8f9fa;
            display: none;
        }
        
        .expandable-row.show {
            display: table-row;
        }
        
        .btn-expand {
            background-color: #17a2b8;
            color: white;
            padding: 6px 12px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
        }
        
        .btn-expand:hover {
            background-color: #138496;
        }
        
        .inline-form {
            display: flex;
            gap: 10px;
            align-items: end;
        }
        
        .inline-form .form-group {
            margin-bottom: 0;
        }
        
        .stats-badges {
            display: flex;
            gap: 15px;
            margin-top: 10px;
        }
        
        .badge {
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
        }
        
        .badge-primary {
            background-color: #007bff;
            color: white;
        }
        
        .badge-success {
            background-color: #28a745;
            color: white;
        }
        
        .badge-warning {
            background-color: #ffc107;
            color: #000;
        }
        
        .status-badge {
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: bold;
        }
        
        .status-today {
            background-color: #dc3545;
            color: white;
        }
        
        .status-tomorrow {
            background-color: #ffc107;
            color: #000;
        }
        
        .status-future {
            background-color: #28a745;
            color: white;
        }
        
        .status-past {
            background-color: #6c757d;
            color: white;
        }
    </style>
</head>
<body>
    <nav class="navbar">
        <h1>Gestione Fornitori</h1>
        <a href="dashboard_manager.php">← Dashboard</a>
    </nav>
    
    <div class="container">
        <?php if ($flash): ?>
        <div class="flash-message flash-<?php echo $flash['type']; ?>">
            <?php echo htmlspecialchars($flash['message']); ?>
        </div>
        <?php endif; ?>
        
        <div class="card">
            <div class="card-header">
                Aggiungi Nuovo Fornitore
            </div>
            <div class="card-body">
                <form method="POST">
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="p_iva">Partita IVA</label>
                            <input type="text" id="p_iva" name="p_iva" required 
                                   pattern="[0-9]{11}" maxlength="11"
                                   placeholder="12345678901">
                        </div>
                        
                        <div class="form-group">
                            <label for="indirizzo">Indirizzo</label>
                            <input type="text" id="indirizzo" name="indirizzo" required
                                   placeholder="Via Example 123, Città">
                        </div>
                    </div>
                    
                    <button type="submit" name="aggiungi_fornitore">Aggiungi Fornitore</button>
                </form>
            </div>
        </div>
        
        <div class="card">
            <div class="card-header">
                Elenco Fornitori
            </div>
            <div class="card-body" style="overflow-x: auto;">
                <table>
                    <thead>
                        <tr>
                            <th>P.IVA</th>
                            <th>Indirizzo</th>
                            <th>Prodotti in Catalogo</th>
                            <th>Ordini Totali</th>
                            <th>Valore Ordini</th>
                            <th>Azioni</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($fornitori as $fornitore): ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($fornitore['p_iva']); ?></strong></td>
                            <td><?php echo htmlspecialchars($fornitore['indirizzo']); ?></td>
                            <td><?php echo $fornitore['num_prodotti']; ?> prodotti</td>
                            <td><?php echo $fornitore['num_ordini']; ?></td>
                            <td><?php echo formatPrice($fornitore['totale_ordini']); ?></td>
                            <td>
                                <button class="btn-expand" onclick="toggleRow('<?php echo $fornitore['p_iva']; ?>')">
                                    Gestisci Catalogo
                                </button>
                            </td>
                        </tr>
                        <tr class="expandable-row" id="row-<?php echo $fornitore['p_iva']; ?>">
                            <td colspan="6" style="padding: 20px;">
                                <h4>Aggiungi/Modifica Prodotto nel Catalogo</h4>
                                <form method="POST" class="inline-form">
                                    <input type="hidden" name="fornitore_id" value="<?php echo $fornitore['p_iva']; ?>">
                                    
                                    <div class="form-group">
                                        <label>Prodotto</label>
                                        <select name="prodotto_id" required>
                                            <option value="">Seleziona...</option>
                                            <?php foreach ($prodotti as $prodotto): ?>
                                            <option value="<?php echo $prodotto['codice']; ?>">
                                                <?php echo htmlspecialchars($prodotto['nome']); ?>
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    
                                    <div class="form-group">
                                        <label>Prezzo Fornitore (€)</label>
                                        <input type="number" name="prezzo" step="0.01" min="0" required>
                                    </div>
                                    
                                    <div class="form-group">
                                        <label>Quantità Disponibile</label>
                                        <input type="number" name="quantita" min="0" required>
                                    </div>
                                    
                                    <button type="submit" name="aggiungi_prodotto_fornitore">Aggiungi/Aggiorna</button>
                                </form>
                                
                                <?php
                                // Mostra catalogo attuale del fornitore
                                $stmt = $pdo->prepare("
                                    SELECT p.nome, fp.prezzo, fp.quantita
                                    FROM FornitorePossiede fp
                                    JOIN Prodotto p ON fp.codice_prodotto = p.codice
                                    WHERE fp.codice_fornitore = ?
                                    ORDER BY p.nome
                                ");
                                $stmt->execute([$fornitore['p_iva']]);
                                $catalogo = $stmt->fetchAll(PDO::FETCH_ASSOC);
                                
                                if (!empty($catalogo)):
                                ?>
                                <h4 style="margin-top: 20px;">Catalogo Attuale</h4>
                                <table style="margin-top: 10px;">
                                    <thead>
                                        <tr>
                                            <th>Prodotto</th>
                                            <th>Prezzo</th>
                                            <th>Disponibilità</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($catalogo as $item): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($item['nome']); ?></td>
                                            <td><?php echo formatPrice($item['prezzo']); ?></td>
                                            <td><?php echo $item['quantita']; ?> pz</td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                                <?php endif; ?>
                                
                                <?php
                                // Usa la funzione get_ordini_fornitore
                                $stmt = $pdo->prepare("SELECT * FROM get_ordini_fornitore(?)");
                                $stmt->execute([$fornitore['p_iva']]);
                                $ordini = $stmt->fetchAll(PDO::FETCH_ASSOC);
                                
                                if (!empty($ordini)):
                                ?>
                                <h4 style="margin-top: 30px; padding-top: 20px; border-top: 2px solid #ddd;">
                                    Storico Ordini (<?php echo count($ordini); ?> totali)
                                </h4>
                                <table style="margin-top: 10px; width: 100%;">
                                    <thead>
                                        <tr style="background-color: #e3f2fd;">
                                            <th style="padding: 10px;">Ordine</th>
                                            <th style="padding: 10px;">Data Consegna</th>
                                            <th style="padding: 10px;">Negozio</th>
                                            <th style="padding: 10px;">Totale</th>
                                            <th style="padding: 10px;">Stato</th>
                                       </tr>
                                   </thead>
                                   <tbody>
                                       <?php 
                                       foreach ($ordini as $ordine): 
                                           // Recupera info negozio per questo ordine
                                           $stmt_neg = $pdo->prepare("SELECT indirizzo FROM Negozio WHERE codice = ?");
                                           $stmt_neg->execute([$ordine['codice_negozio']]);
                                           $negozio_info = $stmt_neg->fetch(PDO::FETCH_ASSOC);
                                           
                                           // Calcola stato ordine
                                           $data_consegna = strtotime($ordine['data_consegna']);
                                           $oggi = strtotime(date('Y-m-d'));
                                           $differenza_giorni = ($data_consegna - $oggi) / 86400;
                                       ?>
                                       <tr style="border-bottom: 1px solid #eee;">
                                           <td style="padding: 10px;">#<?php echo $ordine['codice']; ?></td>
                                           <td style="padding: 10px;">
                                               <?php echo date('d/m/Y H:i', strtotime($ordine['data_consegna'])); ?>
                                           </td>
                                           <td style="padding: 10px;"><?php echo htmlspecialchars($negozio_info['indirizzo'] ?? 'N/D'); ?></td>
                                           <td style="padding: 10px; font-weight: bold;"><?php echo formatPrice($ordine['totale']); ?></td>
                                           <td style="padding: 10px;">
                                               <?php if ($differenza_giorni < 0): ?>
                                                   <span class="status-badge status-past">Consegnato</span>
                                               <?php elseif ($differenza_giorni == 0): ?>
                                                   <span class="status-badge status-today">Oggi</span>
                                               <?php elseif ($differenza_giorni == 1): ?>
                                                   <span class="status-badge status-tomorrow">Domani</span>
                                               <?php else: ?>
                                                   <span class="status-badge status-future">Tra <?php echo ceil($differenza_giorni); ?> giorni</span>
                                               <?php endif; ?>
                                           </td>
                                       </tr>
                                       <?php endforeach; ?>
                                   </tbody>
                                   <tfoot>
                                       <tr style="background-color: #f5f5f5; font-weight: bold;">
                                           <td colspan="3" style="padding: 10px; text-align: right;">Totale Ordini:</td>
                                           <td style="padding: 10px;">
                                               <?php 
                                               $totale_ordini = array_sum(array_column($ordini, 'totale'));
                                               echo formatPrice($totale_ordini);
                                               ?>
                                           </td>
                                           <td></td>
                                       </tr>
                                   </tfoot>
                               </table>
                               <?php else: ?>
                               <p style="margin-top: 20px; color: #999;">Nessun ordine ancora effettuato presso questo fornitore.</p>
                               <?php endif; ?>
                           </td>
                       </tr>
                       <?php endforeach; ?>
                   </tbody>
               </table>
           </div>
       </div>
   </div>
   
   <script>
       function toggleRow(id) {
           const row = document.getElementById('row-' + id);
           row.classList.toggle('show');
       }
   </script>
</body>
</html>