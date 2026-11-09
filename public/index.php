<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\Auth\Auth;
use App\Core\App;
use App\Core\Csrf;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use FastRoute\Dispatcher;

App::boot(dirname(__DIR__));
$request = new Request();
// Machine endpoints (heartbeat pings, REST API, public status page) are stateless: no session, no CSRF.
$stateless = (bool) preg_match('#^/(ping|api/v1|status)(/|$)#', $request->path);
$api = (bool) preg_match('#^/api/v1(/|$)#', $request->path);
if (!$stateless) {
    Session::start($request->isSecure());
}

$dispatcher = FastRoute\simpleDispatcher(require App::$root . '/config/routes.php');

try {
    // The REST API routes on the real HTTP method; HTML forms only use GET/POST (with _method override).
    $httpMethod = $request->method === 'HEAD' ? 'GET' : $request->method;
    $route = $dispatcher->dispatch($api ? $httpMethod : ($httpMethod === 'GET' ? 'GET' : 'POST'), $request->path);
    switch ($route[0]) {
        case Dispatcher::NOT_FOUND:
            throw HttpException::notFound();
        case Dispatcher::METHOD_NOT_ALLOWED:
            throw new HttpException(405, 'Μη επιτρεπτή μέθοδος.');
    }
    [, [$class, $method], $vars] = $route;

    if (!$stateless && $request->method !== 'GET' && $request->method !== 'HEAD') {
        $token = $_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);
        if (!Csrf::check(is_string($token) ? $token : null)) {
            throw new HttpException(419, 'Η φόρμα έληξε. Ανανέωσε τη σελίδα και δοκίμασε ξανά.');
        }
    }

    $controller = new $class($request);
    // Cast route parameters to the controller's declared types (int ids).
    $args = [];
    foreach ((new ReflectionMethod($class, $method))->getParameters() as $i => $param) {
        $value = array_values($vars)[$i] ?? null;
        $type = $param->getType();
        $args[] = $type instanceof ReflectionNamedType && $type->getName() === 'int' ? (int) $value : $value;
    }
    $response = $controller->$method(...$args);
    unset($_SESSION['_old']);
} catch (HttpException $e) {
    if ($e->redirect !== null) {
        $response = Response::redirect($e->redirect);
    } elseif ($api || $request->wantsJson()) {
        $response = Response::json(['error' => $e->getMessage() ?: 'Σφάλμα', 'status' => $e->status], $e->status);
    } else {
        $response = Response::html(View::render('errors/error', [
            'status' => $e->status,
            'message' => $e->getMessage(),
        ], Auth::check() && Auth::tenant() ? 'layout' : 'auth_layout'), $e->status);
    }
} catch (Throwable $e) {
    App::log('Unhandled: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    $msg = \App\Core\Env::bool('APP_DEBUG') ? $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() : 'Κάτι πήγε στραβά. Το σφάλμα καταγράφηκε.';
    if ($api || $request->wantsJson()) {
        $response = Response::json(['error' => $msg, 'status' => 500], 500);
    } else {
        try {
            $response = Response::html(View::render('errors/error', ['status' => 500, 'message' => $msg], 'auth_layout'), 500);
        } catch (Throwable) {
            $response = Response::html('<h1>500</h1><p>' . htmlspecialchars($msg) . '</p>', 500);
        }
    }
}

$response->send();
