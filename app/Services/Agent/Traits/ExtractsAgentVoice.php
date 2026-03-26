<?php

namespace App\Services\Agent\Traits;

trait ExtractsAgentVoice
{
    /**
    * Extracts the agent's "voice" from the LLM response.
    *
    * Supported formats:
    * [agent speak]text[/agent]
    * [agent speak]text[/agent speak]
    *
    * Behavior:
    * - If the tag is found and valid → returns the content (all occurrences separated by spaces)
    * - If the tag is missing → returns the original string as is
    * - If the tag is open but not closed → returns an empty string
    * - Other tags within the content → are stripped along with the content
    */
    public function extractAgentVoice(string $response): string
    {
        $openTag  = '\[agent speak\]';
        $closeTag = '\[\/agent(?:\s+speak)?\]';

        $hasOpenTag = (bool) preg_match("/{$openTag}/i", $response);

        if (!$hasOpenTag) {
            return $response;
        }

        $hasCloseTag = (bool) preg_match("/{$closeTag}/i", $response);

        if (!$hasCloseTag) {
            return '';
        }

        $pattern = "/{$openTag}(.*?){$closeTag}/is";
        preg_match_all($pattern, $response, $matches);

        if (empty($matches[1])) {
            return '';
        }

        $parts = array_map(function (string $content): string {
            // Cut out paired [tag]...[/tag] tags along with their contents,
            // then single [tag] tags without a pair
            $clean = preg_replace('/\[[^\]]*\].*?\[\/[^\]]*\]|\[[^\]]*\]/is', '', $content);
            return trim($clean);
        }, $matches[1]);

        $parts = array_filter($parts, fn (string $p) => $p !== '');

        return implode(' ', $parts);
    }
}
