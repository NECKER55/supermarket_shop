BEGIN TRANSACTION;

CREATE SCHEMA IF NOT EXISTS società;

SET search_path TO società;

-- =====================================================
-- TABELLE PRINCIPALI (RIMANGONO INVARIATE)
-- =====================================================

CREATE TABLE Persona (
    cf CHAR(16) PRIMARY KEY,
    nome VARCHAR(50) NOT NULL,
    cognome VARCHAR(50) NOT NULL
);

CREATE TABLE Fornitore (
    p_iva CHAR(11) PRIMARY KEY,
    indirizzo VARCHAR(100) NOT NULL
);

CREATE TABLE Negozio (
    codice SERIAL PRIMARY KEY,
    indirizzo VARCHAR(100) NOT NULL,
    cf_responsabile CHAR(16) NOT NULL,
    orario_apertura TIME NOT NULL,
    orario_chiusura TIME NOT NULL,
    FOREIGN KEY (cf_responsabile) REFERENCES Persona(cf) ON UPDATE CASCADE
);

CREATE TABLE Prodotto (
    codice SERIAL PRIMARY KEY,
    nome VARCHAR(50) NOT NULL,
    descrizione TEXT NOT NULL
);

CREATE TABLE Ordine (
    codice SERIAL PRIMARY KEY,
    totale DECIMAL(10, 2) NOT NULL DEFAULT 0 CHECK (totale >= 0),
    data_consegna TIMESTAMP NOT NULL,
    codice_negozio INT NOT NULL,
    codice_fornitore CHAR(11) NOT NULL,
    FOREIGN KEY (codice_fornitore) REFERENCES Fornitore(p_iva) ON DELETE CASCADE ON UPDATE CASCADE,
    FOREIGN KEY (codice_negozio) REFERENCES Negozio(codice) ON DELETE CASCADE ON UPDATE CASCADE
);

CREATE TABLE OrdineContiene (
    codice_ordine INT NOT NULL, 
    codice_prodotto INT NOT NULL, 
    quantita INT NOT NULL CHECK (quantita > 0), 
    prezzo DECIMAL(10,2) NOT NULL CHECK (prezzo > 0),
    PRIMARY KEY (codice_ordine, codice_prodotto),
    FOREIGN KEY (codice_ordine) REFERENCES Ordine(codice) ON DELETE CASCADE ON UPDATE CASCADE,
    FOREIGN KEY (codice_prodotto) REFERENCES Prodotto(codice) ON DELETE CASCADE ON UPDATE CASCADE
);

CREATE TABLE FornitorePossiede (
    codice_fornitore CHAR(11) NOT NULL,
    codice_prodotto INT NOT NULL,
    prezzo DECIMAL(10,2) NOT NULL CHECK (prezzo > 0),
    quantita INT NOT NULL CHECK (quantita >= 0),
    PRIMARY KEY (codice_fornitore, codice_prodotto),
    FOREIGN KEY (codice_fornitore) REFERENCES Fornitore(p_iva) ON DELETE CASCADE ON UPDATE CASCADE,
    FOREIGN KEY (codice_prodotto) REFERENCES Prodotto(codice) ON DELETE CASCADE ON UPDATE CASCADE
);

CREATE TABLE Tessera (
    cf_cliente CHAR(16) PRIMARY KEY,
    negozio INT,
    punti INT NOT NULL DEFAULT 0 CHECK (punti >= 0),
    data_richiesta TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (cf_cliente) REFERENCES Persona(cf) ON DELETE CASCADE ON UPDATE CASCADE,
    FOREIGN KEY (negozio) REFERENCES Negozio(codice) ON DELETE SET NULL ON UPDATE CASCADE
);

CREATE TABLE StoricoTessere (
    cf_cliente CHAR(16) NOT NULL,
    codice_negozio_eliminato INT NOT NULL,
    punti_al_momento_eliminazione INT NOT NULL,
    data_richiesta TIMESTAMP NOT NULL,
    data_eliminazione_negozio TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (cf_cliente, codice_negozio_eliminato, data_eliminazione_negozio),
    FOREIGN KEY (cf_cliente) REFERENCES Persona(cf) ON DELETE CASCADE ON UPDATE CASCADE
);

