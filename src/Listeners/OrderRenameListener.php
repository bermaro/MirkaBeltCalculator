<?php

namespace MirkaBeltCalculator\Listeners;

use Plenty\Modules\Order\Events\OrderCreated;
use Plenty\Modules\Order\Contracts\OrderRepositoryContract;
use Plenty\Modules\Frontend\Session\Storage\Contracts\FrontendSessionStorageFactoryContract;
use Plenty\Plugin\Log\Loggable;
use MirkaBeltCalculator\Configs\PluginConfig;

/**
 * OrderRenameListener (v1.5.22)
 *
 * ---------------------------------------------------------------------
 * KORREKTUR v1.5.15 (08.09.2026) - RUECKFALL WIEDERHERGESTELLT
 * ---------------------------------------------------------------------
 *   In v1.5.13/v1.5.14 wurde der Sitzungs-Zettel ERSATZLOS entfernt,
 *   BEVOR der neue direkte Weg (BasketToOrderListener) nachweislich
 *   funktionierte. Ergebnis (Auftrag 329681): der direkte Weg lieferte
 *   0/6, der Zettel war abgeschaltet - also bekam der Auftrag GAR NICHTS.
 *   Damit war v1.5.13/v1.5.14 SCHLECHTER als v1.5.12, auch beim ganz
 *   normalen Kauf ohne Login-Wechsel. Das war ein Fehler.
 *
 *   Der Zettel ist deshalb wieder da - aber ausschliesslich als LETZTER
 *   RUECKFALL und mit geschlossener Luecke:
 *     - Direkte Werte vom Warenkorb-Artikel sind IMMER fuehrend; der
 *       Zettel ergaenzt nur noch leere Felder, er ueberschreibt nie.
 *     - Die Zuordnung laeuft ueber ALLE Konfigurator-Positionen, nicht
 *       nur ueber die unvollstaendigen. Eine Position, die bereits 6/6
 *       hat, VERBRAUCHT ihren Zettel trotzdem. Sonst haette der liegen
 *       gebliebene Zettel von A einer offenen Position B zugeordnet
 *       werden koennen (die Luecke aus v1.5.13).
 *     - Die Eindeutigkeitsregeln aus v1.5.12 gelten unveraendert:
 *       Zuordnung nur, wenn der Preis auf BEIDEN Seiten eindeutig ist.
 *     - KEINE MISCHUNG (v1.5.16, in v1.5.22 verbessert): Hat die Position
 *       schon einzelne direkte Werte, muss der Zettel bei JEDEM davon
 *       denselben Wert tragen. Weicht auch nur einer ab, gehoert der Zettel
 *       zu einem anderen Band - dann wird nichts ergaenzt und gemeldet.
 *       Stimmen alle ueberein, werden die Luecken gefuellt.
 *       (v1.5.16 hatte den Teilwert-Fall pauschal blockiert. Das war
 *       strenger als noetig und gegenueber v1.5.12 eine Verschlechterung:
 *       dort waere die Position vervollstaendigt worden.)
 *   Bekannte Grenze, ausdruecklich dokumentiert: Der Preis bleibt KEIN
 *   Identitaetsbeweis, und bei Sitzungswechsel (Login/Logout) ist der
 *   Zettel weg. Der Rueckfall ist eine Notversorgung, kein Beweis.
 * ---------------------------------------------------------------------
 *
 * RANGFOLGE DER QUELLEN (Stand v1.5.15):
 *   1. Direkte Werte vom Warenkorb-Artikel, uebergeben vom
 *      BasketToOrderListener (im Auftrag gelesen als Quelle A/B/C).
 *      Diese sind IMMER fuehrend.
 *   2. Sitzungs-Zettel ('mirkaKonfigListe') - nur als Rueckfall fuer
 *      Felder, die nach Schritt 1 noch LEER sind, und nur unter den
 *      strengen Eindeutigkeitsregeln (siehe Korrekturblock oben).
 *   3. Bleibt danach etwas leer, wird die Position BEWUSST unvollstaendig
 *      gelassen und laut gemeldet. Lieber sichtbar unvollstaendig als
 *      unsichtbar falsch.
 *
 * NEU v1.5.3 (13.08.2026): SERVERSEITIGER GUARD.
 *   Zusaetzlich zur Umbenennung prueft dieser Listener jetzt, ob JEDE
 *   Konfigurator-Position alle sechs Eigenschaften traegt (Qualitaet,
 *   Koernung, Verbindung, Breite, Laenge, Mirka-Nr). Datenbasis sind die
 *   bereits zusammengefuehrten Werte aus den Auftrags-Quellen A/B/C
 *   plus dem Zettel-Rueckfall - NICHT ein
 *   separater, schwaecherer Parser. Fehlt ein Wert, wird der Auftrag laut
 *   gemeldet (Methode fuehreGuardAus, Modus aus Tab 8). Der Guard laeuft
 *   bei OrderCreated und verhindert die Bestellung NICHT (das ist zu
 *   spaet), macht den Fehlerfall 328897 aber SICHTBAR, damit kein Auftrag
 *   mit leeren Eigenschaften unbemerkt weiterlaeuft.
 *
 * AENDERUNG v1.4.6 (07.07.2026, Wunsch Bernd):
 * Die Unterzeile "Schleifband Qualität" zeigt jetzt den ausgeschriebenen
 * Qualitaetsnamen mit Code, z.B. "Schleifband Qualität: ABRANET MAX (AB0)"
 * statt nur "AB0". Der gespeicherte Eigenschafts-WERT (fuer Mirka/
 * Fertigung) bleibt unveraendert der reine Code.
 *
 * KORREKTUR 06.07.2026 (Deploy-Fehler): Die PHP-Funktion abs ist in
 * der Plenty-Sandbox verboten ("php function 'abs' is not allowed").
 * Der Preis-Toleranzvergleich rechnet die Differenz jetzt selbst aus
 * (Vorzeichen manuell drehen) - gleiche Logik, ohne abs.
 *
 * AENDERUNGEN v1.4.5 (Zuordnungs-Absicherung, Hinweis aus Code-Review):
 *   RISIKO vorher: Legt ein Kunde Band A und Band B in den Warenkorb,
 *   loescht B wieder und bestellt nur A, dann war der LETZTE Zettel in
 *   der Sitzung der von B -> Position A haette den falschen Namen
 *   bekommen. Das waeren falsche Fertigungsdaten!
 *   ABSICHERUNG jetzt: Jeder Zettel traegt den Brutto-Verkaufspreis.
 *   Der Umbenenner liest den Brutto-Einzelpreis der Auftragsposition
 *   und ordnet einen Zettel NUR zu, wenn die Preise uebereinstimmen
 *   (Toleranz 0,005 EUR; Suche vom neuesten Zettel rueckwaerts).
 *   Ist der Positionspreis nicht lesbar, wird nur der voellig
 *   eindeutige Fall zugelassen (genau 1 Position + genau 1 Zettel).
 *   In JEDEM Zweifelsfall gilt fail-safe: lieber der alte, generische
 *   Name als ein falscher.
 *
 * AENDERUNGEN v1.4.4 (nach Roentgen-Auswertung Auftrag 327788, 06.07.2026):
 *   ERKENNTNIS: Der Auftrag wurde nachweislich MIT Relationen geladen,
 *   und trotzdem waren alle orderProperties LEER; Unterfeld typeId 82
 *   enthaelt in diesem System die BESCHRIFTUNG ("Schleifband Koernung"),
 *   nicht den Kundenwert. Plenty speichert die Kundenwerte also GAR NICHT
 *   am Auftrag.
 *   LOESUNG ("Zettel-Prinzip", Idee Bernd): Der BasketItemListener
 *   (ab v1.3.0) legt die Kundenwerte beim In-den-Warenkorb-Legen als
 *   Zettel in der Kunden-Sitzung ab ('mirkaKonfigListe'). Der Auftrag
 *   entsteht im selben Kundenbesuch -> dieser Listener liest den Zettel
 *   (neue QUELLE Z, fuehrend) und beschriftet damit die Positionen.
 *   AUSSERDEM: Unterfeld typeId 82 wird NIE mehr als Wert benutzt
 *   (Ursache des Kauderwelsch-Namens in Auftrag 327754); aus den
 *   Typ-15-Zeilen wird nur noch die Eigenschafts-ID (Feld 81) gelesen.
 *
 * ZWECK:
 *   Gibt den Auftragspositionen des Schleifband-Konfigurators SPRECHENDE
 *   Namen, sobald ein Auftrag angelegt wurde. Aus
 *       "Mirka Baenderrechner fuer Breitbaender und Langbaender - ..."
 *   wird z. B.
 *       "Mirka Schleifband ABRANET MAX (AB0), P60, Verbindung T,
 *        50 x 3000 mm, Mirka-Nr. AB4AZT0160LF"
 *   Zusaetzlich werden die Bestelleigenschafts-Unterzeilen (bisher nur
 *   "Schleifband") umbenannt in "Name: Wert", z. B.
 *       "Schleifband Qualitaet: AB0".
 *
 * AENDERUNGEN v1.4.3 (nach Praxistest Auftrag 327754):
 *   1) DIAGNOSE-ROENTGENBLICK: Der Praxistest zeigte, dass dieses System
 *      an der offiziell dokumentierten Wert-Stelle (Unterfeld typeId 82)
 *      die NAMEN der Eigenschaften liefert statt der Kundenwerte -
 *      dasselbe Muster wie zuvor im EmailBuilder. Wo die echten Werte
 *      (AB0, 60, T, ...) am Auftrag liegen, verraet nur das System
 *      selbst. Deshalb schreibt der Listener im Modus "NUR
 *      PROTOKOLLIEREN" jetzt einen VOLLSTAENDIGEN Diagnose-Dump ins
 *      Log: fuer jede Typ-15-Zeile den eigenen Positionsnamen, ALLE
 *      Unterfelder (typeId => Inhalt) und alle orderProperties, dazu
 *      die orderProperties der Hauptposition. Ein Testbestellungs-Log
 *      zeigt damit exakt, in welchem Feld die Kundenwerte stecken.
 *   2) MEHRZEILIGER NAME (Wunsch Bernd): Der neue Positionsname wird
 *      mit Zeilenumbruechen aufgebaut (Qualitaet / Koernung+Verbindung /
 *      Mass / Mirka-Nr. untereinander). Ob jedes Dokument die
 *      Umbrueche darstellt, zeigt der Test - sonst Umstellung auf
 *      Trennzeichen.
 *
 * AENDERUNGEN v1.4.1 (nach externem Code-Review):
 *   1) AUFTRAG NEU LADEN: Der Listener vertraut nicht mehr dem
 *      moeglicherweise unvollstaendig geladenen Auftrag aus dem Event,
 *      sondern laedt ihn ueber OrderRepositoryContract::findOrderById()
 *      frisch - erst MIT gewuenschten Relationen, bei Fehler OHNE,
 *      als letzter Rueckfall das Event-Objekt. Die genutzte Quelle
 *      wird geloggt.
 *   2) MEHRERE DATENQUELLEN fuer die Kundenwerte: (a) Unterfelder der
 *      Typ-15-Zeilen (typeId 81 = Eigenschafts-ID, 82 = Wert),
 *      (b) orderProperties der Typ-15-Zeilen, (c) orderProperties der
 *      Hauptposition. Welche Quelle getroffen hat, steht im Log -
 *      der Protokollier-Testlauf zeigt so die echte Struktur im System.
 *   3) SCHREIB-SICHERUNG: Vor dem Schreiben wird der komplette Payload
 *      geloggt; nach dem Schreiben wird der Auftrag erneut geladen und
 *      Rechnungsbetrag + Positionszahl mit vorher verglichen. Jede
 *      Abweichung wird laut protokolliert.
 *   BEIBEHALTEN: updateOrder($data, $orderId). Die offizielle
 *   Schnittstellen-Doku (stable7) fuehrt die Methode ohne
 *   Deprecated-Vermerk, und das offizielle Plenty-Tutorial zu
 *   Event-Procedures nutzt exakt dieses Muster
 *   (updateOrder(['statusId' => 3], $order->id)).
 *
 * SICHERHEITSKONZEPT (Regieanweisung "dryRun zuerst"):
 *   Plugin-Einstellung "Positionsnamen umschreiben" (Tab 7):
 *     off = Listener tut nichts.
 *     log = NUR PROTOKOLLIEREN: neue Namen nur ins Log, Auftrag
 *           unveraendert (Standard nach dem Update).
 *     on  = AKTIV: Namen werden per updateOrder() geschrieben.
 *
 * FEHLERVERHALTEN:
 *   Jeder Fehler wird nur geloggt. Der Bestellabschluss des Kunden wird
 *   NIEMALS gestoert - schlimmstenfalls behaelt die Position ihren
 *   alten Namen.
 *
 * HINWEIS zu error():
 *   Wie im BasketItemListener ist error() der garantiert sichtbare
 *   Diagnose-Kanal der Testphase (info()/debug() schreiben in Plenty nur
 *   mit Uebersetzungs-Schluessel). Vor dem Go-Live werden die
 *   [DIAG]-Zeilen entfernt (Vor-Live-Checkliste).
 */
