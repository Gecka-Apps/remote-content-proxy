<?php

declare(strict_types=1);

/**
 * @author Laurent Dinclaux <laurent@gecka.nc>
 * @copyright 2026 Gecka
 * @license AGPL-3.0-or-later
 */

namespace App\Services;

use enshrined\svgSanitize\Sanitizer as SvgSanitizer;
use Illuminate\Support\Facades\Log;
use Sabberworm\CSS\CSSList\AtRuleBlockList;
use Sabberworm\CSS\CSSList\CSSBlockList;
use Sabberworm\CSS\CSSList\Document;
use Sabberworm\CSS\OutputFormat;
use Sabberworm\CSS\Parser as CssParser;
use Sabberworm\CSS\Property\Import;
use Sabberworm\CSS\RuleSet\RuleContainer;
use Sabberworm\CSS\Settings as CssSettings;
use Sabberworm\CSS\Value\CSSFunction;
use Sabberworm\CSS\Value\CSSString;
use Sabberworm\CSS\Value\RuleValueList;
use Sabberworm\CSS\Value\URL;

/**
 * Content sanitization service for proxied resources.
 *
 * Applies type-specific sanitization rules to ensure proxied content
 * is safe. Handles SVG sanitization via the enshrined/svg-sanitize
 * library and CSS sanitization via sabberworm/php-css-parser AST parsing.
 *
 * @author Laurent Dinclaux <laurent@gecka.nc>
 * @copyright 2026 Gecka
 * @license AGPL-3.0-or-later
 */
class ContentSanitizer
{
    /** @var string|null Base URL of the proxy for rewriting CSS url() references */
    private ?string $proxyBaseUrl;

    /**
     * @param string|null $proxyBaseUrl Base URL for rewriting CSS url() references (e.g. "https://proxy.example.com").
     *                                  When set, external http/https URLs in CSS are rewritten to pass through the proxy.
     *                                  When null, external URLs are replaced with "about:invalid".
     */
    public function __construct(?string $proxyBaseUrl = null)
    {
        // Remove trailing slash
        $this->proxyBaseUrl = $proxyBaseUrl !== null ? rtrim($proxyBaseUrl, '/') : null;
    }

    /** @var array<int, string> CSS properties that allow code execution and must be blocked */
    private const DANGEROUS_PROPERTIES = [
        'expression',
        'behavior',
        '-moz-binding',
    ];

    /** @var array<int, string> CSS value protocols that indicate malicious content */
    private const DANGEROUS_PROTOCOLS = [
        'javascript:',
        'vbscript:',
    ];

    /**
     * Sanitize content based on its MIME type.
     *
     * SVG and CSS content are actively sanitized; binary content types
     * (images, fonts, videos) are passed through unchanged.
     *
     * @param string $content     The raw content to sanitize
     * @param string $contentType The MIME type of the content
     * @return string|null The sanitized content, or null if sanitization failed
     */
    public function sanitize(string $content, string $contentType): ?string
    {
        return match ($contentType) {
            'image/svg+xml' => $this->sanitizeSvg($content),
            'text/css' => $this->sanitizeCss($content),
            default => $content,
        };
    }

    /**
     * Sanitize SVG content using the enshrined/svg-sanitize library.
     *
     * Removes remote references and minifies the output to prevent
     * external resource loading and reduce payload size.
     *
     * @param string $svgContent The raw SVG markup
     * @return string|null The sanitized SVG, or null if the content was rejected
     */
    private function sanitizeSvg(string $svgContent): ?string
    {
        $sanitizer = new SvgSanitizer();
        $sanitizer->removeRemoteReferences(true);
        $sanitizer->minify(true);

        $cleanSvg = $sanitizer->sanitize($svgContent);

        if ($cleanSvg === false || empty($cleanSvg)) {
            return null;
        }

        return $cleanSvg;
    }