CREATE TABLE Credenziali (
    username VARCHAR(50) PRIMARY KEY,
    password VARCHAR(128) NOT NULL,
    cf_persona CHAR(16) NOT NULL,
    manager BOOLEAN NOT NULL DEFAULT FALSE,
    FOREIGN KEY (cf_persona) REFERENCES Persona(cf) ON DELETE CASCADE ON UPDATE CASCADE,
    UNIQUE(cf_persona, manager)
);

CREATE TABLE NegozioPossiede (
    codice_prodotto INT NOT NULL,
    codice_negozio INT NOT NULL,
    prezzo DECIMAL(10,2) NOT NULL CHECK (prezzo > 0),
    quantita INT NOT NULL CHECK (quantita >= 0),
    PRIMARY KEY (codice_prodotto, codice_negozio),
    FOREIGN KEY (codice_prodotto) REFERENCES Prodotto(codice) ON DELETE CASCADE ON UPDATE CASCADE,
    FOREIGN KEY (codice_negozio) REFERENCES Negozio(codice) ON DELETE CASCADE ON UPDATE CASCADE
);


CREATE TABLE Fattura (
    codice SERIAL PRIMARY KEY,
    totale DECIMAL(10,2) NOT NULL DEFAULT 0 CHECK (totale >= 0),
    sconto INTEGER NOT NULL DEFAULT 0 CHECK (sconto >= 0),
    codice_negozio INT NOT NULL,
    cf_cliente CHAR(16) NOT NULL,
    data_acquisto TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (codice_negozio) REFERENCES Negozio(codice) ON DELETE CASCADE ON UPDATE CASCADE,
    FOREIGN KEY (cf_cliente) REFERENCES Persona(cf) ON DELETE CASCADE ON UPDATE CASCADE
);

CREATE TABLE FatturaContiene (
    codice_fattura INT NOT NULL,
    codice_prodotto INT NOT NULL,
    prezzo DECIMAL(10,2) NOT NULL CHECK (prezzo > 0),
    quantita INT NOT NULL CHECK (quantita > 0),
    PRIMARY KEY (codice_fattura, codice_prodotto),
    FOREIGN KEY (codice_fattura) REFERENCES Fattura(codice) ON DELETE CASCADE ON UPDATE CASCADE,
    FOREIGN KEY (codice_prodotto) REFERENCES Prodotto(codice) ON DELETE CASCADE ON UPDATE CASCADE
);

-- =====================================================
-- VISTE MATERIALIZZATE CORRETTE
-- =====================================================

-- CORREZIONE: Vista materializzata con alias per evitare conflitti di nomi
CREATE MATERIALIZED VIEW materialized_view_utenti_piu_300_punti AS
SELECT 
    p.cf as persona_cf,
    p.nome as persona_nome,
    p.cognome as persona_cognome,
    t.cf_cliente as tessera_cf_cliente,
    t.negozio as tessera_negozio,
    t.punti as tessera_punti,
    t.data_richiesta as tessera_data_richiesta
FROM Persona p
JOIN Tessera t ON t.cf_cliente = p.cf
WHERE t.punti > 300;

-- CORREZIONE: Funzione trigger che restituisce correttamente NULL per trigger AFTER
CREATE OR REPLACE FUNCTION update_utenti_piu_300_punti()
RETURNS TRIGGER AS $$
BEGIN
    IF (TG_OP = 'DELETE') THEN
        IF (OLD.punti > 300) THEN
            REFRESH MATERIALIZED VIEW materialized_view_utenti_piu_300_punti;
        END IF;
    ELSIF (TG_OP IN ('INSERT', 'UPDATE')) THEN
        IF ((NEW.punti > 300) OR (TG_OP = 'UPDATE' AND OLD.punti > 300)) THEN
            REFRESH MATERIALIZED VIEW materialized_view_utenti_piu_300_punti;
        END IF;
    END IF;
    RETURN NULL;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trigger_utenti_piu_300_punti
AFTER INSERT OR UPDATE OR DELETE ON Tessera
FOR EACH ROW
EXECUTE FUNCTION update_utenti_piu_300_punti();

-- CORREZIONE: Vista più sensata per lo storico
CREATE MATERIALIZED VIEW materialized_view_storico_tessere AS
SELECT 
    st.*,
    p.nome,
    p.cognome
FROM StoricoTessere st
JOIN Persona p ON p.cf = st.cf_cliente
ORDER BY st.data_eliminazione_negozio DESC;

