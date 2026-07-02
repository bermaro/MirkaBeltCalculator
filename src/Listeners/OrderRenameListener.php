
<?php

namespace MirkaBeltCalculator\Listeners;

use Plenty\Modules\Order\Events\OrderCreated;
use Plenty\Modules\Order\Contracts\OrderRepositoryContract;
use Plenty\Plugin\Log\Loggable;
use MirkaBeltCalculator\Configs\PluginConfig;

/**
 * OrderRenameListener (v1.4.1)
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

                // Quelle A: Unterfelder der Typ-15-Zeile (typeId 81/82).
                $eigenschaftsId = 0;
                $wert           = '';
                try {
                    foreach ($zeile->properties as $eigenschaft) {
                        $propTyp = (int) $eigenschaft->typeId;
                        if ($propTyp === self::PROP_TYP_EIGENSCHAFTS_ID) {
                            $eigenschaftsId = (int) $eigenschaft->value;
                        } elseif ($propTyp === self::PROP_TYP_WERT) {
                            $wert = trim((string) $eigenschaft->value);
                        }
                    }
                } catch (\Throwable $egal) {
                    // properties nicht lesbar -> Quelle B versuchen.
                }
                $quelle = 'A(properties 81/82)';

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
                    $this->diag('[DIAG][Rename] Wert gefunden (' . $quelle . '): '
                        . 'Eigenschaft ' . $eigenschaftsId . ' = "' . $wert
                        . '" (Zeile ' . (int) $zeile->id . ' -> Haupt ' . $hauptId . ')');
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

                $neuerName = 'Mirka Schleifband ' . $qualitaetsName
                    . '(' . $code . '), P' . $grit
                    . ', Verbindung ' . $joint
                    . ', ' . $breite . ' x ' . $laenge . ' mm'
                    . ($mirkaNr !== '' ? ', Mirka-Nr. ' . $mirkaNr : '');

                $neueNamen[$hauptId] = $neuerName;

                // Eigenschafts-Unterzeilen bekommen "Name: Wert".
                $zeilen = isset($zeilenJeHaupt[$hauptId]) ? $zeilenJeHaupt[$hauptId] : [];
                foreach ($zeilen as $eintrag) {
                    $label = $this->labelFuerEigenschaftsId(
                        (int) $eintrag['eigenschaftsId'],
                        $config
                    );
                    if ($label !== '' && $eintrag['wert'] !== '') {
                        $neueNamen[(int) $eintrag['positionsId']] =
                            $label . ': ' . $eintrag['wert'];
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
