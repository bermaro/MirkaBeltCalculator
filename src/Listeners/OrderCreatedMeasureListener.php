<?php

namespace MirkaBeltCalculator\Listeners;

use Plenty\Modules\Order\Events\OrderCreated;
use Plenty\Modules\Order\Contracts\OrderRepositoryContract;
use Plenty\Plugin\Log\Loggable;
use MirkaBeltCalculator\Configs\PluginConfig;

/**
 * OrderCreatedMeasureListener (NEU v1.6.4 - "STUFE A2: reine Messung")
 *
 * ---------------------------------------------------------------------
 * ZWECK (Stufenplan, Anweisung Bernd/ChatGPT):
 *   Rein lesende Messung am FERTIGEN Auftrag. Aendert NICHTS, schreibt
 *   nichts, ruft kein updateOrder/updateBasketItem auf. Er beantwortet
 *   zwei Fragen, die wir fuer die technische Bruecke brauchen:
 *
 *     1) Welche IDs hat der fertige Auftrag? (orderId, je Position die
 *        orderItemId, itemVariationId, references, alle Feldnamen)
 *        -> zusammen mit basketItem.orderRowId aus dem
 *        BasketToOrderMeasureListener wollen wir beweisen, wie
 *        basketItemId/orderRowId zur endgueltigen orderItemId gelangt.
 *
 *     2) Was steht WIRKLICH in Feld 82 der Eigenschaftszeilen (typeId 15)?
 *        Der echte Kundenwert ("40") oder nur die Beschriftung
 *        ("Schleifband Koernung")? Das entscheidet, ob eine Position
 *        sich ueber ihre eigenen Werte identifizieren KANN oder ob wir
 *        die basketItemId-Bruecke brauchen. KEINE Heuristik, nur Messung.
 *
 * ---------------------------------------------------------------------
 * SICHERHEIT:
 *   - Laeuft nur unter Debug (Tab 6) und nur, wenn der Auftrag unsere
 *     Konfigurator-Variante enthaelt. Sonst sofort zurueck.
 *   - Kein Schreibzugriff, keine Geschaeftslogik. Nur Info-Logzeilen.
 *   - Sandbox-sicher: keine dynamischen Property-Namen; jeder Eventwert
 *     wird ueber json_encode()/json_decode() in ein Array gewandelt.
 * ---------------------------------------------------------------------
 */
class OrderCreatedMeasureListener
{
    use Loggable;

    const LOG_KENNUNG = 'MirkaBeltCalculator::MIRKA';

    /** Positionstyp: Bestelleigenschaft als eigene Position. */
    const TYP_BESTELLEIGENSCHAFT = 15;

    public function handle(OrderCreated $event)
    {
        try {
            /** @var PluginConfig $config */
            $config = pluginApp(PluginConfig::class);

            // Nur unter Debug messen -> echte Kunden voellig unberuehrt.
            if (!$config->isDebugMode()) {
                return;
            }

            $eventOrder = $event->getOrder();
            if ($eventOrder === null) {
                return;
            }
            // NEU v1.6.4 (Hinweis ChatGPT): Dem OrderCreated-Eventobjekt NICHT
            // vertrauen - in diesem System sind die Relations (orderItems,
            // properties, references, orderProperties) dort evtl. NOCH NICHT
            // geladen. Deshalb - wie im bewaehrten OrderRenameListener
            // (ladeAuftragVollstaendig) - den Auftrag ueber das Repository
            // FRISCH mit Relations laden. Weiterhin 100 % rein lesend.
            $eventFelder = $this->alsFelder($eventOrder);
            $orderIdInt  = (int) $this->lese($eventFelder, 'id');
            $geladen     = $this->ladeAuftragVollstaendig($orderIdInt, $eventOrder);
            $ladeQuelle  = $geladen['quelle'];
            $order       = $geladen['order'];
            $oFelder     = $this->alsFelder($order);

            // Positionen einsammeln.
            $items = $this->alsListe($this->lese($oFelder, 'orderItems'));
            if (count($items) === 0) {
                return;
            }

            // Enthaelt der Auftrag ueberhaupt unsere Konfigurator-Variante?
            $hatUnsere = false;
            $itemFelderListe = [];
            foreach ($items as $item) {
                $f = $this->alsFelder($item);
                $itemFelderListe[] = $f;
                $vId = (int) $this->lese($f, 'itemVariationId');
                if ($config->isHandledVariation($vId)) {
                    $hatUnsere = true;
                }
            }
            if (!$hatUnsere) {
                return; // Fremdauftrag -> still.
            }

            $orderId = $this->text($this->lese($oFelder, 'id'));

            $this->info('[MIRKA-STUFE-A2 ORDER] orderId=' . $orderId
                . ' | Ladequelle=' . $ladeQuelle
                . ' | Positionen=' . count($items)
                . ' | Auftrag-Felder: ' . $this->schluessel($oFelder));

            // Je Position: IDs + (bei Typ-15) der ECHTE Inhalt von Feld 81/82/83.
            foreach ($itemFelderListe as $f) {
                $typ = (int) $this->lese($f, 'typeId');

                $zeile = '[MIRKA-STUFE-A2 POS] orderId=' . $orderId
                    . ' | orderItemId=' . $this->text($this->lese($f, 'id'))
                    . ' | typeId=' . $typ
                    . ' | itemVariationId=' . $this->text($this->lese($f, 'itemVariationId'))
                    . ' | quantity=' . $this->text($this->lese($f, 'quantity'))
                    . ' | position=' . $this->text($this->lese($f, 'position'))
                    . ' | basketItemId=' . $this->text($this->lese($f, 'basketItemId'))
                    . ' | orderRowId=' . $this->text($this->lese($f, 'orderRowId'))
                    . ' | references=' . $this->kompakt($this->lese($f, 'references'));

                // Bei Eigenschaftszeilen: die Unterfelder 81/82/83 im Klartext.
                if ($typ === self::TYP_BESTELLEIGENSCHAFT) {
                    $props = $this->alsListe($this->lese($f, 'properties'));
                    $teile = [];
                    foreach ($props as $p) {
                        $pf = $this->alsFelder($p);
                        $teile[] = 'typeId=' . $this->text($this->lese($pf, 'typeId'))
                            . ' value="' . $this->text($this->lese($pf, 'value')) . '"';
                    }
                    $zeile .= ' | Unterfelder(81=ID,82=Wert,83=Gruppe): '
                        . (count($teile) > 0 ? implode(' ; ', $teile) : '(keine)');
                }

                $zeile .= ' | Positions-Felder: ' . $this->schluessel($f);

                $this->info($zeile);
            }

        } catch (\Throwable $t) {
            $this->getLogger(self::LOG_KENNUNG)->error(
                '[MIRKA-PROBLEM] Stufe-A2-OrderCreated-Messlistener Exception: ' . $t->getMessage(),
                ['message' => $t->getMessage(), 'file' => $t->getFile(), 'line' => $t->getLine()]
            );
        }
    }

