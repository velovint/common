<?php
/*
 *  $Id$
 *
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS
 * "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT
 * LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR
 * A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT
 * OWNER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL,
 * SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT
 * LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE,
 * DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY
 * THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT
 * (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE
 * OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 *
 * This software consists of voluntary contributions made by many individuals
 * and is licensed under the LGPL. For more information, see
 * <http://www.phpdoctrine.com>.
 */

/**
 * Doctrine_Table   represents a database table
 *                  each Doctrine_Table holds the information of foreignKeys and associations
 *
 *
 * @author      Konsta Vesterinen
 * @package     Doctrine ORM
 * @url         www.phpdoctrine.com
 * @license     LGPL
 * @version     1.0 alpha
 */
class Doctrine_Table extends Doctrine_Configurable implements Countable {
    /**
     * @var boolean $isNewEntry                         whether ot not this table created a new record or not, used only internally
     */
    private $isNewEntry       = false;
    /**
     * @var array $data                                 temporary data which is then loaded into Doctrine_Record::$data
     */
    private $data             = array();
    /**
     * @var array $relations                            an array containing all the Doctrine_Relation objects for this table
     */
    private $relations        = array();
    /**
     * @var array $primaryKeys                          an array containing all primary key column names
     */
    private $primaryKeys      = array();
    /**
     * @var mixed $identifier
     */
    private $identifier;
    /**
     * @see Doctrine_Identifier constants
     * @var integer $identifierType                     the type of identifier this table uses
     */
    private $identifierType;
    /**
     * @var string $query                               cached simple query
     */
    private $query;
    /**
     * @var Doctrine_Connection $connection             Doctrine_Connection object that created this table
     */
    private $connection;
    /**
     * @var string $name                                name of the component, for example component name of the GroupTable is 'Group'
     */
    private $name;
    /**
     * @var string $tableName                           database table name, in most cases this is the same as component name but in some cases
     *                                                  where one-table-multi-class inheritance is used this will be the name of the inherited table
     */
    private $tableName;
    /**
     * @var string $sequenceName                        Some databases need sequences instead of auto incrementation primary keys, you can set specific
     *                                                  sequence for your table by calling setSequenceName()
     */
    private $sequenceName;
    /**
     * @var array $identityMap                          first level cache
     */
    private $identityMap        = array();
    /**
     * @var Doctrine_Repository $repository             record repository
     */
    private $repository;

    /**
     * @var Doctrine_Cache $cache                       second level cache
     */
    private $cache;
    /**
     * @var array $columns                              an array of column definitions
     */
    private $columns;
    /**
     * @var array $bound                                bound relations
     */
    private $bound              = array();
    /**
     * @var array $boundAliases                         bound relation aliases
     */
    private $boundAliases       = array();
    /**
     * @var integer $columnCount                        cached column count, Doctrine_Record uses this column count in when
     *                                                  determining its state
     */
    private $columnCount;


    /**
     * @var array $inheritanceMap                       inheritanceMap is used for inheritance mapping, keys representing columns and values
     *                                                  the column values that should correspond to child classes
     */
    private $inheritanceMap     = array();
    /**
     * @var array $parents                              the parent classes of this component
     */
    private $parents            = array();
    /**
     * @var array $enum                                 enum value arrays
     */
    private $enum               = array();
    /**
     * @var boolean $hasDefaultValues                   whether or not this table has default values
     */
    private $hasDefaultValues;




