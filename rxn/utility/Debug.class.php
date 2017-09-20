<?php
/**
 * This file is part of the Rxn (Reaction) PHP API App
 *
 * @package    Rxn
 * @copyright  2015-2017 David Wyly
 * @author     David Wyly (davidwyly) <david.wyly@gmail.com>
 * @link       Github <https://github.com/davidwyly/rxn>
 * @license    MIT License (MIT) <https://github.com/davidwyly/rxn/blob/master/LICENSE>
 */

namespace Rxn\Utility;

use \Rxn\Error\DebugException;

class Debug
{
    static public $depth         = 1; // initial value may need to be tweaked
    static public $ancestors     = [];
    static public $details_style = "margin-bottom: 10px; margin-top: 10px;";
    static public $summary_style = "font-family: monospace; padding-bottom: 5px;";
    static public $ul_style      = "font-family: monospace; margin-top: 0px; margin-bottom: 0px; padding-top: 5px;";
    static public $null_style    = "color: darkgray;";
    static public $expand_first  = true;
    static public $then;

    /**
     * @param      $var
     * @param null $append_name
     *
     * @return bool
     * @throws DebugException
     */
    static public function dump($var, $append_name = null)
    {
        $summary_style = self::$summary_style;
        $null_style    = self::$null_style;
        $var_info      = self::lookupVarInfo($var);
        $var_name      = $var_info['name'];
        $var_location  = $var_info['location'];
        $var_name      .= ($append_name) ? "($append_name)" : null;
        $var_type      = gettype($var);

        if ($var_type == "object") {
            $class_type = get_class($var);
            $var_render = "<strong>$var_name</strong> => $class_type<span style='font-style: italic; $null_style'>::$var_type</span>";
        } else {
            $var_render = "<strong>$var_name</strong> => <span style='font-style: italic; $null_style'>$var_type</span>";
        }

        $var_map       = self::inspect($var);
        $html          = self::buildRender($var_map);
        $details_style = self::$details_style;
        if (self::$expand_first) {
            $open = "open";
        } else {
            $open = null;
        }

        $render = "
            <details $open style='$details_style'>
                <summary title='$var_location' style='$summary_style'>
                    $var_render
                </summary>$html
            </details>
        ";
        echo($render);

        return true;
    }

    /**
     * @param $var
     *
     * @return mixed
     * @throws DebugException
     */
    static protected function lookupVarInfo($var)
    {
        $backtrace = debug_backtrace();
        $depth     = self::$depth;
        if (!$backtrace[$depth]) {
            throw new DebugException("Debug depth '$depth' gives inconsistent results", 500);
        }
        $v_line = file($backtrace[$depth]['file']);
        $f_line = $v_line[$backtrace[$depth]['line'] - 1];

        preg_match('#($this_class\:\:$this_dump_method\()(.+?)(\,|\)|\;)#', $f_line, $match);
        if (!isset($match[2])) {
            //No match for var name found in file; set var name to be a unique id
            $unique_id = uniqid();
            return $unique_id;
        }
        $var_info['name']     = $match[2];
        $var_info['location'] = "{$backtrace[$depth]['file']}\nline {$backtrace[$depth]['line']}";
        return $var_info;
    }

    /**
     * @param array $var_map_data
     *
     * @return mixed
     */
    static protected function buildRenderKeyArray(array $var_map_data)
    {
        $render_key_array = [];
        foreach ($var_map_data as $key => $value) {
            $render_key_array[$key] = $key;
        }
        return $render_key_array;
    }

    /**
     * @param $var_map_type
     *
     * @return array
     * @throws DebugException
     */
    static protected function buildRenderInfo($var_map_type)
    {
        if (!is_string($var_map_type)) {
            throw new DebugException("Returning false in " . __METHOD__, 500);
        }
        $var_object_position = mb_strpos(mb_strtolower($var_map_type), "object");
        if ($var_object_position) {
            $var_map_name = trim(mb_substr($var_map_type, 0, $var_object_position));
            $var_map_type = "object";
        } elseif ($var_map_type == "array") {
            $var_map_name = "array";
        } else {
            $var_map_name = null;
        }
        return [
            "var_map_type" => $var_map_type,
            "var_map_name" => $var_map_name,
        ];
    }

