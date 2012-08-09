<?php

/**
 *
 * Clase para tener objetos que pueden ser usados como arrays;
 *
 * IMPORTANTE: usar $obj[$key][$keyDelArrayInterno] = $value NO FUNCIONARÁ, pues no es posible que offsetGet() devuelva por referencia
 *
 * <code>
 * $a = new EtuDev_PseudoArray_Object();
 * $a['interno'] = array();
 * $a['interno']['extra']="si";
 * </code>
 *
 * el ultimo valor "si" NO SE GUARDA, porque $a['interno'] es en realidad una copia (SE PASA POR VALOR)
 * luego se esta estableciendo un valor en una copia desechable, el original no se modifica
 *
 * Para evitar esto se ha de activar el flag OFFSET_ARRAYS_AS_PSEUDOARRAY que generará pseudoarrays que hagan de wrappers,
 * y que serán convertidos de vuelta a arrays siempre que se recojan salvo con el offsetGet() (incluído el iterator)
 *
 * Acepta diferentes propertylevels usando el tag "@propertylevel" y una lista de nombres separados por comas (sin espacios), si esta vacia se asume todos los niveles, que es la opción por defecto
 * Acepta diferentes propertyalias, diferentes nombres en los que la misma propiedad puede ser accedida y establecida la misma propiedad, usando el tag "@ propertyalias aliasName realAttributeName" (sin el espacio entre @ y propertyalias)
 *
 * Acepta tag especial "@ propertyignored property" (sin el espacio entre @ y propertyalias) que se usa para indicar que la property con la etiqueta debe ser ignorada a la hora de hacer un toArray()
 *
 * @author eturino
 * @version 5.0 (April 2012) (menos funcionalidad pero mucha más velocidad. Ya no se permiten properties de instancia ni estáticas)
 */
class EtuDev_PseudoArray_Object implements Iterator, ArrayAccess, SeekableIterator, Countable, EtuDev_Interfaces_PreparedForCache, EtuDev_Interfaces_ToArrayAbleFull {

	const LEVEL_ALL              = '';
	const TO_ARRAY_LEVEL_DEFAULT = self::LEVEL_ALL;

	/**
	 * flag de comportamiento que indica que los arrays recogidos por offsetGet() seran convertidos primero en pseudoarrays
	 * @var int
	 */
	const OFFSET_ARRAYS_AS_PSEUDOARRAY = 1;

	/**
	 * flag de comportamiento que indica que ningún dato recogido por offsetGet() será modificado ni convertido, se pasan por valor siempre
	 * @var int
	 */
	const OFFSET_NORMAL = 0;

	protected $_position;

	/**
	 * @var bool if false it will ignore any parameter that is not defined in the docblocks
	 */
	protected $_allow_not_defined = true;


	/** @var array precalculated properties */
	protected $_properties_by_level = array();

	/**
	 * @var array
	 */
	protected $_ignore_to_array = array();

	/**
	 * flag activo
	 * @var int
	 * @see OFFSET_ARRAYS_AS_PSEUDOARRAY
	 * @see OFFSET_NORMAL
	 */
	private $_flag = 0;

	/**
	 * si true, indica que este pseudoarray es en realidad un array normal y fue creado como wrapper por otro pseudo array con el flag OFFSET_ARRAYS_AS_PSEUDOARRAY
	 * @var bool $_isWrapperOfArray
	 */
	private $_isWrapperOfArray = false;

	/**
	 * contenedor de los elementos nuevos (anteriormente _container. cambiamos nombre para compatibilizar con zend_db_table_row)
	 * @var array $_data
	 */
	protected $_data = array();


	//TODO: calcular getters para los atributos Y para los alias de esos atributos
	protected $_getters = array();
	protected $_setters = array();
	protected $_aliases = array();
	protected $_aliases_different = array();

	final public function _getContainer() {
		return $this->_data;
	}

	/**
	 * get the constant in the object, not using "self::" if it can be redefined
	 *
	 * @param string $name
	 *
	 * @return mixed
	 */
	public function _getConstant($name) {
		$r = new ReflectionObject($this);
		return $r->hasConstant($name) ? $r->getConstant($name) : null;
	}

