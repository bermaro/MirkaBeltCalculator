<?php

namespace MirkaBeltCalculator\Listeners;

use Plenty\Modules\Webshop\Events\BeforeBasketItemToOrderItem;
use Plenty\Plugin\Log\Loggable;
use MirkaBeltCalculator\Configs\PluginConfig;

/**
 * BasketToOrderListener (NEU v1.5.13)
 *
 * ZWECK / WARUM ES DIESEN LISTENER GIBT:
 *   Der bisherige Weg (v1.3.0 - v1.5.12) hat die sechs Kundenwerte
 *   (Qualitaet, Koernung, Verbindung, Breite, Laenge, Mirka-Nr.) beim
 *   In-den-Warenkorb-Legen als "Zettel" in die KUNDEN-SITZUNG geschrieben
 *   und beim Auftrag-Anlegen (OrderCreated) ueber den PREIS wieder
 *   zugeordnet. Das ist aus zwei Gruenden unzuverlaessig:
 *     1. Bei externer Bezahlung (z.B. Google Pay / PayPal) entsteht der
 *        Auftrag in einer ANDEREN Sitzung -> die Zettel sind weg -> alle
 *        Werte kommen leer am Auftrag an (real belegt: Auftrag 329670).
 *     2. Zwei Baender mit demselben Preis lassen sich per Preis nicht
 *        eindeutig zuordnen (Gleichpreis-Sperre v1.5.12 -> beide leer).
 *
 *   Plenty bietet fuer GENAU diesen Uebergang das offizielle Ereignis
 *   BeforeBasketItemToOrderItem an. Es liefert den konkreten Warenkorb-
 *   Artikel UNMITTELBAR bevor daraus eine Auftragsposition wird - und
 *   erlaubt ueber addAdditionalVariationProperties(), Eigenschaften direkt
 *   an die entstehende Auftragsposition mitzugeben. Damit brauchen wir
 *   KEINEN Sitzungs-Zettel und KEINEN Preisvergleich mehr.
 *
 * VERHALTEN:
 *   - Reagiert nur auf die konfigurierte Sammelartikel-Variante (Tab 1),
 *     inkl. der optionalen Test-Variante -> gefahrloses Testen moeglich.
 *   - Liest die sechs Werte direkt aus dem Warenkorb-Artikel
 *     (originOrderVariationProperties, ersatzweise basketItemOrderParams).
 *   - Gibt sie als Auftragspositions-Eigenschaften (propertyId + value)
 *     an die entstehende Position weiter.
 *   - Der OrderRenameListener findet diese Werte anschliessend als
 *     "Quelle C (orderProperties Hauptposition)" und baut daraus den
 *     sprechenden Positionsnamen - der bewaehrte Umbenenner bleibt also
 *     unveraendert, bekommt seine Daten aber jetzt zuverlaessig.
 *
 * SICHERHEIT:
 *   - Alles in try/catch: Ein Fehler hier darf den Kauf NIEMALS stoeren.
 *   - Der alte Zettel-/Preis-Weg (BasketItemListener + OrderRenameListener)
 *     bleibt als Rueckfall erhalten. v1.5.13 ist rein ergaenzend.
 */
class BasketToOrderListener
{
    use Loggable;

    /** Feste Log-Kennung wie in den anderen Listenern. */
    const LOG_KENNUNG = 'MirkaBeltCalculator::MIRKA';

