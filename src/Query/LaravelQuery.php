<?php

namespace AlgoWeb\PODataLaravel\Query;

use POData\Providers\Metadata\ResourceProperty;
use POData\Providers\Metadata\ResourceSet;
use POData\UriProcessor\QueryProcessor\Expression\Parser\IExpressionProvider;
use POData\UriProcessor\QueryProcessor\ExpressionParser\FilterInfo;
use POData\UriProcessor\ResourcePathProcessor\SegmentParser\KeyDescriptor;
use POData\Providers\Query\IQueryProvider;
use POData\Providers\Expression\MySQLExpressionProvider;
use POData\Providers\Query\QueryType;
use POData\Providers\Query\QueryResult;
use POData\Providers\Expression\PHPExpressionProvider;
use AlgoWeb\PODataLaravel\Interfaces\AuthInterface;
use AlgoWeb\PODataLaravel\Auth\NullAuthProvider;
use Illuminate\Support\Facades\DB;

class LaravelQuery implements IQueryProvider
{
    protected $expression;
    protected $auth;
    public $queryProviderClassName;

    public function __construct(AuthInterface $auth = null)
    {
        /* MySQLExpressionProvider();*/
        $this->expression = new LaravelExpressionProvider(); //PHPExpressionProvider('expression');
        $this->queryProviderClassName = get_class($this);
        $this->auth = isset($auth) ? $auth : new NullAuthProvider();
    }

    /**
     * Indicates if the QueryProvider can handle ordered paging, this means respecting order, skip, and top parameters
     * If the query provider can not handle ordered paging, it must return the entire result set and POData will
     * perform the ordering and paging
     *
     * @return Boolean True if the query provider can handle ordered paging, false if POData should perform the paging
     */
    public function handlesOrderedPaging()
    {
        return true;
    }

    /**
     * Gets the expression provider used by to compile OData expressions into expression used by this query provider.
     *
     * @return \POData\Providers\Expression\IExpressionProvider
     */
    public function getExpressionProvider()
    {
        return $this->expression;
    }

    /**
     * Gets collection of entities belongs to an entity set
     * IE: http://host/EntitySet
     *  http://host/EntitySet?$skip=10&$top=5&filter=Prop gt Value
     *
     * @param QueryType $queryType indicates if this is a query for a count, entities, or entities with a count
     * @param ResourceSet $resourceSet The entity set containing the entities to fetch
     * @param FilterInfo $filterInfo represents the $filter parameter of the OData query.  NULL if no $filter specified
     * @param mixed $orderBy sorted order if we want to get the data in some specific order
     * @param int $top number of records which  need to be skip
     * @param String $skipToken value indicating what records to skip
     *
     * @return QueryResult
     */
    public function getResourceSet(
        QueryType $queryType,
        ResourceSet $resourceSet,
        $filterInfo = null,
        $orderBy = null,
        $top = null,
        $skipToken = null,
        $sourceEntityInstance = null
    ) {
        if ($resourceSet == null && $sourceEntityInstance == null) {
            throw new \Exception('Must supply at least one of a resource set and source entity');
        }
        if ($sourceEntityInstance == null) {
            $sourceEntityInstance = $this->getSourceEntityInstance($resourceSet);
        }

        $result          = new QueryResult();
        $result->results = null;
        $result->count   = null;

        if (isset($orderBy) && null != $orderBy) {
            foreach ($orderBy->getOrderByInfo()->getOrderByPathSegments() as $order) {
                foreach ($order->getSubPathSegments() as $subOrder) {
                    $sourceEntityInstance = $sourceEntityInstance->orderBy(
                        $subOrder->getName(),
                        $order->isAscending() ? 'asc' : 'desc'
                    );
                }
            }
        }
        if (isset($skipToken)) {
            $sourceEntityInstance = $sourceEntityInstance->skip($skipToken);
        }
        if (isset($top)) {
            $sourceEntityInstance = $sourceEntityInstance->take($top);
        }

        $resultSet = $sourceEntityInstance->get();

        if (isset($filterInfo)) {
            $method = "return ".$filterInfo->getExpressionAsString().";";
            $clln = "$".$resourceSet->getResourceType()->getName();
            $isvalid = create_function($clln, $method);
            $resultSet = $resultSet->filter($isvalid);
        }


        if (QueryType::ENTITIES() == $queryType || QueryType::ENTITIES_WITH_COUNT() == $queryType) {
            $result->results = array();
            foreach ($resultSet as $res) {
                $result->results[] = $res;
            }
        }
        if (QueryType::COUNT() == $queryType || QueryType::ENTITIES_WITH_COUNT() == $queryType) {
            if (is_array($resultSet)) {
                $resultSet = collect($resultSet);
            }
            $result->count = $resultSet->count();
        }
        return $result;
    }
    /**
     * Gets an entity instance from an entity set identified by a key
     * IE: http://host/EntitySet(1L)
     * http://host/EntitySet(KeyA=2L,KeyB='someValue')
     *
     * @param ResourceSet $resourceSet The entity set containing the entity to fetch
     * @param KeyDescriptor $keyDescriptor The key identifying the entity to fetch
     *
     * @return object|null Returns entity instance if found else null
     */
    public function getResourceFromResourceSet(
        ResourceSet $resourceSet,
        KeyDescriptor $keyDescriptor = null
    ) {
        return $this->getResource($resourceSet, $keyDescriptor);
    }

