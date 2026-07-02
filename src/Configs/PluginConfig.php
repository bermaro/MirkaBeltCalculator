<?php

namespace MirkaBeltCalculator\Configs;

use Plenty\Plugin\ConfigRepository;

/**
 * PluginConfig
 *
 * Zentrale Klasse, die die Plugin-Einstellungen aus dem Plenty-Backend liest.
 * Die Einstellungen werden in plugin.json definiert und im Plenty-Backend
 * unter "Plugins -> Plugin XYZ -> Konfigurationen" gepflegt.
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
        return (float) $this->config->get('MirkaBeltCalculator.marginFactor', 0.5);
    }

    /**
     * MwSt.-Satz in Prozent (z.B. 19). Wird auf den Netto-Verkaufspreis
     * aufgeschlagen, weil der Mirka-UVP ein NETTO-Preis ist (B2B-Preisliste)
     * und Plenty den Warenkorb-Preis als BRUTTO interpretiert.
     * 0 = kein Aufschlag. Unsinnige Werte werden auf 19 zurueckgesetzt.
     */
    public function getVatRatePercent()
    {
        $value = (float) $this->config->get('MirkaBeltCalculator.vatRatePercent', 19.0);
        if ($value < 0 || $value > 30) {
            $value = 19.0;
        }
        return $value;
    }

    public function getDefaultDiscount()
    {
        return (float) $this->config->get('MirkaBeltCalculator.defaultDiscount', 0.52);
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

        return (float) $value;
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
        return $this->config->get('MirkaBeltCalculator.debugMode', 'on') === 'on';
    }

    public function isMockMode()
    {
        return $this->config->get('MirkaBeltCalculator.mockMode', 'on') === 'on';
    }

    public function getMockUvp()
    {
        return (float) $this->config->get('MirkaBeltCalculator.mockUvp', 100.00);
    }
}
