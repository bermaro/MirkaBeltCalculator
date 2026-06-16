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
 * Wenn der Sammelartikel (Mirka Schleifband konfiguriert) in den Warenkorb
 * gelegt wird, springt der BasketItemListener an und ueberschreibt den Preis
 * mit dem dynamisch berechneten Verkaufspreis.
 *
 * Hinweis (v1.0.7): Der frueher eingebaute Header-Banner wurde komplett
 * entfernt. Er nutzte ein nicht abgesichertes Event/Methoden-Konstrukt, das
 * die boot()-Methode vorzeitig abbrechen konnte, bevor der Preis-Listener
 * registriert wurde. boot() macht jetzt nur noch zwei sichere Dinge:
 * einen Log-Eintrag schreiben und den Listener registrieren.
 */
class MirkaBeltCalculatorServiceProvider extends ServiceProvider
{
    use Loggable;

    /**
     * Wird beim Laden des Plugins ausgefuehrt.
     */
    public function register()
    {
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
        $this->getLogger(__METHOD__)->info(
            'MirkaBeltCalculator: ServiceProvider boot() wurde ausgefuehrt - Listener wird jetzt registriert.'
        );

        $eventDispatcher->listen(
            BeforeBasketItemAdd::class,
            BasketItemListener::class . '@handle'
        );
    }
}
