<?php

namespace MirkaBeltCalculator\Providers;

use Plenty\Plugin\ServiceProvider;
use Plenty\Plugin\Log\Loggable;
use Plenty\Plugin\Events\Dispatcher;
use Plenty\Modules\Basket\Events\BasketItem\AfterBasketItemAdd;

/**
 * MirkaBeltCalculatorServiceProvider (v1.1.1)
 *
 * Identisch zu v1.1.0: Listener haengt am Event AfterBasketItemAdd
 * (statt BeforeBasketItemAdd), weil die Bestelleigenschaften bei "Before"
 * leer waren (bestaetigt von Steve T. nach Debug in unserem System).
 *
 * Logging in register()/boot() weiterhin ueber Uebersetzungs-Schluessel
 * (Debug.properties). Im Listener werden die Diagnose-Logs in v1.1.1 ueber
 * error() geschrieben, damit sie GARANTIERT erscheinen (siehe Listener).
 *
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