-- CORREZIONE: Trigger corretto per aggiornare lo storico
CREATE OR REPLACE FUNCTION update_storico_tessere()
RETURNS TRIGGER AS $$
BEGIN
    REFRESH MATERIALIZED VIEW materialized_view_storico_tessere;
    RETURN NULL;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trigger_update_storico_tessere
AFTER INSERT OR UPDATE OR DELETE ON StoricoTessere
FOR EACH ROW
EXECUTE FUNCTION update_storico_tessere();

-- =====================================================
-- FUNZIONI CORE CORRETTE
-- =====================================================

CREATE OR REPLACE FUNCTION get_ordini_fornitore(fornitore CHAR(11))
RETURNS SETOF Ordine AS $$
BEGIN
    RETURN QUERY 
    SELECT o.*
    FROM Ordine o
    WHERE o.codice_fornitore = fornitore;
END;
$$ LANGUAGE plpgsql;

-- Funzione per calcolare i punti necessari per uno sconto
CREATE OR REPLACE FUNCTION punti_necessari_sconto(percentuale_sconto INT)
RETURNS INT AS $$
BEGIN
    CASE percentuale_sconto
        WHEN 5 THEN RETURN 100;
        WHEN 15 THEN RETURN 200;
        WHEN 30 THEN RETURN 300;
        ELSE RETURN 0;
    END CASE;
END;
$$ LANGUAGE plpgsql;

CREATE OR REPLACE FUNCTION trova_fornitore_economico(cod_prodotto INT, quantita_richiesta INT)
RETURNS CHAR(11) AS $$
DECLARE
    fornitore_scelto CHAR(11);
BEGIN
    SELECT codice_fornitore
    INTO fornitore_scelto
    FROM FornitorePossiede
    WHERE codice_prodotto = cod_prodotto 
      AND quantita >= quantita_richiesta
    ORDER BY prezzo ASC
    LIMIT 1;
    
    IF fornitore_scelto IS NULL THEN
        RAISE EXCEPTION 'Nessun fornitore disponibile per il prodotto % con quantità %', cod_prodotto, quantita_richiesta;
    END IF;
    
    RETURN fornitore_scelto;
END;
$$ LANGUAGE plpgsql;


CREATE OR REPLACE FUNCTION get_prodotti_negozio(cod_negozio INT)
RETURNS TABLE(
    codice INT,
    nome VARCHAR(50),
    descrizione TEXT,
    prezzo DECIMAL(10,2),
    quantita INT
) AS $$
BEGIN
    RETURN QUERY
    SELECT p.codice, p.nome, p.descrizione, np.prezzo, np.quantita
    FROM Prodotto p
    JOIN NegozioPossiede np ON p.codice = np.codice_prodotto
    WHERE np.codice_negozio = cod_negozio AND np.quantita > 0
    ORDER BY p.nome;
END;
$$ LANGUAGE plpgsql;

CREATE OR REPLACE FUNCTION get_storico_cliente(cf_cliente_input CHAR(16))
RETURNS TABLE(
    codice_fattura INT,
    data_acquisto TIMESTAMP,
    totale DECIMAL(10,2),
    sconto INT,
    negozio_indirizzo VARCHAR(100)
) AS $$
BEGIN
    RETURN QUERY
    SELECT f.codice, f.data_acquisto, f.totale, f.sconto, n.indirizzo
    FROM Fattura f
    JOIN Negozio n ON f.codice_negozio = n.codice
    WHERE f.cf_cliente = cf_cliente_input
    ORDER BY f.data_acquisto DESC;
END;
$$ LANGUAGE plpgsql;

-- CORREZIONE: Parametro rinominato per evitare ambiguità
CREATE OR REPLACE FUNCTION verifica_sconto_disponibile(cf_cliente_param CHAR(16))
RETURNS TABLE(
    sconto_5 BOOLEAN,
    sconto_15 BOOLEAN,
    sconto_30 BOOLEAN,
    punti_attuali INT
) AS $$
BEGIN
    RETURN QUERY
    SELECT 
        t.punti >= 100 AS sconto_5,
        t.punti >= 200 AS sconto_15,
        t.punti >= 300 AS sconto_30,
        t.punti AS punti_attuali
    FROM Tessera t
    WHERE t.cf_cliente = cf_cliente_param;
END;
$$ LANGUAGE plpgsql;

-- =====================================================
-- TRIGGER CORRETTI
-- =====================================================

CREATE OR REPLACE FUNCTION controllo_inserimento_responsabile()
RETURNS TRIGGER AS $$
DECLARE
    is_manager BOOLEAN;
