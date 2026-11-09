<?php

declare(strict_types=1);

namespace App\Interfaces\Telegram\Processing;

/**
 * A small Markdown subset for untrusted AI-generated replies.
 *
 * Only Telegram-supported HTML tags are emitted; all text/URLs are escaped.
 * Malformed Markdown remains harmless literal text.
 */
final class DenizBusinessMessageFormatter
{
    private const TOKEN_PATTERN = '~(?<!\\\\)(?:
        (?<fence>```(?<fenceBody>.*?)```)
        |(?<code>`(?<codeBody>[^`\r\n]+)`)
        |(?<link>\[(?<label>[^\]\r\n]+)\]\((?<url>https?://[^\s)]+)\))
        |(?<bold>\*\*(?<boldBody>.+?)\*\*)
        |(?<boldAlt>__(?<boldAltBody>.+?)__)
        |(?<!\w)(?<!\*)(?<italic>\*(?<italicBody>[^\*\r\n]+)\*(?!\*))
        |(?<!\w)(?<italicAlt>_(?<italicAltBody>[^_\r\n]+)_(?!\w))
        |(?<heading>(?m:^\#{1,3}[ \t]+(?<headingBody>[^\r\n]+)))
    )~usx';

    public function format(string $text): string
    {
        // Trim before rendering so no generated HTML tag is ever truncated.
        return $this->inline(mb_substr($text, 0, 3500));
    }

    private function inline(string $text, int $depth = 0): string
    {
        if ($depth >= 5) {
            return $this->escape($text);
        }

        $html = '';
        $offset = 0;

        while (preg_match(self::TOKEN_PATTERN, $text, $matches, PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL, $offset) === 1) {
            $raw = $matches[0][0];
            $start = $matches[0][1];

            $html .= $this->escape(substr($text, $offset, $start - $offset));

            if ($this->matched($matches, 'fence')) {
                $body = $matches['fenceBody'][0];
                // Remove a short optional code-language header.
                $body = preg_replace('/\A[a-zA-Z0-9_+#.-]{1,30}\r?\n/u', '', $body) ?? $body;
                $html .= '<pre>'.$this->escape(trim($body, "\r\n")).'</pre>';
            } elseif ($this->matched($matches, 'code')) {
                $html .= '<code>'.$this->escape($matches['codeBody'][0]).'</code>';
            } elseif ($this->matched($matches, 'link')) {
                $url = $matches['url'][0];
                $valid = filter_var($url, FILTER_VALIDATE_URL) !== false
                    && in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true)
                    && parse_url($url, PHP_URL_HOST) !== null
                    && parse_url($url, PHP_URL_USER) === null
                    && parse_url($url, PHP_URL_PASS) === null;

                $html .= $valid
                    ? '<a href="'.$this->escape($url).'">'.$this->escape($matches['label'][0]).'</a>'
                    : $this->escape($raw);
            } elseif ($this->matched($matches, 'bold') || $this->matched($matches, 'boldAlt')) {
                $body = $this->matched($matches, 'bold') ? $matches['boldBody'][0] : $matches['boldAltBody'][0];
                $html .= '<b>'.$this->inline($body, $depth + 1).'</b>';
            } elseif ($this->matched($matches, 'italic') || $this->matched($matches, 'italicAlt')) {
                $body = $this->matched($matches, 'italic') ? $matches['italicBody'][0] : $matches['italicAltBody'][0];
                $html .= '<i>'.$this->inline($body, $depth + 1).'</i>';
            } else {
                $html .= '<b>'.$this->inline($matches['headingBody'][0], $depth + 1).'</b>';
            }

            $offset = $start + strlen($raw);
        }

        return $html.$this->escape(substr($text, $offset));
    }

    /**
     * @param  array<string|int, array{0: ?string, 1: int}>  $matches
     */
    private function matched(array $matches, string $name): bool
    {
        return isset($matches[$name][0]);
    }

    private function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
