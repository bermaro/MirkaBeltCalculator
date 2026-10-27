<?php

namespace MirkaBeltCalculator\Services;

use Plenty\Plugin\Log\Loggable;
use Plenty\Modules\Plugin\Libs\Contracts\LibraryCallContract;
use MirkaBeltCalculator\Configs\PluginConfig;

/**
 * MirkaApiClient (v1.3.1, ergaenzt in v1.6.6)
 *
 * NEU v1.6.9: Zusaetzlich 'mirkaNr', 'mirkaBreite', 'mirkaLaenge' (aus dem
 * Mirka-Produktnamen, z. B. "150x7200mm") und 'produktName'.
 * NEU v1.6.6: Im Erfolgsfall wird zusaetzlich 'beltsPerPack' (Baender pro
 * Verpackungseinheit) aus der Cloud-Function-Antwort zurueckgegeben.
 *
 * UMBAU GEGENUEBER v1.0.8:
 * Der eigentliche HTTP-Aufruf (Guzzle) wird NICHT mehr direkt hier gemacht.
 * Grund: In Plenty darf externer Code (echte HTTP-Requests / Guzzle) nur in
 * Dateien im Ordner resources/lib/ laufen. Der direkte Aufruf
 * "pluginApp(\GuzzleHttp\Client::class)" scheiterte mit
 * "class GuzzleHttp\Client not found".
 *
 * Loesung (offiziell dokumentiert von Plenty, Hello-World-SDK-Tutorial):
 *  1. Guzzle als Dependency in plugin.json eintragen.
 *  2. Connector-Datei resources/lib/mirka_connector.php anlegen (macht den GET).
 *  3. Hier den LibraryCallContract nutzen, um den Connector aufzurufen und
 *     Parameter zu uebergeben.
 *
 * KORREKTUR IN v1.3.1 (WICHTIG):
 * Die BERMARO-Cloud-Function gibt NICHT die rohe Mirka-Antwort (mit
 * Price.Value) zurueck, sondern ein EIGENES Objekt. Bestaetigt durch echten
 * Test-Aufruf, Antwort z.B.:
 *   {
 *     "success": true, "uvp": 16.9, "verkaufspreis": 12.17,
 *     "einkaufspreis": 8.11, "mirkaProductCode": "AB4AZT0140VT",
 *     "productName": "...", "beltsPerPack": 5, "minimumOrderQuantity": 8, ...
 *   }
 * Deshalb lesen wir hier den UVP aus $data['uvp'].
 *
 * PREIS-LOGIK (Variante A):
 * Wir nehmen NUR den 'uvp'. Den Verkaufspreis berechnet weiterhin der
 * PriceCalculationService aus UVP * (1 - Rabatt) * (1 + Marge). Das von der
 * Cloud Function mitgelieferte 'verkaufspreis'-Feld wird IGNORIERT, damit nicht
 * doppelt gerechnet wird. (Es wird im Debug-Log nur zur Kontrolle angezeigt.)
 *
 * Das Rueckgabe-Format dieser Methode bleibt unveraendert
 * (['uvp' => ..., 'source' => ..., 'detail' => ...]), damit der
 * PriceCalculationService unveraendert weiterlaeuft.
 */
class MirkaApiClient
{
    use Loggable;

    /**
     * NEU v1.5.7: EINE feste Log-Kennung fuer ALLE Mirka-Meldungen
     * (siehe Listener). Ein Filter im Log zeigt alles.
     */
    const LOG_KENNUNG = 'MirkaBeltCalculator::MIRKA';