    /**
     * NEU v1.6.4: Laedt den Auftrag FRISCH aus der Datenbank - genau der
     * bewaehrte Weg aus OrderRenameListener::ladeAuftragVollstaendig().
     * Reihenfolge: mit Relationen -> ohne Relationen -> Event-Objekt.
     * Rein lesend. Liefert Quelle + Auftrag zurueck.
     *
     * @param int    $orderId
     * @param mixed  $eventOrder  Auftrag aus dem Event (letzter Rueckfall)
     * @return array  ['quelle' => string, 'order' => mixed]
     */
    private function ladeAuftragVollstaendig($orderId, $eventOrder)
    {
        if ($orderId > 0) {
            /** @var OrderRepositoryContract $orderRepo */
            $orderRepo = pluginApp(OrderRepositoryContract::class);

            // Versuch 1: mit den benoetigten Relationen.
            try {
                $order = $orderRepo->findOrderById($orderId, [
                    'orderItems',
                    'orderItems.properties',
                    'orderItems.references',
                    'orderItems.orderProperties',
                ]);
                if ($order !== null) {
                    return ['quelle' => 'Repository mit Relations', 'order' => $order];
                }
            } catch (\Throwable $egal) {
                // weiter mit Versuch 2
            }

            // Versuch 2: ohne Relationsliste.
            try {
                $order = $orderRepo->findOrderById($orderId);
                if ($order !== null) {
                    return ['quelle' => 'Repository Standard', 'order' => $order];
                }
            } catch (\Throwable $egal) {
                // weiter mit Rueckfall
            }
        }

        // Rueckfall: das Objekt aus dem Event.
        return ['quelle' => 'Event', 'order' => $eventOrder];
    }

    // ------------------------------------------------------------------
    //  Sandbox-sichere Helfer (kein $obj->$name, kein Schreibzugriff)
    // ------------------------------------------------------------------

    /** Info-Logzeile (nicht rot) mit Uebersetzungs-Schluessel mirka.stufeA. */
    private function info($text)
    {
        $this->getLogger(self::LOG_KENNUNG)->info(
            'MirkaBeltCalculator::mirka.stufeA',
            ['text' => $text]
        );
    }

    /** Wandelt einen Eventwert in ein normales Feld-Array. */
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

    /** Macht aus einer Quelle immer eine einfache Liste. */
    private function alsListe($q)
    {
        if (is_array($q)) {
            return $q;
        }
        if (is_object($q)) {
            $aus = [];
            foreach ($q as $e) {
                $aus[] = $e;
            }
            return $aus;
        }
        return [];
    }

    /** Liest EIN Feld aus einem Array (Variablen-Schluessel ist erlaubt). */
    private function lese($felder, $name)
    {
        if (is_array($felder) && isset($felder[$name])) {
            return $felder[$name];
        }
        return '';
    }

    /** Kurzer, lesbarer Text eines Werts. */
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

    /** Kompakte, gekuerzte Darstellung eines zusammengesetzten Feldes. */
    private function kompakt($w)
    {
        if ($w === null || $w === '') {
            return '(leer)';
        }
        $json = @json_encode($w);
        if (!is_string($json)) {
            return '(nicht darstellbar)';
        }
        if (strlen($json) > 400) {
            $json = substr($json, 0, 400) . '...(gekuerzt)';
        }
        return $json;
    }

    /** Listet die vorhandenen Feldnamen auf. */
    private function schluessel($felder)
    {
        if (!is_array($felder) || count($felder) === 0) {
            return '(keine Felder lesbar)';
        }
        return implode(',', array_keys($felder));
    }
}
