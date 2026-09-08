<?php
namespace App\Src\Utils;

class TermsContent
{
    public static function getSections(): array
    {
        return [
            [
                'heading' => 'Agreement',
                'body' => 'By using this Article Summarization Software, you agree to follow these Terms and Conditions. If you do not agree, please do not use the system.',
            ],
            [
                'heading' => 'Purpose of the Software',
                'body' => 'This software is designed to help users summarize articles, academic texts, and other written content into shorter and easier-to-understand summaries.',
            ],
            [
                'heading' => 'Data We Collect',
                'body' => 'The system may collect text or article content entered by the user, uploaded files such as PDF documents, summary preferences, account information if registration is required, summary history for logged-in users, feedback ratings, and basic technical data such as date, time, session information, and system errors.',
            ],
            [
                'heading' => 'Why We Collect Data',
                'body' => 'The data is collected to generate summaries, display results, save user history, improve summary quality, process feedback, detect errors, prevent misuse, and maintain system security.',
            ],
            [
                'heading' => 'User Responsibility',
                'body' => 'Users are responsible for the content they enter or upload. Users should not submit private, sensitive, confidential, copyrighted, or unauthorized content unless they have permission to do so.',
            ],
            [
                'heading' => 'Accuracy of Summaries',
                'body' => 'The system attempts to generate accurate and meaningful summaries, but it may still make mistakes. Users should review the original article and should not treat the generated summary as a complete replacement for the original content.',
            ],
            [
                'heading' => 'Data Privacy',
                'body' => 'The system will handle user data with care. Guest data may be stored temporarily during an active session and may be deleted after the session ends. Registered user data, such as summary history, may be stored until deleted by the user or administrator, depending on the system rules.',
            ],
            [
                'heading' => 'Prohibited Use',
                'body' => 'Users must not use the system to upload illegal, harmful, abusive, private, or unauthorized content. Users must not attempt to access another user\'s account, attack the system, overload the server, or misuse the summarization service.',
            ],
            [
                'heading' => 'Changes to the Terms',
                'body' => 'The developers may update these Terms and Conditions when needed. Continued use of the system means the user accepts the updated terms.',
            ],
            [
                'heading' => 'Acceptance',
                'body' => 'By using this Article Summarization Software, the user confirms that they understand and agree to these Terms and Conditions.',
            ],
        ];
    }
}
