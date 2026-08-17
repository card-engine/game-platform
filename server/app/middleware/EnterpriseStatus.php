<?php

namespace app\middleware;

use app\service\game\EnterpriseScope;
use Webman\Http\Request;
use Webman\Http\Response;
use Webman\MiddlewareInterface;

class EnterpriseStatus implements MiddlewareInterface
{
    public function process(Request $request, callable $handler): Response
    {
        if ($admin = $request->header('check_admin')) EnterpriseScope::current((int) $admin['id']);
        return $handler($request);
    }
}