	/**
	 * si se pasa colección de datos originales, se agregan al objeto
	 *
	 * @param array|Traversable $originalData
	 *
	 * @uses setValuesFromOriginalData()
	 * @throws Exception
	 */
	public function __construct($originalData = null) {
		$this->_loadClassInfo();

		$this->_readyBeforeUse();
		$this->setValuesFromOriginalData($originalData);
	}

	protected function _loadClassInfo() {
		$info     = EtuDev_PseudoArray_Factory::getInfo(get_called_class());
		$keyscont = array_keys($this->_data);

		$this->_aliases             = $keyscont ? array_merge(array_combine($keyscont, $keyscont), (array) $info['aliases']) : (array) $info['aliases'];
		$this->_aliases_different   = $info['aliases_different'];
		$this->_getters             = $info['getters'];
		$this->_setters             = $info['setters'];
		$this->_properties_by_level = $info['levels'];
		$this->_ignore_to_array     = $info['ignore_to_array'];
	}

	protected function _readyBeforeUse() {

	}

	/**
	 * prepare some attributes before cached
	 * @return void
	 */
	public function prepareForCache() {
		$this->_aliases             = array();
		$this->_getters             = array();
		$this->_setters             = array();
		$this->_properties_by_level = array();
		$this->_ignore_to_array     = array();
	}

	/**
	 * prepare some attributes before cached
	 * @return void
	 */
	public function afterCache() {
		$this->_loadClassInfo();
	}

	public function __wakeup() {
		$this->_loadClassInfo();
		$this->_position = 0;
		$this->_readyBeforeUse();
	}


	/**
	 * setter para $_isWrapperOfArray
	 *
	 * @param bool $is
	 *
	 * @uses $_isWrapperOfArray
	 */
	protected function setIsWrapperOfArray($is) {
		$this->_isWrapperOfArray = $is;
	}

	/**
	 * getter para $_isWrapperOfArray
	 * @return bool
	 * @uses $_isWrapperOfArray
	 */
	public function isWrapperOfArray() {
		return $this->_isWrapperOfArray;
	}

	/**
	 * establece el flag de comportamiento
	 *
	 * @param int $flag
	 *
	 * @uses OFFSET_ARRAYS_AS_PSEUDOARRAY
	 * @uses OFFSET_NORMAL
	 */
	public function setFlag($flag) {
		if ($flag == self::OFFSET_ARRAYS_AS_PSEUDOARRAY) {
			$this->_flag = self::OFFSET_ARRAYS_AS_PSEUDOARRAY;
		}

		if ($flag == self::OFFSET_NORMAL) {
			$this->_flag = self::OFFSET_NORMAL;
		}
	}

	/**
	 * replace the data content with this one, WARNING: use ONLY when it is clear that we can do this
	 *
	 * @param array $originalData
	 *
	 */
	public function replaceWholeContainer(array $originalData) {
		if (!$this->_allow_not_defined) {
			$originalData = array_intersect_key($originalData, $this->_aliases);
		}

		$this->_data = $originalData;
		if ($originalData) {
			$newkeys        = array_keys($this->_data);
			$this->_aliases = array_merge(array_combine($newkeys, $newkeys), (array) $this->_aliases);
		}
	}

	public function setValuesFromOriginalDataIgnoreChecks($originalData) {
		return $this->doSetValuesFromOriginalData($originalData, true);
	}


	/**
	 * foreach element in the originalData, we call $this->$k = $v
	 *
	 * @param array|Traversable $originalData
	 *
	 * @uses __set()
	 * @throws Exception
	 * @return bool
	 */
	public function setValuesFromOriginalData($originalData) {
		return $this->doSetValuesFromOriginalData($originalData, false);
	}

