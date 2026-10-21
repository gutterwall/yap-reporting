<?php

declare(strict_types=1);

namespace YapReporting\Http;

/** Tiny exact-match router. Path parameters are only allowed where explicitly declared. */
final class Router
{
    /** @var array<int, array{pattern: string, handler: callable, public: bool}> */
    private array $routes = [];

    public function get(string $pattern, callable $handler, bool $public = false): void
    {
        $this->routes[] = ['pattern' => $pattern, 'handler' => $handler, 'public' => $public];
    }

    /** @return array{0: ?array{handler: callable, public: bool}, 1: array<string, string>} */
    public function match(string $path): array
    {
        foreach ($this->routes as $route) {
            $regex = '#^' . preg_replace('#\{([a-z_]+)\}#', '(?P<$1>[A-Za-z0-9_\-]{1,64})', $route['pattern']) . '$#';
            if (preg_match($regex, $path, $m) === 1) {
                $params = [];
                foreach ($m as $k => $v) {
                    if (is_string($k)) {
                        $params[$k] = $v;
                    }
                }
                return [['handler' => $route['handler'], 'public' => $route['public']], $params];
            }
        }
        return [null, []];
    }
}
