<?php

namespace MirkaBeltCalculator\Providers;

use Plenty\Plugin\ServiceProvider;
use Plenty\Plugin\Log\Loggable;
use Plenty\Plugin\Events\Dispatcher;
use Plenty\Modules\Basket\Events\BasketItem\BeforeBasketItemAdd;

/**
 * MirkaBeltCalculatorServiceProvider (v1.0.8)
 *
 * WICHTIGE AENDERUNG: Der ServiceProvider hat jetzt KEINE eigenen
 * Abhaengigkeiten mehr. boot() bekommt nur den Dispatcher (Plenty-Core).
 * Der Listener wird als String registriert. Alle Service-Klassen werden
 * NICHT mehr per Constructor-Injection geladen, sondern im Listener selbst
 * via pluginApp() geholt. Das vermeidet eine verschachtelte DI-Kette, die
 * Plenty's Container beim Booten still abbrechen lassen konnte.
 */
class MirkaBeltCalculatorServiceProvider extends ServiceProvider
{
    use Loggable;

    public function register()
    {
        $this->getLogger(__METHOD__)->info(
            'MirkaBeltCalculator: register() OK - Plugin wird geladen.'
        );
    }

    public function boot(Dispatcher $eventDispatcher)
    {
        $this->getLogger(__METHOD__)->info(
            'MirkaBeltCalculator: boot() OK - Listener wird registriert.'
        );

        $eventDispatcher->listen(
            BeforeBasketItemAdd::class,
            'MirkaBeltCalculator\\Listeners\\BasketItemListener@handle'
        );
    }
}
