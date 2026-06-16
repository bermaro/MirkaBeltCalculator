<?php

namespace MirkaBeltCalculator\Listeners;

use Plenty\Modules\Basket\Events\BasketItem\BeforeBasketItemAdd;
use Plenty\Plugin\Log\Loggable;
use MirkaBeltCalculator\Configs\PluginConfig;
use MirkaBeltCalculator\Services\PriceCalculationService;

/**
 * BasketItemListener (v1.0.8)
 *
 * WICHTIGE AENDERUNG: KEIN Constructor mehr. Die Abhaengigkeiten
 * (PluginConfig, PriceCalculationService) werden erst INNERHALB von handle()
 * per pluginApp() geholt. Das ist der von Plenty empfohlene Weg und vermeidet
 * die verschachtelte Constructor-DI-Kette, die das Booten verhindert hat.
 */
class BasketItemListener
{
    use Loggable;

    /**
     * Wird vom Event-Dispatcher aufgerufen, BEVOR ein Artikel in den
     * Warenkorb gelegt wird.
     */
    public function handle(BeforeBasketItemAdd $event)
    {
        try {
            /** @var PluginConfig $config */
            $config = pluginApp(PluginConfig::class);
            /** @var PriceCalculationService $priceService */
            $priceService = pluginApp(PriceCalculationService::class);

            $basketItem = $event->getBasketItem();
            if ($basketItem === null) {
                return;
            }

            $variationId = (int) $basketItem->variationId;

            if (!$config->isHandledVariation($variationId)) {
                return;
            }

            if ($config->isDebugMode()) {
                $this->getLogger(__METHOD__)->info(
                    'MirkaBeltCalculator: Event ausgeloest fuer behandelte Variation.',
                    [
                        'variationId'         => $variationId,
                        'samplingVariationId' => $config->getSamplingVariationId(),
                        'testVariationId'     => $config->getTestVariationId(),
                        'isMockMode'          => $config->isMockMode(),
                    ]
                );
            }

            $orderParams = $basketItem->basketItemOrderParams;
            if (!is_array($orderParams) || empty($orderParams)) {
                $this->getLogger(__METHOD__)->warning(
                    'MirkaBeltCalculator: Sammelartikel ohne Bestelleigenschaften.',
                    ['variationId' => $variationId]
                );
                $this->setDebugInfo($config, $basketItem, 'FEHLER: Keine Bestelleigenschaften vom Konfigurator gesendet.');
                return;
            }

            $configData = $this->extractConfiguration($config, $orderParams);
            if ($configData === null) {
                $this->getLogger(__METHOD__)->warning(
                    'MirkaBeltCalculator: Konfiguration unvollstaendig.',
                    ['orderParams' => $this->dumpOrderParams($orderParams)]
                );
                $this->setDebugInfo($config, $basketItem, 'FEHLER: Konfigurationsdaten unvollstaendig - siehe Plugin-Log.');
                return;
            }

            $result = $priceService->calculate(
                $configData['productGroupCode'],
                $configData['grit'],
                $configData['jointCode'],
                $configData['width'],
                $configData['length']
            );

            if (!$result['success']) {
                $this->getLogger(__METHOD__)->error(
                    'MirkaBeltCalculator: Preisberechnung fehlgeschlagen.',
                    $result
                );
                $this->setDebugInfo($config, $basketItem, 'FEHLER: Preisberechnung fehlgeschlagen - ' . $result['detail']);
                return;
            }

            $basketItem->useGivenPrice = true;
            $basketItem->givenPrice    = $result['verkaufspreis'];
            $basketItem->inputWidth    = (int) $configData['width'];
            $basketItem->inputLength   = (int) $configData['length'];

            if ($config->isDebugMode()) {
                $debugText = sprintf(
                    'OK | Quelle: %s | UVP: %.2f EUR | Rabatt: %.0f%% | EK: %.2f EUR | Marge: x%.2f | VK: %.2f EUR',
                    $result['source'],
                    $result['uvp'],
                    $result['discount'] * 100,
                    $result['einkaufspreis'],
                    1 + $result['marginFactor'],
                    $result['verkaufspreis']
                );
                $this->setDebugInfo($config, $basketItem, $debugText);

                $this->getLogger(__METHOD__)->info(
                    'MirkaBeltCalculator: Preis erfolgreich gesetzt.',
                    [
                        'variationId'   => $variationId,
                        'verkaufspreis' => $result['verkaufspreis'],
                        'source'        => $result['source'],
                        'uvp'           => $result['uvp'],
                    ]
                );
            }

        } catch (\Throwable $t) {
            $this->getLogger(__METHOD__)->error(
                'MirkaBeltCalculator: Exception im BasketItemListener.',
                [
                    'exception' => get_class($t),
                    'message'   => $t->getMessage(),
                    'file'      => $t->getFile(),
                    'line'      => $t->getLine(),
                ]
            );
        }
    }

