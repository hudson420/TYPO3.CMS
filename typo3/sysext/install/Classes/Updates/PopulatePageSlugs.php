<?php
declare(strict_types = 1);
namespace TYPO3\CMS\Install\Updates;

/*
 * This file is part of the TYPO3 CMS project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 * The TYPO3 project - inspiring people to share!
 */

use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\DataHandling\SlugHelper;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Fills pages.slug with a proper value for pages that do not have a slug updater.
 * Does not take "deleted" pages into account, but respects workspace records.
 *
 * This is how it works:
 * - Check if a page has pages.alias filled.
 * - Check if realurl v1 (tx_realurl_pathcache) or v2 (tx_realurl_pathdata) has a page path, use that instead.
 * - If not -> generate the slug.
 */
class PopulatePageSlugs extends AbstractUpdate
{
    /**
     * The human-readable title of the upgrade wizard
     *
     * @var string
     */
    protected $title = 'Introduce URL parts ("slugs") to all existing pages';

    protected $table = 'pages';

    protected $fieldName = 'slug';

    /**
     * Checks whether updates are required.
     *
     * @param string &$description The description for the update
     * @return bool Whether an update is required (TRUE) or not (FALSE)
     */
    public function checkForUpdate(&$description): bool
    {
        $description = 'TYPO3 includes native URL handling. Every page record has its own speaking URL path ' .
            'called "slug" which can be edited in TYPO3 Backend. However, it is necessary that all pages have
            a URL pre-filled. This is done by evaluating the page title / navigation title and all of its rootline.';

        $updateNeeded = false;

        // Check if the database table even exists
        if ($this->checkIfWizardIsRequired() && !$this->isWizardDone()) {
            $updateNeeded = true;
        }

        return $updateNeeded;
    }

    /**
     * Performs the accordant updates.
     *
     * @param array &$dbQueries Queries done in this update
     * @param string &$customMessage Custom message
     * @return bool Whether everything went smoothly or not
     * @throws \InvalidArgumentException
     */
    public function performUpdate(array &$dbQueries, &$customMessage): bool
    {
        $results = $this->populateSlugs();
        $customMessage .= implode('<br>', $results);
        $this->markWizardAsDone();
        return true;
    }

    /**
     * Fills the database table "pages" with slugs based on the page title and its configuration.
     * But also checks "legacy" functionality.
     */
    protected function populateSlugs(): array
    {
        $connection = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable($this->table);
        $queryBuilder = $connection->createQueryBuilder();
        $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));
        $statement = $queryBuilder
            ->select('*')
            ->from($this->table)
            ->where(
                $queryBuilder->expr()->orX(
                    $queryBuilder->expr()->eq($this->fieldName, $queryBuilder->createNamedParameter('')),
                    $queryBuilder->expr()->isNull($this->fieldName)
                )
            )
            // Ensure that fields with alias are managed first
            ->orderBy('alias', 'desc')
            ->execute();

        // Check for existing slugs from realurl
        $suggestedSlugs = [];
        if ($this->checkIfTableExists('tx_realurl_pathdata')) {
            $suggestedSlugs = $this->getSuggestedSlugs('tx_realurl_pathdata');
        } elseif ($this->checkIfTableExists('tx_realurl_pathcache')) {
            $suggestedSlugs = $this->getSuggestedSlugs('tx_realurl_pathcache');
        }

        $fieldConfig = $GLOBALS['TCA'][$this->table]['columns'][$this->fieldName]['config'];
        $evalInfo = !empty($fieldConfig['eval']) ? GeneralUtility::trimExplode(',', $fieldConfig['eval'], true) : [];
        $hasToBeUniqueInSite = in_array('uniqueInSite', $evalInfo, true);
        $hasToBeUniqueInPid = in_array('uniqueInPid', $evalInfo, true);
        $slugHelper = GeneralUtility::makeInstance(SlugHelper::class, $this->table, $this->fieldName, $fieldConfig);
        $messages = [];
        while ($record = $statement->fetch()) {
            $recordId = (int)$record['uid'];
            $pid = (int)$record['pid'];
            $languageId = (int)$record['sys_language_uid'];
            $pageIdInDefaultLanguage = $languageId > 0 ? (int)$record['l10n_parent'] : $recordId;
            $slug = $suggestedSlugs[$pageIdInDefaultLanguage][$languageId] ?? '';

            // see if an alias field was used, then let's build a slug out of that.
            if (!empty($record['alias'])) {
                $slug = $slugHelper->sanitize('/' . $record['alias']);
            }

            if (empty($slug)) {
                if ($pid === -1) {
                    $queryBuilder = $connection->createQueryBuilder();
                    $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));
                    $liveVersion = $queryBuilder
                        ->select('pid')
                        ->from('pages')
                        ->where(
                            $queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($record['t3ver_oid'], \PDO::PARAM_INT))
                        )->execute()->fetch();
                    $pid = (int)$liveVersion['pid'];
                }
                $slug = $slugHelper->generate($record, $pid);
            }

            if ($hasToBeUniqueInSite && !$slugHelper->isUniqueInSite($slug, $recordId, $pid, $languageId)) {
                $slug = $slugHelper->buildSlugForUniqueInSite($slug, $recordId, $pid, $languageId);
            }
            if ($hasToBeUniqueInPid && !$slugHelper->isUniqueInPid($slug, $recordId, $pid, $languageId)) {
                $slug = $slugHelper->buildSlugForUniqueInPid($slug, $recordId, $pid, $languageId);
            }

            $connection->update(
                $this->table,
                [$this->fieldName => $slug],
                ['uid' => $recordId]
            );
            $messages[] = 'Update record ' . $this->table . ':' . $recordId . ' with slug "' . htmlspecialchars($slug) . '"';
        }
        return $messages;
    }

    /**
     * Check if there are record within "pages" database table with an empty "slug" field.
     *
     * @return bool
     * @throws \InvalidArgumentException
     */
    protected function checkIfWizardIsRequired(): bool
    {
        $connectionPool = GeneralUtility::makeInstance(ConnectionPool::class);
        $queryBuilder = $connectionPool->getQueryBuilderForTable($this->table);
        $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));

        $numberOfEntries = $queryBuilder
            ->count('uid')
            ->from($this->table)
            ->where(
                $queryBuilder->expr()->orX(
                    $queryBuilder->expr()->eq($this->fieldName, $queryBuilder->createNamedParameter('')),
                    $queryBuilder->expr()->isNull($this->fieldName)
                )
            )
            ->execute()
            ->fetchColumn();
        return $numberOfEntries > 0;
    }

    /**
     * Resolve prepared realurl "pagepath" for pages
     *
     * @param string $tableName
     * @return array with pageID (default language) and language ID as two-dimensional array containing the page path
     */
    protected function getSuggestedSlugs(string $tableName): array
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable($tableName);
        $statement = $queryBuilder
            ->select('*')
            ->from($tableName)
            ->where(
                $queryBuilder->expr()->eq('mpvar', $queryBuilder->createNamedParameter(''))
            )
            ->execute();
        $suggestedSlugs = [];
        while ($row = $statement->fetch()) {
            $suggestedSlugs[(int)$row['page_id']][(int)$row['language_id']] = '/' . trim($row['pagepath'], '/') . '/';
        }
        return $suggestedSlugs;
    }
}