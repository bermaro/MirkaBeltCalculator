<?php

namespace MirkaBeltCalculator\Configs;

use Plenty\Plugin\ConfigRepository;

/**
 * PluginConfig
 *
 * Zentrale Klasse, die die Plugin-Einstellungen aus dem Plenty-Backend liest.
 * Die Einstellungen werden in config.json definiert und im Plenty-Backend
 * unter "Plugins -> Plugin XYZ -> Konfigurationen" gepflegt.
 *
 * NEU v1.3.4: Zentraler Komma-Parser parseKommaZahl(). Deutsche Eingaben
 * wie "0,52" werden jetzt korrekt als 0.52 erkannt. Vorher haette PHP
 * "0,52" per (float) einfach als 0 gelesen -> falsche Preisrechnung.
 * Gilt fuer: Rabatt (Standard + pro Schleifmittel), Marge, MwSt., Mock-UVP.
 */
class PluginConfig
{
    /** @var ConfigRepository */
    private $config;

    public function __construct(ConfigRepository $config)
    {
        $this->config = $config;
    }

    // -----------------------------------------------------------------
    //  Zentraler Zahlen-Parser (NEU v1.3.4)
    // -----------------------------------------------------------------

    /**
     * Liest einen Zahlenwert tolerant ein:
     * - akzeptiert Punkt UND Komma als Dezimaltrenner ("0.52" und "0,52")
     * - entfernt Leerzeichen
     * - faellt bei leerem oder nicht-numerischem Wert auf $standard zurueck
     *
     * @param mixed $roh       Rohwert aus der Plenty-Einstellung
     * @param float $standard  Rueckfallwert
     * @return float
     */
    private function parseKommaZahl($roh, $standard)
    {
        if ($roh === null) {
            return $standard;
        }
        // In Text umwandeln, Leerzeichen weg, Komma -> Punkt.
        $text = str_replace(',', '.', trim((string) $roh));
        if ($text === '' || !is_numeric($text)) {
            return $standard;
        }
        return (float) $text;
    }

    // -----------------------------------------------------------------
    //  Artikel
    // -----------------------------------------------------------------

    /**
     * Variations-ID des Sammelartikels (produktiv).
     */
    public function getSamplingVariationId()
    {
        return (int) $this->config->get('MirkaBeltCalculator.samplingVariationId', 22994);
    }

    /**
     * Optional: separate Test-Variations-ID. 0 wenn nicht gesetzt.
     */
    public function getTestVariationId()
    {
        $raw = trim((string) $this->config->get('MirkaBeltCalculator.testVariationId', ''));
        if ($raw === '') {
            return 0;
        }
        return (int) $raw;
    }

    /**
     * Prueft, ob die uebergebene Variation vom Plugin behandelt werden soll.
     */
    public function isHandledVariation($variationId)
    {
        $variationId = (int) $variationId;
        if ($variationId <= 0) {
            return false;
        }
        if ($variationId === $this->getSamplingVariationId()) {
            return true;
        }
        $testId = $this->getTestVariationId();
        if ($testId > 0 && $variationId === $testId) {
            return true;
        }
        return false;
    }

    // -----------------------------------------------------------------
    //  Mirka-Anbindung
    // -----------------------------------------------------------------

    public function getProxyUrl()
    {
        $url = (string) $this->config->get('MirkaBeltCalculator.proxyUrl', '');
        return rtrim($url, '/');
    }

    public function getApiTimeoutSeconds()
    {
        $value = (int) $this->config->get('MirkaBeltCalculator.apiTimeoutSeconds', 10);
        if ($value < 1 || $value > 60) {
            $value = 10;
        }
        return $value;
    }

    // -----------------------------------------------------------------
    //  Preise
    // -----------------------------------------------------------------

    public function getMarginFactor()
    {
        // SICHERHEITS-FIX v1.3.3: Marge wird validiert. Erlaubt ist nur
        // 0 bis 5 (z.B. 0.5 = Faktor 1,5). Ungueltige Werte fallen auf
        // den Standard 0.5 zurueck, damit kein absurder Preis entsteht.
        // NEU v1.3.4: Komma-Eingabe "0,5" wird korrekt erkannt.
        $value = $this->parseKommaZahl(
            $this->config->get('MirkaBeltCalculator.marginFactor', null),
            0.5
        );
        if ($value < 0 || $value > 5) {
            $value = 0.5;
        }
        return $value;
    }

