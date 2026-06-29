<?php

namespace MirkaBeltCalculator\Services;

use Plenty\Plugin\Log\Loggable;
use Plenty\Modules\Plugin\Libs\Contracts\LibraryCallContract;
use MirkaBeltCalculator\Configs\PluginConfig;

/**
 * MirkaApiClient (v1.3.0)
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
 * WICHTIG: Das Rueckgabe-Format dieser Methode bleibt unveraendert
 * (['uvp' => ..., 'source' => ..., 'detail' => ...]), damit der
 * PriceCalculationService unveraendert weiterlaeuft.
 */
class MirkaApiClient
{
    use Loggable;

    public function fetchPrice($productGroupCode, $grit, $jointCode, $width, $length)
    {
        /** @var PluginConfig $config */
        $config = pluginApp(PluginConfig::class);

        // ----- Mock-Modus (unveraendert) -----
        if ($config->isMockMode()) {
            $mockUvp = $config->getMockUvp();

            if ($config->isDebugMode()) {
                $this->getLogger(__METHOD__)->info(
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
            $this->getLogger(__METHOD__)->error(
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
                $this->getLogger(__METHOD__)->error(
                    'MirkaBeltCalculator: lib-Connector meldete Fehler.',
                    ['error_msg' => $msg]
                );
                return ['uvp' => null, 'source' => 'error', 'detail' => 'lib-Fehler: ' . $msg];
            }

            // 2) Erwartetes Format aus mirka_connector.php pruefen.
            if (!is_array($result) || !isset($result['ok'])) {
                $this->getLogger(__METHOD__)->error(
                    'MirkaBeltCalculator: Unerwartete Antwort vom lib-Connector.'
                );
                return ['uvp' => null, 'source' => 'error', 'detail' => 'Unerwartete lib-Antwort'];
            }

            // 3) HTTP-Fehler (z.B. Cloud Function nicht erreichbar / 4xx / 5xx)
            if ($result['ok'] !== true) {
                $httpCode = isset($result['httpCode']) ? $result['httpCode'] : 0;
                $connErr  = isset($result['error']) ? (' / ' . $result['error']) : '';
                $this->getLogger(__METHOD__)->error(
                    'MirkaBeltCalculator: HTTP-Aufruf fehlgeschlagen.',
                    ['httpCode' => $httpCode, 'connError' => $connErr]
                );
                return ['uvp' => null, 'source' => 'error', 'detail' => 'HTTP-Fehler ' . $httpCode . $connErr];
            }

            // 4) Body (JSON-String der Cloud Function) auswerten.
            $body = isset($result['body']) ? (string) $result['body'] : '';
            $data = json_decode($body, true);

            if (!is_array($data)) {
                $this->getLogger(__METHOD__)->error(
                    'MirkaBeltCalculator: Ungueltiges JSON von der Cloud Function.'
                );
                return ['uvp' => null, 'source' => 'error', 'detail' => 'Ungueltiges JSON'];
            }

            // 5) UVP aus der Mirka-Antwortstruktur lesen: Price.Value
            //    (Siehe API-Doku: Price ist ein Objekt mit Value/CurrencyIso/...)
            if (isset($data['Price']) && is_array($data['Price']) && isset($data['Price']['Value'])) {
                $uvp = (float) $data['Price']['Value'];

                if ($config->isDebugMode()) {
                    $this->getLogger(__METHOD__)->info(
                        'MirkaBeltCalculator: UVP von API erhalten.',
                        ['uvp' => $uvp]
                    );
                }

                return [
                    'uvp'    => $uvp,
                    'source' => 'api',
                    'detail' => 'Mirka-UVP ' . number_format($uvp, 2) . ' EUR',
                ];
            }

            $this->getLogger(__METHOD__)->error(
                'MirkaBeltCalculator: Antwort enthielt keinen Price.Value.'
            );
            return ['uvp' => null, 'source' => 'error', 'detail' => 'Antwort enthielt keinen Price.Value'];

        } catch (\Throwable $t) {
            $this->getLogger(__METHOD__)->error(
                'MirkaBeltCalculator: Exception beim lib-Aufruf.',
                ['message' => $t->getMessage()]
            );
            return ['uvp' => null, 'source' => 'error', 'detail' => 'Exception: ' . $t->getMessage()];
        }
    }
}
