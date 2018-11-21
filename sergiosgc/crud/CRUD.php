<?php
namespace sergiosgc\crud;

trait CRUD {
	public static function getDB() {
        return \app\Application::singleton()->getDatabaseConnection();
    }
    public static function dbKeyFields() {
        if (in_array('id', static::dbFields())) return ['id'];
        return [];
    }
    public static function dbKeySequence() {
        return null;
    }
    public static function dbFields() {
        return array_map(function($p) { return $p->getName(); }, array_filter((new \ReflectionClass(get_called_class()))->getProperties(), function($p) { return $p->getModifiers() & 0x100 /* public */; }));
    }
    public function dbSerializeField($field, $value) {
        return $value;
    }
    public function dbMap($row) {
        $fields = static::dbFields();
        foreach ($row as $field => $value) if (in_array($field, $fields)) $this->$field = $value;
        return $this;
    }
    public static function dbTableName($readOperation = false) {
        $className = explode('\\', get_called_class());
        $className = $className[count($className) - 1];
        $className = preg_replace('_([A-Z])_', '_\1', $className);
        $className = strtolower($className);
        $className = preg_replace('/^_/', '', $className);
        return $className;
    }
    public static function dbExec($query) {
        $args = func_get_args();
        array_shift($args);
        $sth = static::getDB()->prepare($query);
        $sth->execute($args);
        $sth->closeCursor();
    }
    public static function dbFetchAll($query) {
        $args = func_get_args();
        array_shift($args);
        $sth = static::getDB()->prepare($query);
        $sth->execute($args);
        $result = $sth->fetchAll();
        $sth->closeCursor();
        return $result;
    }
    public static function dbFetchAllCallback($query, $callback) {
        $args = func_get_args();
        for ($i=0; $i<2; $i++) array_shift($args);
        $sth = static::getDB()->prepare($query);
        $sth->execute($args);
        $result = [];
        while ($row = $sth->fetch(\PDO::FETCH_ASSOC)) $result[] = call_user_func($callback, $row);
        $sth->closeCursor();
        return $result;
    }
    public static function dbReadAll($sortColumn = null, $sortDir = 'ASC', $filter = null, $page = null, $pageSize = 20) {
        $class = get_called_class();
        $filter_args = func_get_args();
        for ($i=0; $i<5; $i++) array_shift($filter_args);
        
        $query = sprintf(<<<EOS
SELECT
 %s
FROM "%s"
%s
ORDER BY "%s" %s
%s
EOS
            , implode(', ', array_map(function($f) { return "\"$f\""; }, static::dbFields())),
            static::dbTableName(), 
            empty($filter) ? '' : sprintf('WHERE %s', $filter), 
            $sortColumn, 
            $sortDir == 'DESC' ? 'DESC' : 'ASC', 
            is_null($page) ? '' : sprintf('LIMIT %d OFFSET %d', $pageSize, $pageSize * ($page - 1)));
        $result = call_user_func_array(
            array($class, 'dbFetchAllCallback'),
            array_merge(
                [$query, 
                 function ($row) use ($class) { return (new $class())->dbMap($row); }], 
                $filter_args));
        
        if (is_null($page)) {
            $count = count($result);
        } else {
            $query = sprintf(<<<EOS
SELECT count(*) AS "count"
FROM "%s"
%s
EOS
                , static::dbTableName(), empty($filter) ? '' : sprintf('WHERE %s', $filter));
            $count = (int) ceil((0 + call_user_func_array(array($class, 'dbFetchAll'), array_merge([$query], $filter_args))['count']) / $pageSize);
        }
        
        return [$result, $count];
    }
    public function dbCreate() {
        $fields = static::dbFields();
        $keys = static::dbKeyFields();
        $toInsert = [];
        foreach ($fields as $field) {
            if (is_null($this->$field) && in_array($field, $keys)) continue;
            $toInsert[$field] = $this->dbSerializeField($field, $this->$field);
        }
        $query = sprintf(<<<EOS
INSERT INTO "%s"(%s) VALUES(%s)
EOS
            , static::dbTableName(), 
            implode(',', array_map(function($fieldName) { return sprintf('"%s"', $fieldName); }, array_keys($toInsert))),
            implode(',', array_map(function($x) { return '?'; }, $toInsert)));
        $sth = static::getDB()->prepare($query);
        $sth->execute(array_values($toInsert));
        $insertId = static::getDB()->lastInsertId(static::dbKeySequence());
        $sth->closeCursor();

        if (count($keys) == 1) {
            $key = $keys[0];
            $this->$key = $insertId;
        }
        return $insertId;
    }
    public static function dbRead($filter) {
        $filterArgs = func_get_args();
        array_shift($filterArgs);
        $args = array_merge([null, 'ASC', $filter, null, 20], $filterArgs);
        list($result, $dummy) = call_user_func_array([get_called_class(), 'dbReadAll'], $args);
        if (count($result) > 1) throw new Exception('dbRead filter returned more than one result');
        if (count($result) == 0) return null;
        return $result[0];
    }
    public function dbUpdate() {
        $fields = static::dbFields();
        $keys = static::dbKeyFields();
        $toUpdate = [];
        $where = [];
        foreach($fields as $field) {
            $toUpdate[$field] = $this->dbSerializeField($field, $this->$field);
        }
        foreach ($keys as $key) {
            unset($toUpdate[$key]);
            $where[$key] = $this->dbSerializeField($key, $this->$key);
        }
        $query = sprintf(<<<EOS
UPDATE "%s"
SET %s
WHERE %s
EOS
        , static::dbTableName(),
        implode(',', array_map(function($fieldName) { return sprintf('"%s" = ?', $fieldName);; }, array_keys($toUpdate))),
        implode(' AND ', array_map(function($fieldName) { return sprintf('"%s" = ?', $fieldName); }, array_keys($where))));
        $sth = static::getDB()->prepare($query);
        $sth->execute(array_merge(array_values($toUpdate), array_values($where)));
        $sth->closeCursor();
    }
    public function dbDelete() {
        $keys = static::dbKeyFields();
        $where = [];
        foreach ($keys as $key) {
            $where[$key] = $this->dbSerializeField($key, $this->$key);
        }
        $query = sprintf('DELETE FROM "%s" WHERE %s'
            , static::dbTableName(),
            implode(' AND ', array_map(function($fieldName) { return sprintf('"%s" = ?', $fieldName); }, array_keys($where))));
        print($query);
        $sth = static::getDB()->prepare($query);
        $sth->execute(array_values($where));
        $sth->closeCursor();
    }
}
