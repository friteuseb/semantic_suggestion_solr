<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return function (ContainerConfigurator $configurator, ContainerBuilder $containerBuilder): void {
    $services = $configurator->services()
        ->defaults()
        ->autowire()
        ->autoconfigure()
        ->public(false);

    $services->load('CyrilMarchand\\SemanticSuggestionSolr\\', '../Classes/*');

    $services->set(CyrilMarchand\SemanticSuggestionSolr\Controller\SuggestionsController::class)
        ->public(true);
};
