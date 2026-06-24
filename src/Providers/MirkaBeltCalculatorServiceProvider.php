<?php

namespace MirkaBeltCalculator\Providers;

use Plenty\Plugin\ServiceProvider;
use Plenty\Plugin\Log\Loggable;
use Plenty\Plugin\Events\Dispatcher;
use Plenty\Modules\Basket\Events\BasketItem\AfterBasketItemAdd;

/**
 * MirkaBeltCalculatorServiceProvider (v1.1.0)
 *
 * AENDERUNG ggue. v1.0.9:
 * Der Listener haengt jetzt am Event AfterBasketItemAdd statt
 * BeforeBasketItemAdd.
 *
 * Hintergrund (bestaetigt von Plenty/Steve T., Area Engineering Manager,
 * nach Debug in unserem System):
 * Bei BeforeBasketItemAdd waren die Bestelleigenschaften
 * ($basketItem->basketItemOrderParams) LEER - der Code stieg deshalb
 * frueh aus ("Sammelartikel ohne Bestelleigenschaften"). Steve hat
 * empfohlen, stattdessen AfterBasketItemAdd zu verwenden, weil die
 * Bestelleigenschaften zu diesem Zeitpunkt am Artikel haengen.
 *
 * WICHTIG / noch zu verifizieren: Bei AfterBasketItemAdd liegt der Artikel
 * bereits im Warenkorb. Ob useGivenPrice/givenPrice hier den Korbpreis noch
 * ueberschreibt, muss der Test zeigen. Der Listener loggt deshalb
 * ausfuehrlich, was er sieht und tut (siehe BasketItemListener v1.1.0).
 *
 * Logging weiterhin ueber Uebersetzungs-Schluessel (Debug.properties).
 * Architektur unveraendert: keine eigenen Abhaengigkeiten im
 * ServiceProvider; Listener als String registriert; Services im Listener
 * via pluginApp().
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
     * Verbindet den Event-Listener mit dem Event AfterBasketItemAdd.
     */
    public function boot(Dispatcher $eventDispatcher)
    {
        // Log ueber Uebersetzungs-Schluessel (siehe Debug.properties -> boot)
        $this->getLogger(__METHOD__)->info(
            'MirkaBeltCalculator::Debug.boot'
        );

        $eventDispatcher->listen(
            AfterBasketItemAdd::class,
            'MirkaBeltCalculator\\Listeners\\BasketItemListener@handle'
        );
    }
}
