<?php

namespace MirkaBeltCalculator\Listeners;

use Plenty\Modules\Order\Events\OrderCreated;
use Plenty\Modules\Order\Contracts\OrderRepositoryContract;
use Plenty\Plugin\Log\Loggable;
use MirkaBeltCalculator\Configs\PluginConfig;

/**
 * OrderPriceGuardListener (NEU v1.4.7) - "FAIL-CLOSED / FEHLERSCHUTZ"
 *
 * WARUM GIBT ES DIESEN LISTENER?
 *   Auftrag 328730 (07.08.2026) ist als bezahlte Bestellung durchgelaufen,
 *   OBWOHL das Plugin ihn nie bepreist hat: Beim In-den-Warenkorb-Legen ist
 *   das Ereignis AfterBasketItemAdd nachweislich NICHT gefeuert (Beleg: im
 *   Plugin-Log fehlt zum Bestellzeitpunkt jede "AfterBasketItemAdd
 *   ausgeloest"-Zeile, obwohl sie sonst bei jedem Zulegen kommt). Folge:
 *   kein givenPrice, kein "Zettel" -> das Band blieb beim Standard-
 *   Verkaufspreis der leeren Variante und die Konfiguration fehlte komplett.
 *
 *   Der BasketItemListener ist damit KEIN verlaesslicher Kontrollpunkt: laeuft
 *   er nicht, greift auch keine Absicherung in ihm. Der EINZIGE Punkt, der bei
 *   328730 zuverlaessig gelaufen ist, ist OrderCreated (der OrderRenameListener
 *   hat dort gefeuert). Genau hier setzt dieser Fehlerschutz an.
 *
 * WAS TUT DIESER LISTENER?
 *   Nach dem Anlegen eines Auftrags prueft er JEDE Konfigurator-Hauptposition
 *   (Variation aus der Plugin-Einstellung, Standard 22994, typeId 1) auf ein
 *   verlaessliches, sitzungs-UNABHAENGIGES Signal: den PREIS.
 *
 *   Ein Band gilt als Fehlbestellung, wenn
 *     (1) sein Brutto-Einzelpreis dem in Tab 8 hinterlegten BASISPREIS der
 *         leeren Konfigurator-Variante entspricht (dann hat das Plugin NICHT
 *         bepreist - genau der 328730-Fall), ODER
 *     (2) der Preis <= 0 ist, ODER
 *     (3) der Preis gar nicht lesbar ist (im Zweifel melden = fail-closed).
 *
 *   Dieses Preis-Signal ist bewusst NICHT vom "Zettel" in der Kunden-Sitzung
 *   abhaengig (den liest/verbraucht der OrderRenameListener). Dadurch ist der
 *   Fehlerschutz voellig unabhaengig vom Umbenenner und kann dessen bewaehrte
 *   Logik nicht stoeren - er ist ein reiner ZUSATZ.
 *
 * WAS PASSIERT BEI EINER FEHLBESTELLUNG? (Tab 8 "Fehlerschutz-Modus")
 *   off = Fehlerschutz komplett aus.
 *   log = NUR MELDEN: lauter Log-Alarm, KEIN Schreibvorgang (Standard, sicher).
 *   on  = ZUSAETZLICH SPERREN: der Auftrag wird auf die in Tab 8 hinterlegte
 *         Status-ID gesetzt (per OrderRepositoryContract::updateOrder -
 *         genau das Muster aus der offiziellen Plenty-Doku "Event Procedures":
 *         updateOrder(['statusId' => 3], $order->id)). Ist keine Status-ID
 *         hinterlegt, wird NUR gemeldet (nie geraten).
 *
 * SICHERHEIT:
 *   - Aendert NIE einen Preis und NIE eine Menge - setzt hoechstens den Status.
 *   - Schreibt nur im Modus "on" UND nur mit hinterlegter Status-ID.
 *   - Alles ist in try/catch gekapselt: Der Fehlerschutz darf den
 *     Bestellabschluss des Kunden NIEMALS stoeren (schlimmstenfalls passiert
 *     nichts ausser einem Log-Eintrag).
 *   - Sandbox-konform: kein abs(), keine dynamischen Property-Zugriffe;
 *     Betragsvergleiche rechnen das Vorzeichen von Hand (wie im Umbenenner).
 *
 * HINWEIS zu error():
 *   Wie im BasketItemListener/OrderRenameListener ist error() der garantiert
 *   sichtbare Log-Kanal (info()/debug() schreiben in Plenty nur ueber
 *   Uebersetzungs-Schluessel). Es sind KEINE echten PHP-Fehler.
 */
class OrderPriceGuardListener
{
    use Loggable;

    /** Positionstyp: normale Variantenposition (der Sammelartikel). */
    const TYP_VARIANTENPOSITION = 1;

    /** Toleranz beim Preisvergleich in EUR (gegen Rundungs-Cent). */
    const PREIS_TOLERANZ = 0.01;

