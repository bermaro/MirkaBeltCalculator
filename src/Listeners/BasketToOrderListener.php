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
 *   position mitgegeben (addAdditionalVariationProperties).
 *
 *   BELEGT ist: Bei Auftrag 329670 waren die sechs Werte im Warenkorb
 *   vollstaendig sichtbar und am erzeugten Auftrag leer - der Verlust
 *   passiert also beim Uebergang Warenkorb -> Auftrag. Die genaue
 *   Ursache dieses Verlusts (z. B. Sitzungswechsel bei externer
 *   Bezahlung) ist damit NICHT bewiesen und wird hier bewusst nicht
 *   behauptet.
 *
 * WICHTIG - QUELLENAUSWAHL (v1.5.13, nach externem Review):
 *   Es wird NICHT die erste nicht-leere Quelle genommen. Stattdessen
 *   werden ALLE bekannten Felder des Warenkorb-Artikels geprueft und die
 *   sechs erwarteten Werte aus nicht-leeren Eintraegen ZUSAMMENGEFUEHRT:
 *       originOrderVariationProperties
 *       basketItemOrderParams          (offiziell dokumentiert)
 *       basketItemVariationProperties  (offiziell dokumentiert)
 *   Liefern zwei Quellen fuer dieselbe Eigenschaft UNTERSCHIEDLICHE
 *   nicht-leere Werte, wird NICHTS uebertragen und ein [MIRKA-PROBLEM]
 *   gemeldet - es wird nicht geraten.
 *
 *   Es werden ausschliesslich die sechs in der PluginConfig hinterlegten
 *   Eigenschafts-IDs akzeptiert, und nur bei vollstaendigen 6/6 wird
 *   uebergeben.
 *
 * STATUS DER DATENFORM:
 *   Plenty dokumentiert das Ereignis und die Methode, aber KEIN Schema
 *   fuer $variationProperties. Die hier verwendete Form
 *   [ ['propertyId' => 64, 'value' => '5C0'], ... ] ist deshalb bis zum
 *   ersten echten Test ausdruecklich UNBEWIESEN (Teststatus).
 *
 * SICHERHEIT:
 *   - Alles in try/catch: ein Fehler hier darf den Kauf niemals stoeren.
 *   - Der Preis wird nicht angefasst.
 *   - Der Session-Zettel wird vom OrderRenameListener seit v1.5.13 NICHT
 *     mehr zur Befuellung benutzt (auch nicht als Rueckfall).
 */
class BasketToOrderListener
{
    use Loggable;

    /** Feste Log-Kennung wie in den anderen Listenern. */
    const LOG_KENNUNG = 'MirkaBeltCalculator::MIRKA';

    /** Alle Felder des Warenkorb-Artikels, die Eigenschaften tragen koennen. */
    const QUELLEN = [
        'originOrderVariationProperties',
        'basketItemOrderParams',
        'basketItemVariationProperties',
    ];

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
            $erwartet = [
                'Qualitaet'  => (int) $config->getPropertyIdSchleifmittel(),
                'Koernung'   => (int) $config->getPropertyIdKoernung(),
                'Verbindung' => (int) $config->getPropertyIdVerbindung(),
                'Breite'     => (int) $config->getPropertyIdBreite(),
                'Laenge'     => (int) $config->getPropertyIdLaenge(),
                'MirkaNr'    => (int) $config->getPropertyIdMirkaCode(),
            ];
            // Umkehr-Zuordnung ID -> Klartext (nur diese IDs sind erlaubt).
            $idZuLabel = [];
            foreach ($erwartet as $label => $pid) {
                if ($pid > 0) {
                    $idZuLabel[$pid] = $label;
                }
            }

            // ---- ALLE Quellen pruefen und nicht-leere Werte zusammenfuehren ----
            $gefunden      = []; // propertyId => Wert
            $herkunft      = []; // propertyId => Quellenname
            $widersprueche = []; // Klartext-Meldungen

            foreach (self::QUELLEN as $quellenName) {
                $liste = $this->leseFeld($basketItem, $quellenName);
                if (!is_array($liste)) {
                    continue;
                }
                foreach ($liste as $prop) {
                    $pid = (int) $this->getPropertyId($prop);
                    $val = trim((string) $this->getValue($prop));
                    if ($pid <= 0 || $val === '') {
                        continue; // leere Eintraege ignorieren
                    }
                    if (!isset($idZuLabel[$pid])) {
                        continue; // fremde Eigenschaft -> nie uebertragen
                    }
                    if (!isset($gefunden[$pid])) {
                        $gefunden[$pid] = $val;
                        $herkunft[$pid] = $quellenName;
                    } elseif ($gefunden[$pid] !== $val) {
                        // Zwei Quellen, zwei verschiedene Werte -> nicht raten.
                        $widersprueche[] = $idZuLabel[$pid] . ' (ID ' . $pid . '): "'
                            . $gefunden[$pid] . '" aus ' . $herkunft[$pid]
                            . ' vs. "' . $val . '" aus ' . $quellenName;
                    }
                }
            }

            // ---- Fehlende bestimmen ----
            $fehlende     = [];
            $zuUebergeben = [];
            $herkunftText = [];
            foreach ($erwartet as $label => $pid) {
                if ($pid > 0 && isset($gefunden[$pid])) {
                    $zuUebergeben[] = [
                        'propertyId' => $pid,
                        'value'      => $gefunden[$pid],
                    ];
                    $herkunftText[] = $label . '<-' . $herkunft[$pid];
                } else {
                    $fehlende[]     = $label;
                    $herkunftText[] = $label . '<-FEHLT';
                }
            }

            // ---- DIAGNOSE VOR der Uebergabe (erste Haelfte des Tests) ----
            $this->getLogger(self::LOG_KENNUNG)->error(
                '[MIRKA-DIAG] VOR BASKET->ORDER'
                . ' | variationId=' . $variationId
                . ' | gefunden=' . (6 - count($fehlende)) . '/6'
                . ' | Herkunft: ' . implode(' ', $herkunftText)
                . ' | erwartete IDs=' . implode(',', $erwartet)
            );

            // ---- Widerspruch zwischen Quellen -> NICHTS uebertragen ----
            if (count($widersprueche) > 0) {
                $this->getLogger(self::LOG_KENNUNG)->error(
                    '[MIRKA-PROBLEM] Widerspruechliche Basket-Quellen '
                    . '(variationId=' . $variationId . '): '
                    . implode(' | ', $widersprueche)
                    . ' | Es wurde NICHTS uebergeben (kein Raten).'
                );
                return;
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
                . ' | Payload=' . count($zuUebergeben)
            );

        } catch (\Throwable $t) {
            $this->getLogger(self::LOG_KENNUNG)->error(
                '[MIRKA-PROBLEM] BasketToOrderListener Exception: ' . $t->getMessage(),
                ['message' => $t->getMessage(), 'file' => $t->getFile(), 'line' => $t->getLine()]
            );
        }
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
