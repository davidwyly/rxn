<?php declare(strict_types=1);

namespace Rxn\Framework\Http;

use \Rxn\Framework\App;
use \Rxn\Framework\Http\Binding\ValidationException;

/**
 * Class Response
 *
 * @package Rxn\Framework\Http\Controller
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
     * @var mixed payload returned by the controller action; serialized
     *             as-is in the JSON envelope.
     */
    public $data;

    /**
     * @var
     */
    public $errors;

    /**
     * Structured per-field errors captured from a ValidationException,
     * surfaced as the `errors` extension member on Problem Details.
     *
     * @var list<array{field: string, message: string}>|null
     */
    public ?array $validation_errors = null;

    /**
     * @var
     */
    public $meta;

    /**
     * @var
     */
    private $code;

    /**
     * @var Request|null
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
        503 => "Container Unavailable",
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
    public function __construct(?Request $request = null)
    {
        if (!is_null($request)) {
            $this->request = $request;
            if (!$this->request->isValidated()) {
                $exception              = $this->request->getException();
                $this->failure_response = $this->getFailure($exception);
            }
        }
    }

    /**
     * Mark this Response as a successful one and store the payload
     * returned by the controller action.
     *
     * @param mixed $data payload to emit under the top-level "data"
     *                    key (typically an array produced by the
     *                    action method)
     */
    public function getSuccess($data = null): Response
    {
        $this->setRendered(true);

        $this->data = $data ?? self::getResponseCodeResult(self::DEFAULT_SUCCESS_CODE);
        $this->code = self::DEFAULT_SUCCESS_CODE;
        $this->meta = [
            'success'    => true,
            'code'       => self::DEFAULT_SUCCESS_CODE,
            'elapsed_ms' => App::getElapsedMs(),
        ];

        return $this;
    }

    /**
     * @param \Throwable $exception
     *
     * @return Response
     */
    public function getFailure(\Throwable $exception): Response
    {
        $this->setRendered(true);

        $code         = self::getErrorCode($exception);
        $message      = $exception->getMessage();
        if (getenv('ENVIRONMENT') === 'production'
            && $this->isInternalThrowable($exception, (int)$code)
        ) {
            $message = (string)self::getResponseCodeResult((int)$code);
        }
        $this->code   = (int)$code;
        $this->errors = [
            'type'    => self::getResponseCodeResult($code),
            'message' => $message,
        ];
        if ($exception instanceof ValidationException) {
            $this->validation_errors = $exception->errors();
        }
        // Only expose file / line / stack trace outside production,
        // so error payloads never leak server internals to end users.
        if (getenv('ENVIRONMENT') !== 'production') {
            $this->errors['file']  = $exception->getFile();
            $this->errors['line']  = $exception->getLine();
            $this->errors['trace'] = self::getErrorTrace($exception);
        }
        $this->meta = [
            'success'    => false,
            'code'       => $code,
            'elapsed_ms' => App::getElapsedMs(),
        ];

        return $this;
    }

    private function isInternalThrowable(\Throwable $exception, int $code): bool
    {
        // ValidationException always carries user-visible field-level errors — never scrub.
        // All other 5xx throwables (including RequestException with a 5xx code) are internal
        // and must be sanitized so implementation details are not exposed in production.
        return $code >= 500 && !$exception instanceof ValidationException;
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
     * @param \Throwable $exception
     *
     * @return int|mixed|string
     */
    public static function getErrorCode(\Throwable $exception)
    {
        $code = $exception->getCode();
        if (empty($code)) {
            $code = '500';
        }
        return $code;
    }

    /**
     * @param \Throwable $exception
     *
     * @return array
     */
    public static function getErrorTrace(\Throwable $exception)
    {
        $full_trace         = $exception->getTrace();
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
            unset($trace_key, $trace_group);
        }

        foreach ($trace as $key => $trace_group) {
            if (isset($trace_group['file'])) {
                $regex               = '^.+\/';
                $trimmed_file        = preg_replace("#$regex#", '', $trace_group['file']);
                $trace[$key]['file'] = $trimmed_file;
            }
        }
        unset($key, $trace_group);

        return $trace;
    }

    /**
     * @param $code
     *
     * @return string
     */
    public static function getResponseCodeResult($code)
    {
        if (!isset(self::$response_codes[$code])) {
            return 'Unsupported Response Code';
        }
        return self::$response_codes[$code];
    }

    /**
     * @param bool $rendered
     */
    public function setRendered(bool $rendered)
    {
        $this->rendered = $rendered;
    }

    /**
     * @return mixed
     */
    public function getCode()
    {
        return $this->code;
    }

    /**
     * @return array
     */
    public function stripEmptyParams()
    {
        $array = (array)$this;
        foreach ($array as $key => $value) {
            if (empty($value)) {
                unset($array[$key]);
            }
        }
        return $array;
    }

    /**
     * Serialize this envelope exactly the way the top-level renderer
     * does. Extracted here so middleware (ETag, compression, etc.)
     * can inspect the wire bytes without duplicating the encoder
     * flags.
     */
    public function toJson(): string
    {
        $json = json_encode(
            (object)$this->stripEmptyParams(),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
        // Null bytes can trip JSON decoders on the other end.
        return str_replace('\\u0000', '', $json);
    }

    /**
     * Build a body-less "304 Not Modified" response. Used by the
     * ETag middleware to short-circuit when the client already has
     * a fresh copy; the renderer emits headers only.
     */
    public static function notModified(): self
    {
        $r = (new \ReflectionClass(self::class))->newInstanceWithoutConstructor();
        $r->rendered = true;
        $r->code     = 304;
        $r->meta     = ['success' => true, 'code' => 304];
        return $r;
    }

    /**
     * Build an RFC 7807 Problem Details response without going
     * through an exception. Use this in middleware that needs to
     * short-circuit with a structured failure (auth, rate limit,
     * idempotency conflict) — the renderer sees `isError()` is
     * true, picks the `application/problem+json` content type, and
     * emits the same shape an uncaught exception would have.
     *
     * `$title` defaults to the standard reason phrase for `$code`;
     * `$detail` defaults to empty. Pass per-field validation errors
     * via `$validationErrors` if you want a 422-shaped `errors[]`
     * extension member.
     *
     * @param list<array{field: string, message: string}>|null $validationErrors
     */
    public static function problem(
        int $code,
        ?string $title = null,
        ?string $detail = null,
        ?array $validationErrors = null,
    ): self {
        $r = (new \ReflectionClass(self::class))->newInstanceWithoutConstructor();
        $r->rendered = true;
        $r->code     = $code;
        $r->errors   = [
            'type'    => $title ?? self::getResponseCodeResult($code),
            'message' => $detail ?? '',
        ];
        if ($validationErrors !== null) {
            $r->validation_errors = $validationErrors;
        }
        $r->meta = [
            'success'    => false,
            'code'       => $code,
            'elapsed_ms' => App::getElapsedMs(),
        ];
        return $r;
    }

    /**
     * True when this response represents a rendered failure — i.e.
     * `getFailure()` has populated `$this->errors`.
     */
    public function isError(): bool
    {
        return !empty($this->errors);
    }

    /**
     * Render the error envelope as an RFC 7807 Problem Details
     * document. Only meaningful on a rendered failure; callers
     * should gate on `isError()` first. `$instance` is the optional
     * URI for the specific occurrence (typically `REQUEST_URI`).
     *
     * The Rxn debug fields (`file`, `line`, `trace`) come along as
     * `x-rxn-*` extension members when present, so dev-mode Problem
     * Details stays as informative as the native envelope.
     *
     * @return array<string, mixed>
     */
    public function toProblemDetails(?string $instance = null): array
    {
        $status = (int)($this->code ?? 500);
        $out = [
            'type'   => 'about:blank',
            'title'  => (string)($this->errors['type'] ?? self::getResponseCodeResult($status)),
            'status' => $status,
            'detail' => (string)($this->errors['message'] ?? ''),
        ];
        if ($instance !== null && $instance !== '') {
            $out['instance'] = $instance;
        }
        if ($this->validation_errors !== null) {
            // RFC 7807 extension member. Using `errors` (array of
            // {field, message}) is the shape tools like Laravel's
            // validator, Spring, and most REST style guides already
            // emit — so consumers don't need a Rxn-specific reader.
            $out['errors'] = $this->validation_errors;
        }
        if (is_array($this->meta) && isset($this->meta['elapsed_ms'])) {
            $out['x-rxn-elapsed-ms'] = $this->meta['elapsed_ms'];
        }
        foreach (['file', 'line', 'trace'] as $k) {
            if (isset($this->errors[$k])) {
                $out['x-rxn-' . $k] = $this->errors[$k];
            }
        }
        return $out;
    }
}
