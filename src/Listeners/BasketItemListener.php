<?php

namespace MirkaBeltCalculator\Listeners;

use Plenty\Modules\Basket\Events\BasketItem\AfterBasketItemAdd;
use Plenty\Modules\Basket\Contracts\BasketItemRepositoryContract;
use Plenty\Modules\Frontend\Session\Storage\Contracts\FrontendSessionStorageFactoryContract;
use Plenty\Plugin\Log\Loggable;
use MirkaBeltCalculator\Configs\PluginConfig;
use MirkaBeltCalculator\Services\PriceCalculationService;

/**
 * BasketItemListener (v1.5.18)
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
        $this->getLogger(self::LOG_KENNUNG)->error($meldung, $kontext);
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

            // NEU v1.5.18: DIE EIGENTLICHE PERSISTENZ.
            // Die sechs Werte dauerhaft AM WARENKORBARTIKEL speichern
            // (basketItemOrderParams) statt nur in der Sitzung. Belegt
            // durch Auftrag 329694: Die Sitzung ist beim Anlegen des
            // Auftrags auch ohne Login-Wechsel nicht mehr verfuegbar.
            // Eigenes try/catch: darf den Kauf niemals stoeren.
            try {
                $this->persistiereAmWarenkorbArtikel($basketItem, $orderProperties, $config);
            } catch (\Throwable $egal) {
                $this->getLogger(self::LOG_KENNUNG)->error(
                    '[MIRKA-PROBLEM] Basket-Persistenz fehlgeschlagen (Ausnahme) | Grund='
                    . $egal->getMessage(),
                    ['message' => $egal->getMessage()]
                );
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
        $werte = [];
        foreach ($orderProperties as $prop) {
            $pid = (int) $this->getPropertyId($prop);
            $val = trim((string) $this->getValue($prop));
            if ($pid > 0 && $val !== '' && isset($istUnsere[$pid])) {
                $werte[$pid] = $val;
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
        $vorMenge   = (string) $this->feldMenge($vorArtikel);
        $vorPreis   = (string) $this->feldGivenPrice($vorArtikel);

        if ($vorAnzahl >= 6) {
            $this->getLogger(self::LOG_KENNUNG)->error(
                '[MIRKA-KURZ] BASKET-PERSIST | basketItemId=' . $basketItemId
                . ' | vor=6/6 | nichts zu tun (Werte stehen bereits am Artikel).'
            );
            return;
        }

        // ---- Neue Parameterliste bauen: fremde behalten, unsere setzen ----
        $neueParams = [];
        foreach ($vorParams as $param) {
            $pid = (int) $this->holeAusFeldern($param, 'propertyId');
            if ($pid > 0 && isset($istUnsere[$pid])) {
                continue; // unsere werden gleich neu gesetzt
            }
            $val = (string) $this->holeAusFeldern($param, 'value');
            if ($pid > 0) {
                $neueParams[] = ['propertyId' => $pid, 'value' => $val];
            }
        }
        foreach ($ids as $id) {
            if ($id > 0 && isset($werte[$id])) {
                $neueParams[] = ['propertyId' => $id, 'value' => (string) $werte[$id]];
            }
        }

        // ---- Schreiben ----
        $repo->updateBasketItem($basketItemId, ['basketItemOrderParams' => $neueParams]);

        // ---- Kontrolle: NEU LADEN und nachzaehlen ----
        $nachArtikel = $repo->findOneById($basketItemId);
        $nachParams  = $this->leseOrderParams($nachArtikel);
        $nachAnzahl  = $this->zaehleUnsere($nachParams, $istUnsere);
        $nachMenge   = (string) $this->feldMenge($nachArtikel);
        $nachPreis   = (string) $this->feldGivenPrice($nachArtikel);

        $mengeGleich = ($vorMenge === $nachMenge);
        $preisGleich = ($vorPreis === $nachPreis);

        if ($nachAnzahl >= 6 && $mengeGleich && $preisGleich) {
            $this->getLogger(self::LOG_KENNUNG)->error(
                '[MIRKA-KURZ] BASKET-PERSIST | basketItemId=' . $basketItemId
                . ' | vor=' . $vorAnzahl . '/6'
                . ' | geschrieben=' . count($neueParams) . ' Parameter'
                . ' | nachReload=' . $nachAnzahl . '/6'
                . ' | Menge unveraendert=ja | Preis unveraendert=ja'
                . ' | ERFOLG'
            );
            return;
        }

        // ---- Fehlschlag: NICHT als Erfolg melden, Struktur zeigen ----
        $this->getLogger(self::LOG_KENNUNG)->error(
            '[MIRKA-PROBLEM] Basket-Persistenz fehlgeschlagen'
            . ' | basketItemId=' . $basketItemId
            . ' | vor=' . $vorAnzahl . '/6'
            . ' | geschrieben=' . count($neueParams) . ' Parameter'
            . ' | nachReload=' . $nachAnzahl . '/6'
            . ' | Menge unveraendert=' . ($mengeGleich ? 'ja' : 'NEIN (' . $vorMenge . ' -> ' . $nachMenge . ')')
            . ' | Preis unveraendert=' . ($preisGleich ? 'ja' : 'NEIN (' . $vorPreis . ' -> ' . $nachPreis . ')')
            . ' | Struktur nachher: ' . $this->paramsKurz($nachParams)
        );
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
                $namen[] = (string) $n . '=' . (is_scalar($w) ? substr((string) $w, 0, 30) : '(komplex)');
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

    /** basketItem.quantity. */
    private function feldMenge($q)
    {
        if (is_object($q) && isset($q->quantity)) {
            return $q->quantity;
        }
        if (is_array($q) && isset($q['quantity'])) {
            return $q['quantity'];
        }
        $felder = $this->modellAlsFelder($q);
        return isset($felder['quantity']) ? $felder['quantity'] : '?';
    }

    /** basketItem.price bzw. givenPrice. */
    private function feldGivenPrice($q)
    {
        $felder = $this->modellAlsFelder($q);
        if (isset($felder['price'])) {
            return $felder['price'];
        }
        if (isset($felder['givenPrice'])) {
            return $felder['givenPrice'];
        }
        if (is_object($q) && isset($q->price)) {
            return $q->price;
        }
        return '?';
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
            $this->getLogger(self::LOG_KENNUNG)->error(
                '[MIRKA-KURZ] WARENKORB'
                . ' | Qualitaet=' . $this->zettelWert($w, $cfg->getPropertyIdSchleifmittel())
                . ' | Koernung='  . $this->zettelWert($w, $cfg->getPropertyIdKoernung())
                . ' | Verbindung=' . $this->zettelWert($w, $cfg->getPropertyIdVerbindung())
                . ' | Breite='    . $this->zettelWert($w, $cfg->getPropertyIdBreite())
                . ' | Laenge='    . $this->zettelWert($w, $cfg->getPropertyIdLaenge())
                . ' | MirkaNr='   . $this->zettelWert($w, $cfg->getPropertyIdMirkaCode())
                . ' | Preis(brutto)=' . (float) $preis
                . ' | Session-Zettel=' . count($liste)
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
