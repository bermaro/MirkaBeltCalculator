<?php

namespace MirkaBeltCalculator\Listeners;

use Plenty\Modules\Basket\Events\BasketItem\AfterBasketItemAdd;
use Plenty\Modules\Basket\Contracts\BasketItemRepositoryContract;
use Plenty\Modules\Frontend\Session\Storage\Contracts\FrontendSessionStorageFactoryContract;
use Plenty\Plugin\Log\Loggable;
use MirkaBeltCalculator\Configs\PluginConfig;
use MirkaBeltCalculator\Services\PriceCalculationService;

/**
 * BasketItemListener (v1.5.25)
 *
 * ---------------------------------------------------------------------
 * v1.5.25 (08.09.2026): LOG-STUFEN KORRIGIERT + PERSISTENZ ALS MESSUNG
 * ---------------------------------------------------------------------
 *   1) LOG-STUFEN: Bisher liefen ALLE Log-Zeilen ueber error() - auch
 *      Erfolge. Grund war eine echte Plenty-Regel: nur ab Stufe "error"
 *      aufwaerts erscheint eine Zeile OHNE Uebersetzungs-Schluessel.
 *      Beleg: Plenty-Doku "Adding log functionality" - "The above
 *      conditions do not apply if the log level is set to error,
 *      critical, alert or emergency."
 *      Jetzt: OK/Routine/Messung laufen auf info() MIT Uebersetzung
 *      (resources/lang/de|en/mirka.properties); echte Probleme bleiben
 *      auf error(). Ein gesundes Log sieht damit nicht mehr aus wie ein
 *      Fehler-Log.
 *
 *   2) BASKET-PERSISTENZ WIEDER AKTIV - aber nur als kontrollierte
 *      Messung und NUR bei Debug=AN (Tab 6). Sie ist der einzige nie
 *      live gemessene Weg, die sechs Werte sitzungs-UNABHAENGIG an den
 *      Auftrag zu bringen. Sie meldet Plentys ECHTEN Validator-Fehler
 *      (getMessageBag) auf Error-Stufe. Der Checkout bleibt stabil: der
 *      Schreibzugriff laeuft nur bei AfterBasketItemAdd (nicht in der
 *      Auftrags-Vorschau) und mit $fireEvents=false; die beiden
 *      Schleifen-Listener bleiben im ServiceProvider abgeschaltet.
 *      Debug=AUS -> exakt der stabile v1.5.24-Betrieb (Preis -> Zettel).
 * ---------------------------------------------------------------------
 *
 * ---------------------------------------------------------------------
 * v1.5.24 (08.09.2026): ZURUECK AUF DEN FUNKTIONIERENDEN BETRIEB
 * ---------------------------------------------------------------------
 *   Dieser Listener macht wieder genau das, was er in v1.5.2 / v1.5.12
 *   gemacht hat - und sonst nichts:
 *       Bestelleigenschaften lesen -> Preis berechnen -> Preis setzen
 *       -> Sitzungs-Zettel schreiben.
 *
 *   ABGESCHALTET: die Basket-Persistenz (updateBasketItem mit
 *   basketItemOrderParams). Sie wurde von Plenty ohnehin mit
 *   "validation error found" abgelehnt, hat also nie funktioniert, aber
 *   bei jedem Zulegen zwei zusaetzliche Datenbankzugriffe verursacht.
 *   Die Methode persistiereAmWarenkorbArtikel() bleibt im Code liegen,
 *   wird aber NICHT mehr aufgerufen.
 *
 *   Der Sitzungs-Marker mirkaSitzungsMarke wird weiterhin geschrieben -
 *   er kostet nichts und zeigt beim naechsten Auftrag, ob die Sitzung
 *   beim Anlegen noch dieselbe ist.
 * ---------------------------------------------------------------------
 *
 * ---------------------------------------------------------------------
 * v1.5.23 - AUS DEM EIGENEN ARCHIV WIEDERGEFUNDEN
 * ---------------------------------------------------------------------
 *   Im Archiv "ALT-Dateien-vor-15-August" liegt plugin-v1.6.1-TEST vom
 *   13.08.2026. Dort wurde dieselbe Persistenz-Idee schon einmal gebaut -
 *   aber mit einem entscheidenden Unterschied im Datensatz:
 *
 *     v1.6.1 (Archiv):   propertyId, type, name, value
 *     v1.5.21 (meins):   propertyId, value
 *
 *   type und name stammen dabei NICHT aus einer Vermutung, sondern kommen
 *   unveraendert aus originOrderVariationProperties - also aus Plentys
 *   eigener Lieferung. Genau die abgespeckte Form endete mit
 *   "validation error found". Ab v1.5.23 wird wieder der vollstaendige
 *   Originaldatensatz gesendet.
 *
 *   Hinweis: v1.6.1 war damals ausdruecklich EXPERIMENTELL und wurde nie
 *   im Betrieb bestaetigt ("nicht ungetestet ausrollen"). Der Readback
 *   bleibt deshalb Pflicht - Erfolg gilt nur bei 6/6 nach erneutem Laden.
 * ---------------------------------------------------------------------
 *
 * ---------------------------------------------------------------------
 * v1.5.21 - vier Korrekturen nach Gegenpruefung (drei davon echte Fehler)
 * ---------------------------------------------------------------------
 *   1) NUR NOCH EIN SCHREIBVERSUCH. v1.5.20 hat drei geratene Datenformen
 *      nacheinander auf denselben echten Kundenwarenkorb geschrieben.
 *      Haette die erste etwas veraendert und die Kontrolle waere trotzdem
 *      durchgefallen, haette die zweite auf einem bereits veraenderten
 *      Datensatz gearbeitet - ohne Rueckweg. Das ist entfernt.
 *
 *   2) DER ECHTE VALIDATOR-FEHLER WIRD PROTOKOLLIERT. Plentys
 *      ValidationException traegt die eigentlichen Feldfehler in
 *      getMessageBag(); "validation error found" ist nur die Ueberschrift.
 *      Bisher wurde nur getMessage() gelesen - deshalb wussten wir nichts.
 *      Jetzt landen Meldung, Ort, MessageBag UND die gesendeten Daten im
 *      Log. Damit sagt Plenty selbst, was falsch ist, statt dass wir raten.
 *
 *   3) FREMDE PARAMETER BLEIBEN WIRKLICH UNVERAENDERT. Bis v1.5.20 wurden
 *      sie auf propertyId+value reduziert - type, name und basketItemId
 *      gingen dabei verloren. Das war ein echter Fehler. Jetzt wird der
 *      komplette vorhandene Datensatz unveraendert weitergereicht.
 *
 *   4) DIE PREIS-KONTROLLE PRUEFT DIE RICHTIGEN FELDER. Bisher wurde
 *      zuerst "price" verglichen. Der Konfigurator arbeitet aber mit
 *      useGivenPrice=true und givenPrice - eine Beschaedigung genau dieser
 *      Felder waere als "Preis unveraendert" durchgegangen. Verglichen
 *      werden jetzt quantity, price, givenPrice UND useGivenPrice.
 *
 *   Entfallen ist ausserdem die geratene Form "type=text, name=<id>".
 *   Welche Felder der Validator verlangt, entscheidet sein MessageBag -
 *   nicht eine Vermutung.
 * ---------------------------------------------------------------------
 *
 * v1.5.20: Die Speicherung in v1.5.19 wurde von Plenty mit
 * "validation error found" abgelehnt. Welche Felder der Validator
 * VERLANGT, ist nicht dokumentiert. Deshalb werden jetzt drei Datenformen
 * - ausschliesslich aus den dokumentierten BasketItemParams-Feldern
 * propertyId/value/basketItemId/type/name - nacheinander versucht.
 * Ausserdem wird VORHER protokolliert, was am frisch geladenen
 * Warenkorbartikel ueberhaupt steht. Erfolg gilt weiterhin nur nach
 * bestandener Kontrolle durch erneutes Laden.
 *
 * v1.5.19 gegenueber v1.5.18 - zwei Korrekturen, beide ohne neue Logik:
 *   1) is_scalar() entfernt. Diese Funktion war die EINZIGE im ganzen
 *      Plugin, die bisher nie benutzt wurde und damit in der
 *      Plenty-Sandbox nicht als erlaubt belegt ist. Ersetzt durch
 *      is_string/is_int/is_float/is_bool - die laufen seit v1.5.16.
 *   2) updateBasketItem() bekommt den dritten Parameter $fireEvents
 *      ausdruecklich auf FALSE. Laut Plenty-Doku steht er sonst auf TRUE
 *      und das Speichern wuerde erneut Warenkorb-Ereignisse ausloesen -
 *      im schlimmsten Fall landet man wieder in diesem Listener.
 *
 * ---------------------------------------------------------------------
 * NEU v1.5.18: DAUERHAFTE SPEICHERUNG AM WARENKORBARTIKEL
 * ---------------------------------------------------------------------
 *   Bisher wurden die sechs Kundenwerte nur als "Zettel" in der
 *   Kunden-SITZUNG abgelegt. Auftrag 329694 (08.09.2026) hat bewiesen,
 *   dass diese Ablage unzuverlaessig ist: Der Zettel wurde um 13:42:16
 *   geschrieben und war um 13:42:52 beim Anlegen des Auftrags nicht mehr
 *   lesbar - bei einem ANGEMELDETEN Kunden, ohne Ab- oder Anmelden.
 *
 *   Deshalb schreibt der Listener die sechs Werte jetzt zusaetzlich in die
 *   basketItemOrderParams des Warenkorbartikels (Methode
 *   persistiereAmWarenkorbArtikel) und PRUEFT durch erneutes Laden, ob sie
 *   dort wirklich angekommen sind. Erfolg wird nur gemeldet, wenn der
 *   Reload 6/6 zeigt und Menge und Preis unveraendert sind.
 *
 *   Der Sitzungs-Zettel bleibt vorerst zusaetzlich bestehen (schadet
 *   nicht) und wird entfernt, sobald die Persistenz im Betrieb traegt.
 * ---------------------------------------------------------------------
 *
 * NEU v1.3.0 - "ZETTEL FUER DEN UMBENENNER" (06.07.2026):
 *    Der Roentgen-Test (Auftrag 327788) hat bewiesen, dass Plenty die
 *    Kundenwerte (AB0, 60, T, ...) NICHT am Auftrag speichert. Dieser
 *    Listener hat die Werte aber nachweislich in perfekter Form
 *    (originOrderVariationProperties). Deshalb legt er sie nach dem
 *    Preis-Setzen als "Zettel" in der Kunden-Sitzung ab (Schluessel
 *    'mirkaKonfigListe', JSON-Liste). Der OrderRenameListener liest den
 *    Zettel beim Auftrags-Anlegen (gleicher Kundenbesuch = gleiche
 *    Sitzung) und kann damit die Positionsnamen korrekt beschriften.
 *
 * ZWECK DIESER VERSION (zwei klar getrennte Aenderungen):
 *
 * 1) QUELLE UMGESTELLT (Anweisung Steve T.):
 *    Bisher wurde $basketItem->basketItemOrderParams gelesen. Dieses Feld war
 *    am Interception-Punkt LEER. Steve hat bestaetigt: Die richtige Quelle ist
 *    $basketItem->originOrderVariationProperties. Daraus liest das Plugin jetzt
 *    die Bestelleigenschaften.
 *
 * 2) VOLLSTAENDIGES DIAGNOSE-LOGGING (per error(), garantiert sichtbar):
 *    Die neue Datenstruktur hat pro Eintrag die Felder
 *        propertyId / type / name / value
 *    Im Debugger tauchte zuletzt propertyId 4 (Gravur) auf, NICHT die
 *    erwarteten IDs 64-69. Wir DUERFEN daher NICHT raten, welche propertyId
 *    welche Eigenschaft (Qualitaet/Koernung/Verbindung/Breite/Laenge) traegt.
 *    Deshalb schreibt dieser Listener jetzt JEDEN gefundenen Eintrag mit ALLEN
 *    vier Feldern ins Log. Aus diesem Log lesen wir im naechsten Schritt die
 *    echten propertyId-Zuordnungen ab und passen extractConfiguration() exakt
 *    darauf an.
 *
 * WICHTIG / BEWUSSTE ENTSCHEIDUNG:
 *    extractConfiguration() arbeitet in v1.2.0 NOCH mit den konfigurierten
 *    IDs 64-69. Solange die echten IDs nicht feststehen, wird die Auslese
 *    voraussichtlich "Konfiguration unvollstaendig" melden. Das ist GEWOLLT:
 *    Erst das Test-Log liefert die echten IDs, dann erfolgt die Anpassung.
 *    Es wird hier NICHTS geraten.
 *
 * HINWEIS zu error():
 *    error() ist hier NUR fuer die Testphase als Diagnose-Kanal genutzt, weil
 *    info()/debug() in Plenty nur mit Uebersetzungs-Schluessel schreiben.
 *    Es handelt sich NICHT um echte Fehler. Vor dem Go-Live werden diese
 *    Zeilen entfernt bzw. auf info()+Translation-Key zurueckgestellt
 *    (siehe Go-Live-Checkliste).
 */