class OrderRenameListener
{
    use Loggable;

    /**
     * NEU v1.5.6: EINE feste Log-Kennung fuer ALLE Meldungen dieses
     * Listeners. Damit liegt alles im Log unter einem Identifikator.
     */
    const LOG_KENNUNG = 'MirkaBeltCalculator::MIRKA';

    /**
     * NEU v1.5.8: Merker fuer den Debug-Zustand (Tab 6), damit diag()
     * die Konfiguration nur einmal pro Auftrag laden muss.
     */
    private $debugGeprueft = false;
    private $debugAn = true;

    /** Positionstyp: normale Variantenposition (der Sammelartikel). */
    const TYP_VARIANTENPOSITION = 1;

    /** Positionstyp: Bestelleigenschaft als eigene Position. */
    const TYP_BESTELLEIGENSCHAFT = 15;

    /** Positions-Eigenschaft: traegt die ID der Bestelleigenschaft. */
    const PROP_TYP_EIGENSCHAFTS_ID = 81;

    /** Positions-Eigenschaft: traegt den vom Kunden gewaehlten WERT. */
    const PROP_TYP_WERT = 82;

    /**
     * Anzeigenamen der 16 Mirka-Qualitaeten (Code -> Name).
     * Muss zur Liste in der JavaScript-Folie passen.
     */
    const QUALITAETS_NAMEN = [
        'AB0' => 'ABRANET MAX',
        '330' => 'ALOX',
        '42A' => 'AVOMAX ANTISTATIC',
        '470' => 'GOLD MAX',
        '5B0' => 'HIOLIT JCA2A0',
        '590' => 'HIOLIT XO',
        '5C0' => 'HIOLIT YPZ1A0',
        '44A' => 'JEPUFLEX ANTISTATIC',
        'EAB' => 'MI231A 5MIL',
        'FM0' => 'MICROSTAR',
        '04A' => 'SICA CLOSED',
        '050' => 'SICA FINE STEARATE',
        '490' => 'SICA OPEN',
        'UC0' => 'ULTIMAX',
        'UB0' => 'ULTIMAX BLACK',
        '110' => 'UNIMAX',
    ];