    private function extractConfiguration(PluginConfig $config, array $orderParams)
    {
        $idSchleifmittel = $config->getPropertyIdSchleifmittel();
        $idKoernung      = $config->getPropertyIdKoernung();
        $idVerbindung    = $config->getPropertyIdVerbindung();
        $idBreite        = $config->getPropertyIdBreite();
        $idLaenge        = $config->getPropertyIdLaenge();

        $productGroupCode = null;
        $grit             = null;
        $jointCode        = null;
        $width            = null;
        $length           = null;

        foreach ($orderParams as $param) {
            if (is_object($param)) {
                $propertyId = isset($param->propertyId) ? (int) $param->propertyId : 0;
                $value      = isset($param->value)      ? (string) $param->value    : '';
            } elseif (is_array($param)) {
                $propertyId = isset($param['propertyId']) ? (int) $param['propertyId'] : 0;
                $value      = isset($param['value'])      ? (string) $param['value']    : '';
            } else {
                continue;
            }

            if ($propertyId === $idSchleifmittel) {
                $productGroupCode = trim($value);
            } elseif ($propertyId === $idKoernung) {
                $grit = (int) $value;
            } elseif ($propertyId === $idVerbindung) {
                $jointCode = trim($value);
            } elseif ($propertyId === $idBreite) {
                $width = (int) $value;
            } elseif ($propertyId === $idLaenge) {
                $length = (int) $value;
            }
        }

        if (empty($productGroupCode) || $grit <= 0 || empty($jointCode) || $width <= 0 || $length <= 0) {
            return null;
        }

        return [
            'productGroupCode' => $productGroupCode,
            'grit'             => $grit,
            'jointCode'        => $jointCode,
            'width'            => $width,
            'length'           => $length,
        ];
    }

    private function setDebugInfo(PluginConfig $config, $basketItem, $message)
    {
        if (!$config->isDebugMode()) {
            return;
        }

        $idMirkaCode = $config->getPropertyIdMirkaCode();
        $orderParams = $basketItem->basketItemOrderParams;
        if (!is_array($orderParams)) {
            return;
        }

        $found = false;
        foreach ($orderParams as $key => $param) {
            if (is_object($param)) {
                $pid = isset($param->propertyId) ? (int) $param->propertyId : 0;
                if ($pid === $idMirkaCode) {
                    $current = isset($param->value) ? (string) $param->value : '';
                    $param->value = $current . ' [DEBUG: ' . $message . ']';
                    $orderParams[$key] = $param;
                    $found = true;
                    break;
                }
            } elseif (is_array($param)) {
                $pid = isset($param['propertyId']) ? (int) $param['propertyId'] : 0;
                if ($pid === $idMirkaCode) {
                    $current = isset($param['value']) ? (string) $param['value'] : '';
                    $param['value'] = $current . ' [DEBUG: ' . $message . ']';
                    $orderParams[$key] = $param;
                    $found = true;
                    break;
                }
            }
        }

        if ($found) {
            $basketItem->basketItemOrderParams = $orderParams;
        }
    }

    private function dumpOrderParams(array $orderParams)
    {
        $result = [];
        foreach ($orderParams as $param) {
            if (is_object($param)) {
                $result[] = [
                    'propertyId' => isset($param->propertyId) ? $param->propertyId : '?',
                    'value'      => isset($param->value)      ? $param->value      : '?',
                ];
            } elseif (is_array($param)) {
                $result[] = [
                    'propertyId' => isset($param['propertyId']) ? $param['propertyId'] : '?',
                    'value'      => isset($param['value'])      ? $param['value']      : '?',
                ];
            }
        }
        return $result;
    }
}
