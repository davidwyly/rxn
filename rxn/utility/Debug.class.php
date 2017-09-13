<?php
/**
 * This file is part of Reaction (RXN).
 *
 * @license MIT License (MIT)
 * @author  David Wyly (davidwyly) <david.wyly@gmail.com>
 */

namespace Rxn\Utility;

/**
 * Class Debug
 *
 * @package Rxn\Utility
 */
class Debug
{
    static public $depth        = 1; // initial value may need to be tweaked
    static public $ancestors    = [];
    static public $detailsStyle = "margin-bottom: 10px; margin-top: 10px;";
    static public $summaryStyle = "font-family: monospace; padding-bottom: 5px;";
    static public $ulStyle      = "font-family: monospace; margin-top: 0px; margin-bottom: 0px; padding-top: 5px;";
    static public $nullStyle    = "color: darkgray;";
    static public $expandFirst  = true;
    static public $then;


    /**
     * @param      $var
     * @param null $appendName
     *
     * @return bool
     * @throws \Exception
     */
    static public function dump($var, $appendName = null)
    {
        $summaryStyle = self::$summaryStyle;
        $nullStyle    = self::$nullStyle;
        $varInfo      = self::lookupVarInfo($var);
        $varName      = $varInfo['name'];
        $varLocation  = $varInfo['location'];
        $varName      .= ($appendName) ? "($appendName)" : null;
        $varType      = gettype($var);
        if ($varType == "object") {
            $classType = get_class($var);
            $varRender = "<strong>$varName</strong> => $classType<span style='font-style: italic; $nullStyle'>::$varType</span>";
        } else {
            $varRender = "<strong>$varName</strong> => <span style='font-style: italic; $nullStyle'>$varType</span>";
        }
        $varMap       = self::inspect($var);
        $html         = self::buildRender($varMap);
        $detailsStyle = self::$detailsStyle;
        if (self::$expandFirst) {
            $open = "open";
        } else {
            $open = null;
        }
        $render = "<details $open style='$detailsStyle'><summary title='$varLocation' style='$summaryStyle'>$varRender</summary>$html</details>";
        echo($render);
        return true;
    }

    /**
     * @param $var
     *
     * @return mixed
     * @throws \Exception
     */
    static protected function lookupVarInfo($var)
    {
        $backtrace = debug_backtrace();
        $depth     = self::$depth;
        if (!$backtrace[$depth]) {
            throw new \Exception("Debug depth '$depth' gives inconsistent results", 500);
        }
        $vLine = file($backtrace[$depth]['file']);
        $fLine = $vLine[$backtrace[$depth]['line'] - 1];

        // get current class name without namespace
        $thisClass = substr(__CLASS__, strrpos(__CLASS__, '\\') + 1);

        $thisDumpMethod = "dump";
        preg_match("#($thisClass\:\:$thisDumpMethod\()(.+?)(\,|\)|\;)#", $fLine, $match);
        if (!isset($match[2])) {
            //No match for var name found in file; set var name to be a unique id
            $uniqueId = uniqid();
            return $uniqueId;
        }
        $varInfo['name']     = $match[2];
        $varInfo['location'] = "{$backtrace[$depth]['file']}\nline {$backtrace[$depth]['line']}";
        return $varInfo;
    }

    /**
     * @param array $varMapData
     *
     * @return mixed
     */
    static protected function buildRenderKeyArray(array $varMapData)
    {
        $nullStyle      = self::$nullStyle;
        $renderKeyArray = [];
        foreach ($varMapData as $key => $value) {
            $renderKeyArray[$key] = $key;
        }
        return $renderKeyArray;
    }

    /**
     * @param $varMapType
     *
     * @return array
     * @throws \Exception
     */
    static protected function buildRenderInfo($varMapType)
    {
        if (!is_string($varMapType)) {
            throw new \Exception("Returning false in " . __METHOD__, 500);
        }
        $varObjectPosition = mb_strpos(mb_strtolower($varMapType), "object");
        if ($varObjectPosition) {
            $varMapName = trim(mb_substr($varMapType, 0, $varObjectPosition));
            $varMapType = "object";
        } elseif ($varMapType == "array") {
            $varMapName = "array";
        } else {
            $varMapName = null;
        }
        return [
            "varMapType" => $varMapType,
            "varMapName" => $varMapName,
        ];
    }

