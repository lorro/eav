<?php
/**
 * Licensed under The GPL-3.0 License
 * For full copyright and license information, please see the LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @since    2.0.0
 * @author   Christopher Castro <chris@quickapps.es>
 * @link     http://www.quickappscms.org
 * @license  http://opensource.org/licenses/gpl-3.0.html GPL-3.0 License
 */
namespace Eav\Model\Behavior;

use Cake\Cache\Cache;
use Cake\Collection\Collection;
use Cake\Collection\CollectionInterface;
use Cake\Datasource\EntityInterface;
use Cake\Error\FatalErrorException;
use Cake\Event\Event;
use Cake\ORM\Behavior;
use Cake\ORM\Entity;
use Cake\ORM\PropertyMarshalInterface;
use Cake\ORM\Query;
use Cake\ORM\Table;
use Cake\ORM\TableRegistry;
use Cake\Utility\Hash;
use Eav\Model\Behavior\EavToolbox;
use Eav\Model\Behavior\QueryScope\QueryScopeInterface;
use Eav\Model\Behavior\QueryScope\SelectScope;
use Eav\Model\Behavior\QueryScope\WhereScope;
use Eav\Model\Entity\CachedColumn;
use \ArrayObject;

/**
 * EAV Behavior.
 *
 * Allows additional columns to be added to tables without altering its physical
 * schema.
 *
 * ### Usage:
 *
 * ```php
 * $this->addBehavior('Eav.Eav');
 * $this->addColumn('user-age', ['type' => 'integer']);
 * ```
 *
 * Using virtual attributes in WHERE clauses:
 *
 * ```php
 * $adults = $this->Users->find()
 *     ->where(['user-age >' => 18])
 *     ->all();
 * ```
 *
 * ### Using EAV Cache:
 *
 * ```php
 * $this->addBehavior('Eav.Eav', [
 *     'cache' => [
 *         'contact_info' => ['user-name', 'user-address'],
 *         'eav_all' => '*',
 *     ],
 * ]);
 * ```
 *
 * Cache all EAV values into a real column named `eav_all`:
 *
 * ```php
 * $this->addBehavior('Eav.Eav', [
 *     'cache' => 'eav_all',
 * ]);
 * ```
 *
 * @link https://github.com/quickapps/docs/blob/2.x/en/developers/field-api.rst
 */
class EavBehavior extends Behavior implements PropertyMarshalInterface
{

    /**
     * Instance of EavToolbox.
     *
     * @var \Eav\Model\Behavior\EavToolbox
     */
    protected $_toolbox = null;

    /**
     * Represents an entity that should be removed from the collection.
     *
     * @var int
     */
    const NULL_ENTITY = -1;

    /**
     * Default configuration.
     *
     * - enabled: Whether this behavior is active or not. Defaults true.
     *
     * - cache: EAV cache feature, see documentation. Defaults false.
     *
     * - hydrator: Callable function responsible of hydrate an entity with its
     *   virtual values, callable receives two arguments: the entity to hydrate and
     *   an array of virtual values, where each virtual value is an array composed
     *   of `property_name` and `value` keys.
     *
     * @var array
     */
    protected $_defaultConfig = [
        'status' => true,
        'cache' => false,
        'hydrator' => null,
        'queryScope' => [
            'Eav\\Model\\Behavior\\QueryScope\\SelectScope',
            'Eav\\Model\\Behavior\\QueryScope\\WhereScope',
            'Eav\\Model\\Behavior\\QueryScope\\OrderScope',
        ],
        'implementedMethods' => [
            'eav' => 'eav',
            'updateEavCache' => 'updateEavCache',
            'addColumn' => 'addColumn',
            'dropColumn' => 'dropColumn',
            'listColumns' => 'listColumns',
        ],
    ];

    /**
     * Query scopes objects to be applied indexed by unique ID.
     *
     * @var array
     */
    protected $_queryScopes = [];

    /**
     * Constructor.
     *
     * @param \Cake\ORM\Table $table The table this behavior is attached to
     * @param array $config Configuration array for this behavior
     */
    public function __construct(Table $table, array $config = [])
    {
        $this->_defaultConfig['hydrator'] = function (EntityInterface $entity, $values) {
            return $this->hydrateEntity($entity, $values);
        };

        $config['priority'] = -999; // EAV above anything else
        $config['cacheMap'] = false; // private config, prevent user modifications
        $this->_toolbox = new EavToolbox($table);
        parent::__construct($table, $config);

        if ($this->config('cache')) {
            $info = $this->config('cache');
            $holders = []; // column => [list of virtual columns]

            if (is_string($info)) {
                $holders[$info] = ['*'];
            } elseif (is_array($info)) {
                foreach ($info as $column => $fields) {
                    if (is_integer($column)) {
                        $holders[$fields] = ['*'];
                    } else {
                        $holders[$column] = ($fields === '*') ? ['*'] : $fields;
                    }
                }
            }

            $this->config('cacheMap', $holders);
        }
    }