	/**
	 * foreach element in the originalData, we call $this->$k = $v
	 *
	 * @param array|Traversable $originalData
	 *
	 * @uses __set()
	 * @throws Exception
	 * @return bool
	 */
	protected function doSetValuesFromOriginalData($originalData, $ignoreChecks = false) {

		if ($originalData) {
			try {

				if ($originalData instanceof EtuDev_Interfaces_ToArrayAble) {
					$originalData = $originalData->toArray();
				}

				if (is_array($originalData) && $originalData) {
					//check aliases

					if ($this->_aliases_different) {
						$a = array();
						foreach ($originalData as $k => $v) {
							$a[@$this->_aliases[$k] ? : $k] = $v;
						}
						$originalData = $a;
					}

					if (!$ignoreChecks && !$this->_allow_not_defined) {
						$originalData = array_intersect_key($originalData, $this->_aliases);
					}


					//los que no tienen setter se meten directamente
					$notSetter = array_diff_key($originalData, $this->_setters);

					if ($notSetter) {
						$this->_data = array_merge($this->_data, $notSetter);

						//aseguramos los nuevos alias
						$newkeys = array_keys($this->_data);

						if ($this->_aliases) {
							$this->_aliases = array_merge(array_combine($newkeys, $newkeys), (array) $this->_aliases);
						} else {
							$this->_aliases = array_combine($newkeys, $newkeys);
						}
					}

					//si hay más, van por setter (no es necesario añadir alias, pues si tienen setter es que están definidos en la clase)
					if (count($notSetter) < count($originalData)) {
						$withSetter = array_diff_key($originalData, $notSetter);
						foreach ($withSetter as $k => $v) {
							$s = $this->_setters[$k];
							$this->$s($v);
						}
					}
				} else {
					foreach ($originalData as $k => $v) {
						$this->_set($k, $v);
					}
				}


			} catch (Exception $e) {
				//eturino 20110107 we no longer use Traversable because there are some traversable classes that don't implements this (like Solr Objects)
				throw new Exception("non Traversable data (nor trav. object nor array)");
			}

			return true;
		}

		return false;
	}

	/**
	 * foreach element in the originalData, we call $this->$k = $v, ONLY if it is not already set
	 *
	 * @param array|Traversable $originalData
	 *
	 * @uses _set()
	 * @throws Exception
	 */
	public function setValuesOnlyIfNotSetted($originalData) {
		if ($originalData) {
			try {
				if ($originalData instanceof EtuDev_Interfaces_ToArrayAble) {
					$originalData = $originalData->toArray();
				}

				if (is_array($originalData) && $originalData) {
					if (!$this->_data) {
						return $this->setValuesFromOriginalData($originalData);
					}
					//podemos quitar los que ya estén en el container
					if ($this->_aliases_different) {
						$a = array();
						foreach ($originalData as $k => $v) {
							$a[@$this->_aliases[$k] ? : $k] = $v;
						}
						$originalData = $a;
					}

					$not_data = array_diff_key($originalData, $this->_data);
					return $this->setValuesFromOriginalData($not_data);
				} else {
					foreach ($originalData as $k => $v) {
						if (!$this->offsetExists($k)) {
							$this->_set($k, $v);
						}
					}
				}
			} catch (Exception $e) {
				//eturino 20110107 we no longer use Traversable because there are some traversable classes that don't implements this (like Solr Objects)
				throw new Exception("non Traversable data (nor trav. object nor array)");
			}

		}
	}

	/**
	 * foreach element in the originalData, we call $this->$k = $v, ONLY if it is not already set
	 *
	 * @param array|Traversable $originalData
	 *
	 * @uses _set()
	 * @throws Exception
	 * @return boolean
	 */
	public function setValuesOnlyIfNull($originalData) {
		if ($originalData) {
			try {
				if ($originalData instanceof EtuDev_Interfaces_ToArrayAble) {
					$originalData = $originalData->toArray();
				}

				if (is_array($originalData) && $originalData) {
					if (!$this->_data) {
						return $this->setValuesFromOriginalData($originalData);
					}

					if ($this->_aliases_different) {
						$a = array();
						foreach ($originalData as $k => $v) {
							$a[@$this->_aliases[$k] ? : $k] = $v;
						}
						$originalData = $a;
					}

					//podemos quitar los que ya estén en el container (salvo null)
					$data     = array_filter($this->_data, function($v) {
						return !is_null($v);
					});
					$not_data = array_diff_key($originalData, $data);

					return $this->setValuesFromOriginalData($not_data);
				} else {
					foreach ($originalData as $k => $v) {
						if (!isset($this->_data[$k])) { //isset devuelve false si es NULL
							$this->_set($k, $v);
						}
					}
					return true;
				}
			} catch (Exception $e) {
				//eturino 20110107 we no longer use Traversable because there are some traversable classes that don't implements this (like Solr Objects)
				throw new Exception("non Traversable data (nor trav. object nor array)");
			}

		}
	}

