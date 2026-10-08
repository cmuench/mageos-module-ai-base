<?php

declare(strict_types=1);

namespace MageOS\AiBase\Test\Unit\View;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Guards the admin form template against unescaped interpolation of the stored row ID.
 *
 * Row IDs are the array keys of the serialized `mageos_ai/services/configuration` value.
 * They arrive as POST array keys and nothing between the request and `core_config_data`
 * normalises them, so they must be treated as untrusted wherever they reach markup.
 *
 * The template's JavaScript lives inside a PHP heredoc, so no JS tooling in this repo can
 * see it. These tests assert the escaping contract against the template source instead.
 */
final class ServicesTemplateEscapingTest extends TestCase
{
    private const TEMPLATE = __DIR__
        . '/../../../src/view/adminhtml/templates/system/config/form/field/services.phtml';

    /**
     * Uses of `rowId` that legitimately do not need `escapeHtml`, with the reason.
     *
     * Anything else that touches `rowId` is assumed to build markup and must escape it.
     */
    private const NON_MARKUP_USES = [
        'const rowId = existingRowId' => 'generates the value',
        'buildField(serviceCode, rowId, field)' => 'buildField escapes the assembled name attribute',
        'findField(rowId,' => 'findField compares the assembled name with getAttribute(), never parsing it',
        "+ rowId + ']['" => 'builds a plain name string compared against getAttribute(), not markup',
        'dataset.rowId' => 'reads the value back out of the DOM',
        'syncRowModel(rowId,' => 'passes the value on to a function that compares it with getAttribute()',
        'findRowModelLabel(rowId)' => 'compares the assembled value with getAttribute(), never parsing it',
        'return rowId;' => 'hands the value back to the caller, no markup involved',
        "getAttribute('data-row-model') === rowId" => 'compares the value, never parses or renders it',
        'const rowId = addRow(' => 'receives the value back from addRow()',
        'document.getElementById(rowId)' => 'getElementById takes an ID, not a selector, so nothing parses it',
        'syncRowHeading(rowId,' => 'passes the value on to a function that compares it with getAttribute()',
        'findByAttribute(' => 'compares the assembled value with getAttribute(), never parsing it',
        'const rowId = button.dataset.rowRename' => 'reads the value back out of the DOM',
        'syncRowEnabled(rowId)' => 'passes the value on to a function that compares it with getAttribute()',
    ];

    /**
     * A function declaring `rowId` as a parameter, matched by shape rather than by name so
     * that renaming the function does not fail this guard for a line that cannot interpolate.
     *
     * The pattern is anchored on both ends and stops at the opening brace, so a line that
     * both declares the parameter and builds markup cannot slip through it.
     */
    private const PARAMETER_DECLARATION = '/^function \w+\((?:[\w\s,]*,\s*)?rowId\b[\w\s,]*\) \{$/';

    private string $template;

    protected function setUp(): void
    {
        $template = file_get_contents(self::TEMPLATE);
        self::assertIsString($template, 'Template is unreadable: ' . self::TEMPLATE);

        $this->template = $template;
    }

    public function test_every_row_id_interpolation_into_markup_is_escaped(): void
    {
        $unescaped = array_filter(
            $this->linesContainingRowId(),
            fn (string $line): bool => !$this->isNonMarkupUse($line) && !str_contains($line, 'escapeHtml(rowId)')
        );

        self::assertSame(
            [],
            $unescaped,
            "Row IDs are attacker-controllable and must not reach markup unescaped.\n"
            . "Wrap each of these in escapeHtml(), or add the use to NON_MARKUP_USES with a reason:\n  "
            . implode("\n  ", $unescaped)
        );
    }

    public function test_the_row_id_sinks_are_still_present_so_the_guard_cannot_pass_vacuously(): void
    {
        self::assertNotSame(
            [],
            array_filter($this->linesContainingRowId(), fn (string $line): bool => str_contains($line, 'escapeHtml(rowId)')),
            'No escaped row-ID interpolation found at all; the template moved and this test now guards nothing.'
        );
    }

