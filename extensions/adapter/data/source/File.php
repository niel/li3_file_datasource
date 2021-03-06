<?php
/**
 * li3_file_datasource: the data source for files
 *
 * @copyright     Copyright 2013, Housni Yakoob (http://koobi.co)
 * @license       http://opensource.org/licenses/bsd-license.php The BSD License
 */

namespace li3_file_datasource\extensions\adapter\data\source;

use SplFileObject;
use DomainException;
use DirectoryIterator;
use lithium\core\Libraries;
use lithium\core\ConfigException;
use lithium\data\model\QueryException;
use lithium\data\entity\Record;
use lithium\data\collection\RecordSet;

class File extends \lithium\data\Source {

	/**
	 * Classes used by `File`.
	 *
	 * @var array
	 */
	protected $_classes = [
		'schema' => 'lithium\data\Schema'
	];

	/**
	 * @var    SplFileObject    Contains the SplFileObject handle.
	 */
	public $file;

	public function __construct(array $config = []) {
		$defaults = [
			'path' => Libraries::get(true, 'resources') . '/file'
		];
		parent::__construct($config + $defaults);
	}

	/**
	 * Checks to see if `path` is a directory and if it is readable/writable,
	 * depending on `$this->_config['options']['mode']`.
	 *
	 * @see    http://www.php.net/manual/en/function.fopen.php
	 */
	public function connect() {
		$this->_isConnected = false;
		$config = $this->_config;

		$path = $config['path'];
		$pathInfo = new DirectoryIterator($path);

		$mode = $config['options']['mode'];
		switch ($mode) {
			case 'r':
				if (!$pathInfo->isReadable()) {
					throw new ConfigException(
						"The path `$path` is not readable"
					);
				}
			break;

			case 'a+':
				if (!$pathInfo->isWritable()) {
					throw new ConfigException(
						"The path `$path` is not writable"
					);
				}
			break;

			default:
				throw new ConfigException(
					"The mode `$mode` is not yet supported"
				);
			break;
		}

		unset($pathInfo);
		$this->_isConnected = true;

		return $this->_isConnected;
	}

	/**
	 * Unsets the file handle.
	 */
	public function disconnect() {
		if ($this->_isConnected) {
			unset($this->file);
			$this->_isConnected = false;
		}
		return true;
	}

	/**
	 * @todo with (array), implement relationships
	 *
	 * @param object $query `lithium\data\model\Query` object
	 * @param array $options
	 * @return object Returns a lithium\data\collection\RecordSet object
	 * @filter This method can be filtered.
	 */
	public function read($query, array $options = []) {
		$model = $options['model'];
		$fields = $model::schema()->fields();
		if (empty($fields)) {
			throw new DomainException(
				"The schema must be defined in the model `$model`."
			);
		}

		$ext    = $this->_config['extension'];
		$mode   = $this->_config['options']['mode'];
		$params = $query->export($this, ['keys' => ['source']]);
		$base   = implode(DIRECTORY_SEPARATOR, [
			$this->_config['path'], $params['source']
		]);
		$this->file = new SplFileObject("{$base}.{$ext}", $mode);

		return $this->_filter(__METHOD__, compact('query', 'options'), function($self, $params) {
			extract($params['options']);
			$names = $model::schema()->names();

			if ($fields) {
				$this->_validateFields($fields, $names);
			}

			if ($order) {
				$orderKeys = array_keys($order);
				$this->_validateFields($orderKeys, $names);
			}

			$records = null;
			foreach ($self->file as $lineNumber => $row) {
				$data = array_combine($names, $row);

				if ($fields) {
					$selected = array_flip(array_intersect($fields, $names));
					$data = array_intersect_key($data, $selected);
				}

				if ($conditions) {
					foreach ($conditions as $key => $condition) {
						if (in_array($data[$key], (array) $condition)) {
							$records[] = $data;
						}
					}
				} else {
					$records[] = $data;
				}
			}

			if ($order) {
				$records = $this->_sort($records, $order);
			}

			if (!$records) {
				return $records;
			}

			if ($limit || ($limit && $page)) {
				$page = $page ? $page : 1;
				$offset = ($page - 1) * $limit;
				$records = array_slice($records, $offset, $limit);
			}

			$data = [];
			foreach ($records as $record) {
				$data[] = new Record(['data' => $record]);
			}
			return new RecordSet(compact('data'));
		});
	}