	/**
	 * comprueba si el valor es válido (para extender, por defecto siempre TRUE)
	 *
	 * @param mixed $value
	 *
	 * @return bool
	 */
	public function valueIsValid($value) {
		//TODO extender
		return true;
	}

	/**
	 * setter automático llamado usando $obj[$key]=$value
	 * Si no es del tipo válidoo lanza excepción
	 *
	 * @param string|int $key
	 * @param mixed      $value
	 *
	 * @uses _set()
	 * @return mixed
	 */
	public function offsetSet($key, $value) {
		return $this->_set($key, $value);
	}

	/**
	 * equivalente a array_key_exists($key, $this->_data)
	 *
	 * @param string $key
	 *
	 * @uses $_data
	 * @return bool
	 */
	public function offsetExists($key) {
		return $this->__isset($key);
	}


	/**
	 * equivalente a array_key_exists($key, $this->_data)
	 *
	 * @param string $key
	 *
	 * @uses $_data
	 * @return bool
	 */
	public function __isset($key) {
		$k = @$this->_aliases[$key] ? : $key;
		return array_key_exists($k, $this->_data);
	}


	/**
	 * equivalente a unset($this->_data[$key]);
	 *
	 * @param string $key
	 *
	 * @uses $_data
	 */
	public function offsetUnset($key) {
		$this->_unset($key);
	}

	public function _unset($key) {
		$k = @$this->_aliases[$key] ? : $key;
		unset($this->_data[$k]);
	}

	/**
	 * getter automático, llamado usando $obj[$key]
	 *
	 * @param string $key
	 *
	 * @uses _get()
	 * @return mixed
	 */
	public function offsetGet($key) {
		$fl = $this->_flag === self::OFFSET_ARRAYS_AS_PSEUDOARRAY;
		$x  = $this->_get($key, $fl);
		if ($fl && is_array($x)) {
			return $this->treatOffsetArraysAsPseudoArrays($key, $x);
		}
		return $x;
	}

	protected function treatOffsetArraysAsPseudoArrays($key, $x) {
		$x = new EtuDev_PseudoArray_Object($x);
		$x->setFlag(EtuDev_PseudoArray_Object::OFFSET_ARRAYS_AS_PSEUDOARRAY);
		$x->setIsWrapperOfArray(true);
		$this->_set($key, $x);
		return $x;
	}

	//magic

	final public function getDefinedAlias($key) {
		return @$this->_aliases[$key] ? : $key;
	}

	/**
	 * establece el elemento en el container, si el elemento es válido
	 *
	 * @param string $key si es '' se hace $this->_data[] = $value
	 * @param mixed  $value
	 *
	 * @uses $_data
	 * @uses valueIsValid()
	 * @return bool
	 */
	public function __set($key, $value) {
		return $this->_set($key, $value);
	}

	/**
	 * common setter, used by __set() & offsetSet()
	 *
	 * @param string $atkey si es '' se hace $this->_data[] = $value
	 * @param mixed  $value
	 *
	 * @uses $_data
	 * @uses valueIsValid()
	 * @uses hasSetter()
	 * @uses hasAttribute()
	 * @return bool
	 */
	protected function _set($atkey, $value) {
		if (!$this->valueIsValid($value)) {
			throw new Exception("Value invalid");
		}

		if (($atkey === '' || $atkey === null) && $this->_allow_not_defined) {
			$this->_data[] = $value;
			end($this->_data);
			$newKey = key($this->_data);
			reset($this->_data);
			$this->_aliases[$newKey] = $newKey;
			return true;
		}

		//por setter no es necesario modificar alias, ya está definido en la clase
		$setter = @$this->_setters[$atkey];
		if ($setter) {
			return $this->$setter($value);
		}

		if ($this->_allow_not_defined || $this->_aliases[$atkey]) {
			$key                    = @$this->_aliases[$atkey] ? : $atkey; //por si no está definido ya
			$this->_data[$key]      = $value;
			$this->_aliases[$atkey] = $key; //por si fuera necesario almacenarlo (mejor directamente que mirar a ver si ya está)
			return true;
		} else {
			return false;
		}

	}

	final protected function _setDirectByDynamic($key, $value) {
		$this->_data[$key]    = $value;
		$this->_aliases[$key] = $key;
		return true;
	}

