<?php

namespace MirkaBeltCalculator\Listeners;

use Plenty\Modules\Webshop\Events\BeforeBasketItemToOrderItem;
use Plenty\Plugin\Log\Loggable;
use MirkaBeltCalculator\Configs\PluginConfig;

/**
 * BasketToOrderListener (NEU v1.5.13)
 *
 * ZWECK:
 *   Der eigentliche Fix fuer den Datenverlust Warenkorb -> Auftrag.
 *   Am offiziellen Plenty-Ereignis BeforeBasketItemToOrderItem werden die
 *   sechs Kundenwerte (Qualitaet, Koernung, Verbindung, Breite, Laenge,
 *   Mirka-Nr.) DIREKT vom Warenkorb-Artikel an die entstehende Auftrags-
 *   position mitgegeben (addAdditionalVariationProperties). Damit entfaellt
 *   die Abhaengigkeit vom Sitzungs-Zettel und vom Preisvergleich.
 *
 *   Hintergrund: Bei externer Bezahlung (Google Pay / PayPal) entsteht der
 *   Auftrag in einer anderen Sitzung -> Sitzungs-Zettel weg -> alle Werte
 *   leer (real belegt: Auftrag 329670). Ausserdem blockierte die
 *   Gleichpreis-Sperre gleich teure Baender. Beides faellt hier weg.
 *
 * WICHTIG (Praezisierung nach externem Review):
 *   - Es werden AUSSCHLIESSLICH die sechs bekannten Mirka-Eigenschafts-IDs
 *     aus der PluginConfig uebertragen (64-69), keine beliebigen IDs.
 *   - Nur wenn ALLE SECHS Werte nicht-leer vorliegen, wird uebergeben und
 *     als "6/6" geloggt. Sonst wird NICHTS uebergeben und ein sichtbares
 *     [MIRKA-PROBLEM] geschrieben (kein halbfertiger Erfolg).
 *
 * SICHERHEIT:
 *   - Alles in try/catch: ein Fehler hier darf den Kauf niemals stoeren.
 *   - Der alte Zettel-/Umbenenn-Weg bleibt als Rueckfall erhalten.
 *   - Der zusaetzliche AfterBasketToOrderDiagnoseListener protokolliert beim
 *     ersten Test, wo genau die Werte am OrderItem ankommen (nur lesen).
 */
class BasketToOrderListener
{
    use Loggable;

    /** Feste Log-Kennung wie in den anderen Listenern. */
    const LOG_KENNUNG = 'MirkaBeltCalculator::MIRKA';

    /**
     * Wird UNMITTELBAR bevor ein Warenkorb-Artikel zur Auftragsposition
     * wird aufgerufen.
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

            // ---- Die sechs erwarteten Eigenschafts-IDs aus der Config ----
            // Reihenfolge: Qualitaet, Koernung, Verbindung, Breite, Laenge, Mirka-Nr.
            $erwarteteIds = [
                'Qualitaet'   => $config->getPropertyIdSchleifmittel(),
                'Koernung'    => $config->getPropertyIdKoernung(),
                'Verbindung'  => $config->getPropertyIdVerbindung(),
                'Breite'      => $config->getPropertyIdBreite(),
                'Laenge'      => $config->getPropertyIdLaenge(),
                'MirkaNr'     => $config->getPropertyIdMirkaCode(),
            ];

            // ---- Alle vorhandenen Eigenschaften des Warenkorb-Artikels lesen ----
            $eigenschaften = $this->leseEigenschaften($basketItem);

            // In eine schnelle Map propertyId => Wert bringen.
            $vorhanden = [];
            if (is_array($eigenschaften)) {
                foreach ($eigenschaften as $prop) {
                    $pid = (int) $this->getPropertyId($prop);
                    $val = trim((string) $this->getValue($prop));
                    if ($pid > 0) {
                        $vorhanden[$pid] = $val;
                    }
                }
            }

            // ---- Nur die sechs bekannten IDs uebernehmen + Vollstaendigkeit pruefen ----
            $zuUebergeben = [];
            $fehlende     = [];
            foreach ($erwarteteIds as $label => $pid) {
                $pid = (int) $pid;
                $wert = isset($vorhanden[$pid]) ? $vorhanden[$pid] : '';
                if ($pid > 0 && $wert !== '') {
                    $zuUebergeben[] = [
                        'propertyId' => $pid,
                        'value'      => $wert,
                    ];
                } else {
                    $fehlende[] = $label;
                }
            }

            // ---- Nur bei 6/6 uebergeben ----
            if (count($fehlende) > 0) {
                $this->getLogger(self::LOG_KENNUNG)->error(
                    '[MIRKA-PROBLEM] BasketToOrder: nur '
                    . (6 - count($fehlende)) . '/6 Mirka-Werte am Warenkorb-Artikel '
                    . '(variationId=' . $variationId . '), fehlt: '
                    . implode(',', $fehlende) . ' | Es wurde NICHTS uebergeben.'
                );
                return;
            }

            // ---- An die entstehende Auftragsposition mitgeben ----
            $event->addAdditionalVariationProperties($zuUebergeben);

            $this->getLogger(self::LOG_KENNUNG)->error(
                '[MIRKA-KURZ] BASKET->ORDER 6/6'
                . ' | variationId=' . $variationId
                . ' | uebergeben=' . count($zuUebergeben)
            );

        } catch (\Throwable $t) {
            $this->getLogger(self::LOG_KENNUNG)->error(
                '[MIRKA-PROBLEM] BasketToOrderListener Exception: ' . $t->getMessage(),
                ['message' => $t->getMessage(), 'file' => $t->getFile(), 'line' => $t->getLine()]
            );
        }
    }

    /**
     * Liest die Eigenschaftsliste aus dem Warenkorb-Artikel.
     * Erst originOrderVariationProperties (wie im BasketItemListener),
     * dann basketItemOrderParams als Ersatz.
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
     * Liest ein benanntes Feld aus einem Objekt ODER Array
     * (Plenty-Sandbox-konform, keine dynamischen Funktionen).
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

    /** Liest die propertyId eines Eintrags (Objekt oder Array). */
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

    /** Liest den Wert eines Eintrags (Objekt oder Array). */
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
