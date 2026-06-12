<?php

namespace MirkaBeltCalculator\Providers;

use Plenty\Plugin\ServiceProvider;
use Plenty\Plugin\Events\Dispatcher;
use Plenty\Modules\Basket\Events\BasketItem\BeforeBasketItemAdd;
use MirkaBeltCalculator\Listeners\BasketItemListener;

/**
 * MirkaBeltCalculatorServiceProvider
 *
 * Registriert den Listener fuer das BeforeBasketItemAdd-Event.
 * Wenn ein Sammelartikel (Mirka Schleifband konfiguriert) in den Warenkorb
 * gelegt wird, springt der BasketItemListener an und ueberschreibt
 * den Preis mit dem dynamisch berechneten Verkaufspreis.
 */
class MirkaBeltCalculatorServiceProvider extends ServiceProvider
{
    /**
     * Wird beim Laden des Plugins ausgefuehrt.
     */
    public function register()
    {
        // Nichts zu registrieren - alles geschieht im boot().
    }

    /**
     * Wird nach register() ausgefuehrt.
     * Verbindet den Event-Listener mit dem Event.
     */
    public function boot(Dispatcher $eventDispatcher)
    {
        $eventDispatcher->listen(
            BeforeBasketItemAdd::class,
            BasketItemListener::class . '@handle'
        );
    }
}
