<?php
namespace Gate\Libs\ORM;

class Database {

	const MASTER  = 0;
	const SLAVE   = 1;

	/**
	 * Singleton.
	 */
	public static function getConn($database) {
		static $singletons = array();
		!isset($singletons[$database]) && $singletons[$database] = new Database($database);
		return $singletons[$database];
	}

	/**
	 * Write data into database.
	 *
	 * @param string $sql
	 * @param array $params
	 * @return unknown_type
	 */
	public function write($sql, $params = array()) {
		$sth = $this->prepare($sql, $params, self::MASTER);
		$success = $this->catchError($sth, $sql, $params);
		$this->last_sth = $sth;

		if ($success === FALSE) {
			return FALSE;
		}

		return $this->getAffectedRows();
	}

	/**
	 * Read data from database. 
	 *
	 * @param string $sql SQL query statement
	 * @param array $params bind variable
	 * @param bool $from_master TRUE to query from master and FALSE to query from slave
	 * @param string $hash_key key the result by the specified field
	 */
	public function read($sql, $params = array(), $from_master = FALSE, $hash_key = NULL) {
		$type = $from_master ? self::MASTER : self::SLAVE;
		$sth = $this->prepare($sql, $params, $type);
		$success = $this->catchError($sth, $sql, $params);

		$result = array();
		while ($row = $sth->fetch(PDO::FETCH_ASSOC)) {
			if (isset($hash_key) && !empty($hash_key)) {
				$result[$row[$hash_key]]= $row;
			}
			else {
				$result[]= $row;
			}
		}
		$sth->closeCursor();

		return $result;
	}

	/**
	 * A shortcut method for reading only one entry from database.
	 */
	public function one($sql, $params = array(), $from_master = FALSE) {
		$result = $this->read($sql, $params, $from_master);
		return $result[0];
	}

	/**
	 * Get number of affected rows in previous MySQL operation.
	 */
	public function getAffectedRows() {
		return $this->last_sth->rowCount();
	}

	public function getInsertId() {
		$connection = $this->getConnection(self::MASTER);
		return $connection->lastInsertId();
	}

	public static function quoteSmart($value) {
		return $value;
	}

	/**
	 * QUERY HELPER
	 * Assemble query by QuerySet object.
	 */

	public static function queryGetKeys($query) {
		$model_class = $query->getModel();
		$primary_key = $model_class::_PRIMARY_KEY_;
		return self::queryGetCol($query, $primary_key);
	}

    public static function queryGetCol($query, $field) {
		$model_class = $query->getModel();

		list($sql_table, $sql_query, $sql_order, $sql_limit) = $query->createStatement();
		$sql = sprintf("SELECT %s.%s FROM %s %s %s %s", $sql_table, $field, $sql_table, $sql_query, $sql_order, $sql_limit);
		$result = $model_class::getConn()->read($sql);
		$set = array();
		foreach ($result AS $row) {
			$set[] = $row[$field];
		}
		return $set;
    }

    public static function queryGetCols($query, $fields) {
		$model_class = $query->getModel();

		list($sql_table, $sql_query, $sql_order, $sql_limit) = $query->createStatement();

		$selects = array();
		foreach ($fields AS $field) {
			$selects[] = "{$sql_table}.{$field}";
		}
		$selects = implode(', ', $selects);

		$sql = sprintf("SELECT %s FROM %s %s %s %s", $selects, $sql_table, $sql_query, $sql_order, $sql_limit);
		$result = $model_class::getConn()->read($sql);
		return $result;
    }

	public static function queryGetItems($query) {
		$model_class = $query->getModel();

		list($sql_table, $sql_query, $sql_order, $sql_limit) = $query->createStatement();
		$sql = sprintf("SELECT %s.* FROM %s %s %s %s", $sql_table, $sql_table, $sql_query, $sql_order, $sql_limit);

		return $model_class::getConn()->read($sql);
	}

