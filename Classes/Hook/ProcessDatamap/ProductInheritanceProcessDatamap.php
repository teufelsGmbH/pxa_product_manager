<?php

declare(strict_types=1);

namespace Pixelant\PxaProductManager\Hook\ProcessDatamap;

use Doctrine\DBAL\FetchMode;
use Pixelant\PxaProductManager\Domain\Repository\ProductRepository;
use Pixelant\PxaProductManager\Utility\DataInheritanceUtility;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Messaging\FlashMessage;
use TYPO3\CMS\Core\Messaging\FlashMessageService;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\MathUtility;

/**
 * Handles data inheritance for products, copying inherited field data from parent to child, etc.
 */
class ProductInheritanceProcessDatamap
{
    protected const TABLE = ProductRepository::TABLE_NAME;

    /**
     * Overlay parent product data as defined by the inherited fields in the ProductType.
     *
     * @param array $fieldArray
     * @param string $table
     * @param $id
     */

    // phpcs:ignore
    public function processDatamap_beforeStart(DataHandler $dataHandler): void
    {
        if (isset($dataHandler->datamap[ProductRepository::TABLE_NAME])) {
            // Make sure product type is correct if product has a parent.
            foreach ($dataHandler->datamap[ProductRepository::TABLE_NAME] as &$record) {
                $this->inheritProductTypeFromParent($record);
            }
        }
    }

    /**
     * Hook to replace NEW01234567890abcdef placeholders in relation index.
     *
     * @param DataHandler $dataHandler
     */

    // phpcs:ignore
    public function processDatamap_afterAllOperations(DataHandler $dataHandler): void
    {
        // Check if any products where saved, then check if it is a parent or a child.
        if (isset($dataHandler->datamap[ProductRepository::TABLE_NAME])) {
            foreach (array_keys($dataHandler->datamap[ProductRepository::TABLE_NAME]) as $identifier) {
                if (MathUtility::canBeInterpretedAsInteger($identifier)) {
                    $productId = (int)$identifier;
                } else {
                    $productId = (int)$dataHandler->substNEWwithIDs[$identifier];
                }
                $inheritStatus = $dataHandler->datamap[ProductRepository::TABLE_NAME][$productId]['is_inherited'] ?? 0;

                if (!empty($productId) && $inheritStatus === 0) {
                    $parentProductId = (int)BackendUtility::getRecord(
                        ProductRepository::TABLE_NAME,
                        $productId,
                        'parent'
                    )['parent'] ?? 0;

                    $children = $this->fetchChildRecordIdentifiers($productId);
                    $recordIsParent = count($children) > 0;
                    $recordIsChild = !empty($parentProductId);

                    // Continue if product is neither a parent or a child.
                    if (!$recordIsParent && !$recordIsChild) {
                        continue;
                    }

                    if ($recordIsChild) {
                        $parentHash = DataInheritanceUtility::calculateInheritanceHash($parentProductId);
                        $childHashes[$productId] = DataInheritanceUtility::calculateInheritanceHash($productId);
                    } elseif ($recordIsParent) {
                        $parentHash = DataInheritanceUtility::calculateInheritanceHash($productId);
                        foreach ($children as $child) {
                            $childHashes[$child] = DataInheritanceUtility::calculateInheritanceHash($child);
                        }
                    }

                    if (!empty($childHashes)) {
                        foreach ($childHashes as $uid => $childHash) {
                            if ($childHash !== $parentHash) {
                                $inheritdData = DataInheritanceUtility::inheritDataFromParent($uid);

                                if (!empty($inheritdData)) {
                                    $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
                                    $dataHandler->start($inheritdData['data'], $inheritdData['cmd']);
                                    $dataHandler->process_datamap();
                                    $dataHandler->process_cmdmap();
                                }

                                if (
                                    !empty($inheritdData)
                                    && isset($inheritdData['data'][ProductRepository::TABLE_NAME])
                                ) {
                                    foreach ($inheritdData['data'][ProductRepository::TABLE_NAME] as $id => $data) {
                                        unset($data['is_inherited']);
                                        $this->generateMessage($id, implode(',', array_keys($data)));
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }
    }

    /**
     * Generate message for BE.
     *
     * @param int $productId
     * @param string $inheritedData
     * @return void
     */
    protected function generateMessage(int $productId, string $inheritedData): void
    {
        $message = GeneralUtility::makeInstance(
            FlashMessage::class,
            sprintf(
                $this->getLanguageService()->sL(
                    'LLL:EXT:pxa_product_manager/Resources/Private/Language/locallang_be.xlf'
                    . ':formengine.productinheritance.updatedcount'
                ),
                $productId,
                $inheritedData
            ),
            '',
            FlashMessage::INFO,
            true
        );

        /** @var FlashMessageService $flashMessageService */
        $flashMessageService = GeneralUtility::makeInstance(FlashMessageService::class);
        $flashMessageService->getMessageQueueByIdentifier()->enqueue($message);
    }

    /**
     * Make sure products with parent set have same product type as parent.
     *
     * @param array $record
     * @return void
     */
    protected function inheritProductTypeFromParent(array &$record): void
    {
        // If parent is set, fetch product_type from parent instead of relying on child.
        if (!empty($record['parent'])) {
            $parent = (string)array_pop(explode('_', (string)$record['parent'] ?? ''));
            $productRow = BackendUtility::getRecord(
                ProductRepository::TABLE_NAME,
                $parent,
                'product_type'
            );
            if (
                isset($productRow['product_type'])
                && (int)$productRow['product_type'] !== (int)($record['product_type'])
            ) {
                $record['product_type'] = (string)$productRow['product_type'];
            }
        }
    }

    /**
     * Fetch child products.
     *
     * @param $identifier
     * @return array
     */
    protected function fetchChildRecordIdentifiers($identifier): array
    {
        // New records can't have any child records yet.
        if (MathUtility::canBeInterpretedAsInteger($identifier)) {
            /** @var QueryBuilder $queryBuilder */
            $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
                ->getConnectionForTable(ProductRepository::TABLE_NAME)
                ->createQueryBuilder();

            $queryBuilder
                ->getRestrictions()
                ->removeAll()
                ->add(GeneralUtility::makeInstance(DeletedRestriction::class));

            return $queryBuilder
                ->select('uid')
                ->from(ProductRepository::TABLE_NAME)
                ->where($queryBuilder->expr()->eq(
                    'parent',
                    $queryBuilder->createNamedParameter($identifier)
                ))
                ->execute()
                ->fetchAll(FetchMode::COLUMN, 0);
        }

        return [];
    }

    /**
     * Get the LanguageService.
     *
     * @return LanguageService
     */
    protected function getLanguageService()
    {
        return $GLOBALS['LANG'];
    }
}
