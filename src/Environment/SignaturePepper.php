<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Environment;

/**
 * Resolves the pepper the connection memo's identity digest is keyed with.
 *
 * The pepper must come from a secret that never lives in the WordPress
 * database — otherwise a DB-only attacker holds both the persisted digest
 * and the key it was keyed with, which makes the digest verifiable offline.
 * wp_salt('auth') only qualifies when AUTH_SALT is defined in wp-config;
 * without the constant WordPress generates the auth salt into the options
 * table on first use. When neither source offers a trusted secret the
 * resolution fails closed (null) and callers must not persist the memo.
 */
final class SignaturePepper
{
    /**
     * The trusted pepper, or null when no DB-independent secret is available.
     */
    public static function resolve(): ?string
    {
        if (defined('AUTH_SALT')) {
            $salt = (string) constant('AUTH_SALT');
            if (trim($salt) !== '') {
                return \wp_salt('auth');
            }
        }

        $fromEnvironment = getenv('LUMEWEB_CAST_SIGNATURE_PEPPER');
        if (is_string($fromEnvironment) && trim($fromEnvironment) !== '') {
            return $fromEnvironment;
        }

        return null;
    }
}
