<?php

namespace MirkaBeltCalculator\Providers;

use Plenty\Plugin\ServiceProvider;
use Plenty\Plugin\Events\Dispatcher;
use Plenty\Plugin\Log\Loggable;
use Plenty\Modules\Basket\Events\BasketItem\BeforeBasketItemAdd;
use MirkaBeltCalculator\Listeners\BasketItemListener;

/**
 * MirkaBeltCalculatorServiceProvider
 *
 * Registriert den Listener fuer das BeforeBasketItemAdd-Event.
 * Wenn ein Sammelartikel (Mirka Schleifband konfiguriert) in den Warenkorb
 * gelegt wird, springt der BasketItemListener an und ueberschreibt
 * den Preis mit dem dynamisch berechneten Verkaufspreis.
 *
 * DIAGNOSE (v1.0.3): Es werden bewusst frueh zwei Log-Eintraege geschrieben,
 * um zu beweisen, ob der ServiceProvider ueberhaupt geladen (register) und
 * gestartet (boot) wird. Diese Eintraege erscheinen im Plenty-Log unter der
 * Integration "MirkaBeltCalculator", sobald das Plugin sauber deployed ist.
 */
class MirkaBeltCalculatorServiceProvider extends ServiceProvider
{
    use Loggable;

    /**
     * Wird beim Laden des Plugins ausgefuehrt.
     */
    public function register()
    {
        // DIAGNOSE-Log: beweist, dass register() ueberhaupt aufgerufen wird.
        $this->getLogger(__METHOD__)->info(
            'MirkaBeltCalculator: ServiceProvider register() wurde ausgefuehrt.'
        );
    }

    /**
     * Wird nach register() ausgefuehrt.
     * Verbindet den Event-Listener mit dem Event.
     */
    public function boot(Dispatcher $eventDispatcher)
    {
        // DIAGNOSE-Log: beweist, dass boot() ausgefuehrt wird und der
        // Listener gleich registriert wird.
        $this->getLogger(__METHOD__)->info(
            'MirkaBeltCalculator: ServiceProvider boot() wurde ausgefuehrt - Listener wird jetzt registriert.'
        );

        $eventDispatcher->listen(
            BeforeBasketItemAdd::class,
            BasketItemListener::class . '@handle'
        );
    }
}
