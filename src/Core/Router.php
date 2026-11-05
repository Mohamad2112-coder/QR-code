<?php

declare(strict_types=1);

namespace App\Core;

class Router
{
    private array $routes = [];

    public function add(string $method, string $path, mixed $handler, array $middlewares = []): void
    {
        $this->routes[] = [
            'method' => strtoupper($method),
            'path' => $path,
            'handler' => $handler,
            'middlewares' => $middlewares,
        ];
    }

    public function get(string $path, mixed $handler, array $middlewares = []): void
    {
        $this->add('GET', $path, $handler, $middlewares);
    }

    public function post(string $path, mixed $handler, array $middlewares = []): void
    {
        $this->add('POST', $path, $handler, $middlewares);
    }

    public function put(string $path, mixed $handler, array $middlewares = []): void
    {
        $this->add('PUT', $path, $handler, $middlewares);
    }

    public function delete(string $path, mixed $handler, array $middlewares = []): void
    {
        $this->add('DELETE', $path, $handler, $middlewares);
    }

    public function dispatch(Request $request, Response $response): void
    {
        $requestMethod = $request->getMethod();
        $requestUri = rtrim($request->getUri(), '/') ?: '/';

        // Check for _method spoofing on POST (e.g. DELETE, PUT)
        if ($requestMethod === 'POST' && $request->input('_method')) {
            $requestMethod = strtoupper((string)$request->input('_method'));
        }

        foreach ($this->routes as $route) {
            if ($route['method'] !== $requestMethod) {
                continue;
            }

            $routePath = rtrim($route['path'], '/') ?: '/';
            $pattern = preg_replace('/\{([a-zA-Z0-9_]+)\}/', '(?P<$1>[^/]+)', $routePath);
            $pattern = '#^' . $pattern . '$#';

            if (preg_match($pattern, $requestUri, $matches)) {
                $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);

                // Execute Middlewares
                foreach ($route['middlewares'] as $middlewareClass) {
                    $middleware = new $middlewareClass();
                    $result = $middleware->handle($request, $response);
                    if ($result !== true) {
                        return; // Middleware handled the response
                    }
                }

                // Execute Handler
                $handler = $route['handler'];
                if (is_array($handler)) {
                    [$class, $action] = $handler;
                    $controller = new $class();
                    $controller->$action($request, $response, $params);
                } elseif (is_callable($handler)) {
                    call_user_func($handler, $request, $response, $params);
                }
                return;
            }
        }

        // Route Not Found
        if ($request->isAjaxOrJson()) {
            $response->json(['error' => 'Endpoint not found'], 404)->send();
        } else {
            $response->html(View::render('errors/404', ['title' => 'Page Not Found']), 404)->send();
        }
    }
}
