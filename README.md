# crud-trait

Crud-trait is a minimalistic approach to object relational mapping. It is based on the expectation that any ORM is leaky. If any ORM is leaky, then a Paretto aproach where basic queries are automated by the ORM, leaving complex SQL access for all other use-cases, leads to a simple codebase.

## 5-minute usage guide

Declare your class as using the trait, add SQL columns as public PHP fields, and override the getDB method:

    class VirtuousGizmo {
        use \sergiosgc\crud\CRUD;
        
        public $id = null;
        public $name = null;
        public $color = null;
        public static function getDB() {
            MyApp::singleton()->getDB(); // Should return a PDO Connection
        }
    }

Crud-trait will assume your table is named `virtuous_gizmo`, having key `id` and fields `id`, `name`, `color`.

You can now operate on the class/table using the db**C**reate(), db**R**ead(), db**U**pdate(), db**D**elete() methods:

    $gizmo = new VirtuousGizmo();
    $gizmo->name = 'ZoomZoom';
    $gizmo->color = 'blue';
    $gizmo->dbCreate(); // $gizmo->id is assigned by dbCreate()
    printf('Created gizmo with id %d', $gizmo->id);
    $gizmo->color = 'green';
    $gizmo->dbUpdate();
    
    $gizmo = VirtuousGizmo::dbRead('color = ?', 'green');
    printf('Green gizmo has id %d', $gizmo->id);
    $gizmo->dbDelete();
    
## Set reads

You may read a set of objects using the `dbReadAll` method:

    list($gizmos, $gizmoCount) = VirtuousGizmo::dbReadAll('name', 'ASC', 'color = ?', 1, 20, 'blue');
    
This would read page 1 of all gizmos whose `color` equals blue, sorted in ASCending order by `name` using a page size of 20 items per page. The function returns an array of `VirtuousGizmo` and a total count (for pagination UI). All arguments after the page size (20 in this case) are passed to the query execution.
    

And that's it. The rest of the document focuses on how to deal with abstraction leaks: 
* handling leaks in the opinionated approach
* handling leaks into the underlying SQL

## Handling abstraction leaks

### Opinionated approach overrides

Crud-trait expects the underlying table to match a definition heuristically defined. If it does not, you'll need to override some methods.

#### Table name

The table name is, by default, defined to be the class name, with every uppercase character preceded by an underscore and lowercased. i.e. Class name converted from camelCase to underscore_case.

If this is false, then you need to override the dbTableName static method. For example:

    public static function dbTableName() { return 'virtuousgizmos'; }
    
#### Fields

Table columns are, by default, all public fields, with the same name as the php field. If this is false, you'll need to override the `dbFields()` static method. For example:

    public static function dbFields() { return ['id', 'name']; }
    
#### Key fields

The assumed default for key fields is that the `id` field, if existant, is the primary key, otherwise there are no keys. If this is false, override the `dbKeys()` static method. For example:

    public static function dbKeyFields() { return ['id', 'name']; }
    
Note that if there are multiple keys, then `dbCreate()` will not populate the object's fields after insertion.

### Field (un)serializing

If fields require serializing before storage, override the `dbSerializeField()` and `dbUnserializeField`. Imagine our example class has a `$parts` field with an array of parts: 

    public static function dbSerializeField($field, $value) {
      switch ($field) {
        case 'parts': return serialize($value);
        default: return $value;
      }
    }
    public static function dbUnserializeField($field, $value) {
      switch ($field) {
        case 'parts': return unserialize($value);
        default: return $value;
      }
    }

### Describable interface

If you find yourself overriding a lot of these methods, it may worthwhile to look at the [Describable interface](docs/Describable.md)

## SQL Access

If you need to directly execute SQL queries, you have access to `dbExec()`, `dbFetchAll()` and `dbFetchAllCallback()`. dbExec and dbFetchAll receive as arguments a query with placeholders (`?`), and values to place in the placeholders. dbExec executes the query and returns nothing (throws an exception on error). dbFetchAll executes the query and returns all rows. dbFetchAllCallback executes the query, maps the rows using the callback (second argument) and returns the resulting array.

dbFetchAllCallback, namely, allows you to get a set of objects using a non-basic query. For example:

    $gizmos = VirtuousGizmo::dbFetchAllCallback(<<<EOQ
    SELECT * FROM alpha_gizmos WHERE color = ?
    UNION
    SELECT * FROM beta_gizmos WHERE release > ?
    EOQ
      , function ($row) { return (new VirtuousGizmo())->dbMap($row); } ,
      'green', 
      1.0);

## Handling relations between tables/classes

The ability to handle relationships depends on field description, as documented in the [Describable interface](docs/Describable.md)
