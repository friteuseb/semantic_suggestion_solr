# Changelog

Toutes les modifications notables de cette extension sont documentées dans ce fichier.

Le format suit [Keep a Changelog](https://keepachangelog.com/fr/1.1.0/),
et le projet adhère au [versionnage sémantique](https://semver.org/lang/fr/).

## [1.0.0-beta.2] - 2026-06-15

### Modifié

- `composer.json` : suppression du champ `version` figé (les versions sont
  désormais pilotées par les tags git, recommandation Packagist).
- `composer.json` : ajout des métadonnées `keywords`, `homepage` et `support`
  pour le référencement Packagist / TER.

## [1.0.0-beta.1] - 2026-06-15

Première livraison publique (bêta).

### Fonctionnalités

- Suggestions de contenu lié via Solr en deux modes de similarité :
  - **MLT** (More Like This) — similarité lexicale TF-IDF native Solr.
  - **SMLT** (Semantic More Like This) — composant Solr personnalisé combinant
    MLT et recherche vectorielle KNN côté serveur (modes `hybrid`,
    `vector_only`, `mlt_only`), avec fusion pondérée des scores.
- Mode `auto` : choix automatique MLT/SMLT selon `plugin.tx_solr.search.query.type`.
- Suggestions inter-types (pages + news EXT:news), détection automatique du
  contexte (page standard / détail news).
- Gestion multilingue : connexion au core Solr de la langue courante.
- Enrichissement image (FAL) des suggestions.
- Filtrage de pertinence (`minScore`, `minScoreRatio`).
- Visibilité par arbres de pages (`includePageTrees` / `excludePageTrees`).
- Plugin contenu + FlexForm, intégration TypoScript (`lib.semantic_suggestion_solr`).
- Cache des similarités + commande CLI de mise à jour.
- Traductions frontend et backend : EN, FR, DE, ES, IT.

### Notes

- État : `beta`. API et réglages susceptibles d'évoluer avant la 1.0.0 stable.
- Le mode SMLT nécessite le build et le déploiement du JAR du composant Solr
  (`smlt-plugin`) ainsi que l'indexation des embeddings — voir le README.

[1.0.0-beta.2]: https://github.com/friteuseb/semantic_suggestion_solr/releases/tag/v1.0.0-beta.2
[1.0.0-beta.1]: https://github.com/friteuseb/semantic_suggestion_solr/releases/tag/v1.0.0-beta.1