    /**
     * Wird vom Event-Dispatcher aufgerufen, NACHDEM ein Auftrag
     * angelegt wurde.
     */
    public function handle(OrderCreated $event)
    {
        try {
            /** @var PluginConfig $config */
            $config = pluginApp(PluginConfig::class);

            $renameModus = $config->getRenamePositionsMode();
            $guardModus  = $config->getFailClosedMode();

            // NEU v1.5.7: Aussteigen NUR, wenn BEIDE Funktionen aus sind.
            //  - Tab 7 (Umbenennen) aus + Tab 8 (Fehlerschutz) log/on
            //    -> Datenaufbereitung + Guard muessen weiterlaufen.
            //  - BEIDE aus -> gar nichts tun.
            // (v1.5.3 hatte den frueheren "nur Tab 7"-Ausstieg entfernt,
            //  aber keinen Ersatz fuer den Fall "beide aus" gesetzt.)
            if ($renameModus === 'off' && $guardModus === 'off') {
                return;
            }

            $modus = $renameModus;
            // NEU v1.5.3: KEIN frueher Ausstieg mehr bei modus==='off'.
            // Frueher stand hier "if (off) return;" - das haette aber den
            // NEUEN Guard (weiter unten) mit abgeschaltet, obwohl der einen
            // EIGENEN Schalter in Tab 8 hat. Der Umbenenn-Modus wird jetzt
            // erst spaeter geprueft (kurz vor dem tatsaechlichen Schreiben);
            // die Werte-Zusammenfuehrung und der Guard laufen unabhaengig
            // davon. So ist der Fehlerschutz aktiv, auch wenn die
            // Positionsnamen-Umbenennung (Tab 7) auf "off" steht.

            $eventAuftrag = $event->getOrder();
            if ($eventAuftrag === null) {
                $this->diag('[DIAG][Rename] Event ohne Auftrag - Abbruch.');
                return;
            }

            // Nur normale Verkaufsauftraege behandeln (typeId 1).
            if ((int) $eventAuftrag->typeId !== 1) {
                return;
            }

            $auftragsId = (int) $eventAuftrag->id;

            // -----------------------------------------------------------
            // NEU v1.4.1 (Review-Punkt 1): Auftrag FRISCH laden, damit
            // Positionen, Unterfelder und Referenzen sicher vorhanden
            // sind. Das Event-Objekt ist nur der letzte Rueckfall.
            // -----------------------------------------------------------
            $order = $this->ladeAuftragVollstaendig($auftragsId, $eventAuftrag);

            // -----------------------------------------------------------
            // Schritt 1: Positionen einsammeln.
            // -----------------------------------------------------------
            $hauptPositionen    = []; // orderItemId => Positions-Modell
            $eigenschaftsZeilen = []; // Liste der Typ-15-Positionen

            foreach ($order->orderItems as $position) {
                $typ = (int) $position->typeId;
                if ($typ === self::TYP_VARIANTENPOSITION
                    && $config->isHandledVariation((int) $position->itemVariationId)) {
                    $hauptPositionen[(int) $position->id] = $position;
                } elseif ($typ === self::TYP_BESTELLEIGENSCHAFT) {
                    $eigenschaftsZeilen[] = $position;
                }
            }

            if (count($hauptPositionen) === 0) {
                return; // Kein Konfigurator-Artikel im Auftrag - fertig.
            }

            $this->diag('[DIAG][Rename] Auftrag ' . $auftragsId . ': '
                . count($hauptPositionen) . ' Konfigurator-Position(en), '
                . count($eigenschaftsZeilen) . ' Eigenschafts-Zeile(n), Modus=' . $modus);

            // ---------------------------------------------------------
            // NEU v1.4.3: DIAGNOSE-ROENTGENBLICK (nur im Modus 'log').
            // Schreibt fuer jede Eigenschafts-Zeile und jede Haupt-
            // position ALLE verfuegbaren Felder ins Log, damit wir
            // sehen, wo dieses System die Kundenwerte wirklich ablegt.
            // ---------------------------------------------------------
            if ($modus === 'log') {
                foreach ($eigenschaftsZeilen as $zeile) {
                    $this->diag('[DIAG][Rename][DUMP] Zeile ' . (int) $zeile->id
                        . ' | eigener Name: "' . (string) $zeile->orderItemName . '"');
                    try {
                        foreach ($zeile->properties as $eigenschaft) {
                            $this->diag('[DIAG][Rename][DUMP]   Unterfeld typeId='
                                . (int) $eigenschaft->typeId
                                . ' value="' . (string) $eigenschaft->value . '"');
                        }
                    } catch (\Throwable $egal) {
                        $this->diag('[DIAG][Rename][DUMP]   (Unterfelder nicht lesbar: '
                            . $egal->getMessage() . ')');
                    }
                    try {
                        foreach ($zeile->orderProperties as $op) {
                            $this->diag('[DIAG][Rename][DUMP]   orderProperty propertyId='
                                . (int) $op->propertyId
                                . ' value="' . (string) $op->value . '"');
                        }
                    } catch (\Throwable $egal) {
                        $this->diag('[DIAG][Rename][DUMP]   (orderProperties nicht lesbar: '
                            . $egal->getMessage() . ')');
                    }
                }
                foreach ($hauptPositionen as $hauptId => $position) {
                    $this->diag('[DIAG][Rename][DUMP] Hauptposition ' . (int) $hauptId
                        . ' | Name: "' . (string) $position->orderItemName . '"');
                    try {
                        foreach ($position->orderProperties as $op) {
                            $this->diag('[DIAG][Rename][DUMP]   orderProperty propertyId='
                                . (int) $op->propertyId
                                . ' value="' . (string) $op->value . '"');
                        }
                    } catch (\Throwable $egal) {
                        $this->diag('[DIAG][Rename][DUMP]   (orderProperties nicht lesbar: '
                            . $egal->getMessage() . ')');
                    }
                    try {
                        foreach ($position->properties as $eigenschaft) {
                            $this->diag('[DIAG][Rename][DUMP]   Unterfeld typeId='
                                . (int) $eigenschaft->typeId
                                . ' value="' . (string) $eigenschaft->value . '"');
                        }
                    } catch (\Throwable $egal) {
                        $this->diag('[DIAG][Rename][DUMP]   (Unterfelder nicht lesbar: '
                            . $egal->getMessage() . ')');
                    }
                }
            }

            // -----------------------------------------------------------
            // Schritt 2: Werte je Hauptposition sammeln - aus MEHREREN
            // Quellen (Review-Punkt 2). Jeder Fund wird mit Quelle geloggt.
            //   werte[hauptId][eigenschaftsId] = Kundenwert
            //   zeilenJeHaupt[hauptId][]       = Typ-15-Zeilen (Umbenennen)
            // -----------------------------------------------------------
            $werte         = [];
            $zeilenJeHaupt = [];

            foreach ($eigenschaftsZeilen as $zeile) {
                // Zugehoerige Hauptposition ueber die Referenz finden.
                $hauptId = 0;
                try {
                    foreach ($zeile->references as $referenz) {
                        if ((string) $referenz->referenceType === 'order_property') {
                            $hauptId = (int) $referenz->referenceOrderItemId;
                            break;
                        }
                    }
                } catch (\Throwable $egal) {
                    // Referenzen nicht lesbar -> Rueckfall unten.
                }
                // Rueckfall: genau EINE Hauptposition -> zuordnen.
                if ($hauptId === 0 && count($hauptPositionen) === 1) {
                    $ids     = array_keys($hauptPositionen);
                    $hauptId = (int) $ids[0];
                }
                if ($hauptId === 0 || !isset($hauptPositionen[$hauptId])) {
                    continue; // Gehoert nicht zu unserem Artikel.
                }

                // Quelle A: Unterfelder der Typ-15-Zeile.
                // NUR noch Feld 81 (Eigenschafts-ID) wird gelesen!
                // Feld 82 enthaelt in diesem System die BESCHRIFTUNG,
                // nicht den Wert (bewiesen im Roentgen-Test 06.07.2026)
                // und wird deshalb bewusst ignoriert.
                $eigenschaftsId = 0;
                $wert           = '';
                try {
                    foreach ($zeile->properties as $eigenschaft) {
                        $propTyp = (int) $eigenschaft->typeId;
                        if ($propTyp === self::PROP_TYP_EIGENSCHAFTS_ID) {
                            $eigenschaftsId = (int) $eigenschaft->value;
                        }
                    }
                } catch (\Throwable $egal) {
                    // properties nicht lesbar -> Quelle B versuchen.
                }
                $quelle = 'A(nur Feld 81, ohne Wert)';

                // Quelle B (v1.5.13 KORRIGIERT): Die orderProperties der
                // Typ-15-Zeile werden jetzt IMMER geprueft - auch dann, wenn
                // die Eigenschafts-ID aus Feld 81 bereits bekannt ist.
                // Frueher lief dieser Block nur bei UNBEKANNTER ID; dadurch
                // waeren Werte, die Plenty an genau dieser Zeile ablegt
                // (moeglicher Zielort von addAdditionalVariationProperties),
                // unbemerkt liegen geblieben.
                try {
                    foreach ($zeile->orderProperties as $op) {
                        $opId  = (int) $op->propertyId;
                        $opVal = trim((string) $op->value);
                        if ($opId <= 0) {
                            continue;
                        }
                        if ($eigenschaftsId === 0) {
                            // ID war unbekannt -> komplett von hier uebernehmen.
                            $eigenschaftsId = $opId;
                            $wert           = $opVal;
                            $quelle         = 'B(orderProperties Zeile)';
                            if ($wert !== '') {
                                break;
                            }
                        } elseif ($opId === $eigenschaftsId && $opVal !== '' && $wert === '') {
                            // ID aus Feld 81 bekannt -> passenden, nicht-leeren
                            // Wert dieser Zeile uebernehmen.
                            $wert   = $opVal;
                            $quelle = 'B(orderProperties Zeile, ID aus Feld 81)';
                            break;
                        }
                    }
                } catch (\Throwable $egal) {
                    // Nicht lesbar -> die Zeile liefert dann nur die ID.
                }

                if ($eigenschaftsId > 0) {
                    $werte[$hauptId][$eigenschaftsId] = $wert;
                    $zeilenJeHaupt[$hauptId][] = [
                        'positionsId'    => (int) $zeile->id,
                        'eigenschaftsId' => $eigenschaftsId,
                        'wert'           => $wert,
                    ];
                    $this->diag('[DIAG][Rename] Zeile erkannt (' . $quelle . '): '
                        . 'Eigenschaft ' . $eigenschaftsId
                        . ($wert !== '' ? ' = "' . $wert . '"' : ' (Wert an dieser Zeile leer - ggf. spaeter aus dem Rueckfall)')
                        . ' (Zeile ' . (int) $zeile->id . ' -> Haupt ' . $hauptId . ')');
                }
            }

            // Quelle C: orderProperties direkt an der Hauptposition
            // (ergaenzt nur, was noch fehlt - ueberschreibt nichts).
            foreach ($hauptPositionen as $hauptId => $position) {
                try {
                    foreach ($position->orderProperties as $op) {
                        $opId  = (int) $op->propertyId;
                        $opVal = trim((string) $op->value);
                        // NEU v1.5.13 (Blocker-Fix): Auch fuellen, wenn der
                        // Schluessel zwar existiert, aber LEER ist. Quelle A
                        // legt 64-69 vorher leer an; ohne diese Ergaenzung
                        // wuerden die vom BasketToOrderListener DIREKT an die
                        // Position geschriebenen Werte hier ignoriert.
                        $nochLeer = !isset($werte[$hauptId][$opId])
                            || trim((string) $werte[$hauptId][$opId]) === '';
                        if ($opId > 0 && $opVal !== '' && $nochLeer) {
                            $werte[$hauptId][$opId] = $opVal;
                            $this->diag('[DIAG][Rename] Wert gefunden (C(orderProperties '
                                . 'Hauptposition)): Eigenschaft ' . $opId . ' = "'
                                . $opVal . '" (Haupt ' . $hauptId . ')');
                        }
                    }
                } catch (\Throwable $egal) {
                    // Relation existiert hier nicht - kein Problem.
                }
            }

            // -----------------------------------------------------------
            // QUELLE Z ("Zettel aus der Kunden-Sitzung") - LETZTER RUECKFALL.
            //
            // Seit v1.5.15 wieder aktiv, aber streng nachrangig:
            //   direkter Weg (BasketToOrderListener, Quellen A/B/C) = fuehrend
            //   Zettel = fuellt nur noch LEERE Felder auf
            // Die Zuordnung laeuft ueber ALLE Konfigurator-Positionen, damit
            // eine bereits vollstaendige Position ihren Zettel verbraucht und
            // er nicht faelschlich bei einer anderen Position landet.
            // -----------------------------------------------------------
            $this->uebernehmeZettelWerteRueckfall($config, $hauptPositionen, $werte);

            // Bilanz nach ALLEN Quellen (direkt + Rueckfall).
            $unvollstaendig = 0;
            foreach ($hauptPositionen as $hId => $pos) {
                $wVorhanden = isset($werte[$hId]) ? $werte[$hId] : [];
                if (!$this->hatAlleSechsWerte($config, $wVorhanden)) {
                    $unvollstaendig++;
                }
            }
            if ($unvollstaendig > 0) {
                $this->wichtig('[MIRKA-PROBLEM] ' . $unvollstaendig
                    . ' Konfigurator-Position(en) haben WEDER ueber den direkten Weg '
                    . '(Warenkorb -> Auftrag) NOCH ueber den Sitzungs-Zettel alle '
                    . 'sechs Werte erhalten. Die Position bleibt unvollstaendig '
                    . 'und wird gemeldet.');
            } else {
                $this->diag('[DIAG][Rename] Alle Konfigurator-Positionen haben '
                    . '6/6 Werte.');
            }

            // -----------------------------------------------------------
            // NEU v1.5.3: SERVERSEITIGER GUARD (Vollstaendigkeits-Pruefung).
            // Anders als ein separater Parser nutzt der Guard GENAU die
            // Werte, die der Umbenenner nach der Zettel-Zuordnung fertig
            // vorliegen hat (Session-Zettel + alle Auftrags-Quellen). So
            // wird eine Konfigurator-Position, bei der eine der sechs
            // Eigenschaften (Qualitaet/Koernung/Verbindung/Breite/Laenge/
            // Mirka-Nr) FEHLT, zuverlaessig erkannt (Fall 328897) - ohne
            // Fehlalarm, weil hier dieselbe Datenbasis wie fuer die
            // Umbenennung gilt. Gesammelt wird in der Namens-Schleife
            // unten; die Meldung erfolgt danach.
            $guardProbleme = [];

            // -----------------------------------------------------------
            // Schritt 3: Neue Namen bauen.
            // -----------------------------------------------------------
            $neueNamen = []; // orderItemId => neuer Name

            foreach ($hauptPositionen as $hauptId => $position) {
                $w = isset($werte[$hauptId]) ? $werte[$hauptId] : [];

                $code    = $this->holeWert($w, $config->getPropertyIdSchleifmittel());
                $grit    = $this->holeWert($w, $config->getPropertyIdKoernung());
                $joint   = $this->holeWert($w, $config->getPropertyIdVerbindung());
                $breite  = $this->holeWert($w, $config->getPropertyIdBreite());
                $laenge  = $this->holeWert($w, $config->getPropertyIdLaenge());
                $mirkaNr = $this->holeWert($w, $config->getPropertyIdMirkaCode());

                // NEU v1.5.3: Guard-Vollstaendigkeit - ALLE SECHS Werte
                // muessen nicht-leer sein. Fehlt einer, ist die Position
                // verdaechtig (auch die Mirka-Nr wird hier verlangt).
                $fehlende = [];
                if ($code === '')    { $fehlende[] = 'Qualitaet';   }
                if ($grit === '')    { $fehlende[] = 'Koernung';    }
                if ($joint === '')   { $fehlende[] = 'Verbindung';  }
                if ($breite === '')  { $fehlende[] = 'Breite';      }
                if ($laenge === '')  { $fehlende[] = 'Laenge';      }
                if ($mirkaNr === '') { $fehlende[] = 'Mirka-Nr';    }
                if (count($fehlende) > 0) {
                    $guardProbleme[] = [
                        'positionsId' => (int) $hauptId,
                        'gefunden'    => (6 - count($fehlende)),
                        'fehlende'    => $fehlende,
                    ];
                }

                // Ohne ALLE SECHS Pflichtwerte wird NICHT umbenannt
                // (kein Raten, lieber alter Name als falscher Name).
                // NEU v1.5.8 (Punkt B): Die Mirka-Nummer zaehlt jetzt
                // ebenfalls als Pflichtwert - vorher wurden nur fuenf
                // Werte verlangt, eine Position ohne Mirka-Nr. wurde also
                // umbenannt, obwohl der Guard sie zu Recht als 5/6 meldete.
                // Jetzt gilt einheitlich: nur 6/6 ist vollstaendig.
                // NEU v1.5.8 (Punkt A): Die Meldung zaehlt nur noch die
                // sechs erwarteten Mirka-Werte; das fruehere count($w)
                // konnte auch fremde Property-IDs mitzaehlen.
                if (count($fehlende) > 0) {
                    $nichtLeer = 6 - count($fehlende);
                    $this->wichtig('[MIRKA-PROBLEM] Position ' . $hauptId
                        . ': nur ' . $nichtLeer . '/6 Mirka-Werte vorhanden, fehlt: '
                        . implode(',', $fehlende)
                        . ' - Name bleibt unveraendert.');
                    continue;
                }

                $qualitaetsName = isset(self::QUALITAETS_NAMEN[$code])
                    ? self::QUALITAETS_NAMEN[$code] . ' '
                    : '';

                // NEU v1.4.3: mehrzeiliger Aufbau (Wunsch Bernd) -
                // jede Angabe in einer eigenen Zeile.
                $neuerName = 'Mirka Schleifband ' . $qualitaetsName
                    . '(' . $code . ')' . "\n"
                    . 'Körnung: P' . $grit . ' · Verbindung: ' . $joint . "\n"
                    . 'Maß: ' . $breite . ' x ' . $laenge . ' mm'
                    . ($mirkaNr !== '' ? "\n" . 'Mirka-Nr.: ' . $mirkaNr : '');

                $neueNamen[$hauptId] = $neuerName;

                // Eigenschafts-Unterzeilen bekommen "Name: Wert".
                $zeilen = isset($zeilenJeHaupt[$hauptId]) ? $zeilenJeHaupt[$hauptId] : [];
                foreach ($zeilen as $eintrag) {
                    $label = $this->labelFuerEigenschaftsId(
                        (int) $eintrag['eigenschaftsId'],
                        $config
                    );
                    // NEU v1.4.4: Wert kommt aus der Werte-Sammlung
                    // (Zettel), NICHT mehr aus Feld 82 der Zeile.
                    $zeilenWert = $this->holeWert($w, (int) $eintrag['eigenschaftsId']);

                    // NEU v1.4.6 (Wunsch Bernd): Bei der Qualitaets-Zeile
                    // den ausgeschriebenen Namen mit ausgeben, z.B.
                    // "Schleifband Qualität: ABRANET MAX (AB0)" statt nur
                    // "AB0". Fuer den Kunden verstaendlicher; der
                    // gespeicherte Eigenschafts-WERT selbst (fuer Mirka/
                    // Fertigung) bleibt unveraendert "AB0".
                    if ((int) $eintrag['eigenschaftsId'] === (int) $config->getPropertyIdSchleifmittel()
                        && isset(self::QUALITAETS_NAMEN[$zeilenWert])) {
                        $zeilenWert = self::QUALITAETS_NAMEN[$zeilenWert]
                            . ' (' . $zeilenWert . ')';
                    }

                    if ($label !== '' && $zeilenWert !== '') {
                        $neueNamen[(int) $eintrag['positionsId']] =
                            $label . ': ' . $zeilenWert;
                    }
                }
            }

            // -----------------------------------------------------------
            // NEU v1.5.3: GUARD-MELDUNG (vor jedem return, damit auch
            // komplett leere Konfigurationen erfasst werden).
            // Laeuft nur, wenn der Fehlerschutz nicht auf "off" steht.
            // Meldet jede Konfigurator-Position, der eine der sechs
            // Eigenschaften fehlt. Modus "on" setzt zusaetzlich den
            // Sperr-Status (falls in Tab 8 hinterlegt) - sonst nur melden.
            $this->fuehreGuardAus($config, $auftragsId, $guardProbleme);

            // NEU v1.5.4: SAMMELZEILE pro Auftrag - alles direkt im
            // Nachrichtentext (Suchbegriff im Log: MIRKA-KURZ).
            $this->kurzMeldungAuftrag($auftragsId, $hauptPositionen, $guardProbleme);

            if (count($neueNamen) === 0) {
                return; // Nichts umzubenennen.
            }

            // -----------------------------------------------------------
            // Schritt 4: Protokollieren (in JEDEM Modus).
            // -----------------------------------------------------------
            foreach ($order->orderItems as $position) {
                $id = (int) $position->id;
                if (isset($neueNamen[$id])) {
                    $this->diag('[DIAG][Rename] Position ' . $id . ': "'
                        . (string) $position->orderItemName
                        . '" -> "' . $neueNamen[$id] . '"');
                }
            }

            if ($modus !== 'on') {
                // NEU v1.5.7: Text unterscheidet jetzt korrekt zwischen
                // "aus" (Tab 7 = off) und "nur protokollieren" (log).
                // Frueher stand bei BEIDEN "nur protokollieren" im Log -
                // das war bei abgeschalteter Umbenennung irrefuehrend.
                $modusText = ($modus === 'off')
                    ? 'AUS (Tab 7 abgeschaltet)'
                    : 'nur protokollieren';
                $this->diag('[DIAG][Rename] Umbenennen ' . $modusText . ': '
                    . 'Es wurde NICHTS am Auftrag geaendert.');
                return;
            }

            // -----------------------------------------------------------
            // Schritt 5: Schreiben (nur Modus "on").
            //   ALLE Positionen mit id + Name uebergeben, damit keine
            //   Position als fehlend gilt. Nur Namen, keine Betraege!
            //   NEU v1.4.1 (Review-Punkt 3): Payload-Log vorher,
            //   Nachkontrolle (Rechnungsbetrag + Positionszahl) danach.
            // -----------------------------------------------------------
            $payloadPositionen = [];
            foreach ($order->orderItems as $position) {
                $id = (int) $position->id;
                $payloadPositionen[] = [
                    'id'            => $id,
                    'orderItemName' => isset($neueNamen[$id])
                        ? $neueNamen[$id]
                        : (string) $position->orderItemName,
                ];
            }

            $this->diag('[DIAG][Rename] Payload: '
                . json_encode($payloadPositionen, JSON_UNESCAPED_UNICODE));

            // Kontrollwerte VOR dem Schreiben merken.
            $betragVorher     = $this->leseRechnungsbetrag($order);
            $positionenVorher = count($order->orderItems);

            /** @var OrderRepositoryContract $orderRepo */
            $orderRepo = pluginApp(OrderRepositoryContract::class);
            $orderRepo->updateOrder(
                ['orderItems' => $payloadPositionen],
                $auftragsId
            );

            // Nachkontrolle: Auftrag erneut laden und vergleichen.
            $kontrolle        = $orderRepo->findOrderById($auftragsId);
            $betragNachher    = $this->leseRechnungsbetrag($kontrolle);
            $positionenNachher = count($kontrolle->orderItems);

            if ($betragVorher === $betragNachher
                && $positionenVorher === $positionenNachher) {
                $this->diag('[DIAG][Rename] Auftrag ' . $auftragsId . ': '
                    . count($neueNamen) . ' Positionsname(n) GESCHRIEBEN. '
                    . 'Nachkontrolle OK (Betrag ' . $betragNachher
                    . ', Positionen ' . $positionenNachher . ').');
            } else {
                $this->wichtig('[MIRKA-PROBLEM] ⚠️ ABWEICHUNG nach dem Schreiben! '
                    . 'Betrag vorher=' . $betragVorher . ' nachher=' . $betragNachher
                    . ', Positionen vorher=' . $positionenVorher
                    . ' nachher=' . $positionenNachher
                    . '. BITTE Auftrag ' . $auftragsId . ' SOFORT pruefen und '
                    . 'Tab 7 auf "NUR PROTOKOLLIEREN" stellen!');
            }
        } catch (\Throwable $fehler) {
            // Bestellabschluss NIEMALS stoeren - nur loggen.
            $this->wichtig('[MIRKA-PROBLEM] Rename-FEHLER: ' . $fehler->getMessage());
        }
    }

