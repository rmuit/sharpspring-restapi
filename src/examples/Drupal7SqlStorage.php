<?php

namespace SharpSpring\RestApi\examples;

/**
 * A key-value store using Drupal 7 database backend.
 *
 * This class has no official interface (which is why it's in the examples/
 * subdir). Anyone not using Drupal 7 is expected to write their own key-value
 * storage.
 *
 * Not all methods are used but it seemed easy enough to just implement a
 * comprehensive set of methods including 'multiple/all' variants.
 */
class Drupal7SqlStorage
{
    /**
     * The name of the SQL table to use.
     *
     * @var string
     */
    protected $table;

    /**
     * Constructor.
     *
     * @param string
     *   Table name.
     */
    public function __construct($table)
    {
        $this->table = $table;
    }

    // General:

    public function get($key, $default = null)
    {
        $values = $this->getMultiple(array($key));
        return isset($values[$key]) ? $values[$key] : $default;
    }

    public function setMultiple(array $data)
    {
        foreach ($data as $key => $value) {
            $this->set($key, $value);
        }
    }

    public function delete($key)
    {
        $this->deleteMultiple(array($key));
    }

    // Backend specific:

    public function has($key)
    {
        return (bool)db_query("SELECT 1 FROM {{$this->table}} WHERE name = :name", array(':name' => $key))
            ->fetchField();
    }

    public function getMultiple(array $keys)
    {
        $values = db_query("SELECT name, value FROM {{$this->table}} WHERE name IN ( :keys )", array(':keys' => $keys))->fetchAllKeyed();
        foreach ($values as $key => $value) {
            $values[$key] = unserialize($value);
        }
        return $values;
    }

    public function getAllBatched($limit = 1024, $offset = 0)
    {
        $values = db_query_range("SELECT name, value FROM {{$this->table}} ORDER BY name", $offset, $limit)->fetchAllKeyed();
        foreach ($values as $key => $value) {
            $values[$key] = unserialize($value);
        }
        return $values;
    }

    public function set($key, $value)
    {
        db_merge($this->table)
            ->key(array('name' => $key))
            ->fields(array('value' => serialize($value)))
            ->execute();
    }

    public function deleteMultiple(array $keys)
    {
        // Delete in chunks when a large array is passed.
        while ($keys) {
            db_delete($this->table)
                ->condition('name', array_splice($keys, 0, 1024), 'IN')
                ->execute();
        }
    }

    public function deleteAll()
    {
        db_delete($this->table)->execute();
    }

}
