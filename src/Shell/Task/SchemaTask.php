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
namespace Eav\Shell\Task;

use Cake\Console\Shell;
use Cake\Error\FatalErrorException;
use Cake\ORM\TableRegistry;
use Eav\Model\Behavior\EavToolbox;

/**
 * Table schema manipulator task.
 */
class SchemaTask extends Shell
{

    /**
     * Gets the option parser instance and configures it.
     *
     * @return \Cake\Console\ConsoleOptionParser
     */
    public function getOptionParser()
    {
        $parser = parent::getOptionParser();
        $parser
            ->description(__d('eav', 'Select target table'))
            ->addOption('use', [
                'short' => 'u',
                'help' => __d('eav', 'The table alias name. e.g. "User.Users".'),
            ])
            ->addOption('action', [
                'short' => 'a',
                'help' => __d('eav', 'Indicates what you want to do, drop an existing column or add a new one.'),
                'default' => 'add',
                'choices' => ['add', 'drop'],
            ])
            ->addOption('name', [
                'short' => 'n',
                'help' => __d('eav', 'Name of the column to be added or dropped'),
            ])
            ->addOption('type', [
                'short' => 't',
                'help' => __d('eav', 'Type of information for the column being added.'),
                'default' => 'string',
                'choices' => EavToolbox::$types,
            ])
            ->addOption('bundle', [
                'short' => 'b',
                'help' => __d('eav', 'Indicates the column belongs to a bundle name within the table.'),
                'default' => null,
            ])
            ->addOption('searchable', [
                'short' => 's',
                'help' => __d('eav', 'Whether the column being created can be used in SQL WHERE clauses.'),
                'boolean' => true,
                'default' => true,
            ])
            ->addOption('overwrite', [
                'short' => 'o',
                'help' => __d('eav', 'Overwrite column definition if already exists. An exception will be raised when overwrite is disabled and column already exists.'),
                'boolean' => true,
            ]);

        return $parser;
    }

    /**
     * Adds or drops the specified column.
     *
     * @return bool
     */
    public function main()
    {
        $options = (array)$this->params;
        if (empty($options['use'])) {
            $this->err(__d('eav', 'You must indicate a table alias name using the "--use" option. Example: "Articles.Users"'));

            return false;
        }

        try {
            $table = TableRegistry::get($options['use']);
        } catch (\Exception $ex) {
            $table = false;
        }

        if (!$table) {
            $this->err(__d('eav', 'The specified table does not exists.'));

            return false;
        } elseif (!$table->behaviors()->has('Eav')) {
            $this->err(__d('eav', 'The specified table is not using EAV behavior.'));

            return false;
        } elseif (empty($options['name'])) {
            $this->err(__d('eav', 'You must indicate a table column.'));

            return false;
        } elseif (!preg_match('/^[a-z\d\-_]+$/', $options['name'])) {
            $this->err(__d('eav', 'Invalid column name, please use only lowercase letters, numbers, "-" and "_", e.g.: "user_age".'));

            return false;
        }

        $meta = [
            'type' => $options['type'],
            'bundle' => (empty($options['bundle']) ? null : (string)$options['bundle']),
            'searchable' => $options['searchable'],
            'overwrite' => $options['overwrite'],
        ];

        if ($options['action'] == 'drop') {
            return $table->dropColumn($options['name'], $meta['bundle']);
        }

        try {
            $errors = $table->addColumn($options['name'], $meta);
            if (!empty($errors)) {
                foreach ($errors as $message) {
                    $this->err($message);
                }

                return false;
            }

            return true;
        } catch (FatalErrorException $ex) {
            $this->err($ex->getMessage());

            return false;
        }
    }
}