    /**
     * @param $key
     * @param $value
     *
     * @return bool|string
     * @throws \Exception
     */
    static public function buildRenderValue($key, $value)
    {
        $valueRenderInfo = self::buildRenderInfo($value['__type']);
        if (!$valueRenderInfo) {
            throw new \Exception("No value render info available during debug", 500);
        }
        $arrow        = "=&gt;";
        $valueMapType = $valueRenderInfo['varMapType'];
        $valueMapName = $valueRenderInfo['varMapName'];
        $ulStyle      = self::$ulStyle;
        $nullStyle    = self::$nullStyle;
        switch ($valueMapType) {
            case "object":
                $valueRender = self::buildRender($value);
                $html        = "<ul style='$ulStyle'><details>";
                $html        .= "<summary>[$key] $arrow $valueMapName<span style='font-style: italic; $nullStyle'>::object</span></summary>";
                $html        .= $valueRender;
                $html        .= "</details></ul>";
                break;
            case "array":
                $valueRender = self::buildRender($value);
                $html        = "<ul style='$ulStyle'><details>";
                if (empty($value['__data'])) {
                    //echo ("<pre>"); print_r($value['__data']); echo "</pre>";
                    $html .= "<summary><span style='font-style: italic; $nullStyle'>[$key] $arrow $valueMapName</span></summary>";
                } else {
                    $html .= "<summary>[$key] $arrow <span style='font-style: italic; $nullStyle'>$valueMapName</span></summary>";
                }
                $html .= $valueRender;
                $html .= "</details></ul>";
                break;
            case "string":
                $html = "<ul style='margin-left: 14px; $ulStyle'>[$key] $arrow &ldquo;" . $value["__data"]
                    . "&rdquo;</ul>";
                break;
            case "null":
                $html = "<ul style='margin-left: 14px; $ulStyle $nullStyle'>[$key] $arrow </ul>";
                break;
            case "boolean":
                if ($value["__data"] === 0
                    || $value["__data"] === false
                ) {
                    $booleanRender = "<span style='$nullStyle'>false</span>";
                } elseif ($value["__data"] === 1
                    || $value["__data"] === true
                ) {
                    $booleanRender = "true";
                } else {
                    return false;
                }
                $html = "<ul style='margin-left: 14px; $ulStyle'>[$key] $arrow $booleanRender</ul>";
                break;
            default:
                $html = "<ul style='margin-left: 14px; $ulStyle'>[$key] $arrow " . $value["__data"] . "</ul>";
        }
        return $html;
    }

    /**
     * @param array $varMap
     *
     * @return bool|string
     * @throws \Exception
     */
    static protected function buildRender(array $varMap)
    {
        if (!isset($varMap["__type"])) {
            throw new \Exception("No type to debug", 500);
        }
        if (!isset($varMap["__data"])) {
            //No data to debug
            return false;
        }

        if (!is_array($varMap["__data"])) {
            if (is_string($varMap["__data"])) {
                return "<ul><span style='" . self::$summaryStyle . "'>&ldquo;" . $varMap['__data']
                    . "&rdquo;</span></ul>";
            } elseif (is_numeric($varMap["__data"])) {
                return "<ul style='" . self::$summaryStyle . "'>" . $varMap['__data'] . "</ul>";
            } elseif (is_bool($varMap["__data"])) {
                $booleanRender = null;
                if ($varMap["__data"] === true) {
                    $booleanRender = "true";
                } elseif ($varMap["__data"] === false) {
                    $booleanRender = "false";
                }
                return "<ul style='" . self::$summaryStyle . "'><em>" . $booleanRender . "</em></ul>";
            }
        }

        // figure out the varMap type (especially if an object)
        $renderInfo = self::buildRenderInfo($varMap["__type"]);
        $varMapType = $renderInfo['varMapType'];

        // initialize render string
        $html = '';

        // return html for strings, ints and floats
        if ($varMapType != "object" && $varMapType != "array") {
            foreach ($varMap['__data'] as $key => $value) {
                $html .= "<ul>[$key] =&gt; $value</ul>";
            }
            return $html;
        }

        // build render key array for private and public properties
        if ($varMapType == "object") {
            $renderKeyArray = self::buildRenderKeyArray($varMap["__data"]);

            // return property -> value html for objects
            foreach ($varMap['__data'] as $key => $value) {
                $key  = $renderKeyArray[$key];
                $html .= self::buildRenderValue($key, $value);
            }
            return $html;
        }

        // return key => value html for arrays
        if ($varMapType == "array") {
            foreach ($varMap['__data'] as $key => $value) {
                $html .= self::buildRenderValue($key, $value);
            }
            return $html;
        }
    }