    /**
     * Gets/sets EAV status.
     *
     * - TRUE: Enables EAV behavior so virtual columns WILL be fetched from database.
     * - FALSE: Disables EAV behavior so virtual columns WLL NOT be fetched from database.
     *
     * @param bool|null $status EAV status to set, or null to get current state
     * @return void|bool Current status if `$status` is set to null
     */
    public function eav($status = null)
    {
        if ($status === null) {
            return $this->config('status');
        }

        $this->config('status', (bool)$status);
    }

    /**
     * Defines a new virtual-column, or update if already defined.
     *
     * ### Usage:
     *
     * ```php
     * $errors = $this->Users->addColumn('user-age', [
     *     'type' => 'integer',
     *     'bundle' => 'some-bundle-name',
     *     'extra' => [
     *         'option1' => 'value1'
     *     ]
     * ], true);
     *
     * if (empty($errors)) {
     *     // OK
     * } else {
     *     // ERROR
     *     debug($errors);
     * }
     * ```
     *
     * The third argument can be set to FALSE to get a boolean response:
     *
     * ```php
     * $success = $this->Users->addColumn('user-age', [
     *     'type' => 'integer',
     *     'bundle' => 'some-bundle-name',
     *     'extra' => [
     *         'option1' => 'value1'
     *     ]
     * ]);
     *
     * if ($success) {
     *     // OK
     * } else {
     *     // ERROR
     * }
     * ```
     *
     * @param string $name Column name. e.g. `user-age`
     * @param array $options Column configuration options
     * @param bool $errors If set to true will return an array list of errors
     *  instead of boolean response. Defaults to TRUE
     * @return bool|array True on success or array of error messages, depending on
     *  $error argument
     * @throws \Cake\Error\FatalErrorException When provided column name collides
     *  with existing column names. And when an invalid type is provided
     */
    public function addColumn($name, array $options = [], $errors = true)
    {
        if (in_array($name, (array)$this->_table->schema()->columns())) {
            throw new FatalErrorException(__d('eav', 'The column name "{0}" cannot be used as it is already defined in the table "{1}"', $name, $this->_table->alias()));
        }

        $data = $options + [
            'type' => 'string',
            'bundle' => null,
            'searchable' => true,
            'overwrite' => false,
        ];

        $data['type'] = $this->_toolbox->mapType($data['type']);
        if (!in_array($data['type'], EavToolbox::$types)) {
            throw new FatalErrorException(__d('eav', 'The column {0}({1}) could not be created as "{2}" is not a valid type.', $name, $data['type'], $data['type']));
        }

        $data['name'] = $name;
        $data['table_alias'] = $this->_table->table();
        $attr = TableRegistry::get('Eav.EavAttributes')->find()
            ->where([
                'name' => $data['name'],
                'table_alias' => $data['table_alias'],
                'bundle IS' => $data['bundle'],
            ])
            ->limit(1)
            ->first();

        if ($attr && !$data['overwrite']) {
            throw new FatalErrorException(__d('eav', 'Virtual column "{0}" already defined, use the "overwrite" option if you want to change it.', $name));
        }

        if ($attr) {
            $attr = TableRegistry::get('Eav.EavAttributes')->patchEntity($attr, $data);
        } else {
            $attr = TableRegistry::get('Eav.EavAttributes')->newEntity($data);
        }

        $success = (bool)TableRegistry::get('Eav.EavAttributes')->save($attr);
        Cache::clear(false, 'eav_table_attrs');

        if ($errors) {
            return (array)$attr->errors();
        }

        return (bool)$success;
    }