    /**
     * the constructor
     * @throws Doctrine_Connection_Exception    if there are no opened connections
     * @throws Doctrine_Table_Exception         if there is already an instance of this table
     * @return void
     */
    public function __construct($name) {
        $this->connection = Doctrine_Manager::getInstance()->getCurrentConnection();

        $this->setParent($this->connection);

        $this->name = $name;

        if( ! class_exists($name) || empty($name))
            throw new Doctrine_Exception("Couldn't find class $name");

        $record = new $name($this);

        $names = array();

        $class = $name;

        // get parent classes

        do {
            if($class == "Doctrine_Record") 
                break;

           	$name  = $class;
            $names[] = $name;
        } while($class = get_parent_class($class));

        // reverse names
        $names = array_reverse($names);

        // create database table
        if(method_exists($record,"setTableDefinition")) {
            $record->setTableDefinition();

            $this->columnCount = count($this->columns);

            if(isset($this->columns)) {
                                      	
                // get the declaring class of setTableDefinition method
                $method    = new ReflectionMethod($this->name,"setTableDefinition");
                $class     = $method->getDeclaringClass();

                if( ! isset($this->tableName))
                    $this->tableName = Doctrine::tableize($class->getName());

                switch(count($this->primaryKeys)):
                    case 0:
                        $this->columns = array_merge(array("id" => array("integer",11, array("autoincrement" => true, "primary" => true))), $this->columns);
                        $this->primaryKeys[] = "id";
                        $this->identifier = "id";
                        $this->identifierType = Doctrine_Identifier::AUTO_INCREMENT;
                        $this->columnCount++;
                    break;
                    default:
                        if(count($this->primaryKeys) > 1) {
                            $this->identifier = $this->primaryKeys;
                            $this->identifierType = Doctrine_Identifier::COMPOSITE;

                        } else {
                            foreach($this->primaryKeys as $pk) {
                                $e = $this->columns[$pk][2];

                                $found = false;

                                foreach($e as $option => $value) {
                                    if($found)
                                        break;

                                    $e2 = explode(":",$option);

                                    switch(strtolower($e2[0])):
                                        case "autoincrement":
                                            $this->identifierType = Doctrine_Identifier::AUTO_INCREMENT;
                                            $found = true;
                                        break;
                                        case "seq":
                                            $this->identifierType = Doctrine_Identifier::SEQUENCE;
                                            $found = true;
                                        break;
                                    endswitch;
                                }
                                if( ! isset($this->identifierType))
                                    $this->identifierType = Doctrine_Identifier::NORMAL;

                                $this->identifier = $pk;
                            }
                        }
                endswitch;

                 if($this->getAttribute(Doctrine::ATTR_CREATE_TABLES)) {
                    if(Doctrine_DataDict::isValidClassname($class->getName())) {
                        $dict      = new Doctrine_DataDict($this->getConnection()->getDBH());
                        $dict->createTable($this->tableName, $this->columns);
                    }
                }

            }
        } else {
            throw new Doctrine_Exception("Class '$name' has no table definition.");
        }


        $record->setUp();

        // save parents
        array_pop($names);
        $this->parents   = $names;

        $this->query     = "SELECT ".implode(", ",array_keys($this->columns))." FROM ".$this->getTableName();

        // check if an instance of this table is already initialized
        if( ! $this->connection->addTable($this))
            throw new Doctrine_Table_Exception();
            
        $this->repository = new Doctrine_Repository($this);
    }
    /**
     * @return Doctrine_Repository
     */
    public function getRepository() {
        return $this->repository;
    }
    /**
     * setColumn
     * @param string $name
     * @param string $type
     * @param integer $length
     * @param mixed $options
     * @return void
     */
    final public function setColumn($name, $type, $length, $options = array()) {
        if(is_string($options)) 
            $options = explode('|', $options);

        foreach($options as $k => $option) {
            if(is_numeric($k)) {
                if( ! empty($option))
                    $options[$option] = true;

                unset($options[$k]);
            }
        }
        $name = strtolower($name);
        $this->columns[$name] = array($type,$length,$options);

        if(isset($options['primary'])) {
            $this->primaryKeys[] = $name;
        }
        if(isset($options['default'])) {
            $this->hasDefaultValues = true;
        }
    }
    /**
     * hasDefaultValues
     * returns true if this table has default values, otherwise false
     *
     * @return boolean
     */
    public function hasDefaultValues() {
        return $this->hasDefaultValues;
    }
    /**
     * getDefaultValueOf
     * returns the default value(if any) for given column
     *
     * @param string $column
     * @return mixed
     */
    public function getDefaultValueOf($column) {
        $column = strtolower($column);
        if( ! isset($this->columns[$column]))
            throw new Doctrine_Table_Exception("Couldn't get default value. Column ".$column." doesn't exist.");

        if(isset($this->columns[$column][2]['default'])) {

            return $this->columns[$column][2]['default'];
        } else
            return null;
    }
    /**
     * @return mixed
     */
    final public function getIdentifier() {
        return $this->identifier;
    }
    /**
     * @return integer
     */
    final public function getIdentifierType() {
        return $this->identifierType;
    }
    /**
     * hasColumn
     * @return boolean
     */
    final public function hasColumn($name) {
        return isset($this->columns[$name]);
    }
    /**
     * @param mixed $key
     * @return void
     */
    final public function setPrimaryKey($key) {
        switch(gettype($key)):
            case "array":
                $this->primaryKeys = array_values($key);
            break;
            case "string":
                $this->primaryKeys[] = $key;
            break;
        endswitch;
    }
    /**
     * returns all primary keys
     * @return array
     */
    final public function getPrimaryKeys() {
        return $this->primaryKeys;
    }
    /**
     * @return boolean
     */
    final public function hasPrimaryKey($key) {
        return in_array($key,$this->primaryKeys);
    }
    /**
     * @param $sequence
     * @return void
     */
    final public function setSequenceName($sequence) {
        $this->sequenceName = $sequence;
    }
    /**
     * @return string   sequence name
     */
    final public function getSequenceName() {
        return $this->sequenceName;
    }
    /**
     * getParents
     */
    final public function getParents() {
        return $this->parents;
    }
    /**
     * @return boolean
     */
    final public function hasInheritanceMap() {
        return (empty($this->inheritanceMap));
    }
    /**
     * setInheritanceMap
     * @param array $inheritanceMap
     * @return void
     */
    final public function setInheritanceMap(array $inheritanceMap) {
        $this->inheritanceMap = $inheritanceMap;
    }
    /**
     * @return array        inheritance map (array keys as fields)
     */
    final public function getInheritanceMap() {
        return $this->inheritanceMap;
    }
    /**
     * return all composite paths in the form [component1].[component2]. . .[componentN]
     * @return array
     */
    final public function getCompositePaths() {
        $array = array();
        $name  = $this->getComponentName();
        foreach($this->bound as $k=>$a) {
            try {
            $fk = $this->getRelation($k);
            switch($fk->getType()):
                case Doctrine_Relation::ONE_COMPOSITE:
                case Doctrine_Relation::MANY_COMPOSITE:
                    $n = $fk->getTable()->getComponentName();
                    $array[] = $name.".".$n;
                    $e = $fk->getTable()->getCompositePaths();
                    if( ! empty($e)) {
                        foreach($e as $name) {
                            $array[] = $name.".".$n.".".$name;
                        }
                    }
                break;
            endswitch;
            } catch(InvalidKeyException $e) {

            }
        }
        return $array;
    }
    /**
     * returns all bound relations
     *
     * @return array
     */
    final public function getBounds() {
        return $this->bound;
    }
    /**
     * returns a bound relation array
     *
     * @param string $name
     * @return array
     */
    final public function getBound($name) {
        if( ! isset($this->bound[$name]))
            throw new InvalidKeyException('Unknown bound '.$name);

        return $this->bound[$name];
    }
    /**
     * returns a bound relation array
     *
     * @param string $name
     * @return array
     */
    final public function getBoundForName($name) {
        foreach($this->bound as $k => $bound) {
            if($bound[3] == $name) {
                return $this->bound[$k];
            }
        }
        throw new InvalidKeyException('Unknown bound '.$name);
    }
    /**
     * returns the alias for given component name
     *
     * @param string $name
     * @return string
     */
    final public function getAlias($name) {
        if(isset($this->boundAliases[$name]))
            return $this->boundAliases[$name];

        return $name;
    }
    /**
     * returns component name for given alias
     *
     * @param string $alias
     * @return string
     */
    final public function getAliasName($alias) {
        if($name = array_search($this->boundAliases,$alias))
            return $name;

        throw new InvalidKeyException('Unknown alias '.$alias);
    }
    /**
     * unbinds all relations
     *
     * @return void
     */
    final public function unbindAll() {
        $this->bound        = array();
        $this->relations    = array();
        $this->boundAliases = array();
    }
    /**
     * unbinds a relation
     * returns true on success, false on failure
     *
     * @param $name
     * @return boolean
     */
    final public function unbind($name) {
        if( ! isset($this->bound[$name]))
            return false;

        unset($this->bound[$name]);

        if(isset($this->relations[$name]))
            unset($this->relations[$name]);

        if(isset($this->boundAliases[$name]))
            unset($this->boundAliases[$name]);

        return true;
    }
    /**
     * binds a relation
     *
     * @param string $name
     * @param string $field
     * @return void
     */
    final public function bind($name,$field,$type,$localKey) {
        if(isset($this->relations[$name]))
            throw new InvalidKeyException('Relation already set for '.$name);

        $e          = explode(" as ",$name);
        $name       = $e[0];

        if(isset($e[1])) {
            $alias = $e[1];
            $this->boundAliases[$name] = $alias;
        } else
            $alias = $name;


        $this->bound[$alias] = array($field,$type,$localKey,$name);
    }
    /**
     * getComponentName
     * @return string                   the component name
     */
    final public function getComponentName() {
        return $this->name;
    }
    /**
     * @return Doctrine_Connection
     */
    final public function getConnection() {
        return $this->connection;
    }
    /**
     * @return Doctrine_Cache
     */
    final public function getCache() {
        return $this->cache;
    }
    /**
     * hasRelatedComponent
     * @return boolean
     */
    final public function hasRelatedComponent($name, $component) {
         return (strpos($this->bound[$name][0], $component.'.') !== false);
    }
    /**
     * @param string $name              component name of which a foreign key object is bound
     * @return boolean
     */
    final public function hasRelation($name) {
        if(isset($this->bound[$name]))
            return true;

        foreach($this->bound as $k=>$v)
        {
            if($this->hasRelatedComponent($k, $name))
                return true;
        }
        return false;
    }
    /**
     * @param string $name              component name of which a foreign key object is bound
     * @return Doctrine_Relation
     */
    final public function getRelation($name) {
        $original = $name;

        if(isset($this->relations[$name]))
            return $this->relations[$name];

        if(isset($this->bound[$name])) {
            $type       = $this->bound[$name][1];
            $local      = $this->bound[$name][2];
            list($component, $foreign) = explode(".",$this->bound[$name][0]);
            $alias      = $name;
            $name       = $this->bound[$alias][3];

            $table      = $this->connection->getTable($name);

            if($component == $this->name || in_array($component, $this->parents)) {

                // ONE-TO-ONE
                if($type == Doctrine_Relation::ONE_COMPOSITE ||
                   $type == Doctrine_Relation::ONE_AGGREGATE) {
                    if( ! isset($local))
                        $local = $table->getIdentifier();

                    $relation = new Doctrine_LocalKey($table,$foreign,$local,$type, $alias);
                } else
                    throw new Doctrine_Table_Exception("Only one-to-one relations are possible when local reference key is used.");

            } elseif($component == $name || 
                    ($component == $alias && ($name == $this->name || in_array($name,$this->parents)))) {

                if( ! isset($local))
                    $local = $this->identifier;

                // ONE-TO-MANY or ONE-TO-ONE
                $relation = new Doctrine_ForeignKey($table, $local, $foreign, $type, $alias);

            } else {
                // MANY-TO-MANY
                // only aggregate relations allowed

                if($type != Doctrine_Relation::MANY_AGGREGATE)
                    throw new Doctrine_Table_Exception("Only aggregate relations are allowed for many-to-many relations");

                $classes = array_merge($this->parents, array($this->name));

                foreach(array_reverse($classes) as $class) {
                    try {
                        $bound = $table->getBoundForName($class);
                        break;
                    } catch(InvalidKeyException $exc) { }

                }
                if( ! isset($local))
                    $local = $this->identifier;

                $e2    = explode(".",$bound[0]);
                $fields = explode("-",$e2[1]);

                if($e2[0] != $component)
                    throw new Doctrine_Table_Exception($e2[0]." doesn't match ".$component);

                $associationTable = $this->connection->getTable($e2[0]);

                if(count($fields) > 1) {
                    // SELF-REFERENCING THROUGH JOIN TABLE
                    $this->relations[$e2[0]] = new Doctrine_ForeignKey($associationTable,$local,$fields[0],Doctrine_Relation::MANY_COMPOSITE, $e2[0]);

                    $relation = new Doctrine_Association_Self($table,$associationTable,$fields[0],$fields[1], $type, $alias);
                } else {

                    // auto initialize a new one-to-one relationship for association table
                    $associationTable->bind($this->getComponentName(),  $associationTable->getComponentName(). '.' .$e2[1], Doctrine_Relation::ONE_AGGREGATE, 'id');
                    $associationTable->bind($table->getComponentName(), $associationTable->getComponentName(). '.' .$foreign, Doctrine_Relation::ONE_AGGREGATE, 'id');

                    // NORMAL MANY-TO-MANY RELATIONSHIP
                    $this->relations[$e2[0]] = new Doctrine_ForeignKey($associationTable,$local,$e2[1],Doctrine_Relation::MANY_COMPOSITE, $e2[0]);

                    $relation = new Doctrine_Association($table, $associationTable, $e2[1], $foreign, $type, $alias);
                }

            }
            $this->relations[$alias] = $relation;
            return $this->relations[$alias];
        }
        try {
            throw new Doctrine_Table_Exception($this->name . " doesn't have a relation to " . $original);
        } catch(Exception $e) {
            print $e;
        }
    }
    /**
     * returns an array containing all foreign key objects
     *
     * @return array
     */
    final public function getRelations() {
        $a = array();
        foreach($this->bound as $k=>$v) {
            $this->getRelation($k);
        }

        return $this->relations;
    }
    /**
     * sets the database table name
     *
     * @param string $name              database table name
     * @return void
     */
    final public function setTableName($name) {
        $this->tableName = $name;
    }