    /**
     * Common method for getResourceFromRelatedResourceSet() and getResourceFromResourceSet()
     * @param ResourceSet|null $resourceSet
     * @param null|KeyDescriptor $keyDescriptor
     */
    protected function getResource(
        $resourceSet,
        $keyDescriptor,
        array $whereCondition = [],
        $sourceEntityInstance = null
    ) {
        if ($resourceSet == null && $sourceEntityInstance == null) {
            throw new \Exception('Must supply at least one of a resource set and source entity');
        }
        if ($sourceEntityInstance == null) {
            $entityClassName = $resourceSet->getResourceType()->getInstanceType()->name;
            $sourceEntityInstance = new $entityClassName();
        }
        if ($keyDescriptor) {
            foreach ($keyDescriptor->getValidatedNamedValues() as $key => $value) {
                $sourceEntityInstance = $sourceEntityInstance->where($key, $value[0]);
            }
        }
        foreach ($whereCondition as $fieldName => $fieldValue) {
            $sourceEntityInstance = $sourceEntityInstance->where($fieldName, $fieldValue);
        }
        $sourceEntityInstance = $sourceEntityInstance->get();
        if (0 == $sourceEntityInstance->count()) {
            return null;
        }
        return $sourceEntityInstance->first();
    }

    /**
     * Get related resource set for a resource
     * IE: http://host/EntitySet(1L)/NavigationPropertyToCollection
     * http://host/EntitySet?$expand=NavigationPropertyToCollection
     *
     * @param QueryType $queryType indicates if this is a query for a count, entities, or entities with a count
     * @param ResourceSet $sourceResourceSet The entity set containing the source entity
     * @param object $sourceEntityInstance The source entity instance.
     * @param ResourceSet $targetResourceSet The resource set of containing the target of the navigation property
     * @param ResourceProperty $targetProperty The navigation property to retrieve
     * @param FilterInfo $filter represents the $filter parameter of the OData query.  NULL if no $filter specified
     * @param mixed $orderBy sorted order if we want to get the data in some specific order
     * @param int $top number of records which  need to be skip
     * @param String $skip value indicating what records to skip
     *
     * @return QueryResult
     *
     */
    public function getRelatedResourceSet(
        QueryType $queryType,
        ResourceSet $sourceResourceSet,
        $sourceEntityInstance,
        ResourceSet $targetResourceSet,
        ResourceProperty $targetProperty,
        $filter = null,
        $orderBy = null,
        $top = null,
        $skip = null
    ) {
        $propertyName = $targetProperty->getName();
        $results = $sourceEntityInstance->$propertyName();

        return $this->getResourceSet(
            $queryType,
            $sourceResourceSet,
            $filter,
            $orderBy,
            $top,
            $skip,
            $results
        );

    }

