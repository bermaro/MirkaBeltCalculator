<?php

namespace MirkaBeltCalculator\Listeners;

use Plenty\Modules\Basket\Events\BasketItem\AfterBasketItemAdd;
use Plenty\Plugin\Log\Loggable;
use MirkaBeltCalculator\Configs\PluginConfig;
use MirkaBeltCalculator\Services\PriceCalculationService;

/**
 * BasketItemListener (v1.1.1)
 *
 * AENDERUNG ggue. v1.1.0:
 * Die drei WICHTIGSTEN Diagnose-Logzeilen laufen jetzt ueber error()
 * statt info(). Grund (Hinweis Steve T.): info()/warning()/debug() werden
 * von Plenty nur geschrieben, wenn ein Uebersetzungs-Schluessel verwendet
 * wird UND das Log aktiviert ist. error() dagegen erscheint IMMER, ganz
 * ohne Uebersetzungsdatei. Damit ist der Test garantiert aussagekraeftig:
 * Wir sehen sicher, (a) ob AfterBasketItemAdd feuert, (b) ob die
 * Bestelleigenschaften gefuellt sind und (c) ob der Preis gesetzt wurde.
 *
 * HINWEIS: error() ist hier NUR fuer die Testphase als Diagnose-Kanal
 * "missbraucht". Es handelt sich NICHT um echte Fehler. Vor dem Go-Live
 * werden diese Zeilen wieder entfernt oder auf info()+Translation-Key
 * zurueckgestellt (siehe Go-Live-Checkliste).
 *
 * Weitere Aenderungen aus v1.1.0 bleiben:
 * - Event AfterBasketItemAdd statt BeforeBasketItemAdd
 * - inputWidth/inputLength entfernt
 * - Architektur: kein Constructor, pluginApp() in handle()
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
                // error() = garantiert sichtbar
                $this->getLogger(__METHOD__)->error(
                    'MirkaBeltCalculator [DIAG]: Kein BasketItem im Event erhalten.'
                );
                return;
            }

            $variationId = (int) $basketItem->variationId;

            // DIAG 1: Feuert AfterBasketItemAdd ueberhaupt? (garantiert sichtbar)
            $this->getLogger(__METHOD__)->error(
                'MirkaBeltCalculator [DIAG]: AfterBasketItemAdd ausgeloest.',
                [
                    'variationId'   => $variationId,
                    'wirdBehandelt' => $config->isHandledVariation($variationId),
                ]
            );

            if (!$config->isHandledVariation($variationId)) {
                return;
            }

            // -------------------------------------------------------------
            //  Bestelleigenschaften pruefen (Kernfrage des Tests)
            // -------------------------------------------------------------
            $orderParams = $basketItem->basketItemOrderParams;

            // DIAG 2: Was steht in den orderParams bei "After"? (garantiert sichtbar)
            $this->getLogger(__METHOD__)->error(
                'MirkaBeltCalculator [DIAG]: Zustand basketItemOrderParams bei AfterBasketItemAdd.',
                [
                    'istArray' => is_array($orderParams),
                    'anzahl'   => is_array($orderParams) ? count($orderParams) : 0,
                    'inhalt'   => is_array($orderParams) ? $this->dumpOrderParams($orderParams) : null,
                ]
            );

            if (!is_array($orderParams) || empty($orderParams)) {
                $this->getLogger(__METHOD__)->error(
                    'MirkaBeltCalculator [DIAG]: Sammelartikel ohne Bestelleigenschaften (auch bei After leer).',
                    ['variationId' => $variationId]
                );
                $this->setDebugInfo($config, $basketItem, 'FEHLER: Keine Bestelleigenschaften (auch nach Hinzufuegen leer).');
                return;
            }

            $configData = $this->extractConfiguration($config, $orderParams);
            if ($configData === null) {
                $this->getLogger(__METHOD__)->error(
                    'MirkaBeltCalculator [DIAG]: Konfiguration unvollstaendig.',
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
                    'MirkaBeltCalculator [DIAG]: Preisberechnung fehlgeschlagen.',
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

            // DIAG 3: Preis wurde im Listener gesetzt (garantiert sichtbar).
            // Ob er im Warenkorb ANKOMMT, zeigt der Vergleich Shop <-> Log.
            $this->getLogger(__METHOD__)->error(
                'MirkaBeltCalculator [DIAG]: Preis im Listener gesetzt (useGivenPrice/givenPrice).',
                [
                    'variationId'    => $variationId,
                    'gesetzterPreis' => $result['verkaufspreis'],
                    'source'         => $result['source'],
                    'uvp'            => $result['uvp'],
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
                'MirkaBeltCalculator [DIAG]: Exception im BasketItemListener.',
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