class BasketItemListener
{
    use Loggable;

    /**
     * NEU v1.5.6: EINE feste Log-Kennung fuer alle Mirka-Sammelzeilen.
     * Dadurch liegen Warenkorb- UND Auftragsmeldungen im Log unter
     * demselben Identifikator - ein einziger Filter zeigt alles.
     */
    const LOG_KENNUNG = 'MirkaBeltCalculator::MIRKA';

    /**
     * NEU v1.5.9: Merker fuer den Debug-Zustand (Tab 6).
     */
    private $debugGeprueft = false;
    private $debugAn = true;

    /**
     * NEU v1.5.9: ROUTINE-Meldung mit Zusatzkontext - erscheint NUR,
     * wenn in Tab 6 der Debug-Modus AN ist. Im Normalbetrieb bleibt das
     * Log dadurch schlank: pro Mirka-Artikel nur noch die eine
     * [MIRKA-KURZ]-Zeile. Echte Probleme laufen weiter ueber die
     * direkten Logger und erscheinen IMMER.
     *
     * @param string $meldung
     * @param array  $kontext
     */
    private function diagKontext($meldung, $kontext = [])
    {
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
            return;
        }
        // NEU v1.5.25: Routine-Diagnose laeuft jetzt auf Stufe "info"
        // (nicht mehr "error"). Damit die Zeile im Backend-Log ueberhaupt
        // erscheint, MUSS der Log-Code eine Uebersetzung haben - sonst
        // verwirft Plenty alles unterhalb von "error". Die Uebersetzung
        // liegt in resources/lang/de|en/mirka.properties (Schluessel diag).
        // Der eigentliche Klartext steht in der Zusatzinfo ("text").
        $k = is_array($kontext) ? $kontext : [];
        $k['text'] = $meldung;
        $this->getLogger(self::LOG_KENNUNG)->info('MirkaBeltCalculator::mirka.diag', $k);
    }


    /**
     * Wird vom Event-Dispatcher aufgerufen, NACHDEM ein Artikel in den
     * Warenkorb gelegt wurde.
     */
    public function handle(AfterBasketItemAdd $event)
    {
        try {
            /** @var PluginConfig $config */
            $config = pluginApp(PluginConfig::class);
            /** @var PriceCalculationService $priceService */
            $priceService = pluginApp(PriceCalculationService::class);

            $basketItem = $event->getBasketItem();
            if ($basketItem === null) {
                // error() = garantiert sichtbar
                $this->getLogger(self::LOG_KENNUNG)->error(
                    '[MIRKA-PROBLEM] Kein BasketItem im Event erhalten.'
                );
                return;
            }

            $variationId = (int) $basketItem->variationId;

            // NEU v1.5.9: ZUERST pruefen, ob es ueberhaupt ein Mirka-
            // Konfigurator-Artikel ist. Frueher wurde VOR dieser Pruefung
            // geloggt - dadurch erzeugte jeder ganz normale Artikel im
            // Warenkorb eine Mirka-Logzeile. Jetzt bleibt das Log bei
            // Fremdartikeln vollstaendig still.
            if (!$config->isHandledVariation($variationId)) {
                return;
            }

            // Routine-Meldung nur bei Debug (Tab 6).
            $this->diagKontext(
                'MirkaBeltCalculator [DIAG]: AfterBasketItemAdd ausgeloest.',
                ['variationId' => $variationId]
            );

            // -------------------------------------------------------------
            //  Bestelleigenschaften lesen
            //  NEU in v1.2.0: Quelle ist originOrderVariationProperties,
            //  NICHT mehr basketItemOrderParams (war leer).
            // -------------------------------------------------------------
            $orderProperties = $basketItem->originOrderVariationProperties ?? [];

            // DIAG 2: VOLLSTAENDIGER Dump der neuen Struktur (propertyId/type/name/value).
            // Hieraus lesen wir die echten propertyId-Zuordnungen ab.
            $this->diagKontext(
                'MirkaBeltCalculator [DIAG]: originOrderVariationProperties (VOLLDUMP).',
                [
                    'istArray' => is_array($orderProperties),
                    'anzahl'   => is_array($orderProperties) ? count($orderProperties) : 0,
                    'inhalt'   => is_array($orderProperties) ? $this->dumpProperties($orderProperties) : null,
                ]
            );

            if (!is_array($orderProperties) || empty($orderProperties)) {
                // NEU v1.5.10 (KRITISCH): Diese Meldung MUSS immer
                // erscheinen, auch bei Debug=AUS. In v1.5.9 lief sie
                // versehentlich ueber diagKontext() und war damit im
                // Normalbetrieb unsichtbar - ausgerechnet bei dem Fall,
                // den wir suchen: Mirka-Artikel erkannt, aber KEINE
                // Bestelleigenschaften da. Dann wird kein Preis gesetzt,
                // keine [MIRKA-KURZ]-Zeile geschrieben - ohne diese
                // Meldung stuende gar nichts im Log.
                $this->getLogger(self::LOG_KENNUNG)->error(
                    '[MIRKA-PROBLEM] Keine Bestelleigenschaften am Mirka-'
                    . 'Warenkorbartikel | variationId=' . $variationId
                    . ' | Es wurde KEIN Preis gesetzt.',
                    ['variationId' => $variationId]
                );
                // Kein Preis-Setzen, kein stiller 1-EUR-Artikel.
                return;
            }

            // -------------------------------------------------------------
            //  Auslese mit den AKTUELL konfigurierten IDs (64-69).
            //  Wird voraussichtlich null liefern, solange die echten IDs
            //  nicht feststehen. Das ist beabsichtigt (siehe Klassen-Doc).
            // -------------------------------------------------------------
            $configData = $this->extractConfiguration($config, $orderProperties);
            if ($configData === null) {
                $this->getLogger(self::LOG_KENNUNG)->error(
                    '[MIRKA-PROBLEM] Konfiguration unvollstaendig mit aktuell konfigurierten IDs. '
                    . 'Bitte VOLLDUMP oben pruefen und echte propertyId-Zuordnung ableiten.',
                    ['erwarteteIds' => [
                        'schleifmittel' => $config->getPropertyIdSchleifmittel(),
                        'koernung'      => $config->getPropertyIdKoernung(),
                        'verbindung'    => $config->getPropertyIdVerbindung(),
                        'breite'        => $config->getPropertyIdBreite(),
                        'laenge'        => $config->getPropertyIdLaenge(),
                    ]]
                );
                // Bewusst KEIN Preis setzen -> kein falscher Preis im Warenkorb.
                return;
            }

            $result = $priceService->calculate(
                $configData['productGroupCode'],
                $configData['grit'],
                $configData['jointCode'],
                $configData['width'],
                $configData['length']
            );

            if (!$result['success']) {
                // NEU v1.5.10: Der Grund steht jetzt DIREKT in der
                // sichtbaren Meldung - vorher steckte er nur im
                // Zusatzkontext und musste aufgeklappt werden.
                $grund = '';
                if (isset($result['detail']) && $result['detail'] !== '') {
                    $grund = (string) $result['detail'];
                } elseif (isset($result['error']) && $result['error'] !== '') {
                    $grund = (string) $result['error'];
                } else {
                    $grund = 'kein Grund gemeldet';
                }
                $this->getLogger(self::LOG_KENNUNG)->error(
                    '[MIRKA-PROBLEM] Preisberechnung fehlgeschlagen | Grund='
                    . $grund . ' | Es wurde KEIN Preis gesetzt.',
                    $result
                );
                // Bewusst KEIN Preis setzen.
                return;
            }

            // -------------------------------------------------------------
            //  Preis setzen (zu verifizieren, ob das bei "After" greift)
            // -------------------------------------------------------------
            $basketItem->useGivenPrice = true;
            $basketItem->givenPrice    = $result['verkaufspreis'];

            // DIAG 3: Preis wurde im Listener gesetzt (garantiert sichtbar).
            $this->diagKontext(
                'MirkaBeltCalculator [DIAG]: Preis im Listener gesetzt (useGivenPrice/givenPrice).',
                [
                    'variationId'    => $variationId,
                    'gesetzterPreis' => $result['verkaufspreis'],
                    'source'         => $result['source'],
                    'uvp'            => $result['uvp'],
                ]
            );

            // NEU v1.3.0: "Zettel fuer den Umbenenner" - die Kundenwerte in
            // der Sitzung ablegen (am Auftrag speichert Plenty sie nicht).
            // Fehler hier duerfen den Kauf NIEMALS stoeren -> eigenes try.
            try {
                $this->merkeKonfigurationFuerRename($orderProperties, (float) $result['verkaufspreis']);
            } catch (\Throwable $egal) {
                $this->getLogger(self::LOG_KENNUNG)->error(
                    '[MIRKA-PROBLEM] Zettel konnte nicht gespeichert werden | Grund='
                    . $egal->getMessage(),
                    ['message' => $egal->getMessage()]
                );
            }

            // ---------------------------------------------------------
            // NEU v1.5.25: BASKET-PERSISTENZ ALS KONTROLLIERTE MESSUNG.
            // ---------------------------------------------------------
            //   Warum wieder aktiv: Dies ist der EINZIGE bisher nie live
            //   gemessene Weg, der die sechs Werte sitzungs-UNABHAENGIG an
            //   den Auftrag bringen wuerde (die Werte lebten dann am
            //   Warenkorb-Datensatz, nicht in der fluechtigen Sitzung).
            //   v1.5.24 hatte ihn abgeschaltet, BEVOR das Ergebnis je zu
            //   sehen war.
            //
            //   Warum das den Checkout NICHT stoert (belegt):
            //     - Der Schreibzugriff laeuft NUR hier, bei AfterBasketItemAdd
            //       (echtes In-den-Warenkorb-Legen), NICHT in der staendig
            //       neu gerechneten Auftrags-VORSCHAU. Die beiden Listener,
            //       die die Ausloggen-Schleife ausgeloest hatten
            //       (BeforeBasketItemToOrderItem / AfterBasketItemToOrderItem),
            //       bleiben im ServiceProvider ABGESCHALTET.
            //     - updateBasketItem() wird mit $fireEvents = false gerufen -
            //       loest also KEINE weiteren Warenkorb-Ereignisse aus.
            //
            //   Warum nur bei Debug: Solange wir messen, laeuft der
            //   Schreibzugriff nur, wenn Tab 6 (Debug) AN ist. Ist Debug AUS,
            //   arbeitet das Plugin exakt wie die stabile v1.5.24 (Preis ->
            //   Zettel), ohne jeden zusaetzlichen Datenbankzugriff. So kann
            //   ein einziger Warenkorb-Test die offene Frage klaeren, ohne
            //   den Normalbetrieb zu belasten.
            //
            //   Die Methode selbst meldet Plentys ECHTEN Validator-Fehler
            //   (getMessageBag) auf Error-Stufe - genau die verlangte
            //   Debug-Funktion. Der Kauf wird durch nichts davon gestoert
            //   (eigenes try/catch).
            // ---------------------------------------------------------
            if ($config->isDebugMode()) {
                try {
                    $this->persistiereAmWarenkorbArtikel($basketItem, $orderProperties, $config);
                } catch (\Throwable $egal) {
                    $this->getLogger(self::LOG_KENNUNG)->error(
                        '[MIRKA-PROBLEM] Basket-Persistenz (Messung) unerwartet abgebrochen | Grund='
                        . $egal->getMessage(),
                        ['message' => $egal->getMessage()]
                    );
                }
            }


        } catch (\Throwable $t) {
            $this->getLogger(self::LOG_KENNUNG)->error(
                '[MIRKA-PROBLEM] BasketItemListener Exception: ' . $t->getMessage(),
                [
                    'exception' => 'Throwable',
                    'message'   => $t->getMessage(),
                    'file'      => $t->getFile(),
                    'line'      => $t->getLine(),
                ]
            );
        }
    }

    /**
     * NEU v1.5.18 - DAUERHAFTE SPEICHERUNG AM WARENKORBARTIKEL.
     *
     * WARUM:
     *   Auftrag 329694 (08.09.2026) hat bewiesen, dass der Sitzungs-Zettel
     *   auch bei einem voellig normalen Checkout eines ANGEMELDETEN Kunden
     *   verschwindet: 13:42:16 geschrieben, 13:42:52 beim Anlegen des
     *   Auftrags nicht mehr lesbar. Die Sitzung ist also keine verlaessliche
     *   Ablage. Der Warenkorbartikel dagegen ist ein echter Datensatz mit
     *   eigener Id - was dort steht, ueberlebt Sitzungswechsel, Login und
     *   externe Bezahlvorgaenge.
     *
     * WAS PASSIERT:
     *   1. Die sechs Kundenwerte aus originOrderVariationProperties lesen
     *      (an dieser Stelle liegen sie nachweislich vollstaendig vor).
     *   2. Vorhandene basketItemOrderParams des Artikels lesen.
     *      Sind unsere sechs schon vollstaendig da -> NICHTS tun
     *      (verhindert wiederholtes Schreiben).
     *   3. FREMDE Parameter unveraendert uebernehmen, nur die sechs
     *      konfigurierten IDs setzen bzw. ersetzen.
     *   4. Mit updateBasketItem() speichern.
     *   5. Denselben Artikel mit findOneById() NEU LADEN und nachzaehlen.
     *   6. Erfolg wird NUR gemeldet, wenn der Reload wirklich 6/6 zeigt.
     *      Ausserdem werden Menge und Preis vor/nach verglichen - haben
     *      sie sich veraendert, ist das ein lautes Problem.
     *
     * WAS NICHT PASSIERT:
     *   Kein Anfassen von Preis oder Menge, kein Loeschen fremder
     *   Parameter, kein Schreiben, wenn die sechs Werte schon dort stehen.
     *
     * @param mixed        $basketItem
     * @param array        $orderProperties
     * @param PluginConfig $config
     */
    private function persistiereAmWarenkorbArtikel($basketItem, array $orderProperties, PluginConfig $config)
    {
        // ---- Die sechs konfigurierten IDs ----
        $ids = [
            (int) $config->getPropertyIdSchleifmittel(),
            (int) $config->getPropertyIdKoernung(),
            (int) $config->getPropertyIdVerbindung(),
            (int) $config->getPropertyIdBreite(),
            (int) $config->getPropertyIdLaenge(),
            (int) $config->getPropertyIdMirkaCode(),
        ];
        $istUnsere = [];
        foreach ($ids as $id) {
            if ($id > 0) {
                $istUnsere[$id] = true;
            }
        }

        // ---- Werte aus den Bestelleigenschaften einsammeln ----
        // v1.5.23 - AUS DEM ARCHIV UEBERNOMMEN (plugin-v1.6.1-TEST, 13.08.2026):
        // Nicht nur propertyId+value, sondern der VOLLSTAENDIGE Datensatz,
        // den Plenty selbst geliefert hat - inklusive type und name. Der
        // Versuch mit der abgespeckten Form endete mit "validation error
        // found". type und name werden dabei NICHT erfunden, sondern
        // unveraendert aus originOrderVariationProperties uebernommen.
        $werte  = [];   // propertyId => Wert (fuer Zaehlung/Vergleich)
        $params = [];   // propertyId => vollstaendiger Parameter-Datensatz
        foreach ($orderProperties as $prop) {
            $pid = (int) $this->getPropertyId($prop);
            $val = trim((string) $this->getValue($prop));
            if ($pid > 0 && $val !== '' && isset($istUnsere[$pid])) {
                $werte[$pid]  = $val;
                $params[$pid] = [
                    'propertyId' => $pid,
                    'type'       => (string) $this->getType($prop),
                    'name'       => (string) $this->getName($prop),
                    'value'      => $val,
                ];
            }
        }
        if (count($werte) < 6) {
            $this->getLogger(self::LOG_KENNUNG)->error(
                '[MIRKA-PROBLEM] Basket-Persistenz uebersprungen: nur '
                . count($werte) . '/6 Werte am Warenkorbartikel lesbar.'
            );
            return;
        }

        // ---- Warenkorbartikel-Id ----
        $basketItemId = (int) $this->feldBasketItemId($basketItem);
        if ($basketItemId <= 0) {
            $this->getLogger(self::LOG_KENNUNG)->error(
                '[MIRKA-PROBLEM] Basket-Persistenz uebersprungen: keine '
                . 'basketItemId am Warenkorbartikel lesbar.'
            );
            return;
        }

        /** @var BasketItemRepositoryContract $repo */
        $repo = pluginApp(BasketItemRepositoryContract::class);

        // ---- Ist-Zustand VOR dem Schreiben ----
        $vorArtikel = $repo->findOneById($basketItemId);
        $vorParams  = $this->leseOrderParams($vorArtikel);
        $vorAnzahl  = $this->zaehleUnsere($vorParams, $istUnsere);
        // v1.5.21: Der Vergleichs-Zustand umfasst jetzt ALLE vier Felder,
        // die den Preis dieser Position bestimmen. Vorher wurde nur
        // "price" verglichen - ein kaputtes givenPrice/useGivenPrice waere
        // dabei als "Preis unveraendert" durchgegangen. Genau damit
        // arbeitet der Rechner aber (useGivenPrice=true + givenPrice).
        $vorZustand = $this->zustandKurz($vorArtikel);

        if ($vorAnzahl >= 6) {
            // NEU v1.5.25: OK/No-op -> Stufe "info" (kein "Error").
            $this->getLogger(self::LOG_KENNUNG)->info(
                'MirkaBeltCalculator::mirka.persistOk',
                ['text' => '[MIRKA-KURZ] BASKET-PERSIST | basketItemId=' . $basketItemId
                    . ' | vor=6/6 | nichts zu tun (Werte stehen bereits am Artikel).']
            );
            return;
        }

        // ---- MESSUNG: was steht ueberhaupt am frisch geladenen Artikel? ----
        // NEU v1.5.25: reine Messung -> Stufe "info" (kein "Error").
        $this->getLogger(self::LOG_KENNUNG)->info(
            'MirkaBeltCalculator::mirka.messung',
            ['text' => '[MIRKA-STRUKTUR] Warenkorbartikel frisch geladen'
                . ' | basketItemId=' . $basketItemId
                . ' | Zustand: ' . $vorZustand
                . ' | vorhandene basketItemOrderParams=' . count($vorParams)
                . ' | davon unsere=' . $vorAnzahl . '/6'
                . ' | Inhalt: ' . $this->paramsKurz($vorParams)
                . ' | Zu sendende Mirka-Parameter: ' . $this->paramsKurz($params)]
        );

        // ---- Fremde Parameter VOLLSTAENDIG unveraendert uebernehmen ----
        // v1.5.21 (Fehler-Korrektur): Bis v1.5.20 wurden fremde Parameter
        // auf propertyId+value reduziert - dabei gingen type, name und
        // basketItemId verloren. Jetzt wird der komplette vorhandene
        // Datensatz unveraendert weitergereicht.
        $neueParams = [];
        foreach ($vorParams as $param) {
            $pid = (int) $this->holeAusFeldern($param, 'propertyId');
            if ($pid > 0 && isset($istUnsere[$pid])) {
                continue; // unsere werden gleich neu gesetzt
            }
            $neueParams[] = $param;   // unveraendert, mit allen Feldern
        }

        // ---- Unsere sechs Werte anhaengen ----
        // Mit propertyId, type, name UND value - so wie Plenty sie selbst
        // geliefert hat (siehe oben). Das ist die Form aus dem archivierten
        // v1.6.1-TEST; die abgespeckte Form aus v1.5.21 wurde vom Validator
        // abgelehnt.
        foreach ($ids as $id) {
            if ($id > 0 && isset($params[$id])) {
                $neueParams[] = $params[$id];
            }
        }

        // ---- EIN kontrollierter Schreibversuch ----
        // v1.5.21: Bewusst nur EINER. Bis v1.5.20 wurden drei Datenformen
        // nacheinander auf denselben echten Kundenwarenkorb geschrieben -
        // haette der erste Versuch etwas veraendert, haette der zweite auf
        // einem bereits veraenderten Datensatz gearbeitet, ohne Rueckweg.
        $fehlertext = '';
        $fehlerBag  = null;
        $fehlerOrt  = '';
        try {
            // Dritter Parameter $fireEvents ausdruecklich FALSE - sonst
            // wuerden erneut Warenkorb-Ereignisse ausgeloest.
            $repo->updateBasketItem(
                $basketItemId,
                ['basketItemOrderParams' => $neueParams],
                false
            );
        } catch (\Throwable $ausnahme) {
            $fehlertext = $ausnahme->getMessage();
            $fehlerOrt  = $ausnahme->getFile() . ':' . $ausnahme->getLine();
            // Plentys ValidationException traegt die EIGENTLICHEN
            // Feldfehler in getMessageBag(). "validation error found" allein
            // ist nur die Ueberschrift. Ob die Methode existiert, wird nicht
            // mit method_exists geprueft (im Plugin bisher nie benutzt),
            // sondern durch einen eigenen Versuch.
            try {
                $fehlerBag = $ausnahme->getMessageBag();
            } catch (\Throwable $egal) {
                $fehlerBag = null;
            }
        }

        if ($fehlertext !== '') {
            $bagText = '(keiner)';
            if ($fehlerBag !== null) {
                $roh = @json_encode($fehlerBag);
                if (is_string($roh) && $roh !== '') {
                    $bagText = strlen($roh) > 900 ? substr($roh, 0, 900) . '..' : $roh;
                }
            }
            $this->getLogger(self::LOG_KENNUNG)->error(
                '[MIRKA-PROBLEM] Basket-Persistenz abgelehnt'
                . ' | basketItemId=' . $basketItemId
                . ' | Meldung=' . $fehlertext
                . ' | Ort=' . $fehlerOrt
                . ' | Validator-Details=' . $bagText
                . ' | Es wurde NICHTS veraendert. Der Sitzungs-Zettel bleibt'
                . ' vorerst der einzige Weg.',
                [
                    'basketItemId'   => $basketItemId,
                    'meldung'        => $fehlertext,
                    'messageBag'     => $fehlerBag,
                    'gesendeteDaten' => $neueParams,
                ]
            );
            return;
        }

        // ---- Kontrolle: NEU LADEN und nachzaehlen ----
        $nachArtikel = $repo->findOneById($basketItemId);
        $nachParams  = $this->leseOrderParams($nachArtikel);
        $nachAnzahl  = $this->zaehleUnsere($nachParams, $istUnsere);
        $nachZustand = $this->zustandKurz($nachArtikel);
        $zustandGleich = ($vorZustand === $nachZustand);

        if ($nachAnzahl >= 6 && $zustandGleich) {
            // NEU v1.5.25: ERFOLG -> Stufe "info" (kein "Error").
            // Das ist der gruene Beweis, dass die Werte dauerhaft am
            // Warenkorb-Datensatz angekommen sind.
            $this->getLogger(self::LOG_KENNUNG)->info(
                'MirkaBeltCalculator::mirka.persistOk',
                ['text' => '[MIRKA-KURZ] BASKET-PERSIST | basketItemId=' . $basketItemId
                    . ' | vor=' . $vorAnzahl . '/6'
                    . ' | nachReload=' . $nachAnzahl . '/6'
                    . ' | Menge/Preise unveraendert=ja'
                    . ' | ERFOLG']
            );
            return;
        }

        $this->getLogger(self::LOG_KENNUNG)->error(
            '[MIRKA-PROBLEM] Basket-Persistenz ohne Fehlermeldung gespeichert,'
            . ' aber Kontrolle NICHT bestanden'
            . ' | basketItemId=' . $basketItemId
            . ' | nachReload=' . $nachAnzahl . '/6'
            . ' | Zustand vorher: ' . $vorZustand
            . ' | Zustand nachher: ' . $nachZustand
            . ' | Inhalt nachher: ' . $this->paramsKurz($nachParams)
        );
    }

    /**
     * NEU v1.5.21: Der vollstaendige Vergleichs-Zustand einer
     * Warenkorbposition. Enthaelt ALLE Felder, die den Preis dieser
     * Position bestimmen - nicht nur "price". Der Konfigurator arbeitet
     * ausdruecklich mit useGivenPrice=true und givenPrice; eine Kontrolle,
     * die nur "price" vergleicht, wuerde eine Beschaedigung genau dieser
     * beiden Felder uebersehen.
     *
     * @param mixed $artikel
     * @return string
     */
    private function zustandKurz($artikel)
    {
        $felder = $this->modellAlsFelder($artikel);
        $menge  = isset($felder['quantity'])      ? $felder['quantity']      : '?';
        $preis  = isset($felder['price'])         ? $felder['price']         : '?';
        $gPreis = isset($felder['givenPrice'])    ? $felder['givenPrice']    : '?';
        $gFlag  = isset($felder['useGivenPrice']) ? $felder['useGivenPrice'] : '?';
        return 'quantity=' . $this->wertFuerLog($menge)
            . '|price=' . $this->wertFuerLog($preis)
            . '|givenPrice=' . $this->wertFuerLog($gPreis)
            . '|useGivenPrice=' . $this->wertFuerLog($gFlag);
    }

    /**
     * NEU v1.5.18: Liest die basketItemOrderParams eines Artikels als
     * Liste von Feld-Arrays.
     *
     * WICHTIG: Plenty-Modelle geben ihre Inhalte nicht ueber foreach oder
     * isset($obj->feld) heraus (belegt in Auftrag 329694 - dort erschienen
     * nur die internen Schalter incrementing/exists/timestamps). Deshalb
     * wird jeder Eintrag ueber json_encode()/json_decode() in ein normales
     * Array umgewandelt.
     *
     * @param mixed $artikel
     * @return array Liste von Feld-Arrays
     */
    private function leseOrderParams($artikel)
    {
        $roh = null;
        if (is_object($artikel) && isset($artikel->basketItemOrderParams)) {
            $roh = $artikel->basketItemOrderParams;
        } elseif (is_array($artikel) && isset($artikel['basketItemOrderParams'])) {
            $roh = $artikel['basketItemOrderParams'];
        }
        if ($roh === null && is_object($artikel)) {
            // Ueber die Gesamtausgabe des Modells versuchen.
            $felder = $this->modellAlsFelder($artikel);
            if (isset($felder['basketItemOrderParams'])) {
                $roh = $felder['basketItemOrderParams'];
            }
        }

        $liste = [];
        if (is_array($roh)) {
            $liste = $roh;
        } elseif (is_object($roh)) {
            foreach ($roh as $e) {
                $liste[] = $e;
            }
        }

        $aus = [];
        foreach ($liste as $eintrag) {
            $felder = is_array($eintrag) ? $eintrag : $this->modellAlsFelder($eintrag);
            if (count($felder) > 0) {
                $aus[] = $felder;
            }
        }
        return $aus;
    }

    /** Wandelt ein Plenty-Modell ueber json_encode in ein Feld-Array. */
    private function modellAlsFelder($objekt)
    {
        if (is_array($objekt)) {
            return $objekt;
        }
        if (!is_object($objekt)) {
            return [];
        }
        $json = @json_encode($objekt);
        if (is_string($json) && $json !== '' && $json !== 'null') {
            $arr = @json_decode($json, true);
            if (is_array($arr)) {
                return $arr;
            }
        }
        return [];
    }

    /** Holt ein Feld aus einem bereits umgewandelten Feld-Array. */
    private function holeAusFeldern($felder, $name)
    {
        if (!is_array($felder)) {
            return '';
        }
        if ($name === 'propertyId') {
            return isset($felder['propertyId']) ? $felder['propertyId'] : '';
        }
        if ($name === 'value') {
            return isset($felder['value']) ? $felder['value'] : '';
        }
        return '';
    }

    /** Zaehlt, wie viele UNSERER sechs IDs in der Parameterliste nicht leer sind. */
    private function zaehleUnsere($params, $istUnsere)
    {
        $gefunden = [];
        foreach ($params as $felder) {
            $pid = (int) $this->holeAusFeldern($felder, 'propertyId');
            $val = trim((string) $this->holeAusFeldern($felder, 'value'));
            if ($pid > 0 && $val !== '' && isset($istUnsere[$pid])) {
                $gefunden[$pid] = true;
            }
        }
        return count($gefunden);
    }

    /** Kurzfassung der Parameterliste fuers Log. */
    private function paramsKurz($params)
    {
        if (count($params) === 0) {
            return '(leer)';
        }
        $teile = [];
        $i = 0;
        foreach ($params as $felder) {
            $namen = [];
            foreach ($felder as $n => $w) {
                $namen[] = (string) $n . '=' . $this->wertFuerLog($w);
            }
            $teile[] = '[' . $i . ']{' . implode('|', $namen) . '}';
            $i++;
            if ($i >= 8) {
                $teile[] = '...';
                break;
            }
        }
        return implode('', $teile);
    }

    /**
     * Kurzform eines Feldwerts fuers Log.
     * Bewusst OHNE is_scalar(): Diese Funktion wurde im Plugin bisher nie
     * benutzt und ist in der Plenty-Sandbox nicht als erlaubt belegt.
     * is_string/is_int/is_float/is_bool sind seit v1.5.16 im Einsatz.
     */
    private function wertFuerLog($w)
    {
        if (is_string($w) || is_int($w) || is_float($w)) {
            return substr((string) $w, 0, 30);
        }
        if (is_bool($w)) {
            return $w ? 'true' : 'false';
        }
        if ($w === null) {
            return 'null';
        }
        return '(komplex)';
    }

    /** basketItem.id (fest ausgeschrieben, Sandbox-Regel). */
    private function feldBasketItemId($q)
    {
        if (is_object($q) && isset($q->id)) {
            return $q->id;
        }
        if (is_array($q) && isset($q['id'])) {
            return $q['id'];
        }
        $felder = $this->modellAlsFelder($q);
        return isset($felder['id']) ? $felder['id'] : 0;
    }



    /**
     * NEU v1.3.0: Legt die sechs Kundenwerte als "Zettel" in der
     * Kunden-Sitzung ab, damit der OrderRenameListener sie beim
     * Auftrags-Anlegen wiederfindet. Es wird eine LISTE gefuehrt
     * (JSON), damit auch mehrere Konfigurator-Positionen in einem
     * Warenkorb funktionieren. Nur die letzten 10 Eintraege werden
     * behalten (Speicher-Hygiene).
     */
    private function merkeKonfigurationFuerRename(array $orderProperties, $preis)
    {
        /** @var FrontendSessionStorageFactoryContract $sessionFactory */
        $sessionFactory = pluginApp(FrontendSessionStorageFactoryContract::class);
        $ablage = $sessionFactory->getPlugin();

        // Bisherige Liste lesen (JSON-Text) und neuen Eintrag anhaengen.
        $roh   = (string) $ablage->getValue('mirkaKonfigListe');
        $liste = [];
        if ($roh !== '') {
            $dekodiert = json_decode($roh, true);
            if (is_array($dekodiert)) {
                $liste = $dekodiert;
            }
        }

        $eintrag = [
            'preis' => (float) $preis,
            'zeit'  => time(),
            'werte' => [],
        ];
        foreach ($orderProperties as $prop) {
            $propertyId = (int) $this->getPropertyId($prop);
            $wert       = (string) $this->getValue($prop);
            if ($propertyId > 0) {
                $eintrag['werte'][(string) $propertyId] = $wert;
            }
        }
        $liste[] = $eintrag;

        // HINWEIS v1.5.12 (bewusst NICHT geaendert - dokumentierte Grenze):
        // Die Sitzungs-Liste behaelt nur die letzten 10 Zettel.
        // Bewusst NICHT einfach erhoeht, weil die Liste nicht nur die
        // aktuellen Warenkorbpositionen enthaelt: Auch wieder entfernte
        // oder mehrfach umkonfigurierte Baender hinterlassen Zettel
        // ("stale"). Eine groessere Liste wuerde damit MEHR alte Zettel
        // aufbewahren und dadurch die Preis-Mehrdeutigkeit erhoehen -
        // also genau den Fall haeufiger machen, den die neue
        // Fail-Safe-Sperre blockiert.
        // Sauber geloest wird das erst mit einer eindeutigen configId /
        // BasketItem-Zuordnung (siehe UEBERGABE, offene Punkte).
        // Praktische Folge heute: Bei mehr als 10 nacheinander
        // konfigurierten Baendern faellt der aelteste Zettel weg; die
        // betroffene Position wird dann vom 6/6-Guard als unvollstaendig
        // gemeldet (fail-safe, kein stiller Fehler).
        if (count($liste) > 10) {
            $liste = array_slice($liste, -10);
        }
        $ablage->setValue('mirkaKonfigListe', json_encode($liste));

        // NEU v1.5.17: Zusaetzlich eine einfache Kontrollmarke in dieselbe
        // Ablage schreiben. Der OrderRenameListener liest sie beim
        // Auftrag-Anlegen wieder. Ist die Marke dort ebenfalls weg, ist
        // bewiesen, dass es sich um eine ANDERE Sitzung handelt und nicht
        // um ein Problem mit dem Zettel selbst.
        $ablage->setValue('mirkaSitzungsMarke', 'gesetzt-' . time());

        $this->diagKontext(
            'MirkaBeltCalculator [DIAG]: Zettel fuer Umbenenner in Sitzung gespeichert.',
            [
                'anzahlEintraege' => count($liste),
                'preis'           => (float) $preis,
                'werte'           => $eintrag['werte'],
            ]
        );

        // -------------------------------------------------------------
        // NEU v1.5.4: SAMMELZEILE. Alles Wichtige steht direkt IM
        // Nachrichtentext - dadurch in der Log-Liste (Spalte "Nachricht")
        // sofort lesbar, ohne "additionalInfo" aufklappen zu muessen.
        // Suchbegriff im Log: MIRKA-KURZ
        // Fehler hier duerfen den Kauf niemals stoeren -> eigenes try.
        // -------------------------------------------------------------
        try {
            /** @var PluginConfig $cfg */
            $cfg = pluginApp(PluginConfig::class);
            $w   = $eintrag['werte'];
            // Feste Kennung: ALLE Mirka-Sammelzeilen landen unter einem
            // einzigen Identifikator -> im Log genau EIN Filter noetig.
            // NEU v1.5.25: OK-Sammelzeile -> Stufe "info" (kein "Error").
            // Der lesbare Klartext steht in der Zusatzinfo ("text");
            // die Uebersetzung (mirka.warenkorb) macht die Info-Zeile im
            // Backend-Log ueberhaupt sichtbar.
            $this->getLogger(self::LOG_KENNUNG)->info(
                'MirkaBeltCalculator::mirka.warenkorb',
                ['text' => '[MIRKA-KURZ] WARENKORB'
                    . ' | Qualitaet=' . $this->zettelWert($w, $cfg->getPropertyIdSchleifmittel())
                    . ' | Koernung='  . $this->zettelWert($w, $cfg->getPropertyIdKoernung())
                    . ' | Verbindung=' . $this->zettelWert($w, $cfg->getPropertyIdVerbindung())
                    . ' | Breite='    . $this->zettelWert($w, $cfg->getPropertyIdBreite())
                    . ' | Laenge='    . $this->zettelWert($w, $cfg->getPropertyIdLaenge())
                    . ' | MirkaNr='   . $this->zettelWert($w, $cfg->getPropertyIdMirkaCode())
                    . ' | Preis(brutto)=' . (float) $preis
                    . ' | Session-Zettel=' . count($liste)]
            );
        } catch (\Throwable $egal) {
            // Sammelzeile ist reine Bequemlichkeit - Fehler ignorieren.
        }
    }

    /**
     * NEU v1.5.4: Liest einen Wert aus dem Zettel-Werte-Array fuer die
     * Sammelzeile. Gibt "(leer)" zurueck, wenn nichts drinsteht - so
     * sieht man auf einen Blick, welcher Wert gefehlt hat.
     *
     * @param array $werte      Werte-Array des Zettels (Schluessel = Property-ID)
     * @param int   $propertyId
     * @return string
     */
    private function zettelWert($werte, $propertyId)
    {
        $schluessel = (string) ((int) $propertyId);
        if (is_array($werte) && isset($werte[$schluessel])
            && (string) $werte[$schluessel] !== '') {
            return (string) $werte[$schluessel];
        }
        return '(leer)';
    }

    /**
     * Liest die Konfiguration aus den originOrderVariationProperties.
     *
     * NEUE STRUKTUR: jeder Eintrag hat propertyId / type / name / value.
     * Wir lesen propertyId und value; die Zuordnung erfolgt ueber die
     * aktuell konfigurierten IDs. (Anpassung auf echte IDs folgt nach
     * Auswertung des VOLLDUMP-Logs.)
     */
    private function extractConfiguration(PluginConfig $config, array $orderProperties)
    {
        $idSchleifmittel = $config->getPropertyIdSchleifmittel();
        $idKoernung      = $config->getPropertyIdKoernung();
        $idVerbindung    = $config->getPropertyIdVerbindung();
        $idBreite        = $config->getPropertyIdBreite();
        $idLaenge        = $config->getPropertyIdLaenge();

        $productGroupCode = null;
        $grit             = null;
        $jointCode        = null;
        $width            = null;
        $length           = null;

        foreach ($orderProperties as $prop) {
            $propertyId = $this->getPropertyId($prop);
            $value      = $this->getValue($prop);

            $propertyId = (int) $propertyId;
            $value      = (string) $value;

            if ($propertyId === $idSchleifmittel) {
                $productGroupCode = trim($value);
            } elseif ($propertyId === $idKoernung) {
                $grit = (int) $value;
            } elseif ($propertyId === $idVerbindung) {
                $jointCode = trim($value);
            } elseif ($propertyId === $idBreite) {
                $width = (int) $value;
            } elseif ($propertyId === $idLaenge) {
                $length = (int) $value;
            }
        }

        if (empty($productGroupCode) || $grit <= 0 || empty($jointCode) || $width <= 0 || $length <= 0) {
            return null;
        }

        return [
            'productGroupCode' => $productGroupCode,
            'grit'             => $grit,
            'jointCode'        => $jointCode,
            'width'            => $width,
            'length'           => $length,
        ];
    }

    /**
     * Liest ein Feld aus einem Eintrag, egal ob Objekt oder Array.
     * Gibt '' zurueck, wenn das Feld fehlt.
     */
    /**
     * Liest die vier bekannten Felder eines Eintrags fest aus - ohne
     * dynamische Property-Namen und ohne Konvertierungs-Funktionen
     * (beides ist in der Plenty-Sandbox verboten). Jeder Zugriff ist
     * fest ausgeschrieben mit isset()-Pruefung; funktioniert sowohl fuer
     * Objekte (->propertyId) als auch Arrays (['propertyId']).
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

    private function getType($prop)
    {
        if (is_object($prop)) {
            return isset($prop->type) ? $prop->type : '';
        }
        if (is_array($prop)) {
            return isset($prop['type']) ? $prop['type'] : '';
        }
        return '';
    }

    private function getName($prop)
    {
        if (is_object($prop)) {
            return isset($prop->name) ? $prop->name : '';
        }
        if (is_array($prop)) {
            return isset($prop['name']) ? $prop['name'] : '';
        }
        return '';
    }

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

    /**
     * Erstellt einen vollstaendigen, lesbaren Dump ALLER vier Felder
     * (propertyId / type / name / value) je Eigenschaft fuer das Log.
     */
    private function dumpProperties(array $orderProperties)
    {
        $result = [];
        foreach ($orderProperties as $prop) {
            $result[] = [
                'propertyId' => $this->getPropertyId($prop),
                'type'       => $this->getType($prop),
                'name'       => $this->getName($prop),
                'value'      => $this->getValue($prop),
            ];
        }
        return $result;
    }
}
