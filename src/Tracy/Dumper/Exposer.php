<?php

/**
 * This file is part of the Tracy (https://tracy.nette.org)
 * Copyright (c) 2004 David Grudl (https://davidgrudl.com)
 */

declare(strict_types=1);

namespace Tracy\Dumper;


/**
 * Exposes internal PHP objects.
 * @internal
 */
final class Exposer
{
	public static function exposeObject(object $obj, Value $value, Describer $describer): void
	{
		$tmp = (array) $obj;
		$values = $tmp; // bug #79477, PHP < 7.4.6
		$props = self::getProperties(get_class($obj));

		foreach (array_diff_key($values, $props) as $k => $v) {
			$describer->addPropertyTo(
				$value,
				(string) $k,
				$v,
				Value::PROP_DYNAMIC,
				$describer->getReferenceId($values, $k)
			);
		}

		foreach ($props as $k => [$name, $type]) {
			if (array_key_exists($k, $values)) {
				$describer->addPropertyTo(
					$value,
					$name,
					$values[$k],
					$type,
					$describer->getReferenceId($values, $k)
				);
			} else {
				$value->items[] = [$name, new Value(Value::TYPE_TEXT, 'unset'), $type];
			}
		}
	}


	private static function getProperties($class): array
	{
		static $cache;
		if (isset($cache[$class])) {
			return $cache[$class];
		}
		$rc = new \ReflectionClass($class);
		$parentProps = $rc->getParentClass() ? self::getProperties($rc->getParentClass()->getName()) : [];
		$props = [];

		foreach ($rc->getProperties() as $prop) {
			$name = $prop->getName();
			if ($prop->isStatic()) {
				// nothing
			} elseif ($prop->isPrivate()) {
				$props["\x00" . $class . "\x00" . $name] = [$name, $class];
			} elseif ($prop->isProtected()) {
				$props["\x00*\x00" . $name] = [$name, Value::PROP_PROTECTED];
			} else {
				$props[$name] = [$name, Value::PROP_PUBLIC];
				unset($parentProps["\x00*\x00" . $name]);
			}
		}

		return $cache[$class] = $props + $parentProps;
	}


	public static function exposeClosure(\Closure $obj, Value $value, Describer $describer): void
	{
		$rc = new \ReflectionFunction($obj);
		if ($describer->location) {
			$describer->addPropertyTo($value, 'file', $rc->getFileName() . ':' . $rc->getStartLine());
		}

		$params = [];
		foreach ($rc->getParameters() as $param) {
			$params[] = '$' . $param->getName();
		}
		$value->value .= '(' . implode(', ', $params) . ')';

		$uses = [];
		$useValue = new Value(Value::TYPE_OBJECT);
		$useValue->depth = $value->depth + 1;
		foreach ($rc->getStaticVariables() as $name => $v) {
			$uses[] = '$' . $name;
			$describer->addPropertyTo($useValue, '$' . $name, $v);
		}
		if ($uses) {
			$useValue->value = implode(', ', $uses);
			$useValue->collapsed = true;
			$value->items[] = ['use', $useValue];
		}
	}


	public static function exposeArrayObject(\ArrayObject $obj, Value $value, Describer $describer): void
	{
		$flags = $obj->getFlags();
		$obj->setFlags(\ArrayObject::STD_PROP_LIST);
		self::exposeObject($obj, $value, $describer);
		$obj->setFlags($flags);
		$describer->addPropertyTo($value, 'storage', $obj->getArrayCopy(), \ArrayObject::class);
	}


	public static function exposeSplFileInfo(\SplFileInfo $obj): array
	{
		return ['path' => $obj->getPathname()];
	}


	public static function exposeSplObjectStorage(\SplObjectStorage $obj): array
	{
		$res = [];
		foreach (clone $obj as $item) {
			$res[] = ['object' => $item, 'data' => $obj[$item]];
		}
		return $res;
	}


	public static function exposePhpIncompleteClass(\__PHP_Incomplete_Class $obj): array
	{
		$info = ['className' => null, 'private' => [], 'protected' => [], 'public' => []];
		foreach ((array) $obj as $name => $value) {
			$name = (string) $name;
			if ($name === '__PHP_Incomplete_Class_Name') {
				$info['className'] = $value;
			} elseif (preg_match('#^\x0\*\x0(.+)$#D', $name, $m)) {
				$info['protected'][$m[1]] = $value;
			} elseif (preg_match('#^\x0(.+)\x0(.+)$#D', $name, $m)) {
				$info['private'][$m[1] . '::$' . $m[2]] = $value;
			} else {
				$info['public'][$name] = $value;
			}
		}
		return $info;
	}
}
