<?php

namespace MirkaBeltCalculator\Listeners;

use Plenty\Modules\Webshop\Events\AfterBasketItemToOrderItem;
use Plenty\Plugin\Log\Loggable;
use MirkaBeltCalculator\Configs\PluginConfig;

/**
 * BasketToOrderMeasureListener (NEU v1.6.0 - "STUFE A: reine Messung")
 *
 * ---------------------------------------------------------------------
 * ZWECK (Arbeitsanweisung Stufe A):
 *   Dieser Listener beweist EINE einzige Sache - schwarz auf weiss:
 *
 *       basketItemId  ->  orderItemId
 *
 *   Er aendert NICHTS am Auftrag, am Warenkorb, am Preis oder an einer
 *   Eigenschaft. Er schreibt KEINE Datenbank. Er ruft KEIN updateBasketItem
 *   und KEIN updateOrder auf. Er loggt nur.
 *
 *   Erst wenn dieser Test eindeutig ist (basketItemId 456 -> orderItemId 789),
 *   folgt Stufe B (dauerhafter Datensatz) und Stufe C (Werte an die Position
 *   schreiben). Vorher wird NICHTS gebaut.
 *
 * ---------------------------------------------------------------------
 * WARUM DAS SICHER IST (die Ausloggen-Schleife von frueher kann NICHT
 * wieder passieren):
 *
 *   1) VORSCHAU-SCHUTZ ZUERST. Das Event AfterBasketItemToOrderItem feuert
 *      im Checkout auch bei jeder Auftrags-VORSCHAU - frueher dutzendfach in
 *      Sekunden. Genau das hat die Schleife ausgeloest, weil die alten
 *      Listener bei JEDEM dieser Fires schwere Arbeit gemacht haben
 *      (findOneById, JSON-Umwandlung, mehrere Logzeilen).
 *      Hier ist die ALLERERSTE Zeile: ist es eine Vorschau
 *      (getIncompleteStatus === true), kehren wir SOFORT zurueck - ohne
 *      jeden weiteren Zugriff.
 *
 *   2) NUR UNTER DEBUG. Selbst beim echten Uebergang laeuft die Messung nur,
 *      wenn Tab 6 (Debug) AN ist. Im Normalbetrieb (Debug AUS) kehrt der
 *      Listener sofort zurueck und ruehrt gar nichts an - echte Kunden sind
 *      damit unberuehrt. Zum Messen: Debug an, EINE Testbestellung, Debug
 *      wieder aus.
 *
 *   3) NUR UNSERE VARIANTE. Fremdartikel werden sofort verlassen.
 *
 *   4) KEIN DATENBANK-/SCHREIBZUGRIFF. Es werden ausschliesslich die Felder
 *      gelesen, die das Event ohnehin schon mitbringt. Danach genau eine
 *      Messzeile.
 *
 * ---------------------------------------------------------------------
 * PLENTY-SANDBOX (hart erarbeitete Regeln, gelten weiter):
 *   - Dynamische Property-Namen ($obj->$name) sind VERBOTEN. Deshalb wird
 *     jeder Eventwert zuerst ueber json_encode()/json_decode() in ein
 *     normales Array umgewandelt (alsFelder). Plenty-Modelle geben ihre
 *     echten Felder nur so heraus, nicht ueber isset($obj->feld)/foreach.
 *   - getBasketItem()/getOrderItem() liefern laut Doku ein Array; der
 *     json-Weg funktioniert aber auch, falls es doch ein Modell ist.
 *
 * ---------------------------------------------------------------------
 * WAS DIE MESSZEILE ZEIGT UND WARUM:
 *   basketItemId : die Warenkorb-Positions-ID (technische Identitaet vorher)
 *   orderItemId  : die Auftrags-Positions-ID (technische Identitaet nachher)
 *   Felder       : ALLE verfuegbaren Feldnamen der Auftragsposition - damit
 *                  sehen wir, OB an dieser Stelle ueberhaupt schon eine
 *                  orderItemId existiert (der Auftrag ist hier evtl. noch
 *                  nicht final gespeichert). Genau das muss die Messung
 *                  klaeren - es wird NICHTS angenommen.
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
     * Git-/Webhook-404 hat genau das mehrfach verursacht).
     */
    const BUILD = 'Version 1.6.0 | Stufe A (Messung basketItemId->orderItemId)';

    public function handle(AfterBasketItemToOrderItem $event)
    {
        try {
            // -------------------------------------------------------------
            // 1) VORSCHAU-SCHUTZ - die allererste Handlung, ohne jeden
            //    weiteren Zugriff. Verhindert die alte Ausloggen-Schleife.
            // -------------------------------------------------------------
            try {
                if ($event->getIncompleteStatus() === true) {
                    return; // reine Vorschau -> nichts tun.
                }
            } catch (\Throwable $egal) {
                // Sollte die Methode wider Erwarten fehlen: NICHT abbrechen,
                // aber es bleibt bei genau einer Logzeile ohne DB-Zugriff.
            }

            $basketItem = $event->getBasketItem();
            $orderItem  = $event->getOrderItem();

            // In normale Arrays umwandeln (Sandbox-sicher, siehe Klassenkopf).
            $biFelder = $this->alsFelder($basketItem);
            $oiFelder = $this->alsFelder($orderItem);

            // -------------------------------------------------------------
            // 2) Nur unsere Konfigurator-Variante behandeln.
            // -------------------------------------------------------------
            $variationId = (int) $this->lese($biFelder, 'variationId');
            if ($variationId === 0) {
                $variationId = (int) $this->lese($oiFelder, 'itemVariationId');
            }

            /** @var PluginConfig $config */
            $config = pluginApp(PluginConfig::class);
            if (!$config->isHandledVariation($variationId)) {
                return; // Fremdartikel -> still.
            }

            // -------------------------------------------------------------
            // 3) NUR unter Debug (Tab 6) messen. Schuetzt echte Kunden.
            // -------------------------------------------------------------
            if (!$config->isDebugMode()) {
                return;
            }

            // -------------------------------------------------------------
            // 4) BUILD-Kennung - garantiert sichtbar (error-Kanal).
            //    KEINE echte Fehlermeldung, nur der Versions-Beweis.
            // -------------------------------------------------------------
            $this->getLogger(self::LOG_KENNUNG)->error(
                '[MIRKA-BUILD] ' . self::BUILD . ' - dies ist KEINE Fehlermeldung.'
            );

            // -------------------------------------------------------------
            // 5) DIE MESSUNG. Rein lesend, eine Zeile.
            // -------------------------------------------------------------
            $basketItemId = $this->lese($biFelder, 'id');
            $orderItemId  = $this->lese($oiFelder, 'id');

            $this->getLogger(self::LOG_KENNUNG)->error(
                '[MIRKA-STUFE-A] BASKET->ORDER (echter Uebergang)'
                . ' | variationId=' . $variationId
                . ' | basketItemId=' . $this->text($basketItemId)
                . ' | orderItemId=' . $this->text($orderItemId)
                . ' | orderItem-Felder: ' . $this->schluessel($oiFelder)
                . ' | basketItem-Felder: ' . $this->schluessel($biFelder)
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
            return '(leer/nicht vorhanden)';
        }
        if (is_string($w) || is_int($w) || is_float($w)) {
            return (string) $w;
        }
        if (is_bool($w)) {
            return $w ? 'true' : 'false';
        }
        return '(komplex)';
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