    /**
     * Gets a related entity instance from an entity set identified by a key
     * IE: http://host/EntitySet(1L)/NavigationPropertyToCollection(33)
     *
     * @param ResourceSet $sourceResourceSet The entity set containing the source entity
     * @param object $sourceEntityInstance The source entity instance.
     * @param ResourceSet $targetResourceSet The entity set containing the entity to fetch
     * @param ResourceProperty $targetProperty The metadata of the target property.
     * @param KeyDescriptor $keyDescriptor The key identifying the entity to fetch
     *
     * @return object|null Returns entity instance if found else null
     */
    public function getResourceFromRelatedResourceSet(
        ResourceSet $sourceResourceSet,
        $sourceEntityInstance,
        ResourceSet $targetResourceSet,
        ResourceProperty $targetProperty,
        KeyDescriptor $keyDescriptor
    ) {
        $propertyName = $targetProperty->getName();
        return $this->getResource(null, $keyDescriptor, [], $sourceEntityInstance->$propertyName);
    }

    /**
     * Get related resource for a resource
     * IE: http://host/EntitySet(1L)/NavigationPropertyToSingleEntity
     * http://host/EntitySet?$expand=NavigationPropertyToSingleEntity
     *
     * @param ResourceSet $sourceResourceSet The entity set containing the source entity
     * @param object $sourceEntityInstance The source entity instance.
     * @param ResourceSet $targetResourceSet The entity set containing the entity pointed to by the navigation property
     * @param ResourceProperty $targetProperty The navigation property to fetch
     *
     * @return object|null The related resource if found else null
     */
    public function getRelatedResourceReference(
        ResourceSet $sourceResourceSet,
        $sourceEntityInstance,
        ResourceSet $targetResourceSet,
        ResourceProperty $targetProperty
    ) {
        $propertyName = $targetProperty->getName();
        return $sourceEntityInstance->$propertyName;
    }

    /**
     * @param ResourceSet $resourceSet
     * @return mixed
     */
    protected function getSourceEntityInstance(ResourceSet $resourceSet)
    {
        $entityClassName = $resourceSet->getResourceType()->getInstanceType()->name;
        $sourceEntityInstance = new $entityClassName();
        return $sourceEntityInstance = $sourceEntityInstance->newQuery();
    }
    
    /**
     * Updates a resource 
     *
     * @param ResourceSet      $sourceResourceSet    The entity set containing the source entity
     * @param object           $sourceEntityInstance The source entity instance
     * @param KeyDescriptor    $keyDescriptor        The key identifying the entity to fetch
     * @param object           $data                 The New data for the entity instance.
     * @param bool             $shouldUpdate        Should undefined values be updated or reset to default
     *
     * @return object|null The new resource value if it is assignable or throw exception for null.
     */
    public function updateResource(
        ResourceSet $sourceResourceSet,
        $sourceEntityInstance,
        KeyDescriptor $keyDescriptor,
        $data,
        $shouldUpdate = false
    ) {
        throw new \POData\Common\NotImplementedException();
    }
    /**
     * Delete resource from a resource set.
     * @param ResourceSet|null $resourceSet
     * @param object           $sourceEntityInstance
     *
     * return bool true if resources sucessfully deteled, otherwise false.
     */
    public function deleteResource(
        ResourceSet $sourceResourceSet,
        $sourceEntityInstance
    ) {
        throw new \POData\Common\NotImplementedException();
    }
    /**
     * @param ResourceSet      $resourceSet   The entity set containing the entity to fetch
     * @param object           $sourceEntityInstance The source entity instance
     * @param object           $data                 The New data for the entity instance.
     *
     * returns object|null returns the newly created model if sucessful or null if model creation failed.
     */
    public function createResourceforResourceSet(
        ResourceSet $resourceSet,
        $sourceEntityInstance,
        $data
    ) {
        throw new \POData\Common\NotImplementedException();
    }
}