    /**
     * The parameter-declaration exemption is the one rule matched by shape instead of by
     * literal, so pin what it must not swallow: anything that concatenates the row ID.
     */
    #[DataProvider('lines_the_parameter_declaration_exemption_must_reject')]
    public function test_the_parameter_declaration_exemption_covers_declarations_only(string $line): void
    {
        self::assertDoesNotMatchRegularExpression(
            self::PARAMETER_DECLARATION,
            $line,
            'The parameter-declaration exemption now covers a line that reaches markup.'
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function lines_the_parameter_declaration_exemption_must_reject(): array
    {
        return [
            'attribute interpolation' => ["const rowHtml = '<tr id=\"' + rowId + '\">'"],
            'declaration followed by markup' => ["function f(rowId) { return '<tr id=\"' + rowId + '\">'; }"],
            'call rather than declaration' => ['renderRow(rowId, serviceCode);'],
        ];
    }

    /**
     * The fix above is only worth anything if the helper it delegates to closes the
     * attribute it is used inside, so pin the characters that break out of one.
     */
    #[DataProvider('attribute_breaking_characters')]
    public function test_escape_html_helper_handles_attribute_breaking_characters(string $character): void
    {
        self::assertMatchesRegularExpression(
            '/\.replace\(\/' . preg_quote($character, '/') . '\/g,/',
            $this->escapeHtmlHelper(),
            sprintf('escapeHtml() no longer escapes "%s", so escaping the row ID stops protecting anything.', $character)
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function attribute_breaking_characters(): array
    {
        return [
            'ampersand' => ['&'],
            'less than' => ['<'],
            'greater than' => ['>'],
            'double quote' => ['"'],
            'single quote' => ["'"],
        ];
    }

    /**
     * Every value interpolated into the inline script has to be one the HTML parser cannot react to.
     *
     * A stored value or a provider's model name containing `<!--<script>` once switched the parser
     * into the script-data double-escaped state, the real `</script>` was swallowed, the script did
     * not run and no row rendered; the next save then stored an empty list. So each `{$var}` in the
     * script must come from escapeJs(), from the block's encodeForScript() (directly or through the
     * two methods that use it), or be a literal boolean.
     */
    public function test_every_value_interpolated_into_the_script_is_encoded_for_it(): void
    {
        preg_match('/<<<script\n(.*?)\nscript;/s', $this->template, $script);
        self::assertArrayHasKey(1, $script, 'Inline script heredoc not found in the template.');
        preg_match_all('/\{\$(\w+)\}/', $script[1], $variables);
        self::assertNotSame([], $variables[1], 'No interpolation found; this guard now checks nothing.');

        $unsafe = array_values(array_filter(
            array_unique($variables[1]),
            fn (string $variable): bool => !$this->isEncodedForScript($variable)
        ));

        self::assertSame([], $unsafe, 'Interpolated into the script without escapeJs()/encodeForScript(): '
            . implode(', ', $unsafe));
    }

    public function test_no_value_reaches_the_script_through_plain_json_encode(): void
    {
        self::assertStringNotContainsString(
            'json_encode(',
            $this->template,
            'Plain json_encode() leaves "<" in place; use $block->encodeForScript().'
        );
    }

    /**
     * The stored rows are where a hostile value lives, so pin that each part of their addRow() call
     * goes through an encoder rather than relying on the generic check above to find the sprintf.
     */
    public function test_stored_rows_are_encoded_into_their_add_row_calls(): void
    {
        preg_match('/foreach \(\$block->getStoredRows\(\) as \$_row\) \{(.*?)\n    \}/s', $this->template, $loop);
        self::assertArrayHasKey(1, $loop, 'The stored-row loop is gone; this guard now checks nothing.');

        self::assertStringContainsString("encodeForScript(\$_row['values'])", $loop[1]);
        self::assertStringContainsString("encodeForScript(\$_row['modelOptions'])", $loop[1]);
        self::assertStringContainsString("escapeJs(\$_row['code'])", $loop[1]);
        self::assertStringContainsString("escapeJs(\$_row['id'])", $loop[1]);
    }

    /**
     * A row whose provider is not registered reaches the script as plain strings: its code, its
     * label and its id. All three are stored data, so each goes through escapeJs(), and none of the
     * row's values (its masked credentials included) is handed to the script at all.
     */
    public function test_unregistered_rows_are_encoded_into_their_placeholder_calls(): void
    {
        preg_match('/if \(!\$_row\[\'registered\'\]\) \{(.*?)\n        \}/s', $this->template, $branch);
        self::assertArrayHasKey(1, $branch, 'The unregistered-row branch is gone; this guard now checks nothing.');

        self::assertStringContainsString('addUnregisteredRow(', $branch[1]);
        self::assertStringContainsString("escapeJs(\$_row['code'])", $branch[1]);
        self::assertStringContainsString("escapeJs(\$_row['label'])", $branch[1]);
        self::assertStringContainsString("escapeJs(\$_row['id'])", $branch[1]);
        self::assertStringNotContainsString("\$_row['values']", $branch[1]);
    }

    /**
     * Every stored value the placeholder puts into markup goes through the script's escapeHtml():
     * the heading (label and code), the row id in its attributes, and the translated notice.
     */
    public function test_the_unregistered_placeholder_escapes_everything_it_renders(): void
    {
        $function = $this->functionBody('addUnregisteredRow');

        self::assertStringContainsString("'<tr id=\"' + escapeHtml(rowId) + '\"", $function);
        self::assertStringContainsString("' data-row-id=\"' + escapeHtml(rowId) + '\"", $function);
        self::assertStringContainsString("escapeHtml(title)", $function);
        self::assertStringContainsString("escapeHtml('{\$unregisteredNoticeJs}')", $function);
        self::assertDoesNotMatchRegularExpression(
            '/\+ (label|serviceCode|title) \+ \'/',
            $function,
            'A stored value is concatenated into the placeholder markup without escapeHtml().'
        );
    }

    /**
     * The placeholder posts no field of its own, which is what lets the backend model keep the row as
     * stored. Its only input is the deletion marker its delete button adds.
     */
    public function test_the_unregistered_placeholder_posts_nothing_but_its_deletion_marker(): void
    {
        self::assertStringNotContainsString('<input', $this->functionBody('addUnregisteredRow'));
        self::assertStringNotContainsString('fieldNameBase', $this->functionBody('addUnregisteredRow'));

        $marker = $this->functionBody('markUnregisteredRowDeleted');
        self::assertStringContainsString("marker.name = fieldNameBase + '[{\$deletedMarkerJs}][]';", $marker);
        self::assertStringContainsString('marker.value = button.dataset.rowId;', $marker);
        self::assertStringContainsString('EncryptedServices::DELETED_MARKER', $this->template);
        self::assertStringContainsString(
            'if (deleteButton.dataset.unregistered) markUnregisteredRowDeleted(deleteButton);',
            $this->template
        );
    }

    /**
     * The backend model only accepts the server-rendered empty marker together with the one the
     * script adds once every stored row is on the page. Adding it any earlier, or anywhere but as
     * the last step, would let a script that failed halfway save only the rows it managed to render.
     */
    public function test_the_rendered_marker_is_added_only_after_every_stored_row_is_rendered(): void
    {
        $rowsAt = strpos($this->template, '{$existingRowsJs}');
        $markerAt = strrpos($this->template, 'markRendered();');

        self::assertIsInt($rowsAt);
        self::assertIsInt($markerAt);
        self::assertGreaterThan($rowsAt, $markerAt);
        self::assertMatchesRegularExpression('/markRendered\(\);\n\}\);\nscript;/', $this->template);
        self::assertSame(1, substr_count($this->template, 'markRendered();'));
    }

    public function test_the_empty_marker_is_still_rendered_server_side(): void
    {
        self::assertStringContainsString('EncryptedServices::EMPTY_MARKER', $this->template);
        self::assertStringContainsString('EncryptedServices::RENDERED_MARKER', $this->template);
    }

    /**
     * The body of one of the inline script's top-level functions.
     */
    private function functionBody(string $name): string
    {
        preg_match('/    function ' . $name . '\([^)]*\) \{\n(.*?)\n    \}\n/s', $this->template, $matches);
        self::assertArrayHasKey(1, $matches, $name . '() not found in the template; this guard now checks nothing.');

        return $matches[1];
    }

    /**
     * Whether a template variable is assigned only from something that encodes it for the script.
     */
    private function isEncodedForScript(string $variable): bool
    {
        if ($variable === 'existingRowsJs') {
            return true;
        }
        if (preg_match('/\$' . $variable . ' = (.*?);\n/s', $this->template, $assignment) !== 1) {
            return false;
        }

        foreach (['escapeJs(', 'encodeForScript(', 'getServicesSchemaJson()', 'getScopeParamsJson()'] as $encoder) {
            if (str_starts_with(trim($assignment[1]), '$escaper->' . $encoder)
                || str_starts_with(trim($assignment[1]), '$block->' . $encoder)) {
                return true;
            }
        }

        return preg_match('/^\$(_\w+|block->\w+\(\)) \? \'true\' : \'false\'$/', trim($assignment[1])) === 1;
    }

    /**
     * @return array<int, string>
     */
    private function linesContainingRowId(): array
    {
        return array_values(array_filter(
            array_map('trim', explode("\n", $this->template)),
            fn (string $line): bool => str_contains($line, 'rowId')
        ));
    }

    private function isNonMarkupUse(string $line): bool
    {
        if (preg_match(self::PARAMETER_DECLARATION, $line) === 1) {
            return true;
        }

        foreach (array_keys(self::NON_MARKUP_USES) as $allowed) {
            if (str_contains($line, $allowed)) {
                return true;
            }
        }

        return false;
    }

    private function escapeHtmlHelper(): string
    {
        preg_match('/function escapeHtml\(value\) \{(.*?)\n    \}/s', $this->template, $matches);
        self::assertArrayHasKey(1, $matches, 'escapeHtml() helper not found in the template.');

        return $matches[1];
    }
}