    public function fetchPrice($productGroupCode, $grit, $jointCode, $width, $length)
    {
        /** @var PluginConfig $config */
        $config = pluginApp(PluginConfig::class);

        // ----- Mock-Modus (unveraendert) -----
        if ($config->isMockMode()) {
            $mockUvp = $config->getMockUvp();

            if ($config->isDebugMode()) {
                $this->getLogger(self::LOG_KENNUNG)->info(
                    'MirkaBeltCalculator: Mock-Modus aktiv - kein API-Aufruf.',
                    ['mockUvp' => $mockUvp]
                );
            }

            return [
                'uvp'    => $mockUvp,
                'source' => 'mock',
                'detail' => 'Mock-UVP ' . number_format($mockUvp, 2) . ' EUR',
            ];
        }

        // ----- Echter API-Aufruf ueber den lib-Connector -----
        $baseUrl = $config->getProxyUrl();
        if ($baseUrl === '') {
            $this->getLogger(self::LOG_KENNUNG)->error(
                'MirkaBeltCalculator: Keine Proxy-URL konfiguriert.'
            );
            return ['uvp' => null, 'source' => 'error', 'detail' => 'Keine Proxy-URL konfiguriert'];
        }

        // Falls in der Config doch ein abschliessender / steht: entfernen,
        // damit "?action=..." im Connector sauber angehaengt wird.
        $baseUrl = rtrim($baseUrl, '/');

        try {
            /** @var LibraryCallContract $libCall */
            $libCall = pluginApp(LibraryCallContract::class);

            // Aufruf des Connectors. Schema: "PluginNamespace::dateiname_ohne_endung"
            // -> resources/lib/mirka_connector.php  =>  "MirkaBeltCalculator::mirka_connector"
            $result = $libCall->call(
                'MirkaBeltCalculator::mirka_connector',
                [
                    'baseUrl'          => $baseUrl,
                    'productGroupCode' => (string) $productGroupCode,
                    'grit'             => (string) $grit,
                    'jointCode'        => (string) $jointCode,
                    'width'            => (string) $width,
                    'length'           => (string) $length,
                    'timeout'          => $config->getApiTimeoutSeconds(),
                ]
            );

            // 1) Hat Plenty selbst einen lib-Fehler geliefert? (error => true)
            if (is_array($result) && isset($result['error']) && $result['error'] === true) {
                $msg = isset($result['error_msg']) ? $result['error_msg'] : 'unbekannter lib-Fehler';
                $this->getLogger(self::LOG_KENNUNG)->error(
                    'MirkaBeltCalculator: lib-Connector meldete Fehler.',
                    ['error_msg' => $msg]
                );
                return ['uvp' => null, 'source' => 'error', 'detail' => 'lib-Fehler: ' . $msg];
            }

            // 2) Erwartetes Format aus mirka_connector.php pruefen.
            if (!is_array($result) || !isset($result['ok'])) {
                $this->getLogger(self::LOG_KENNUNG)->error(
                    'MirkaBeltCalculator: Unerwartete Antwort vom lib-Connector.'
                );
                return ['uvp' => null, 'source' => 'error', 'detail' => 'Unerwartete lib-Antwort'];
            }

            // 3) HTTP-Fehler (z.B. Cloud Function nicht erreichbar / 4xx / 5xx)
            if ($result['ok'] !== true) {
                $httpCode = isset($result['httpCode']) ? $result['httpCode'] : 0;
                $connErr  = isset($result['connError']) ? (' / ' . $result['connError']) : '';
                $this->getLogger(self::LOG_KENNUNG)->error(
                    'MirkaBeltCalculator: HTTP-Aufruf fehlgeschlagen.',
                    ['httpCode' => $httpCode, 'connError' => $connErr]
                );
                return ['uvp' => null, 'source' => 'error', 'detail' => 'HTTP-Fehler ' . $httpCode . $connErr];
            }

            // 4) Body (JSON-String der Cloud Function) auswerten.
            $body = isset($result['body']) ? (string) $result['body'] : '';
            $data = json_decode($body, true);

            if (!is_array($data)) {
                $this->getLogger(self::LOG_KENNUNG)->error(
                    'MirkaBeltCalculator: Ungueltiges JSON von der Cloud Function.',
                    ['body' => $body]
                );
                return ['uvp' => null, 'source' => 'error', 'detail' => 'Ungueltiges JSON'];
            }

            // Debug: kompletten Body anzeigen, damit man im Log sieht, was kam.
            if ($config->isDebugMode()) {
                $this->getLogger(self::LOG_KENNUNG)->info(
                    'MirkaBeltCalculator: Cloud-Function-Antwort erhalten.',
                    ['body' => $data]
                );
            }

            // 5) HAUPTFALL: UVP aus der BERMARO-Cloud-Function-Antwort lesen.
            //    Die Cloud Function liefert ein eigenes Objekt mit success/uvp/...
            //    Wir nehmen NUR den uvp; den VK rechnet der PriceCalculationService.
            if (
                isset($data['success']) &&
                $data['success'] === true &&
                isset($data['uvp']) &&
                is_numeric($data['uvp']) &&
                (float) $data['uvp'] > 0
            ) {
                $uvp = (float) $data['uvp'];

                if ($config->isDebugMode()) {
                    $this->getLogger(self::LOG_KENNUNG)->info(
                        'MirkaBeltCalculator: UVP von Cloud Function uebernommen.',
                        [
                            'uvp'                 => $uvp,
                            'verkaufspreis_cloud' => isset($data['verkaufspreis']) ? $data['verkaufspreis'] : null,
                            'mirkaProductCode'    => isset($data['mirkaProductCode']) ? $data['mirkaProductCode'] : null,
                        ]
                    );
                }

                // NEU v1.6.6: Verpackungseinheit (Baender pro Pack) mitgeben.
                // Die Cloud Function liefert sie als 'beltsPerPack' (z. B. 5, 10).
                // Fehlt der Wert oder ist er ungueltig -> null (dann wird im
                // Positionsnamen einfach keine Pack-Zeile angezeigt).
                $beltsPerPack = null;
                if (isset($data['beltsPerPack']) && is_numeric($data['beltsPerPack'])
                    && (int) $data['beltsPerPack'] > 0) {
                    $beltsPerPack = (int) $data['beltsPerPack'];
                }

                // NEU v1.6.9: Mirka-Artikelnummer und die ECHTEN Mirka-Masse
                // mitgeben. Mirka rastet krumme Laengen auf ein Raster ein
                // (gemessen 27.10.2026: 7190 bis 7210 mm -> alles
                // "150x7200mm", Nummer 444CG00180QP). Geliefert wird das
                // Band laut Produktname - DAS muss im Auftrag stehen.
                $mirkaNr = isset($data['mirkaProductCode']) ? trim((string) $data['mirkaProductCode']) : '';
                $masse   = $this->leseMasseAusProduktname(
                    isset($data['productName']) ? (string) $data['productName'] : ''
                );

                return [
                    'uvp'          => $uvp,
                    'source'       => 'api',
                    'detail'       => 'Mirka-UVP ' . number_format($uvp, 2) . ' EUR',
                    'beltsPerPack' => $beltsPerPack,
                    'mirkaNr'      => $mirkaNr,
                    'mirkaBreite'  => $masse['breite'],
                    'mirkaLaenge'  => $masse['laenge'],
                    'produktName'  => isset($data['productName']) ? (string) $data['productName'] : '',
                ];
            }

            // 6) NOTFALL-RUECKFALL (Guertel + Hosentraeger):
            //    Falls die Cloud Function eines Tages doch die ROHE Mirka-Struktur
            //    durchreichen sollte (Price.Value), fangen wir auch das ab.
            //    Aktuell nicht der Fall, aber schadet nicht.
            if (isset($data['Price']) && is_array($data['Price']) && isset($data['Price']['Value'])) {
                $uvp = (float) $data['Price']['Value'];
                if ($uvp > 0) {
                    if ($config->isDebugMode()) {
                        $this->getLogger(self::LOG_KENNUNG)->info(
                            'MirkaBeltCalculator: UVP aus roher Mirka-Struktur (Rueckfall).',
                            ['uvp' => $uvp]
                        );
                    }
                    return [
                        'uvp'    => $uvp,
                        'source' => 'api',
                        'detail' => 'Mirka-UVP ' . number_format($uvp, 2) . ' EUR (Rueckfall)',
                    ];
                }
            }

            // 7) Cloud Function hat geantwortet, aber ohne gueltigen UVP
            //    (z.B. success=false, ungueltige Maße, Mirka lieferte nichts).
            $cfMessage = '';
            if (isset($data['success']) && $data['success'] === false && isset($data['error'])) {
                $cfMessage = ' (' . (string) $data['error'] . ')';
            }
            $this->getLogger(self::LOG_KENNUNG)->error(
                'MirkaBeltCalculator: Cloud-Function-Antwort enthielt keinen gueltigen uvp.',
                ['body' => $data]
            );
            return [
                'uvp'    => null,
                'source' => 'error',
                'detail' => 'Cloud-Function-Antwort ohne gueltigen uvp' . $cfMessage,
            ];

        } catch (\Throwable $t) {
            $this->getLogger(self::LOG_KENNUNG)->error(
                'MirkaBeltCalculator: Exception beim lib-Aufruf.',
                ['message' => $t->getMessage()]
            );
            return ['uvp' => null, 'source' => 'error', 'detail' => 'Exception: ' . $t->getMessage()];
        }
    }
    /**
     * NEU v1.6.9: Liest Breite und Laenge aus dem Mirka-Produktnamen,
     * z. B. "JEPUFLEX ANTISTATIC 150x7200mm P80, 10/Pack" -> 150 / 7200.
     * Bewusst OHNE regulaere Ausdruecke (nur einfache Textfunktionen,
     * Plenty-Sandbox). Nicht lesbar -> beide Werte 0 (dann bleibt die
     * Eingabe des Kunden stehen - es wird nichts geraten).
     *
     * @param string $name
     * @return array ['breite' => int, 'laenge' => int]
     */
    private function leseMasseAusProduktname($name)
    {
        $leer = ['breite' => 0, 'laenge' => 0];
        $name = (string) $name;
        if ($name === '') {
            return $leer;
        }
        foreach (explode(' ', $name) as $wort) {
            $wort = trim($wort);
            $laengeWort = strlen($wort);
            // Gesucht: <Zahl>x<Zahl>mm
            if ($laengeWort < 6 || substr($wort, $laengeWort - 2) !== 'mm') {
                continue;
            }
            $kern  = substr($wort, 0, $laengeWort - 2);
            $teile = explode('x', $kern);
            if (count($teile) !== 2) {
                continue;
            }
            $b = trim($teile[0]);
            $l = trim($teile[1]);
            if ($b !== '' && $l !== '' && is_numeric($b) && is_numeric($l)
                && (int) $b > 0 && (int) $l > 0
                && (string) (int) $b === $b && (string) (int) $l === $l) {
                return ['breite' => (int) $b, 'laenge' => (int) $l];
            }
        }
        return $leer;
    }
}
