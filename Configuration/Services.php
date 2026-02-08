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

    $services->load('Cywolf\\SemanticSuggestionSolr\\', '../Classes/*');

    $services->set(Cywolf\SemanticSuggestionSolr\Controller\SuggestionsController::class)
        ->public(true);

    $services->set(Cywolf\SemanticSuggestionSolr\Command\UpdateSimilaritiesCommand::class)
        ->tag('console.command', [
            'command' => 'semantic-suggestion-solr:update-similarities',
            'schedulable' => true,
        ]);
};