    /**
     * Drops an existing column.
     *
     * @param string $name Name of the column to drop
     * @param string|null $bundle Removes the column within a particular bundle
     * @return bool True on success, false otherwise
     */
    public function dropColumn($name, $bundle = null)
    {
        $attr = TableRegistry::get('Eav.EavAttributes')->find()
            ->where([
                'name' => $name,
                'table_alias' => $this->_table->table(),
                'bundle IS' => $bundle,
            ])
            ->limit(1)
            ->first();

        Cache::clear(false, 'eav_table_attrs');
        if ($attr) {
            return (bool)TableRegistry::get('Eav.EavAttributes')->delete($attr);
        }

        return false;
    }

    /**
     * Gets a list of virtual columns attached to this table.
     *
     * @param string|null $bundle Get attributes within given bundle, or all of them
     *  regardless of the bundle if not provided
     * @return array Columns information indexed by column name
     */
    public function listColumns($bundle = null)
    {
        $columns = [];
        foreach ($this->_toolbox->attributes($bundle) as $name => $attr) {
            $columns[$name] = [
                'id' => $attr->get('id'),
                'bundle' => $attr->get('bundle'),
                'name' => $name,
                'type' => $attr->get('type'),
                'searchable ' => $attr->get('searchable'),
                'extra ' => $attr->get('extra'),
            ];
        }

        return $columns;
    }

    /**
     * Update EAV cache for the specified $entity.
     *
     * @param \Cake\Datasource\EntityInterface $entity The entity to update
     * @return bool Success
     */
    public function updateEavCache(EntityInterface $entity)
    {
        if (!$this->config('cacheMap')) {
            return false;
        }

        $attrsById = [];
        foreach ($this->_toolbox->attributes() as $attr) {
            $attrsById[$attr['id']] = $attr;
        }

        if (empty($attrsById)) {
            return true; // nothing to cache
        }

        $query = TableRegistry::get('Eav.EavValues')
            ->find('all')
            ->where([
                'EavValues.eav_attribute_id IN' => array_keys($attrsById),
                'EavValues.entity_id' => $this->_toolbox->getEntityId($entity),
            ])
            ->toArray();

        $values = [];
        foreach ($query as $v) {
            $type = $attrsById[$v->get('eav_attribute_id')]->get('type');
            $name = $attrsById[$v->get('eav_attribute_id')]->get('name');
            $values[$name] = $this->_toolbox->marshal($v->get("value_{$type}"), $type);
        }

        $toUpdate = [];
        foreach ((array)$this->config('cacheMap') as $column => $fields) {
            $cache = [];
            if (in_array('*', $fields)) {
                $cache = $values;
            } else {
                foreach ($fields as $field) {
                    if (isset($values[$field])) {
                        $cache[$field] = $values[$field];
                    }
                }
            }

            $toUpdate[$column] = (string)serialize(new CachedColumn($cache));
        }

        if (!empty($toUpdate)) {
            $conditions = []; // scope to entity's PK (composed PK supported)
            $keys = $this->_table->primaryKey();
            $keys = !is_array($keys) ? [$keys] : $keys;
            foreach ($keys as $key) {
                // TO-DO: check key exists in entity's visible properties list.
                // Throw an error otherwise as PK MUST be correctly calculated.
                $conditions[$key] = $entity->get($key);
            }

            if (empty($conditions)) {
                return false;
            }

            return (bool)$this->_table->updateAll($toUpdate, $conditions);
        }

        return true;
    }

    /**
     * Attaches virtual properties to entities.
     *
     * This method is also responsible of looking for virtual columns in SELECT and
     * WHERE clauses (if applicable) and properly scope the Query object. Query
     * scoping is performed by the `_scopeQuery()` method.
     *
     * EAV can be enabled or disabled on the fly using `eav` finder option, or
     * `eav()` method. When mixing, `eav` option has the highest priority:
     *
     * ```php
     * $this->Articles->eav(false);
     * $articlesNoVirtual = $this->Articles->find('all');
     * $articlesWithVirtual = $this->Articles->find('all', ['eav' => true]);
     * ```
     *
     * @param \Cake\Event\Event $event The beforeFind event that was triggered
     * @param \Cake\ORM\Query $query The original query to modify
     * @param \ArrayObject $options Additional options given as an array
     * @param bool $primary Whether this find is a primary query or not
     * @return bool|null
     */
    public function beforeFind(Event $event, Query $query, ArrayObject $options, $primary)
    {
        $status = array_key_exists('eav', $options) ? $options['eav'] : $this->config('status');

        if ($status) {
            $options['bundle'] = !isset($options['bundle']) ? null : $options['bundle'];
            $this->_initScopes();

            if (empty($this->_queryScopes['Eav\\Model\\Behavior\\QueryScope\\SelectScope'])) {
                return $query;
            }

            $selectedVirtual = $this->_queryScopes['Eav\\Model\\Behavior\\QueryScope\\SelectScope']->getVirtualColumns($query, $options['bundle']);
            $args = compact('options', 'primary', 'selectedVirtual');
            $query = $this->_scopeQuery($query, $options['bundle']);

            return $query->formatResults(function ($results) use ($args) {
                return $this->_hydrateEntities($results, $args);
            }, Query::PREPEND);
        }
    }