    /**
     * @param $key
     * @param $value
     *
     * @return bool|string
     * @throws DebugException
     */
    static public function buildRenderValue($key, $value)
    {
        $valueRenderInfo = self::buildRenderInfo($value['__type']);
        if (!$valueRenderInfo) {
            throw new DebugException("No value render info available during debug", 500);
        }
        $arrow          = "=&gt;";
        $value_map_type = $valueRenderInfo['var_map_type'];
        $value_map_name = $valueRenderInfo['var_map_name'];
        $ul_style       = self::$ul_style;
        $null_style     = self::$null_style;

        switch ($value_map_type) {
            case "object":
                $value_render = self::buildRender($value);

                $html = "
                    <ul style='$ul_style'>
                        <details>
                            <summary>
                                [$key] $arrow $value_map_name<span style='font-style: italic; $null_style'>::object</span>
                            </summary>
                            $value_render
                        </details>
                    </ul>
                ";
                break;
            case "array":
                $value_render = self::buildRender($value);

                if (empty($value['__data'])) {
                    $html = "
                        <ul style='$ul_style'>
                            <details>
                                <summary>
                                     <span style='font-style: italic; $null_style'>[$key] $arrow $value_map_name</span>
                                </summary>
                                $value_render
                            </details>
                        </ul>
                    ";
                } else {
                    $html = "
                        <ul style='$ul_style'>
                            <details>
                                <summary>
                                    [$key] $arrow <span style='font-style: italic; $null_style'>$value_map_name</span>
                                </summary>
                                $value_render
                            </details>
                        </ul>
                    ";
                }
                break;
            case "string":
                $html = "
                    <ul style='margin-left: 14px; $ul_style'>
                        [$key] $arrow &ldquo;{$value["__data"]}&rdquo;
                    </ul>
                ";
                break;
            case "null":
                $html = "
                    <ul style='margin-left: 14px; $ul_style $null_style'>
                        [$key] $arrow 
                    </ul>
                ";
                break;
            case "boolean":
                if ($value["__data"] === 0
                    || $value["__data"] === false
                ) {
                    $boolean_render = "<span style='$null_style'>false</span>";
                } elseif ($value["__data"] === 1
                    || $value["__data"] === true
                ) {
                    $boolean_render = "true";
                } else {
                    return false;
                }
                $html = "
                    <ul style='margin-left: 14px; $ul_style'>
                        [$key] $arrow $boolean_render
                    </ul>
                ";
                break;
            default:
                $html = "
                    <ul style='margin-left: 14px; $ul_style'>
                        [$key] $arrow {$value["__data"]}
                    </ul>
                ";
        }

        return $html;
    }

    /**
     * @param array $var_map
     *
     * @return bool|string
     * @throws DebugException
     */
    static protected function buildRender(array $var_map)
    {
        if (!isset($var_map["__type"])) {
            throw new DebugException("No type to debug", 500);
        }
        if (!isset($var_map["__data"])) {
            //No data to debug
            return false;
        }

        $summary_style = self::$summary_style;
        if (!is_array($var_map["__data"])) {
            if (is_string($var_map["__data"])) {
                return "
                    <ul>
                        <span style = '$summary_style' >&ldquo;{$var_map['__data']} &rdquo;</span >
                    </ul >
                ";
            } elseif (is_numeric($var_map["__data"])) {
                return "
                    <ul style = '$summary_style' >
                        {$var_map['__data']}
                    </ul >
                ";
            } elseif (is_bool($var_map["__data"])) {
                $booleanRender = null;
                if ($var_map["__data"] === true) {
                    $booleanRender = "true";
                } elseif ($var_map["__data"] === false) {
                    $booleanRender = "false";
                }
                return "
                    <ul style = '$summary_style'>
                        <em>$booleanRender</em >
                    </ul >
                ";
            }
        }

        // figure out the varMap type (especially if an object)
        $render_info  = self::buildRenderInfo($var_map["__type"]);
        $var_map_type = $render_info['var_map_type'];

        // initialize render string
        $html = '';

        // return html for strings, ints and floats
        if ($var_map_type != "object" && $var_map_type != "array") {
            foreach ($var_map['__data'] as $key => $value) {
                $html .= "<ul>[$key] = &gt; $value </ul>";
            }
            return $html;
        }

        // build render key array for private and public properties
        if ($var_map_type == "object") {
            $render_key_array = self::buildRenderKeyArray($var_map["__data"]);

            // return property -> value html for objects
            foreach ($var_map['__data'] as $key => $value) {
                $key  = $render_key_array[$key];
                $html .= self::buildRenderValue($key, $value);
            }
            return $html;
        }

        // return key => value html for arrays
        if ($var_map_type == "array") {
            foreach ($var_map['__data'] as $key => $value) {
                $html .= self::buildRenderValue($key, $value);
            }
            return $html;
        }

        return $html;
    }

