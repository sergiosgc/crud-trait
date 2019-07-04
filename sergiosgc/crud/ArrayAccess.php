<?php
namespace sergiosgc\crud;

trait ArrayAccess {
    public function offsetExists ( $offset ) {
        return in_array($offset, array_keys(static::describeFields()));
    }
    public function offsetGet ( $offset ) {
        if (!$this->offsetExists($offset)) return null;
        return $this->$offset;
    }
    public function offsetSet ( $offset , $value ) {
        if (!$this->offsetExists($offset)) return null;
        $this->$offset = $value;
    }
    public function offsetUnset ( $offset ) {
        if (!$this->offsetExists($offset)) return null;
        unset($this->$offset);
    }
}
