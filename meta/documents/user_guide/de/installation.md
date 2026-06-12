# Mirka Belt Calculator – Installation und Test

## Was macht dieses Plugin?

Wenn ein Kunde im Konfigurator ein Mirka-Schleifband konfiguriert und auf "In den Warenkorb" klickt, springt dieses Plugin an, ruft die Mirka Belt Calculator API auf, wendet Rabatt und Marge an und überschreibt den Artikelpreis im Warenkorb mit dem berechneten BERMARO-Verkaufspreis.

Die Preisberechnung passiert **serverseitig** – Manipulation aus dem Browser ist nicht möglich.

## Voraussetzungen

* Plentymarkets LTE mit Ceres-Theme
* Sammelartikel "Mirka Schleifband konfiguriert" angelegt (BERMARO-Variations-ID: 22994)
* Sechs Bestelleigenschaften angelegt und dem Sammelartikel zugeordnet (BERMARO-IDs: 64–69)
* Für Echt-Betrieb: Cloud Function als Mirka-Proxy (kann anfangs leer bleiben → Mock-Modus)

## Installation

1. Plenty-Backend öffnen.
2. **Plugins → Plugin-Übersicht** öffnen, aktives Plugin-Set anklicken.
3. ZIP-Datei dieses Plugins hochladen.
4. Plugin installieren.
5. Plugin aktivieren (Schalter grün).
6. **Plugin-Set deployen**.

## Konfiguration

Auf das Zahnrad-Symbol des Plugins klicken. Sechs Reiter sind verfügbar:

### Reiter "1. Artikel"

| Einstellung | BERMARO-Wert |
|---|---|
| Variations-ID des Sammelartikels (Produktion) | 22994 |
| Variations-ID für Tests (optional) | leer oder eigene Test-Artikel-ID |

**Tipp:** Wenn du erstmal einen separaten Test-Artikel anlegen möchtest (in einer versteckten Kategorie), trag die Test-Variations-ID hier ein. Das Plugin behandelt dann beide IDs – produktiv und Test.

### Reiter "2. Mirka-Anbindung"

| Einstellung | Wert |
|---|---|
| Cloud-Function-URL | leer lassen oder echte URL nach Cloud-Function-Setup |
| API-Timeout in Sekunden | 10 |

### Reiter "3. Preise"

| Einstellung | Wert |
|---|---|
| Standard-Rabatt | 0.52 |
| Margenfaktor | 0.5 |

### Reiter "4. Bestelleigenschaften"

Standardwerte sind die BERMARO-IDs 64–69 — nichts anpassen.

### Reiter "5. Rabatte pro Schleifmittel"

Alle 16 Werte stehen auf 0.52. Ultimax und Ultimax Black: Wert ggf. nach Mirka-Klärung anpassen.

### Reiter "6. Test & Debug" ⭐ WICHTIG für den ersten Test

| Einstellung | Empfehlung beim ersten Test | Produktiv-Betrieb |
|---|---|---|
| Debug-Modus | **AN** | AUS |
| Mock-Modus | **AN** | AUS |
| Mock-UVP in EUR | 100.00 | — |

**Erklärung:**

- **Debug-Modus AN:** Im Plenty-Log werden ausführliche Einträge geschrieben. Außerdem wird am Mirka-Artikelnummer-Feld in der Warenkorb-Position ein Debug-Text angehängt, sodass du **direkt im Warenkorb siehst**, was das Plugin gemacht hat.

- **Mock-Modus AN:** Es wird KEIN echter Mirka-API-Aufruf gemacht. Stattdessen nimmt das Plugin den Mock-UVP (Standard: 100 €). Damit kannst du das Plugin **testen, bevor die Cloud Function läuft**.

## Test-Strategie (empfohlen)

### Schritt 1 – Plugin im Mock-/Debug-Modus installieren

- Mock-Modus AN, Debug-Modus AN, Mock-UVP 100 €
- Sammelartikel **noch nicht** öffentlich freischalten
- Stattdessen den Sammelartikel in eine **versteckte Test-Kategorie** legen (oder nur über Direkt-Link im Konfigurator zugänglich)

### Schritt 2 – Test-Bestellung durchführen

Über den Konfigurator (oder per Test-Aufruf mit manuell gesetzten Bestelleigenschaften):
- Artikel in den Warenkorb legen
- Im Warenkorb sollte ein Preis von **72,00 €** stehen (100 × 0.48 × 1.5)
- Direkt unter dem Mirka-Artikelnummer-Eintrag steht z. B.:
  `[DEBUG: OK | Quelle: mock | UVP: 100.00 EUR | Rabatt: 52% | EK: 48.00 EUR | Marge: x1.50 | VK: 72.00 EUR]`

Falls das funktioniert: Das Plugin selbst arbeitet korrekt.

### Schritt 3 – Cloud Function einrichten

Im Plugin "Cloud-Function-URL" eintragen, Mock-Modus auf AUS stellen, Plugin-Set neu deployen. Plugin holt jetzt echte UVPs.

### Schritt 4 – Produktivschalten

Wenn alles passt:
- Debug-Modus AUS
- Sammelartikel öffentlich freischalten

## Wo finde ich die Plenty-Logs?

**Plenty-Backend → Daten → Log → "MirkaBeltCalculator"** als Suchbegriff.

Dort siehst du alle Plugin-Aktionen mit Detaildaten.

## Wo sehe ich Debug-Infos im Frontend/Warenkorb?

Im Warenkorb-Eintrag des konfigurierten Bandes wird die Mirka-Artikelnummer angezeigt. Im Debug-Modus wird dort am Ende ein `[DEBUG: ...]`-Block angehängt, sichtbar im Warenkorb und auch in der späteren Bestellung.

Im Produktiv-Modus (Debug-Modus AUS) wird dieser Block weggelassen – die Mirka-Artikelnummer ist dann sauber.

## Fehlersuche

| Symptom | Mögliche Ursache | Lösung |
|---|---|---|
| Preis bleibt 1,00 € im Warenkorb | Plugin greift nicht – Variations-ID falsch eingetragen | Reiter 1, Variations-ID prüfen |
| `[DEBUG: FEHLER: Keine Bestelleigenschaften gesendet]` | Konfigurator setzt keine OrderParams | Konfigurator-JS prüfen |
| `[DEBUG: FEHLER: Konfigurationsdaten unvollstaendig]` | Property-IDs stimmen nicht | Reiter 4, IDs prüfen |
| `[DEBUG: FEHLER: HTTP-Fehler 5xx]` | Cloud Function nicht erreichbar oder fehlerhaft | Cloud-Function-URL prüfen, Test im Mock-Modus |
| Nichts passiert, kein Log-Eintrag | Plugin nicht aktiviert oder Plugin-Set nicht deployed | Plugin-Status prüfen, deployen |

## Wichtig zur Sicherheit

Im Produktiv-Modus rechnet der **Plenty-Server** den Preis aus, nicht der Browser. Der Kunde sieht im Frontend zwar einen Preis, aber der ENDGÜLTIGE Preis im Warenkorb wird ausschließlich serverseitig vom Plugin festgelegt. Eine Manipulation aus dem Browser ist nicht möglich.

Wichtig: Im **Mock-Modus** wird dagegen immer der konfigurierte Mock-UVP genommen – das ist nur zum Testen geeignet, nicht für echte Verkäufe.