    /**
     * @param $var
     *
     * @return array|bool
     * @throws DebugException
     */
    static protected function inspect($var)
    {
        $new_var = $var;
        if (is_string($var)) {
            $type = "string";
        } elseif (is_int($var)) {
            $type = "int";
        } elseif (is_array($var)) {
            $type    = "array";
            $new_var = self::inspectArray($var);
        } elseif (is_object($var)) {
            $class   = get_class($var);
            $type    = "$class object";
            $new_var = self::inspectObject($var);
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
        $var_container = [
            "__type" => $type,
            "__data" => $new_var,
        ];
        return $var_container;
    }

    /**
     * @param $array
     *
     * @return array|bool
     * @throws DebugException
     */
    static private function inspectArray($array)
    {
        if (!is_array($array)) {
            return false;
        }
        foreach ($array as $key => $value) {
            $inspected_value = self::inspect($value);
            $array[$key]     = $inspected_value;
        }
        return $array;
    }

    /**
     * @param $class_type
     *
     * @return array|null
     */
    static public function getMethods($class_type)
    {
        $reflection = new \ReflectionClass($class_type);
        $methods    = $reflection->getMethods();
        foreach ($methods as $key => $method_reflection) {
            $method_reflection->setAccessible(true);
            $is_abstract  = $method_reflection->isAbstract();
            $is_public    = $method_reflection->isPublic();
            $is_protected = $method_reflection->isProtected();
            $is_private   = $method_reflection->isPrivate();
            $is_static    = $method_reflection->isStatic();
            $modifiers    = [];

            // define modifiers to append before method name
            if ($is_abstract) {
                $modifiers[] = "abstract";
            }
            if ($is_static) {
                $modifiers[] = "static";
            }
            if ($is_public) {
                $modifiers[] = "public";
            } elseif ($is_protected) {
                $modifiers[] = "protected";
            } elseif ($is_private) {
                $modifiers[] = "private";
            }

            // generate method name with modifiers
            $new_value = implode(" ", $modifiers) . " " . $method_reflection->name;

            // append to method array
            $method_array[$new_value] = $method_reflection->getParameters();
        }
        if (!isset($method_array) || !is_array($method_array)) {
            return null;
        }
        $final_method_array = [];
        foreach ($method_array as $key => $value) {
            if (is_array($value)) {
                $param_array  = null;
                $render_array = [];
                foreach ($value as $sub_key => $sub_value) {
                    /* @var $sub_value \ReflectionParameter */

                    // determine the properties of the parameter
                    $is_array                   = $sub_value->isArray();
                    $is_optional                = $sub_value->isOptional();
                    $is_passed_by_reference     = $sub_value->isPassedByReference();
                    $is_default_value_available = $sub_value->isDefaultValueAvailable();

                    // append '$' before each parameter
                    $rendered_param = "$" . $sub_value->name;

                    // if parameter is passed by reference, append '&'
                    if ($is_passed_by_reference) {
                        $rendered_param = " & " . $rendered_param;
                    }

                    // if parameter an array, append 'array'
                    if ($is_array) {
                        $rendered_param = "array " . $rendered_param;
                    }

                    // if parameter has a default value, set the parameter equal to it
                    if ($is_default_value_available) {
                        $default_value = $sub_value->getDefaultValue();
                        if ($default_value === null) {
                            $rendered_param = "$rendered_param = null";
                        } elseif ($default_value === false) {
                            $rendered_param = "$rendered_param = false";
                        } elseif ($default_value === true) {
                            $rendered_param = "$rendered_param = true";
                        } elseif ($default_value === 0) {
                            $rendered_param = "$rendered_param = 0";
                        } elseif ($default_value === 1) {
                            $rendered_param = "$rendered_param = 1";
                        } elseif (is_array($default_value)) {
                            $arrayString    = implode(',', $default_value);
                            $rendered_param = "$rendered_param = [$arrayString]";
                        } else {
                            $rendered_param = "$rendered_param = $default_value";
                        }
                    }

                    // if parameter is optional, put the parameter in brackets
                    if ($is_optional) {
                        $rendered_param = "[$rendered_param]";
                    }
                    $render_array{$key}[] = $rendered_param;
                }

                // implode the list of rendered parameters and append that to the end of rendered method name
                if (!empty($value)) {
                    $rendered_method      = $key . " (" . implode(", ", $render_array[$key]) . ")";
                    $final_method_array[] = $rendered_method;
                } else {
                    $rendered_method      = $key . " (<span style = '" . self::$null_style . "' > void</span >)";
                    $final_method_array[] = $rendered_method;
                }
            }
        }
        return $final_method_array;
    }

    /**
     * @param $object
     *
     * @return array
     * @throws DebugException
     */
    static private function inspectObject($object)
    {
        if (!is_object($object)) {
            throw new DebugException("Trying to inspect an object that isn't an object", 500);
        }
        $reflection            = new \ReflectionObject($object);
        $reflection_properties = $reflection->getProperties(\ReflectionProperty::IS_PROTECTED
            | \ReflectionProperty::IS_PUBLIC | \ReflectionProperty::IS_STATIC | \ReflectionProperty::IS_PRIVATE);

        // RARELY, reflection->getProperties fails (e.g., \DateTime), so we need an error array
        $object_array     = get_object_vars($object);
        $reflection_array = [];
        foreach ($reflection_properties as $key => $reflection_property) {
            $reflection_array[] = $reflection_property->name;
        }
        $exception_array = [];
        foreach ($object_array as $property_name => $property_value) {
            if (!in_array($property_name, $reflection_array)) {
                $exception_array[$property_name] = $property_value;
            }
        }
        // end error array check

        $array = [];
        foreach ($reflection_properties as $key => $reflection_property) {
            $reflection_property->setAccessible(true);
            $property_name = $reflection_property->getName();
            if ($reflection_property->isStatic()) {
                if ($reflection_property->isPrivate()) {
                    $property_name = self::renderPrivate($property_name);
                }
                if ($reflection_property->isProtected()) {
                    $property_name = self::renderProtected($property_name);
                }
                $property_name = self::renderStatic($property_name);
            } else {
                if ($reflection_property->isPrivate()) {
                    $property_name = self::renderPrivate($property_name);
                }
                if ($reflection_property->isProtected()) {
                    $property_name = self::renderProtected($property_name);
                }
            }
            $array[$property_name] = $reflection_property->getValue($object);
        }

        // exceptions are appended
        foreach ($exception_array as $exception_key => $exception_value) {
            $array[$exception_key] = $exception_value;
        }

        $class         = get_class($object);
        $special_array = [];
        foreach ($array as $key => $value) {
            if (mb_strpos(' < span', $key)) {
                $special_array[$key] = $value;
                unset($array[$key]);
            }
        }
        ksort($special_array);
        $array = $array + $special_array;

        $array["<em style='color: darkgray;'>::methods</em>"] = self::getMethods($class);

        foreach ($array as $key => $value) {
            $array[$key] = self::inspect($value);
        }

        return $array;
    }

    /**
     * @param $property_name
     *
     * @return string
     */
    static private function renderPrivate($property_name)
    {
        $private_style = 'color: rgba(244, 67, 57, 1);';
        return "<span style='$private_style' title='private'>&bull;</span>$property_name";
    }

    /**
     * @param $property_name
     *
     * @return string
     */
    static private function renderProtected($property_name)
    {
        $protected_style = 'color: #FFC107;';
        return $property_name = "<span style='$protected_style' title='protected'>&bull;</span>$property_name";
    }

    /**
     * @param $property_name
     *
     * @return string
     */
    static private function renderStatic($property_name)
    {
        $static_style = 'color: rgba(3,169,244,1);';
        return "<span style='$static_style' title='static'>&bull;</span>$property_name";
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