<?php
require_once 'config.php';
checkManager();

$pdo = getDBConnection();

// Aggiungi nuovo negozio
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['crea_negozio'])) {
    $indirizzo = $_POST['indirizzo'];
    $orario_apertura = $_POST['orario_apertura'];
    $orario_chiusura = $_POST['orario_chiusura'];
    try {
       $stmt = $pdo->prepare("
           INSERT INTO Negozio (indirizzo, cf_responsabile, orario_apertura, orario_chiusura) 
           VALUES (?, ?, ?, ?)
       ");
       $stmt->execute([$indirizzo, $_SESSION['cf'], $orario_apertura, $orario_chiusura]);
       
       setFlashMessage("Negozio creato con successo!", 'success');
   } catch (PDOException $e) {
       setFlashMessage("Errore nella creazione del negozio: " . $e->getMessage(), 'error');
   }
   
   header("Location: gestione_negozi.php");
   exit();
}

// Elimina negozio
if (isset($_GET['elimina'])) {
   $negozio_id = $_GET['elimina'];
   
   try {
       // Verifica che il manager sia responsabile di questo negozio
       $stmt = $pdo->prepare("SELECT codice FROM Negozio WHERE codice = ? AND cf_responsabile = ?");
       $stmt->execute([$negozio_id, $_SESSION['cf']]);
       
       if ($stmt->fetch()) {
           // Il trigger mantieni_storico_tessere si occuperà di salvare le tessere
           $stmt = $pdo->prepare("DELETE FROM Negozio WHERE codice = ?");
           $stmt->execute([$negozio_id]);
           setFlashMessage("Negozio eliminato con successo. Le tessere emesse rimangono valide.", 'success');
       } else {
           setFlashMessage("Non hai i permessi per eliminare questo negozio", 'error');
       }
   } catch (PDOException $e) {
       setFlashMessage("Errore nell'eliminazione del negozio", 'error');
   }
   
   header("Location: gestione_negozi.php");
   exit();
}

// Recupera negozi dove l'utente è responsabile
try {
   $stmt = $pdo->prepare("
       SELECT n.codice, n.indirizzo, n.orario_apertura, n.orario_chiusura,
              COUNT(DISTINCT np.codice_prodotto) as num_prodotti,
              COUNT(DISTINCT o.codice) as ordini_attivi,
              COALESCE(SUM(DISTINCT o.totale), 0) as valore_ordini
       FROM Negozio n
       LEFT JOIN NegozioPossiede np ON n.codice = np.codice_negozio
       LEFT JOIN Ordine o ON n.codice = o.codice_negozio 
                          AND o.data_consegna >= CURRENT_TIMESTAMP
       WHERE n.cf_responsabile = ?
       GROUP BY n.codice, n.indirizzo, n.orario_apertura, n.orario_chiusura
       ORDER BY n.indirizzo
   ");
   $stmt->execute([$_SESSION['cf']]);
   $negozi = $stmt->fetchAll(PDO::FETCH_ASSOC);
   
   // Recupera storico tessere per negozi eliminati usando la vista materializzata
   $stmt = $pdo->query("
       SELECT * FROM materialized_view_storico_tessere
   ");
   $storico_tessere = $stmt->fetchAll(PDO::FETCH_ASSOC);
   
   // Raggruppa storico per negozio
   $storico_per_negozio = [];
   foreach ($storico_tessere as $tessera) {
       $codice_negozio = $tessera['codice_negozio_eliminato'];
       if (!isset($storico_per_negozio[$codice_negozio])) {
           $storico_per_negozio[$codice_negozio] = [];
       }
       $storico_per_negozio[$codice_negozio][] = $tessera;
   }
   
} catch (PDOException $e) {
   $error = "Errore nel recupero dei dati";
}

$flash = getFlashMessage();
?>

<!DOCTYPE html>
<html lang="it">
<head>
   <meta charset="UTF-8">
   <meta name="viewport" content="width=device-width, initial-scale=1.0">
   <title>Gestione Negozi - Manager</title>
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
       
       .header-info {
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
       }
       
       .card-header {
           background-color: #f8f9fa;
           padding: 15px 20px;
           border-bottom: 1px solid #dee2e6;
           font-weight: bold;
           border-radius: 8px 8px 0 0;
       }
       
       .card-body {
           padding: 20px;
       }
       
       .create-form {
           display: grid;
           grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
           gap: 15px;
           margin-bottom: 20px;
       }
       
       .form-group {
           display: flex;
           flex-direction: column;
       }
       
       .form-group label {
           margin-bottom: 5px;
           color: #333;
           font-weight: bold;
       }
       
       .form-group input {
           padding: 10px;
           border: 1px solid #ddd;
           border-radius: 4px;
           font-size: 16px;
       }
       
       .form-group input:focus {
           outline: none;
           border-color: #4CAF50;
       }
       
       .btn-create {
           padding: 12px 30px;
           background-color: #4CAF50;
           color: white;
           border: none;
           border-radius: 4px;
           cursor: pointer;
           font-size: 16px;
           font-weight: bold;
           margin-top: 10px;
       }
       
       .btn-create:hover {
           background-color: #45a049;
       }
       
       .negozi-grid {
           display: grid;
           grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
           gap: 20px;
       }
       
       .negozio-card {
           background: white;
           border-radius: 8px;
           box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
           overflow: hidden;
           transition: transform 0.2s, box-shadow 0.2s;
       }
       
       .negozio-card:hover {
           transform: translateY(-2px);
           box-shadow: 0 4px 8px rgba(0, 0, 0, 0.15);
       }
       
       .negozio-header {
           background-color: #f8f9fa;
           padding: 20px;
           border-bottom: 1px solid #dee2e6;
       }
       
       .negozio-header h3 {
           color: #333;
           margin-bottom: 10px;
       }
       
       .negozio-address {
           color: #666;
           font-size: 14px;
           margin-bottom: 5px;
       }
       
       .negozio-code {
           color: #999;
           font-size: 12px;
       }
       
       .negozio-body {
           padding: 20px;
       }
       
       .negozio-stats {
           display: grid;
           grid-template-columns: 1fr 1fr;
           gap: 15px;
           margin-bottom: 20px;
       }
       
       .stat-item {
           text-align: center;
           padding: 15px;
           background-color: #f8f9fa;
           border-radius: 4px;
       }
       
       .stat-value {
           font-size: 24px;
           font-weight: bold;
           color: #2196F3;
       }
       
       .stat-label {
           color: #666;
           font-size: 12px;
           margin-top: 5px;
       }
       
       .orari-section {
           margin-bottom: 20px;
           padding: 15px;
           background-color: #e3f2fd;
           border-radius: 4px;
       }
       
       .orari-title {
           font-weight: bold;
           margin-bottom: 10px;
           color: #1976D2;
       }
       
       .orari-list {
           font-size: 14px;
           line-height: 1.6;
       }
       
       .btn-gestisci {
           display: block;
           width: 100%;
           padding: 12px;
           background-color: #4CAF50;
           color: white;
           text-align: center;
           text-decoration: none;
           border-radius: 4px;
           font-weight: bold;
           transition: background-color 0.3s;
       }
       
       .btn-gestisci:hover {
           background-color: #45a049;
       }
       
       .btn-elimina {
           background-color: #dc3545;
           color: white;
           padding: 12px;
           text-align: center;
           text-decoration: none;
           border-radius: 4px;
           font-weight: bold;
           display: block;
           margin-top: 10px;
       }
       
       .btn-elimina:hover {
           background-color: #c82333;
       }
       
       .no-negozi {
           background: white;
           padding: 60px 20px;
           text-align: center;
           border-radius: 8px;
           color: #666;
       }
       
       .no-negozi h2 {
           margin-bottom: 20px;
       }
       
       .alert-ordini {
           display: inline-block;
           background-color: #ff9800;
           color: white;
           padding: 4px 8px;
           border-radius: 12px;
           font-size: 12px;
           margin-left: 10px;
       }
       
       .info-text {
           color: #666;
           font-size: 14px;
           margin-top: 10px;
       }
       
       .value-badge {
           background-color: #4CAF50;
           color: white;
           padding: 4px 8px;
           border-radius: 4px;
           font-size: 12px;
           margin-left: 10px;
       }
   </style>
</head>
<body>
   <nav class="navbar">
       <h1>I Miei Negozi</h1>
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
               Crea Nuovo Negozio
           </div>
           <div class="card-body">
               <form method="POST">
                   <div class="create-form">
                       <div class="form-group">
                           <label for="indirizzo">Indirizzo del Negozio</label>
                           <input type="text" id="indirizzo" name="indirizzo" 
                                  placeholder="Es: Via Roma 1, Milano" required>
                       </div>
                       
                       <div class="form-group">
                           <label for="orario_apertura">Orario Apertura</label>
                           <input type="time" id="orario_apertura" name="orario_apertura" 
                                  value="09:00" required>
                       </div>
                       
                       <div class="form-group">
                           <label for="orario_chiusura">Orario Chiusura</label>
                           <input type="time" id="orario_chiusura" name="orario_chiusura" 
                                  value="19:00" required>
                       </div>
                   </div>
                   
                   <div class="info-text">
                       Il negozio sarà automaticamente assegnato a te come responsabile.
                   </div>
                   
                   <button type="submit" name="crea_negozio" class="btn-create">
                       + Crea Negozio
                   </button>
               </form>
           </div>
       </div>
       
       <?php if (!empty($storico_per_negozio)): ?>
       <div class="header-info" style="margin-top: 30px;">
           <h2>Storico Emissioni - Negozi Eliminati</h2>
           <p>Registro delle tessere emesse da negozi non più attivi (le tessere rimangono valide)</p>
       </div>
       
       <?php foreach ($storico_per_negozio as $codice_negozio => $tessere): ?>
       <div class="card" style="background: #fff8dc; border: 1px solid #ffc107; margin-bottom: 20px;">
           <div class="card-header" style="background-color: #ffc107; color: #000;">
               <h3>Negozio Chiuso #<?php echo $codice_negozio; ?></h3>
           </div>
           <div class="card-body">
               <p style="margin-bottom: 15px; color: #856404;">
                   <strong>ℹ️ Nota:</strong> Le tessere emesse da questo negozio rimangono valide e utilizzabili.
               </p>
               <p style="margin-bottom: 15px;">
                   <strong>Tessere emesse:</strong> <?php echo count($tessere); ?>
               </p>
               <table style="width: 100%; border-collapse: collapse;">
                   <thead>
                       <tr style="background-color: #f8f9fa;">
                           <th style="padding: 10px; border: 1px solid #ddd;">Cliente</th>
                           <th style="padding: 10px; border: 1px solid #ddd;">CF</th>
                           <th style="padding: 10px; border: 1px solid #ddd;">Data emissione</th>
                           <th style="padding: 10px; border: 1px solid #ddd;">Punti alla chiusura</th>
                       </tr>
                   </thead>
                   <tbody>
                       <?php foreach ($tessere as $tessera): ?>
                       <tr>
                           <td style="padding: 10px; border: 1px solid #ddd;">
                               <?php echo htmlspecialchars($tessera['nome'] . ' ' . $tessera['cognome']); ?>
                           </td>
                           <td style="padding: 10px; border: 1px solid #ddd;">
                               <?php echo htmlspecialchars($tessera['cf_cliente']); ?>
                           </td>
                           <td style="padding: 10px; border: 1px solid #ddd;">
                               <?php echo date('d/m/Y', strtotime($tessera['data_richiesta'])); ?>
                           </td>
                           <td style="padding: 10px; border: 1px solid #ddd;">
                               <strong><?php echo $tessera['punti_al_momento_eliminazione']; ?></strong> punti
                           </td>
                       </tr>
                       <?php endforeach; ?>
                   </tbody>
               </table>
           </div>
       </div>
       <?php endforeach; ?>
       <?php endif; ?>
       
       <div class="header-info">
           <h2>Negozi sotto la tua gestione</h2>
           <p>Seleziona un negozio per gestire prodotti e ordini</p>
       </div>
       
       <?php if (empty($negozi)): ?>
       <div class="no-negozi">
           <h2>Nessun negozio assegnato</h2>
           <p>Crea il tuo primo negozio utilizzando il form sopra.</p>
       </div>
       <?php else: ?>
       <div class="negozi-grid">
           <?php foreach ($negozi as $negozio): ?>
           <div class="negozio-card">
               <div class="negozio-header">
                   <h3>
                       Negozio #<?php echo $negozio['codice']; ?>
                       <?php if ($negozio['ordini_attivi'] > 0): ?>
                       <span class="alert-ordini"><?php echo $negozio['ordini_attivi']; ?> ordini in arrivo</span>
                       <?php endif; ?>
                       <?php if ($negozio['valore_ordini'] > 0): ?>
                       <span class="value-badge"><?php echo formatPrice($negozio['valore_ordini']); ?></span>
                       <?php endif; ?>
                   </h3>
                   <div class="negozio-address"><?php echo htmlspecialchars($negozio['indirizzo']); ?></div>
               </div>
               
               <div class="negozio-body">
                   <div class="negozio-stats">
                       <div class="stat-item">
                           <div class="stat-value"><?php echo $negozio['num_prodotti']; ?></div>
                           <div class="stat-label">Prodotti in catalogo</div>
                       </div>
                       <div class="stat-item">
                           <div class="stat-value"><?php echo $negozio['ordini_attivi']; ?></div>
                           <div class="stat-label">Ordini in arrivo</div>
                       </div>
                   </div>
                   
                   <?php if ($negozio['orario_apertura'] && $negozio['orario_chiusura']): ?>
                   <div class="orari-section">
                       <div class="orari-title">Orari:</div>
                       <div class="orari-list">
                           <?php 
                           echo substr($negozio['orario_apertura'], 0, 5) . ' - ' . substr($negozio['orario_chiusura'], 0, 5);
                           ?>
                       </div>
                   </div>
                   <?php endif; ?>
                   
                   <a href="negozio_dettaglio.php?id=<?php echo $negozio['codice']; ?>" class="btn-gestisci">
                       Gestisci Negozio →
                   </a>
                   
                   <a href="?elimina=<?php echo $negozio['codice']; ?>" 
                      class="btn-elimina"
                      onclick="return confirm('Sei sicuro di voler eliminare questo negozio? Le tessere emesse rimarranno valide.')">
                       Elimina Negozio
                   </a>
               </div>
           </div>
           <?php endforeach; ?>
       </div>
       <?php endif; ?>
   </div>
</body>
</html>