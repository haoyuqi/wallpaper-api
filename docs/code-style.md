# Code style

PHP 8.4.27 is the CI baseline. Composer also permits PHP 8.5.12 or later
within PHP 8.x, which is not separately covered by CI. Run Pint with the
project's Laravel preset both locally and in CI. Existing
`declare(strict_types=1)` declarations
may remain, but adding them is optional and is not enforced by Pint.
Larastan level 5 checks production code and database definitions; use accurate
PHPDoc for framework magic and fix findings instead of suppressing them.

## Naming

- Classes and matching filenames use PascalCase.
- Variables, parameters, application-owned properties and methods use camelCase.
- Use descriptive business names in longer scopes, plurals for collections, and
  `is`, `has` or `can` prefixes for boolean predicates where appropriate.
- Constants use UPPER_SNAKE_CASE. Shared Bing identity values belong to
  `BingWallpaperGateway::SOURCE` and `BingWallpaperGateway::MARKET`.
- Database columns, configuration keys and structured log keys use snake_case.
- Public JSON keys retain the frozen camelCase contract.
- Preserve framework-defined names and inherited property types.

## Types and readability

Declare parameter, property and return types where their contracts are known.
Use `mixed` only for genuinely unrestricted input, such as raw JSON values.
PHPDoc describes collection element types and array shapes that PHP cannot
express natively. Check the parent contract before typing framework overrides.

Use arrow functions for short expressions and ordinary closures for multiple
steps. Use `match` for simple mappings; extract nested decisions into named
methods. Prefer explicit arrays when constructing public JSON fields.
Compare validated canonical calendar-date strings with `strcmp`.
Use early returns and strict comparisons where appropriate. Follow Pint for
spacing, imports, line breaks and constructor parentheses.

## Tests

Concrete test classes without subclasses are final. Test methods use
`test_descriptive_snake_case`; helpers and data providers use camelCase.
Use `self::assert...` for PHPUnit assertions and `$this` for Laravel instance
helpers such as `assertDatabaseCount`, `getJson` and `artisan`.
Keep literal expected source and market values in fixtures and assertions so
tests independently verify the integration contract.