	final protected function _setDirectByDynamicAdd($value) {
		$this->_data[] = $value;
		end($this->_data);
		$newKey = key($this->_data);
		reset($this->_data);
		$this->_aliases[$newKey] = $newKey;
		return true;
	}

	final protected function _setBySetter($setter, $value) {
		return $this->$setter($value); //no es necesario modificar alias (si hay setter está definido en la clase)
	}


	/**
	 * common getter, used by __get() & offsetGet()
	 *
	 * @param string $origkey
	 * @param bool   $convertWrappersToArray si el elemento recogido es un pseudoarray marcado como wrapper para array, indica si se devuelve el pseudoarray o el array interno
	 *
	 * @uses $_data
	 * @return mixed
	 */
	protected function _get($origkey, $convertWrappersToArray = true) {
		//cehck if we know how we need to set it
		$getter = @$this->_getters[$origkey];

		if ($getter) {
			$ret = $this->$getter();
		} else {
			$key = @$this->_aliases[$origkey]; //nos hemos asegurado en los sets y en la carga de info que todos los definidos en container están en alias, por lo que podemos hacer esto (es más rápido que comprobar) Mejoramos la velocidad de GET con respecto a la de SET (se hace siempre muchas más veces el GET)
			$ret = @$this->_data[$key];
		}
		/** @var $ret EtuDev_PseudoArray_Object */
		if ($ret && $convertWrappersToArray && $ret instanceof EtuDev_PseudoArray_Object && $ret->isWrapperOfArray()) {
			return $ret->toArray();
		}

		return $ret;
	}

	final protected function _getByGetter($getter) {
		return $this->$getter();
	}

	final protected function _getDirectByDynamic($key) {
		// eturino: resulta mucho más rápido que esto
		//		return array_key_exists($key,$this->_data) ? $this->_data[$key] : null;
		return @$this->_data[$key];
	}


	/**
	 * if it is a wrapper of array => toArray(), if not returns this
	 * @return array|EtuDev_PseudoArray_Object
	 */
	public function unwrap() {
		return $this->isWrapperOfArray() ? $this->toArray() : $this;
	}

	final protected function _setDirect($key, $value) {
		if ($key === '' || $key === null) {
			$this->_data[] = $value;
			end($this->_data);
			$newKey = key($this->_data);
			reset($this->_data);
			$this->_aliases[$newKey] = $newKey;
		} else {
			$k                    = @$this->_aliases[$key] ? : $key;
			$this->_data[$k]      = $value;
			$this->_aliases[$key] = $k;
		}
	}

	final protected function _getDirect($origkey, $convertWrappersToArray = true) {
		$key = @$this->_aliases[$origkey];
		$ret = @$this->_data[$key];


		/** @var $ret EtuDev_PseudoArray_Object */
		if ($ret && $convertWrappersToArray && $ret instanceof EtuDev_PseudoArray_Object && $ret->isWrapperOfArray()) {
			return $ret->toArray();
		}

		return $ret;
	}

	/**
	 * recoge el elemento (null si no existe)
	 *
	 * @param string $key
	 *
	 * @uses _get()
	 * @return mixed
	 */
	public function __get($key) {
		return $this->_get($key);
	}

	public function getArrayCopy($level = null, $toArrayPseudoArrays = true) {
		return $this->toArray($level, $toArrayPseudoArrays);
	}

	public function _autoFillProperties() {
		//only needed for the setters => we get the container
		$setters_with_info_in_data = array_intersect_key($this->_setters, $this->_data);
		foreach ($setters_with_info_in_data as $key => $setter) {
			$this->$setter($this->_data[$key]);
		}
	}

	/**
	 * @return array
	 */
	public function toArrayFull() {
		return $this->toArray(null, true);
	}

