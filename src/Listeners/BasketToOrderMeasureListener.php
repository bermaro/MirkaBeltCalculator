<?php

namespace MirkaBeltCalculator\Listeners;

use Plenty\Modules\Webshop\Events\AfterBasketItemToOrderItem;
use Plenty\Plugin\Log\Loggable;
use MirkaBeltCalculator\Configs\PluginConfig;

/**
 * BasketToOrderMeasureListener (v1.6.1 - "STUFE A: reine Messung")
 *
 * ---------------------------------------------------------------------
 * ZWECK (Arbeitsanweisung Stufe A):
 *   Dieser Listener beweist EINE einzige Sache - schwarz auf weiss:
 *
 *       basketItemId  ->  orderItemId
 *
 *   Er aendert NICHTS am Auftrag, am Warenkorb, am Preis oder an einer
 *   Eigenschaft. Er schreibt KEINE Datenbank. Er ruft KEIN updateBasketItem
 *   und KEIN updateOrder auf. Er loggt nur - rein lesend.
 *
 *   Erst wenn dieser Test eindeutig ist, folgt Stufe B (dauerhafter
 *   Datensatz) und Stufe C (Werte an die Position schreiben). Vorher wird
 *   NICHTS gebaut. Es wird auch NICHTS angenommen: ob an dieser Stelle
 *   ueberhaupt schon eine orderItemId existiert und ob eine eindeutige
 *   Bruecke Warenkorbposition -> Auftragsposition da ist, muss die Messung
 *   ZEIGEN. Kein Preis-, kein Reihenfolge-Rueckfall.
 *
 * ---------------------------------------------------------------------
 * REIHENFOLGE IM HANDLER (bewusst so, damit echte Kunden unberuehrt sind
 * und die alte Ausloggen-Schleife nicht wiederkommen kann):
 *
 *   1) VORSCHAU-SCHUTZ, FAIL-SAFE, als allererste Handlung.
 *      Das Event feuert im Checkout auch bei jeder Auftrags-VORSCHAU -
 *      frueher dutzendfach in Sekunden; genau das hat die Schleife
 *      ausgeloest. Deshalb: Nur wenn getIncompleteStatus() ZWEIFELSFREI
 *      "false" (= kein Preview) liefert, geht es weiter. Liefert es "true"
 *      (Preview), etwas Unerwartetes ODER wirft es eine Ausnahme -> SOFORT
 *      zurueck, ohne jeden weiteren Zugriff. Im Zweifel: nichts tun.
 *
 *   2) NUR UNTER DEBUG. Direkt danach - VOR jedem Zugriff auf die
 *      Event-Daten - wird Tab 6 (Debug) geprueft. Ist Debug AUS, kehrt der
 *      Listener sofort zurueck und liest nicht einmal das BasketItem.
 *      Im Normalbetrieb (Debug AUS) ist er damit praktisch inaktiv - echte
 *      Kunden sind unberuehrt. Zum Messen: Debug an, EINE Testbestellung,
 *      Debug wieder aus.
 *
 *   3) ERST JETZT Event-Daten lesen/umwandeln und auf unsere Variante
 *      pruefen. Danach genau EINE Messzeile.
 *
 * ---------------------------------------------------------------------
 * PLENTY-SANDBOX (gilt weiter):
 *   - Dynamische Property-Namen ($obj->$name) sind VERBOTEN. Jeder
 *     Eventwert wird zuerst ueber json_encode()/json_decode() in ein
 *     normales Array umgewandelt (alsFelder). Auf dem Array ist der Zugriff
 *     mit Variablen-Schluessel ($arr[$name]) erlaubt.
 *   - getBasketItem()/getOrderItem() liefern laut Doku ein Array; der
 *     json-Weg funktioniert aber auch, falls es doch ein Modell ist.
 * ---------------------------------------------------------------------
 */
class BasketToOrderMeasureListener
{
    use Loggable;

    /** Feste Log-Kennung wie in allen Mirka-Listenern (ein Filter zeigt alles). */
    const LOG_KENNUNG = 'MirkaBeltCalculator::MIRKA';

    /**
     * Eindeutige Build-Kennung. MUSS im Plenty-Log erscheinen, BEVOR ein
     * Testergebnis ausgewertet wird - sonst laeuft noch alter Code (der
     * Git-/Webhook-404 hat genau das mehrfach verursacht). Die Nummer
     * ZWINGT Plenty NICHT zum Neuladen; sie beweist nur, welcher Code laeuft.
     */
    const BUILD = 'Version 1.6.1 | Stufe A (Messung basketItemId->orderItemId)';

