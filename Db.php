<?php
class Db {
	/**
	 * Configuration storage
	 * @var array
	 */
	protected static $config = array(
		'driver' => 'mysql',
		'host'   => 'localhost',
		'fetch'  => 'stdClass'
	);
	/**
	 * Get and set Db configurations
	 * @uses   static::config
	 * @param  string|array $key   Name of configuration or hash array of configurations names / values
	 * @param  mixed        $value Value of the configuration
	 * @return mixed        
	 */
	static public function config ( $key = null, $value = null ) {
		if ( ! isset( $key ) )
			return static::$config;
		if ( isset( $value ) )
			return static::$config[ (string) $key ] = $value;
		if ( is_array( $key ) )
			return array_map( 'static::config', array_keys( (array) $key ), array_values( (array) $key ) );
		if ( isset( static::$config[ $key ] ) )
			return static::$config[ $key ];
	}
	/**
	 * Database connection
	 * @var PDO
	 */
	protected $db;
	/**
	 * Latest query statement
	 * @var PDOStatement
	 */
	protected $statement;
	/**
	 * Constructor
	 * @uses   PDO
	 * @param  string $dsn   Name of configuration or hash array of configurations names / values
	 * @param  string $user   
	 * @param  string $pass  
	 */
	public function __construct ( $dns, $user, $pass = null ) {
		$this->db = new pdo( $dns, $user, $pass, array(
			PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
		) );
	}
	/* Static instance */
	protected static $instance;
	static public function instance () {
		return static::$instance ?: 
			static::$instance = new static( 
				static::config( 'dsn' ) ?:
					static::config( 'dsn', static::config( 'driver' ) . ':host=' . static::config( 'host' ) . ';dbname=' . static::config( 'database' ) ), static::config( 'user' ), static::config( 'password' )
			);
	}
	/* Query methods */
	public function raw ( $sql ) {
		$this->statement = $this->db->query( $sql );
		return $this;
	}
	public function query ( $sql, array $params ) {
		$name = 'STATEMENT:' . $sql;
		if ( $config = self::config( $name ) )
			$this->statement = $config;
		else
			$this->statement = self::config( $name, $this->db->prepare( self::_uncomment( $sql ) ) );
		$this->statement->execute( $params );
		return $this;
	}
//@todo
	public function select ( $table, $fields = '*', $where = null, $order = null, $limit = null ) {
		$sql = 'SELECT ' . self::_fields( $fields ) . ' FROM ' . $this->_escape( $table );
		if ( $where && $where = $this->_conditions( $where ) )
			$sql .= ' WHERE ' . $where->sql;
		if ( $order )
			$sql .= ' ORDER BY ' . $order;
		if ( $limit )
			$sql .= ' LIMIT ' . $limit;
		return $where ?
			$this->query( $sql, $where->params ) :
			$this->raw( $sql );
	}
	/* Query formating helpers */
	static protected function _is_plain ( $data ) {
		if ( ! is_scalar( $data ) )
			return false;
		return is_string( $data ) ? ! preg_match( '/\W/i', $data ) : true;
	}
	static protected function _is_list ( array $array ) {
		foreach ( array_keys( $array ) as $key )
			if ( ! is_int( $key ) )
				return false;
		return true;
	}
	static protected function _uncomment ( $sql ) {
		/* '@
		(([\'"]).*?[^\\\]\2) # $1 : Skip single & double quoted expressions
		|(                   # $3 : Match comments
			(?:\#|--).*?$    # - Single line comments
			|                # - Multi line (nested) comments
			 /\*             #   . comment open marker
				(?: [^/*]    #   . non comment-marker characters
					|/(?!\*) #   . ! not a comment open
					|\*(?!/) #   . ! not a comment close
					|(?R)    #   . recursive case
				)*           #   . repeat eventually
			\*\/             #   . comment close marker
		)\s*                 # Trim after comments
		|(?<=;)\s+           # Trim after semi-colon
		@msx' */
		return trim( preg_replace( '@(([\'"]).*?[^\\\]\2)|((?:\#|--).*?$|/\*(?:[^/*]|/(?!\*)|\*(?!/)|(?R))*\*\/)\s*|(?<=;)\s+@ms', '$1', $sql ) );
	}
//@todo remove this
	static protected function _param ( $param ) {
		return self::_is_plain( $param ) ?
			':' . $param :
			'?';
	}
//@todo to work with / like _conditions ()
	static protected function _params ( $data, $operator = '=', $glue = ', ' ) {
		$params = is_string( $data) ? array( $data ) : array_keys( (array) $data );
		foreach ( $params as &$param )
			$param = implode( ' ', array( self::_escape( $param ), $operator, self::_param( $param ) ) );
		return implode( $glue, $params );
	}
	static protected function _escape ( $field ) {
		return self::_is_plain( $field ) ?
			'`' . $field  . '`' :
			$field;
	}
	static protected function _fields ( $fields ) {
		if ( empty( $fields ) )
			return '*';
		if ( is_string( $fields ) )
			return $fields;
		$_fields = array();
		foreach ( $fields as $alias => $field )
			$_fields[] = self::_escape( $field ) . ( is_string( $alias ) ? ' AS `' . $alias . '`' : '' );
		return implode( ', ', $_fields );
	}
//@todo
	static protected function _conditions ( array $conditions ) {
		$sql    = array();
		$params = array();
		$i      = 0;
		foreach ( $conditions as $condition => $param ) {
			if ( is_string( $condition ) ) {
				for ( $keys = array(), $n = 0; false !== ( $n = strpos( $condition, '?', $n ) ); $n ++ )
					$condition = substr_replace( $condition, ':' . ( $keys[] = '_' . ++ $i ), $n, 1 );
				if ( ! empty( $keys ) )
					$param = array_combine( $keys, (array) $param );
				$params += (array) $param;
				if ( self::_is_plain( $condition ) ) // change condiftion by reference ?
					$condition = self::_params( $condition );
			} else
				$condition = $param;
			$sql[]= $condition;
		}
		return (object) array( 
			'sql'    => '( ' . implode( ' ) AND ( ', $sql ) . ' )',
			'params' => $params
		);
	}
	/* Data column helpers */
	static protected function _column ( array $data, $field ) {
		$column = array();
		foreach ( $data as $row )
			if ( is_object( $row ) && isset( $row->{$field} ) )
				$column[] = $row->{$field};
			else if ( is_array( $row ) && isset( $row[ $field ] ) )
				$column[] = $row[ $field ];
			else 
				$column[] = null;
		return $column;
	}
	static protected function _index ( array $data, $field ) {
		return array_combine(
			self::_column( $data, $field ),
			$data
		);
	}
	/* CRUD methods */
	public function create ( $table, array $data ) {
		$keys = array_keys( $data );
		$sql  = 'INSERT INTO ' . $this->_escape( $table ) . ' (' . implode( ', ', $keys ) . ') VALUES (:' . implode( ', :', $keys ) . ')';
		return $this->query( $sql, $data );  
	}
	//public function read ( $table, $where ) 
	public function read ( $table, $id, $key = null ) {
		$key = $key ?: current( $this->key( $table ) );
		$sql = 'SELECT * FROM ' . $this->_escape( $table ) . ' WHERE ' . self::_params( $key );
		return $this->query( $sql, array( ':' . $key => $id ) );
	}
	//public function update ( $table, $data, $where )
	public function update ( $table, $data, $value = null, $id = null, $key = null ) {
		if ( is_array( $data ) ) {
			$key  = $id;
			$id   = $value;
		} else
			$data = array( $data => $value );
		$key = $key ?: current( $this->key( $table ) );
		if ( is_null( $id ) && isset( $data[ $key ] ) && ! ( $id = $data[ $key ] ) )
			throw new Exception( 'No `' . $key . '` key value to update `' . $table . '` table, please specify a key value' );
		$sql = 'UPDATE ' . $this->_escape( $table ) . ' SET ' . self::_params( $data ) . ' WHERE ' . self::_params( $key );
		return $this->query( $sql, array_merge( $data, array( ':' . $key => $id ) ) );
	}
	//public function delete ( $table, $where )
	public function delete ( $table, $id, $key = null ) {
		$key = $key ?: current( $this->key( $table ) );
		$sql = 'DELETE FROM ' . $this->_escape( $table ) . ' WHERE ' . self::_params( $key );
		return $this->query( $sql, array( ':' . $key => $id ) );
	}
	/* Fetch methods */
	public function fetch ( $class = null ) {
		if ( ! $this->statement )
			throw new Exception( 'Can\'t fetch result if no query!' );
		return $class === false ?
			$this->statement->fetch( PDO::FETCH_ASSOC ) :
			$this->statement->fetchObject( $class ?: self::config( 'fetch' ) ?: 'stdClass' );
	}
	public function all ( $class = null ) {
		if ( ! $this->statement )
			throw new Exception( 'Can\'t fetch results if no query!' );
		return $class === false ?
			$this->statement->fetchAll( PDO::FETCH_ASSOC ) :
			$this->statement->fetchAll( PDO::FETCH_CLASS, $class ?: self::config( 'fetch' ) ?: 'stdClass' );
	}
	public function column ( $field, $index = null ) {
		$data   = $this->all( false );
		$values = self::_column( $data, $field );
		return is_string( $index ) ?
			array_combine( self::_column( $data, $index ), $values ) :
			$values;
	}
	/* Table infos */
	public function key ( $table ) {
		$name = 'KEY:' . $table;
		if ( $config = self::config( $name ) )
			return $config;
		$sql = 'SELECT `COLUMN_NAME`
			FROM `INFORMATION_SCHEMA`.`COLUMNS`
			WHERE `TABLE_NAME` = ' . $this->quote( $table ) . ' AND `COLUMN_KEY` = "PRI"
			ORDER BY `ORDINAL_POSITION` ASC';
		$key = $this->db->query( $sql );
		if ( ! $key->rowCount() )
			throw new Exception( 'No primary key on `' . $table . '` table, please set a primary key' );
		return self::config( $name, $key->fetchAll( PDO::FETCH_COLUMN, 0 ) );
	}
	public function fields ( $table ) {
		$name = 'FIELDS:' . $table;
		if ( $config = self::config( $name ) )
			return $config;
		$sql = 'SELECT 
			`COLUMN_NAME`                                               AS `name`, 
			`COLUMN_DEFAULT`                                            AS `default`, 
			NULLIF( `IS_NULLABLE`, "NO" )                               AS `null`, 
			`DATA_TYPE`                                                 AS `type`, 
			COALESCE( `CHARACTER_MAXIMUM_LENGTH`, `NUMERIC_PRECISION` ) AS `length`, 
			`CHARACTER_SET_NAME`                                        AS `encoding`, 
			`COLUMN_KEY`                                                AS `key`, 
			`EXTRA`                                                     AS `auto`, 
			`COLUMN_COMMENT`                                            AS `comment`
			FROM `INFORMATION_SCHEMA`.`COLUMNS`
			WHERE `TABLE_NAME` = ' . $this->quote( $table ) . '
			ORDER BY `ORDINAL_POSITION` ASC';
		$fields = $this->db->query( $sql );
		if ( ! $fields->rowCount() )
			throw new Exception( 'No `' . $table . '` table, please specify a valid table' );
		return self::config( $name, self::_index( $fields->fetchAll( PDO::FETCH_CLASS ), 'name' ) );
	}
	/* Quote Helper */
	public function quote ( $value ) {
		return is_null( $value ) ?
			'NULL' : 
			$this->db->quote( $value );
	}
	/* Statement infos */
	public function id () {

		return $this->db->lastInsertId();
	}
	public function count () {
		return $this->statement ? 
			$this->statement->rowCount() :
			null;
	}
	public function sql () {
		return $this->statement ? 
			$this->statement->queryString :
			null;
	}
}
Class Mysql extends Db {
	protected static $config = array(
		'driver' => 'mysql',
		'host'   => 'localhost'
	);
	
}

