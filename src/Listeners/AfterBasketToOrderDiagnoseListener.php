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
 * WICHTIG - PLENTY-SANDBOX (Fehler beim Bereitstellen v1.5.13a):
 *   Plenty verbietet DYNAMISCHE PROPERTY-NAMEN. Ein Zugriff der Form
 *   $objekt->$name  (Feldname steht in einer Variablen) fuehrt beim
 *   Bereitstellen zum Abbruch:
 *     "dynamic property names are not allowed"
 *   Deshalb gibt es hier KEINE allgemeine Hilfsfunktion leseFeld($obj, $name)
 *   mehr, sondern fuer JEDES Feld eine eigene, fest ausgeschriebene
 *   Lesefunktion (feldBasketItemId(), feldOrderItemProperties(), ...).
 *   Bitte nie wieder auf eine generische Hilfsfunktion umstellen.
 *
 * WICHTIG (Log-Form):
 *   Es wird NICHT ein grosser JSON-Block nach fester Laenge abgeschnitten
 *   (dabei koennten genau die relevanten Felder wegfallen). Stattdessen wird
 *   JEDES relevante Feld EINZELN ausgegeben und EINZELN begrenzt.
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
            $variationId = (int) $this->feldBasketItemVariationId($basketItem);
            if ($variationId === 0) {
                $variationId = (int) $this->feldOrderItemItemVariationId($orderItem);
            }

            /** @var PluginConfig $config */
            $config = pluginApp(PluginConfig::class);
            if (!$config->isHandledVariation($variationId)) {
                return; // Fremdartikel -> still.
            }

            // ---- Quell-Warenkorbartikel: alle in Frage kommenden Felder ----
            $this->getLogger(self::LOG_KENNUNG)->error(
                '[MIRKA-DIAG] NACH BASKET->ORDER (Quell-Warenkorbartikel)'
                . ' | variationId=' . $variationId
                . ' | originOrderVariationProperties='
                    . $this->anzahl($this->feldBasketItemOriginOrderVariationProperties($basketItem))
                . ' | basketItemOrderParams='
                    . $this->anzahl($this->feldBasketItemOrderParams($basketItem))
                . ' | basketItemVariationProperties='
                    . $this->anzahl($this->feldBasketItemVariationProperties($basketItem)),
                [
                    'basketItem.id'
                        => $this->alsText($this->feldBasketItemId($basketItem)),
                    'basketItem.variationId'
                        => $this->alsText($this->feldBasketItemVariationId($basketItem)),
                    'basketItem.originOrderVariationProperties'
                        => $this->alsText($this->feldBasketItemOriginOrderVariationProperties($basketItem)),
                    'basketItem.basketItemOrderParams'
                        => $this->alsText($this->feldBasketItemOrderParams($basketItem)),
                    'basketItem.basketItemVariationProperties'
                        => $this->alsText($this->feldBasketItemVariationProperties($basketItem)),
                    'basketItem.schluessel'
                        => $this->schluesselListe($basketItem),
                ]
            );

            // ---- Entstandene Auftragsposition: alle moeglichen Zielorte ----
            $this->getLogger(self::LOG_KENNUNG)->error(
                '[MIRKA-DIAG] NACH BASKET->ORDER (entstandene Auftragsposition)'
                . ' | variationId=' . $variationId
                . ' | orderProperties=' . $this->anzahl($this->feldOrderItemOrderProperties($orderItem))
                . ' | properties=' . $this->anzahl($this->feldOrderItemProperties($orderItem))
                . ' | orderPropertyItems=' . $this->anzahl($this->feldOrderItemOrderPropertyItems($orderItem))
                . ' | references=' . $this->anzahl($this->feldOrderItemReferences($orderItem)),
                [
                    'orderItem.itemVariationId'
                        => $this->alsText($this->feldOrderItemItemVariationId($orderItem)),
                    'orderItem.orderProperties'
                        => $this->alsText($this->feldOrderItemOrderProperties($orderItem)),
                    'orderItem.properties'
                        => $this->alsText($this->feldOrderItemProperties($orderItem)),
                    'orderItem.orderPropertyItems'
                        => $this->alsText($this->feldOrderItemOrderPropertyItems($orderItem)),
                    'orderItem.references'
                        => $this->alsText($this->feldOrderItemReferences($orderItem)),
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

    // ------------------------------------------------------------------
    // FESTE LESEFUNKTIONEN - je Feld eine eigene.
    // KEINE dynamischen Property-Namen (Plenty-Sandbox verbietet das).
    // ------------------------------------------------------------------

    /** basketItem.id */
    private function feldBasketItemId($q)
    {
        if (is_object($q)) { return isset($q->id) ? $q->id : null; }
        if (is_array($q))  { return isset($q['id']) ? $q['id'] : null; }
        return null;
    }

    /** basketItem.variationId */
    private function feldBasketItemVariationId($q)
    {
        if (is_object($q)) { return isset($q->variationId) ? $q->variationId : 0; }
        if (is_array($q))  { return isset($q['variationId']) ? $q['variationId'] : 0; }
        return 0;
    }

    /** basketItem.originOrderVariationProperties */
    private function feldBasketItemOriginOrderVariationProperties($q)
    {
        if (is_object($q)) {
            return isset($q->originOrderVariationProperties) ? $q->originOrderVariationProperties : null;
        }
        if (is_array($q)) {
            return isset($q['originOrderVariationProperties']) ? $q['originOrderVariationProperties'] : null;
        }
        return null;
    }

    /** basketItem.basketItemOrderParams */
    private function feldBasketItemOrderParams($q)
    {
        if (is_object($q)) {
            return isset($q->basketItemOrderParams) ? $q->basketItemOrderParams : null;
        }
        if (is_array($q)) {
            return isset($q['basketItemOrderParams']) ? $q['basketItemOrderParams'] : null;
        }
        return null;
    }

    /** basketItem.basketItemVariationProperties */
    private function feldBasketItemVariationProperties($q)
    {
        if (is_object($q)) {
            return isset($q->basketItemVariationProperties) ? $q->basketItemVariationProperties : null;
        }
        if (is_array($q)) {
            return isset($q['basketItemVariationProperties']) ? $q['basketItemVariationProperties'] : null;
        }
        return null;
    }

    /** orderItem.itemVariationId */
    private function feldOrderItemItemVariationId($q)
    {
        if (is_object($q)) { return isset($q->itemVariationId) ? $q->itemVariationId : 0; }
        if (is_array($q))  { return isset($q['itemVariationId']) ? $q['itemVariationId'] : 0; }
        return 0;
    }

    /** orderItem.orderProperties */
    private function feldOrderItemOrderProperties($q)
    {
        if (is_object($q)) { return isset($q->orderProperties) ? $q->orderProperties : null; }
        if (is_array($q))  { return isset($q['orderProperties']) ? $q['orderProperties'] : null; }
        return null;
    }

    /** orderItem.properties */
    private function feldOrderItemProperties($q)
    {
        if (is_object($q)) { return isset($q->properties) ? $q->properties : null; }
        if (is_array($q))  { return isset($q['properties']) ? $q['properties'] : null; }
        return null;
    }

    /** orderItem.orderPropertyItems */
    private function feldOrderItemOrderPropertyItems($q)
    {
        if (is_object($q)) { return isset($q->orderPropertyItems) ? $q->orderPropertyItems : null; }
        if (is_array($q))  { return isset($q['orderPropertyItems']) ? $q['orderPropertyItems'] : null; }
        return null;
    }

    /** orderItem.references */
    private function feldOrderItemReferences($q)
    {
        if (is_object($q)) { return isset($q->references) ? $q->references : null; }
        if (is_array($q))  { return isset($q['references']) ? $q['references'] : null; }
        return null;
    }

    // ------------------------------------------------------------------
    // Hilfsfunktionen fuer die Ausgabe
    // ------------------------------------------------------------------

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
            $json = substr($json, 0, self::FELD_MAX) . ' ...(Feld gekuerzt)';
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
     * Listet die vorhandenen Schluessel/Felder auf.
     * Damit sehen wir auch dann etwas, wenn die Werte in einem Feld
     * landen, an das wir bisher nicht gedacht haben.
     * (foreach ueber ein Objekt liest nur die sichtbaren Felder - das ist
     * KEIN dynamischer Property-Zugriff und in der Sandbox erlaubt.)
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
}
