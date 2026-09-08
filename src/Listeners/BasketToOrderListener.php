<?php

namespace MirkaBeltCalculator\Listeners;

use Plenty\Modules\Webshop\Events\BeforeBasketItemToOrderItem;
use Plenty\Plugin\Log\Loggable;
use MirkaBeltCalculator\Configs\PluginConfig;

/**
 * BasketToOrderListener (v1.5.18)
 *
 * NEU AB v1.5.18: Der BasketItemListener speichert die sechs Werte jetzt
 * dauerhaft in den basketItemOrderParams des Warenkorbartikels. Genau
 * dieses Feld war beim Uebergang bisher leer (Auftrag 329694,
 * basketItemOrderParams=0). Wenn die Persistenz traegt, findet dieser
 * Listener die Werte dort - im dokumentierten Format propertyId/value -
 * und gibt sie an die Auftragsposition weiter.
 *
 * ---------------------------------------------------------------------
 * BEFUND AUS AUFTRAG 329694 (08.09.2026) - WARUM v1.5.16 NICHTS FAND
 * ---------------------------------------------------------------------
 *   Die Messung aus v1.5.16 hat es gezeigt:
 *
 *     [MIRKA-STRUKTUR] basketItemVariationProperties | n=6;Typ=object;
 *       [0]{incrementing=(true)|preventsLazyLoading=(false)|exists=(true)
 *           |wasRecentlyCreated=(false)|timestamps=(true)}
 *
 *   Das sind KEINE Nutzdaten, sondern die internen Schalter der
 *   Plenty-Modellklasse. Der Grund: Plenty-Modelle halten ihre echten
 *   Felder NICHT als normale Objekt-Eigenschaften, sondern intern in
 *   einer Liste, die ueber magische Getter herausgegeben wird.
 *
 *   Folgen daraus:
 *     - foreach ($objekt as $name => $wert) zeigt nur die Basis-Schalter.
 *     - isset($objekt->propertyId) kann FALSE liefern, obwohl
 *       $objekt->propertyId einen Wert haette. Genau diese isset-Pruefung
 *       steckte in allen bisherigen Zugriffen - deshalb 0/6.
 *
 *   LOESUNG (v1.5.17): Jeder Eintrag wird ueber json_encode() in ein
 *   normales Array umgewandelt. json_encode() geht bei Plenty-Modellen
 *   ueber deren eigene Ausgabe-Funktion und liefert damit die ECHTEN
 *   Felder. Erst auf diesem Array wird gelesen.
 *   (Derselbe Weg wird im AfterBasketToOrderDiagnoseListener seit jeher
 *   benutzt - dort waren die Inhalte im Zusatzkontext auch sichtbar.)
 * ---------------------------------------------------------------------
 *
 * WAS GELESEN WIRD - UND WAS NICHT:
 *   Ausschliesslich die dokumentierten Felder  propertyId  und  value
 *   auf der obersten Ebene des umgewandelten Eintrags. Kein  id ,
 *   kein  name , kein  propertyValue  - diese Ausweichversuche aus
 *   v1.5.15 waren Raten und sind entfernt geblieben.
 *
 * DREI SICHERUNGEN GEGEN FALSCHE DATEN:
 *   1. Die gelesene ID muss EINE DER SECHS konfigurierten IDs sein
 *      (64-69 bzw. was in der Plugin-Konfiguration steht).
 *   2. Der Wert darf nicht wie eine BESCHRIFTUNG aussehen. In diesem
 *      Projekt hat Plenty an anderer Stelle schon "Schleifband Koernung"
 *      statt "80" geliefert. Solche Werte werden verworfen und gemeldet.
 *   3. Uebergeben wird NUR bei 6 von 6. Fehlt eines, passiert nichts.
 *   Zusaetzlich wird bei jedem Fehlschlag der komplette Inhalt des ersten
 *   Eintrags als JSON protokolliert - damit steht die echte Feldform im
 *   Log, falls auch dieser Weg nicht traegt.
 *
 * WARUM DIE UEBERGABE JETZT AKTIV IST:
 *   In v1.5.16 stand der Schalter bewusst auf AUS, weil der Sitzungs-
 *   Zettel den Betrieb tragen sollte. Auftrag 329694 hat gezeigt, dass er
 *   das NICHT tut: Der Zettel wurde um 13:42:16 geschrieben und war um
 *   13:42:52 beim Anlegen des Auftrags nicht mehr lesbar - ohne dass sich
 *   der Kunde ab- oder angemeldet hatte. Es gibt also derzeit KEINEN
 *   funktionierenden zweiten Weg, den man schuetzen muesste. Ein
 *   ausgeschalteter direkter Weg garantiert 0/6; ein eingeschalteter kann
 *   im schlechtesten Fall ebenfalls nichts liefern (dann greifen die drei
 *   Sicherungen oben und es passiert nichts), im besten Fall loest er das
 *   Problem. Deshalb: UEBERGABE_AKTIV = true.
 *
 * WICHTIG - PLENTY-SANDBOX (Fehler beim Bereitstellen v1.5.13a):
 *   "dynamic property names are not allowed" - ein Zugriff der Form
 *   $objekt->$name ist VERBOTEN. Alle Feldzugriffe hier sind deshalb FEST
 *   AUSGESCHRIEBEN. Bitte nie wieder auf eine generische
 *   leseFeld($obj, $name)-Hilfsfunktion umstellen.
 *
 * SICHERHEIT:
 *   - Alles in try/catch: ein Fehler hier darf den Kauf niemals stoeren.
 *   - Der Preis wird nicht angefasst.
 */
