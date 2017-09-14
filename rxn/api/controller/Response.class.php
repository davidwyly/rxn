<?php
/**
 * This file is part of Reaction (RXN).
 *
 * @license MIT License (MIT)
 * @author  David Wyly (davidwyly) <david.wyly@gmail.com>
 */

namespace Rxn\Api\Controller;

use \Rxn\Config;
use \Rxn\Api\Request;
use \Rxn\Application;
use \Rxn\Utility\Debug;

/**
 * Class Response
 *
 * @package Rxn\Api\Controller
 */
class Response
{
    const DEFAULT_SUCCESS_CODE = 200;

    /**
     * @var bool
     */
    protected $rendered = false;

    /**
     * @var array
     */
    protected $failure_response;

    /**
     * @var string
     */
    private $response_leader_key;

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
    public $elapsed_ms;

    /**
     * @var array
     */
    static public $response_codes = [
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
     * @param Config  $config
     */
    public function __construct(Request $request, Config $config)
    {
        $this->response_leader_key = $config->response_leader_key;
        $this->request             = $request;
        if (!$this->request->isValidated()) {
            $e                      = $this->request->getException();
            $this->failure_response = $this->getFailure($e);
        }
    }

    /**
     * @return Response
     */
    public function getSuccess(): Response
    {
        $this->success    = true;
        $this->code       = self::DEFAULT_SUCCESS_CODE;
        $this->result     = self::getResponseCodeResult($this->code);
        $this->rendered   = true;
        $this->elapsed_ms = Application::getElapsedMs();
        return $this;
    }

    /**
     * @param \Exception $e
     *
     * @return Response
     */
    public function getFailure(\Exception $e): Response
    {
        $this->success  = false;
        $this->code     = $e->getCode();
        $this->result   = self::getResponseCodeResult($this->code);
        $this->message  = $e->getMessage();
        $this->trace    = self::getErrorTrace($e);
        $this->rendered = true;
        return $this;
    }

    /**
     * @return mixed
     */
    public function getFailureResponse()
    {
        return $this->failure_response;
    }

    /**
     * @return bool
     */
    public function isRendered()
    {
        return $this->rendered;
    }

    /**
     * @param \Exception $e
     *
     * @return int|mixed|string
     */
    static public function getErrorCode(\Exception $e)
    {
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
    static public function getErrorTrace(\Exception $e)
    {
        $full_trace         = $e->getTrace();
        $allowed_debug_keys = [
            'file',
            'line',
            'function',
            'class',
        ];
        $trace              = [];
        foreach ($allowed_debug_keys as $allowed_key) {
            foreach ($full_trace as $trace_key => $trace_group) {
                if (isset($trace_group[$allowed_key])) {
                    $trace[$trace_key][$allowed_key] = $trace_group[$allowed_key];
                }
            }
        }
        foreach ($trace as $key => $trace_group) {
            if (isset($trace_group['file'])) {
                $regex               = '^.+\/';
                $trimmed_file        = preg_replace("#$regex#", '', $trace_group['file']);
                $trace[$key]['file'] = $trimmed_file;
            }
        }
        return $trace;
    }

    /**
     * @param $code
     *
     * @return string
     */
    static public function getResponseCodeResult($code)
    {
        if (!isset(self::$response_codes[$code])) {
            return 'Unsupported Response Code';
        }
        return self::$response_codes[$code];
    }
}