    public function handle(AfterBasketItemToOrderItem $event)
    {
        // -----------------------------------------------------------------
        // 1) VORSCHAU-SCHUTZ - FAIL-SAFE. Allererste Handlung, ohne jeden
        //    weiteren Zugriff. Nur bei zweifelsfrei "false" geht es weiter.
        // -----------------------------------------------------------------
        try {
            if ($event->getIncompleteStatus() !== false) {
                return; // Vorschau ODER unerwarteter Wert -> nichts tun.
            }
        } catch (\Throwable $egal) {
            return; // Status nicht sicher lesbar -> nichts tun (fail-safe).
        }

        try {
            // -------------------------------------------------------------
            // 2) NUR unter Debug (Tab 6). Config VOR jedem Event-Zugriff.
            //    Bei Debug AUS ruehrt der Listener gar nichts an.
            // -------------------------------------------------------------
            /** @var PluginConfig $config */
            $config = pluginApp(PluginConfig::class);
            if (!$config->isDebugMode()) {
                return;
            }

            // -------------------------------------------------------------
            // 3) Erst jetzt die Event-Daten lesen und umwandeln.
            // -------------------------------------------------------------
            $biFelder = $this->alsFelder($event->getBasketItem());
            $oiFelder = $this->alsFelder($event->getOrderItem());

            // Nur unsere Konfigurator-Variante behandeln.
            $variationId = (int) $this->lese($biFelder, 'variationId');
            if ($variationId === 0) {
                $variationId = (int) $this->lese($oiFelder, 'itemVariationId');
            }
            if (!$config->isHandledVariation($variationId)) {
                return; // Fremdartikel -> still.
            }

            // -------------------------------------------------------------
            // 4) BUILD-Kennung - garantiert sichtbar (error-Kanal).
            //    KEINE echte Fehlermeldung, nur der Versions-Beweis.
            // -------------------------------------------------------------
            $this->getLogger(self::LOG_KENNUNG)->error(
                '[MIRKA-BUILD] ' . self::BUILD . ' - dies ist KEINE Fehlermeldung.'
            );

            // -------------------------------------------------------------
            // 5) DIE MESSUNG. Rein lesend, eine Zeile, menschenlesbar.
            //    Ziel: auch wenn orderItemId hier noch leer ist, genug
            //    Felder sehen, um die naechste Bruecke festzulegen.
            // -------------------------------------------------------------
            $this->getLogger(self::LOG_KENNUNG)->error(
                '[MIRKA-STUFE-A] BASKET->ORDER (echter Uebergang)'
                . ' | WARENKORB:'
                    . ' basketId=' . $this->text($this->lese($biFelder, 'basketId'))
                    . ' basketItemId=' . $this->text($this->lese($biFelder, 'id'))
                    . ' variationId=' . $this->text($this->lese($biFelder, 'variationId'))
                    . ' quantity=' . $this->text($this->lese($biFelder, 'quantity'))
                    . ' position=' . $this->text($this->lese($biFelder, 'position'))
                . ' || AUFTRAG:'
                    . ' orderId=' . $this->text($this->lese($oiFelder, 'orderId'))
                    . ' orderItemId=' . $this->text($this->lese($oiFelder, 'id'))
                    . ' itemVariationId=' . $this->text($this->lese($oiFelder, 'itemVariationId'))
                    . ' quantity=' . $this->text($this->lese($oiFelder, 'quantity'))
                    . ' position=' . $this->text($this->lese($oiFelder, 'position'))
                    . ' orderItem.basketItemId=' . $this->text($this->lese($oiFelder, 'basketItemId'))
                    . ' references=' . $this->kompakt($this->lese($oiFelder, 'references'))
                . ' || WARENKORB-Felder: ' . $this->schluessel($biFelder)
                . ' || AUFTRAG-Felder: ' . $this->schluessel($oiFelder)
            );

        } catch (\Throwable $t) {
            $this->getLogger(self::LOG_KENNUNG)->error(
                '[MIRKA-PROBLEM] Stufe-A-Messlistener Exception: ' . $t->getMessage(),
                ['message' => $t->getMessage(), 'file' => $t->getFile(), 'line' => $t->getLine()]
            );
        }
    }

    // ------------------------------------------------------------------
    //  Sandbox-sichere Helfer (kein $obj->$name, kein Schreibzugriff)
    // ------------------------------------------------------------------

    /**
     * Wandelt einen Eventwert IMMER in ein normales Feld-Array um.
     * Array -> direkt. Objekt/Modell -> ueber json_encode() (nur so geben
     * Plenty-Modelle ihre echten Felder heraus).
     *
     * @param mixed $x
     * @return array
     */
    private function alsFelder($x)
    {
        if (is_array($x)) {
            return $x;
        }
        if (!is_object($x)) {
            return [];
        }
        try {
            $json = @json_encode($x);
            if (is_string($json) && $json !== '' && $json !== 'null') {
                $arr = @json_decode($json, true);
                if (is_array($arr)) {
                    return $arr;
                }
            }
        } catch (\Throwable $egal) {
            // nicht umwandelbar -> leer
        }
        return [];
    }

    /**
     * Liest EIN Feld aus einem bereits umgewandelten Array.
     * Array-Zugriff mit Variablen-Schluessel ist erlaubt - nur der
     * dynamische OBJEKT-Zugriff ($obj->$name) ist in der Sandbox verboten.
     *
     * @param array  $felder
     * @param string $name
     * @return mixed  '' wenn nicht vorhanden
     */
    private function lese($felder, $name)
    {
        if (is_array($felder) && isset($felder[$name])) {
            return $felder[$name];
        }
        return '';
    }

    /** Kurzer, lesbarer Text eines Werts fuers Log. */
    private function text($w)
    {
        if ($w === null || $w === '') {
            return '(leer)';
        }
        if (is_string($w) || is_int($w) || is_float($w)) {
            return (string) $w;
        }
        if (is_bool($w)) {
            return $w ? 'true' : 'false';
        }
        return '(komplex)';
    }

    /**
     * Kompakte, gekuerzte Darstellung eines zusammengesetzten Feldes
     * (z. B. references) fuers Log. Rein lesend.
     *
     * @param mixed $w
     * @return string
     */
    private function kompakt($w)
    {
        if ($w === null || $w === '') {
            return '(leer)';
        }
        $json = @json_encode($w);
        if (!is_string($json)) {
            return '(nicht darstellbar)';
        }
        if (strlen($json) > 300) {
            $json = substr($json, 0, 300) . '...(gekuerzt)';
        }
        return $json;
    }

    /** Listet die vorhandenen Feldnamen auf (damit wir sehen, was da ist). */
    private function schluessel($felder)
    {
        if (!is_array($felder) || count($felder) === 0) {
            return '(keine Felder lesbar)';
        }
        return implode(',', array_keys($felder));
    }
}