	public static function queryGetLength($query) {
		$model_class = $query->getModel();

		list($sql_table, $sql_query, $sql_order, $sql_limit) = $query->createStatement();
		$sql = sprintf("SELECT COUNT(1) AS num FROM %s %s", $sql_table, $sql_query);

		$result = $model_class::getConn()->one($sql);
		return $result['num'];
	}

	public static function queryUpdate($query, $args) {
		$model_class = $query->getModel();

		$updates = array();
		foreach ($args AS $field => $value) {
			$value = self::quoteSmart($value);
			if (is_array($value)) {
				$updates[] = "{$field} = {$field} {$value[0]} {$value[1]}";
			}
			else {
				$updates[] = "{$field} = '{$value}'";
			}
		}
		$updates = implode(', ', $updates);

		list($sql_table, $sql_query, $sql_order, $sql_limit) = $query->createStatement();
		$sql = sprintf("UPDATE %s SET %s %s", $sql_table, $updates, $sql_query);
		$result = $model_class::getConn()->write($sql);
		return $query;
	}

	public static function queryIncr($query, $args) {
		$model_class = $query->getModel();

		$updates = array();
		foreach ($args AS $field => $value) {
			$value = self::quoteSmart($value);
			$updates[] = "{$field} = {$field} + {$value}";
		}
		$updates = implode(', ', $updates);

		list($sql_table, $sql_query, $sql_order, $sql_limit) = $query->createStatement();
		$sql = sprintf("UPDATE %s SET %s %s", $sql_table, $updates, $sql_query);
		$result = $model_class::getConn()->write($sql);
		return $query;
	}

	public static function queryDecr($query, $args) {
		$model_class = $query->getModel();

		$updates = array();
		foreach ($args AS $field => $value) {
			$value = self::quoteSmart($value);
			$updates[] = "{$field} = {$field} - {$value}";
		}
		$updates = implode(', ', $updates);

		list($sql_table, $sql_query, $sql_order, $sql_limit) = $query->createStatement();
		$sql = sprintf("UPDATE %s SET %s %s", $sql_table, $updates, $sql_query);
		$result = $model_class::getConn()->write($sql);
		return $query;
	}

	public static function queryDelete($query) {
		$model_class = $query->getModel();

		list($sql_table, $sql_query, $sql_order, $sql_limit) = $query->createStatement();
		$sql = sprintf("DELETE FROM %s %s", $sql_table, $sql_query);

		$result = $model_class::getConn()->write($sql);
		return $result;
	}

	public static function objectLoad($obj) {
		$class = get_class($obj);
		$sql = sprintf('SELECT * FROM %s WHERE %s = :_id', $class::_TABLE_, $class::_PRIMARY_KEY_);
		$params = array('_id' => $obj->id);
		$result = $class::getConn()->one($sql, $params, $from_master);
		return $result;
	}

	public static function objectReplace($obj, $args) {
		$class = get_class($obj);
		$fields = array();
		foreach ($args AS $field => $value) {
			$value = self::quoteSmart($value);
			$fields[] = "{$field} = '{$value}'";
		}
		$fields = implode(', ', $fields);
        $sql = sprintf('REPLACE INTO %s SET %s', $class::_TABLE_, $fields);
        $class::getConn()->write($sql);
    }

	public static function objectSave($obj, $args, $force_insert = FALSE) {
		$class = get_class($obj);
		$fields = array();
		foreach ($args AS $field => $value) {
			$value = self::quoteSmart($value);
			$fields[] = "{$field} = '{$value}'";
		}
		$fields = implode(', ', $fields);

        if (FALSE === strpos($class::_PRIMARY_KEY_, ',')) {
            // single-column primary key
            if (!$force_insert && $obj->id) {
                // update
                $sql = sprintf('UPDATE %s SET %s WHERE %s = :_id', $class::_TABLE_, $fields, $class::_PRIMARY_KEY_);
                $params = array('_id' => $obj->id);
                $class::getConn()->write($sql, $params);
                return $obj->id;
            }
            else {
                // insert
                $sql = sprintf('INSERT IGNORE INTO %s SET %s', $class::_TABLE_, $fields);
                $class::getConn()->write($sql);

                // return insert id
                return $class::getConn()->getInsertId();
            }
        }
        else {
            // multi-column primary key
            // always issue an INSERT and leave the duplication check to
            // lower level engine, i.e. database.
            $sql = sprintf('INSERT INTO %s SET %s', $class::_TABLE_, $fields);
            $class::getConn()->write($sql);

            // make the id
            $fields = explode($class::_PRIMARY_KEY_);
            $id = array();
            foreach ($fields AS $field) {
                $id[$field] = $args[$field];
            }
            return $id;
        }
	}

