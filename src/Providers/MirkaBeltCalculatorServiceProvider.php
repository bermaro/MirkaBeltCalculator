<?php

namespace MirkaBeltCalculator\Providers;

use Plenty\Plugin\ServiceProvider;
use Plenty\Plugin\Log\Loggable;
use Plenty\Plugin\Events\Dispatcher;
use Plenty\Modules\Basket\Events\BasketItem\AfterBasketItemAdd;
use Plenty\Modules\Order\Events\OrderCreated;
use Plenty\Modules\Webshop\Events\AfterBasketItemToOrderItem;

/**
 * MirkaBeltCalculatorServiceProvider (v1.4.7)
 *
 * NEU v1.4.7: Dritter Event-Listener registriert.
 *   - OrderCreated -> OrderPriceGuardListener (NEU, "Fehlerschutz/fail-closed"):
 *     prueft nach dem Auftrag-Anlegen, ob jede Konfigurator-Position einen
 *     gueltig berechneten Preis hat. Ein Band zum Basispreis der leeren
 *     Variante (oder ohne lesbaren Preis) gilt als Fehlbestellung und wird
 *     - je nach Tab 8 - gemeldet und optional auf einen Sperr-Status gesetzt.
 *     Anlass: Auftrag 328730 lief ohne AfterBasketItemAdd (und damit ohne
 *     Preis) als bezahlte Bestellung durch. Der Fehlerschutz haengt bewusst
 *     an OrderCreated, weil das der EINZIGE Punkt ist, der auch dann laeuft,
 *     wenn AfterBasketItemAdd (z.B. bei Warenkorb-Merge nach Login) ausbleibt.
 *
 * NEU v1.4.0: Zweiter Event-Listener registriert.
 *   - AfterBasketItemAdd -> BasketItemListener (unveraendert):
 *     setzt den berechneten Preis im Warenkorb.
 *   - OrderCreated -> OrderRenameListener:
 *     gibt den Konfigurator-Positionen nach Auftragsanlage sprechende
 *     Namen (Qualitaet, Koernung, Verbindung, Masse, Mirka-Nr.), damit
 *     Auftragsbestaetigung, Rechnung, Lieferschein und Pickliste die
 *     Konfiguration zeigen. Gesteuert ueber die Plugin-Einstellung
 *     "Positionsnamen umschreiben" (Tab 7): off / nur protokollieren / on.
 *
 * Historie: Listener haengt seit v1.1.0 am Event AfterBasketItemAdd
 * (statt BeforeBasketItemAdd), weil die Bestelleigenschaften bei "Before"
 * leer waren (bestaetigt von Steve T. nach Debug in unserem System).
 *
 * Architektur unveraendert: keine eigenen Abhaengigkeiten im
 * ServiceProvider; Listener als String registriert; Services in den
 * Listenern via pluginApp().
 */
class MirkaBeltCalculatorServiceProvider extends ServiceProvider
{
    use Loggable;

    /**
     * NEU v1.5.7: EINE feste Log-Kennung fuer ALLE Mirka-Meldungen.
     * Dadurch reicht im Plenty-Log EIN Filter, um Warenkorb, Umbenenner,
     * Vollstaendigkeits-Guard UND Preis-Guard gemeinsam zu sehen.
     */
    const LOG_KENNUNG = 'MirkaBeltCalculator::MIRKA';

    /**
     * Wird beim Laden des Plugins ausgefuehrt.
     */
    public function register()
    {
    }

