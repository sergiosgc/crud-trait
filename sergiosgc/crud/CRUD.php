<?php
namespace sergiosgc\crud;

trait CRUD {
	public static function getDB() {
        return \app\Application::singleton()->getDatabaseConnection();
    }
    public static function dbFields() {
        return array_map(function($p) { return $p->getName(); }, array_filter((new \ReflectionClass(get_called_class()))->getProperties(), function($p) { return $p->getModifiers() & 0x100 /* public */; }));
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
    public static function dbRead($filter) {
        $filterArgs = func_get_args();
        array_shift($filterArgs);
        $args = array_merge([null, 'ASC', $filter, null, 20], $filterArgs);
        list($result, $dummy) = call_user_func_array([get_called_class(), 'dbReadAll'], $args);
        if (count($result) > 1) throw new Exception('dbRead filter returned more than one result');
        if (count($result) == 0) return null;
        return $result[0];
    }
}