    /**
     * Sanitize CSS content using AST-based parsing.
     *
     * Parses the CSS into an abstract syntax tree using sabberworm/php-css-parser,
     * then walks the tree to remove dangerous constructs: @import rules, external
     * url() references, dangerous properties (expression, behavior, -moz-binding),
     * and script protocol values. Falls back to rejection on parse failure.
     *
     * @param string $cssContent The raw CSS content
     * @return string|null The sanitized CSS, or null if malicious content was detected
     */
    private function sanitizeCss(string $cssContent): ?string
    {
        // Remove null bytes before parsing
        $cssContent = str_replace("\0", '', $cssContent);

        // Reject obvious non-CSS content (HTML/script injection)
        if ($this->containsHtmlInjection($cssContent)) {
            Log::warning('CSS contains HTML injection attempt');
            return null;
        }

        // Parse CSS into AST
        try {
            $settings = CssSettings::create()->withLenientParsing(true);
            $parser = new CssParser($cssContent, $settings);
            $document = $parser->parse();
        } catch (\Exception $e) {
            Log::warning('CSS parse failed, rejecting content', ['error' => $e->getMessage()]);
            return null;
        }

        // Walk the AST and sanitize
        $rejected = $this->sanitizeCssBlock($document);
        if ($rejected) {
            return null;
        }

        return $document->render(OutputFormat::createCompact());
    }

    /**
     * Recursively sanitize a CSS block list (document, @media, etc.).
     *
     * Removes @import rules and delegates rule sanitization. Returns true
     * if actively malicious content was found and the entire stylesheet
     * should be rejected.
     *
     * @param CSSBlockList $block The CSS block to sanitize
     * @return bool True if the content should be rejected entirely
     */
    private function sanitizeCssBlock(CSSBlockList $block): bool
    {
        $contents = $block->getContents();
        $toRemove = [];

        foreach ($contents as $item) {
            // Remove all @import rules
            if ($item instanceof Import) {
                $toRemove[] = $item;
                continue;
            }

            // Recurse into nested blocks (@media, @supports, etc.)
            if ($item instanceof AtRuleBlockList) {
                $rejected = $this->sanitizeCssBlock($item);
                if ($rejected) {
                    return true;
                }
                continue;
            }

            // Check declaration blocks (rule sets and declaration blocks)
            if ($item instanceof RuleContainer) {
                $rejected = $this->sanitizeRuleSet($item);
                if ($rejected) {
                    return true;
                }
            }
        }

        // Remove collected @import rules
        foreach ($toRemove as $item) {
            $block->remove($item);
        }

        return false;
    }

    /**
     * Sanitize a CSS rule set by checking and cleaning its declarations.
     *
     * Removes rules with dangerous property names and sanitizes url()
     * values in remaining rules. Returns true if an actively malicious
     * value (javascript:, vbscript:) is detected.
     *
     * @param RuleContainer $ruleSet The rule set to sanitize
     * @return bool True if actively malicious content was found
     */
    private function sanitizeRuleSet(RuleContainer $ruleSet): bool
    {
        $rulesToRemove = [];

        foreach ($ruleSet->getRules() as $rule) {
            $property = strtolower($rule->getRule());

            // Remove dangerous properties entirely
            if (in_array($property, self::DANGEROUS_PROPERTIES, true)) {
                $rulesToRemove[] = $rule;
                continue;
            }

            // Check values for dangerous protocols
            $value = $rule->getValue();
            if ($this->containsDangerousProtocol($value)) {
                Log::warning('Malicious CSS value detected', ['property' => $property]);
                return true;
            }

            // Sanitize url() references in values
            $this->sanitizeValue($value);
        }

        foreach ($rulesToRemove as $rule) {
            $ruleSet->removeRule($rule);
        }

        return false;
    }