    /**
     * Laedt den Auftrag frisch aus der Datenbank (Review-Punkt 1).
     * Reihenfolge: mit Relationen -> ohne Relationen -> Event-Objekt.
     * Die tatsaechlich genutzte Quelle wird geloggt, damit der
     * Protokollier-Testlauf zeigt, was im System funktioniert.
     *
     * @param int    $auftragsId
     * @param object $eventAuftrag  Auftrag aus dem Event (Rueckfall)
     * @return object
     */
    private function ladeAuftragVollstaendig($auftragsId, $eventAuftrag)
    {
        /** @var OrderRepositoryContract $orderRepo */
        $orderRepo = pluginApp(OrderRepositoryContract::class);

        // Versuch 1: mit den benoetigten Relationen laden.
        try {
            $order = $orderRepo->findOrderById($auftragsId, [
                'orderItems',
                'orderItems.properties',
                'orderItems.references',
                'orderItems.orderProperties',
            ]);
            if ($order !== null) {
                $this->diag('[DIAG][Rename] Auftrag frisch geladen (mit Relationen).');
                return $order;
            }
        } catch (\Throwable $egal) {
            $this->wichtig('[MIRKA-PROBLEM] Laden MIT Relationen fehlgeschlagen ('
                . $egal->getMessage() . ') - versuche ohne.');
        }

        // Versuch 2: ohne Relationsliste laden.
        try {
            $order = $orderRepo->findOrderById($auftragsId);
            if ($order !== null) {
                $this->diag('[DIAG][Rename] Auftrag frisch geladen (Standard).');
                return $order;
            }
        } catch (\Throwable $egal) {
            $this->wichtig('[MIRKA-PROBLEM] Laden OHNE Relationen fehlgeschlagen ('
                . $egal->getMessage() . ') - nutze Event-Objekt.');
        }

        // Rueckfall: das Objekt aus dem Event.
        $this->diag('[DIAG][Rename] Rueckfall auf das Event-Objekt.');
        return $eventAuftrag;
    }

