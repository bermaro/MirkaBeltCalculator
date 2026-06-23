<?php

namespace MirkaBeltCalculator\Providers;

use Plenty\Plugin\ServiceProvider;
use Plenty\Plugin\Log\Loggable;
use Plenty\Plugin\Events\Dispatcher;
use Plenty\Modules\Basket\Events\BasketItem\BeforeBasketItemAdd;

/**
 * MirkaBeltCalculatorServiceProvider (v1.0.9)
 *
 * WICHTIGE AENDERUNG ggue. v1.0.8:
 * Die Log-Eintraege in register() und boot() verwenden jetzt
 * UEBERSETZUNGS-SCHLUESSEL statt freiem Klartext.
 *
 * Hintergrund (bestaetigt von Plenty/Steve T., Area Engineering Manager):
 * Plenty schreibt Log-Eintraege der Stufen info() und debug() NUR dann,
 * wenn (a) das Log-Level fuer das Plugin aktiviert ist UND (b) als Log-Text
 * ein Uebersetzungs-Schluessel uebergeben wird, zu dem eine passende
 * .properties-Datei im Ordner resources/lang/<sprache>/ existiert.
 * Freier Klartext (wie in v1.0.8) wird bei info()/debug() still verschluckt -
 * nur error() erscheint immer. Genau deshalb blieb unser Log bisher leer,
 * obwohl der ServiceProvider moeglicherweise die ganze Zeit gebootet hat.
 *
 * Schluessel-Aufbau:  MirkaBeltCalculator::Debug.register
 *   - "MirkaBeltCalculator" = Plugin-Name (vor dem ::)
 *   - "Debug"               = Dateiname Debug.properties (nach dem ::, vor dem Punkt)
 *   - "register"            = Zeile/Key in der Datei (nach dem Punkt)
 *
 * Vorbild: https://github.com/plentymarkets/plugin-io/blob/stable/resources/lang/en/Debug.properties
 *
 * Architektur bleibt wie v1.0.8: KEINE eigenen Abhaengigkeiten im
 * ServiceProvider. boot() bekommt nur den Dispatcher (Plenty-Core). Der
 * Listener wird als String registriert; alle Service-Klassen werden im
 * Listener selbst via pluginApp() geholt (keine verschachtelte DI-Kette).
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
     * Verbindet den Event-Listener mit dem Event.
     */
    public function boot(Dispatcher $eventDispatcher)
    {
        // Log ueber Uebersetzungs-Schluessel (siehe Debug.properties -> boot)
        $this->getLogger(__METHOD__)->info(
            'MirkaBeltCalculator::Debug.boot'
        );

        $eventDispatcher->listen(
            BeforeBasketItemAdd::class,
            'MirkaBeltCalculator\\Listeners\\BasketItemListener@handle'
        );
    }
}