    /**
     * Attach EAV attributes for every entity in the provided result-set.
     *
     * This method iterates over each retrieved entity and invokes the
     * `hydrateEntity()` method. This last should return the altered entity object
     * with all its virtual properties, however if this method returns NULL the
     * entity will be removed from the resulting collection.
     *
     * @param \Cake\Collection\CollectionInterface $entities Set of entities to be
     *  processed
     * @param array $args Contains three keys: "options" and "primary" given to the
     *  originating beforeFind(), and "selectedVirtual", a list of virtual columns
     *  selected in the originating find query
     * @return \Cake\Collection\CollectionInterface New set with altered entities
     */
    protected function _hydrateEntities(CollectionInterface $entities, array $args)
    {
        $values = $this->_prepareSetValues($entities, $args);

        return $entities->map(function ($entity) use ($values) {
            if ($entity instanceof EntityInterface) {
                $entity = $this->_prepareCachedColumns($entity);
                $entityId = $this->_toolbox->getEntityId($entity);
                $entityValues = isset($values[$entityId]) ? $values[$entityId] : [];
                $hydrator = $this->config('hydrator');
                $entity = $hydrator($entity, $entityValues);

                if ($entity === null) {
                    // mark as NULL_ENTITY
                    $entity = self::NULL_ENTITY;
                }
            }

            return $entity;
        })
        ->filter(function ($entity) {
            // remove all entities marked as NULL_ENTITY
            return $entity !== self::NULL_ENTITY;
        });
    }

    /**
     * Hydrates a single entity and returns it.
     *
     * Returning NULL indicates the entity should be removed from the resulting
     * collection.
     *
     * @param \Cake\Datasource\EntityInterface $entity The entity to hydrate
     * @param array $values Holds stored virtual values for this particular entity
     * @return bool|null|\Cake\Datasource\EntityInterface
     */
    public function hydrateEntity(EntityInterface $entity, array $values)
    {
        foreach ($values as $value) {
            if (!$this->_toolbox->propertyExists($entity, $value['property_name'])) {
                $entity->set($value['property_name'], $value['value']);
                $entity->dirty($value['property_name'], false);
            }
        }

        // force cache-columns to be of the proper type as they might be NULL if
        // entity has not been updated yet.
        if ($this->config('cacheMap')) {
            foreach ($this->config('cacheMap') as $column => $fields) {
                if ($this->_toolbox->propertyExists($entity, $column) && !($entity->get($column) instanceof Entity)) {
                    $entity->set($column, new Entity);
                }
            }
        }

        return $entity;
    }