    /**
     * Liest den Brutto-Rechnungsbetrag eines Auftrags als Text
     * (fuer den Vorher/Nachher-Vergleich). '' wenn nicht lesbar.
     *
     * @param object $order
     * @return string
     */
    private function leseRechnungsbetrag($order)
    {
        try {
            foreach ($order->amounts as $betrag) {
                return (string) $betrag->invoiceTotal;
            }
        } catch (\Throwable $egal) {
            // Betraege nicht lesbar - Vergleich entfaellt.
        }
        return '';
    }

    /**
     * NEU v1.5.4: Schreibt EINE Sammelzeile pro Auftrag, in der alles
     * Wichtige direkt im Nachrichtentext steht - also in der Log-Liste
     * (Spalte "Nachricht") sofort lesbar, ohne etwas aufklappen zu
     * muessen. Suchbegriff im Log: MIRKA-KURZ
     *
     * Beispiel bei sauberem Auftrag:
     *   [MIRKA-KURZ] AUFTRAG 329642 | Konfig-Positionen=2 | OK=2 | PROBLEM=0
     * Beispiel bei Fehler:
     *   [MIRKA-KURZ] AUFTRAG 329642 | Konfig-Positionen=2 | OK=1 | PROBLEM=1
     *   || Position 418561: 0/6 Werte, fehlt: Qualitaet,Koernung,...
     *
     * @param int   $auftragsId
     * @param array $hauptPositionen
     * @param array $guardProbleme
     */
    private function kurzMeldungAuftrag($auftragsId, $hauptPositionen, $guardProbleme)
    {
        try {
            $gesamt   = is_array($hauptPositionen) ? count($hauptPositionen) : 0;
            $probleme = is_array($guardProbleme) ? count($guardProbleme) : 0;
            $ok       = $gesamt - $probleme;
            if ($ok < 0) {
                $ok = 0;
            }

            $text = '[MIRKA-KURZ] AUFTRAG ' . (int) $auftragsId
                . ' | Konfig-Positionen=' . $gesamt
                . ' | OK=' . $ok
                . ' | PROBLEM=' . $probleme;

            if ($probleme > 0) {
                foreach ($guardProbleme as $p) {
                    $posId    = isset($p['positionsId']) ? (int) $p['positionsId'] : 0;
                    $gefunden = isset($p['gefunden']) ? (int) $p['gefunden'] : 0;
                    $fehlende = (isset($p['fehlende']) && is_array($p['fehlende']))
                        ? implode(',', $p['fehlende']) : '?';
                    $text .= ' || Position ' . $posId . ': ' . $gefunden
                        . '/6 Werte, fehlt: ' . $fehlende;
                }
            }

            // Klartext als Nachricht (nicht ueber den Uebersetzungs-
            // Schluessel), damit der Text direkt in der Log-Liste steht.
            $this->getLogger(self::LOG_KENNUNG)->error($text);
        } catch (\Throwable $egal) {
            // Sammelzeile ist reine Bequemlichkeit - Fehler ignorieren.
        }
    }

