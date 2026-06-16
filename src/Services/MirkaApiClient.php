<?php

namespace MirkaBeltCalculator\Services;

use Plenty\Plugin\Log\Loggable;
use MirkaBeltCalculator\Configs\PluginConfig;

/**
 * MirkaApiClient (v1.0.8)
 *
 * KEIN Constructor mehr. PluginConfig wird in fetchPrice() per pluginApp()
 * geholt. Verwendet GuzzleHttp ueber pluginApp() (natives curl ist im
 * Plenty-Sandbox blockiert).
 */
class MirkaApiClient
{
    use Loggable;

    public function fetchPrice($productGroupCode, $grit, $jointCode, $width, $length)
    {
        /** @var PluginConfig $config */
        $config = pluginApp(PluginConfig::class);

        // ----- Mock-Modus -----
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

        // ----- Echter API-Aufruf -----
        $baseUrl = $config->getProxyUrl();
        if ($baseUrl === '') {
            $this->getLogger(__METHOD__)->error(
                'MirkaBeltCalculator: Keine Proxy-URL konfiguriert.'
            );
            return ['uvp' => null, 'source' => 'error', 'detail' => 'Keine Proxy-URL konfiguriert'];
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
            $client = pluginApp(\GuzzleHttp\Client::class);

            $response = $client->request('GET', $url, [
                'timeout'         => $config->getApiTimeoutSeconds(),
                'connect_timeout' => 5,
                'http_errors'     => false,
                'headers'         => [
                    'Accept'     => 'application/json',
                    'User-Agent' => 'BERMARO-Plenty-Plugin/1.0',
                ],
            ]);

            $httpCode = $response->getStatusCode();
            $body     = (string) $response->getBody();

            if ($httpCode < 200 || $httpCode >= 300) {
                $this->getLogger(__METHOD__)->error(
                    'MirkaBeltCalculator: API-Aufruf fehlgeschlagen.',
                    ['httpCode' => $httpCode]
                );
                return ['uvp' => null, 'source' => 'error', 'detail' => 'HTTP-Fehler ' . $httpCode];
            }

            $data = json_decode($body, true);
            if (!is_array($data)) {
                return ['uvp' => null, 'source' => 'error', 'detail' => 'Ungueltiges JSON'];
            }

            if (isset($data['Price']) && is_array($data['Price']) && isset($data['Price']['Value'])) {
                $uvp = (float) $data['Price']['Value'];
                return [
                    'uvp'    => $uvp,
                    'source' => 'api',
                    'detail' => 'Mirka-UVP ' . number_format($uvp, 2) . ' EUR',
                ];
            }

            return ['uvp' => null, 'source' => 'error', 'detail' => 'Antwort enthielt keinen Price.Value'];

        } catch (\Throwable $t) {
            $this->getLogger(__METHOD__)->error(
                'MirkaBeltCalculator: Exception beim API-Aufruf.',
                ['message' => $t->getMessage()]
            );
            return ['uvp' => null, 'source' => 'error', 'detail' => 'Exception: ' . $t->getMessage()];
        }
    }
}
