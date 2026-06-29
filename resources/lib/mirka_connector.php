<?php
/**
 * mirka_connector.php
 *
 * Externer HTTP-Connector fuer das MirkaBeltCalculator-Plugin.
 *
 * WICHTIG (von Plenty so vorgeschrieben):
 *  - Externer Code (Guzzle / echte HTTP-Aufrufe) darf NUR in Dateien im
 *    Ordner resources/lib/ ausgefuehrt werden. NUR hier ist GuzzleHttp\Client
 *    verfuegbar (vorausgesetzt "guzzlehttp/guzzle" steht in plugin.json unter
 *    "dependencies").
 *  - Parameter, die das Plugin uebergibt, werden hier ueber die Helfer-Klasse
 *    SdkRestApi::getParam('name') ausgelesen.
 *  - Die Datei MUSS ein einfaches Array zurueckgeben (kein Objekt, keine Klasse).
 *    Plenty serialisiert das Ergebnis als JSON zurueck an das Plugin.
 *  - Bei einem Fehler im lib-Code liefert Plenty automatisch ein Array mit
 *    'error' => true, 'error_msg' => ... an den Aufrufer zurueck. Trotzdem
 *    fangen wir hier selbst ab und liefern ein sauber strukturiertes Ergebnis.
 *
 * Diese Datei ruft die BERMARO-Cloud-Function (CORS-Proxy) auf, NICHT direkt
 * die Mirka-API. Die Cloud Function spricht intern mit Mirka.
 */

// ----- Parameter, die der Aufrufer (MirkaApiClient) uebergibt -----
$baseUrl         = (string) SdkRestApi::getParam('baseUrl');          // Cloud-Function-URL OHNE / am Ende
$productGroupCode = (string) SdkRestApi::getParam('productGroupCode'); // z.B. "UC0"
$grit            = (string) SdkRestApi::getParam('grit');             // z.B. "40"
$jointCode       = (string) SdkRestApi::getParam('jointCode');        // z.B. "T" / "A" / "B"
$width           = (string) SdkRestApi::getParam('width');            // mm
$length          = (string) SdkRestApi::getParam('length');           // mm
$timeout         = (int)    SdkRestApi::getParam('timeout');          // Sekunden

// Sicherheits-Standard, falls timeout nicht gesetzt wurde
if ($timeout <= 0) {
    $timeout = 15;
}

// ----- URL zusammenbauen -----
// Die Cloud Function erwartet ?action=calculation&... (siehe Uebergabe-Doku).
$url = $baseUrl
    . '?action=calculation'
    . '&language=de-de'
    . '&productGroupCode=' . rawurlencode($productGroupCode)
    . '&width='            . rawurlencode($width)
    . '&length='           . rawurlencode($length)
    . '&grit='             . rawurlencode($grit)
    . '&jointCode='        . rawurlencode($jointCode);

try {
    // Guzzle ist hier im lib-Kontext verfuegbar (Dependency in plugin.json).
    $client = new \GuzzleHttp\Client();

    $response = $client->request('GET', $url, [
        'timeout'         => $timeout,
        'connect_timeout' => 5,
        'http_errors'     => false, // wir werten den Status selbst aus
        'headers'         => [
            'Accept'     => 'application/json',
            'User-Agent' => 'BERMARO-Plenty-Plugin/1.2',
        ],
    ]);

    $httpCode = $response->getStatusCode();
    $body     = (string) $response->getBody();

    // Antwort als reines Array zurueckgeben. KEIN Objekt!
    return [
        'ok'       => ($httpCode >= 200 && $httpCode < 300),
        'httpCode' => $httpCode,
        'body'     => $body,   // roher JSON-String der Cloud Function
        'url'      => $url,    // nur fuer Diagnose-Logs
    ];

} catch (\Throwable $t) {
    // Sauber strukturierter Fehler, damit der Aufrufer ihn loggen kann.
    return [
        'ok'       => false,
        'httpCode' => 0,
        'body'     => '',
        'url'      => $url,
        'error'    => $t->getMessage(),
    ];
}
