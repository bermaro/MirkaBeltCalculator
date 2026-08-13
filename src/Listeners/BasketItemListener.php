<?php

namespace MirkaBeltCalculator\Listeners;

use Plenty\Modules\Basket\Events\BasketItem\AfterBasketItemAdd;
use Plenty\Modules\Frontend\Session\Storage\Contracts\FrontendSessionStorageFactoryContract;
use Plenty\Plugin\Log\Loggable;
use MirkaBeltCalculator\Configs\PluginConfig;
use MirkaBeltCalculator\Services\PriceCalculationService;

/**
 * BasketItemListener (v1.3.0)
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
                $this->getLogger(__METHOD__)->error(
                    'MirkaBeltCalculator [DIAG]: Kein BasketItem im Event erhalten.'
                );
                return;
            }

            $variationId = (int) $basketItem->variationId;

            // DIAG 1: Feuert AfterBasketItemAdd ueberhaupt? (garantiert sichtbar)
            $this->getLogger(__METHOD__)->error(
                'MirkaBeltCalculator [DIAG]: AfterBasketItemAdd ausgeloest.',
                [
                    'variationId'   => $variationId,
                    'wirdBehandelt' => $config->isHandledVariation($variationId),
                ]
            );

            if (!$config->isHandledVariation($variationId)) {
                return;
            }

            // -------------------------------------------------------------
            //  Bestelleigenschaften lesen
            //  NEU in v1.2.0: Quelle ist originOrderVariationProperties,
            //  NICHT mehr basketItemOrderParams (war leer).
            // -------------------------------------------------------------
            $orderProperties = $basketItem->originOrderVariationProperties ?? [];

            // DIAG 2: VOLLSTAENDIGER Dump der neuen Struktur (propertyId/type/name/value).
            // Hieraus lesen wir die echten propertyId-Zuordnungen ab.
            $this->getLogger(__METHOD__)->error(
                'MirkaBeltCalculator [DIAG]: originOrderVariationProperties (VOLLDUMP).',
                [
                    'istArray' => is_array($orderProperties),
                    'anzahl'   => is_array($orderProperties) ? count($orderProperties) : 0,
                    'inhalt'   => is_array($orderProperties) ? $this->dumpProperties($orderProperties) : null,
                ]
            );

            if (!is_array($orderProperties) || empty($orderProperties)) {
                $this->getLogger(__METHOD__)->error(
                    'MirkaBeltCalculator [DIAG]: Keine originOrderVariationProperties gefunden (leer).',
                    ['variationId' => $variationId]
                );
                // Kein Preis-Setzen, kein stiller 1-EUR-Artikel: hier nur Diagnose.
                return;
            }

            // -------------------------------------------------------------
            //  Auslese mit den AKTUELL konfigurierten IDs (64-69).
            //  Wird voraussichtlich null liefern, solange die echten IDs
            //  nicht feststehen. Das ist beabsichtigt (siehe Klassen-Doc).
            // -------------------------------------------------------------
            $configData = $this->extractConfiguration($config, $orderProperties);
            if ($configData === null) {
                $this->getLogger(__METHOD__)->error(
                    'MirkaBeltCalculator [DIAG]: Konfiguration unvollstaendig mit aktuell konfigurierten IDs. '
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
                $this->getLogger(__METHOD__)->error(
                    'MirkaBeltCalculator [DIAG]: Preisberechnung fehlgeschlagen.',
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
            $this->getLogger(__METHOD__)->error(
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
                $this->getLogger(__METHOD__)->error(
                    'MirkaBeltCalculator [DIAG]: Zettel konnte nicht gespeichert werden.',
                    ['message' => $egal->getMessage()]
                );
            }

        } catch (\Throwable $t) {
            $this->getLogger(__METHOD__)->error(
                'MirkaBeltCalculator [DIAG]: Exception im BasketItemListener.',
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

        if (count($liste) > 10) {
            $liste = array_slice($liste, -10);
        }
        $ablage->setValue('mirkaKonfigListe', json_encode($liste));

        $this->getLogger(__METHOD__)->error(
            'MirkaBeltCalculator [DIAG]: Zettel fuer Umbenenner in Sitzung gespeichert.',
            [
                'anzahlEintraege' => count($liste),
                'preis'           => (float) $preis,
                'werte'           => $eintrag['werte'],
            ]
        );
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
