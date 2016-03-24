<?php
/**
 * This file is part of Reaction (RXN).
 *
 * @license MIT License (MIT)
 * @author David Wyly (davidwyly) <david.wyly@gmail.com>
 */


namespace Rxn\Api\Controller;

use \Rxn\Application;
use \Rxn\Api\Request;
use \Rxn\Router\Collector;
use \Rxn\Utility\Debug;

class Response
{
    const DEFAULT_SUCCESS_CODE = 200;
    const LEADER_KEY = '_rxn';

    /**
     * @var bool
     */
    protected $rendered = false;

    /**
     * @var bool
     */
    public $success;

    /**
     * @var
     */
    public $code;

    /**
     * @var
     */
    public $result;

    /**
     * @var
     */
    public $message;

    /**
     * @var
     */
    public $trace;

    /**
     * @var Request
     */
    public $request;

    /**
     * @var
     */
    public $elapsed;

    /**
     * @var array
     */
    static public $responseCodes = [
        100 => "Continue",
        101 => "Switching Protocols",
        102 => "Processing",
        200 => "OK",
        201 => "Created",
        202 => "Accepted",
        203 => "Non-Authoritative Information",
        204 => "No Content",
        205 => "Reset Content",
        206 => "Partial Content",
        207 => "Multi-Status",
        208 => "Already Reported",
        226 => "IM Used",
        300 => "Multiple Choices",
        301 => "Moved Permanently",
        302 => "Found",
        303 => "See Other",
        304 => "Not Modified",
        305 => "Use Proxy",
        307 => "Temporary Redirect",
        308 => "Permanent Redirect",
        400 => "Bad Request",
        401 => "Unauthorized",
        403 => "Forbidden",
        404 => "Not Found",
        405 => "Method Not Allowed",
        406 => "Not Acceptable",
        407 => "Proxy Authentication Required",
        408 => "Request Timeout",
        409 => "Conflict",
        410 => "Gone",
        411 => "Length Required",
        412 => "Precondition Failed",
        413 => "Request Entity Too Large",
        414 => "Request-URI Too Long",
        415 => "Unsupported Media Type",
        416 => "Requested Range Not Satisfiable",
        417 => "Expectation Failed",
        421 => "Misdirected Request",
        422 => "Unprocessable Entity",
        423 => "Locked",
        424 => "Failed Dependency",
        426 => "Upgrade Required",
        428 => "Precondition Required",
        429 => "Too Many Requests",
        431 => "Request Header Fields Too Large",
        500 => "Internal Server Error",
        501 => "Not Implemented",
        502 => "Bad Gateway",
        503 => "Service Unavailable",
        504 => "Gateway Timeout",
        505 => "HTTP Version Not Supported",
        506 => "Variant Also Negotiates",
        507 => "Insufficient Storage",
        508 => "Loop Detected",
        510 => "Not Extended",
        511 => "Network Authentication Required",
    ];

    /**
     * Response constructor.
     *
     * @param Request $request
     */
    public function __construct(Request $request) {
        $this->request = $request;
    }

    /**
     * @return array
     */
    public function getSuccess() {
        $this->success = true;
        $this->code = self::DEFAULT_SUCCESS_CODE;
        $this->result = self::getResponseCodeResult($this->code);
        $this->rendered = true;
        $this->stopTimer();
        return [self::LEADER_KEY => $this];
    }

    /**
     * @param \Exception $e
     *
     * @return array
     */
    public function getFailure(\Exception $e) {
        $this->success = false;
        $this->code = $e->getCode();
        $this->result = self::getResponseCodeResult($this->code);
        $this->message = $e->getMessage();
        $this->trace = self::getErrorTrace($e);
        $this->rendered = true;
        $this->stopTimer();
        return [self::LEADER_KEY => $this];
    }

    /**
     * @param \Exception $e
     *
     * @return int|mixed|string
     */
    static public function getErrorCode(\Exception $e) {
        $code = $e->getCode();
        if (empty($code)) {
            $code = '500';
        }
        return $code;
    }

    /**
     * @param \Exception $e
     *
     * @return array
     */
    static public function getErrorTrace(\Exception $e) {
        $fullTrace = $e->getTrace();
        $allowedDebugKeys = ['file','line','function','class'];
        $trace = array();
        foreach ($allowedDebugKeys as $allowedKey) {
            foreach ($fullTrace as $traceKey=>$traceGroup) {
                if (isset($traceGroup[$allowedKey])) {
                    $trace[$traceKey][$allowedKey] = $traceGroup[$allowedKey];
                }
            }
        }
        foreach ($trace as $key=>$traceGroup) {
            if (isset($traceGroup['file'])) {
                $regex = '^.+\/';
                $trimmedFile = preg_replace("#$regex#",'',$traceGroup['file']);
                $trace[$key]['file'] = $trimmedFile;
            }
        }
        return $trace;
    }

    /**
     * @param $code
     *
     * @return string
     */
    static public function getResponseCodeResult($code) {
        if (!isset(self::$responseCodes[$code])) {
            return 'Unsupported Response Code';
        }
        return self::$responseCodes[$code];
    }

    /**
     *
     */
    private function stopTimer() {
        $this->elapsed = round(microtime(true) - Application::$timeStart,4);
        $this->peakMemory = memory_get_peak_usage(true);
        $this->requestsPerSecond = round(1 / $this->elapsed);
    }
}