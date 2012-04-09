<?php

class EtuDev_PseudoArray_Factory {
	/**
	 * @var array static warehouse for all properties from docblock
	 */
	static public $allDocBlockAliases = array();
	static public $allDocBlockProperties = array();
	static public $allClassProperties = array();
	static public $allClassStaticProperties = array();
	static public $allGetters = array();
	static public $allSetters = array();
	static public $allUnsetters = array();
	static public $keySetBy = array();
	static public $keySetByDirect = array();
	static public $keyGetBy = array();
	static public $keyGetByDirect = array();

	static public $info = array();

	static public function getInfo($class) {
		if (@static::$info[$class]) {
			return static::$info[$class];
		}

		//load from cache
		$info = EtuDev_PseudoArray_Cache::getInstance()->get($class);
		if ($info) {
			static::$info[$class] = $info;
			return $info;
		}

		$info = static::loadInfo($class);
		if ($info) {
			EtuDev_PseudoArray_Cache::getInstance()->write($class, $info);

			static::$info[$class] = $info;
			return $info;
		}

		return array();
	}

	static public function deleteAllCached() {
		EtuDev_PseudoArray_Cache::getInstance()->deleteAll();
	}

	static public function loadInfo($class) {
		$ref  = new ReflectionClass($class);
		$info = static::calculateAttributeDocBlock($ref);

		$info['aliases'] = ($info['properties'] || $info['aliases_different']) ? array_merge(array_combine((array) $info['properties'], (array) $info['properties']), (array) $info['aliases_different']) : array();

		$setters = array();
		$getters = array();
		foreach ((array) $info['properties'] as $prop) {
			$aliases = $info['aliases'] ? array_filter($info['aliases'], function($v) use ($prop) {
				return $v == $prop;
			}) : array();

			$posGetters = static::calculatePossibleGetterNames($prop);
			foreach ($posGetters as $getter) {
				if ($ref->hasMethod($getter)) {
					$getter = $ref->getMethod($getter)->getName();
					foreach ($aliases as $al) {
						$getters[$al] = $getter;
					}
				}
			}

			$posSetters = static::calculatePossibleSetterNames($prop);
			foreach ($posSetters as $setter) {
				if ($ref->hasMethod($setter)) {
					$setter = $ref->getMethod($setter)->getName();
					foreach ($aliases as $al) {
						$setters[$al] = $setter;
					}
				}
			}
		}

		$info['getters'] = $getters;
		$info['setters'] = $setters;

		return $info;
	}


	static protected function calculatePossibleGetterNames($key) {
		$setKey = 'get' . $key;
		$k      = str_replace('_', '', $key);
		$setK   = 'get' . $k;
		$res    = array($setKey);
		if ($setKey != $setK) {
			$res[] = $setK;
		}
		return $res;
	}

	static protected function calculatePossibleSetterNames($key) {
		$setKey = 'set' . $key;
		$k      = str_replace('_', '', $key);
		$setK   = 'set' . $k;
		$res    = array($setKey);
		if ($setKey != $setK) {
			$res[] = $setK;
		}
		return $res;
	}

	static protected function getDocBlock($ref) {
		$docblocks   = array();
		$docblocks[] = $ref->getDocComment();

		/** @var $ref ReflectionClass */
		while (($ref = $ref->getParentClass()) && ($ref->getName() != __CLASS__)) {
			$d = $ref->getDocComment();
			if ($d) {
				$docblocks[] = $d;
			}
		}

		$docblocks = array_reverse($docblocks);

		return implode("\n", $docblocks);
	}

	static protected function calculateAttributeDocBlock($ref) {
		//docblock used to parse
		$dc    = static::getDocBlock($ref);
		$lines = explode("\n", $dc);

		$active_levels = array();
		$aliases       = array();
		$forall        = array();

		$ats_by_level = array(EtuDev_PseudoArray_Object::LEVEL_ALL);

		foreach ($lines as $line) {
			// check new active level
			if (preg_match('/@propertieslevel[\s\t]+[a-zA-Z_0-9,]+/', $line, $matches)) {
				foreach ($matches as $key => $m) {
					$res = trim(str_replace('@propertieslevel', '', $m));
					if (!$res) {
						$active_levels = array();
					} else {
						$active_levels = explode(',', $res);
					}
				}

			} elseif (trim($line) == '/**') { //new class docblock => reset property level
				$active_levels = array();
			} else {
				// check attribute
				if (preg_match('/@propertyalias[\s\t]+[a-zA-Z_0-9]+[\s\t]+[a-zA-Z_0-9]+/', $line, $matches)) {
					foreach ($matches as $key => $m) {
						$res              = trim(str_replace('@propertyalias', '', $m));
						$res              = preg_replace('/[\s\t]+/', ' ', $res);
						$res              = explode(' ', $res);
						$aliases[$res[0]] = $res[1];
					}
				}

				//con tipo
				if (preg_match('/@property[\s\t]+[a-zA-Z_]+[\s\t]+[\$][a-zA-Z_0-9]+/', $line, $matches)) {
					foreach ($matches as $key => $m) {
						$at                                                    = preg_replace('/^@[\t\sa-zA-Z_0-9]+\$/', '', $m);
						$ats_by_level[EtuDev_PseudoArray_Object::LEVEL_ALL][] = $at;
						if ($active_levels) {
							foreach ($active_levels as $al) {
								$ats_by_level[$al][] = $at;
							}
						} else {
							$forall[] = $at;
						}
					}
				}

				//sin tipo
				if (preg_match('/@property[\s\t]+[\$][a-zA-Z_]+/', $line, $matches)) {
					foreach ($matches as $key => $m) {
						$at = preg_replace('/^@[\t\sa-zA-Z_0-9]+\$/', '', $m);
						if (!in_array($at, $forall)) {
							$ats_by_level[EtuDev_PseudoArray_Object::LEVEL_ALL][] = $at;
							if ($active_levels) {
								foreach ($active_levels as $al) {
									$ats_by_level[$al][] = $at;
								}
							} else {
								$forall[] = $at;
							}
						}
					}
				}
			}
		}

		$forall = array_unique($forall);

		$auxlevels = array();
		//clean
		foreach ($ats_by_level as $al => $allist) {
			if ($allist) { //add the ats for all levels + the ats for this level
				$auxlevels[$al] = array_values(array_unique(array_merge($forall, $allist)));
			}
		}

		$info['properties']        = (array) $auxlevels[EtuDev_PseudoArray_Object::LEVEL_ALL];
		$info['levels']            = (array) $auxlevels;
		$info['aliases_different'] = (array) $aliases;

		return $info;
	}

}