    /**
     * Retrieves all virtual values of all the entities within the given result-set.
     *
     * @param \Cake\Collection\CollectionInterface $entities Set of entities
     * @param array $args Contains two keys: "options" and "primary" given to the
     *  originating beforeFind(), and "selectedVirtual" a list of virtual columns
     *  selected in the originating find query
     * @return array Virtual values indexed by entity ID
     */
    protected function _prepareSetValues(CollectionInterface $entities, array $args)
    {
        $entityIds = $this->_toolbox->extractEntityIds($entities);
        $result = [];

        if (empty($entityIds)) {
            return $result;
        }

        $selectedVirtual = $args['selectedVirtual'];
        $bundle = $args['options']['bundle'];
        $validColumns = array_values($selectedVirtual);
        $validNames = array_intersect($this->_toolbox->getAttributeNames($bundle), $validColumns);
        $attrsById = [];

        foreach ($this->_toolbox->attributes($bundle) as $name => $attr) {
            if (in_array($name, $validNames)) {
                $attrsById[$attr['id']] = $attr;
            }
        }

        if (empty($attrsById)) {
            return $result;
        }

        $fetchedRawValues = TableRegistry::get('Eav.EavValues')
            ->find('all')
            ->bufferResults(false)
            ->where([
                'EavValues.eav_attribute_id IN' => array_keys($attrsById),
                'EavValues.entity_id IN' => $entityIds,
            ])
            ->all()
            ->map(function ($value) use ($attrsById, $selectedVirtual) {
                $attrName = $attrsById[$value->get('eav_attribute_id')]->get('name');
                $attrType = $attrsById[$value->get('eav_attribute_id')]->get('type');
                $alias = array_search($attrName, $selectedVirtual);

                return [
                    'attribute_id' => $value->get('eav_attribute_id'),
                    'entity_id' => $value->get('entity_id'),
                    'property_name' => is_string($alias) ? $alias : $attrName,
                    'property_name_real' => $attrName,
                    'aliased' => is_string($alias),
                    'value' => $this->_toolbox->marshal($value->get("value_{$attrType}"), $attrType),
                ];
            })
            ->groupBy('entity_id')
            ->map(function ($values) {
                return (new Collection($values))->indexBy('attribute_id')->toArray();
            })
            ->toArray();

        $fetchedValues = [];
        foreach ($entityIds as $entityId) {
            $fetchedValues[$entityId] = [];
            $values = !empty($fetchedRawValues[$entityId]) ? $fetchedRawValues[$entityId] : [];

            foreach ($attrsById as $attrId => $attributeInfo) {
                if (isset($values[$attrId])) {
                    $fetchedValues[$entityId][] = $values[$attrId];
                } else {
                    $fetchedValues[$entityId][] = [
                        'attribute_id' => $attrId,
                        'entity_id' => $entityId,
                        'property_name' => $attributeInfo->get('name'),
                        'property_name_real' => $attributeInfo->get('name'),
                        'aliased' => false,
                        'value' => null,
                    ];
                }
            }
        }

        return $fetchedValues;
    }

    /**
     * Triggered before data is converted into entities.
     *
     * Converts incoming POST data to its corresponding types.
     *
     * @param \Cake\Event\Event $event The event that was triggered
     * @param \ArrayObject $data The POST data to be merged with entity
     * @param \ArrayObject $options The options passed to the marshaller
     * @return void
     */
    public function beforeMarshal(Event $event, ArrayObject $data, ArrayObject $options)
    {
        $bundle = !empty($options['bundle']) ? $options['bundle'] : null;
        $attrs = array_keys($this->_toolbox->attributes($bundle));
        foreach ($data as $property => $value) {
            if (!in_array($property, $attrs)) {
                continue;
            }
            $dataType = $this->_toolbox->getType($property);
            $marshaledValue = $this->_toolbox->marshal($value, $dataType);
            $data[$property] = $marshaledValue;
        }
    }

    /**
     * Ensures that virtual properties are included in the marshalling process.
     *
     * @param \Cake\ORM\Marhshaller $marshaller The marhshaller of the table the behavior is attached to.
     * @param array $map The property map being built.
     * @param array $options The options array used in the marshalling call.
     * @return array A map of `[property => callable]` of additional properties to marshal.
     */
    public function buildMarshalMap($marshaller, $map, $options)
    {
        $bundle = !empty($options['bundle']) ? $options['bundle'] : null;
        $attrs = $this->_toolbox->attributes($bundle);
        $map = [];

        foreach ($attrs as $name => $info) {
            $map[$name] = function ($value, $entity) use ($info) {
                return $this->_toolbox->marshal($value, $info['type']);
            };
        }

        return $map;
    }

