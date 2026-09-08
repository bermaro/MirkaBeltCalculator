<?php

namespace MirkaBeltCalculator\Listeners;

use Plenty\Modules\Webshop\Events\BeforeBasketItemToOrderItem;
use Plenty\Plugin\Log\Loggable;
use MirkaBeltCalculator\Configs\PluginConfig;

/**
 * BasketToOrderListener (v1.5.16)
 *
 * ---------------------------------------------------------------------
 * ACHTUNG: DIESE VERSION MISST NUR - SIE SCHREIBT NICHTS.
 * ---------------------------------------------------------------------
 *   Die Konstante UEBERGABE_AKTIV steht auf false. Solange sie false ist,
 *   wird addAdditionalVariationProperties() NICHT aufgerufen. Der Listener
 *   liest nur, protokolliert die tatsaechliche Datenstruktur und laesst
 *   den Auftrag voellig unveraendert.
 *
 *   WARUM: Es ist NICHT bewiesen, dass in basketItemVariationProperties
 *   die sechs Kundenwerte stehen. Bewiesen ist nur, dass dieses Feld beim
 *   Uebergang SECHS EINTRAEGE hat (Auftrag 329681). Was in diesen
 *   Eintraegen steht, ist unbekannt. Solange das so ist, darf daraus
 *   nichts an einen echten Auftrag geschrieben werden - sonst koennte im
 *   schlimmsten Fall eine Beschriftung ("Schleifband Koernung") als
 *   Kundenwert durchgehen und ein FALSCHES Band als vollstaendig gelten.
 *
 *   Betrieb in dieser Version: Der Sitzungs-Zettel im OrderRenameListener
 *   macht die Arbeit wie in v1.5.12. Der Shop laeuft also wie vor v1.5.13.
 *
 *   NACH dem Test: Wenn das Log zeigt, in welchem Feld die Kundenwerte
 *   wirklich stehen, wird GENAU dieses Feld fest eingebaut und
 *   UEBERGABE_AKTIV auf true gesetzt - nicht vorher.
 * ---------------------------------------------------------------------
 *
 * WAS DIESE VERSION IM LOG LIEFERT:
 *   Fuer jede der drei moeglichen Quellen am Warenkorb-Artikel
 *       originOrderVariationProperties
 *       basketItemOrderParams
 *       basketItemVariationProperties
 *   wird ausgegeben: Anzahl der Eintraege, Typ des Eintrags und - fuer
 *   jeden Eintrag - alle Feldnamen MIT ihren einfachen Werten. Damit
 *   steht nach EINEM Test fest, wie Plenty diese Eintraege aufbaut.
 *
 * NUR DOKUMENTIERTE FELDNAMEN:
 *   Gelesen wird ausschliesslich  propertyId  und  value  - das ist die
 *   von Plenty fuer BasketItemParams dokumentierte Form. Frueher
 *   (v1.5.15) wurden zusaetzlich  id / property.id  bzw.
 *   propertyValue / propertySelectionValue / name  probiert. Das war
 *   Raten und ist ENTFERNT. Besonders  name  war gefaehrlich: In diesem
 *   Projekt hat Plenty an anderer Stelle schon die Beschriftung statt des
 *   Kundenwerts geliefert - daraus haette ein falsches "6/6" entstehen
 *   koennen. Findet der Listener mit den dokumentierten Feldern nichts,
 *   meldet er 0/6 und die Struktur; der Zettel-Rueckfall traegt solange.
 *
 * WICHTIG - PLENTY-SANDBOX (Fehler beim Bereitstellen v1.5.13a):
 *   "dynamic property names are not allowed" - ein Zugriff der Form
 *   $objekt->$name ist VERBOTEN. Deshalb sind hier ALLE Feldzugriffe
 *   FEST AUSGESCHRIEBEN (eigene kleine Methode je Feld). Bitte nie wieder
 *   auf eine generische leseFeld($obj, $name)-Hilfsfunktion umstellen.
 *
 * SICHERHEIT:
 *   - Alles in try/catch: ein Fehler hier darf den Kauf niemals stoeren.
 *   - Der Preis wird nicht angefasst.
 *   - Solange UEBERGABE_AKTIV false ist, wird nichts geschrieben.
 */
class BasketToOrderListener
{
    use Loggable;

    /** Feste Log-Kennung wie in den anderen Listenern. */
    const LOG_KENNUNG = 'MirkaBeltCalculator::MIRKA';

    /**
     * SCHALTER (v1.5.16): Solange false, wird NICHTS an die entstehende
     * Auftragsposition uebergeben - der Listener misst nur.
     *
     * Auf true darf das erst gesetzt werden, wenn im Log belegt ist, in
     * welchem Feld die sechs Kundenwerte tatsaechlich stehen, UND der
     * Lesepfad genau darauf festgelegt wurde. Nicht vorher.
     */
    const UEBERGABE_AKTIV = false;

