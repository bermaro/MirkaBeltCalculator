<?php

namespace MirkaBeltCalculator\Listeners;

use Plenty\Modules\Order\Events\OrderCreated;
use Plenty\Modules\Order\Contracts\OrderRepositoryContract;
use Plenty\Modules\Frontend\Session\Storage\Contracts\FrontendSessionStorageFactoryContract;
use Plenty\Plugin\Log\Loggable;
use MirkaBeltCalculator\Configs\PluginConfig;

/**
 * OrderRenameListener (v1.4.5)
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

            $modus = $config->getRenamePositionsMode();
            if ($modus === 'off') {
                return; // Funktion abgeschaltet - nichts tun.
            }

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

                // Quelle B: orderProperties der Typ-15-Zeile.
                if ($eigenschaftsId === 0) {
                    try {
                        foreach ($zeile->orderProperties as $op) {
                            $opId = (int) $op->propertyId;
                            if ($opId > 0) {
                                $eigenschaftsId = $opId;
                                $wert           = trim((string) $op->value);
                                $quelle         = 'B(orderProperties Zeile)';
                                break;
                            }
                        }
                    } catch (\Throwable $egal) {
                        // Auch nicht lesbar -> Zeile liefert nichts.
                    }
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
                        . ($wert !== '' ? ' = "' . $wert . '"' : ' (Wert folgt vom Zettel)')
                        . ' (Zeile ' . (int) $zeile->id . ' -> Haupt ' . $hauptId . ')');
                }
            }

            // Quelle C: orderProperties direkt an der Hauptposition
            // (ergaenzt nur, was noch fehlt - ueberschreibt nichts).
            foreach ($hauptPositionen as $hauptId => $position) {
                try {
                    foreach ($position->orderProperties as $op) {
                        $opId = (int) $op->propertyId;
                        if ($opId > 0 && !isset($werte[$hauptId][$opId])) {
                            $werte[$hauptId][$opId] = trim((string) $op->value);
                            $this->diag('[DIAG][Rename] Wert gefunden (C(orderProperties '
                                . 'Hauptposition)): Eigenschaft ' . $opId . ' = "'
                                . trim((string) $op->value) . '" (Haupt ' . $hauptId . ')');
                        }
                    }
                } catch (\Throwable $egal) {
                    // Relation existiert hier nicht - kein Problem.
                }
            }

            // -----------------------------------------------------------
            // NEU v1.4.4 - QUELLE Z ("Zettel aus der Kunden-Sitzung"):
            // Der BasketItemListener hat die Kundenwerte beim In-den-
            // Warenkorb-Legen dort abgelegt. Diese Quelle ist FUEHREND
            // und ueberschreibt alles Bisherige (am Auftrag selbst
            // speichert Plenty die Werte nachweislich nicht).
            // -----------------------------------------------------------
            $this->uebernehmeZettelWerte($hauptPositionen, $werte);

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

                // Ohne die fuenf Pflichtwerte wird NICHT umbenannt
                // (kein Raten, lieber alter Name als falscher Name).
                if ($code === '' || $grit === '' || $joint === ''
                    || $breite === '' || $laenge === '') {
                    $this->diag('[DIAG][Rename] Position ' . $hauptId
                        . ': Werte unvollstaendig (gefunden: '
                        . count($w) . ') - Name bleibt unveraendert.');
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
                    if ($label !== '' && $zeilenWert !== '') {
                        $neueNamen[(int) $eintrag['positionsId']] =
                            $label . ': ' . $zeilenWert;
                    }
                }
            }

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
                $this->diag('[DIAG][Rename] Modus "nur protokollieren": '
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
                $this->diag('[DIAG][Rename] ⚠️ ABWEICHUNG nach dem Schreiben! '
                    . 'Betrag vorher=' . $betragVorher . ' nachher=' . $betragNachher
                    . ', Positionen vorher=' . $positionenVorher
                    . ' nachher=' . $positionenNachher
                    . '. BITTE Auftrag ' . $auftragsId . ' SOFORT pruefen und '
                    . 'Tab 7 auf "NUR PROTOKOLLIEREN" stellen!');
            }
        } catch (\Throwable $fehler) {
            // Bestellabschluss NIEMALS stoeren - nur loggen.
            $this->diag('[DIAG][Rename] FEHLER: ' . $fehler->getMessage());
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
            $this->diag('[DIAG][Rename] Laden MIT Relationen fehlgeschlagen ('
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
            $this->diag('[DIAG][Rename] Laden OHNE Relationen fehlgeschlagen ('
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
     * v1.4.4/v1.4.5: Liest den "Zettel" des BasketItemListeners aus der
     * Kunden-Sitzung und traegt die Kundenwerte in die Werte-Sammlung
     * ein (fuehrende Quelle Z).
     *
     * NEU v1.4.5 - ZUORDNUNG MIT PREIS-GEGENCHECK:
     * Jeder Zettel traegt den Brutto-Verkaufspreis seiner Konfiguration.
     * Ein Zettel wird einer Auftragsposition nur zugeordnet, wenn sein
     * Preis zum Brutto-Einzelpreis der Position passt (Toleranz 0,005
     * EUR), gesucht wird vom NEUESTEN Zettel rueckwaerts. So bekommt
     * z. B. nach "A rein, B rein, B geloescht, A bestellt" die Position
     * A trotzdem den richtigen Zettel A (der Zettel von B passt nicht
     * zum Preis von A - ausser beide kosten exakt gleich viel, dann
     * waere auch die Konfiguration praktisch identisch teuer; dieses
     * Restrisiko ist dokumentiert und akzeptiert).
     * Ist der Positionspreis nicht lesbar, wird NUR der voellig
     * eindeutige Fall zugelassen: genau 1 Position UND genau 1 Zettel.
     * Fail-safe in jedem Zweifelsfall: Name bleibt unveraendert.
     *
     * @param array $hauptPositionen  hauptId => Position
     * @param array $werte            (per Referenz) hauptId => [propId => Wert]
     */
    private function uebernehmeZettelWerte($hauptPositionen, &$werte)
    {
        try {
            /** @var FrontendSessionStorageFactoryContract $sessionFactory */
            $sessionFactory = pluginApp(FrontendSessionStorageFactoryContract::class);
            $ablage = $sessionFactory->getPlugin();

            $roh   = (string) $ablage->getValue('mirkaKonfigListe');
            $liste = ($roh !== '') ? json_decode($roh, true) : [];
            if (!is_array($liste) || count($liste) === 0) {
                $this->diag('[DIAG][Rename] Kein Zettel in der Sitzung gefunden - '
                    . 'Namen bleiben ggf. unveraendert (fail-safe).');
                return;
            }

            $hauptIds = array_keys($hauptPositionen);
            sort($hauptIds);

            $benutzteIndizes = [];

            foreach ($hauptIds as $hauptId) {
                $position = $hauptPositionen[$hauptId];
                $posPreis = $this->leseBruttoEinzelpreis($position);

                $gewaehlterIndex = -1;

                if ($posPreis !== null) {
                    // Preis lesbar: vom NEUESTEN Zettel rueckwaerts den
                    // ersten unbenutzten mit passendem Preis suchen.
                    for ($i = count($liste) - 1; $i >= 0; $i--) {
                        if (isset($benutzteIndizes[$i])) {
                            continue;
                        }
                        $zettelPreis = isset($liste[$i]['preis'])
                            ? (float) $liste[$i]['preis'] : null;
                        if ($zettelPreis !== null
                            && abs($zettelPreis - $posPreis) < 0.005) {
                            $gewaehlterIndex = $i;
                            break;
                        }
                    }
                    if ($gewaehlterIndex < 0) {
                        $this->diag('[DIAG][Rename] ⚠️ Kein Zettel passt zum '
                            . 'Positionspreis ' . $posPreis . ' (Haupt ' . (int) $hauptId
                            . ') - Position wird uebersprungen (fail-safe).');
                        continue;
                    }
                } else {
                    // Preis NICHT lesbar: nur den voellig eindeutigen
                    // Fall zulassen (1 Position, 1 Zettel).
                    if (count($hauptIds) === 1 && count($liste) === 1) {
                        $gewaehlterIndex = 0;
                        $this->diag('[DIAG][Rename] Positionspreis nicht lesbar - '
                            . 'eindeutiger Fall (1 Position, 1 Zettel), Zettel wird verwendet.');
                    } else {
                        $this->diag('[DIAG][Rename] ⚠️ Positionspreis nicht lesbar und '
                            . 'Lage mehrdeutig (' . count($hauptIds) . ' Position(en), '
                            . count($liste) . ' Zettel) - uebersprungen (fail-safe).');
                        continue;
                    }
                }

                $benutzteIndizes[$gewaehlterIndex] = true;
                $eintrag  = $liste[$gewaehlterIndex];
                $werteMap = (isset($eintrag['werte']) && is_array($eintrag['werte']))
                    ? $eintrag['werte']
                    : [];
                foreach ($werteMap as $propertyId => $wert) {
                    $pid = (int) $propertyId;
                    if ($pid > 0) {
                        $werte[$hauptId][$pid] = trim((string) $wert);
                    }
                }
                $this->diag('[DIAG][Rename] Werte uebernommen (Z(Sitzungs-Zettel)): '
                    . count($werteMap) . ' Wert(e) fuer Haupt ' . (int) $hauptId
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
            $this->diag('[DIAG][Rename] Zettel-Lesen fehlgeschlagen: '
                . $fehler->getMessage());
        }
    }

    /**
     * NEU v1.4.5: Liest den Brutto-Einzelpreis einer Auftragsposition.
     * Versucht nacheinander die beiden ueblichen Feldnamen der
     * Betrags-Zeilen (fest benannte Zugriffe, sandbox-konform).
     * Liefert null, wenn nichts lesbar ist - dann greift die
     * Eindeutigkeits-Regel in uebernehmeZettelWerte().
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
    private function diag($text)
    {
        $this->getLogger(__METHOD__)->error(
            'MirkaBeltCalculator::Debug.properties',
            $text
        );
    }
}