    /**
     * NEU v1.5.3: SERVERSEITIGER GUARD.
     * Meldet jede Konfigurator-Position, bei der nicht alle sechs
     * Eigenschaften vorliegen (Datenbasis: die im Umbenenner bereits
     * zusammengefuehrten Werte aus den Auftrags-Quellen A/B/C plus dem
     * Zettel-Rueckfall). Verhindert nichts
     * (OrderCreated laeuft nach dem Anlegen), sondern macht den Fehler
     * SICHTBAR, damit der Auftrag nicht unbemerkt weiterlaeuft.
     *
     * Modus kommt aus Tab 8 (getFailClosedMode, wie beim Preis-Guard):
     *   off = nichts tun
     *   log = nur laut melden (Standard, sicher)
     *   on  = zusaetzlich Sperr-Status setzen (falls in Tab 8 hinterlegt)
     *
     * @param PluginConfig $config
     * @param int          $auftragsId
     * @param array        $guardProbleme  Liste verdaechtiger Positionen
     */
    private function fuehreGuardAus($config, $auftragsId, $guardProbleme)
    {
        try {
            $modus = $config->getFailClosedMode();
            if ($modus === 'off') {
                return;
            }
            if (!is_array($guardProbleme) || count($guardProbleme) === 0) {
                return; // Alles vollstaendig - nichts zu melden.
            }

            $this->wichtig('[MIRKA-PROBLEM] GUARD Auftrag ' . $auftragsId . ': '
                . count($guardProbleme) . ' Konfigurator-Position(en) mit '
                . 'UNVOLLSTAENDIGEN Bestelleigenschaften - BITTE PRUEFEN. '
                . 'Details: ' . json_encode($guardProbleme));

            if ($modus !== 'on') {
                $this->diag('[DIAG][Guard] Modus "nur melden" - es '
                    . 'wurde NICHTS am Auftrag geaendert.');
                return;
            }

            // Modus 'on': Sperr-Status setzen, falls hinterlegt.
            $statusId = $config->getFailClosedStatusId();
            if ($statusId <= 0) {
                $this->wichtig('[MIRKA-PROBLEM] Fehlerschutz steht auf AN, aber KEINE '
                    . 'Sperr-Status-ID in Tab 8 - es wurde nur gemeldet.');
                return;
            }

            /** @var OrderRepositoryContract $orderRepo */
            $orderRepo = pluginApp(OrderRepositoryContract::class);
            $orderRepo->updateOrder(['statusId' => $statusId], $auftragsId);
            $this->wichtig('[MIRKA-PROBLEM] GUARD Auftrag ' . $auftragsId
                . ' auf Sperr-Status ' . $statusId . ' gesetzt.');
        } catch (\Throwable $fehler) {
            // Der Guard darf den Umbenenner/Auftrag NIEMALS stoeren.
            $this->wichtig('[MIRKA-PROBLEM] Guard-Fehler: '
                . $fehler->getMessage());
        }
    }




