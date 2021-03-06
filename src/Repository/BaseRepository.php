<?php
/*
 * This file is part of the Neo4j PHP OGM package.
 *
 * (c) Frédéric Giudicelli https://github.com/giudicelli/
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Neo4j\OGM\Repository;

use Doctrine\Common\Collections\Criteria;
use Neo4j\OGM\Event\NodeCreatedEvent;
use Neo4j\OGM\Event\NodeDeletedEvent;
use Neo4j\OGM\Event\NodeUpdatedEvent;
use Neo4j\OGM\Metadata\ClassMetadata;
use Neo4j\OGM\Model\NodeInterface;
use Neo4j\OGM\NodeManagerInterface;

class BaseRepository implements RepositoryInterface
{
    protected string $className;

    protected ClassMetadata $classMetadata;

    protected NodeManagerInterface $_nm;

    public function __construct(
        NodeManagerInterface $nm,
        string $className
    ) {
        $this->_nm = $nm;
        $this->className = $className;
        $this->classMetadata = $nm->getMetadataCache()->getClassMetadata($className);

        $nm->setRepository($className, $this);
    }

    public function getClassName(): string
    {
        return $this->className;
    }

    public function find(int $id): ?NodeInterface
    {
        $cachedNode = $this->_nm->getNodesCache()->get($this->className, $id);
        if ($cachedNode) {
            return $cachedNode;
        }

        return $this->findOneBy(['id()' => $id]);
    }

    public function findAll(): ?array
    {
        return $this->findBy([]);
    }

    public function findBy(array $filters, array $orderBy = null, $limit = null, $offset = null): ?array
    {
        $criteria = $this->buildCriteria($filters, $orderBy, $offset, $limit);

        $identifier = $this->getIdentifier();
        $stmt = $this->_nm->getQueryBuilder()->getSearchQuery($this->className, $identifier, $criteria);
        $result = $this->_nm->getClient()->runStatement($stmt);

        return $this->hydrateEntities($identifier, $result);
    }

    public function findByQuery(string $identifier, string $query, array $params, array $orderBy = null, $limit = null, $offset = null): ?array
    {
        $query .= $this->_nm->getQueryBuilder()->getCustomSearchQuery($this->className, $identifier, $params, $orderBy, $limit, $offset);
        $result = $this->_nm->getClient()->run($query, $params);

        return $this->hydrateEntities($identifier, $result);
    }

    public function findOneBy(array $filters, array $orderBy = null): ?NodeInterface
    {
        $criteria = $this->buildCriteria($filters, $orderBy, null, 1);

        $identifier = $this->getIdentifier();
        $stmt = $this->_nm->getQueryBuilder()->getSearchQuery($this->className, $identifier, $criteria);
        $result = $this->_nm->getClient()->runStatement($stmt);
        if (count($result) > 1) {
            throw new \LogicException(sprintf('Expected only 1 record, got %d', count($result)));
        }
        if (!count($result)) {
            return null;
        }
        $entities = $this->hydrateEntities($identifier, $result);

        return !empty($entities) ? $entities[0] : null;
    }

    public function findOneByQuery(string $identifier, string $query, array $params, array $orderBy = null): ?NodeInterface
    {
        $query .= $this->_nm->getQueryBuilder()->getCustomSearchQuery($this->className, $identifier, $params, $orderBy, 1, null);
        $result = $this->_nm->getClient()->run($query, $params);

        if (count($result) > 1) {
            throw new \LogicException(sprintf('Expected only 1 record, got %d', count($result)));
        }
        if (!count($result)) {
            return null;
        }
        $entities = $this->hydrateEntities($identifier, $result);

        return !empty($entities) ? $entities[0] : null;
    }

    public function matching(Criteria $criteria): ?array
    {
        $identifier = $this->getIdentifier();
        $stmt = $this->_nm->getQueryBuilder()->getSearchQuery($this->className, $identifier, $criteria);
        $result = $this->_nm->getClient()->runStatement($stmt);

        return $this->hydrateEntities($identifier, $result);
    }

    public function save(NodeInterface $node): int
    {
        $identifier = $this->getIdentifier();
        $id = $this->classMetadata->getIdValue($node);
        $insert = null === $id;

        $stmt = $insert ?
            $this->_nm->getQueryBuilder()->getCreateQuery($node, $identifier)
            :
            $this->_nm->getQueryBuilder()->getUpdateQuery($node, $identifier);

        if (null === $stmt) {
            return 0;
        }

        $result = $this->_nm->getClient()->runStatement($stmt);
        if (!count($result)) {
            return 0;
        }

        if ($insert) {
            $entry = $result->get(0);
            if (!$entry instanceof \Ds\Map) {
                throw new \RuntimeException('Failed to handle inserted node: unexpected value');
            }

            try {
                $id = $entry->get($identifier.'_id');
            } catch (\Throwable $e) {
                throw new \RuntimeException('Failed to handle inserted node: unexpected value');
            }
            $this->classMetadata->setIdValue($node, $id);
            $this->_nm->getEventDispatcher()->dispatch(new NodeCreatedEvent($node));
        } else {
            $this->_nm->getEventDispatcher()->dispatch(new NodeUpdatedEvent($node));
        }

        $this->_nm->getNodesCache()->put($this->className, $id, $node);

        return count($result);
    }

    public function delete(NodeInterface $node): int
    {
        $id = $this->classMetadata->getIdValue($node);
        if (null === $id) {
            return 0;
        }

        $this->_nm->getNodesCache()->remove($this->className, $id);

        $identifier = $this->getIdentifier();

        $stmt = $this->_nm->getQueryBuilder()->getDetachDeleteQuery($node, $identifier);
        $result = $this->_nm->getClient()->runStatement($stmt);

        try {
            if (count($result) && $result->first()->get('ctr')) {
                $this->_nm->getEventDispatcher()->dispatch(new NodeDeletedEvent($node));

                return $result->first()->get('ctr');
            }
        } catch (\Throwable $e) {
        }

        return 0;
    }

    public function refresh(NodeInterface $node): void
    {
        $this->reload($node);
        $this->_nm->getEventDispatcher()->dispatch(new NodeUpdatedEvent($node));
    }

    public function reload(NodeInterface $node): void
    {
        $identifier = $this->getIdentifier();
        $id = $this->classMetadata->getIdValue($node);
        if (null === $id) {
            return;
        }

        $criteria = $this->buildCriteria(['id()' => $id], null, null, 1);

        $stmt = $this->_nm->getQueryBuilder()->getSearchQuery($this->className, $identifier, $criteria);
        $result = $this->_nm->getClient()->runStatement($stmt);
        if (1 !== count($result)) {
            throw new \LogicException(sprintf('Expected only 1 record, got %d', count($result)));
        }
        $values = $result->first()->get($identifier.'_value');
        $this->_nm->getHydrator()->popuplate($this->_nm, $node, $values);
    }

    public function count(array $filters): int
    {
        $criteria = $this->buildCriteria($filters);

        $identifier = $this->getIdentifier();
        $stmt = $this->_nm->getQueryBuilder()->getCountQuery($this->className, $identifier, $criteria);
        $result = $this->_nm->getClient()->runStatement($stmt);
        if (1 !== count($result)) {
            return 0;
        }

        try {
            return $result->first()->get($identifier.'_value');
        } catch (\Throwable $e) {
            return 0;
        }
    }

    protected function buildCriteria(array $filters, ?array $orderings = null, $firstResult = null, $maxResults = null): Criteria
    {
        $expressionBuilder = Criteria::expr();
        $criteria = new Criteria(null, $orderings, $firstResult, $maxResults);
        foreach ($filters as $field => $value) {
            $criteria->andWhere($expressionBuilder->eq($field, $value));
        }

        return $criteria;
    }

    protected function getNodeManager(): NodeManagerInterface
    {
        return $this->_nm;
    }

    protected function getIdentifier(): string
    {
        return $this->classMetadata->getNodeIdentifier();
    }

    /** @return NodeInterface[] */
    protected function hydrateEntities(string $identifier, \Ds\Vector $entries): array
    {
        $entities = [];
        foreach ($entries as $entry) {
            $node = new $this->className();
            $values = $entry->get($identifier.'_value');
            $this->_nm->getHydrator()->popuplate($this->_nm, $node, $values);
            $entities[] = $node;
        }

        return $entities;
    }
}