class BasketToOrderListener
{
    use Loggable;

    /** Feste Log-Kennung wie in den anderen Listenern. */
    const LOG_KENNUNG = 'MirkaBeltCalculator::MIRKA';

    /**
     * Schalter: true = die sechs Werte werden an die entstehende
     * Auftragsposition uebergeben. Siehe Begruendung im Klassenkopf.
     */
    const UEBERGABE_AKTIV = true;

    /** Obergrenze fuer den Strukturtext je Quelle (Log lesbar halten). */
    const STRUKTUR_MAX = 1500;

    /** Ab dieser Laenge gilt ein Wert als verdaechtig (Beschriftung). */
    const WERT_MAX_LAENGE = 40;

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
            $idZuLabel = [];
            foreach ($erwartet as $label => $pid) {
                if ($pid > 0) {
                    $idZuLabel[$pid] = $label;
                }
            }

            // ---- Alle drei Quellen einsammeln ----
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

            // ---- Werte zusammenfuehren (nur propertyId + value) ----
            $gefunden      = [];
            $herkunft      = [];
            $widersprueche = [];
            $verdaechtige  = [];

            foreach ($quellenListe as $quelle) {
                $quellenName = $quelle['name'];
                foreach ($quelle['liste'] as $eintrag) {
                    // ENTSCHEIDEND: erst in ein normales Array umwandeln.
                    $felder = $this->alsFelder($eintrag);
                    if (count($felder) === 0) {
                        continue;
                    }
                    $pid = (int) $this->getPropertyId($felder);
                    $val = trim((string) $this->getValue($felder));
                    if ($pid <= 0 || $val === '') {
                        continue;
                    }
                    if (!isset($idZuLabel[$pid])) {
                        continue; // fremde Eigenschaft -> nie uebertragen
                    }
                    // Sicherung 2: sieht der Wert wie eine Beschriftung aus?
                    if ($this->siehtWieBeschriftungAus($val)) {
                        $verdaechtige[] = $idZuLabel[$pid] . ' (ID ' . $pid . '): "' . $val . '"';
                        continue;
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

            // ---- Bilanz-Zeile ----
            $this->getLogger(self::LOG_KENNUNG)->error(
                '[MIRKA-DIAG] VOR BASKET->ORDER'
                . ' | variationId=' . $variationId
                . ' | gefunden=' . (6 - count($fehlende)) . '/6'
                . ' | Quellen mit Inhalt=' . count($quellenListe)
                . ' | Herkunft: ' . implode(' ', $herkunftText)
                . ' | erwartete IDs=' . implode(',', $erwartet)
                . ' | Uebergabe=' . (self::UEBERGABE_AKTIV ? 'AKTIV' : 'AUS (nur Messung)')
                . (count($verdaechtige) > 0
                    ? ' | VERWORFEN (sieht nach Beschriftung aus): ' . implode(' ; ', $verdaechtige)
                    : '')
            );

            // ---- Bei Fehlschlag: echte Feldform ins Log ----
            if (count($fehlende) > 0) {
                $this->getLogger(self::LOG_KENNUNG)->error(
                    '[MIRKA-STRUKTUR] originOrderVariationProperties | ' . $this->strukturDetail($q1)
                );
                $this->getLogger(self::LOG_KENNUNG)->error(
                    '[MIRKA-STRUKTUR] basketItemOrderParams | ' . $this->strukturDetail($q2)
                );
                $this->getLogger(self::LOG_KENNUNG)->error(
                    '[MIRKA-STRUKTUR] basketItemVariationProperties | ' . $this->strukturDetail($q3)
                );
            }

            if (!self::UEBERGABE_AKTIV) {
                $this->getLogger(self::LOG_KENNUNG)->error(
                    '[MIRKA-KURZ] BASKET->ORDER NUR MESSUNG | variationId=' . $variationId
                    . ' | haette uebergeben=' . count($zuUebergeben) . '/6'
                    . ' | Es wurde NICHTS geschrieben.'
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
                . ' | Werte: ' . $this->payloadKurz($zuUebergeben)
            );

        } catch (\Throwable $t) {
            $this->getLogger(self::LOG_KENNUNG)->error(
                '[MIRKA-PROBLEM] BasketToOrderListener Exception: ' . $t->getMessage(),
                ['message' => $t->getMessage(), 'file' => $t->getFile(), 'line' => $t->getLine()]
            );
        }
    }

    // ------------------------------------------------------------------
    //  Umwandlung und Struktur
    // ------------------------------------------------------------------

    /**
     * Macht aus einer Quelle IMMER eine einfache PHP-Liste.
     * Arrays direkt, Objekte/Sammlungen ueber foreach.
     * (Der is_array()-Fehler aus v1.5.13/v1.5.14 ist damit erledigt.)
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
     * NEU v1.5.17 - DER ENTSCHEIDENDE PUNKT.
     *
     * Wandelt EINEN Eintrag in ein normales Feld-Array um.
     * Plenty-Modelle geben ihre echten Inhalte weder ueber foreach noch
     * ueber isset($obj->feld) heraus - beides sieht nur die internen
     * Basis-Schalter (belegt in Auftrag 329694). json_encode() dagegen
     * benutzt die modelleigene Ausgabe und liefert die echten Felder.
     *
     * @param mixed $eintrag
     * @return array  leer, wenn nichts lesbar war
     */
    private function alsFelder($eintrag)
    {
        if (is_array($eintrag)) {
            return $eintrag;
        }
        if (!is_object($eintrag)) {
            return [];
        }
        try {
            $json = @json_encode($eintrag);
            if (is_string($json) && $json !== '' && $json !== 'null') {
                $arr = @json_decode($json, true);
                if (is_array($arr)) {
                    return $arr;
                }
            }
        } catch (\Throwable $egal) {
            // nicht umwandelbar - unten leer zurueckgeben
        }
        return [];
    }

    /**
     * Struktur einer Quelle fuers Log - mit den ECHTEN Feldern, weil
     * jeder Eintrag vorher durch alsFelder() geht.
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
        $text  = 'n=' . $n . ';';
        $index = 0;
        foreach ($liste as $eintrag) {
            $felder = $this->alsFelder($eintrag);
            if (count($felder) === 0) {
                $text .= '[' . $index . ']{nicht umwandelbar}';
            } else {
                $teile = [];
                foreach ($felder as $name => $wert) {
                    $teile[] = (string) $name . '=' . $this->wertKurz($wert);
                }
                $text .= '[' . $index . ']{' . implode('|', $teile) . '}';
            }
            $index++;
            if (strlen($text) > self::STRUKTUR_MAX) {
                $text .= '...(gekuerzt)';
                break;
            }
        }
        return $text;
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
            return '(liste:' . implode(',', array_keys($w)) . ')';
        }
        return '(objekt)';
    }

    /** Die uebergebenen Werte kurz fuers Log. */
    private function payloadKurz($payload)
    {
        $teile = [];
        foreach ($payload as $p) {
            $teile[] = $p['propertyId'] . '=' . $p['value'];
        }
        return implode(' ', $teile);
    }

    /**
     * Sicherung gegen Beschriftungen statt Kundenwerten.
     *
     * In diesem Projekt hat Plenty an anderer Stelle bereits
     * "Schleifband Koernung" statt "80" geliefert. Solche Texte duerfen
     * niemals als Kundenwert durchgehen, sonst entsteht ein sauber
     * aussehendes 6/6 mit sechs Beschriftungen.
     *
     * @param string $wert
     * @return bool
     */
    private function siehtWieBeschriftungAus($wert)
    {
        if (strlen($wert) > self::WERT_MAX_LAENGE) {
            return true;
        }
        $klein = strtolower($wert);
        if (strpos($klein, 'schleifband') !== false) {
            return true;
        }
        if (strpos($klein, 'artikelnummer') !== false) {
            return true;
        }
        if (strpos($klein, 'koernung') !== false || strpos($klein, 'körnung') !== false) {
            return true;
        }
        if (strpos($klein, 'verbindung') !== false) {
            return true;
        }
        if (strpos($klein, 'qualit') !== false) {
            return true;
        }
        if (strpos($klein, 'breite in mm') !== false || strpos($klein, 'laenge in mm') !== false) {
            return true;
        }
        return false;
    }

    // ------------------------------------------------------------------
    //  FESTE Feldzugriffe auf den WARENKORB-ARTIKEL.
    //  Dynamische Property-Namen sind in der Plenty-Sandbox VERBOTEN.
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

    // ------------------------------------------------------------------
    //  Zugriffe auf EINEN bereits umgewandelten Eintrag (immer Array).
    //  Nur die dokumentierten Feldnamen - kein Raten.
    // ------------------------------------------------------------------

    /** Eigenschafts-ID: ausschliesslich aus  propertyId. */
    private function getPropertyId($felder)
    {
        if (is_array($felder) && isset($felder['propertyId'])) {
            return $this->nurSkalar($felder['propertyId']);
        }
        return '';
    }

    /** Kundenwert: ausschliesslich aus  value. */
    private function getValue($felder)
    {
        if (is_array($felder) && isset($felder['value'])) {
            return $this->nurSkalar($felder['value']);
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
