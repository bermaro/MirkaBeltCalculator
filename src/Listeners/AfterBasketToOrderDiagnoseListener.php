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
 *   gemacht hat, protokolliert dieser Listener GEZIELT die Felder, in denen
 *   die sechs Mirka-Werte stecken koennten - getrennt fuer den Quell-
 *   Warenkorbartikel UND die entstandene Auftragsposition.
 *
 *   Zusammen mit der Meldung "[MIRKA-DIAG] VOR BASKET->ORDER" aus dem
 *   BasketToOrderListener laesst sich damit nach EINEM Test eindeutig
 *   unterscheiden:
 *     - Waren die Werte schon VOR dem Uebergang weg?
 *     - Wurde die Payload angenommen, kommt aber woanders an?
 *     - Kam sie korrekt an, liest der Umbenenner nur die falsche Stelle?
 *
 * WICHTIG:
 *   Es wird NICHT ein grosser JSON-Block nach fester Laenge abgeschnitten
 *   (dabei koennten genau die relevanten Felder wegfallen). Stattdessen wird
 *   JEDES relevante Feld EINZELN ausgegeben.
 *
 * SICHERHEIT:
 *   - Kein Schreibzugriff. Alles in try/catch. Kann den Kauf nicht stoeren.
 *   - Reagiert nur auf die konfigurierte Konfigurator-Variante (inkl. Test).
 *   - Nach dem bestaetigenden Test kann dieser Listener wieder entfernt werden.
 */
class AfterBasketToOrderDiagnoseListener
{
    use Loggable;

    const LOG_KENNUNG = 'MirkaBeltCalculator::MIRKA';

    /** Obergrenze je EINZELFELD (nicht fuer den gesamten Dump). */
    const FELD_MAX = 2000;

    public function handle(AfterBasketItemToOrderItem $event)
    {
        try {
            $basketItem = $event->getBasketItem();
            $orderItem  = $event->getOrderItem();

            // Variation bestimmen (kann Objekt oder Array sein).
            $variationId = (int) $this->leseFeld($basketItem, 'variationId');
            if ($variationId === 0) {
                $variationId = (int) $this->leseFeld($orderItem, 'itemVariationId');
            }

            /** @var PluginConfig $config */
            $config = pluginApp(PluginConfig::class);
            if (!$config->isHandledVariation($variationId)) {
                return; // Fremdartikel -> still.
            }

            // ---- Quell-Warenkorbartikel: alle in Frage kommenden Felder ----
            $this->getLogger(self::LOG_KENNUNG)->error(
                '[MIRKA-DIAG] NACH BASKET->ORDER (Quell-Warenkorbartikel)'
                . ' | variationId=' . $variationId,
                [
                    'basketItem.id'
                        => $this->alsText($this->leseFeld($basketItem, 'id')),
                    'basketItem.variationId'
                        => $this->alsText($this->leseFeld($basketItem, 'variationId')),
                    'basketItem.originOrderVariationProperties'
                        => $this->alsText($this->leseFeld($basketItem, 'originOrderVariationProperties')),
                    'basketItem.basketItemOrderParams'
                        => $this->alsText($this->leseFeld($basketItem, 'basketItemOrderParams')),
                    'basketItem.basketItemVariationProperties'
                        => $this->alsText($this->leseFeld($basketItem, 'basketItemVariationProperties')),
                ]
            );

            // ---- Entstandene Auftragsposition: alle moeglichen Zielorte ----
            $this->getLogger(self::LOG_KENNUNG)->error(
                '[MIRKA-DIAG] NACH BASKET->ORDER (entstandene Auftragsposition)'
                . ' | variationId=' . $variationId
                . ' | orderProperties=' . $this->anzahl($this->leseFeld($orderItem, 'orderProperties'))
                . ' | properties=' . $this->anzahl($this->leseFeld($orderItem, 'properties'))
                . ' | orderPropertyItems=' . $this->anzahl($this->leseFeld($orderItem, 'orderPropertyItems'))
                . ' | references=' . $this->anzahl($this->leseFeld($orderItem, 'references')),
                [
                    'orderItem.itemVariationId'
                        => $this->alsText($this->leseFeld($orderItem, 'itemVariationId')),
                    'orderItem.orderProperties'
                        => $this->alsText($this->leseFeld($orderItem, 'orderProperties')),
                    'orderItem.properties'
                        => $this->alsText($this->leseFeld($orderItem, 'properties')),
                    'orderItem.orderPropertyItems'
                        => $this->alsText($this->leseFeld($orderItem, 'orderPropertyItems')),
                    'orderItem.references'
                        => $this->alsText($this->leseFeld($orderItem, 'references')),
                    'orderItem.schluessel'
                        => $this->schluesselListe($orderItem),
                ]
            );

        } catch (\Throwable $t) {
            $this->getLogger(self::LOG_KENNUNG)->error(
                '[MIRKA-PROBLEM] AfterBasketToOrderDiagnoseListener Exception: ' . $t->getMessage(),
                ['message' => $t->getMessage()]
            );
        }
    }

    /**
     * Macht aus einem beliebigen Feldinhalt lesbaren Text fuers Log.
     * Jedes Feld wird EINZELN begrenzt, damit nie die wichtigen Felder
     * durch eine Gesamt-Kuerzung verloren gehen.
     *
     * @param mixed $wert
     * @return string
     */
    private function alsText($wert)
    {
        if ($wert === null) {
            return '(nicht vorhanden)';
        }
        if (is_string($wert) || is_int($wert) || is_float($wert) || is_bool($wert)) {
            return (string) $wert;
        }
        $json = @json_encode($wert);
        if (!is_string($json)) {
            return '(nicht als JSON darstellbar)';
        }
        if (strlen($json) > self::FELD_MAX) {
            $json = substr($json, 0, self::FELD_MAX) . ' …(Feld gekuerzt)';
        }
        return $json;
    }

    /**
     * Zaehlt die Eintraege eines Feldes fuer die SICHTBARE Logzeile
     * (damit man die Zahlen sieht, ohne den Zusatzkontext aufzuklappen).
     *
     * @param mixed $wert
     * @return string
     */
    private function anzahl($wert)
    {
        if (is_array($wert)) {
            return (string) count($wert);
        }
        if ($wert === null) {
            return 'fehlt';
        }
        if (is_object($wert)) {
            $n = 0;
            foreach ($wert as $egal) {
                $n++;
            }
            return (string) $n;
        }
        return 'kein Array';
    }

    /**
     * Listet die vorhandenen Schluessel/Felder der Auftragsposition auf.
     * Damit sehen wir auch dann etwas, wenn die Werte in einem Feld
     * landen, an das wir bisher nicht gedacht haben.
     *
     * @param mixed $quelle
     * @return string
     */
    private function schluesselListe($quelle)
    {
        if (is_array($quelle)) {
            return implode(',', array_keys($quelle));
        }
        if (is_object($quelle)) {
            $namen = [];
            foreach ($quelle as $name => $egal) {
                $namen[] = (string) $name;
            }
            return implode(',', $namen);
        }
        return '(keine Felder lesbar)';
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