    /**
     * returns the database table name
     *
     * @return string
     */
    final public function getTableName() {
        return $this->tableName;
    }
    /**
     * create
     * creates a new record
     *
     * @param $array                    an array where keys are field names and values representing field values
     * @return Doctrine_Record
     */
    public function create(array $array = array()) {
        $this->data         = $array;
        $this->isNewEntry   = true;
        $record = new $this->name($this);
        $this->isNewEntry   = false;
        $this->data         = array();
        return $record;
    }
    /**
     * finds a record by its identifier
     *
     * @param $id                       database row id
     * @throws Doctrine_Find_Exception
     * @return Doctrine_Record          a record for given database identifier
     */
    public function find($id) {
        if($id !== null) {
            if( ! is_array($id))
                $id = array($id);
            else
                $id = array_values($id);

            $query  = $this->query." WHERE ".implode(" = ? AND ",$this->primaryKeys)." = ?";
            $query  = $this->applyInheritance($query);


            $params = array_merge($id, array_values($this->inheritanceMap));

            $stmt  = $this->connection->execute($query,$params);

            $this->data = $stmt->fetch(PDO::FETCH_ASSOC);

            if($this->data === false)
                return false;
        }
        return $this->getRecord();
    }
    /**
     * applyInheritance
     * @param $where                    query where part to be modified
     * @return string                   query where part with column aggregation inheritance added
     */
    final public function applyInheritance($where) {
        if( ! empty($this->inheritanceMap)) {
            $a = array();
            foreach($this->inheritanceMap as $field => $value) {
                $a[] = $field." = ?";
            }
            $i = implode(" AND ",$a);
            $where .= " AND $i";
        }
        return $where;
    }
    /**
     * findAll
     * returns a collection of records
     *
     * @return Doctrine_Collection
     */
    public function findAll() {
        $graph = new Doctrine_Query($this->connection);
        $users = $graph->query("FROM ".$this->name);
        return $users;
    }
    /**
     * findByDql
     * finds records with given DQL where clause
     * returns a collection of records
     *
     * @param string $dql               DQL after WHERE clause
     * @param array $params             query parameters
     * @return Doctrine_Collection
     */
    public function findBySql($dql, array $params = array()) {
        $q = new Doctrine_Query($this->connection);
        $users = $q->query("FROM ".$this->name." WHERE ".$dql, $params);
        return $users;
    }

