<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Export\Rewrite;

/**
 * srcset/imagesrcset candidate splitter that does not destroy Cloudinary
 * transform commas.
 *
 * A comma splits candidates only when it is followed by whitespace or by an
 * obvious URL start (https://, //, /path). Transform commas inside a URL
 * (f_auto,q_auto) and commas inside data URIs never become boundaries. Trailing
 * "width"/"density" descriptors are peeled, the URL is rewritten by a caller
 * callback, and the descriptor is re-attached; candidates that are not URLs
 * (bare digits or descriptors) are left untouched.
 */
final class SrcsetSplitter
{
    public function rewrite(string $srcset, callable $convert): string
    {
        if (trim($srcset) === '') {
            return $srcset;
        }

        $rewritten = [];
        foreach ($this->split($srcset) as $candidate) {
            $rewritten[] = $this->rewriteCandidate($candidate, $convert);
        }

        return implode(', ', $rewritten);
    }

    /**
     * @return list<string>
     */
    private function split(string $srcset): array
    {
        $tokens = [];
        $tokenStart = 0;
        $length = strlen($srcset);

        for ($i = 0; $i < $length; ++$i) {
            if ($srcset[$i] !== ',') {
                continue;
            }

            $after = $i + 1;
            while ($after < $length && ($srcset[$after] === ' ' || $srcset[$after] === "\t")) {
                ++$after;
            }

            $rest = substr($srcset, $after);
            $isUrlStart = preg_match('{^(?:https?://|//|/[^/])}', $rest) === 1;
            $hasWhitespace = $after > $i + 1;

            if ($isUrlStart || $hasWhitespace) {
                $tokens[] = substr($srcset, $tokenStart, $i - $tokenStart);
                $tokenStart = $after;
                $i = $after - 1;
            }
        }

        $tokens[] = substr($srcset, $tokenStart);

        return $tokens;
    }

    private function rewriteCandidate(string $raw, callable $convert): string
    {
        $token = trim($raw);

        $trailing = '';
        while (str_ends_with($token, ',')) {
            $trailing .= ',';
            $token = rtrim(substr($token, 0, -1));
        }

        if ($token === '') {
            return $trailing;
        }

        [$url, $descriptor] = $this->peelDescriptor($token);

        // Not a URL candidate: bare digits or a lone width/density descriptor.
        if ($url === '' || preg_match('/^\d+(?:\.\d+)?[xw]$/i', $url) === 1 || preg_match('/^\d+$/', $url) === 1) {
            return rtrim($token) . $trailing;
        }

        $rewritten = (string) $convert($url);

        return trim($rewritten . ($descriptor !== '' ? ' ' . $descriptor : '')) . $trailing;
    }

    /**
     * @return array{0: string, 1: string} [url, descriptor part (without leading space)]
     */
    private function peelDescriptor(string $token): array
    {
        $url = $token;
        $descriptors = [];

        while (preg_match('/^(.*?)\s+(\d+(?:\.\d+)?[xw])$/', $url, $matches) === 1) {
            array_unshift($descriptors, $matches[2]);
            $url = trim($matches[1]);
        }

        return [$url, implode(' ', $descriptors)];
    }
}