    /** Obergrenze fuer den Strukturtext je Quelle (Log lesbar halten). */
    const STRUKTUR_MAX = 1200;

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

            // ---- Alle drei Quellen einsammeln ----
            // alsListe() statt is_array(): Plenty liefert diese Felder auch
            // als Objekt/Sammlung. Mit is_array() wurden sie in
            // v1.5.13/v1.5.14 stillschweigend verworfen (Auftrag 329681).
            $q1 = $this->alsListe($this->feldOriginOrderVariationProperties($basketItem));
            $q2 = $this->alsListe($this->feldBasketItemOrderParams($basketItem));
            $q3 = $this->alsListe($this->feldBasketItemVariationProperties($basketItem));

            $quellenListe = [];
            if (count($q1) > 0) {
                $quellenListe[] = ['name' => 'originOrderVariationProperties', 'liste' => $q1];
            }
            if (count($q2) > 0) {
                $quellenListe[] = ['name' => 'basketItemOrderParams', 'liste' => $q2];
            }
            if (count($q3) > 0) {
                $quellenListe[] = ['name' => 'basketItemVariationProperties', 'liste' => $q3];
            }

            // ---- Werte der sechs IDs zusammenfuehren ----
            // Gelesen wird NUR propertyId + value (dokumentierte Form).
            $gefunden      = []; // propertyId => Wert
            $herkunft      = []; // propertyId => Quellenname
            $widersprueche = [];

            foreach ($quellenListe as $quelle) {
                $quellenName = $quelle['name'];
                foreach ($quelle['liste'] as $prop) {
                    $pid = (int) $this->getPropertyId($prop);
                    $val = trim((string) $this->getValue($prop));
                    if ($pid <= 0 || $val === '') {
                        continue;
                    }
                    if (!isset($idZuLabel[$pid])) {
                        continue; // fremde Eigenschaft -> nie uebertragen
                    }
                    if (!isset($gefunden[$pid])) {
                        $gefunden[$pid] = $val;
                        $herkunft[$pid] = $quellenName;
                    } elseif ($gefunden[$pid] !== $val) {
                        $widersprueche[] = $idZuLabel[$pid] . ' (ID ' . $pid . '): "'
                            . $gefunden[$pid] . '" aus ' . $herkunft[$pid]
                            . ' vs. "' . $val . '" aus ' . $quellenName;
                    }
                }
            }

            // ---- Bilanz aufstellen ----
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

            // ---- MESSUNG: die tatsaechliche Struktur ins Log ----
            // Jede Quelle bekommt eine eigene Zeile, damit nichts abgeschnitten
            // wird und alles direkt in der Nachrichtenspalte steht.
            $this->getLogger(self::LOG_KENNUNG)->error(
                '[MIRKA-STRUKTUR] originOrderVariationProperties | variationId='
                . $variationId . ' | ' . $this->strukturDetail($q1)
            );
            $this->getLogger(self::LOG_KENNUNG)->error(
                '[MIRKA-STRUKTUR] basketItemOrderParams | variationId='
                . $variationId . ' | ' . $this->strukturDetail($q2)
            );
            $this->getLogger(self::LOG_KENNUNG)->error(
                '[MIRKA-STRUKTUR] basketItemVariationProperties | variationId='
                . $variationId . ' | ' . $this->strukturDetail($q3)
            );

            // ---- Bilanz-Zeile ----
            $this->getLogger(self::LOG_KENNUNG)->error(
                '[MIRKA-DIAG] VOR BASKET->ORDER'
                . ' | variationId=' . $variationId
                . ' | gefunden=' . (6 - count($fehlende)) . '/6'
                . ' (nur ueber die dokumentierten Felder propertyId+value)'
                . ' | Quellen mit Inhalt=' . count($quellenListe)
                . ' | Herkunft: ' . implode(' ', $herkunftText)
                . ' | erwartete IDs=' . implode(',', $erwartet)
                . ' | Uebergabe=' . (self::UEBERGABE_AKTIV ? 'AKTIV' : 'AUS (nur Messung)')
            );

