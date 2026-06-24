<?php

namespace MirkaBeltCalculator\Listeners;

use Plenty\Modules\Basket\Events\BasketItem\AfterBasketItemAdd;
use Plenty\Plugin\Log\Loggable;
use MirkaBeltCalculator\Configs\PluginConfig;
use MirkaBeltCalculator\Services\PriceCalculationService;

/**
 * BasketItemListener (v1.1.0)
 *
 * AENDERUNGEN ggue. v1.0.8/1.0.9:
 * 1. Haengt jetzt am Event AfterBasketItemAdd statt BeforeBasketItemAdd
 *    (Empfehlung Steve T. nach Debug: orderParams waren bei "Before" leer).
 * 2. Ausfuehrliches Diagnose-Logging: Der Listener schreibt jetzt bei JEDEM
 *    Durchlauf (sobald isDebugMode aktiv) mit, ob orderParams gefuellt sind
 *    und ob der Preis gesetzt wurde. Damit beantwortet EIN Test zwei Fragen:
 *      a) Sind die Bestelleigenschaften bei AfterBasketItemAdd jetzt da?
 *      b) Greift useGivenPrice/givenPrice an dieser Stelle ueberhaupt noch?
 * 3. Die Zeilen $basketItem->inputWidth / inputLength wurden ENTFERNT.
 *    Das sind keine Standard-Felder des BasketItem und koennten bei "After"
 *    Fehler werfen. Breite/Laenge stehen ohnehin in den Bestelleigenschaften.
 *
 * Architektur unveraendert: KEIN Constructor; Abhaengigkeiten via pluginApp()
 * innerhalb von handle().
 */
class BasketItemListener
{
    use Loggable;

    /**
     * Wird vom Event-Dispatcher aufgerufen, NACHDEM ein Artikel in den
     * Warenkorb gelegt wurde.
     */
    public function handle(AfterBasketItemAdd $event)
    {
        try {
            /** @var PluginConfig $config */
            $config = pluginApp(PluginConfig::class);
            /** @var PriceCalculationService $priceService */
            $priceService = pluginApp(PriceCalculationService::class);

            $basketItem = $event->getBasketItem();
            if ($basketItem === null) {
                $this->getLogger(__METHOD__)->warning(
                    'MirkaBeltCalculator: Kein BasketItem im Event erhalten.'
                );
                return;
            }

            $variationId = (int) $basketItem->variationId;

            // Diagnose: Event wurde fuer IRGENDEINEN Artikel ausgeloest.
            // (Hilft zu sehen, ob AfterBasketItemAdd ueberhaupt feuert.)
            $this->getLogger(__METHOD__)->info(
                'MirkaBeltCalculator: AfterBasketItemAdd ausgeloest.',
                [
                    'variationId'      => $variationId,
                    'wirdBehandelt'    => $config->isHandledVariation($variationId),
                ]
            );

            if (!$config->isHandledVariation($variationId)) {
                return;
            }

            // -------------------------------------------------------------
            //  Bestelleigenschaften pruefen (Kernfrage des Tests)
            // -------------------------------------------------------------
            $orderParams = $basketItem->basketItemOrderParams;

            // IMMER loggen, was wir bei "After" tatsaechlich sehen:
            $this->getLogger(__METHOD__)->info(
                'MirkaBeltCalculator: Zustand basketItemOrderParams bei AfterBasketItemAdd.',
                [
                    'istArray'    => is_array($orderParams),
                    'anzahl'      => is_array($orderParams) ? count($orderParams) : 0,
                    'inhalt'      => is_array($orderParams) ? $this->dumpOrderParams($orderParams) : null,
                ]
            );

            if (!is_array($orderParams) || empty($orderParams)) {
                $this->getLogger(__METHOD__)->warning(
                    'MirkaBeltCalculator: Sammelartikel ohne Bestelleigenschaften (auch bei AfterBasketItemAdd leer).',
                    ['variationId' => $variationId]
                );
                $this->setDebugInfo($config, $basketItem, 'FEHLER: Keine Bestelleigenschaften (auch nach Hinzufuegen leer).');
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

            // -------------------------------------------------------------
            //  Preis setzen (zu verifizieren, ob das bei "After" greift)
            // -------------------------------------------------------------
            $basketItem->useGivenPrice = true;
            $basketItem->givenPrice    = $result['verkaufspreis'];

            // Diagnose: protokollieren, dass wir den Preis GESETZT haben.
            // Ob er im Warenkorb ANKOMMT, zeigt der Vergleich Shop <-> Log.
            $this->getLogger(__METHOD__)->info(
                'MirkaBeltCalculator: Preis im Listener gesetzt (useGivenPrice/givenPrice).',
                [
                    'variationId'   => $variationId,
                    'gesetzterPreis'=> $result['verkaufspreis'],
                    'source'        => $result['source'],
                    'uvp'           => $result['uvp'],
                ]
            );

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