    /**
     * MwSt.-Satz in Prozent (z.B. 19). Wird auf den Netto-Verkaufspreis
     * aufgeschlagen, weil der Mirka-UVP ein NETTO-Preis ist (B2B-Preisliste)
     * und Plenty den Warenkorb-Preis als BRUTTO interpretiert.
     * 0 = kein Aufschlag. Unsinnige Werte werden auf 19 zurueckgesetzt.
     * NEU v1.3.4: Komma-Eingabe "19,0" wird korrekt erkannt.
     */
    public function getVatRatePercent()
    {
        $value = $this->parseKommaZahl(
            $this->config->get('MirkaBeltCalculator.vatRatePercent', null),
            19.0
        );
        if ($value < 0 || $value > 30) {
            $value = 19.0;
        }
        return $value;
    }

    public function getDefaultDiscount()
    {
        // SICHERHEITS-FIX v1.3.3: Rabatt wird validiert. Erlaubt ist nur
        // 0 bis 0.95 (Dezimalschreibweise). Wird versehentlich z.B. "52"
        // statt "0.52" eingetragen, wuerde 1 - 52 = -51 einen NEGATIVEN
        // Preis erzeugen. Ungueltige Werte fallen auf 0.52 zurueck.
        // NEU v1.3.4: Komma-Eingabe "0,52" wird korrekt erkannt.
        $value = $this->parseKommaZahl(
            $this->config->get('MirkaBeltCalculator.defaultDiscount', null),
            0.52
        );
        if ($value < 0 || $value > 0.95) {
            $value = 0.52;
        }
        return $value;
    }

    /**
     * Rabatt fuer ein bestimmtes Schleifmittel-Produktgruppen-Kuerzel.
     */
    public function getDiscountForProductGroup($productGroupCode)
    {
        $key   = 'discount' . $productGroupCode;
        $value = $this->config->get('MirkaBeltCalculator.' . $key, null);

        if ($value === null || $value === '') {
            return $this->getDefaultDiscount();
        }

        // SICHERHEITS-FIX v1.3.3: gleiche Validierung wie beim Standard-
        // Rabatt (nur 0 bis 0.95 erlaubt, sonst Rueckfall auf Standard).
        // NEU v1.3.4: Komma-Eingabe "0,52" wird korrekt erkannt. Ein nicht
        // lesbarer Wert faellt ueber den Marker -1 auf den Standard zurueck.
        $value = $this->parseKommaZahl($value, -1.0);
        if ($value < 0 || $value > 0.95) {
            return $this->getDefaultDiscount();
        }

        return $value;
    }

    // -----------------------------------------------------------------
    //  Bestelleigenschaften (Property-IDs)
    // -----------------------------------------------------------------

    public function getPropertyIdSchleifmittel() { return (int) $this->config->get('MirkaBeltCalculator.propertyIdSchleifmittel', 64); }
    public function getPropertyIdKoernung()     { return (int) $this->config->get('MirkaBeltCalculator.propertyIdKoernung',     65); }
    public function getPropertyIdVerbindung()   { return (int) $this->config->get('MirkaBeltCalculator.propertyIdVerbindung',   66); }
    public function getPropertyIdBreite()       { return (int) $this->config->get('MirkaBeltCalculator.propertyIdBreite',       67); }
    public function getPropertyIdLaenge()       { return (int) $this->config->get('MirkaBeltCalculator.propertyIdLaenge',       68); }
    public function getPropertyIdMirkaCode()    { return (int) $this->config->get('MirkaBeltCalculator.propertyIdMirkaCode',    69); }

    // -----------------------------------------------------------------
    //  Test & Debug
    // -----------------------------------------------------------------

    public function isDebugMode()
    {
        // HINWEIS: Fallback bewusst 'on' waehrend der TESTPHASE.
        // VOR LIVE: Einstellung in Plenty auf "off" stellen UND diesen
        // Fallback auf 'off' aendern (steht auf der Vor-Live-Checkliste).
        return $this->config->get('MirkaBeltCalculator.debugMode', 'on') === 'on';
    }

    public function isMockMode()
    {
        // SICHERHEITS-FIX v1.3.3: Fallback ist jetzt 'off'. Falls Plenty die
        // Einstellung nicht findet, laeuft das Plugin mit der ECHTEN API
        // statt mit dem Mock-Platzhalterpreis (fail-safe fuer Live).
        return $this->config->get('MirkaBeltCalculator.mockMode', 'off') === 'on';
    }

    public function getMockUvp()
    {
        // NEU v1.3.4: Komma-Eingabe "100,00" wird korrekt erkannt.
        // Negativer Mock-UVP macht keinen Sinn -> Rueckfall auf 100.
        $value = $this->parseKommaZahl(
            $this->config->get('MirkaBeltCalculator.mockUvp', null),
            100.00
        );
        if ($value <= 0) {
            $value = 100.00;
        }
        return $value;
    }
}