	/**
	 * Sorts $records based on $order
	 *
	 * @param  array  $records  An array of records
	 * @param  array  $order  An array whose key is the field to sort by and
	 *                        the value is the sort type (ASC|DESC)
	 * @return  array  A sorted $records
	 */
	protected function _sort(array $records, array $order) {
		usort($records, function($a, $b) use ($order) {
			$key = key($order);
			$type = reset($order);
			switch ($type) {
				case 'DESC':
					if (!is_numeric($a[$key])) {
						$result = strcmp($a[$key], $b[$key]);
						if ($result == 0) {
							return $result;
						}

						return $result * -1;
					}

					if ($a[$key] == $b[$key]) {
						return 0;
					}

					if ($a[$key] < $b[$key]) {
						return 1;
					}

					return -1;
				break;

				default:
				case 'ASC':
					if (!is_numeric($a[$key])) {
						return strcmp($a[$key], $b[$key]);
					}

					if ($a[$key] == $b[$key]) {
						return 0;
					}

					if ($a[$key] < $b[$key]) {
						return -1;
					}

					return 1;
				break;
			}
		});

		return $records;
	}

	/**
	 * Checks to see if $fields exists in $names
	 *
	 * Since this plugin expects you to define the schema, when you sepcify
	 * a finders `fields`, `order`, etc clause, it checks to see if the
	 * specified field exists in the defined schema.
	 *
	 * @param  array  $fields  An array of fields to check for
	 * @param  array  $names  An array of the schema fields to check against
	 * @return  boolean  True if fields are valid
	 * @throws  QueryException
	 */
	protected function _validateFields(array $fields, array $names) {
		if ($fields && $unknowns = array_diff($fields, $names)) {
			$unknown = reset($unknowns);
			throw new QueryException("Unknown field '$unknown' in field list");
		}
		return true;
	}

	public function calculation($type, $query, array $options = []) {
		$ext    = $this->_config['extension'];
		$mode   = $this->_config['options']['mode'];
		$params = $query->export($this, ['keys' => ['source']]);
		$base   = implode(DIRECTORY_SEPARATOR, [
			$this->_config['path'], $params['source']
		]);
		$this->file = new SplFileObject("{$base}.{$ext}", $mode);
		$this->file->setFlags($this->_config['options']['flags']);
		switch ($type) {
			case 'count':
				return iterator_count($this->file);
			break;
		}
	}

	/**
	 * Returns the list of files in $this->_config['path']
	 *
	 * @todo    Only return files that comply with the options.mode.
	 * @param string $model The fully-name-spaced class name of the model object making the request.
	 * @return array Returns an array of file which models access.
	 */
	public function sources($class = null) {
		$dir = new DirectoryIterator($this->_config['path']);
		foreach ($dir as $file) {
			print_r($file->getBasename($this->_config['extension']));
			echo '<hr>';
			echo 'TODO::' . print_r(__METHOD__, true);
		}
		die();
	}

	/**
	 * Returns a Schema object populated with the models' schema.
	 *
	 * @param mixed $entity Specifies the table name for which the schema should be returned, or
	 *        the class name of the model object requesting the schema, in which case the model
	 *        class will be queried for the correct table name.
	 * @param array $fields
	 * @param array $meta The meta-information for the model class, which this method may use in
	 *        introspecting the schema.
	 * @return array Returns a `Schema` object describing the given model's schema, where the
	 *         array keys are the available fields, and the values are arrays describing each
	 *         field, containing the following keys:
	 *         - `'type'`: The field type name
	 */
	public function describe($entity, $fields = [], array $meta = []) {
		return $this->invokeMethod('_instance', ['schema', compact('fields')]);
	}
	public function relationship($class, $type, $name, array $options = []) {}
	public function create($query, array $options = []) {}
	public function update($query, array $options = []) {}
	public function delete($query, array $options = []) {}
}

?>