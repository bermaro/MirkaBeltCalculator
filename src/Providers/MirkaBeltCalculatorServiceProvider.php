<?php

namespace MirkaBeltCalculator\Providers;

use Plenty\Plugin\ServiceProvider;
use Plenty\Plugin\Log\Loggable;
use Plenty\Plugin\Events\Dispatcher;
use Plenty\Modules\Basket\Events\BasketItem\AfterBasketItemAdd;
use Plenty\Modules\Order\Events\OrderCreated;

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
     * Wird beim Laden des Plugins ausgefuehrt.
     */
    public function register()
    {
        // Log ueber Uebersetzungs-Schluessel (siehe Debug.properties -> register)
        $this->getLogger(__METHOD__)->info(
            'MirkaBeltCalculator::Debug.register'
        );
    }

    /**
     * Wird nach register() ausgefuehrt.
     * Verbindet die Event-Listener mit ihren Events.
     */
    public function boot(Dispatcher $eventDispatcher)
    {
        // Log ueber Uebersetzungs-Schluessel (siehe Debug.properties -> boot)
        $this->getLogger(__METHOD__)->info(
            'MirkaBeltCalculator::Debug.boot'
        );

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
    }
}