echo '<pre>';
Mysql::config( array(
	'database' => 'test',
	'user'     => 'root',
	'password' => 'azerty'
) );
//Mysql::instance()->create( 'type', array( 'content' => 'pouet ') );
$where = array( 
	'content = "pouet "',                                           // do nothing
	//'content'                   => 'pouet ',                        // _params!  -> content = "pouet "
	//'content'                   => 'p%',                            // _params? -> content LIKE "%p"
	//'id'                        => array( 1, 2 ),                   // _params? -> id IN ( 1, 2 )
	'url LIKE ?'                => '%.php',                         // don't use ? placeholder -> url LIKE "%.php"
	'note <= :note OR id = :id' => array( 'note' => 2, 'id' => 1 ), // redy for query + execute
	'note <= ? OR id = ?'       => array( 2, 1 )                    // don't use ? placeholder -> note <= 2 OR id = 1
);
var_dump(
//*
	//Mysql::instance()->read( 'type', 1 )->column( 'content', 'id' ),
	Mysql::instance()->select( 'type', array( 'count' => 'count(id)', '`url` AS u', 'content' ), $where )->fetch(),
	Mysql::instance()->sql()
	//Mysql::instance()->key( 'type' ),
	//array_keys( Mysql::instance()->fields( 'type' ) ),
	//Mysql::config()
//*/
);
