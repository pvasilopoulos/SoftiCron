<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Auth;
use App\Core\App;
use App\Core\Db;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;

abstract class Controller
{
    protected Db $db;

    public function __construct(protected Request $req)
    {
        $this->db = App::db();
    }

    /** Requires a logged-in user. */
    protected function user(): array
    {
        $u = Auth::user();
        if (!$u) {
            $back = $this->req->method === 'GET' ? $this->req->path : '/';
            Session::set('_intended', $back);
            throw HttpException::redirect(url('login'));
        }
        return $u;
    }

    /** Requires a selected tenant; returns its row. */
    protected function tenant(): array
    {
        $this->user();
        $t = Auth::tenant();
        if (!$t) {
            if (Auth::isSuperAdmin()) {
                throw HttpException::redirect(url('admin/tenants'));
            }
            throw HttpException::redirect(url('no-tenant'));
        }
        return $t;
    }

    protected function tenantId(): int
    {
        return (int) $this->tenant()['id'];
    }

    protected function authorize(string $permission): void
    {
        $this->tenant();
        if (!Auth::can($permission)) {
            throw HttpException::forbidden();
        }
    }

    protected function superAdmin(): void
    {
        $this->user();
        if (!Auth::isSuperAdmin()) {
            throw HttpException::forbidden();
        }
    }

    protected function view(string $template, array $data = [], ?string $layout = 'layout'): Response
    {
        return Response::html(View::render($template, $data, $layout));
    }

    protected function redirect(string $path): Response
    {
        return Response::redirect(str_starts_with($path, '/') || str_starts_with($path, 'http') ? $path : url($path));
    }

    protected function back(string $fallback = '/'): Response
    {
        $ref = $_SERVER['HTTP_REFERER'] ?? '';
        $host = $_SERVER['HTTP_HOST'] ?? '';
        if ($ref !== '' && parse_url($ref, PHP_URL_HOST) === parse_url('http://' . $host, PHP_URL_HOST)) {
            return Response::redirect($ref);
        }
        return $this->redirect($fallback);
    }

    protected function json(mixed $data, int $status = 200): Response
    {
        return Response::json($data, $status);
    }

    protected function flash(string $type, string $message): void
    {
        Session::flash($type, $message);
    }

    /** Keep submitted values for re-displaying a form after validation errors. */
    protected function keepOld(): void
    {
        $data = $_POST;
        foreach (array_keys($data) as $k) {
            if (preg_match('/pass|secret|token/i', (string) $k)) {
                unset($data[$k]);
            }
        }
        $_SESSION['_old'] = $data;
    }

    protected function page(): int
    {
        return max(1, (int) $this->req->query('page', 1));
    }

    /** @return array{rows: array, total: int, page: int, pages: int} */
    protected function paginate(string $sqlFromWhere, array $params, string $select, string $order, int $perPage = 25): array
    {
        $total = (int) $this->db->value("SELECT COUNT(*) $sqlFromWhere", $params);
        $pages = max(1, (int) ceil($total / $perPage));
        $page = min($this->page(), $pages);
        $offset = ($page - 1) * $perPage;
        $rows = $this->db->all("SELECT $select $sqlFromWhere ORDER BY $order LIMIT $perPage OFFSET $offset", $params);
        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => $pages];
    }
}
