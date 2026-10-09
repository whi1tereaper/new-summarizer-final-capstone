<?php
declare(strict_types=1);

namespace App\Src\Services;

/**
 * Per-combination prompt templates parameterized by profile and length.
 *
 * Replaces the monolithic "generate everything" prompt with focused, single-combination
 * instructions where each model/worker execution produces exactly one summary.
 */
final class SummarizerPromptTemplate
{
    public const ALLOWED_PROFILES = [
        'general',
        'academic',
        'executive',
        'technical',
        'study',
        'news',
    ];

    public const ALLOWED_LENGTHS = [
        'brief',
        'balanced',
        'detailed',
        'comprehensive',
    ];

    public const PROFILE_INSTRUCTIONS = [
        'general' => 'Focus on core ideas, key arguments, and major conclusions. Maintain an objective, accessible tone suitable for a general audience.',
        'academic' => 'Emphasize research questions, theoretical framing, methodology, empirical findings, and scholarly implications. Maintain a formal academic tone.',
        'executive' => 'Prioritize strategic impact, high-level decisions, operational outcomes, and actionable takeaways for executive leadership.',
        'technical' => 'Highlight system architecture, engineering specifications, implementation details, mechanisms, constraints, and quantitative trade-offs.',
        'study' => 'Structure around foundational concepts, clear principle explanations, key definitions, and study takeaways for learning and retention.',
        'news' => 'Use an inverted pyramid style: lead with the most newsworthy facts (who, what, where, when, why), followed by crucial context and immediate implications.',
    ];

    public const LENGTH_INSTRUCTIONS = [
        'brief' => [
            'label' => 'Brief',
            'sentence_range' => '1-2 sentences',
            'approx_words' => '30-50 words',
            'instruction' => 'Distill strictly the single most vital conclusion or premise. Maximum conciseness.',
        ],
        'balanced' => [
            'label' => 'Balanced',
            'sentence_range' => '3-5 sentences',
            'approx_words' => '100-180 words',
            'instruction' => 'Deliver a well-rounded summary preserving the primary premise, key supporting points, and major conclusion.',
        ],
        'detailed' => [
            'label' => 'Detailed',
            'sentence_range' => '6-10 sentences',
            'approx_words' => '200-350 words',
            'instruction' => 'Provide thorough coverage of major arguments, supporting evidence, key examples, and qualifications.',
        ],
        'comprehensive' => [
            'label' => 'Comprehensive',
            'sentence_range' => '12+ sentences',
            'approx_words' => '400+ words',
            'instruction' => 'Produce an exhaustive, high-coverage synthesis preserving structural sections, nuanced details, and full findings.',
        ],
    ];

    public static function isAllowedProfile(string $profile): bool
    {
        return in_array(strtolower(trim($profile)), self::ALLOWED_PROFILES, true);
    }

    public static function isAllowedLength(string $length): bool
    {
        return in_array(strtolower(trim($length)), self::ALLOWED_LENGTHS, true);
    }

    public static function getProfileInstruction(string $profile): string
    {
        $key = strtolower(trim($profile));
        return self::PROFILE_INSTRUCTIONS[$key] ?? self::PROFILE_INSTRUCTIONS['general'];
    }

    public static function getLengthInstruction(string $length): array
    {
        $key = strtolower(trim($length));
        return self::LENGTH_INSTRUCTIONS[$key] ?? self::LENGTH_INSTRUCTIONS['balanced'];
    }

    /**
     * Render a per-combination prompt template parameterized by profile and length.
     */
    public static function renderPrompt(string $profile, string $length, string $sourceText): string
    {
        $normProfile = strtolower(trim($profile));
        $normLength = strtolower(trim($length));

        if (!self::isAllowedProfile($normProfile)) {
            throw new \InvalidArgumentException(
                "Invalid profile '{$profile}'. Allowed profiles: " . implode(', ', self::ALLOWED_PROFILES) . '.'
            );
        }

        if (!self::isAllowedLength($normLength)) {
            throw new \InvalidArgumentException(
                "Invalid length '{$length}'. Allowed lengths: " . implode(', ', self::ALLOWED_LENGTHS) . '.'
            );
        }

        $profInstruction = self::getProfileInstruction($normProfile);
        $lengthCfg = self::getLengthInstruction($normLength);
        $label = ucfirst($normProfile);
        $cleanSource = trim($sourceText);

        return <<<PROMPT
You are an expert summarization engine. Produce exactly ONE summary for the provided source text.

Target Configuration:
- Profile: {$label} — {$profInstruction}
- Length: {$lengthCfg['label']} ({$lengthCfg['sentence_range']}, approx {$lengthCfg['approx_words']}) — {$lengthCfg['instruction']}

Directives:
1. Generate strictly one coherent summary matching the specified profile and length.
2. Rely exclusively on the source text; never invent facts, extrapolate unsupported claims, or pad content.
3. Do not output multi-section labels, colons, or alternative profiles.

Source Text:
"""
{$cleanSource}
"""

Summary:
PROMPT;
    }
}