    /**
     * Wird vom Event-Dispatcher aufgerufen, NACHDEM ein Auftrag angelegt wurde.
     */
    public function handle(OrderCreated $event)
    {
        try {
            /** @var PluginConfig $config */
            $config = pluginApp(PluginConfig::class);

            $modus = $config->getFailClosedMode();
            if ($modus === 'off') {
                return; // Fehlerschutz abgeschaltet.
            }

            $eventAuftrag = $event->getOrder();
            if ($eventAuftrag === null) {
                $this->guardLog('MirkaBeltCalculator [DIAG][Guard]: Event ohne Auftrag - Abbruch.');
                return;
            }

            // Nur normale Verkaufsauftraege (typeId 1). Nachbestellungen an
            // Mirka (typeId 12) o.ae. gehen den Fehlerschutz nichts an.
            if ((int) $eventAuftrag->typeId !== 1) {
                return;
            }

            $auftragsId = (int) $eventAuftrag->id;

            // Auftrag frisch laden (wie im Umbenenner, gleiche bewaehrte
            // Relationsliste - damit sind orderItems + Betraege sicher da).
            $order = $this->ladeAuftrag($auftragsId, $eventAuftrag);

            $basisPreisBrutto = $config->getFailClosedBasePriceGross();

            // -------------------------------------------------------------
            //  Konfigurator-Positionen pruefen.
            // -------------------------------------------------------------
            $verdaechtige   = [];
            $konfigGefunden = 0;

            foreach ($order->orderItems as $position) {
                if ((int) $position->typeId !== self::TYP_VARIANTENPOSITION) {
                    continue;
                }
                if (!$config->isHandledVariation((int) $position->itemVariationId)) {
                    continue;
                }
                $konfigGefunden++;

                $preis = $this->leseBruttoEinzelpreis($position);

                // Regel 1: Preis nicht lesbar -> im Zweifel melden (fail-closed).
                if ($preis === null) {
                    $verdaechtige[] = [
                        'positionsId' => (int) $position->id,
                        'preis'       => 'nicht lesbar',
                        'grund'       => 'Positionspreis nicht lesbar',
                    ];
                    continue;
                }

                // Regel 2: Preis <= 0 -> immer verdaechtig.
                if ($preis <= 0) {
                    $verdaechtige[] = [
                        'positionsId' => (int) $position->id,
                        'preis'       => $preis,
                        'grund'       => 'Preis kleiner/gleich 0',
                    ];
                    continue;
                }

                // Regel 3 (Hauptfall 328730): Preis == Basispreis der leeren
                // Variante -> das Plugin hat NICHT bepreist. Nur pruefbar,
                // wenn der Basispreis in Tab 8 hinterlegt ist.
                if ($basisPreisBrutto > 0) {
                    $differenz = $preis - $basisPreisBrutto;
                    if ($differenz < 0) {
                        $differenz = -$differenz; // Betrag ohne abs() (Sandbox).
                    }
                    if ($differenz < self::PREIS_TOLERANZ) {
                        $verdaechtige[] = [
                            'positionsId' => (int) $position->id,
                            'preis'       => $preis,
                            'grund'       => 'Preis entspricht dem Basispreis '
                                . $basisPreisBrutto . ' - Konfigurator hat NICHT bepreist',
                        ];
                    }
                }
            }

            if ($konfigGefunden === 0) {
                return; // Kein Konfigurator-Artikel im Auftrag - fertig.
            }

            // Hinweis, falls der Basispreis fehlt: dann kann Regel 3 (der
            // wichtigste Fall) nicht greifen. Einmal deutlich ins Log.
            if ($basisPreisBrutto <= 0) {
                $this->guardLog(
                    'MirkaBeltCalculator [DIAG][Guard]: KEIN Basispreis in Tab 8 hinterlegt - '
                    . 'die Preis-Pruefung ist inaktiv. Bitte den Brutto-Basispreis der leeren '
                    . 'Konfigurator-Variante eintragen, damit Fehlbestellungen wie 328730 '
                    . 'erkannt werden.',
                    ['auftrag' => $auftragsId]
                );
            }

            if (count($verdaechtige) === 0) {
                return; // Alles in Ordnung - Preise plausibel.
            }

            // -------------------------------------------------------------
            //  FEHLERSCHUTZ GREIFT: immer laut und deutlich melden.
            // -------------------------------------------------------------
            $this->guardLog(
                'MirkaBeltCalculator [FEHLERSCHUTZ] Auftrag ' . $auftragsId . ': '
                . count($verdaechtige) . ' Konfigurator-Position(en) OHNE gueltig berechneten '
                . 'Preis. Preis/Fertigung NICHT gesichert - BITTE PRUEFEN.',
                ['auftrag' => $auftragsId, 'verdaechtige' => $verdaechtige, 'modus' => $modus]
            );

            if ($modus !== 'on') {
                $this->guardLog(
                    'MirkaBeltCalculator [FEHLERSCHUTZ] Modus "nur melden" - es wurde NICHTS am '
                    . 'Auftrag geaendert. Zum aktiven Sperren Tab 8 auf "AN" stellen und eine '
                    . 'Sperr-Status-ID eintragen.',
                    ['auftrag' => $auftragsId]
                );
                return;
            }

            // Modus 'on': Auftrag auf den konfigurierten Sperr-Status setzen.
            $statusId = $config->getFailClosedStatusId();
            if ($statusId <= 0) {
                $this->guardLog(
                    'MirkaBeltCalculator [FEHLERSCHUTZ] Modus "AN", aber KEINE Sperr-Status-ID '
                    . 'in Tab 8 hinterlegt - es wurde NICHTS geaendert (nur gemeldet).',
                    ['auftrag' => $auftragsId]
                );
                return;
            }

            $this->sperreAuftrag($auftragsId, $statusId);

        } catch (\Throwable $fehler) {
            // Der Fehlerschutz darf den Bestellabschluss NIEMALS stoeren.
            $this->guardLog(
                'MirkaBeltCalculator [DIAG][Guard]: Exception im Fehlerschutz.',
                [
                    'message' => $fehler->getMessage(),
                    'file'    => $fehler->getFile(),
                    'line'    => $fehler->getLine(),
                ]
            );
        }
    }