    /**
     * @param $var
     *
     * @return array|bool
     * @throws \Exception
     */
    static protected function inspect($var)
    {
        $newVar = $var;
        if (is_string($var)) {
            $type = "string";
        } elseif (is_int($var)) {
            $type = "int";
        } elseif (is_array($var)) {
            $type   = "array";
            $newVar = self::inspectArray($var);
        } elseif (is_object($var)) {
            $class = get_class($var);
            $type  = "$class object";
            //if (in_array($class,self::$ancestors)) {
            //    $var = new \ReflectionClass($class);
            //} else {
            $newVar = self::inspectObject($var);
            //    self::$ancestors[] = $class;
            //}
        } elseif (is_float($var)) {
            $type = "float";
        } elseif (is_double($var)) {
            $type = "double";
        } elseif (is_null($var)) {
            $type = "null";
        } elseif (is_bool($var)) {
            $type = "boolean";
        } else {
            return false;
        }
        $varContainer = [
            "__type" => $type,
            "__data" => $newVar,
        ];
        return $varContainer;
    }

    /**
     * @param $array
     *
     * @return array|bool
     * @throws \Exception
     */
    static private function inspectArray($array)
    {
        if (!is_array($array)) {
            return false;
        }
        foreach ($array as $key => $value) {
            $inspectedValue = self::inspect($value);
            $array[$key]    = $inspectedValue;
        }
        return $array;
    }

    /**
     * @param $classType
     *
     * @return array|null
     */
    static public function getMethods($classType)
    {
        $reflection = new \ReflectionClass($classType);
        $methods    = $reflection->getMethods();
        foreach ($methods as $key => $methodReflection) {
            $methodReflection->setAccessible(true);
            $isAbstract  = $methodReflection->isAbstract();
            $isPublic    = $methodReflection->isPublic();
            $isProtected = $methodReflection->isProtected();
            $isPrivate   = $methodReflection->isPrivate();
            $isStatic    = $methodReflection->isStatic();
            $newValue    = $methodReflection->name;
            if ($isPublic) {
                $newValue = "public $newValue";
            } elseif ($isProtected) {
                $newValue = "protected $newValue";
            } elseif ($isPrivate) {
                $newValue = "private $newValue";
            }
            if ($isStatic) {
                $newValue = "static $newValue";
            }
            $methodArray[$newValue] = $methodReflection->getParameters();
        }
        if (!isset($methodArray) || !is_array($methodArray)) {
            return null;
        }
        $finalMethodArray = [];
        foreach ($methodArray as $key => $value) {
            if (is_array($value)) {
                $paramArray  = null;
                $renderArray = [];
                foreach ($value as $subKey => $subValue) {
                    /* @var $subValue \ReflectionParameter */

                    // determine the properties of the parameter
                    $isArray                 = $subValue->isArray();
                    $isOptional              = $subValue->isOptional();
                    $isPassedByReference     = $subValue->isPassedByReference();
                    $isDefaultValueAvailable = $subValue->isDefaultValueAvailable();

                    // append '$' before each parameter
                    $renderedParam = "$" . $subValue->name;

                    // if parameter is passed by reference, append '&'
                    if ($isPassedByReference) {
                        $renderedParam = "&" . $renderedParam;
                    }

                    // if parameter an array, append 'array'
                    if ($isArray) {
                        $renderedParam = "array " . $renderedParam;
                    }

                    // if parameter has a default value, set the parameter equal to it
                    if ($isDefaultValueAvailable) {
                        $defaultValue = $subValue->getDefaultValue();
                        if ($defaultValue === null) {
                            $renderedParam = "$renderedParam=null";
                        } elseif ($defaultValue === false) {
                            $renderedParam = "$renderedParam=false";
                        } elseif ($defaultValue === true) {
                            $renderedParam = "$renderedParam=true";
                        } elseif ($defaultValue === 0) {
                            $renderedParam = "$renderedParam=0";
                        } elseif ($defaultValue === 1) {
                            $renderedParam = "$renderedParam=1";
                        } elseif (is_array($defaultValue)) {
                            $arrayString   = implode(',', $defaultValue);
                            $renderedParam = "$renderedParam=[$arrayString]";
                        } else {
                            $renderedParam = "$renderedParam=$defaultValue";
                        }
                    }

                    // if parameter is optional, put the parameter in brackets
                    if ($isOptional) {
                        $renderedParam = "[$renderedParam]";
                    }
                    $renderArray{$key}[] = $renderedParam;
                }

                // implode the list of rendered parameters and append that to the end of rendered method name
                if (!empty($value)) {
                    $renderedMethod     = $key . " (" . implode(", ", $renderArray[$key]) . ")";
                    $finalMethodArray[] = $renderedMethod;
                } else {
                    $renderedMethod     = $key . " (<span style='" . self::$nullStyle . "'>void</span>)";
                    $finalMethodArray[] = $renderedMethod;
                }
            }
        }
        return $finalMethodArray;
    }

