<?php
namespace sergiosgc\crud;

trait Validatable {
    public static function validate($values) {
        return Validator::validateValues(static::describeFields(), (array) $values);
    }
    public function validateInstance() {
        $result = Validator::validateValues(static::describeFields(), (array) $this);
        if (is_callable([$this, '_validate'])) foreach($this->_validate() as $field => $errors) {
            if (!is_array($errors)) $errors = [ (string) $errors ];
            if (!isset($result[$field])) $result[$field] = [];
            if (!is_array($result[$field])) $result[$field] = [ (string) $result[$field]];
            foreach ($errors as $error) $result[$field][] = $error;
        }
        foreach(array_keys($result) as $field) $result[$field] = array_unique($result[$field]);
        return $result;
    }
}
