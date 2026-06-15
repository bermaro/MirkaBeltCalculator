<?php

namespace MirkaBeltCalculator\Providers;

use Plenty\Plugin\ServiceProvider;
use Plenty\Plugin\Events\Dispatcher;
use Plenty\Plugin\Log\Loggable;
use Plenty\Plugin\Templates\Twig;
use Plenty\Modules\Basket\Events\BasketItem\BeforeBasketItemAdd;
use MirkaBeltCalculator\Listeners\BasketItemListener;

/**
 * MirkaBeltCalculatorServiceProvider
 *
 * DIAGNOSE-VERSION (v1.0.5):
 *  - Schreibt frueh Log-Eintraege (register + boot).
 *  - Blendet zusaetzlich einen SICHTBAREN Banner im Shop-Frontend ein
 *    ("MirkaBeltCalculator laeuft"), indem es sich in das Plenty-Event
 *    'IO.Resources.Import' bzw. 'LayoutContainer.Header' einklinkt.
 *    Dadurch sieht man OHNE Log und OHNE Warenkorb, ob das Plugin geladen wird.
 *  - Registriert weiterhin den BasketItemListener fuer die Preisberechnung.
 */
class MirkaBeltCalculatorServiceProvider extends ServiceProvider
{
    use Loggable;

    public function register()
    {
        $this->getLogger(__METHOD__)->info(
            'MirkaBeltCalculator: ServiceProvider register() wurde ausgefuehrt.'
        );
    }

    public function boot(Dispatcher $eventDispatcher)
    {
        // 1) Log-Beweis
        $this->getLogger(__METHOD__)->info(
            'MirkaBeltCalculator: ServiceProvider boot() wurde ausgefuehrt - Listener wird jetzt registriert.'
        );

        // 2) SICHTBARER Beweis im Shop: Banner ganz oben im Header einblenden.
        //    Das Event "LayoutContainer.Header" wird vom Ceres/plentyShop LTS
        //    Template gefeuert, wenn der Kopfbereich gerendert wird.
        $eventDispatcher->listen(
            'LayoutContainer.Header',
            function ($container) {
                $container->addContent(
                    '<div style="background:#dd9313;color:#fff;text-align:center;'
                    . 'padding:8px;font-weight:bold;font-size:14px;z-index:99999;">'
                    . '&#10003; MirkaBeltCalculator l&auml;uft (v1.0.5) &mdash; ServiceProvider aktiv'
                    . '</div>'
                );
            }
        );

        // 3) Eigentliche Funktion: Preis-Listener fuer den Warenkorb.
        $eventDispatcher->listen(
            BeforeBasketItemAdd::class,
            BasketItemListener::class . '@handle'
        );
    }
}