    /**
     * Setzt den Auftrag auf die konfigurierte Sperr-Status-ID und liest
     * anschliessend zur Kontrolle den Status erneut. Schreibt nur den Status,
     * nie Preise oder Mengen. Muster laut offizieller Plenty-Doku
     * "Event Procedures": updateOrder(['statusId' => X], $orderId).
     *
     * @param int   $auftragsId
     * @param float $statusId
     */
    private function sperreAuftrag($auftragsId, $statusId)
    {
        try {
            /** @var OrderRepositoryContract $orderRepo */
            $orderRepo = pluginApp(OrderRepositoryContract::class);

            $orderRepo->updateOrder(['statusId' => $statusId], $auftragsId);

            // Nachkontrolle: Auftrag neu laden und Status vergleichen.
            $istStatus = null;
            try {
                $kontrolle = $orderRepo->findOrderById($auftragsId);
                $istStatus = (float) $kontrolle->statusId;
            } catch (\Throwable $egal) {
                $istStatus = null;
            }

            $differenz = ($istStatus !== null) ? ($istStatus - (float) $statusId) : 999.0;
            if ($differenz < 0) {
                $differenz = -$differenz;
            }

            if ($istStatus !== null && $differenz < 0.001) {
                $this->guardLog(
                    'MirkaBeltCalculator [FEHLERSCHUTZ] Auftrag ' . $auftragsId
                    . ' auf Status ' . $statusId . ' gesperrt. Nachkontrolle OK.',
                    ['auftrag' => $auftragsId, 'statusId' => $statusId]
                );
            } else {
                $this->guardLog(
                    'MirkaBeltCalculator [FEHLERSCHUTZ] Auftrag ' . $auftragsId
                    . ': Status-Setzen NICHT bestaetigt (Soll ' . $statusId . ', Ist '
                    . ($istStatus !== null ? $istStatus : 'nicht lesbar')
                    . '). BITTE MANUELL PRUEFEN.',
                    ['auftrag' => $auftragsId]
                );
            }
        } catch (\Throwable $fehler) {
            $this->guardLog(
                'MirkaBeltCalculator [FEHLERSCHUTZ] Auftrag ' . $auftragsId
                . ': Sperren fehlgeschlagen (' . $fehler->getMessage()
                . '). BITTE MANUELL PRUEFEN.',
                ['auftrag' => $auftragsId]
            );
        }
    }

    /**
     * Laedt den Auftrag frisch aus der Datenbank. Gleiche bewaehrte
     * Relationsliste wie im OrderRenameListener (dort sind die Betraege
     * nachweislich lesbar). Reihenfolge: mit Relationen -> ohne -> Event.
     *
     * @param int    $auftragsId
     * @param object $eventAuftrag  Auftrag aus dem Event (Rueckfall)
     * @return object
     */
    private function ladeAuftrag($auftragsId, $eventAuftrag)
    {
        /** @var OrderRepositoryContract $orderRepo */
        $orderRepo = pluginApp(OrderRepositoryContract::class);

        try {
            $order = $orderRepo->findOrderById($auftragsId, [
                'orderItems',
                'orderItems.amounts',
            ]);
            if ($order !== null) {
                return $order;
            }
        } catch (\Throwable $egal) {
            // Weiter mit dem naechsten Versuch.
        }

        try {
            $order = $orderRepo->findOrderById($auftragsId);
            if ($order !== null) {
                return $order;
            }
        } catch (\Throwable $egal) {
            // Weiter mit dem Rueckfall.
        }

        return $eventAuftrag;
    }

    /**
     * Liest den Brutto-Einzelpreis einer Auftragsposition (fest benannte
     * Zugriffe, sandbox-konform). Erst priceOriginalGross, dann priceGross.
     * Liefert null, wenn nichts lesbar ist.
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
     * Diagnose-/Alarm-Log (garantiert sichtbarer Kanal error(), wie im
     * uebrigen Plugin etabliert).
     *
     * @param string $meldung
     * @param array  $kontext
     */
    private function guardLog($meldung, $kontext = [])
    {
        $this->getLogger(__METHOD__)->error($meldung, $kontext);
    }
}
