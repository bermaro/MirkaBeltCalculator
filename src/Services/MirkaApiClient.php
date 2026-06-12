<?php

namespace MirkaBeltCalculator\Services;

use Plenty\Plugin\Log\Loggable;
use MirkaBeltCalculator\Configs\PluginConfig;

/**
 * MirkaApiClient
 *
 * Spricht ueber die konfigurierte Cloud-Function-URL mit der Mirka Belt
 * Calculator API. Verwendet Plenty's GuzzleHttp-Client (NICHT natives curl,
 * das ist in Plenty-Plugins nicht erlaubt).
 *
 * Mock-Modus: Wenn aktiviert, wird kein echter HTTP-Aufruf gemacht,
 * sondern ein fester Mock-UVP zurueckgegeben. Praktisch zum Testen,
 * bevor die Cloud Function eingerichtet ist.
 */
class MirkaApiClient
{
    use Loggable;

    /** @var PluginConfig */
    private $config;

    public function __construct(PluginConfig $config)
    {
        $this->config = $config;
    }

    /**
     * Holt den Brutto-UVP fuer eine konkrete Konfiguration.
     *
     * @return array  ['uvp' => float|null, 'source' => 'mock'|'api'|'error', 'detail' => string]
     */
    public function fetchPrice($productGroupCode, $grit, $jointCode, $width, $length)
    {
        // ----- Mock-Modus: ohne HTTP-Call zurueckgeben -----
        if ($this->config->isMockMode()) {
            $mockUvp = $this->config->getMockUvp();

            if ($this->config->isDebugMode()) {
                $this->getLogger(__METHOD__)->info(
                    'MirkaBeltCalculator: Mock-Modus aktiv - kein API-Aufruf.',
                    [
                        'mockUvp'          => $mockUvp,
                        'productGroupCode' => $productGroupCode,
                        'grit'             => $grit,
                        'jointCode'        => $jointCode,
                        'width'            => $width,
                        'length'           => $length,
                    ]
                );
            }

            return [
                'uvp'    => $mockUvp,
                'source' => 'mock',
                'detail' => 'Mock-UVP ' . number_format($mockUvp, 2) . ' EUR',
            ];
        }

        // ----- Echter API-Aufruf -----
        $baseUrl = $this->config->getProxyUrl();
        if ($baseUrl === '') {
            $this->getLogger(__METHOD__)->error(
                'MirkaBeltCalculator: Keine Proxy-URL konfiguriert - Plugin-Einstellungen pruefen.'
            );
            return [
                'uvp'    => null,
                'source' => 'error',
                'detail' => 'Keine Proxy-URL konfiguriert',
            ];
        }

        $url = $baseUrl
            . '/calculation'
            . '?language=de-de'
            . '&productGroupCode=' . urlencode((string) $productGroupCode)
            . '&width='            . urlencode((string) $width)
            . '&length='           . urlencode((string) $length)
            . '&grit='             . urlencode((string) $grit)
            . '&jointCode='        . urlencode((string) $jointCode);

        try {
            // Plenty bietet GuzzleHttp ueber pluginApp.
            $client = pluginApp(\GuzzleHttp\Client::class);

            $response = $client->request('GET', $url, [
                'timeout'         => $this->config->getApiTimeoutSeconds(),
                'connect_timeout' => 5,
                'http_errors'     => false,
                'headers'         => [
                    'Accept'     => 'application/json',
                    'User-Agent' => 'BERMARO-Plenty-Plugin/1.0',
                ],
            ]);

            $httpCode = $response->getStatusCode();
            $body     = (string) $response->getBody();

            if ($this->config->isDebugMode()) {
                $this->getLogger(__METHOD__)->info(
                    'MirkaBeltCalculator: HTTP-Antwort erhalten.',
                    [
                        'url'        => $url,
                        'httpCode'   => $httpCode,
                        'bodyLength' => strlen($body),
                        'bodyPreview' => substr($body, 0, 300),
                    ]
                );
            }

            if ($httpCode < 200 || $httpCode >= 300) {
                $this->getLogger(__METHOD__)->error(
                    'MirkaBeltCalculator: API-Aufruf fehlgeschlagen.',
                    [
                        'url'      => $url,
                        'httpCode' => $httpCode,
                        'body'     => substr($body, 0, 500),
                    ]
                );
                return [
                    'uvp'    => null,
                    'source' => 'error',
                    'detail' => 'HTTP-Fehler ' . $httpCode,
                ];
            }

            $data = json_decode($body, true);
            if (!is_array($data)) {
                $this->getLogger(__METHOD__)->error(
                    'MirkaBeltCalculator: Antwort war kein JSON.',
                    ['body' => substr($body, 0, 500)]
                );
                return [
                    'uvp'    => null,
                    'source' => 'error',
                    'detail' => 'Ungueltiges JSON',
                ];
            }

            if (
                isset($data['Price']) &&
                is_array($data['Price']) &&
                isset($data['Price']['Value'])
            ) {
                $uvp = (float) $data['Price']['Value'];
                return [
                    'uvp'    => $uvp,
                    'source' => 'api',
                    'detail' => 'Mirka-UVP ' . number_format($uvp, 2) . ' EUR',
                ];
            }

            $this->getLogger(__METHOD__)->warning(
                'MirkaBeltCalculator: Kein Price.Value in der Antwort.',
                ['response' => $data]
            );
            return [
                'uvp'    => null,
                'source' => 'error',
                'detail' => 'Antwort enthielt keinen Price.Value',
            ];

        } catch (\Throwable $t) {
            $this->getLogger(__METHOD__)->error(
                'MirkaBeltCalculator: Exception beim API-Aufruf.',
                [
                    'exception' => get_class($t),
                    'message'   => $t->getMessage(),
                ]
            );
            return [
                'uvp'    => null,
                'source' => 'error',
                'detail' => 'Exception: ' . $t->getMessage(),
            ];
        }
    }
}