    /**
     * Check if a CSS value contains a dangerous protocol (javascript:, vbscript:).
     *
     * Recursively inspects value lists, functions, and URL objects.
     *
     * @param mixed $value The CSS value to inspect
     * @return bool True if a dangerous protocol was found
     */
    private function containsDangerousProtocol(mixed $value): bool
    {
        if ($value instanceof URL) {
            $urlString = $this->extractUrlString($value);
            foreach (self::DANGEROUS_PROTOCOLS as $protocol) {
                if (str_starts_with(strtolower(trim($urlString)), $protocol)) {
                    return true;
                }
            }
        }

        if ($value instanceof CSSFunction) {
            $name = strtolower($value->getName());
            if ($name === 'expression') {
                return true;
            }
            foreach ($value->getArguments() as $arg) {
                if ($this->containsDangerousProtocol($arg)) {
                    return true;
                }
            }
        }

        if ($value instanceof RuleValueList) {
            foreach ($value->getListComponents() as $component) {
                if ($this->containsDangerousProtocol($component)) {
                    return true;
                }
            }
        }

        if (is_string($value)) {
            $lower = strtolower(trim($value));
            foreach (self::DANGEROUS_PROTOCOLS as $protocol) {
                if (str_starts_with($lower, $protocol)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Sanitize url() values within a CSS value node.
     *
     * For external http/https URLs: rewrites them to pass through the proxy
     * (if a proxy base URL is configured) or replaces with "about:invalid".
     * Safe data URIs (data:image/*, data:font/*) and fragment references (#id)
     * are preserved unchanged.
     *
     * @param mixed $value The CSS value to sanitize in-place
     * @return void
     */
    private function sanitizeValue(mixed $value): void
    {
        if ($value instanceof URL) {
            $urlString = $this->extractUrlString($value);
            if (!$this->isSafeUrl($urlString)) {
                $proxyUrl = $this->rewriteToProxyUrl($urlString);
                $value->setURL(new CSSString($proxyUrl ?? 'about:invalid'));
            }
            return;
        }

        if ($value instanceof CSSFunction) {
            foreach ($value->getArguments() as $arg) {
                $this->sanitizeValue($arg);
            }
            return;
        }

        if ($value instanceof RuleValueList) {
            foreach ($value->getListComponents() as $component) {
                $this->sanitizeValue($component);
            }
        }
    }

    /**
     * Rewrite an external URL to pass through the proxy.
     *
     * Only http://, https://, and protocol-relative (//) URLs are rewritten.
     * Returns null if the URL cannot be proxied (no base URL configured,
     * or the URL uses an unsupported scheme).
     *
     * @param string $url The external URL to rewrite
     * @return string|null The proxy URL, or null if not rewritable
     */
    private function rewriteToProxyUrl(string $url): ?string
    {
        if ($this->proxyBaseUrl === null) {
            return null;
        }

        $trimmed = trim($url);

        // Protocol-relative URL: prepend https:
        if (str_starts_with($trimmed, '//')) {
            $trimmed = 'https:' . $trimmed;
        }

        // Only proxy http/https URLs
        $lower = strtolower($trimmed);
        if (!str_starts_with($lower, 'http://') && !str_starts_with($lower, 'https://')) {
            return null;
        }

        $encoded = rtrim(strtr(base64_encode($trimmed), '+/', '-_'), '=');

        return $this->proxyBaseUrl . '/i/' . $encoded;
    }

    /**
     * Extract the URL string from a sabberworm URL value object.
     *
     * @param URL $url The URL value object
     * @return string The raw URL string
     */
    private function extractUrlString(URL $url): string
    {
        $inner = $url->getURL();

        if ($inner instanceof CSSString) {
            return $inner->getString();
        }

        return (string) $inner;
    }

    /**
     * Check if a URL value is safe to keep in sanitized CSS.
     *
     * Safe URLs are: data:image/* URIs, data:font/* URIs, and
     * fragment-only references (#id). Everything else is blocked.
     *
     * @param string $url The URL string to check
     * @return bool True if the URL is safe to preserve
     */
    private function isSafeUrl(string $url): bool
    {
        $url = trim($url);

        // Fragment-only references (#gradient, #filter, etc.)
        if (str_starts_with($url, '#')) {
            return true;
        }

        // data:image/* (safe embedded images)
        if (preg_match('/^data:image\//i', $url)) {
            return true;
        }

        // data:font/* and data:application/font-* (embedded fonts)
        if (preg_match('/^data:(?:font|application\/(?:font-|x-font-|vnd\.ms-))/i', $url)) {
            return true;
        }

        return false;
    }

    /**
     * Check if CSS content contains HTML injection attempts.
     *
     * Detects script tags and HTML comments that could break out
     * of a style context.
     *
     * @param string $content The raw CSS content to check
     * @return bool True if HTML injection was detected
     */
    private function containsHtmlInjection(string $content): bool
    {
        $patterns = [
            '/<\s*script/i',
            '/<\s*\/\s*script/i',
            '/<!--/',
            '/-->/',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $content)) {
                return true;
            }
        }

        return false;
    }
}
