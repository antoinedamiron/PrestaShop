<?php
/**
 * 2007-2018 PrestaShop.
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * https://opensource.org/licenses/OSL-3.0
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@prestashop.com so we can send you a copy immediately.
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade PrestaShop to newer
 * versions in the future. If you wish to customize PrestaShop for your
 * needs please refer to http://www.prestashop.com for more information.
 *
 * @author    PrestaShop SA <contact@prestashop.com>
 * @copyright 2007-2018 PrestaShop SA
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 * International Registered Trademark & Property of PrestaShop SA
 */


namespace PrestaShop\PrestaShop\Core\Grid\Position;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\Statement;
use GuzzleHttp\Exception\ConnectException;

/**
 * Class GridPositionUpdater.
 * @package PrestaShop\PrestaShop\Core\Grid\Position
 */
class DoctrineGridPositionUpdater implements GridPositionUpdaterInterface
{
    /**
     * @var Connection
     */
    private $connection;

    /**
     * @var string
     */
    private $dbPrefix;

    /**
     * GridPositionUpdater constructor.
     * @param Connection $connection
     * @param string     $dbPrefix
     */
    public function __construct(
        Connection $connection,
        $dbPrefix
    ) {
        $this->connection = $connection;
        $this->dbPrefix = $dbPrefix;
    }

    /**
     * @param PositionUpdateInterface $positionUpdate
     *
     * @throws \Doctrine\DBAL\ConnectionException
     * @return array
     */
    public function update(PositionUpdateInterface $positionUpdate)
    {
        $newPositions = $this->getNewPositions($positionUpdate);

        //Sort by new position value
        asort($newPositions);

        return $this->updatePositions($positionUpdate->getPositionDefinition(), $newPositions);
    }

    /**
     * @param PositionDefinitionInterface $positionDefinition
     * @param array $newPositions
     *
     * @throws \Doctrine\DBAL\ConnectionException
     * @return array
     */
    private function updatePositions(PositionDefinitionInterface $positionDefinition, array $newPositions)
    {
        $errors = [];
        try {
            $this->connection->beginTransaction();
            $positionIndex = 0;
            foreach ($newPositions as $rowId => $newPosition) {
                $qb = $this->connection->createQueryBuilder();
                $qb
                    ->update($this->dbPrefix . $positionDefinition->getTable())
                    ->set($positionDefinition->getPositionField(), ':position')
                    ->andWhere($positionDefinition->getIdField().' = :rowId')
                    ->setParameter('rowId', $rowId)
                    ->setParameter('position', $positionIndex)
                ;

                $statement = $qb->execute();
                if ($statement instanceof Statement && $statement->errorCode()) {
                    $errors[] = [
                        'key' => 'Could not update #%i',
                        'domain' => 'Admin.Catalog.Notification',
                        'parameters' => [$rowId],
                    ];
                }
                $positionIndex++;
            }
            $this->connection->commit();
        } catch (ConnectException $e) {
            $this->connection->rollBack();
        }

        return $errors;
    }

    /**
     * @param PositionUpdateInterface $positionUpdate
     * @return array
     */
    private function getNewPositions(PositionUpdateInterface $positionUpdate)
    {
        $positions = $this->getCurrentPositions($positionUpdate->getParentId(), $positionUpdate->getPositionDefinition());

        /** @var RowUpdateInterface $rowUpdate */
        foreach ($positionUpdate->getRowUpdateCollection() as $rowUpdate) {
            $positions[$rowUpdate->getId()] = $rowUpdate->getNewPosition();
        }

        return $positions;
    }

    /**
     * @param mixed $parentId
     * @param PositionDefinitionInterface $positionDefinition
     *
     * @return array
     */
    private function getCurrentPositions($parentId, PositionDefinitionInterface $positionDefinition)
    {
        $qb = $this->connection->createQueryBuilder();
        $qb
            ->from($this->dbPrefix . $positionDefinition->getParentTable(), 'p')
            ->leftJoin(
                'p',
                $this->dbPrefix . $positionDefinition->getTable(),
                't', 'p.' . $positionDefinition->getParentTableIdField() . ' = ' . 't.' . $positionDefinition->getParentIdField()
            )
            ->select('t.' . $positionDefinition->getIdField() . ', t.' . $positionDefinition->getPositionField())
            ->andWhere('p.' . $positionDefinition->getParentTableIdField() . ' = :parentId')
            ->addOrderBy('t.' . $positionDefinition->getPositionField(), 'ASC')
            ->setParameter('parentId', $parentId)
        ;

        $positions = $qb->execute()->fetchAll();
        $currentPositions = [];
        foreach ($positions as $position) {
            $positionId = $position[$positionDefinition->getIdField()];
            $currentPositions[$positionId] = $position[$positionDefinition->getPositionField()];
        }

        return $currentPositions;
    }
}
