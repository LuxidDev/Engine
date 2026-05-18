<?php

namespace Luxid\Http;

class Request
{
    /**
     * @var array|null Cached request body to avoid repeated parsing
     */
    private ?array $cachedBody = null;

    /**
     * @var array|null Cached query parameters
     */
    private ?array $cachedQuery = null;

    protected string $customPath = '';
    protected string $customMethod = '';
    protected string $customBody = '';
    protected array $customHeaders = [];

    public function setPath(string $path): void
    {
        $this->customPath = $path;
    }

    public function setMethod(string $method): void
    {
        $this->customMethod = $method;
    }

    public function setBody(string $body): void
    {
        $this->customBody = $body;
        $this->cachedBody = json_decode($body, true);
    }

    public function setHeader(string $name, string $value): void
    {
        $this->customHeaders[$name] = $value;
    }

    public function getPath()
    {
        if ($this->customPath) {
            return $this->customPath;
        }
        // Fallback to superglobals for PHP-FPM
        $path = $_SERVER["REQUEST_URI"] ?? '/';
        $position = strpos($path, '?');
        return $position === false ? $path : substr($path, 0, $position);
    }

    public function method()
    {
        if ($this->customMethod) {
            return $this->customMethod;
        }
        // Fallback for PHP-FPM
        $method = strtolower($_SERVER['REQUEST_METHOD'] ?? 'get');

        // Check for method overrid in POST data
        if ($method === 'post' && isset($_POST['method'])) {
            return strtolower($_POST['_method']);
        }

        // Check for method override in headers
        if (isset($_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE'])) {
            return strtolower($_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE']);
        }

        return $method;
    }

    /**
     * Get all request data with automatic sanitization and caching
     *
     * Handles different request types:
     * - GET requests: Returns sanitized $_GET data
     * - POST requests: Returns sanitized $_POST or JSON data
     * - PUT/PATCH/DELETE: Parses raw input (form or JSON)
     *
     * @return array Associative array of request data
     *
     * Note: Results are cached to avoid repeated parsing of php://input
     */
    public function getBody()
    {
        // Return cached result if available
        if ($this->cachedBody !== null) {
            return $this->cachedBody;
        }

        $body = [];
        $method = $this->method();

        /**
            have a look in the Super Global 'GET' and 'POST
            find the key, take the value
            remove invalid chars and insert into the body
        */
        if ($method === 'get') {
            foreach ($_GET as $key => $value) {
                $body[$key] = filter_input(INPUT_GET, $key, FILTER_SANITIZE_SPECIAL_CHARS);
            }
        } else {
            // Handle POST, PUT, PATCH, DELETE

            // First check if it's JSON input
            $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
            $rawInput = file_get_contents('php://input');

            if (strpos($contentType, 'application/json') !== false) {
                // JSON input
                $jsonData = json_decode($rawInput, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $body = $jsonData;
                }
            } else {
                // Form data
                if ($method === 'post') {
                    foreach ($_POST as $key => $value) {
                        $body[$key] = filter_input(INPUT_POST, $key, FILTER_SANITIZE_SPECIAL_CHARS);
                    }
                }

                // Also parse raw input for PUT/PATCH/DELETE
                if (!empty($rawInput) && empty($body)) {
                    parse_str($rawInput, $parsed);
                    foreach ($parsed as $key => $value) {
                        $body[$key] = filter_var($value, FILTER_SANITIZE_SPECIAL_CHARS);
                    }
                }
            }
        }

        // Cache the result
        $this->cachedBody = $body;

        return $body;
    }

    /**
     * Clear the cached request body (useful for testing)
     */
    public function clearCache(): void
    {
        $this->cachedBody = null;
    }

    // Utility Methods (helpers) ================
    public function isGet()
    {
        return $this->method() === 'get';
    }

    public function isPost()
    {
        return $this->method() === 'post';
    }

    public function isPut()
    {
        return $this->method() === 'put';
    }

    public function isPatch()
    {
        return $this->method() === 'patch';
    }

    public function isDelete()
    {
        return $this->method() === 'delete';
    }

    public function isJson()
    {
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        return strpos($contentType, 'application/json') !== false;
    }

    public function getJson()
    {
        $rawInput = file_get_contents('php://input');
        return json_decode($rawInput, true);
    }

    /**
     * Get input value by key
     *
     * @param string $key The parameter name to retrieve
     * @param mixed $default Default value if key doesn't exist
     * @return mixed The parameter value or default
     *
     * Example:
     * - Request: GET /api/todos?status=pending
     * - $request->get('status') returns 'pending'
     * - $request->get('search', 'default') returns 'default'
     */
    public function get(string $key, $default = null)
    {
        $body = $this->getBody();
        return $body[$key] ?? $default;
    }

    /**
     * Get all input data
     *
     * Alias for getBody() for consistency with other frameworks
     *
     * @return array All request parameters
     *
     * Example:
     * - Request: GET /api/todos?status=pending&search=work
     * - Returns: ['status' => 'pending', 'search' => 'work']
     */
    public function all(): array
    {
        return $this->getBody();
    }

    /**
     * Get only specific keys from request data
     *
     * @param array $keys Array of keys to retrieve
     * @return array Associative array with only specified keys
     *
     * Example:
     * - Request data: ['title' => 'Todo', 'status' => 'pending', 'extra' => 'data']
     * - $request->only(['title', 'status']) returns ['title' => 'Todo', 'status' => 'pending']
     */
    public function only(array $keys): array
    {
        $body = $this->getBody();
        $result = [];

        foreach ($keys as $key) {
            if (isset($body[$key])) {
                $result[$key] = $body[$key];
            }
        }

        return $result;
    }

    /**
     * Check if request contains a specific key
     *
     * @param string $key The parameter name to check
     * @return bool True if key exists in request data
     *
     * Example:
     * - Request: GET /api/todos?status=pending
     * - $request->has('status') returns true
     * - $request->has('search') returns false
     */
    public function has(string $key): bool
    {
        $body = $this->getBody();
        return isset($body[$key]);
    }

    /**
     * Get query parameters (GET requests only)
     *
     * This method is specifically for query string parameters
     * Unlike getBody() which merges everything, this only returns $_GET data
     *
     * @param string $key The query parameter name
     * @param mixed $default Default value if key doesn't exist
     * @return mixed The query parameter value or default
     *
     * Example:
     * - URL: /api/todos?status=pending&page=2
     * - $request->query('status') returns 'pending'
     * - $request->query('sort', 'created_at') returns 'created_at'
     */
    public function query(string $key = null, $default = null)
    {
        if ($this->cachedQuery === null) {
            $this->cachedQuery = [];
            foreach ($_GET as $key => $value) {
                $this->cachedQuery[$key] = filter_input(INPUT_GET, $key, FILTER_SANITIZE_SPECIAL_CHARS);
            }
        }

        if ($key === null) {
            return $this->cachedQuery;
        }

        return $this->cachedQuery[$key] ?? $default;
    }

    /**
     * Get POST/PUT/PATCH input data (not including query parameters)
     *
     * This method returns only the request body data, not query string parameters
     * Useful when you want to separate query params from body content
     *
     * @param string $key The input parameter name
     * @param mixed $default Default value if key doesn't exist
     * @return mixed The input value or default
     *
     * Example for POST request:
     * - Body: {"title": "Todo", "status": "pending"}
     * - $request->input('title') returns 'Todo'
     */
    public function input(string $key = null, $default = null)
    {
        $body = $this->getBody();

        // For GET requests, input() should return empty (use query() instead)
        if ($this->isGet()) {
            if ($key === null) {
                return [];
            }
            return $default;
        }

        if ($key === null) {
            return $body;
        }

        return $body[$key] ?? $default;
    }
}