BEGIN
    SELECT manager
    INTO is_manager
    FROM Credenziali
    WHERE cf_persona = NEW.cf_responsabile;

    IF NOT FOUND OR is_manager IS DISTINCT FROM TRUE THEN
        RAISE EXCEPTION 'Il codice fiscale % inserito come responsabile non corrisponde a un utente amministratore.', NEW.cf_responsabile;
    END IF;

    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trigger_controllo_responsabile
BEFORE INSERT OR UPDATE OF cf_responsabile ON Negozio
FOR EACH ROW
EXECUTE FUNCTION controllo_inserimento_responsabile();

CREATE OR REPLACE FUNCTION aggiorna_totale_ordine()
RETURNS TRIGGER AS $$
BEGIN
    UPDATE Ordine
    SET totale = (
        SELECT COALESCE(SUM(quantita * prezzo), 0)
        FROM OrdineContiene
        WHERE codice_ordine = COALESCE(NEW.codice_ordine, OLD.codice_ordine)
    )
    WHERE codice = COALESCE(NEW.codice_ordine, OLD.codice_ordine);
    
    RETURN COALESCE(NEW, OLD);
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trigger_aggiorna_totale_ordine
AFTER INSERT OR UPDATE OR DELETE ON OrdineContiene
FOR EACH ROW
EXECUTE FUNCTION aggiorna_totale_ordine();

CREATE OR REPLACE FUNCTION aggiorna_disponibilita_fornitore()
RETURNS TRIGGER AS $$
BEGIN
    UPDATE FornitorePossiede
    SET quantita = quantita - NEW.quantita
    WHERE codice_fornitore = (
        SELECT codice_fornitore 
        FROM Ordine 
        WHERE codice = NEW.codice_ordine
    )
    AND codice_prodotto = NEW.codice_prodotto;
    
    IF EXISTS (
        SELECT 1 FROM FornitorePossiede 
        WHERE codice_fornitore = (
            SELECT codice_fornitore 
            FROM Ordine 
            WHERE codice = NEW.codice_ordine
        )
        AND codice_prodotto = NEW.codice_prodotto
        AND quantita < 0
    ) THEN
        RAISE EXCEPTION 'Quantità insufficiente presso il fornitore per il prodotto %', NEW.codice_prodotto;
    END IF;
    
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trigger_aggiorna_disponibilita_fornitore
AFTER INSERT ON OrdineContiene
FOR EACH ROW
EXECUTE FUNCTION aggiorna_disponibilita_fornitore();


-- Trigger per applicazione sconto e decurtazione punti
CREATE OR REPLACE FUNCTION applica_sconto_fattura()
RETURNS TRIGGER AS $$
DECLARE
    punti_attuali INT;
    punti_necessari INT;
    sconto_euro DECIMAL(10,2);
BEGIN
    -- Se è stato applicato uno sconto, decurta i punti
    IF NEW.sconto > 0 THEN
        SELECT punti INTO punti_attuali
        FROM Tessera
        WHERE cf_cliente = NEW.cf_cliente;
        
        punti_necessari := punti_necessari_sconto(NEW.sconto);
        
        -- Controlla che il cliente abbia abbastanza punti
        IF punti_attuali < punti_necessari THEN
            RAISE EXCEPTION 'Punti insufficienti per applicare lo sconto del %: Punti disponibili: %, Punti necessari: %', 
                NEW.sconto, punti_attuali, punti_necessari;
        END IF;
        
        -- Calcola lo sconto in euro (massimo 100 euro)
        sconto_euro := LEAST(NEW.totale * NEW.sconto / 100.0, 100.0);
        
        -- Aggiorna il totale della fattura
        NEW.totale := NEW.totale - sconto_euro;
        
        -- Decurta i punti dalla tessera
        UPDATE Tessera
        SET punti = punti - punti_necessari
        WHERE cf_cliente = NEW.cf_cliente;
        
    END IF;
    
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trigger_applica_sconto_fattura
    BEFORE INSERT ON Fattura
    FOR EACH ROW
    EXECUTE FUNCTION applica_sconto_fattura();