    public function findByDql($dql, array $params = array()) {
        return $this->findBySql($dql, $params);
    }
    /**
     * clear
     * clears the first level cache (identityMap)
     *
     * @return void
     */
    public function clear() {
        $this->identityMap = array();
    }
    /**
     * getRecord
     * first checks if record exists in identityMap, if not
     * returns a new record
     *
     * @return Doctrine_Record
     */
    public function getRecord() {
        $this->data = array_change_key_case($this->data, CASE_LOWER);

        $key = $this->getIdentifier();

        if( ! is_array($key))
            $key = array($key);

        foreach($key as $k) {
            if( ! isset($this->data[$k]))
                throw new Doctrine_Exception("Primary key value for $k wasn't found");

            $id[] = $this->data[$k];
        }

        $id = implode(' ', $id);

        if(isset($this->identityMap[$id]))
            $record = $this->identityMap[$id];
        else {
            $record = new $this->name($this);
            $this->identityMap[$id] = $record;
        }
        $this->data = array();

        return $record;
    }
    /**
     * @param $id                       database row id
     * @throws Doctrine_Find_Exception
     * @return DAOProxy                 a proxy for given identifier
     */
    final public function getProxy($id = null) {
        if($id !== null) {
            $query = "SELECT ".implode(", ",$this->primaryKeys)." FROM ".$this->getTableName()." WHERE ".implode(" = ? && ",$this->primaryKeys)." = ?";
            $query = $this->applyInheritance($query);

            $params = array_merge(array($id), array_values($this->inheritanceMap));

            $this->data = $this->connection->execute($query,$params)->fetch(PDO::FETCH_ASSOC);

            if($this->data === false)
                return false;
        }
        return $this->getRecord();
    }
    /**
     * getTableDescription
     * @return Doctrine_Table_Description
     */
    final public function getTableDescription() {
        return $this->columns;
    }
    /**
     * count
     *
     * @return integer
     */
    public function count() {
        $a = $this->connection->getDBH()->query("SELECT COUNT(1) FROM ".$this->tableName)->fetch(PDO::FETCH_NUM);
        return current($a);
    }
    /**
     * @return Doctrine_Query                           a Doctrine_Query object
     */
    public function getQueryObject() {
        $graph = new Doctrine_Query($this->getConnection());
        $graph->load($this->getComponentName());
        return $graph;
    }
    /**
     * execute
     * @param string $query
     * @param array $array
     * @param integer $limit
     * @param integer $offset
     */
    public function execute($query, array $array = array(), $limit = null, $offset = null) {
        $coll  = new Doctrine_Collection($this);
        $query = $this->connection->modifyLimitQuery($query,$limit,$offset);
        if( ! empty($array)) {
            $stmt = $this->connection->getDBH()->prepare($query);
            $stmt->execute($array);
        } else {
            $stmt = $this->connection->getDBH()->query($query);
        }
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $stmt->closeCursor();

        foreach($data as $row) {
            $this->data = $row;
            $record = $this->getRecord();
            $coll->add($record);
        }
        return $coll;
    }
    /**
     * sets enumerated value array for given field
     *
     * @param string $field
     * @param array $values
     * @return void
     */
    final public function setEnumValues($field, array $values) {
        $this->enum[strtolower($field)] = $values;
    }
    /**
     * @param string $field
     * @return array
     */
    final public function getEnumValues($field) {
        if(isset($this->enum[$field]))
            return $this->enum[$field];
        else
            return array();
    }
    /**
     * enumValue
     *
     * @param string $field
     * @param integer $index
     * @return mixed
     */
    final public function enumValue($field, $index) {
        return isset($this->enum[$field][$index])?$this->enum[$field][$index]:$index;
    }
    /**
     * enumIndex
     *
     * @param string $field
     * @param mixed $value
     * @return mixed
     */
    final public function enumIndex($field, $value) {
        if( ! isset($this->enum[$field])) 
            $values = array();
        else
            $values = $this->enum[$field];

        return array_search($value, $values);
    }
    /**
     * @return integer
     */
    final public function getColumnCount() {
        return $this->columnCount;
    }

