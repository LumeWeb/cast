<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Export;

/**
 * The tiny deterministic page written when a true local page move is
 * captured: an instant meta-refresh to the offline target plus a canonical
 * link and a visible fallback link. Assets are never given a stub — their
 * redirect only queues the target.
 */
final class RedirectPage
{
    public static function render(string $target): string
    {
        $escaped = htmlspecialchars($target, ENT_QUOTES, 'UTF-8');

        return sprintf(
            "<!doctype html>\n<html lang=\"en\">\n<head>\n<meta charset=\"utf-8\">\n"
            . "<meta http-equiv=\"refresh\" content=\"0; url=%s\">\n"
            . "<link rel=\"canonical\" href=\"%s\">\n<title>Redirecting</title>\n</head>\n"
            . "<body>\n<p><a href=\"%s\">Redirected to %s</a></p>\n</body>\n</html>\n",
            $escaped,
            $escaped,
            $escaped,
            $escaped,
        );
    }
}
