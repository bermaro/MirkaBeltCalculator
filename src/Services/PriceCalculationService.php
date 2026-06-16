<?php

namespace MirkaBeltCalculator\Services;

use MirkaBeltCalculator\Configs\PluginConfig;
use Plenty\Plugin\Log\Loggable;

/**
 * PriceCalculationService (v1.0.8)
 *
 * KEIN Constructor mehr. Abhaengigkeiten werden in calculate() per
 * pluginApp() geholt, um die DI-Kette flach zu halten.
 *
 *   EK = UVP * (1 - Rabatt)
 *   VK = EK  * (1 + Margenfaktor)
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

        $apiResult = $apiClient->fetchPrice($productGroupCode, $grit, $jointCode, $width, $length);

        $uvp    = $apiResult['uvp'];
        $source = $apiResult['source'];
        $detail = $apiResult['detail'];

        if ($uvp === null || $uvp <= 0) {
            return [
                'success'       => false,
                'verkaufspreis' => null,
                'uvp'           => null,
                'einkaufspreis' => null,
                'discount'      => $discount,
                'marginFactor'  => $marginFactor,
                'source'        => $source,
                'detail'        => $detail,
            ];
        }

        $einkaufspreis = $uvp * (1.0 - $discount);
        $verkaufspreis = round($einkaufspreis * (1.0 + $marginFactor), 2);

        if ($config->isDebugMode()) {
            $this->getLogger(__METHOD__)->info(
                'MirkaBeltCalculator: Preis berechnet.',
                [
                    'uvp'           => $uvp,
                    'discount'      => $discount,
                    'marginFactor'  => $marginFactor,
                    'einkaufspreis' => round($einkaufspreis, 2),
                    'verkaufspreis' => $verkaufspreis,
                    'source'        => $source,
                ]
            );
        }

        return [
            'success'       => true,
            'verkaufspreis' => $verkaufspreis,
            'uvp'           => $uvp,
            'einkaufspreis' => round($einkaufspreis, 2),
            'discount'      => $discount,
            'marginFactor'  => $marginFactor,
            'source'        => $source,
            'detail'        => $detail,
        ];
    }
}