    /**
     * Wird nach register() ausgefuehrt.
     * Verbindet die Event-Listener mit ihren Events.
     */
    public function boot(Dispatcher $eventDispatcher)
    {

        // 1) Preis setzen, wenn ein Artikel in den Warenkorb kommt.
        $eventDispatcher->listen(
            AfterBasketItemAdd::class,
            'MirkaBeltCalculator\\Listeners\\BasketItemListener@handle'
        );

        // 2) NEU v1.4.0: Positionsnamen umschreiben, wenn ein Auftrag
        //    angelegt wurde (Verhalten steuert die Einstellung in Tab 7).
        $eventDispatcher->listen(
            OrderCreated::class,
            'MirkaBeltCalculator\\Listeners\\OrderRenameListener@handle'
        );

        // 3) NEU v1.4.7: Fehlerschutz (fail-closed). Prueft nach dem
        //    Auftrag-Anlegen, ob jede Konfigurator-Position gueltig bepreist
        //    ist; meldet/sperrt Fehlbestellungen (Verhalten steuert Tab 8).
        //    Eigener Listener, damit die bewaehrte Umbenenn-Logik unberuehrt
        //    bleibt und der Schutz unabhaengig vom Umbenenn-Modus greift.
        $eventDispatcher->listen(
            OrderCreated::class,
            'MirkaBeltCalculator\\Listeners\\OrderPriceGuardListener@handle'
        );

        // 4) NEU v1.6.0 - STUFE A: REINE MESSUNG basketItemId -> orderItemId.
        //    Dieser Listener aendert NICHTS. Er ist mehrfach abgesichert:
        //      - Vorschau-Schutz als allererste Handlung (getIncompleteStatus)
        //        -> die alte Ausloggen-Schleife kann nicht wieder entstehen,
        //      - laeuft nur unter Debug (Tab 6) -> echte Kunden unberuehrt,
        //      - nur unsere Variante, kein DB-/Schreibzugriff, eine Logzeile.
        //    Siehe BasketToOrderMeasureListener (Klassenkopf) fuer Details.
        $eventDispatcher->listen(
            AfterBasketItemToOrderItem::class,
            'MirkaBeltCalculator\\Listeners\\BasketToOrderMeasureListener@handle'
        );

        // 5) NEU v1.6.3 - STUFE A2: REINE MESSUNG am FERTIGEN Auftrag.
        //    Liest nur (unter Debug): orderId, je Position orderItemId,
        //    references und den echten Inhalt von Feld 81/82/83. Aendert
        //    NICHTS. Zusammen mit orderRowId aus dem BasketToOrderMeasure-
        //    Listener soll das die Bruecke basketItemId -> orderItemId
        //    beweisen. Siehe OrderCreatedMeasureListener (Klassenkopf).
        $eventDispatcher->listen(
            OrderCreated::class,
            'MirkaBeltCalculator\\Listeners\\OrderCreatedMeasureListener@handle'
        );

        // -----------------------------------------------------------
        // ALTE SCHREIBENDE UEBERGANGS-LISTENER: WEITERHIN ABGESCHALTET
        // -----------------------------------------------------------
        //   KLARSTELLUNG (v1.6.0): Das Event AfterBasketItemToOrderItem
        //   IST oben (Punkt 4) registriert - aber NUR fuer den neuen,
        //   REIN LESENDEN BasketToOrderMeasureListener (Stufe A). Die alten
        //   SCHREIBENDEN Listener - BasketToOrderListener (auf
        //   BeforeBasketItemToOrderItem) und AfterBasketToOrderDiagnoseListener
        //   (auf AfterBasketItemToOrderItem) - bleiben NICHT registriert.
        //   Sie haben die Ausloggen-Schleife ausgeloest und werden erst
        //   wieder aktiviert, wenn sie sauber gebaut sind (siehe unten).
        //
        //   GRUND (belegt im Log vom 08.09.2026, 16:16 Uhr):
        //     - Die Ereignisse feuerten zwischen 16:16:16 und 16:16:50
        //       DUTZENDFACH, ohne dass je ein Auftrag entstand. Laut
        //       Plenty-Doku bieten beide getIncompleteStatus() ("preview
        //       status for current event") - sie feuern also auch bei der
        //       Auftrags-VORSCHAU, die der Checkout staendig neu rechnet.
        //       Genau das hat der Listener nie geprueft.
        //     - Bei JEDEM dieser Durchlaeufe lief ein Datenbankzugriff
        //       (findOneById), die JSON-Umwandlung des Warenkorbartikels
        //       samt sechs Unterzeilen und fuenf Logzeilen. In einer
        //       halben Minute also hunderte Zugriffe mitten im Checkout.
        //     - Im selben Zeitraum wechselte die sessionId DREIMAL
        //       (cMvoU2gI... -> AmlWTMsB... -> enVnF98x...) am selben
        //       Warenkorb 51503605. Das ist der Logout, den der Kunde sieht.
        //
        //   Ob diese Listener den Sitzungswechsel VERURSACHEN oder ihn nur
        //   verstaerken, ist NICHT bewiesen. Genau deshalb sind sie jetzt
        //   abgeschaltet: Das ist der einzige saubere Weg, es in einem
        //   Durchgang zu klaeren, und der Shop laeuft dabei wieder so wie
        //   vor dem ganzen Umbau (Preis -> Zettel -> Umbenennen).
        //
        //   Der Code der beiden Listener bleibt im Plugin liegen. Er wird
        //   erst wieder registriert, wenn er richtig gebaut ist:
        //     - getIncompleteStatus() pruefen und bei Vorschau SOFORT
        //       zurueckkehren,
        //     - KEIN Datenbankzugriff im Ereignis,
        //     - hoechstens EINE Logzeile.
        // -----------------------------------------------------------
    }
}
