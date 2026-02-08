<?php

declare(strict_types=1);

namespace Cywolf\SemanticSuggestionSolr\Command;

use Cywolf\SemanticSuggestionSolr\Service\SimilarityCacheService;
use Cywolf\SemanticSuggestionSolr\Service\SolrMltService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * CLI command that queries Solr for every page in the site tree and persists
 * the similarity results to tx_semanticsuggestion_similarities.
 *
 * This allows page_link_insights to visualize Solr-based semantic links
 * without waiting for frontend visits.
 *
 * Automatically available as a scheduler task in the TYPO3 backend.
 */
#[AsCommand(
    name: 'semantic-suggestion-solr:update-similarities',
    description: 'Query Solr for all pages and persist similarity results for page_link_insights',
)]
class UpdateSimilaritiesCommand extends Command
{
    /** Default settings matching constants.typoscript (mode forced to mlt for CLI context) */
    private const DEFAULT_SETTINGS = [
        'similarityMode' => 'mlt',
        'maxResults' => '6',
        'minTermFreq' => '1',
        'minDocFreq' => '1',
        'mltFields' => 'content,title,keywords',
        'boostFields' => 'content^0.5,title^1.2,keywords^2.0',
        'smltMode' => 'hybrid',
        'smltMltWeight' => '0.3',
        'smltVectorWeight' => '0.7',
        'minScore' => '0',
        'minScoreRatio' => '0',
        'allowedTypes' => '',
        'excludeContentTypes' => '',
        'filterByPids' => '',
    ];

    public function __construct(
        private readonly SolrMltService $solrMltService,
        private readonly SimilarityCacheService $similarityCacheService,
        private readonly SiteFinder $siteFinder,
        private readonly ConnectionPool $connectionPool,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('site', 's', InputOption::VALUE_OPTIONAL, 'Site identifier (default: all sites)')
            ->addOption('language', 'l', InputOption::VALUE_OPTIONAL, 'Language UID', '0')
            ->addOption('mode', 'm', InputOption::VALUE_OPTIONAL, 'Similarity mode: mlt or smlt', 'mlt');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $languageUid = (int)$input->getOption('language');
        $siteIdentifier = $input->getOption('site');
        $mode = $input->getOption('mode');

        if (!in_array($mode, ['mlt', 'smlt'], true)) {
            $io->error('Invalid mode "' . $mode . '". Use mlt or smlt.');
            return Command::FAILURE;
        }

        $settings = self::DEFAULT_SETTINGS;
        $settings['similarityMode'] = $mode;

        if ($siteIdentifier !== null) {
            try {
                $sites = [$this->siteFinder->getSiteByIdentifier($siteIdentifier)];
            } catch (\Throwable) {
                $io->error('Site not found: ' . $siteIdentifier);
                return Command::FAILURE;
            }
        } else {
            $sites = $this->siteFinder->getAllSites();
        }

        if (empty($sites)) {
            $io->warning('No sites configured.');
            return Command::SUCCESS;
        }

        $totalUpdated = 0;
        $totalErrors = 0;

        foreach ($sites as $site) {
            $rootPageId = $site->getRootPageId();
            $io->section(sprintf('Site "%s" (root page %d)', $site->getIdentifier(), $rootPageId));

            $pageUids = $this->getAllPageUids($rootPageId);
            $count = count($pageUids);
            $io->text($count . ' pages found.');

            if ($count === 0) {
                continue;
            }

            $progressBar = $io->createProgressBar($count);
            $progressBar->setFormat(' %current%/%max% [%bar%] %percent:3s%% %elapsed:6s% — page %message%');
            $progressBar->setMessage((string)$pageUids[0]);
            $progressBar->start();

            foreach ($pageUids as $pageUid) {
                $progressBar->setMessage((string)$pageUid);
                try {
                    $suggestions = $this->solrMltService->findSimilar(
                        $pageUid,
                        $settings,
                        $languageUid
                    );
                    $this->similarityCacheService->persist(
                        $pageUid,
                        $rootPageId,
                        $languageUid,
                        $suggestions
                    );
                    $totalUpdated++;
                } catch (\Throwable $e) {
                    $totalErrors++;
                    if ($io->isVerbose()) {
                        $io->newLine();
                        $io->warning(sprintf('Page %d: %s', $pageUid, $e->getMessage()));
                    }
                }
                $progressBar->advance();
            }

            $progressBar->finish();
            $io->newLine(2);
        }

        $io->success(sprintf('Done. %d pages updated, %d errors.', $totalUpdated, $totalErrors));
        return Command::SUCCESS;
    }

    /**
     * Get all non-special page UIDs under the given root (recursive).
     */
    private function getAllPageUids(int $rootPageId): array
    {
        $excludedDokTypes = [254, 255, 199]; // sysfolder, recycler, separator

        $uids = [$rootPageId];
        $toProcess = [$rootPageId];

        while (!empty($toProcess)) {
            $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
            $queryBuilder->getRestrictions()
                ->removeAll()
                ->add(new DeletedRestriction());

            $rows = $queryBuilder
                ->select('uid')
                ->from('pages')
                ->where(
                    $queryBuilder->expr()->in(
                        'pid',
                        $queryBuilder->createNamedParameter($toProcess, Connection::PARAM_INT_ARRAY)
                    ),
                    $queryBuilder->expr()->notIn(
                        'doktype',
                        $queryBuilder->createNamedParameter($excludedDokTypes, Connection::PARAM_INT_ARRAY)
                    ),
                    $queryBuilder->expr()->eq('sys_language_uid', 0)
                )
                ->executeQuery()
                ->fetchAllAssociative();

            $toProcess = [];
            foreach ($rows as $row) {
                $uid = (int)$row['uid'];
                $uids[] = $uid;
                $toProcess[] = $uid;
            }
        }

        return $uids;
    }
}