    /**
     * @param $object
     *
     * @return array
     * @throws \Exception
     */
    static private function inspectObject($object)
    {
        if (!is_object($object)) {
            throw new \Exception("Trying to inspect an object that isn't an object", 500);
        }
        $reflection           = new \ReflectionObject($object);
        $reflectionProperties = $reflection->getProperties(\ReflectionProperty::IS_PROTECTED
            | \ReflectionProperty::IS_PUBLIC | \ReflectionProperty::IS_STATIC | \ReflectionProperty::IS_PRIVATE);

        // RARELY, reflection->getProperties fails (e.g., \DateTime), so we need an exception array
        $objectArray     = get_object_vars($object);
        $reflectionArray = [];
        foreach ($reflectionProperties as $key => $reflectionProperty) {
            $reflectionArray[] = $reflectionProperty->name;
        }
        $exceptionArray = [];
        foreach ($objectArray as $propertyName => $propertyValue) {
            if (!in_array($propertyName, $reflectionArray)) {
                $exceptionArray[$propertyName] = $propertyValue;
            }
        }
        // end exception array check

        $array = [];
        foreach ($reflectionProperties as $key => $reflectionProperty) {
            $reflectionProperty->setAccessible(true);
            $propertyName = $reflectionProperty->getName();
            if ($reflectionProperty->isStatic()) {
                if ($reflectionProperty->isPrivate()) {
                    $propertyName = self::renderPrivate($propertyName);
                }
                if ($reflectionProperty->isProtected()) {
                    $propertyName = self::renderProtected($propertyName);
                }
                $propertyName = self::renderStatic($propertyName);
            } else {
                if ($reflectionProperty->isPrivate()) {
                    $propertyName = self::renderPrivate($propertyName);
                }
                if ($reflectionProperty->isProtected()) {
                    $propertyName = self::renderProtected($propertyName);
                }
            }
            $array[$propertyName] = $reflectionProperty->getValue($object);
        }

        // exceptions are appended
        foreach ($exceptionArray as $exceptionKey => $exceptionValue) {
            $array[$exceptionKey] = $exceptionValue;
        }

        $class        = get_class($object);
        $specialArray = [];
        foreach ($array as $key => $value) {
            if (mb_strpos('<span', $key)) {
                $specialArray[$key] = $value;
                unset($array[$key]);
            }
        }
        ksort($specialArray);
        $array                                                = $array + $specialArray;
        $array["<em style='color: darkgray;'>::methods</em>"] = self::getMethods($class);

        foreach ($array as $key => $value) {
            $array[$key] = self::inspect($value);
        }

        return $array;
    }

    /**
     * @param $propertyName
     *
     * @return string
     */
    static private function renderPrivate($propertyName)
    {
        $privateStyle = 'color: rgba(244,67,57,1);';
        return "<span style='$privateStyle' title='private'>&bull;</span>$propertyName";
    }

    /**
     * @param $propertyName
     *
     * @return string
     */
    static private function renderProtected($propertyName)
    {
        $protectedStyle = 'color: #FFC107;';
        return $propertyName = "<span style='$protectedStyle' title='protected'>&bull;</span>$propertyName";
    }

    /**
     * @param $propertyName
     *
     * @return string
     */
    static private function renderStatic($propertyName)
    {
        $staticStyle = 'color: rgba(3,169,244,1);';
        return "<span style='$staticStyle' title='static'>&bull;</span>$propertyName";
    }

    /**
     *
     */
    static public function startTimer()
    {
        self::$then = microtime(true);
    }

    /**
     *
     */
    static public function stopTimer($message = null)
    {
        $now     = microtime(true);
        $elapsed = (($now - self::$then) * 1000) . " ms";
        echo "\n$message\n ";
        echo $elapsed;
    }
}