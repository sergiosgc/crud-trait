# Describable interface

Instead of overriding the relational mapping methods in the CRUD trait, as described in the main Readme, you may opt for a more
comprehensive approach of fully describing the fields. You do that by implementing the \sergiosgc\crud\Describable interface, 
which means implementing a `describeFields` method. Let's setup an example: 

* A ComplexGizmo class, which has an id, name, a many to one relationship with an Owner and a many to many relationship with Part(s).
* A Part class with id and name
* An Owner class with id and name

Sample code: 

    class Owner {
        use \sergiosgc\crud\CRUD;
    
        public $id = null;
        public $name = null;
    }
    
    class Part {
        use \sergiosgc\crud\CRUD;
    
        public $id = null;
        public $name = null;
    }

    class ComplexGizmo {
        use \sergiosgc\crud\CRUD;
    
        public $id = null;
        public $name = null;
        public $owner = null;
        public $parts = null;
    }
    
Let's describe ComplexGizmo:

    class ComplexGizmo implements \sergiosgc\crud\Describable {
        use \sergiosgc\crud\CRUD;
    
        public $id = null;
        public $name = null;
        public $owner = null;
        public $parts = null;
        
        public static function describeFields() {
            return [
              'id' => [ 'type' => 'int', 'db:primarykey' => true ],
              'name' => [ 
                'type' => 'string', 
                'validation' => [ 'required', 
                                  'nonempty' 
                                  [ 'regexp', 
                                    '#^[1-9][0-9][0-9]$#', 
                                    _('ID must be a three digit integer.')]
                                ]
              ],
              'owner' => [
                'type' => 'int',
                 'db:many_to_one' => [ 'type' => 'Owner', 'keymap' => [ 'owner' => 'id' ] ]
              ],
              'parts' => [
                'type' => 'int[]',
                'db:many_to_many' => [
                  'type' => 'Part',
                  'keymap' => [
                    'middle_table' => 'complex_gizmo_part',
                    'left' => [ 'id' => 'gizmo' ],
                    'right' => [ 'part' => 'id' ]
                  ]
                ]
              ]
            ];
        }
    }
    
There's a bit to parse here. `describeFields()` must return an associative array of fields. Keys are the field names, both in
the PHP object and in the table. Values are field descriptions. Each field description must have a `type` descriptor. The type
may be:
* int, string, boolean or float for native types
* int[], string[], boolean[] or float[] for arrays of native types

On Describable classes `CRUD::dbFields` no longer uses the class public fields as the list of database fields. It switches to the fields returned by
`describeFields()`.

Descriptors are namespaced. Those that are for application in a specific package have a prefix followed by ':'. That's the case 
for the `db:primaryKey` descriptor. It marks primary key fields. 

On Describable classes `CRUD::dbKeyFields` no longer assumes `id` is the key field, and relies on fields described with the `db:primaryKey` descriptor.

`db:many_to_one` and `db:many_to_many` mark the fields as relational, pointing to a different class (and hence table). They must
contain a `type` defining the class on the other end of the relation. They must define a `keymap` which declares field mappings.

For `db:many_to_one` the field map defines the local and remote fields.

For `db:many_to_many`, the only implemented SQL model is the usage of an intermediary table, called a middle_table here. 
The keymap defines the intermediary table in `middle_table`, and then two field mappings. The `left` connect the local table 
to the middle_table, and the right connects the middle_table to the destination.

## Relational getter

When a field is marked relational, it still behaves according to its type. 

    $gizmo = ComplexGizmo::read('id = ?', 1);
    print($gizmo->owner); // Will print an integer
    
If you want to access the object on the other end of the relation, CRUD provides a `dbGetReferred()` method, which receives a 
field as an argument:

    print($gizmo->getReferred('owner')->name); // Will print the owner's name
    
Obviously, on a many to many relationship, `dbGetReferred()` returns an array:

    var_dump($gizmo->getReferred('parts')); // Will dump an array of Part objects




