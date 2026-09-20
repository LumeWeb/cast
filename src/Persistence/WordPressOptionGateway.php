<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Persistence;

/**
 * WordPress option-table gateway.
 *
 * Values are the explicitly serialized aggregate arrays; options are always
 * written with autoload disabled so plugin state never rides along on every
 * page load.
 */
final class WordPressOptionGateway implements OptionGateway
{
    public function get(string $option, mixed $default): mixed
    {
        return get_option($option, $default);
    }

    public function add(string $option, mixed $value, bool $autoload): bool
    {
        return add_option($option, $value, '', $autoload);
    }

    public function update(string $option, mixed $value, bool $autoload): bool
    {
        return update_option($option, $value, $autoload);
    }

    public function delete(string $option): bool
    {
        return delete_option($option);
    }
}