	public static function objectDelete($obj) {
		$class = get_class($obj);
		$sql = sprintf('DELETE FROM %s WHERE %s = :_id', $class::_TABLE_, $class::_PRIMARY_KEY_);
		$params = array('_id' => $this->id);
		return $class::getConn()->write($sql, $params);
	}

	//////////////
	// privates //
	//////////////

	private $config; 
	private $in_transaction;
	private $last_sth; //@TODO: sort of an ugly hack for retrieving affected_rows

	/**
	 * Store PDO connections for reusing. This is an array holds different
	 * types (MASTER or SLAVE) of connections.
	 *
	 * @var array
	 */
	private $connection = array();

	/**
	 * Private constructor. Load database config.
	 */
	private function __construct($database) {
		$this->config = \IO\Libs\Config::load('MySQL')->$database;
		$this->in_transaction = FALSE;
		$this->last_sth = NULL;
	}

	/**
	 * Get a connection for writing data.
	 *
	 * @param int $type Database::MASTER or Database::SLAVE
	 */
	private function getConnection($type) {

		if (isset($this->connection[$type])) {
			return $this->connection[$type];
		}

		switch ($type) {
			case self::MASTER:
				$conf = $this->config['MASTER'];
				break;

			case self::SLAVE:
			default:
				$conf = $this->config['SLAVES'][array_rand($this->config['SLAVES'])];
				break;
		}
		
		try {
			$this->connection[$type] = new PDO($conf['HOST'], $conf['DB'], $conf['USER'], $conf['PASS'], $conf['PORT']);
			$this->connection[$type]->exec("SET NAMES utf8");
		}
		catch (PDOException $e) {
			echo $e->getMessage();
		}

		return $this->connection[$type];
	}

	/**
	 * Prepares a statement for execution and returns a statement object.
	 *
	 * @param string $sql sql statement
	 * @param array $params parameters used in sql statement
	 * @param int $op database operation type
	 */
	private function prepare($sql, $params, $type) {
		$connection = $this->getConnection($type);

		//$sql_monitor = new SQLMonitor($sql);
		//$sql_monitor->start();

		$sth = $connection->prepare($sql, array(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => FALSE));
		foreach ($params AS $key => $value) {
			if (strpos($key, '_') === 0) {
				$sth->bindValue(":{$key}", $value, PDO::PARAM_INT);
			}
			else {
				$sth->bindValue(":{$key}", $value, PDO::PARAM_STR);
			}
		}
		$sth->execute();

		//$sql_monitor->finish($sth);

		return $sth;
	}


	/**
	 * Catch database error.
	 * http://www.php.net/manual/en/pdostatement.errorinfo.php
	 *
	 * @param PDOStatement $sth
	 */
	private function catchError($sth, $sql = '', $params = '') {
		list($sql_state, $error_code, $error_message) = $sth->errorInfo();

		if ($sql_state == '00000') {
			return TRUE;
		}

		// rollback if in a transaction
		$this->rollback();
	}

	private function beginTransaction() {
		$connection = $this->getConnection(self::MASTER);
		$this->in_transaction = TRUE;
		$connection->beginTransaction();
	}

	private function commit() {
		$connection = $this->getConnection(self::MASTER);
		$connection->commit();
		$this->in_transaction = FALSE;
	}

	private function rollback() {
		if ($this->in_transaction) {
			$connection = $this->getConnection(self::MASTER);
			$connection->rollback();
			$this->in_transaction = FALSE;
		}
	}

}