-- Trigger per aggiornamento totale fattura
CREATE OR REPLACE FUNCTION aggiorna_totale_fattura()
RETURNS TRIGGER AS $$
BEGIN
    UPDATE Fattura
    SET totale = (
        SELECT COALESCE(SUM(quantita * prezzo), 0)
        FROM FatturaContiene
        WHERE codice_fattura = COALESCE(NEW.codice_fattura, OLD.codice_fattura)
    )
    WHERE codice = COALESCE(NEW.codice_fattura, OLD.codice_fattura);
    
    RETURN COALESCE(NEW, OLD);
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trigger_aggiorna_totale_fattura
    AFTER INSERT OR UPDATE OR DELETE ON FatturaContiene
    FOR EACH ROW
    EXECUTE FUNCTION aggiorna_totale_fattura();


-- Trigger per aggiornamento punti tessera fedeltà
CREATE OR REPLACE FUNCTION aggiorna_punti_tessera()
RETURNS TRIGGER AS $$
BEGIN
    -- Aggiunge punti (1 punto per ogni euro speso)
    UPDATE Tessera
    SET punti = punti + FLOOR(NEW.totale)
    WHERE cf_cliente = NEW.cf_cliente;
    
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trigger_aggiorna_punti_tessera
    AFTER INSERT ON Fattura
    FOR EACH ROW
    EXECUTE FUNCTION aggiorna_punti_tessera();

CREATE OR REPLACE FUNCTION aggiorna_scorte_negozio()
RETURNS TRIGGER AS $$
BEGIN
    UPDATE NegozioPossiede
    SET quantita = quantita - NEW.quantita
    WHERE codice_prodotto = NEW.codice_prodotto
      AND codice_negozio = (
          SELECT codice_negozio 
          FROM Fattura 
          WHERE codice = NEW.codice_fattura
      );
    
    IF EXISTS (
        SELECT 1 FROM NegozioPossiede 
        WHERE codice_prodotto = NEW.codice_prodotto
          AND codice_negozio = (
              SELECT codice_negozio 
              FROM Fattura 
              WHERE codice = NEW.codice_fattura
          )
          AND quantita < 0
    ) THEN
        RAISE EXCEPTION 'Quantità insufficiente in negozio per il prodotto %', NEW.codice_prodotto;
    END IF;
    
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trigger_aggiorna_scorte_negozio
AFTER INSERT ON FatturaContiene
FOR EACH ROW
EXECUTE FUNCTION aggiorna_scorte_negozio();

-- CORREZIONE: Gestione più intelligente del prezzo nel negozio
CREATE OR REPLACE FUNCTION aggiorna_scorte_da_ordine()
RETURNS TRIGGER AS $$
DECLARE
    prezzo_esistente DECIMAL(10,2);
    quantita_esistente INT;
BEGIN
    -- Verifica se il prodotto esiste già nel negozio
    SELECT prezzo, quantita
    INTO prezzo_esistente, quantita_esistente
    FROM NegozioPossiede
    WHERE codice_prodotto = NEW.codice_prodotto
      AND codice_negozio = (
          SELECT codice_negozio 
          FROM Ordine 
          WHERE codice = NEW.codice_ordine
      );
    
    IF FOUND THEN
        -- Se esiste, aggiorna solo la quantità
        UPDATE NegozioPossiede
        SET quantita = quantita + NEW.quantita
        WHERE codice_prodotto = NEW.codice_prodotto
          AND codice_negozio = (
              SELECT codice_negozio 
              FROM Ordine 
              WHERE codice = NEW.codice_ordine
          );
    ELSE
        -- Se non esiste, inserisce con markup del 30%
        INSERT INTO NegozioPossiede (codice_prodotto, codice_negozio, quantita, prezzo)
        SELECT 
            NEW.codice_prodotto,
            o.codice_negozio,
            NEW.quantita,
            NEW.prezzo * 1.3
        FROM Ordine o
        WHERE o.codice = NEW.codice_ordine;
    END IF;
    
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trigger_aggiorna_scorte_da_ordine
AFTER INSERT ON OrdineContiene
FOR EACH ROW
EXECUTE FUNCTION aggiorna_scorte_da_ordine();

CREATE OR REPLACE FUNCTION mantieni_storico_tessere()
RETURNS TRIGGER AS $$
BEGIN
    INSERT INTO StoricoTessere (cf_cliente, codice_negozio_eliminato, punti_al_momento_eliminazione, data_richiesta)
    SELECT cf_cliente, OLD.codice, punti, data_richiesta
    FROM Tessera
    WHERE negozio = OLD.codice;
    
    RETURN OLD;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trigger_mantieni_storico_tessere