    /**
     * Wird vom Event-Dispatcher aufgerufen, UNMITTELBAR bevor ein
     * Warenkorb-Artikel in eine Auftragsposition umgewandelt wird.
     */
    public function handle(BeforeBasketItemToOrderItem $event)
    {
        try {
            $basketItem = $event->getBasketItem();
            if ($basketItem === null) {
                return;
            }

            // ---- Nur unsere Konfigurator-Variante behandeln ----
            $variationId = (int) $this->leseFeld($basketItem, 'variationId');
            /** @var PluginConfig $config */
            $config = pluginApp(PluginConfig::class);
            if (!$config->isHandledVariation($variationId)) {
                return; // Fremdartikel -> nichts tun, Log bleibt still.
            }

            // ---- Die sechs Werte aus dem Warenkorb-Artikel lesen ----
            // Bevorzugt originOrderVariationProperties (dieselbe Quelle wie
            // im BasketItemListener); ersatzweise basketItemOrderParams.
            $eigenschaften = $this->leseEigenschaften($basketItem);

            if (!is_array($eigenschaften) || count($eigenschaften) === 0) {
                // Sichtbare Meldung: erkannt, aber keine Eigenschaften da.
                $this->getLogger(self::LOG_KENNUNG)->error(
                    '[MIRKA-PROBLEM] BasketToOrder: keine Bestelleigenschaften '
                    . 'am Warenkorb-Artikel gefunden | variationId=' . $variationId
                );
                return;
            }

            // ---- In die Ziel-Struktur bringen: [{propertyId, value}, ...] ----
            // Diese Struktur entspricht genau dem, was der OrderRenameListener
            // als "orderProperties" der Hauptposition liest (propertyId/value).
            $zuUebergeben = [];
            foreach ($eigenschaften as $prop) {
                $propertyId = (int) $this->getPropertyId($prop);
                $wert       = (string) $this->getValue($prop);
                if ($propertyId > 0) {
                    $zuUebergeben[] = [
                        'propertyId' => $propertyId,
                        'value'      => $wert,
                    ];
                }
            }

            if (count($zuUebergeben) === 0) {
                $this->getLogger(self::LOG_KENNUNG)->error(
                    '[MIRKA-PROBLEM] BasketToOrder: Eigenschaften vorhanden, '
                    . 'aber keine gueltige propertyId lesbar | variationId=' . $variationId
                );
                return;
            }

            // ---- An die entstehende Auftragsposition mitgeben ----
            $event->addAdditionalVariationProperties($zuUebergeben);

            // Kurze Erfolgsmeldung (im Log unter MIRKA-KURZ auffindbar).
            $this->getLogger(self::LOG_KENNUNG)->error(
                '[MIRKA-KURZ] BASKET->ORDER'
                . ' | variationId=' . $variationId
                . ' | Eigenschaften uebergeben=' . count($zuUebergeben)
            );

        } catch (\Throwable $t) {
            // Darf den Kauf niemals stoeren.
            $this->getLogger(self::LOG_KENNUNG)->error(
                '[MIRKA-PROBLEM] BasketToOrderListener Exception: ' . $t->getMessage(),
                [
                    'message' => $t->getMessage(),
                    'file'    => $t->getFile(),
                    'line'    => $t->getLine(),
                ]
            );
        }
    }

    /**
     * Liest die Eigenschaftsliste aus dem Warenkorb-Artikel.
     * Erst originOrderVariationProperties (wie im BasketItemListener),
     * dann basketItemOrderParams als Ersatz. Gibt [] zurueck, wenn nichts
     * lesbar ist.
     *
     * @param mixed $basketItem
     * @return array
     */
    private function leseEigenschaften($basketItem)
    {
        $quelle = $this->leseFeld($basketItem, 'originOrderVariationProperties');
        if (is_array($quelle) && count($quelle) > 0) {
            return $quelle;
        }
        $ersatz = $this->leseFeld($basketItem, 'basketItemOrderParams');
        if (is_array($ersatz) && count($ersatz) > 0) {
            return $ersatz;
        }
        return [];
    }

    /**
     * Liest ein benanntes Feld aus einem Objekt ODER Array.
     * Fest ausgeschriebene Zugriffe (keine dynamischen Property-Namen und
     * keine verbotenen Funktionen - Plenty-Sandbox-konform).
     *
     * @param mixed  $quelle
     * @param string $name
     * @return mixed|null
     */
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

    /**
     * Liest die propertyId eines Eintrags (Objekt oder Array).
     */
    private function getPropertyId($prop)
    {
        if (is_object($prop)) {
            return isset($prop->propertyId) ? $prop->propertyId : '';
        }
        if (is_array($prop)) {
            return isset($prop['propertyId']) ? $prop['propertyId'] : '';
        }
        return '';
    }

    /**
     * Liest den Wert eines Eintrags (Objekt oder Array).
     */
    private function getValue($prop)
    {
        if (is_object($prop)) {
            return isset($prop->value) ? $prop->value : '';
        }
        if (is_array($prop)) {
            return isset($prop['value']) ? $prop['value'] : '';
        }
        return '';
    }
}
