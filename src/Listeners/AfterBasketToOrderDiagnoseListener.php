<?php

namespace MirkaBeltCalculator\Listeners;

use Plenty\Modules\Webshop\Events\AfterBasketItemToOrderItem;
use Plenty\Plugin\Log\Loggable;
use MirkaBeltCalculator\Configs\PluginConfig;

/**
 * AfterBasketToOrderDiagnoseListener (NEU v1.5.13)
 *
 * REIN LESEND / NUR DIAGNOSE - aendert NICHTS.
 *
 * ZWECK:
 *   Direkt NACHDEM Plenty aus einem Warenkorb-Artikel eine Auftragsposition
 *   gemacht hat, protokolliert dieser Listener die ROHSTRUKTUR der
 *   entstandenen Auftragsposition (getOrderItem) und - zur Kontrolle - des
 *   Quell-Warenkorbartikels (getBasketItem). So sehen wir beim ERSTEN Test
 *   eindeutig, WO die vom BasketToOrderListener uebergebenen sechs Werte
 *   tatsaechlich ankommen (orderProperties? Unterfelder? andere Struktur?)
 *   und ob die Datenform von addAdditionalVariationProperties() stimmt.
 *
 *   Erst wenn dieses Log den Zielort bestaetigt, gilt der neue Weg als
 *   bewiesen. Danach kann dieser Diagnose-Listener wieder entfernt werden.
 *
 * SICHERHEIT:
 *   - Kein Schreibzugriff. Alles in try/catch. Kann den Kauf nicht stoeren.
 *   - Reagiert nur auf die konfigurierte Konfigurator-Variante (inkl. Test).
 */
class AfterBasketToOrderDiagnoseListener
{
    use Loggable;

    const LOG_KENNUNG = 'MirkaBeltCalculator::MIRKA';

    public function handle(AfterBasketItemToOrderItem $event)
    {
        try {
            $basketItem = $event->getBasketItem();
            $orderItem  = $event->getOrderItem();

            // Variation bestimmen (BasketItem kann Objekt oder Array sein).
            $variationId = (int) $this->leseFeld($basketItem, 'variationId');
            if ($variationId === 0) {
                $variationId = (int) $this->leseFeld($orderItem, 'itemVariationId');
            }

            /** @var PluginConfig $config */
            $config = pluginApp(PluginConfig::class);
            if (!$config->isHandledVariation($variationId)) {
                return; // Fremdartikel -> still.
            }

            // Die entstandene Auftragsposition als JSON ins Log (gekuerzt),
            // damit die genaue Struktur der Eigenschaften sichtbar wird.
            $orderItemJson = @json_encode($orderItem);
            if (!is_string($orderItemJson)) {
                $orderItemJson = '(nicht als JSON darstellbar)';
            }
            if (strlen($orderItemJson) > 3500) {
                $orderItemJson = substr($orderItemJson, 0, 3500) . ' …(gekuerzt)';
            }

            $this->getLogger(self::LOG_KENNUNG)->error(
                '[MIRKA-DIAG] AfterBasketToOrderItem | variationId=' . $variationId
                . ' | OrderItem-Struktur folgt im Zusatzkontext.',
                ['orderItem' => $orderItemJson]
            );

        } catch (\Throwable $t) {
            $this->getLogger(self::LOG_KENNUNG)->error(
                '[MIRKA-PROBLEM] AfterBasketToOrderDiagnoseListener Exception: ' . $t->getMessage(),
                ['message' => $t->getMessage()]
            );
        }
    }

    /** Liest ein benanntes Feld aus Objekt ODER Array. */
    private function leseFeld($quelle, $name)
    {
        if (is_object($quelle) && isset($quelle->$name)) {
            return $quelle->$name;
        }
        if (is_array($quelle) && isset($quelle[$name])) {
            return $quelle[$name];
        }
        return null;
    }
}
