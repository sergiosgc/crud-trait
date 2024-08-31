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
            if (substr($desc[$field]['type'], -2) == "[]") {
                if (0 === preg_match_all('/{?(?<val>(?:[^,]*)|(?:"[^"]*"))(?:,|}$)/', $value, $matches)) return $value;
                return array_map(function($escaped) {
                    if (strlen($escaped) == 0) return $escaped;
                    if ($escaped[0] != '"') return $escaped;
                    return strtr(substr($escaped, 1, -1), [ 
                        '\b' => "\b",
                        '\f' => "\f",
                        '\n' => "\n",
                        '\r' => "\r",
                        '\"' => '"',
                        '\\' => '\\\\'
                    ]);
                }, $matches['val']);
            }
        }

        return $value;
    }
    public function dbSerializeField($field, $value) {
        if (isset(class_implements(\get_called_class())['sergiosgc\crud\Describable'])) {
            $desc = static::describeFields();
            if ($desc[$field]['type'] == 'boolean') return $value ? 1 : 0;
            if ($desc[$field]['type'] == 'json') {
                return is_null($value) || '' === $value ? null : (is_string($value) ? $value : json_encode($value));
            }
            if ($desc[$field]['type'] == 'timestamp') {
                try {
                    if ($value instanceof \DateTime) {
                        $datetime = $value;
                    } elseif (( (string) $value ) === ( (string) ((int) $value) )) { // Unix timestamp
                        $datetime = new \DateTime('@' . $value);
                    } else {
                        $datetime = new \DateTime($value);
                    }
                    return $datetime->format('c');
                } catch (\Exception $e) { return $value; }
            }
            if (substr($desc[$field]['type'], -2) == "[]" && !is_array($value)) throw new Exception('Unable to serialize non-array value onto array field ' . $field);
            if (substr($desc[$field]['type'], -2) == "[]") {
                $baseType = substr($desc[$field]['type'], 0, -2);
                return sprintf("{ %s }", implode(", ", 
                    array_map(
                        function($v) {
                            if (FALSE !== strpos($v, ",") || 
                                FALSE !== strpos($v, "{") || 
                                FALSE !== strpos($v, "}")) return sprintf('"%s"', strtr($v, [ '\\' => '\\\\', '"' => '\"' ]));
                            return $v;
                        }, 
                        array_map(
                            function($v) use ($baseType) {
                                switch ($baseType) {
                                    case 'boolean':
                                        return $v ? 1 : 0;
                                    case 'json':
                                        return is_null($v) ? '' : (is_string($v) ? $v : json_encode($v));
                                    case 'timestamp':
                                        return ( $v instanceof \DateTime ? 
                                            $value : 
                                            ( ((string) $v) === ((string) ((int) $v)) ? 
                                                new \DateTime('@' . $v) : 
                                                new \DateTime($v) ) 
                                        )->format('c');
                                    default: 
                                        return $v;
                                }
                            }, 
                            $value
                        )
                    )
                ));
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
    public static function dbExec($query, ...$args) {
        $sth = static::getDB()->prepare($query);
        $sth->execute($args);
        $sth->closeCursor();
    }
    public static function dbFetchAll($query, ...$args) {
        $sth = static::getDB()->prepare($query);
        $sth->execute($args);
        $result = $sth->fetchAll();
        $sth->closeCursor();
        return $result;
    }
    public static function dbFetchAllCallback($query, $callback, ...$args) {
        $sth = static::getDB()->prepare($query);
        $sth->execute($args);
        $result = [];
        while ($row = $sth->fetch(\PDO::FETCH_ASSOC)) $result[] = call_user_func($callback, $row);
        $sth->closeCursor();
        return $result;
    }
    public static function dbReadPaged($sortColumn = null, $sortDir = 'ASC', $filter = null, $page = null, $pageSize = 20, ...$filter_args) {
        $class = get_called_class();
        if (!is_null($sortColumn) && !in_array($sortColumn, static::dbFields())) throw new Exception("Invalid sort column");

        $query = sprintf(<<<EOS
SELECT
 %s
FROM "%s"
%s
%s
%s
EOS
            , implode(', ', array_map(function($f) { return "\"$f\""; }, static::dbFields())),
            str_replace('.', '"."', static::dbTableName()),
            empty($filter) ? '' : sprintf('WHERE %s', $filter),
            $sortColumn ? sprintf('ORDER BY "%s" %s', $sortColumn, $sortDir) : '',
            is_null($page) ? '' : sprintf('LIMIT %d OFFSET %d', $pageSize, $pageSize * ($page - 1)));
        $result = $class::dbFetchAllCallback(
                $query,
                function ($row) use ($class) { return (new $class())->dbMap($row); },
                ...$filter_args);

        if (is_null($page)) {
            $count = count($result);
        } else {
            $query = sprintf(<<<EOS
SELECT count(*) AS "count"
FROM "%s"
%s
EOS
                , str_replace('.', '"."', static::dbTableName()), empty($filter) ? '' : sprintf('WHERE %s', $filter));
            $count = (int) ceil((0 + $class::dbFetchAll($query, ...$filter_args)[0]['count']) / $pageSize);
        }

        return [$result, $count];
    }
    public static function dbReadAll($sortColumn = null, $sortDir = 'ASC', $filter = null, ...$filter_args) {
        $class = get_called_class();

        $query = sprintf(<<<EOS
SELECT
 %s
FROM "%s"
%s
%s
EOS
            , implode(', ', array_map(function($f) { return "\"$f\""; }, static::dbFields())),
            str_replace('.', '"."', static::dbTableName()),
            empty($filter) ? '' : sprintf('WHERE %s', $filter),
            $sortColumn ? sprintf('ORDER BY "%s" %s', $sortColumn, $sortDir) : '');
        $result = $class::dbFetchAllCallback(
                $query,
                function ($row) use ($class) { return (new $class())->dbMap($row); },
                ...$filter_args);
 
        return $result;
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
INSERT INTO "%s"(%s) VALUES(%s) RETURNING *
EOS
            , str_replace('.', '"."', static::dbTableName()),
            implode(',', array_map(function($fieldName) { return sprintf('"%s"', $fieldName); }, array_keys($toInsert))),
            implode(',', array_map(function($fieldName) use ($toInsert) { 
                if (is_array($toInsert[$fieldName])) { return sprintf('ARRAY[%s]', implode(',', array_map(function() { return '?'; }, $toInsert[$fieldName]))); }
                return '?';
            }, array_keys($toInsert))));
        $sth = static::getDB()->prepare($query);
        $sth->execute(array_reduce($toInsert, function($acc, $value) { return array_merge($acc, is_array($value) ? $value : [ $value ]); }, []));
        $result = $sth->fetchAll();
        $insertedKeys = array_reduce(
            array_map(
                function($key) use ($result) { return [ $key, $result[0][$key] ]; },
                    $keys
            ),
            function($acc, $value) { $acc[$value[0]] = $value[1]; return $acc; },
            []
        );
        $sth->closeCursor();
        foreach ($insertedKeys as $key => $value) $this->{$key} = $value;
        return count($insertedKeys) == 1 ? $insertedKeys[$keys[0]] : $insertedKeys;
    }
    public static function dbRead($filter, ...$filterArgs) {
        $args = array_merge([null, 'ASC', $filter], $filterArgs);
        $result = call_user_func_array([get_called_class(), 'dbReadAll'], $args);
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
        , str_replace('.', '"."', static::dbTableName()),
        implode(',', array_map(function($fieldName) use ($toUpdate) { 
            if (is_array($toUpdate[$fieldName])) { return sprintf('"%s" = ARRAY[%s]', $fieldName, implode(',', array_map(function() { return '?'; }, $toUpdate[$fieldName]))); }
            return sprintf('"%s" = ?', $fieldName); 
        }, array_keys($toUpdate))),
        implode(' AND ', array_map(function($fieldName) { return sprintf('"%s" = ?', $fieldName); }, array_keys($where))));
        $sth = static::getDB()->prepare($query);
        $sth->execute(array_reduce(array_merge(array_values($toUpdate), array_values($where)), function($acc, $value) { return array_merge($acc, is_array($value) ? $value : [ $value ]); }, []));
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
            , str_replace('.', '"."', static::dbTableName()),
            implode(' AND ', array_map(function($fieldName) { return sprintf('"%s" = ?', $fieldName); }, array_keys($where))));
        $sth = static::getDB()->prepare($query);
        $sth->execute(array_values($where));
        $sth->closeCursor();
    }
    public function setDescribedFields($values) {
        $result = [];
        if (!$this instanceof Describable) throw new Exception("CRUD::setDescribedFields() can only be used by Describable classes");
        foreach ($this->describeFields() as $field => $description) {
            if (!in_array($description['type'], ['int', 'int[]', 'text', 'text[]', 'password', 'integer', 'color', 'date', 'time', 'timestamp', 'email', 'range', 'telephone', 'url', 'submit', 'json', 'boolean'])) continue;
            $valueKey = substr($description['type'], -2) == '[]' && array_key_exists($field . '[]', $values) ? $field  . '[]' : $field;
            if (!array_key_exists($valueKey, $values)) continue;
            if ($this->$field !== $values[$valueKey]) $result[$field] = $values[$valueKey];
            $this->$field = $values[$valueKey];
        }
        return $result;
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
        $leftTableName = str_replace('.', '"."', static::dbTableName(true));
        $middleTableName = str_replace('.', '"."', $manyToMany['keymap']['middle_table']);
        $rightTableName = str_replace('.', '"."', $class::dbTableName(true));
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
        $leftTableName = str_replace('.', '"."', static::dbTableName(true));
        $middleTableName = str_replace('.', '"."', $manyToMany['keymap']['middle_table']);
        $rightTableName = str_replace('.', '"."', $class::dbTableName(true));
 
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
        if (!isset(class_implements(\get_called_class())['sergiosgc\crud\Describable'])) throw new Exception('dbGetReferred can only be used by Describable classes');
        $fields = static::describeFields();
        if (!array_key_exists($fieldName, $fields)) throw new Exception(sprintf('%s is not a field of %s', $fieldName, \get_called_class()));
        $field = $fields[$fieldName];
        if (!array_key_exists('db:one_to_many', $field) &&
            !array_key_exists('db:many_to_one', $field) &&
            !array_key_exists('db:many_to_many', $field)) throw new Exception(sprintf('%s has no referential integrity definition', $fieldName));
        if (array_key_exists('db:many_to_one', $field)) {
            foreach (['type', 'keymap' ] as $required) if (!array_key_exists($required, $field['db:many_to_one'])) throw new Exception(sprintf("%s field is declared db:many_to_one but has no %s descriptor", $fieldName, $required));
            if (!array_key_exists('keymap', $field['db:many_to_one'])) throw new Exception(sprintf("%s field is declared db:many_to_one but has no %s descriptor", $fieldName, 'keymap'));
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
            return $class::dbRead($queryWhere, ...$queryArgs);
        }
        if (array_key_exists('db:one_to_many', $field)) {
            if (!array_key_exists('keymap', $field['db:one_to_many'])) throw new Exception(sprintf("%s field is declared db:one_to_many but has no %s descriptor", $fieldName, 'keymap'));
            if (!array_key_exists('type', $field['db:one_to_many'])) throw new Exception(sprintf("%s field is declared db:one_to_many but has no %s descriptor", $fieldName, 'keymap'));
            if (!class_exists($field['db:one_to_many']['type'])) throw new Exception(sprintf("%s field declared as type %s but that class does not exist", $fieldName, $field['db:one_to_many']['type']));
            $class = $field['db:one_to_many']['type'];
            $queryWhere = implode(' AND ', array_map(
                function($key) {
                    return sprintf('"%s" = ?', $key);
                },
                array_values($field['db:one_to_many']['keymap'])));
            $_this = $this;
            $queryArgs = array_map(
                function($key) use ($_this) {
                    return $_this->$key;
                },
                array_keys($field['db:one_to_many']['keymap']));
            return $class::dbReadAll(null, null, $queryWhere, ...$queryArgs);
        }
        if (array_key_exists('db:many_to_many', $field)) {
            if (!array_key_exists('keymap', $field['db:many_to_many'])) throw new Exception(sprintf("%s field is declared db:many_to_many but has no %s descriptor", $fieldName, 'keymap'));
            foreach(['middle_table', 'left', 'right'] as $descriptor) if (!array_key_exists($descriptor, $field['db:many_to_many']['keymap'])) throw new Exception(sprintf("%s field is declared db:many_to_many but has no keymap %s descriptor", $fieldName, $descriptor));
            if (!array_key_exists('type', $field['db:many_to_many'])) throw new Exception(sprintf("%s field is declared db:many_to_many but has no %s descriptor", $fieldName, 'keymap'));
            if (!class_exists($field['db:many_to_many']['type'])) throw new Exception(sprintf("%s field declared as type %s but that class does not exist", $fieldName, $field['db:one_to_many']['type']));
            $class = $field['db:many_to_many']['type'];
            $keymap = $field['db:many_to_many']['keymap'];
            $queryWhere = sprintf('(%s) IN (SELECT %s FROM "%s" WHERE (%s) = (%s))',
                implode(", ", array_map( 
                    function($column) { return sprintf('"%s"', $column); },
                    array_values($keymap['right'])
                )),
                implode(", ", array_map( 
                    function($column) { return sprintf('"%s"', $column); },
                    array_keys($keymap['right'])
                )),
                $keymap['middle_table'],
                implode(", ", array_map( 
                    function($column) { return sprintf('"%s"', $column); },
                    array_values($keymap['left'])
                )),
                implode(", ", array_map( 
                    function($column) { return sprintf('?', $column); },
                    array_keys($keymap['left'])
                )),
            );
            $_this = $this;
            $queryArgs = array_map(
                function($key) use ($_this) {
                    return $_this->$key;
                },
                array_keys($keymap['left']));
            return $class::dbReadAll(null, null, $queryWhere, ...$queryArgs);
        }
        throw new Exception('Unimplemented'); // TODO: Implement me
    }
    public function dbJsonGet($fieldName, $jsonPath) {
        if (!isset(class_implements(\get_called_class())['sergiosgc\crud\Describable'])) throw new Exception('dbGetReferred can only be used by Describable classes');
        $jsonObject = new \JsonPath\JsonObject($this->$fieldName);
        return $jsonObject->get($jsonPath);
    }
    public function dbJsonSet($fieldName, $jsonPath, $value) {
        if (!isset(class_implements(\get_called_class())['sergiosgc\crud\Describable'])) throw new Exception('dbGetReferred can only be used by Describable classes');
        $jsonObject = new \JsonPath\JsonObject($this->$fieldName);
        $this->$fieldName = (string) $jsonObject->set($jsonPath, $value);
    }
}