    /**
     * returns all columns and their definitions
     *
     * @return array
     */
    final public function getColumns() {
        return $this->columns;
    }
    /**
     * returns an array containing all the column names
     *
     * @return array
     */
    public function getColumnNames() {
        return array_keys($this->columns);
    }
    /**
     * getDefinitionOf
     *
     * @return mixed        array on success, false on failure
     */
    public function getDefinitionOf($column) {
        if(isset($this->columns[$column]))
            return $this->columns[$column];

        return false;
    }
    /**
     * getTypeOf
     *
     * @return mixed        string on success, false on failure
     */
    public function getTypeOf($column) {
        if(isset($this->columns[$column]))
            return $this->columns[$column][0];

        return false;
    }
    /**
     * setData
     * doctrine uses this function internally
     * users are strongly discouraged to use this function
     *
     * @param array $data               internal data
     * @return void
     */
    public function setData(array $data) {
        $this->data = $data;
    }
    /**
     * returns the maximum primary key value
     *
     * @return integer
     */
    final public function getMaxIdentifier() {
        $sql  = "SELECT MAX(".$this->getIdentifier().") FROM ".$this->getTableName();
        $stmt = $this->connection->getDBH()->query($sql);
        $data = $stmt->fetch(PDO::FETCH_NUM);
        return isset($data[0])?$data[0]:1;
    }
    /**
     * return whether or not a newly created object is new or not
     *
     * @return boolean
     */
    final public function isNewEntry() {
        return $this->isNewEntry;
    }
    /**
     * returns simple cached query
     *
     * @return string
     */
    final public function getQuery() {
        return $this->query;
    }
    /**
     * returns internal data, used by Doctrine_Record instances
     * when retrieving data from database
     *
     * @return array
     */
    final public function getData() {
        return $this->data;
    }
    /**
     * returns a string representation of this object
     *
     * @return string
     */
    public function __toString() {
        return Doctrine_Lib::getTableAsString($this);
    }
}