            // ---- Ab hier wuerde geschrieben - in dieser Version nicht ----
            if (!self::UEBERGABE_AKTIV) {
                $this->getLogger(self::LOG_KENNUNG)->error(
                    '[MIRKA-KURZ] BASKET->ORDER NUR MESSUNG'
                    . ' | variationId=' . $variationId
                    . ' | haette uebergeben=' . count($zuUebergeben) . '/6'
                    . ' | Es wurde NICHTS geschrieben.'
                    . ' | Die Werte kommen in dieser Version wie frueher aus dem'
                    . ' Sitzungs-Zettel (OrderRenameListener).'
                );
                return;
            }

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
     * Macht aus einer Quelle IMMER eine einfache PHP-Liste.
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
     * NEU v1.5.16: Vollstaendige Struktur-Ausgabe einer Quelle.
     *
     * Gibt fuer JEDEN Eintrag alle Feldnamen MIT ihren einfachen Werten
     * aus, z. B.:
     *   n=6;Typ=object;[0]{propertyId=64|value=5C0};[1]{propertyId=65|value=80}
     *
     * Damit ist nach EINEM Test belegt, wie Plenty die Eintraege aufbaut -
     * und ob dort ueberhaupt Kundenwerte oder nur Beschriftungen stehen.
     * Verschachtelte Felder werden als (objekt)/(liste) markiert, nicht
     * ausgeklappt; das haelt die Zeile lesbar.
     *
     * @param array $liste
     * @return string
     */
    private function strukturDetail($liste)
    {
        $n = count($liste);
        if ($n === 0) {
            return 'n=0 (leer oder nicht vorhanden)';
        }

        $typ = 'unbekannt';
        foreach ($liste as $erster) {
            if (is_array($erster)) {
                $typ = 'array';
            } elseif (is_object($erster)) {
                $typ = 'object';
            } else {
                $typ = 'skalar';
            }
            break;
        }

        $text  = 'n=' . $n . ';Typ=' . $typ . ';';
        $index = 0;
        foreach ($liste as $eintrag) {
            $text .= '[' . $index . ']' . $this->eintragDetail($eintrag);
            $index++;
            if (strlen($text) > self::STRUKTUR_MAX) {
                $text .= '...(gekuerzt)';
                break;
            }
        }
        return $text;
    }

    /** Feldnamen + einfache Werte EINES Eintrags als Text. */
    private function eintragDetail($eintrag)
    {
        if (is_array($eintrag)) {
            $teile = [];
            foreach ($eintrag as $name => $wert) {
                $teile[] = (string) $name . '=' . $this->wertKurz($wert);
            }
            return '{' . implode('|', $teile) . '}';
        }
        if (is_object($eintrag)) {
            $teile = [];
            foreach ($eintrag as $name => $wert) {
                $teile[] = (string) $name . '=' . $this->wertKurz($wert);
            }
            return '{' . implode('|', $teile) . '}';
        }
        return '{skalar=' . $this->wertKurz($eintrag) . '}';
    }

    /** Kurzform eines Feldwerts fuers Log. */
    private function wertKurz($w)
    {
        if ($w === null) {
            return '(null)';
        }
        if (is_bool($w)) {
            return $w ? '(true)' : '(false)';
        }
        if (is_string($w) || is_int($w) || is_float($w)) {
            $t = (string) $w;
            if (strlen($t) > 60) {
                $t = substr($t, 0, 60) . '..';
            }
            return $t;
        }
        if (is_array($w)) {
            return '(liste:' . count($w) . ')';
        }
        if (is_object($w)) {
            $namen = [];
            foreach ($w as $name => $egal) {
                $namen[] = (string) $name;
            }
            return '(objekt:' . implode(',', $namen) . ')';
        }
        return '(unbekannt)';
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
     * Liest die Eigenschafts-ID eines Eintrags - AUSSCHLIESSLICH aus dem
     * dokumentierten Feld  propertyId.
     *
     * v1.5.16: Die Versuche ueber  id  und  property.id  sind ENTFERNT.
     * Was  id  in einer unbekannten Struktur bedeutet, ist unbewiesen -
     * eine Zeilen-ID koennte zufaellig 64-69 sein und damit einen falschen
     * Wert an die richtige Eigenschaft haengen.
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
     * Liest den Wert eines Eintrags - AUSSCHLIESSLICH aus dem
     * dokumentierten Feld  value.
     *
     * v1.5.16: Die Versuche ueber  propertyValue / propertySelectionValue
     * und besonders  name  sind ENTFERNT. In diesem Projekt hat Plenty an
     * anderer Stelle bereits die BESCHRIFTUNG statt des Kundenwerts
     * geliefert ("Schleifband Koernung"). Waere  name  hier dasselbe,
     * haette der Listener eine Beschriftung als Kundenwert genommen und
     * ein falsches Band als vollstaendig gemeldet.
     *
     * Nicht-skalare Inhalte werden verworfen.
     */
    private function getValue($prop)
    {
        if (is_object($prop)) {
            return isset($prop->value) ? $this->nurSkalar($prop->value) : '';
        }
        if (is_array($prop)) {
            return isset($prop['value']) ? $this->nurSkalar($prop['value']) : '';
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
