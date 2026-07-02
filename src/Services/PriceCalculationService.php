<?php

namespace MirkaBeltCalculator\Services;

use MirkaBeltCalculator\Configs\PluginConfig;
use Plenty\Plugin\Log\Loggable;

/**
 * PriceCalculationService (v1.0.9)
 *
 * KEIN Constructor mehr. Abhaengigkeiten werden in calculate() per
 * pluginApp() geholt, um die DI-Kette flach zu halten.
 *
 * WICHTIG: Der Mirka-UVP aus der API ist ein NETTO-Preis (bestaetigt durch
 * den offiziellen Mirka-Baenderrechner: "Unverbindliche Preisempfehlung
 * ohne MwSt."). Plenty interpretiert den Warenkorb-Preis (givenPrice)
 * als BRUTTO. Deshalb wird am Ende die MwSt. aufgeschlagen:
 *
 *   EK (netto)        = UVP * (1 - Rabatt)
 *   VK (netto)        = EK  * (1 + Margenfaktor)
 *   VK (brutto)       = VK netto * (1 + MwSt-Satz / 100)
 *
 * 'verkaufspreis' im Rueckgabe-Array ist der BRUTTO-Preis (fuer givenPrice).
 * 'verkaufspreisNetto' und 'einkaufspreis' bleiben NETTO (fuer Marge/EK).
 */
class PriceCalculationService
{
    use Loggable;

    public function calculate($productGroupCode, $grit, $jointCode, $width, $length)
    {
        /** @var PluginConfig $config */
        $config = pluginApp(PluginConfig::class);
        /** @var MirkaApiClient $apiClient */
        $apiClient = pluginApp(MirkaApiClient::class);

        $discount     = $config->getDiscountForProductGroup($productGroupCode);
        $marginFactor = $config->getMarginFactor();
        $vatRate      = $config->getVatRatePercent();

        $apiResult = $apiClient->fetchPrice($productGroupCode, $grit, $jointCode, $width, $length);

        $uvp    = $apiResult['uvp'];
        $source = $apiResult['source'];
        $detail = $apiResult['detail'];

        if ($uvp === null || $uvp <= 0) {
            return [
                'success'            => false,
                'verkaufspreis'      => null,
                'verkaufspreisNetto' => null,
                'uvp'                => null,
                'einkaufspreis'      => null,
                'discount'           => $discount,
                'marginFactor'       => $marginFactor,
                'vatRatePercent'     => $vatRate,
                'source'             => $source,
                'detail'             => $detail,
            ];
        }

        $einkaufspreis      = $uvp * (1.0 - $discount);
        // Netto-Verkaufspreis nach der BERMARO-Formel.
        $verkaufspreisNetto = round($einkaufspreis * (1.0 + $marginFactor), 2);
        // MwSt. aufschlagen -> BRUTTO-Preis fuer den Plenty-Warenkorb.
        $verkaufspreis      = round($verkaufspreisNetto * (1.0 + $vatRate / 100.0), 2);

        if ($config->isDebugMode()) {
            $this->getLogger(__METHOD__)->info(
                'MirkaBeltCalculator: Preis berechnet.',
                [
                    'uvp'                => $uvp,
                    'discount'           => $discount,
                    'marginFactor'       => $marginFactor,
                    'vatRatePercent'     => $vatRate,
                    'einkaufspreis'      => round($einkaufspreis, 2),
                    'verkaufspreisNetto' => $verkaufspreisNetto,
                    'verkaufspreisBrutto'=> $verkaufspreis,
                    'source'             => $source,
                ]
            );
        }

        return [
            'success'            => true,
            'verkaufspreis'      => $verkaufspreis,
            'verkaufspreisNetto' => $verkaufspreisNetto,
            'uvp'                => $uvp,
            'einkaufspreis'      => round($einkaufspreis, 2),
            'discount'           => $discount,
            'marginFactor'       => $marginFactor,
            'vatRatePercent'     => $vatRate,
            'source'             => $source,
            'detail'             => $detail,
        ];
    }
}
