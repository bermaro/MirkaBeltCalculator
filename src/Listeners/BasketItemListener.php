<?php

namespace MirkaBeltCalculator\Listeners;

use Plenty\Modules\Basket\Events\BasketItem\BeforeBasketItemAdd;
use Plenty\Plugin\Log\Loggable;
use MirkaBeltCalculator\Configs\PluginConfig;
use MirkaBeltCalculator\Services\PriceCalculationService;

/**
 * BasketItemListener
 *
 * Wird ausgeloest, BEVOR ein Artikel in den Warenkorb gelegt wird.
 *
 * Workflow:
 *  1. Pruefen, ob es der Sammelartikel (oder Test-Artikel) ist.
 *  2. Konfigurationsdaten aus den Bestelleigenschaften lesen.
 *  3. Preis berechnen (echt oder Mock).
 *  4. useGivenPrice = true und givenPrice = berechneter VK setzen.
 *  5. Im Debug-Modus: Zusatz-Eintrag in orderParams fuer sichtbare Debug-Info.
 */
class BasketItemListener
{
    use Loggable;

    /** @var PluginConfig */
    private $config;

    /** @var PriceCalculationService */
    private $priceService;

    public function __construct(PluginConfig $config, PriceCalculationService $priceService)
    {
        $this->config       = $config;
        $this->priceService = $priceService;
    }

    /**
     * Wird vom Event-Dispatcher aufgerufen.
     */
    public function handle(BeforeBasketItemAdd $event)
    {
        try {
            $basketItem = $event->getBasketItem();
            if ($basketItem === null) {
                return;
            }

            $variationId = (int) $basketItem->variationId;

            // Pruefen, ob das Plugin fuer diese Variation zustaendig ist
            // (Sammelartikel ODER Test-Variation).
            if (!$this->config->isHandledVariation($variationId)) {
                return;
            }

            if ($this->config->isDebugMode()) {
                $this->getLogger(__METHOD__)->info(
                    'MirkaBeltCalculator: Event ausgeloest fuer behandelte Variation.',
                    [
                        'variationId'          => $variationId,
                        'samplingVariationId'  => $this->config->getSamplingVariationId(),
                        'testVariationId'      => $this->config->getTestVariationId(),
                        'isMockMode'           => $this->config->isMockMode(),
                    ]
                );
            }

            // Bestelleigenschaften (orderParams) auslesen
            $orderParams = $basketItem->basketItemOrderParams;
            if (!is_array($orderParams) || empty($orderParams)) {
                $this->getLogger(__METHOD__)->warning(
                    'MirkaBeltCalculator: Sammelartikel ohne Bestelleigenschaften.',
                    ['variationId' => $variationId]
                );
                $this->setDebugInfo($basketItem, 'FEHLER: Keine Bestelleigenschaften vom Konfigurator gesendet.');
                return;
            }

            $configData = $this->extractConfiguration($orderParams);
            if ($configData === null) {
                $this->getLogger(__METHOD__)->warning(
                    'MirkaBeltCalculator: Konfiguration unvollstaendig.',
                    [
                        'orderParams' => $this->dumpOrderParams($orderParams),
                        'erwarteteIds' => [
                            'Schleifmittel' => $this->config->getPropertyIdSchleifmittel(),
                            'Koernung'      => $this->config->getPropertyIdKoernung(),
                            'Verbindung'    => $this->config->getPropertyIdVerbindung(),
                            'Breite'        => $this->config->getPropertyIdBreite(),
                            'Laenge'        => $this->config->getPropertyIdLaenge(),
                        ],
                    ]
                );
                $this->setDebugInfo($basketItem, 'FEHLER: Konfigurationsdaten unvollstaendig - siehe Plugin-Log.');
                return;
            }

            // Preis berechnen
            $result = $this->priceService->calculate(
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
                $this->setDebugInfo(
                    $basketItem,
                    'FEHLER: Preisberechnung fehlgeschlagen - ' . $result['detail']
                );
                return;
            }

            // Preis auf dem BasketItem setzen
            $basketItem->useGivenPrice = true;
            $basketItem->givenPrice    = $result['verkaufspreis'];
            $basketItem->inputWidth    = (int) $configData['width'];
            $basketItem->inputLength   = (int) $configData['length'];

            if ($this->config->isDebugMode()) {
                $debugText = sprintf(
                    'OK | Quelle: %s | UVP: %.2f EUR | Rabatt: %.0f%% | EK: %.2f EUR | Marge: x%.2f | VK: %.2f EUR',
                    $result['source'],
                    $result['uvp'],
                    $result['discount'] * 100,
                    $result['einkaufspreis'],
                    1 + $result['marginFactor'],
                    $result['verkaufspreis']
                );
                $this->setDebugInfo($basketItem, $debugText);

                $this->getLogger(__METHOD__)->info(
                    'MirkaBeltCalculator: Preis erfolgreich gesetzt.',
                    [
                        'variationId'   => $variationId,
                        'verkaufspreis' => $result['verkaufspreis'],
                        'source'        => $result['source'],
                        'uvp'           => $result['uvp'],
                        'einkaufspreis' => $result['einkaufspreis'],
                        'discount'      => $result['discount'],
                        'marginFactor'  => $result['marginFactor'],
                    ]
                );
            }

        } catch (\Throwable $t) {
            // Niemals die Bestellung blockieren, falls etwas schiefgeht
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

    /**
     * Liest die Konfigurationsdaten aus den orderParams.
     *
     * @param array $orderParams  Bestelleigenschaften vom BasketItem
     * @return array|null         Assoziatives Array oder null bei fehlenden Daten
     */
    private function extractConfiguration(array $orderParams)
    {
        $idSchleifmittel = $this->config->getPropertyIdSchleifmittel();
        $idKoernung      = $this->config->getPropertyIdKoernung();
        $idVerbindung    = $this->config->getPropertyIdVerbindung();
        $idBreite        = $this->config->getPropertyIdBreite();
        $idLaenge        = $this->config->getPropertyIdLaenge();

        $productGroupCode = null;
        $grit             = null;
        $jointCode        = null;
        $width            = null;
        $length           = null;

        foreach ($orderParams as $param) {
            // Plenty kann die Params als Array oder als Objekt liefern.
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

        if (
            empty($productGroupCode) ||
            $grit <= 0 ||
            empty($jointCode) ||
            $width <= 0 ||
            $length <= 0
        ) {
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

    /**
     * Schreibt eine Debug-Info als zusaetzliche Bestelleigenschaft auf das
     * BasketItem - dadurch ist sie im Warenkorb-/Auftrags-Datensatz sichtbar.
     * Verwendet die Mirka-Artikelnummer-Property als Traeger, indem ein
     * Hinweis dahinter angehaengt wird. Alternativ koennte man eine
     * eigene Debug-Property anlegen.
     *
     * Nur aktiv im Debug-Modus.
     */
    private function setDebugInfo($basketItem, $message)
    {
        if (!$this->config->isDebugMode()) {
            return;
        }

        // Wir haengen den Hinweis an die Mirka-Artikelnummer-Property an,
        // damit man ihn im Warenkorb direkt sieht.
        $idMirkaCode = $this->config->getPropertyIdMirkaCode();
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

    /**
     * Wandelt orderParams in eine kompakte Form fuer's Logging um.
     */
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