BEFORE DELETE ON Negozio
FOR EACH ROW
EXECUTE FUNCTION mantieni_storico_tessere();

-- =====================================================
-- INDICI PER OTTIMIZZAZIONE
-- =====================================================

CREATE INDEX idx_fattura_cf_cliente ON Fattura(cf_cliente);
CREATE INDEX idx_fattura_data_acquisto ON Fattura(data_acquisto);
CREATE INDEX idx_fattura_negozio ON Fattura(codice_negozio);

CREATE INDEX idx_ordine_data_consegna ON Ordine(data_consegna);
CREATE INDEX idx_ordine_negozio ON Ordine(codice_negozio);

CREATE INDEX idx_tessera_punti ON Tessera(punti);
CREATE INDEX idx_negozio_possiede_quantita ON NegozioPossiede(quantita);

-- =====================================================
-- DATI DI ESEMPIO
-- =====================================================

INSERT INTO Persona (cf, nome, cognome) VALUES 
('RSSMRA80A01H501Z', 'Mario', 'Rossi'),
('VRDLGI85B15F205X', 'Luigi', 'Verdi'),
('BNCGIA90C25L736Y', 'Giulia', 'Bianchi'),
('NRGFRN88D12A662K', 'Francesco', 'Neri'),
('MNTLRA92E20B354T', 'Laura', 'Monti');

INSERT INTO Credenziali (username, password, cf_persona, manager) VALUES
('admin', 'admin123', 'RSSMRA80A01H501Z', TRUE),
('lverdi', 'password123', 'VRDLGI85B15F205X', FALSE),
('gbianchi', 'password123', 'BNCGIA90C25L736Y', FALSE),
('fneri', 'password123', 'NRGFRN88D12A662K', FALSE),
('lmonti', 'password123', 'MNTLRA92E20B354T', FALSE);

INSERT INTO Negozio (indirizzo, cf_responsabile, orario_apertura, orario_chiusura) VALUES
('Via Roma 1, Milano', 'RSSMRA80A01H501Z', '09:00:00', '19:00:00'),
('Via Garibaldi 15, Roma', 'RSSMRA80A01H501Z', '08:30:00', '20:00:00');

INSERT INTO Fornitore (p_iva, indirizzo) VALUES
('12345678901', 'Via Industria 10, Torino'),
('98765432109', 'Via Commercio 5, Napoli'),
('11223344556', 'Via Logistica 8, Bologna');

INSERT INTO Prodotto (nome, descrizione) VALUES
('Smartphone XYZ', 'Smartphone di ultima generazione con fotocamera avanzata'),
('Laptop ABC', 'Computer portatile per uso professionale'),
('Tablet DEF', 'Tablet per intrattenimento e lavoro'),
('Cuffie GHI', 'Cuffie wireless con cancellazione del rumore'),
('Mouse JKL', 'Mouse ergonomico per computer');

INSERT INTO FornitorePossiede (codice_fornitore, codice_prodotto, prezzo, quantita) VALUES
('12345678901', 1, 299.99, 50),
('98765432109', 1, 289.99, 30),
('12345678901', 2, 799.99, 25),
('11223344556', 2, 759.99, 40),
('98765432109', 3, 199.99, 60),
('12345678901', 4, 89.99, 100),
('11223344556', 5, 29.99, 200);

INSERT INTO NegozioPossiede (codice_prodotto, codice_negozio, prezzo, quantita) VALUES
(1, 1, 329.99, 10),
(1, 2, 319.99, 8),
(2, 1, 849.99, 5),
(2, 2, 829.99, 7),
(3, 1, 229.99, 12),
(4, 1, 99.99, 15),
(5, 1, 34.99, 25);

INSERT INTO Tessera (cf_cliente, negozio, punti, data_richiesta) VALUES
('VRDLGI85B15F205X', 1, 350, '2024-01-15 10:00:00'),
('BNCGIA90C25L736Y', 1, 120, '2024-02-01 14:30:00'),
('NRGFRN88D12A662K', 2, 80, '2024-01-20 09:15:00'),
('MNTLRA92E20B354T', 2, 450, '2024-01-10 16:45:00');

-- Refresh iniziale delle viste materializzate
REFRESH MATERIALIZED VIEW materialized_view_utenti_piu_300_punti;
REFRESH MATERIALIZED VIEW materialized_view_storico_tessere;

COMMIT;