	/**
	 * returns an actual array with the same elements the iterator can access
	 *
	 * @param string $level filter with the given level
	 * @param bool   $toArrayToArrayables if true the elements instanceof EtuDev_Interfaces_ToArrayAbleFull will have a toArra() also
	 *
	 * @return array
	 */
	public function toArray($level = null, $toArrayToArrayables = true) {
//		var_dump('>>>>',get_called_class());

		if (is_null($level)) {
			$level = static::TO_ARRAY_LEVEL_DEFAULT ? : self::LEVEL_ALL;
		}

		if ($level == self::LEVEL_ALL) {
			$reals = array_unique(array_values($this->_aliases));
			if (!$reals) {
				return array();
			}
		} else { //si filtramos por level, tenemos que dar solo los del level? (en principio si) => si no existe ese level, entonces las del level all (por que? por si estamos haciendo un toArray() y este es un nivel encadenado, si no está definido el nivel es como pedir todos!!!)
			$reals = @$this->_properties_by_level[$level] ? : (array_key_exists($level, $this->_properties_by_level) ? array() : @$this->_properties_by_level[self::LEVEL_ALL]);
		}
		if (!$reals) {
			return array();
		}

		$st = array_fill_keys($reals, null);
		if ($this->_data) {
			$st = array_merge($st, $this->_data);
		}

		$st = array_diff_key($st, array_flip($this->_ignore_to_array));

		//getters
		foreach ($this->_getters as $k => $getter) {
			if (array_key_exists($k, $st) && !in_array($k, $this->_ignore_to_array)) {
				$st[$k] = $this->_getByGetter($getter);
			}
		}

		if ($toArrayToArrayables) {
			$o = array();
			foreach ($st as $k => $v) {
				if ($v instanceof EtuDev_Interfaces_ToArrayAbleFull) {
					/** @var $v EtuDev_Interfaces_ToArrayAbleFull */
					$o[$k] = $v->toArray($level, $toArrayToArrayables);
				} elseif ($v instanceof EtuDev_Interfaces_ToArrayAble) {
					/** @var $v EtuDev_Interfaces_ToArrayAble */
					$o[$k] = $v->toArray();
				} elseif (is_array($v)) {
					$a = array();
					foreach ($v as $vk => $vv) {
						if ($vv instanceof EtuDev_Interfaces_ToArrayAbleFull) {
							/** @var $vv EtuDev_Interfaces_ToArrayAbleFull */
							$a[$vk] = $vv->toArray($level, $toArrayToArrayables);
						} elseif ($vv instanceof EtuDev_Interfaces_ToArrayAble) {
							/** @var $vv EtuDev_Interfaces_ToArrayAble */
							$a[$vk] = $vv->toArray();
						} else {
							$a[$vk] = $vv;
						}
					}
					$o[$k] = $a;
				} else {
					$o[$k] = $v;
				}
			}
			return $o;
		} else {
			return $st;
		}
	}

	/**
	 * @return bool if false it will ignore any parameter that is not defined in the docblocks
	 */
	public function isAllowNotDefined() {
		return $this->_allow_not_defined;
	}

	/**
	 * @param bool $v
	 *
	 * @return EtuDev_PseudoArray_Object
	 */
	public function setAllowNotDefined($v = true) {
		$this->_allow_not_defined = (bool) $v;
		return $this;
	}

	/**
	 * @return EtuDev_PseudoArray_Object
	 */
	public function unsetAllowNotDefined() {
		$this->_allow_not_defined = false;
		return $this;
	}

	/**
	 * cuenta los elementos (elementos del container)
	 * @return int
	 */
	public function count() {
		return count($this->_data);
	}

	public function rewind() {
		$this->_position = 0;
	}

	public function current() {
		$x = @array_slice($this->_data, $this->_position, 1);
		if ($x) {
			return current($x);
		}
		return null;
	}

	public function key() {
		$x = @array_slice($this->_data, $this->_position, 1);
		if ($x) {
			return key($x);
		}
		return null;
	}

	public function next() {
		++$this->_position;
	}

	public function valid() {
		return $this->_position >= 0 && $this->_position <= count($this->_data);
	}

	public function seek($position) {
		$this->_position = $position;

		if (!($this->_position >= 0 && $this->_position <= count($this->_data))) {
			throw new OutOfBoundsException("invalid seek position ($position)");
		}
	}

	public function append($value) {
		$this->_data[] = $value;
		end($this->_data);
		$newKey = key($this->_data);
		reset($this->_data);
		$this->_aliases[$newKey] = $newKey;
	}

	public function ksort() {
		ksort($this->_data);
	}

	public function asort() {
		asort($this->_data);
	}

	public function uasort($cmp_function) {
		uasort($this->_data, $cmp_function);
	}

	public function uksort($cmp_function) {
		uasort($this->_data, $cmp_function);
	}

	public function natsort() {
		natsort($this->_data);
	}

	public function natcasesort() {
		natcasesort($this->_data);
	}

}
