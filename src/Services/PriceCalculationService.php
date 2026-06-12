<?php

namespace MirkaBeltCalculator\Services;

use MirkaBeltCalculator\Configs\PluginConfig;
use Plenty\Plugin\Log\Loggable;

/**
 * PriceCalculationService
 *
 * Holt den Brutto-UVP von Mirka (oder Mock) und berechnet den
 * BERMARO-Verkaufspreis:
 *
 *   EK = UVP * (1 - Rabatt)
 *   VK = EK  * (1 + Margenfaktor)
 *
 * Beispiel: UVP 100 EUR, Rabatt 52%, Margenfaktor 0.5
 *   100 * (1 - 0.52) = 48 EUR EK
 *   48  * (1 + 0.5)  = 72 EUR VK
 */
class PriceCalculationService
{
    use Loggable;

    /** @var PluginConfig */
    private $config;

    /** @var MirkaApiClient */
    private $apiClient;

    public function __construct(PluginConfig $config, MirkaApiClient $apiClient)
    {
        $this->config    = $config;
        $this->apiClient = $apiClient;
    }

    /**
     * Berechnet den BERMARO-Verkaufspreis fuer eine konkrete Konfiguration.
     *
     * @return array  Detail-Array fuer Debug + Ergebnis:
     *   [
     *     'success'        => bool,
     *     'verkaufspreis'  => float|null,
     *     'uvp'            => float|null,
     *     'einkaufspreis'  => float|null,
     *     'discount'       => float,
     *     'marginFactor'   => float,
     *     'source'         => 'mock'|'api'|'error',
     *     'detail'         => string,
     *   ]
     */
    public function calculate($productGroupCode, $grit, $jointCode, $width, $length)
    {
        $discount     = $this->config->getDiscountForProductGroup($productGroupCode);
        $marginFactor = $this->config->getMarginFactor();

        $apiResult = $this->apiClient->fetchPrice(
            $productGroupCode,
            $grit,
            $jointCode,
            $width,
            $length
        );

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
        $verkaufspreis = $einkaufspreis * (1.0 + $marginFactor);
        $verkaufspreis = round($verkaufspreis, 2);

        if ($this->config->isDebugMode()) {
            $this->getLogger(__METHOD__)->info(
                'MirkaBeltCalculator: Preis berechnet.',
                [
                    'productGroupCode' => $productGroupCode,
                    'grit'             => $grit,
                    'jointCode'        => $jointCode,
                    'width'            => $width,
                    'length'           => $length,
                    'uvp'              => $uvp,
                    'discount'         => $discount,
                    'marginFactor'     => $marginFactor,
                    'einkaufspreis'    => round($einkaufspreis, 2),
                    'verkaufspreis'    => $verkaufspreis,
                    'source'           => $source,
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