    /**
     * NEU v1.5.13: Prueft, ob fuer eine Position bereits ALLE SECHS
     * Mirka-Werte (Qualitaet, Koernung, Verbindung, Breite, Laenge,
     * Mirka-Nr.) nicht-leer vorliegen. Wird gebraucht, um zu entscheiden,
     * ob der Session-Zettel ueberhaupt noch befragt werden muss.
     *
     * @param PluginConfig $config
     * @param array        $w  eigenschaftsId => Wert (einer Position)
     * @return bool
     */
    /**
     * NEU v1.5.15: RUECKFALL ueber den Sitzungs-Zettel.
     *
     * Der BasketItemListener legt beim In-den-Warenkorb-Legen einen
     * "Zettel" in der Kunden-Sitzung ab (Schluessel 'mirkaKonfigListe'):
     * die sechs Werte + der Brutto-Verkaufspreis. Diese Methode ordnet
     * die Zettel den Auftragspositionen ueber den Preis zu.
     *
     * WICHTIG - was diese Methode NICHT ist:
     *   Der Preis ist KEIN Identitaetsbeweis. Die Zuordnung ist eine
     *   Notversorgung, kein Nachweis. Deshalb gelten die strengen
     *   Eindeutigkeitsregeln aus v1.5.12 unveraendert weiter:
     *     - Haben MEHRERE Auftragspositionen denselben Bruttopreis,
     *       bekommt KEINE davon einen Zettel.
     *     - Passen MEHRERE Zettel auf denselben Positionspreis,
     *       wird KEINER davon verwendet.
     *     - Ist der Positionspreis nicht lesbar, wird nur der voellig
     *       eindeutige Fall zugelassen (genau 1 Position, 1 Zettel).
     *   In jedem Zweifelsfall passiert NICHTS und der Guard meldet die
     *   unvollstaendige Position.
     *
     * NEU v1.5.15 (Luecke geschlossen):
     *   Die Schleife laeuft ueber ALLE Konfigurator-Positionen, auch ueber
     *   die, die bereits 6/6 direkte Werte haben. Diese Positionen
     *   VERBRAUCHEN ihren passenden Zettel, uebernehmen daraus aber
     *   nichts. Ohne das koennte der liegen gebliebene Zettel einer
     *   vollstaendigen Position A einer offenen Position B zugeordnet
     *   werden - genau die Luecke, wegen der in v1.5.13 vorschnell der
     *   ganze Rueckfall geloescht wurde.
     *
     * @param PluginConfig $config
     * @param array        $hauptPositionen  hauptId => Position
     * @param array        $werte            (per Referenz) hauptId => [propId => Wert]
     */
    private function uebernehmeZettelWerteRueckfall($config, $hauptPositionen, &$werte)
    {
        try {
            /** @var FrontendSessionStorageFactoryContract $sessionFactory */
            $sessionFactory = pluginApp(FrontendSessionStorageFactoryContract::class);
            $ablage = $sessionFactory->getPlugin();

            $roh   = (string) $ablage->getValue('mirkaKonfigListe');
            $liste = ($roh !== '') ? json_decode($roh, true) : [];

            // NEU v1.5.17: Beweis-Zeile zur Sitzung. Bei Auftrag 329694 war
            // der Zettel um 13:42:16 geschrieben und um 13:42:52 nicht mehr
            // lesbar - OHNE dass sich der Kunde ab-/angemeldet hatte. Diese
            // Zeile trennt die moeglichen Ursachen sauber:
            //   Ablage erreichbar + Laenge 0  -> Sitzung ist eine ANDERE
            //                                    (oder der Wert wurde geleert)
            //   Ablage NICHT erreichbar       -> beim Auftrag-Anlegen gibt es
            //                                    ueberhaupt keine Kundensitzung
            $this->wichtig('[MIRKA-SESSION] Beim Auftrag-Anlegen: Sitzungsablage'
                . ' erreichbar=ja | Zettel-Rohwert Laenge=' . strlen($roh)
                . ' | Eintraege=' . (is_array($liste) ? count($liste) : 0)
                . ' | Kontrollwert mirkaSitzungsMarke="'
                . (string) $ablage->getValue('mirkaSitzungsMarke') . '"');

            if (!is_array($liste) || count($liste) === 0) {
                $this->wichtig('[MIRKA-PROBLEM] Kein Zettel in der Sitzung - der '
                    . 'Rueckfall kann nichts liefern. Der Zettel wird beim '
                    . 'In-den-Warenkorb-Legen geschrieben; ist er beim '
                    . 'Auftrag-Anlegen weg, ist die Sitzung dort eine andere. '
                    . 'Siehe [MIRKA-SESSION]-Zeile davor.');
                return;
            }

            $hauptIds = array_keys($hauptPositionen);
            sort($hauptIds);

            $benutzteIndizes = [];

            // Vorab zaehlen, wie viele Hauptpositionen JEWEILS denselben
            // Bruttopreis haben (Eindeutigkeit der Positions-Seite).
            $preisAnzahlPositionen = [];
            foreach ($hauptIds as $zaehlId) {
                $zaehlPreis = $this->leseBruttoEinzelpreis($hauptPositionen[$zaehlId]);
                if ($zaehlPreis !== null) {
                    $schluessel = (string) round((float) $zaehlPreis, 2);
                    if (!isset($preisAnzahlPositionen[$schluessel])) {
                        $preisAnzahlPositionen[$schluessel] = 0;
                    }
                    $preisAnzahlPositionen[$schluessel]++;
                }
            }

            foreach ($hauptIds as $hauptId) {
                $position = $hauptPositionen[$hauptId];
                $posPreis = $this->leseBruttoEinzelpreis($position);

                // Wie viele Werte hat die Position ueber den direkten Weg
                // schon? 0 = leer, 6 = vollstaendig, 1-5 = Teilwerte.
                $wVorhanden = isset($werte[$hauptId]) ? $werte[$hauptId] : [];
                $anzahlDirekt      = $this->zaehleDirekteWerte($config, $wVorhanden);
                $schonVollstaendig = ($anzahlDirekt >= 6);
                // NEU v1.5.16 (BLOCKER-FIX): Bei 1-5 direkten Werten wird der
                // Zettel NICHT zum Auffuellen benutzt. Sonst koennte ein
                // fremder, preisgleicher Zettel die leeren Felder ergaenzen
                // und es entstuende eine MISCHKONFIGURATION aus zwei
                // verschiedenen Baendern, die anschliessend als saubere 6/6
                // durchgeht (Guard meldet "OK"). Lieber sichtbar
                // unvollstaendig als unsichtbar gemischt.
                // v1.5.22 (Regressions-Korrektur): Frueher stand hier
                // ($anzahlDirekt > 0). Damit blieb eine Position mit 1-5
                // direkten Werten OHNE Zettel-Ergaenzung - v1.5.12 haette
                // sie vervollstaendigt. Das war eine Verschlechterung
                // gegenueber dem alten Stand. Stumm bleiben die Meldungen
                // jetzt nur noch bei einer bereits vollstaendigen Position;
                // der Teilwert-Fall wird weiter unten sauber geloest, indem
                // die vorhandenen Werte mit dem Zettel VERGLICHEN werden.
                $nurVerbrauchen = $schonVollstaendig;

                $gewaehlterIndex = -1;

                if ($posPreis !== null) {
                    // Positions-Seite eindeutig?
                    $preisSchluessel = (string) round((float) $posPreis, 2);
                    $anzahlPositionenMitPreis = isset($preisAnzahlPositionen[$preisSchluessel])
                        ? $preisAnzahlPositionen[$preisSchluessel] : 0;
                    if ($anzahlPositionenMitPreis > 1) {
                        if (!$nurVerbrauchen) {
                            $this->wichtig('[MIRKA-PROBLEM] Zettel-Zuordnung mehrdeutig'
                                . ' | Hauptposition=' . (int) $hauptId
                                . ' | Positionspreis=' . $posPreis
                                . ' | HauptpositionenMitDiesemPreis=' . $anzahlPositionenMitPreis
                                . ' | Es wurden KEINE Werte uebernommen'
                                . ' (fail-safe: bei gleichem Preis ist nicht beweisbar,'
                                . ' welcher Zettel zu welcher Position gehoert).');
                        }
                        continue;
                    }

                    // Zettel-Seite eindeutig? ALLE passenden zaehlen,
                    // NICHT beim ersten Treffer abbrechen.
                    $treffer = [];
                    for ($i = count($liste) - 1; $i >= 0; $i--) {
                        if (isset($benutzteIndizes[$i])) {
                            continue;
                        }
                        $zettelPreis = isset($liste[$i]['preis'])
                            ? (float) $liste[$i]['preis'] : null;
                        if ($zettelPreis !== null) {
                            // Betrag OHNE abs() - in der Plenty-Sandbox verboten.
                            $differenz = $zettelPreis - $posPreis;
                            if ($differenz < 0) {
                                $differenz = -$differenz;
                            }
                            if ($differenz < 0.005) {
                                $treffer[] = $i;
                            }
                        }
                    }

                    if (count($treffer) === 1) {
                        $gewaehlterIndex = $treffer[0];
                    } elseif (count($treffer) > 1) {
                        if (!$nurVerbrauchen) {
                            $this->wichtig('[MIRKA-PROBLEM] Zettel-Zuordnung mehrdeutig'
                                . ' | Hauptposition=' . (int) $hauptId
                                . ' | Positionspreis=' . $posPreis
                                . ' | passende Session-Zettel=' . count($treffer)
                                . ' | Es wurden KEINE Werte uebernommen'
                                . ' (fail-safe, sonst koennten die Baender vertauscht werden).');
                        }
                        continue;
                    }

                    if ($gewaehlterIndex < 0) {
                        if (!$nurVerbrauchen) {
                            $this->wichtig('[MIRKA-PROBLEM] Kein Zettel passt zum '
                                . 'Positionspreis ' . $posPreis . ' (Haupt ' . (int) $hauptId
                                . ') - Position wird uebersprungen (fail-safe).');
                        }
                        continue;
                    }
                } else {
                    // Preis nicht lesbar: nur den voellig eindeutigen Fall.
                    if (count($hauptIds) === 1 && count($liste) === 1) {
                        $gewaehlterIndex = 0;
                        $this->diag('[DIAG][Rename] Positionspreis nicht lesbar - '
                            . 'eindeutiger Fall (1 Position, 1 Zettel), Zettel wird verwendet.');
                    } else {
                        if (!$nurVerbrauchen) {
                            $this->wichtig('[MIRKA-PROBLEM] Positionspreis nicht lesbar und '
                                . 'Lage mehrdeutig (' . count($hauptIds) . ' Position(en), '
                                . count($liste) . ' Zettel) - uebersprungen (fail-safe).');
                        }
                        continue;
                    }
                }

                // Zettel ist zugeordnet -> in JEDEM Fall als verbraucht
                // markieren, auch wenn die Position ihn nicht braucht.
                $benutzteIndizes[$gewaehlterIndex] = true;

                if ($schonVollstaendig) {
                    $this->diag('[DIAG][Rename] Haupt ' . (int) $hauptId
                        . ' hat bereits 6/6 direkte Werte - Zettel wird nur '
                        . 'verbraucht, NICHT uebernommen.');
                    continue;
                }

                $eintrag  = $liste[$gewaehlterIndex];
                $werteMap = (isset($eintrag['werte']) && is_array($eintrag['werte']))
                    ? $eintrag['werte']
                    : [];
                // v1.5.22 - SCHUTZ GEGEN MISCHKONFIGURATION, ohne den
                // Normalfall zu blockieren:
                // Hat die Position schon einzelne direkte Werte (1-5), muss
                // der Zettel bei JEDEM davon denselben Wert tragen. Stimmt
                // auch nur einer nicht ueberein, gehoert der Zettel
                // offensichtlich zu einem ANDEREN Band - dann wird nichts
                // ergaenzt und laut gemeldet. Stimmen alle ueberein, ist die
                // Zugehoerigkeit belegt und die Luecken werden gefuellt.
                if ($anzahlDirekt > 0) {
                    $konflikte = [];
                    foreach ($werteMap as $vPropertyId => $vWert) {
                        $vPid = (int) $vPropertyId;
                        if ($vPid <= 0) {
                            continue;
                        }
                        $direkt = isset($werte[$hauptId][$vPid])
                            ? trim((string) $werte[$hauptId][$vPid]) : '';
                        $ausZettel = trim((string) $vWert);
                        if ($direkt !== '' && $ausZettel !== '' && $direkt !== $ausZettel) {
                            $konflikte[] = 'Eigenschaft ' . $vPid . ': direkt "'
                                . $direkt . '" vs. Zettel "' . $ausZettel . '"';
                        }
                    }
                    if (count($konflikte) > 0) {
                        $this->wichtig('[MIRKA-PROBLEM] Der Sitzungs-Zettel widerspricht '
                            . 'den direkten Werten der Position ' . (int) $hauptId
                            . ' - er gehoert offensichtlich zu einem ANDEREN Band und '
                            . 'wird NICHT verwendet (sonst entstuende eine '
                            . 'Mischkonfiguration). Abweichungen: '
                            . implode(' | ', $konflikte));
                        continue;
                    }
                    $this->diag('[DIAG][Rename] Haupt ' . (int) $hauptId . ': '
                        . $anzahlDirekt . '/6 direkte Werte stimmen mit dem Zettel '
                        . 'ueberein - die fehlenden werden ergaenzt.');
                }

                $ergaenzt = 0;
                foreach ($werteMap as $propertyId => $wert) {
                    $pid  = (int) $propertyId;
                    $wneu = trim((string) $wert);
                    // Direkte Werte sind fuehrend: nur LEERE Felder fuellen.
                    $nochLeer = !isset($werte[$hauptId][$pid])
                        || trim((string) $werte[$hauptId][$pid]) === '';
                    if ($pid > 0 && $wneu !== '' && $nochLeer) {
                        $werte[$hauptId][$pid] = $wneu;
                        $ergaenzt++;
                    }
                }
                $this->diag('[DIAG][Rename] Werte ergaenzt (Z(Sitzungs-Zettel, Rueckfall)): '
                    . $ergaenzt . ' Wert(e) fuer Haupt ' . (int) $hauptId
                    . ' (Zettel-Preis: '
                    . (isset($eintrag['preis']) ? $eintrag['preis'] : '?')
                    . ', Positionspreis: ' . ($posPreis !== null ? $posPreis : 'nicht lesbar')
                    . ')');
            }

            // Nur die tatsaechlich verbrauchten Zettel entfernen.
            if (count($benutzteIndizes) > 0) {
                $rest = [];
                foreach ($liste as $i => $eintrag) {
                    if (!isset($benutzteIndizes[$i])) {
                        $rest[] = $eintrag;
                    }
                }
                $ablage->setValue('mirkaKonfigListe', count($rest) > 0 ? json_encode($rest) : '');
            }
        } catch (\Throwable $fehler) {
            // Der Rueckfall darf den Auftrag NIEMALS stoeren.
            $this->wichtig('[MIRKA-PROBLEM] Zettel-Rueckfall fehlgeschlagen: '
                . $fehler->getMessage());
        }
    }

