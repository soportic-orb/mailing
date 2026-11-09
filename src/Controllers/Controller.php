<?php

declare(strict_types=1);

namespace Mailing\Controllers;

use Mailing\Core\Context;
use Mailing\Core\DB;
use Mailing\Core\Session;
use Mailing\Core\View;

abstract class Controller
{
    protected function project(): array
    {
        $p = Context::project();
        if (!$p) {
            abort(409, 'Tria primer un projecte.');
        }
        return $p;
    }

    protected function pid(): int
    {
        return (int) $this->project()['id'];
    }

    protected function view(string $view, array $data = [], ?string $layout = 'layouts/app'): never
    {
        View::show($view, $data, $layout);
    }

    /** A row of the current project or 404. */
    protected function own(string $table, int $id): array
    {
        $row = DB::one('SELECT * FROM `' . $table . '` WHERE id = ? AND project_id = ?', [$id, $this->pid()]);
        if (!$row) {
            abort(404, 'No s\'ha trobat (potser és d\'un altre projecte).');
        }
        return $row;
    }

    protected function fail(string $message, ?string $to = null): never
    {
        if (wants_json()) {
            json_response(['ok' => false, 'message' => $message], 422);
        }
        Session::keepInput();
        flash('err', $message);
        $to ? redirect($to) : back();
    }

    protected function done(string $message, string $to): never
    {
        if (wants_json()) {
            json_response(['ok' => true, 'message' => $message]);
        }
        flash('ok', $message);
        redirect($to);
    }

    /** Integer ids from an input array. */
    protected function ids(string $key): array
    {
        return array_values(array_unique(array_filter(array_map('intval', (array) input($key, [])))));
    }

    protected function paginate(string $countSql, string $sql, array $params, int $per = 50): array
    {
        $total = (int) DB::value($countSql, $params);
        $pages = max(1, (int) ceil($total / $per));
        $page = max(1, min($pages, int_input('pagina', 1)));
        $rows = DB::all($sql . ' LIMIT ' . $per . ' OFFSET ' . (($page - 1) * $per), $params);
        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => $pages];
    }
}
