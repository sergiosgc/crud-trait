<?php
namespace sergiosgc\crud;

trait Normalizable {
    public static function normalize($values) {
        return Normalizer::normalizeValues(static::describeFields(), (array) $values);
    }
    public function normalizeInstance() {
        $values = [];
        foreach(array_keys(static::describeFields()) as $key) if (isset($this->key)) $values[$key] = $this->key;
        $values = Normalizer::normalizeValues(static::describeFields(), (array) $values);
        foreach($values as $key => $val) $this->$key = $val;
    }
}
