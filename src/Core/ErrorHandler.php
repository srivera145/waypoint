<?php

namespace Keel\Core;

class ErrorHandler
{
    public static function render(int $status, ?\Throwable $e = null): never
    {
        if ($e !== null) {
            error_log('[Keel] Uncaught exception: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
        }

        $status = $status === 404 ? 404 : 500;
        http_response_code($status);

        $debug = (bool) Env::get('APP_DEBUG', false);
        $exception = $debug ? $e : null;
        $title = $status === 404 ? 'Page Not Found' : 'Server Error';
        $template = $status === 404 ? 'errors.404' : 'errors.500';

        // Under the test harness the rendered page becomes the captured response instead of
        // ending the process, the same way Response::abort() and friends behave.
        if (Response::capturing()) {
            ob_start();
        }

        try {
            View::render($template, [
                'title' => $title,
                'exception' => $exception,
            ]);
        } catch (\Throwable $viewException) {
            echo $status === 404 ? 'Page not found' : 'Server error';
        }

        if (Response::capturing()) {
            throw new CapturedResponseException($status, [], (string) ob_get_clean());
        }

        exit;
    }
}