    /**
     * Liest den Brutto-Einzelpreis einer Auftragsposition.
     * Versucht nacheinander die beiden ueblichen Feldnamen der
     * Betrags-Zeilen (fest benannte Zugriffe, sandbox-konform).
     * Liefert null, wenn nichts lesbar ist - dann greift die
     * Eindeutigkeits-Regel in uebernehmeZettelWerteRueckfall().
     *
     * @param mixed $position Auftragsposition
     * @return float|null
     */
    private function leseBruttoEinzelpreis($position)
    {
        // Versuch 1: amounts[0]->priceOriginalGross
        try {
            foreach ($position->amounts as $betrag) {
                $wert = (float) $betrag->priceOriginalGross;
                if ($wert > 0) {
                    return $wert;
                }
                break;
            }
        } catch (\Throwable $egal) {
            // weiter mit Versuch 2
        }
        // Versuch 2: amounts[0]->priceGross
        try {
            foreach ($position->amounts as $betrag) {
                $wert = (float) $betrag->priceGross;
                if ($wert > 0) {
                    return $wert;
                }
                break;
            }
        } catch (\Throwable $egal) {
            // nicht lesbar
        }
        return null;
    }

    /**
     * NEU v1.5.16: Zaehlt, wie viele der sechs Mirka-Eigenschaften fuer
     * eine Position bereits einen nicht-leeren Wert haben.
     * Wird gebraucht, um die MISCHUNG von Teilwerten mit einem fremden
     * Sitzungs-Zettel zu verhindern (siehe uebernehmeZettelWerteRueckfall).
     *
     * @param PluginConfig $config
     * @param array        $w  eigenschaftsId => Wert (einer Position)
     * @return int  0 bis 6
     */
    private function zaehleDirekteWerte($config, $w)
    {
        if (!is_array($w)) {
            return 0;
        }
        $ids = [
            $config->getPropertyIdSchleifmittel(),
            $config->getPropertyIdKoernung(),
            $config->getPropertyIdVerbindung(),
            $config->getPropertyIdBreite(),
            $config->getPropertyIdLaenge(),
            $config->getPropertyIdMirkaCode(),
        ];
        $n = 0;
        foreach ($ids as $id) {
            $id = (int) $id;
            if ($id > 0 && isset($w[$id]) && trim((string) $w[$id]) !== '') {
                $n++;
            }
        }
        return $n;
    }

    private function hatAlleSechsWerte($config, $w)
    {
        if (!is_array($w)) {
            return false;
        }
        $ids = [
            $config->getPropertyIdSchleifmittel(),
            $config->getPropertyIdKoernung(),
            $config->getPropertyIdVerbindung(),
            $config->getPropertyIdBreite(),
            $config->getPropertyIdLaenge(),
            $config->getPropertyIdMirkaCode(),
        ];
        foreach ($ids as $id) {
            $id = (int) $id;
            if ($id <= 0) {
                return false;
            }
            if (!isset($w[$id]) || trim((string) $w[$id]) === '') {
                return false;
            }
        }
        return true;
    }

    /**
     * Liest einen Wert aus dem Werte-Array (leerer Text, wenn nicht da).
     *
     * @param array $werte           eigenschaftsId => Wert
     * @param int   $eigenschaftsId  gesuchte Eigenschafts-ID
     * @return string
     */
    private function holeWert($werte, $eigenschaftsId)
    {
        $eigenschaftsId = (int) $eigenschaftsId;
        if (isset($werte[$eigenschaftsId])) {
            return trim((string) $werte[$eigenschaftsId]);
        }
        return '';
    }

    /**
     * Kundensichtbare Beschriftung fuer eine Eigenschafts-ID
     * (fuer die Umbenennung der Unterzeilen in "Name: Wert").
     * Unbekannte IDs liefern '' -> Zeile bleibt unveraendert.
     *
     * @param int          $eigenschaftsId
     * @param PluginConfig $config
     * @return string
     */
    private function labelFuerEigenschaftsId($eigenschaftsId, PluginConfig $config)
    {
        if ($eigenschaftsId === $config->getPropertyIdSchleifmittel()) {
            return 'Schleifband Qualität';
        }
        if ($eigenschaftsId === $config->getPropertyIdKoernung()) {
            return 'Schleifband Körnung';
        }
        if ($eigenschaftsId === $config->getPropertyIdVerbindung()) {
            return 'Schleifband Verbindung';
        }
        if ($eigenschaftsId === $config->getPropertyIdBreite()) {
            return 'Schleifband Breite in mm';
        }
        if ($eigenschaftsId === $config->getPropertyIdLaenge()) {
            return 'Schleifband Länge in mm';
        }
        if ($eigenschaftsId === $config->getPropertyIdMirkaCode()) {
            return 'Mirka Artikelnummer';
        }
        return '';
    }

    /**
     * Diagnose-Log (Testphase): error() als garantiert sichtbarer Kanal,
     * wie im BasketItemListener etabliert.
     *
     * @param string $text
     */
    /**
     * NEU v1.5.8: ROUTINE-Diagnose.
     * Schreibt den Text als KLARTEXT ins Log (nicht mehr ueber den
     * Uebersetzungs-Schluessel) - dadurch steht die Meldung direkt in
     * der Log-Liste, Spalte "Nachricht". Kein Aufklappen mehr noetig.
     *
     * Diese Routine-Zeilen erscheinen NUR, wenn in Tab 6 der
     * Debug-Modus auf AN steht. Im Normalbetrieb bleibt das Log
     * dadurch schlank; fuer eine Fehlersuche schaltet man Debug an.
     * Problemmeldungen laufen ueber wichtig() und erscheinen IMMER.
     *
     * @param string $text
     */
    private function diag($text)
    {
        // NEU v1.5.8: Debug-Zustand nur EINMAL ermitteln und merken -
        // diag() wird pro Auftrag rund 30x aufgerufen, und jedes Mal die
        // Konfiguration neu zu laden waere unnoetiger Aufwand.
        if ($this->debugGeprueft === false) {
            $this->debugGeprueft = true;
            try {
                /** @var PluginConfig $cfg */
                $cfg = pluginApp(PluginConfig::class);
                $this->debugAn = $cfg->isDebugMode();
            } catch (\Throwable $egal) {
                $this->debugAn = true; // Im Zweifel lieber loggen.
            }
        }
        if (!$this->debugAn) {
            return; // Routine-Rauschen im Normalbetrieb unterdruecken.
        }
        $this->getLogger(self::LOG_KENNUNG)->error($text);
    }

    /**
     * NEU v1.5.8: WICHTIGE Meldung - erscheint IMMER, unabhaengig vom
     * Debug-Modus. Fuer Probleme, Guard-Alarme und Zusammenfassungen.
     * Ebenfalls Klartext, also direkt in der Log-Liste lesbar.
     *
     * @param string $text
     */
    private function wichtig($text)
    {
        $this->getLogger(self::LOG_KENNUNG)->error($text);
    }
}
