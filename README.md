# MirkaBeltCalculator

Plenty-Plugin für BERMARO GmbH. Berechnet beim Hinzufügen des Sammelartikels "Mirka Schleifband konfiguriert" in den Warenkorb den Verkaufspreis dynamisch auf Basis der Mirka Belt Calculator API.

**Version 1.0.0** mit Debug- und Mock-Modus für sichere Tests.

## Installationsanleitung

Siehe `meta/documents/user_guide/de/installation.md`

## Architektur

```
Konfigurator (JS, Plenty-Frontend)
   │
   │ "In den Warenkorb" klicken
   │ → setzt Bestelleigenschaften:
   │     Schleifmittel, Körnung, Verbindung, Breite, Länge, Mirka-Code
   ▼
Plenty löst Event BeforeBasketItemAdd aus
   │
   ▼
Dieses Plugin (BasketItemListener):
   1. Prüft, ob Variation = Sammelartikel/Test-Artikel
   2. Liest Konfigurationsdaten aus orderParams
   3. Holt UVP (über Cloud Function oder Mock)
   4. Berechnet: VK = UVP × (1 − Rabatt) × (1 + Margenfaktor)
   5. Setzt useGivenPrice = true, givenPrice = berechneter VK
   6. Optional: Schreibt Debug-Info in orderParams
   ▼
Plenty packt Artikel mit korrektem Preis in Warenkorb
   ▼
Normaler Checkout
```

## Wichtigste Dateien

* `plugin.json` – Manifest und Konfigurationsschema
* `src/Providers/MirkaBeltCalculatorServiceProvider.php` – Plugin-Registrierung
* `src/Listeners/BasketItemListener.php` – Logik für das BeforeBasketItemAdd-Event
* `src/Services/MirkaApiClient.php` – HTTP-Aufruf an die Cloud Function (mit Mock-Fallback)
* `src/Services/PriceCalculationService.php` – Rabatt- und Margenberechnung
* `src/Configs/PluginConfig.php` – Zugriff auf die Plugin-Einstellungen

## Preis-Formel

```
Einkaufspreis = Mirka-UVP × (1 − Rabatt)
Verkaufspreis = Einkaufspreis × (1 + Margenfaktor)
```

Beispiel BERMARO-Standard:
* UVP 100 € × (1 − 0.52) = 48 € EK
* 48 € × (1 + 0.5) = **72 € VK**
