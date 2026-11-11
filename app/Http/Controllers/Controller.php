<?php

namespace App\Http\Controllers;

use App\View\PageData;
use Illuminate\Http\Response;

abstract class Controller
{
    protected function page(string $view, array $data = [], ?string $title = null, int $status = 200): Response
    {
        return response()->view('v2/' . $view, ['data' => app(PageData::class)->with($data, $title)], $status);
    }

    protected function forbidden(): Response
    {
        return $this->page('Forbidden', [], '403 Forbidden', 403);
    }
}
