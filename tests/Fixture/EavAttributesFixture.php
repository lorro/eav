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
namespace Eav\Test\Fixture;

use Cake\TestSuite\Fixture\TestFixture;

class EavAttributesFixture extends TestFixture
{

    /**
     * Table name.
     *
     * @var string
     */
    public $table = 'eav_attributes';

    /**
     * Table columns.
     *
     * @var array
     */
    public $fields = [
        '_constraints' => [
            'primary' => [
                'type' => 'primary',
                'columns' => [0 => 'id'],
                'length' => [],
            ],
        ],
        '_indexes' => [
            'eav_attributes_table_alias_index' => [
                'type' => 'index',
                'columns' => [0 => 'table_alias'],
                'length' => [],
            ],
            'eav_attributes_bundle_index' => [
                'type' => 'index',
                'columns' => [0 => 'bundle'],
                'length' => [],
            ],
            'eav_attributes_name_index' => [
                'type' => 'index',
                'columns' => [0 => 'name'],
                'length' => [],
            ],
        ],
        '_options' => [
            'engine' => 'InnoDB',
            'collation' => 'utf8_unicode_ci',
        ],
        'id' => [
            'type' => 'integer',
            'unsigned' => false,
            'null' => false,
            'default' => null,
            'comment' => '',
            'autoIncrement' => true,
            'precision' => null,
        ],
        'table_alias' => [
            'type' => 'string',
            'length' => 50,
            'null' => false,
            'default' => null,
            'comment' => '',
            'precision' => null,
            'fixed' => null,
        ],
        'bundle' => [
            'type' => 'string',
            'length' => 50,
            'null' => true,
            'default' => null,
            'comment' => '',
            'precision' => null,
            'fixed' => null,
        ],
        'name' => [
            'type' => 'string',
            'length' => 50,
            'null' => false,
            'default' => null,
            'comment' => '',
            'precision' => null,
            'fixed' => null,
        ],
        'type' => [
            'type' => 'string',
            'length' => 10,
            'null' => false,
            'default' => 'varchar',
            'comment' => '',
            'precision' => null,
            'fixed' => null,
        ],
        'searchable' => [
            'type' => 'boolean',
            'length' => null,
            'null' => false,
            'default' => '1',
            'comment' => '',
            'precision' => null,
        ],
        'extra' => [
            'type' => 'text',
            'length' => null,
            'null' => true,
            'default' => null,
            'comment' => '',
            'precision' => null,
        ],
    ];

    /**
     * Table records.
     *
     * @var array
     */
    public $records = [
        [
            'id' => 1,
            'table_alias' => 'dummy',
            'bundle' => null,
            'name' => 'virtual_text',
            'type' => 'text',
            'searchable' => true,
            'extra' => null,
        ],
        [
            'id' => 2,
            'table_alias' => 'dummy',
            'bundle' => null,
            'name' => 'virtual_integer',
            'type' => 'integer',
            'searchable' => true,
            'extra' => null,
        ],
        [
            'id' => 3,
            'table_alias' => 'dummy',
            'bundle' => null,
            'name' => 'virtual_date',
            'type' => 'date',
            'searchable' => true,
            'extra' => null,
        ]
    ];
}