    /**
     * Save virtual values after an entity's real values were saved.
     *
     * @param \Cake\Event\Event $event The event that was triggered
     * @param \Cake\Datasource\EntityInterface $entity The entity that was saved
     * @param \ArrayObject $options Additional options given as an array
     * @return bool True always
     */
    public function afterSave(Event $event, EntityInterface $entity, ArrayObject $options)
    {
        $valuesTable = TableRegistry::get('Eav.EavValues');
        $result = $valuesTable
            ->connection()
            ->transactional(function () use ($valuesTable, $entity, $options) {
                $attrsById = [];
                $updatedAttrs = [];

                foreach ($this->_toolbox->attributes() as $name => $attr) {
                    if (!$this->_toolbox->propertyExists($entity, $name)) {
                        continue;
                    }
                    $attrsById[$attr->get('id')] = $attr;
                }

                if (empty($attrsById)) {
                    return true; // nothing to do
                }

                $values = $valuesTable
                    ->find()
                    ->where([
                        'eav_attribute_id IN' => array_keys($attrsById),
                        'entity_id' => $this->_toolbox->getEntityId($entity),
                    ]);

                // NOTE: row level locking only supported by MySQL and Postgres.
                $driver = $this->_toolbox->driver($values);
                if (in_array($driver, ['mysql', 'postgres'])) {
                    $values->epilog('FOR UPDATE');
                }

                foreach ($values as $value) {
                    $updatedAttrs[] = $value->get('eav_attribute_id');
                    $info = $attrsById[$value->get('eav_attribute_id')];
                    $type = $this->_toolbox->getType($info->get('name'));

                    $marshaledValue = $this->_toolbox->marshal($entity->get($info->get('name')), $type);
                    $value->set("value_{$type}", $marshaledValue);
                    $entity->set($info->get('name'), $marshaledValue);
                    $valuesTable->save($value);
                }

                foreach ($this->_toolbox->attributes() as $name => $attr) {
                    if (!$this->_toolbox->propertyExists($entity, $name)) {
                        continue;
                    }

                    if (!in_array($attr->get('id'), $updatedAttrs)) {
                        $type = $this->_toolbox->getType($name);
                        $value = $valuesTable->newEntity([
                            'eav_attribute_id' => $attr->get('id'),
                            'entity_id' => $this->_toolbox->getEntityId($entity),
                        ]);

                        $marshaledValue = $this->_toolbox->marshal($entity->get($name), $type);
                        $value->set("value_{$type}", $marshaledValue);
                        $entity->set($name, $marshaledValue);
                        $valuesTable->save($value);
                    }
                }

                if ($this->config('cacheMap')) {
                    $this->updateEavCache($entity);
                }

                return true;
            });

        return $result;
    }

    /**
     * After an entity was removed from database. Here is when EAV values are
     * removed from DB.
     *
     * @param \Cake\Event\Event $event The event that was triggered
     * @param \Cake\Datasource\EntityInterface $entity The entity that was deleted
     * @param \ArrayObject $options Additional options given as an array
     * @throws \Cake\Error\FatalErrorException When using this behavior in non-atomic mode
     * @return void
     */
    public function afterDelete(Event $event, EntityInterface $entity, ArrayObject $options)
    {
        if (!$options['atomic']) {
            throw new FatalErrorException(__d('eav', 'Entities in fieldable tables can only be deleted using transactions. Set [atomic = true]'));
        }

        $valuesToDelete = TableRegistry::get('Eav.EavValues')
            ->find()
            ->contain('EavAttribute')
            ->where([
                'EavAttribute.table_alias' => $this->_table->table(),
                'EavValues.entity_id' => $this->_toolbox->getEntityId($entity),
            ]);

        foreach ($valuesToDelete as $value) {
            TableRegistry::get('Eav.EavValues')->delete($value);
        }
    }

    /**
     * Prepares entity's cache-columns (those defined using `cache` option).
     *
     * @param \Cake\Datasource\EntityInterface $entity The entity to prepare
     * @return \Cake\Datasource\EntityInterfa Modified entity
     */
    protected function _prepareCachedColumns(EntityInterface $entity)
    {
        if ($this->config('cacheMap')) {
            foreach ((array)$this->config('cacheMap') as $column => $fields) {
                if (in_array($column, $entity->visibleProperties())) {
                    $string = $entity->get($column);
                    if ($string == serialize(false) || unserialize($string) !== false) {
                        $entity->set($column, unserialize($string));
                    } else {
                        $entity->set($column, new CachedColumn());
                    }
                }
            }
        }

        return $entity;
    }

    /**
     * Look for virtual columns in some query's clauses.
     *
     * @param \Cake\ORM\Query $query The query to scope
     * @param string|null $bundle Consider attributes only for a specific bundle
     * @return \Cake\ORM\Query The modified query object
     */
    protected function _scopeQuery(Query $query, $bundle = null)
    {
        $this->_initScopes();
        foreach ($this->_queryScopes as $scope) {
            if ($scope instanceof QueryScopeInterface) {
                $query = $scope->scope($query, $bundle);
            }
        }

        return $query;
    }

    /**
     * Initializes the scope objects
     *
     * @return void
     */
    protected function _initScopes()
    {
        foreach ((array)$this->config('queryScope') as $className) {
            if (!empty($this->_queryScopes[$className])) {
                continue;
            }

            if (class_exists($className)) {
                $instance = new $className($this->_table);
                if ($instance instanceof QueryScopeInterface) {
                    $this->_queryScopes[$className] = $instance;
                }
            }
        }
    }
}
