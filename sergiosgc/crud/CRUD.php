<?php
namespace sergiosgc\crud;

trait CRUD {
    public static function getDB() {
        return \app\Application::singleton()->getDatabaseConnection();
    }
    public static function dbKeyFields() {
        if (isset(class_implements(\get_called_class())['sergiosgc\crud\Describable'])) {
            $fields = static::describeFields();
            return array_filter(array_map(
                function($key, $value) {
                    if (isset($value['db:primarykey'])) return $key;
                    return false;
                }, 
                array_keys($fields),
                $fields
            ));
        } else {
            if (in_array('id', static::dbFields())) return ['id'];
            return [];
        }
    }
    public static function dbKeySequence() {
        return null;
    }
    public static function dbFields() {
        if (isset(class_implements(\get_called_class())['sergiosgc\crud\Describable'])) {
            return array_keys(array_filter(array_map(
                function($field) {
                    if (isset($field['db:many_to_many'])) return false;
                    if (isset($field['db:one_to_many'])) return false;
                    return $field;
                },
                static::describeFields())));
        } else {
            return array_map(function($p) { return $p->getName(); }, array_filter((new \ReflectionClass(get_called_class()))->getProperties(), function($p) { return $p->getModifiers() & 0x100 /* public */; }));
        }
    }
    public function dbUnserializeField($field, $value) {
        if (isset(class_implements(\get_called_class())['sergiosgc\crud\Describable'])) {
            $desc = static::describeFields();
            if ($desc[$field]['type'] == 'boolean') ($value == 'f' || $value == 'false' || $value == '0') ? false : (bool) $value;
            if ($desc[$field]['type'] == 'json') return json_decode($value, true);
            if ($desc[$field]['type'] == 'timestamp') try { return new \DateTime($value); } catch (\Exception $e) { return $value; }
        }

        return $value;
    }
    public function dbSerializeField($field, $value) {
        if (isset(class_implements(\get_called_class())['sergiosgc\crud\Describable'])) {
            $desc = static::describeFields();
            if ($desc[$field]['type'] == 'boolean') return $value ? '1' : '0';
            if ($desc[$field]['type'] == 'json') return json_encode($value);
            if ($desc[$field]['type'] == 'timestamp') {
                try {
                    if ($value instanceof \DateTime) {
                        $datetime = $value;
                    } elseif (( (string) $value ) === ( (string) ((int) $value) )) { // Unix timestamp
                        $datetime = new \DateTime('@' . $value);
                    } else {
                        $datetime = new DateTime($value);
                    }
                    return $datetime->format('c');
                } catch (\Exception $e) { return $value; }
            }
        }

        return $value;
    }
    public function dbMap($row) {
        $fields = static::dbFields();
        foreach ($row as $field => $value) if (in_array($field, $fields)) $this->$field = $this->dbUnserializeField($field, $value);
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
%s
%s
EOS
            , implode(', ', array_map(function($f) { return "\"$f\""; }, static::dbFields())),
            static::dbTableName(), 
            empty($filter) ? '' : sprintf('WHERE %s', $filter), 
            $sortColumn ? sprintf('ORDER BY "%s" %s', $sortColumn, $sortDir) : '',
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
            $count = (int) ceil((0 + call_user_func_array(array($class, 'dbFetchAll'), array_merge([$query], $filter_args))[0]['count']) / $pageSize);
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
        $this->dbUpdateDescribedRelations();
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
        $this->dbUpdateDescribedRelations();
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
    public function setDescribedFields($values) {
        if (!$this instanceof Describable) throw new Exception("CRUD::setDescribedFields() can only be used by Describable classes");
        foreach ($this->describeFields() as $field => $description) {
            if (!in_array($description['type'], ['int', 'int[]', 'text', 'text[]'])) continue;
            $valueKey = substr($description['type'], -2) == '[]' ? $field  . '[]' : $field;
            if (!array_key_exists($valueKey, $values)) continue;
            $this->$field = $values[$valueKey];
        }
    }
    public function describableGetter($name) {
        if (!$this instanceof Describable) throw new Exception("CRUD::describableGetter() can only be used by Describable classes");
        $fields = static::describeFields();
        if (!in_array($name, array_keys($fields)) || !(isset($fields[$name]['db:many_to_many']) || isset($fields[$name]['db:many_to_one']) || isset($fields[$name]['db:one_to_many']))) {
            $trace = debug_backtrace();
            trigger_error(
                'Undefined property via __get(): ' . $name .
                ' in ' . $trace[0]['file'] .
                ' on line ' . $trace[0]['line'],
                E_USER_NOTICE);
            return null;
        }
        if (isset($fields[$name]['db:many_to_many'])) return $this->describableManyToManyGetter($name, $fields[$name]);
        if (isset($fields[$name]['db:many_to_one'])) return $this->describableManyToOneGetter($name, $fields[$name]);
        if (isset($fields[$name]['db:one_to_many'])) return $this->describableOneToManyGetter($name, $fields[$name]);
        throw new Exception('Unexpected code reached');
    }
    public function describableManyToManyGetter($name, $description) {
        $manyToMany = $description['db:many_to_many'];
        foreach (['type', 'keymap', 'label'] as $required) if (!isset($manyToMany[$required])) throw new Exception(sprintf("%s field is declared db:many_to_many but has no %s descriptor", $name, $required));
        foreach (['middle_table', 'left', 'right'] as $required) if (!isset($manyToMany['keymap'][$required])) throw new Exception(sprintf("%s field is declared db:many_to_many but has no %s descriptor in keymap", $name, $required));
        $class = $manyToMany['type'];
        if (!class_exists($class)) throw new Exception(sprintf("%s class does not exist", $class));
        $leftTableName = static::dbTableName(true);
        $middleTableName = $manyToMany['keymap']['middle_table'];
        $rightTableName = $class::dbTableName(true);
        $resultFields = implode(',', array_map(
            function($field) use ($rightTableName) { return sprintf('"%s"."%s"', $rightTableName, $field);},
            $class::dbKeyFields()));
        $query = <<<EOS
    SELECT %s
    FROM
     "%s"
     JOIN "%s" ON ((%s) = (%s))
     JOIN "%s" ON ((%s) = (%s))
    WHERE
     (%s) = (%s)
EOS;

        $query = sprintf($query,
            $resultFields,
            $leftTableName,
            $middleTableName,
            implode(', ', array_map(function($field) use ($leftTableName) { return sprintf('"%s"."%s"', $leftTableName, $field); }, array_keys($manyToMany['keymap']['left']))),
            implode(', ', array_map(function($field) use ($middleTableName) { return sprintf('"%s"."%s"', $middleTableName, $field); }, array_values($manyToMany['keymap']['left']))),
            $rightTableName, 
            implode(', ', array_map(function($field) use ($middleTableName) { return sprintf('"%s"."%s"', $middleTableName, $field); }, array_keys($manyToMany['keymap']['right']))),
            implode(', ', array_map(function($field) use ($rightTableName) { return sprintf('"%s"."%s"', $rightTableName, $field); }, array_values($manyToMany['keymap']['right']))),
            implode(', ', array_map(function($field) use ($leftTableName) { return sprintf('"%s"."%s"', $leftTableName, $field); }, static::dbKeyFields())),
            implode(', ', array_map(function($field) { return sprintf('?', $field); }, static::dbKeyFields()))
        );
        $values = [];
        foreach (static::dbKeyFields() as $key) {
            $values[] = $this->dbSerializeField($key, $this->$key);
        }
        $result = call_user_func_array([ \get_called_class(), 'dbFetchAll'], array_merge([ $query ], $values));
        if (count(static::dbKeyFields()) == 1) return array_map(function($f) { return $f[0]; }, $result);
        return $result;
    }
    public function describableSetter($name, $value) {
        if (!$this instanceof Describable) throw new Exception("CRUD::describableSetter() can only be used by Describable classes");
        $fields = static::describeFields();
        if (!in_array($name, array_keys($fields)) || !(isset($fields[$name]['db:many_to_many']) || isset($fields[$name]['db:many_to_one']) || isset($fields[$name]['db:one_to_many']))) {
            $trace = debug_backtrace();
            trigger_error(
                'Undefined property via __set(): ' . $name .
                ' in ' . $trace[0]['file'] .
                ' on line ' . $trace[0]['line'],
                E_USER_NOTICE);
            return null;
        }
        if (isset($fields[$name]['db:many_to_many'])) return $this->describableManyToManySetter($name, $fields[$name], $value);
        if (isset($fields[$name]['db:many_to_one'])) return $this->describableManyToOneSetter($name, $fields[$name], $value);
        if (isset($fields[$name]['db:one_to_many'])) return $this->describableOneToManySetter($name, $fields[$name], $value);
        throw new Exception('Unexpected code reached');
    }
    public function dbUpdateDescribedRelations() {
        if (!$this instanceof Describable) return;
        $fields = static::describeFields();
        foreach(array_keys($fields) as $name) {
            if (isset($fields[$name]['db:many_to_many'])) $this->dbUpdateDescribableManyToMany($name, $fields[$name]);
        }
    }
    public function dbUpdateDescribableManyToMany($name, $description) {
        $manyToMany = $description['db:many_to_many'];
        foreach (['type', 'keymap', 'label'] as $required) if (!isset($manyToMany[$required])) throw new Exception(sprintf("%s field is declared db:many_to_many but has no %s descriptor", $name, $required));
        foreach (['middle_table', 'left', 'right'] as $required) if (!isset($manyToMany['keymap'][$required])) throw new Exception(sprintf("%s field is declared db:many_to_many but has no %s descriptor in keymap", $name, $required));
        $class = $manyToMany['type'];
        if (!class_exists($class)) throw new Exception(sprintf("%s class does not exist", $class));
        if (count($manyToMany['keymap']['left']) != 1) throw new Exception('Multi-key joins in middle table not implemented.');
        if (count($manyToMany['keymap']['right']) != 1) throw new Exception('Multi-key joins in middle table not implemented.');
        $leftTableName = static::dbTableName(true);
        $middleTableName = $manyToMany['keymap']['middle_table'];
        $rightTableName = $class::dbTableName(true);
        
        $currentValues = $this->describableManyToManyGetter($name, $description);
        $newValues = $this->$name;
        $toAdd = [];
        $toRemove = [];
        foreach($currentValues as $value) if (!in_array($value, $newValues)) $toRemove[] = $value;
        foreach($newValues as $value) if (!in_array($value, $currentValues)) $toAdd[] = $value;

        $query = 'INSERT INTO "%s"(%s) VALUES(?,?)';
        $query = sprintf($query,
            $manyToMany['keymap']['middle_table'],
            implode(", ",
                array_map(function($field) { return sprintf('"%s"', $field); },
                [
                    array_values($manyToMany['keymap']['left'])[0],
                    array_keys($manyToMany['keymap']['right'])[0],
                ]
                )
            )
        );
        $key = static::dbKeyFields()[0];
        $left = $this->dbSerializeField($key, $this->$key);
        foreach ($toAdd as $right) static::dbExec($query, $left, $right);

        $query = 'DELETE FROM "%s" WHERE (%s) = (?,?)';
        $query = sprintf($query,
            $manyToMany['keymap']['middle_table'],
            implode(", ",
                array_map(function($field) { return sprintf('"%s"', $field); },
                [
                    array_values($manyToMany['keymap']['left'])[0],
                    array_keys($manyToMany['keymap']['right'])[0],
                ]
                )
            )
        );
        $key = static::dbKeyFields()[0];
        $left = $this->dbSerializeField($key, $this->$key);
        foreach ($toRemove as $right) static::dbExec($query, $left, $right);
    }
    public function dbGetReferred($fieldName) {
        $fields = static::describeFields();
        if (!array_key_exists($fieldName, $fields)) throw new Exception(sprintf('%s is not a field of %s', $field, \get_called_class()));
        $field = $fields[$fieldName];
        if (!array_key_exists('db:one_to_many', $field) &&
            !array_key_exists('db:many_to_one', $field) &&
            !array_key_exists('db:many_to_many', $field)) throw new Exception(sprintf('%s has no referential integrity definition', $fieldName));
        if (array_key_exists('db:many_to_one', $field)) {
            foreach (['type', 'keymap', 'label'] as $required) if (!array_key_exists($required, $field['db:many_to_one'])) throw new Exception(sprintf("%s field is declared db:many_to_one but has no %s descriptor", $fieldName, $required));
            if (!array_key_exists('keymap', $field['db:many_to_one'])) throw new Exception(sprintf("%s field is declared db:many_to_many but has no %s descriptor", $fieldName, 'keymap'));
            $class = $field['db:many_to_one']['type'];
            $queryWhere = implode(' AND ', array_map(
                function($key) {
                    return sprintf('"%s" = ?', $key);
                },
                array_values($field['db:many_to_one']['keymap'])));
            $_this = $this;
            $queryArgs = array_map(
                function($key) use ($_this) {
                    return $_this->$key;
                },
                array_keys($field['db:many_to_one']['keymap']));
            return call_user_func_array( [$class, 'dbRead'], array_merge( [ $queryWhere ], $queryArgs));
        }
        throw new Exception('Unimplemented'); // TODO: Implement me
    }
}
