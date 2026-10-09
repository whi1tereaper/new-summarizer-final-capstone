<?php
declare(strict_types=1);

// Rendering helpers only: authorization is enforced by every entry point.
function ev_h(mixed $value): string { return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function ev_label(string $value): string { return ucwords(str_replace('_', ' ', $value)); }
function ev_number(mixed $value, int $digits = 3): string { return is_numeric($value) && is_finite((float) $value) ? number_format((float) $value, $digits) : 'Not available'; }
function ev_pvalue(mixed $value): string {
    if (!is_numeric($value) || !is_finite((float) $value)) { return 'Not available'; }
    return (float) $value > 0 && (float) $value < 0.00001 ? '<0.00001' : number_format((float) $value, 5);
}
function ev_id(mixed $value): int {
    $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($id === false) { throw new InvalidArgumentException('A valid record ID is required.'); }
    return $id;
}
function ev_array(mixed $value): array {
    if (is_array($value)) { return $value; }
    if (!is_string($value) || $value === '') { return []; }
    $decoded = json_decode($value, true);
    return is_array($decoded) ? $decoded : [];
}
function ev_form_start(string $action, array $hidden = []): void {
    echo '<form method="post" class="evaluation-form"><input type="hidden" name="csrf_token" value="' . ev_h(generateCsrfToken()) . '"><input type="hidden" name="action" value="' . ev_h($action) . '">';
    foreach ($hidden as $name => $value) { echo '<input type="hidden" name="' . ev_h($name) . '" value="' . ev_h($value) . '">'; }
}
function ev_field_id(string $name): string { static $index = 0; return 'eval-' . (++$index) . '-' . $name; }
function ev_input(string $name, string $label, string $type = 'text', string $value = '', bool $required = true): void {
    $id = ev_field_id($name);
    echo '<div class="form-group"><label for="' . ev_h($id) . '">' . ev_h($label) . '</label><input id="' . ev_h($id) . '" name="' . ev_h($name) . '" type="' . ev_h($type) . '" value="' . ev_h($value) . '"' . ($required ? ' required' : '') . ($type === 'number' ? ' min="1"' : ' maxlength="2000"') . '></div>';
}
function ev_textarea(string $name, string $label, bool $required = true, string $value = ''): void {
    $id = ev_field_id($name);
    echo '<div class="form-group"><label for="' . ev_h($id) . '">' . ev_h($label) . '</label><textarea id="' . ev_h($id) . '" name="' . ev_h($name) . '"' . ($required ? ' required' : '') . ' maxlength="1000000">' . ev_h($value) . '</textarea></div>';
}
function ev_select(string $name, string $label, array $values, string $selected = ''): void {
    $id = ev_field_id($name);
    echo '<div class="form-group"><label for="' . ev_h($id) . '">' . ev_h($label) . '</label><select id="' . ev_h($id) . '" name="' . ev_h($name) . '" required>';
    $isList = array_is_list($values);
    foreach ($values as $key => $value) {
        if ($isList) { $key = $value; $value = ev_label((string) $value); }
        echo '<option value="' . ev_h($key) . '"' . ((string) $key === $selected ? ' selected' : '') . '>' . ev_h($value) . '</option>';
    }
    echo '</select></div>';
}
function ev_submit(string $label): void { echo '<div class="evaluation-actions"><button class="btn-auth" type="submit">' . ev_h($label) . '</button></div></form>'; }
function ev_checks(string $name, string $legend, array $values, array $checked = []): void {
    echo '<fieldset><legend>' . ev_h($legend) . '</legend><div class="evaluation-checks">';
    foreach ($values as $value) { echo '<label class="evaluation-check"><input type="checkbox" name="' . ev_h($name) . '[]" value="' . ev_h($value) . '"' . (in_array($value, $checked, true) ? ' checked' : '') . '>' . ev_h(ev_label($value)) . '</label>'; }
    echo '</div></fieldset>';
}
function ev_header(string $title, bool $admin = true, string $tab = ''): void {
    ?><!doctype html><html lang="en" data-theme="light"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex,nofollow"><title><?= ev_h($title) ?> — LIGHT</title>
    <link rel="icon" type="image/png" href="assets/images/poc-neust-logo.png"><link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Outfit:wght@600;700;800;900&display=swap" rel="stylesheet"><link rel="stylesheet" href="assets/css/design-tokens.css"><link rel="stylesheet" href="assets/css/style.css"><link rel="stylesheet" href="assets/css/analytics.css"><link rel="stylesheet" href="assets/css/evaluation.css"><link rel="stylesheet" href="assets/css/site-nav.css"><link rel="stylesheet" href="assets/css/site-footer.css"><link rel="stylesheet" href="assets/css/global-button-effects.css"></head><body class="analytics-page evaluation-page">
    <?php require __DIR__ . '/site-nav.php'; ?>
    <main class="analytics-shell"><div class="analytics-heading"><div><p class="eyebrow"><?= $admin ? 'Research workspace' : 'Independent review' ?></p><h1><?= ev_h($title) ?></h1><p><?= $admin ? 'Traceable measurements, independent ratings, and reproducible experiments.' : 'Read the source and assess each assigned summary using the same rubric.' ?></p></div></div>
    <?php if ($admin): ?><nav class="evaluation-tabs" aria-label="Evaluation sections"><?php foreach (['overview' => 'Overview', 'datasets' => 'Datasets', 'runs' => 'Runs & ablations', 'assignments' => 'Human evaluation', 'reports' => 'Reports'] as $key => $label): ?><a href="evaluation.php?tab=<?= $key ?>"<?= $key === $tab ? ' aria-current="page"' : '' ?>><?= $label ?></a><?php endforeach; ?></nav><?php endif;
}
function ev_footer(): void { echo '</main>'; require __DIR__ . '/site-footer.php'; echo '</body></html>'; }

function ev_criteria(): array {
    return [
        'relevance' => ['Relevance', 'How well does the summary focus on information important to the document’s purpose?'],
        'factual_consistency' => ['Factual consistency', 'Are all claims supported by the source, with numbers, negations, and qualifications preserved?'],
        'coverage' => ['Coverage', 'Does it include the essential information appropriate to its displayed length?'],
        'coherence' => ['Coherence', 'Do sentences connect logically, with clear references and a sensible order?'],
        'readability' => ['Readability / fluency', 'Is the wording grammatical, complete, and easy to understand?'],
        'conciseness' => ['Conciseness', 'Does it convey useful information without unnecessary detail?'],
        'non_redundancy' => ['Non-redundancy', 'Does each sentence add information without repeating other sentences?'],
    ];
}
function ev_rating_fields(array $values = []): void {
    foreach (ev_criteria() as $key => [$title, $description]) {
        $helpId = ev_field_id($key);
        echo '<fieldset class="evaluation-rating"><legend>' . ev_h($title) . '</legend><p id="help-' . ev_h($helpId) . '" class="evaluation-note">' . ev_h($description) . '</p><div class="evaluation-checks">';
        foreach ([1 => 'Very poor', 2 => 'Poor', 3 => 'Acceptable', 4 => 'Good', 5 => 'Excellent'] as $score => $label) {
            echo '<label class="evaluation-check"><input type="radio" name="ratings[' . $key . ']" value="' . $score . '" required aria-describedby="help-' . ev_h($helpId) . '"' . ((int) ($values[$key] ?? 0) === $score ? ' checked' : '') . '>' . $score . ' · ' . $label . '</label>';
        }
        echo '</div></fieldset>';
    }
}
