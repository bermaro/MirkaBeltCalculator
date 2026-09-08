<?php

namespace MirkaBeltCalculator\Listeners;

use Plenty\Modules\Webshop\Events\BeforeBasketItemToOrderItem;
use Plenty\Plugin\Log\Loggable;
use MirkaBeltCalculator\Configs\PluginConfig;

/**
 * BasketToOrderListener (v1.5.15)
 *
 * ZWECK:
 *   Der eigentliche Fix fuer den Datenverlust Warenkorb -> Auftrag.
 *   Am offiziellen Plenty-Ereignis BeforeBasketItemToOrderItem werden die
 *   sechs Kundenwerte (Qualitaet, Koernung, Verbindung, Breite, Laenge,
 *   Mirka-Nr.) DIREKT vom Warenkorb-Artikel an die entstehende Auftrags-
 *   position mitgegeben (addAdditionalVariationProperties).
 *
 * ---------------------------------------------------------------------
 * FEHLER-KORREKTUR v1.5.15 (Auftrag 329681, 08.09.2026)  -  WICHTIG
 * ---------------------------------------------------------------------
 *   In v1.5.13/v1.5.14 hat dieser Listener IMMER "gefunden=0/6" gemeldet,
 *   obwohl die Werte da waren. Beleg aus demselben Log, dieselbe Sekunde:
 *
 *     [MIRKA-DIAG] VOR  BASKET->ORDER  ... Quellen mit Inhalt=0
 *     [MIRKA-DIAG] NACH BASKET->ORDER  ... basketItemVariationProperties=6
 *
 *   Zwei Meldungen ueber DASSELBE Feld, zwei verschiedene Ergebnisse.
 *   Der einzige Unterschied: Der Diagnose-Listener zaehlt mit foreach und
 *   sieht deshalb auch Objekte/Sammlungen; dieser Listener hatte davor
 *   ein hartes  is_array($q) && count($q) > 0  stehen. Plenty liefert
 *   basketItemVariationProperties NICHT als einfaches PHP-Array, sondern
 *   als Objekt/Sammlung  ->  is_array() war false  ->  die Quelle wurde
 *   verworfen, BEVOR ueberhaupt ein Wert gelesen wurde.
 *
 *   Korrektur: Jede Quelle laeuft jetzt durch alsListe(), das Arrays UND
 *   Objekte/Sammlungen in eine einfache Liste umwandelt. Zusaetzlich wird
 *   die tatsaechliche Struktur der Eintraege in der SICHTBAREN Logzeile
 *   ausgegeben (strukturInfo), damit die Datenform beim naechsten Test
 *   ohne Aufklappen und ohne Raten feststeht.
 * ---------------------------------------------------------------------
 *
 * QUELLENAUSWAHL:
 *   Es wird NICHT die erste nicht-leere Quelle genommen. Stattdessen werden
 *   ALLE bekannten Felder des Warenkorb-Artikels geprueft und die sechs
 *   erwarteten Werte aus nicht-leeren Eintraegen ZUSAMMENGEFUEHRT:
 *       originOrderVariationProperties
 *       basketItemOrderParams          (offiziell dokumentiert)
 *       basketItemVariationProperties  (offiziell dokumentiert)
 *   Liefern zwei Quellen fuer dieselbe Eigenschaft UNTERSCHIEDLICHE
 *   nicht-leere Werte, wird NICHTS uebertragen und ein [MIRKA-PROBLEM]
 *   gemeldet - es wird nicht geraten. Nur die sechs in der PluginConfig
 *   hinterlegten IDs werden akzeptiert, und nur bei 6/6 wird uebergeben.
 *
 * FELDNAMEN DER EINTRAEGE:
 *   Plenty benennt die Felder je nach Struktur unterschiedlich. Deshalb
 *   werden fuer die ID nacheinander  propertyId / id / property.id  und
 *   fuer den Wert  value / propertyValue / propertySelectionValue / name
 *   geprueft - alles FEST AUSGESCHRIEBEN. Das ist kein Raten: Ein Wert
 *   wird nur uebernommen, wenn die ermittelte ID EINE DER SECHS
 *   konfigurierten Eigenschafts-IDs ist. Passt nichts, bleibt es bei 0/6
 *   und die Struktur steht im Log.
 *
 * WICHTIG - PLENTY-SANDBOX (Fehler beim Bereitstellen v1.5.13a):
 *   "dynamic property names are not allowed" - ein Zugriff der Form
 *   $objekt->$name ist VERBOTEN. Deshalb sind hier ALLE Feldzugriffe
 *   FEST AUSGESCHRIEBEN (eigene kleine Methode je Feld), genau wie im
 *   bewaehrten BasketItemListener. Bitte nie wieder auf eine generische
 *   leseFeld($obj, $name)-Hilfsfunktion umstellen.
 *
 * SICHERHEIT:
 *   - Alles in try/catch: ein Fehler hier darf den Kauf niemals stoeren.
 *   - Der Preis wird nicht angefasst.
 *   - Schlaegt dieser direkte Weg fehl, faengt seit v1.5.15 wieder der
 *     Sitzungs-Zettel im OrderRenameListener auf (reiner Rueckfall).
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
            $variationId = (int) $this->feldVariationId($basketItem);
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

            // ---- ALLE Quellen einsammeln ----
            // KORREKTUR v1.5.15: alsListe() statt is_array(). Plenty liefert
            // diese Felder auch als Objekt/Sammlung; die alte Pruefung hat
            // solche Quellen stillschweigend verworfen (Auftrag 329681).
            $quellenListe  = [];
            $strukturTexte = [];

            $q1 = $this->alsListe($this->feldOriginOrderVariationProperties($basketItem));
            $strukturTexte[] = 'originOrderVariationProperties[' . $this->strukturInfo($q1) . ']';
            if (count($q1) > 0) {
                $quellenListe[] = ['name' => 'originOrderVariationProperties', 'liste' => $q1];
            }

            $q2 = $this->alsListe($this->feldBasketItemOrderParams($basketItem));
            $strukturTexte[] = 'basketItemOrderParams[' . $this->strukturInfo($q2) . ']';
            if (count($q2) > 0) {
                $quellenListe[] = ['name' => 'basketItemOrderParams', 'liste' => $q2];
            }

            $q3 = $this->alsListe($this->feldBasketItemVariationProperties($basketItem));
            $strukturTexte[] = 'basketItemVariationProperties[' . $this->strukturInfo($q3) . ']';
            if (count($q3) > 0) {
                $quellenListe[] = ['name' => 'basketItemVariationProperties', 'liste' => $q3];
            }

            // ---- Nicht-leere Werte der sechs IDs zusammenfuehren ----
            $gefunden      = []; // propertyId => Wert
            $herkunft      = []; // propertyId => Quellenname
            $widersprueche = []; // Klartext-Meldungen

            foreach ($quellenListe as $quelle) {
                $quellenName = $quelle['name'];
                foreach ($quelle['liste'] as $prop) {
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

            // ---- Fehlende bestimmen + Payload bauen ----
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
            // Die Struktur der Quellen steht jetzt MIT in der sichtbaren
            // Zeile - kein Aufklappen mehr noetig, um die Datenform zu sehen.
            $this->getLogger(self::LOG_KENNUNG)->error(
                '[MIRKA-DIAG] VOR BASKET->ORDER'
                . ' | variationId=' . $variationId
                . ' | gefunden=' . (6 - count($fehlende)) . '/6'
                . ' | Quellen mit Inhalt=' . count($quellenListe)
                . ' | Herkunft: ' . implode(' ', $herkunftText)
                . ' | erwartete IDs=' . implode(',', $erwartet)
                . ' | STRUKTUR: ' . implode(' ', $strukturTexte)
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
                    . ' | Der Sitzungs-Zettel faengt das im OrderRenameListener auf.'
                    . ' | STRUKTUR: ' . implode(' ', $strukturTexte)
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

    // ------------------------------------------------------------------
    //  Listen- und Struktur-Hilfen
    // ------------------------------------------------------------------

    /**
     * NEU v1.5.15: Macht aus einer Quelle IMMER eine einfache PHP-Liste.
     * Arrays werden direkt zurueckgegeben, Objekte/Sammlungen mit foreach
     * durchlaufen. Genau hier lag der Fehler von v1.5.13/v1.5.14: Dort
     * stand is_array(), und Plenty liefert diese Felder auch als Objekt -
     * die Quelle wurde deshalb komplett verworfen.
     *
     * @param mixed $q
     * @return array
     */
    private function alsListe($q)
    {
        if (is_array($q)) {
            return $q;
        }
        if (is_object($q)) {
            $aus = [];
            foreach ($q as $eintrag) {
                $aus[] = $eintrag;
            }
            return $aus;
        }
        return [];
    }

    /**
     * NEU v1.5.15: Kurze Struktur-Beschreibung einer Quelle fuers Log.
     * Zeigt Anzahl, Typ des ersten Eintrags und dessen Feldnamen. Damit
     * ist beim naechsten Test sofort sichtbar, WIE Plenty die Eintraege
     * benennt - ohne den Zusatzkontext aufklappen zu muessen.
     *
     * @param array $liste
     * @return string
     */
    private function strukturInfo($liste)
    {
        $n = count($liste);
        if ($n === 0) {
            return 'n=0';
        }
        $erster = null;
        foreach ($liste as $eintrag) {
            $erster = $eintrag;
            break;
        }
        if (is_array($erster)) {
            return 'n=' . $n . ';Typ=array;Felder=' . implode(',', array_keys($erster));
        }
        if (is_object($erster)) {
            $namen = [];
            foreach ($erster as $name => $egal) {
                $namen[] = (string) $name;
            }
            return 'n=' . $n . ';Typ=object;Felder=' . implode(',', $namen);
        }
        return 'n=' . $n . ';Typ=skalar;Wert=' . substr((string) $erster, 0, 40);
    }

    // ------------------------------------------------------------------
    //  FESTE Feldzugriffe - je Feld eine eigene Methode.
    //  Dynamische Property-Namen ($obj->$name) sind in der Plenty-Sandbox
    //  VERBOTEN ("dynamic property names are not allowed").
    // ------------------------------------------------------------------

    /** Liest variationId (Objekt oder Array). */
    private function feldVariationId($q)
    {
        if (is_object($q)) {
            return isset($q->variationId) ? $q->variationId : 0;
        }
        if (is_array($q)) {
            return isset($q['variationId']) ? $q['variationId'] : 0;
        }
        return 0;
    }

    /** Liest originOrderVariationProperties (Objekt oder Array). */
    private function feldOriginOrderVariationProperties($q)
    {
        if (is_object($q)) {
            return isset($q->originOrderVariationProperties) ? $q->originOrderVariationProperties : null;
        }
        if (is_array($q)) {
            return isset($q['originOrderVariationProperties']) ? $q['originOrderVariationProperties'] : null;
        }
        return null;
    }

    /** Liest basketItemOrderParams (Objekt oder Array). */
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

    /** Liest basketItemVariationProperties (Objekt oder Array). */
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

    /**
     * Liest die Eigenschafts-ID eines Eintrags.
     * Reihenfolge der fest ausgeschriebenen Versuche:
     *   propertyId  ->  id  ->  property.id
     * Das ist kein Raten: Der gefundene Wert wird oben nur akzeptiert,
     * wenn er EINE DER SECHS konfigurierten IDs ist.
     */
    private function getPropertyId($prop)
    {
        if (is_object($prop)) {
            if (isset($prop->propertyId)) {
                return $prop->propertyId;
            }
            if (isset($prop->id)) {
                return $prop->id;
            }
            if (isset($prop->property)) {
                return $this->getIdAusUnterobjekt($prop->property);
            }
            return '';
        }
        if (is_array($prop)) {
            if (isset($prop['propertyId'])) {
                return $prop['propertyId'];
            }
            if (isset($prop['id'])) {
                return $prop['id'];
            }
            if (isset($prop['property'])) {
                return $this->getIdAusUnterobjekt($prop['property']);
            }
            return '';
        }
        return '';
    }

    /** Liest die id aus einem verschachtelten property-Objekt/-Array. */
    private function getIdAusUnterobjekt($p)
    {
        if (is_object($p)) {
            if (isset($p->id)) {
                return $p->id;
            }
            if (isset($p->propertyId)) {
                return $p->propertyId;
            }
            return '';
        }
        if (is_array($p)) {
            if (isset($p['id'])) {
                return $p['id'];
            }
            if (isset($p['propertyId'])) {
                return $p['propertyId'];
            }
            return '';
        }
        return '';
    }

    /**
     * Liest den Wert eines Eintrags.
     * Reihenfolge der fest ausgeschriebenen Versuche:
     *   value  ->  propertyValue  ->  propertySelectionValue  ->  name
     * Nicht-skalare Inhalte (Arrays/Objekte) werden verworfen, damit nie
     * ein unbrauchbarer Text in eine Bestelleigenschaft geraet.
     */
    private function getValue($prop)
    {
        if (is_object($prop)) {
            if (isset($prop->value)) {
                return $this->nurSkalar($prop->value);
            }
            if (isset($prop->propertyValue)) {
                return $this->nurSkalar($prop->propertyValue);
            }
            if (isset($prop->propertySelectionValue)) {
                return $this->nurSkalar($prop->propertySelectionValue);
            }
            if (isset($prop->name)) {
                return $this->nurSkalar($prop->name);
            }
            return '';
        }
        if (is_array($prop)) {
            if (isset($prop['value'])) {
                return $this->nurSkalar($prop['value']);
            }
            if (isset($prop['propertyValue'])) {
                return $this->nurSkalar($prop['propertyValue']);
            }
            if (isset($prop['propertySelectionValue'])) {
                return $this->nurSkalar($prop['propertySelectionValue']);
            }
            if (isset($prop['name'])) {
                return $this->nurSkalar($prop['name']);
            }
            return '';
        }
        return '';
    }

    /** Laesst nur einfache Werte (Text/Zahl) durch, sonst leer. */
    private function nurSkalar($w)
    {
        if (is_string($w) || is_int($w) || is_float($w)) {
            return (string) $w;
        }
        return '';
    }
}
